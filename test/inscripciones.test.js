const { test, beforeEach } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { abrirBaseDatos } = require('../src/infraestructura/db');
const { crearSimulador, ErrorValidacion } = require('../src/aplicacion/simulador');
const { crearInscripciones } = require('../src/aplicacion/inscripciones');
const { crearAdministracion } = require('../src/aplicacion/admin');

const SEMILLA = path.join(__dirname, '..', 'data', 'asociados.seed.json');
const EXP = Object.fromEntries(require(SEMILLA).map((a) => [a.documento, a.fecha_expedicion]));
const AHORA = new Date('2026-10-05T15:00:00Z');
const PDF = Buffer.from('%PDF-1.4 prueba').toString('base64');
const CAMPOS = [{ id: 'correo', etiqueta: 'Correo', tipo: 'correo', requerido: true }];

let db, ins, dirSoportes;
beforeEach(() => {
  db = abrirBaseDatos(':memory:', { datosPrueba: true });
  dirSoportes = fs.mkdtempSync(path.join(os.tmpdir(), 'soportes-'));
  const simulador = crearSimulador(db, { hoy: () => AHORA });
  ins = crearInscripciones(db, simulador, {
    config: { inscripciones_desde: '2026-10-01', inscripciones_hasta: '2026-10-31', soporte_max_mb: 1 },
    camposSoporte: CAMPOS, habeasData: { version: 'v-prueba' }, dirSoportes, ahora: () => AHORA,
  });
});

const cred = (documento, referencia) => ({ documento, fechaExpedicion: EXP[documento], referencia });
const soporte = (r, extra = {}) => ins.cargarSoporte({
  ...cred(r.documento, r.referencia), cus: '123456789', banco: 'Bancolombia', fechaPago: '2026-10-05',
  valorPagado: r.total, campos: { correo: 'ana@correo.com' }, archivo: { nombre: 'pago.pdf', contenido: PDF }, autorizaDatos: true, ...extra,
});
const preinscribir = (documento, acompanantes = []) => ({
  ...ins.preinscribir({ ...cred(documento), acompanantes, autorizaDatos: true }, { ip: '10.0.0.1' }), documento,
});

test('preinscripción genera referencia, fija el valor y separa cupos', () => {
  const r = preinscribir('2001', [{ documento: '2002', nombre: 'Persona Prueba' }, { documento: '90777', nombre: 'Persona Prueba' }]);
  assert.match(r.referencia, /^EVT26-\d{6}$/);
  assert.equal(r.estado, 'PREINSCRITO');
  assert.equal(r.total, 176000);
  let orito = ins.cupos().find((c) => c.agencia === 'ORITO');
  assert.equal(orito.ocupados, 0); // la preinscripción no ocupa cupo
  assert.equal(orito.pendientes, 2);
  soporte(r);
  orito = ins.cupos().find((c) => c.agencia === 'ORITO');
  assert.equal(orito.ocupados, 2); // al registrar el pago: titular + acompañante asociado; el invitado no ocupa cupo
  assert.equal(orito.invitados, 1);
});

test('no permite preinscribir fuera del periodo de inscripciones', () => {
  const simulador = crearSimulador(db, { hoy: () => AHORA });
  const antes = crearInscripciones(db, simulador, { config: { inscripciones_desde: '2026-10-10' }, dirSoportes, ahora: () => AHORA });
  assert.throws(() => antes.preinscribir({ ...cred('1001'), autorizaDatos: true }), /abren el 10\/10\/2026/);
});

test('un asociado no puede tener dos inscripciones ni aparecer en otra como acompañante', () => {
  const r = preinscribir('1001', [{ documento: '90555', nombre: 'Persona Prueba' }]);
  assert.throws(() => preinscribir('1001'), new RegExp(r.referencia));
  assert.throws(() => preinscribir('1002', [{ documento: '1001', nombre: 'Persona Prueba' }]), /ya está registrado/);
  assert.throws(() => preinscribir('1002', [{ documento: '90555', nombre: 'Persona Prueba' }]), /ya está registrado/);
});

test('respeta el cupo por agencia contando solo asociados', () => {
  db.prepare("UPDATE tarifas SET cupos = 1 WHERE agencia = 'ORITO'").run();
  assert.throws(() => preinscribir('2001', [{ documento: '2002', nombre: 'Persona Prueba' }]), /superado el límite de cupos disponibles: en el evento de ORITO quedan 1/);
  const r = preinscribir('2001', [{ documento: '90001', nombre: 'Persona Prueba' }, { documento: '90002', nombre: 'Persona Prueba' }]);
  soporte(r); // invitados: sin límite de cupo; el titular ocupa el único cupo al pagar
  assert.throws(() => preinscribir('2002'), /Lo sentimos, se ha superado el límite de cupos disponibles/);
});

test('el cupo se ocupa al enviar el pago y se verifica en ese momento', () => {
  db.prepare("UPDATE tarifas SET cupos = 1 WHERE agencia = 'ORITO'").run();
  const a = preinscribir('2001');
  const b = preinscribir('2002'); // ambas preinscripciones caben: no ocupan cupo
  assert.equal(ins.cupos().find((c) => c.agencia === 'ORITO').ocupados, 0);
  soporte(a);
  assert.throws(() => soporte(b, { cus: '55556666' }), /Lo sentimos, se ha superado el límite de cupos disponibles/);
  assert.equal(ins.consultar(cred('2002', b.referencia)).estado, 'PREINSCRITO');
  ins.revisar(ins.listar({ busqueda: a.referencia })[0].id, { accion: 'RECHAZAR', motivo: 'Ilegible' }, 'admin');
  assert.equal(ins.cupos().find((c) => c.agencia === 'ORITO').ocupados, 0); // rechazado libera el cupo
  assert.equal(soporte(b, { cus: '55556666' }).estado, 'EN_REVISION');
});

test('los valores y el cupo se toman del evento de la agencia a la que asiste', () => {
  const r = ins.preinscribir({ ...cred('1001'), agenciaEvento: 'ORITO', acompanantes: [{ documento: '90777', nombre: 'Persona Prueba' }], autorizaDatos: true });
  assert.equal(r.agencia, 'ORITO');
  assert.equal(r.agenciaAsociado, 'BOGOTA');
  assert.equal(r.total, 33000 + 110000);
  assert.equal(ins.cupos().find((c) => c.agencia === 'ORITO').pendientes, 1);
  assert.equal(ins.cupos().find((c) => c.agencia === 'BOGOTA').pendientes, 0);
  assert.throws(() => ins.preinscribir({ ...cred('1002'), agenciaEvento: 'MARTE', autorizaDatos: true }), /No existe un evento/);
  const m = ins.modificar({ ...cred('1001', r.referencia), agenciaEvento: 'CALI', acompanantes: [] });
  assert.equal(m.agencia, 'CALI');
  assert.equal(m.total, 42000);
});

test('el administrador puede ajustar el cupo de una agencia', () => {
  soporte(preinscribir('2001', [{ documento: '2002', nombre: 'Persona Prueba' }]));
  assert.throws(() => ins.ajustarCupos('ORITO', 1, 'admin'), /2 cupos ocupados/);
  assert.throws(() => ins.ajustarCupos('ORITO', -3, 'admin'), /entero/);
  const ajustado = ins.ajustarCupos('ORITO', 2, 'admin');
  assert.deepEqual([ajustado.cupos, ajustado.cupos_excel, ajustado.disponibles], [2, 115, 0]);
  assert.throws(() => ins.preinscribir({ ...cred('3001'), agenciaEvento: 'ORITO', autorizaDatos: true }), /Lo sentimos, se ha superado el límite de cupos disponibles/);
  assert.equal(ins.ajustarCupos('ORITO', '', 'admin').cupos, 115); // vuelve al valor del Excel
});

test('cancelar libera el cupo y permite preinscribirse de nuevo', () => {
  const r = preinscribir('1001');
  assert.equal(ins.cancelar(cred('1001', r.referencia)).estado, 'CANCELADO');
  assert.equal(preinscribir('1001').estado, 'PREINSCRITO');
});

test('consultar exige referencia, documento y fecha de expedición correctos', () => {
  const r = preinscribir('1001');
  assert.equal(ins.consultar(cred('1001', r.referencia)).referencia, r.referencia);
  assert.throws(() => ins.consultar({ ...cred('1001', r.referencia), fechaExpedicion: '1990-01-01' }), ErrorValidacion);
  preinscribir('1002');
  assert.throws(() => ins.consultar(cred('1002', r.referencia)), /No encontramos/);
});

test('modificar acompañantes recalcula el total y valida cupos', () => {
  const r = preinscribir('1001', [{ documento: '90555', nombre: 'Persona Prueba' }]);
  const m = ins.modificar({ ...cred('1001', r.referencia), acompanantes: [{ documento: '1002', nombre: 'Persona Prueba' }, { documento: '90556', nombre: 'Persona Prueba' }] });
  assert.equal(m.total, 43500 + 43500 + 145000);
  assert.deepEqual(m.personas.map((p) => p.tipo), ['TITULAR', 'ASOCIADO', 'INVITADO']);
  assert.equal(preinscribir('3001', [{ documento: '90555', nombre: 'Persona Prueba' }]).estado, 'PREINSCRITO'); // 555 quedó libre
  db.prepare("UPDATE tarifas SET cupos = 2 WHERE agencia = 'BOGOTA'").run();
  assert.throws(() => ins.modificar({ ...cred('1001', r.referencia), acompanantes: [{ documento: '1002', nombre: 'Persona Prueba' }, { documento: '1003', nombre: 'Persona Prueba' }] }), /cupo/);
});

test('cargar soporte pasa a revisión y bloquea cambios', () => {
  const r = preinscribir('1001');
  assert.equal(soporte(r).estado, 'EN_REVISION');
  assert.equal(fs.readdirSync(dirSoportes).length, 1);
  assert.throws(() => ins.modificar({ ...cred('1001', r.referencia), acompanantes: [] }), /en revision/);
  assert.throws(() => soporte(r), /en revision/);
});

test('valida el soporte: tipo de archivo, CUS, campos adicionales y fecha', () => {
  const r = preinscribir('1001');
  assert.throws(() => soporte(r, { archivo: { nombre: 'x.exe', contenido: Buffer.from('MZ...').toString('base64') } }), /PDF, JPG o PNG/);
  assert.throws(() => soporte(r, { cus: 'abc' }), /CUS/);
  assert.throws(() => soporte(r, { campos: {} }), /Correo/);
  assert.throws(() => soporte(r, { fechaPago: '2026-10-06' }), /futura/);
  assert.throws(() => soporte(r, { archivo: { contenido: Buffer.alloc(2 * 1024 * 1024, 0x25).toString('base64') } }), /tamaño máximo/);
  assert.equal(fs.readdirSync(dirSoportes).length, 0);
});

test('un CUS no puede usarse en dos inscripciones', () => {
  soporte(preinscribir('1001'));
  assert.throws(() => soporte(preinscribir('1002')), /CUS ya fue registrado/);
});

test('marca alerta cuando el valor pagado no coincide', () => {
  soporte(preinscribir('1001'), { valorPagado: '40.000' });
  assert.match(ins.listar()[0].alerta, /distinto del total/);
});

test('revisión: rechazar permite corregir y volver a cargar; aprobar confirma', () => {
  const r = preinscribir('1001');
  soporte(r);
  const id = ins.listar()[0].id;
  assert.throws(() => ins.revisar(id, { accion: 'RECHAZAR' }, 'admin'), /motivo/);
  assert.equal(ins.revisar(id, { accion: 'RECHAZAR', motivo: 'Soporte ilegible' }, 'admin').estado, 'RECHAZADO');
  assert.equal(ins.consultar(cred('1001', r.referencia)).motivo, 'Soporte ilegible');
  soporte(r); // mismo CUS en la misma inscripción: permitido
  assert.equal(ins.revisar(id, { accion: 'APROBAR' }, 'admin').estado, 'CONFIRMADO');
  assert.throws(() => ins.cancelar(cred('1001', r.referencia)), ErrorValidacion);
});

test('anular libera el cupo', () => {
  const r = preinscribir('1001', [{ documento: '90555', nombre: 'Persona Prueba' }]);
  ins.revisar(ins.listar()[0].id, { accion: 'ANULAR', motivo: 'Duplicada' }, 'admin');
  assert.equal(ins.cupos().find((c) => c.agencia === 'BOGOTA').ocupados, 0);
  assert.equal(ins.consultar(cred('1001', r.referencia)).estado, 'ANULADO');
});

test('exportación CSV incluye campos adicionales y neutraliza fórmulas', () => {
  assert.throws(() => preinscribir('1001', [{ documento: '90555', nombre: '=HYPERLINK("x")' }]), /solo debe contener letras/);
  const r = preinscribir('1001', [{ documento: '90555', nombre: 'Ana Pérez' }]);
  soporte(r);
  ins.revisar(ins.listar()[0].id, { accion: 'RECHAZAR', motivo: '=HYPERLINK("x")' }, 'admin'); // texto libre del revisor
  const csv = ins.exportarCsv();
  assert.ok(csv.startsWith('﻿Referencia;'));
  assert.match(csv, /;Correo;/);
  assert.match(csv, /ana@correo\.com/);
  assert.ok(!csv.includes(';=HYPERLINK') && csv.includes("'=HYPERLINK"));
});

test('administradores: clave con hash, sesión válida y cierre', () => {
  const admin = crearAdministracion(db, { ahora: () => AHORA });
  admin.crearUsuario('tesoreria', 'Tesorería', 'clave-segura-123');
  assert.throws(() => admin.iniciarSesion('tesoreria', 'mala'), /incorrectos/);
  const { token } = admin.iniciarSesion('tesoreria', 'clave-segura-123');
  assert.equal(admin.validarSesion(token).usuario, 'tesoreria');
  admin.cerrarSesion(token);
  assert.equal(admin.validarSesion(token), null);
  assert.ok(!db.prepare('SELECT hash FROM administradores').get().hash.includes('clave'));
});

test('habeas data: exige autorización y la registra con versión, fecha e IP', () => {
  assert.throws(() => ins.preinscribir({ ...cred('1001') }), /tratamiento de datos/);
  const r = preinscribir('1001');
  const fila = db.prepare('SELECT autorizacion_version, autorizacion_en, autorizacion_ip FROM inscripciones WHERE referencia = ?').get(r.referencia);
  assert.deepEqual({ ...fila }, { autorizacion_version: 'v-prueba', autorizacion_en: AHORA.toISOString(), autorizacion_ip: '10.0.0.1' });
  assert.throws(() => soporte(r, { autorizaDatos: false }), /tratamiento de datos/);
  assert.match(ins.exportarCsv(), /v-prueba/);
});

test('la validación del periodo de inscripciones se puede pausar desde la configuración', () => {
  const simulador = crearSimulador(db, { hoy: () => AHORA });
  const config = { inscripciones_desde: '2026-12-01', validar_periodo_inscripcion: false };
  const pausada = crearInscripciones(db, simulador, { config, dirSoportes, ahora: () => AHORA });
  assert.equal(pausada.preinscribir({ ...cred('1001'), autorizaDatos: true }).estado, 'PREINSCRITO');
});

test('al intentar una segunda preinscripción se informa la referencia existente', () => {
  const r = preinscribir('1001');
  try {
    preinscribir('1001');
    assert.fail('debía rechazarse');
  } catch (err) {
    assert.equal(err.datos.referenciaExistente, r.referencia);
  }
});

test('el Coopetrolito ocupa cupo', () => {
  soporte(preinscribir('1001', [{ documento: '1100001', nombre: 'Mariana Torres', tipo: 'COOPETROLITO' }, { documento: '90777', nombre: 'Invitado Prueba' }]));
  const bogota = ins.cupos().find((c) => c.agencia === 'BOGOTA');
  assert.equal(bogota.ocupados, 2); // titular + Coopetrolito
  assert.equal(bogota.invitados, 1);
});

test('campos condicionales del soporte: placa y tipo de discapacidad solo cuando aplican', () => {
  const simulador = crearSimulador(db, { hoy: () => AHORA });
  const campos = require('../data/formulario_soporte.json');
  const i2 = crearInscripciones(db, simulador, {
    config: { validar_periodo_inscripcion: false }, camposSoporte: campos, habeasData: { version: 'v' }, dirSoportes, ahora: () => AHORA,
  });
  const r = { ...i2.preinscribir({ ...cred('1001'), autorizaDatos: true }), documento: '1001' };
  const enviar = (extra, cus = '11112222') => i2.cargarSoporte({
    ...cred('1001', r.referencia), cus, banco: 'Banco', fechaPago: '2026-10-05', valorPagado: r.total,
    archivo: { nombre: 'p.pdf', contenido: PDF }, autorizaDatos: true,
    campos: { celular: '3001234567', correo: 'a@b.co', vehiculo: 'No', discapacidad: 'No', ...extra },
  });
  assert.throws(() => enviar({ vehiculo: 'Sí' }), /Placa del vehículo/);
  assert.throws(() => enviar({ vehiculo: 'Sí', placa: '12ABC' }), /Placa del vehículo.*formato/);
  assert.throws(() => enviar({ discapacidad: 'Sí' }), /tipo de discapacidad/);
  assert.throws(() => enviar({ discapacidad: undefined }), /discapacidad/);
  enviar({ vehiculo: 'Sí', placa: 'abc 12d', discapacidad: 'Sí', tipo_discapacidad: 'Titular: movilidad reducida', placaIgnorada: 'x' });
  const guardado = JSON.parse(db.prepare('SELECT campos FROM soportes ORDER BY id DESC').get().campos);
  assert.equal(guardado.placa, 'ABC12D');
  assert.equal(guardado.tipo_discapacidad, 'Titular: movilidad reducida');
  assert.ok(!('placaIgnorada' in guardado));
});

test('si no aplica, el campo condicional se descarta aunque llegue', () => {
  const simulador = crearSimulador(db, { hoy: () => AHORA });
  const campos = require('../data/formulario_soporte.json');
  const i2 = crearInscripciones(db, simulador, { camposSoporte: campos, habeasData: { version: 'v' }, dirSoportes, ahora: () => AHORA });
  const r = i2.preinscribir({ ...cred('1002'), autorizaDatos: true });
  i2.cargarSoporte({
    ...cred('1002', r.referencia), cus: '33334444', banco: 'Banco', fechaPago: '2026-10-05', valorPagado: r.total,
    archivo: { nombre: 'p.pdf', contenido: PDF }, autorizaDatos: true,
    campos: { celular: '3001234567', correo: 'a@b.co', vehiculo: 'No', placa: 'XYZ999', discapacidad: 'Prefiero no responder', tipo_discapacidad: 'no debe guardarse' },
  });
  const guardado = JSON.parse(db.prepare('SELECT campos FROM soportes ORDER BY id DESC').get().campos);
  assert.ok(!('placa' in guardado) && !('tipo_discapacidad' in guardado));
});

test('pago en agencia: sin CUS ni comprobante obligatorio; recibo opcional y sin repetir', () => {
  const pagarEnAgencia = (r, extra = {}) => ins.cargarSoporte({
    ...cred(r.documento, r.referencia), medioPago: 'AGENCIA', agenciaPago: 'bogota', fechaPago: '2026-10-05',
    valorPagado: r.total, campos: { correo: 'ana@correo.com' }, autorizaDatos: true, ...extra,
  });
  const r1 = preinscribir('1001');
  assert.throws(() => pagarEnAgencia(r1, { agenciaPago: 'MARTE' }), /agencia donde realizó el pago/);
  assert.equal(pagarEnAgencia(r1, { recibo: 'rc 12345' }).estado, 'EN_REVISION');
  const s = db.prepare('SELECT medio_pago, cus, agencia_pago, recibo, archivo FROM soportes ORDER BY id DESC').get();
  assert.deepEqual({ ...s }, { medio_pago: 'AGENCIA', cus: '', agencia_pago: 'BOGOTA', recibo: 'RC12345', archivo: '' });
  assert.equal(fs.readdirSync(dirSoportes).length, 0);
  assert.throws(() => ins.archivoSoporte(db.prepare('SELECT id FROM soportes').get().id), /sin comprobante/);
  const detalle = ins.consultar(cred('1001', r1.referencia)).soporte;
  assert.equal(detalle.medioPago, 'AGENCIA');
  assert.equal(detalle.conArchivo, false);

  const r2 = preinscribir('1002');
  assert.throws(() => pagarEnAgencia(r2, { recibo: 'RC12345' }), /recibo ya fue registrado/);
  pagarEnAgencia(r2, { archivo: { nombre: 'recibo.pdf', contenido: PDF } }); // comprobante opcional, si lo adjunta se guarda
  assert.equal(fs.readdirSync(dirSoportes).length, 1);
  assert.match(ins.exportarCsv(), /Agencia \/ efectivo;;;BOGOTA;RC12345;No/);
});

test('PSE sigue exigiendo CUS y comprobante', () => {
  const r = preinscribir('1001');
  assert.throws(() => soporte(r, { medioPago: 'PSE', archivo: undefined }), /Adjunte el soporte/);
  assert.throws(() => soporte(r, { medioPago: 'OTRO' }), /cómo realizó el pago/);
});

test('consulta sin referencia: encuentra la inscripción vigente con documento y fecha de expedición', () => {
  const sinRef = (doc) => ({ documento: doc, fechaExpedicion: EXP[doc] });
  assert.throws(() => ins.consultar(sinRef('1001')), /No encontramos una inscripción para su documento/);
  const primera = preinscribir('1001');
  assert.equal(ins.consultar(sinRef('1001')).referencia, primera.referencia);
  ins.cancelar(sinRef('1001'));
  const segunda = preinscribir('1001');
  assert.equal(ins.consultar(sinRef('1001')).referencia, segunda.referencia); // prioriza la vigente sobre la cancelada
  assert.equal(soporte({ ...segunda, referencia: undefined }).estado, 'EN_REVISION'); // pagar sin referencia
  assert.throws(() => ins.consultar({ ...sinRef('1001'), fechaExpedicion: '1990-01-01' }), ErrorValidacion);
});

test('soporte PSE sin banco; celular solo dígitos', () => {
  const simulador = crearSimulador(db, { hoy: () => AHORA });
  const campos = [{ id: 'celular', etiqueta: 'Celular', tipo: 'telefono', requerido: true }];
  const i2 = crearInscripciones(db, simulador, { camposSoporte: campos, habeasData: { version: 'v' }, dirSoportes, ahora: () => AHORA });
  const r = i2.preinscribir({ ...cred('1001'), autorizaDatos: true });
  const enviar = (extra) => i2.cargarSoporte({
    ...cred('1001', r.referencia), cus: '77778888', fechaPago: '2026-10-05', valorPagado: r.total,
    archivo: { nombre: 'p.pdf', contenido: PDF }, autorizaDatos: true, campos: { celular: '300 123 4567' }, ...extra,
  });
  assert.throws(() => enviar({ campos: { celular: '300abc4567' } }), /Celular/);
  enviar({});
  assert.equal(JSON.parse(db.prepare('SELECT campos FROM soportes ORDER BY id DESC').get().campos).celular, '3001234567');
});

test('sesiones del panel: en la base solo se guarda el hash del token', () => {
  const admin = crearAdministracion(db, { ahora: () => AHORA });
  admin.crearUsuario('revisor', 'Revisor', 'clave-segura-123');
  const { token } = admin.iniciarSesion('revisor', 'clave-segura-123');
  const guardado = db.prepare('SELECT token FROM sesiones').get().token;
  assert.notEqual(guardado, token);
  assert.match(guardado, /^[0-9a-f]{64}$/);
  assert.equal(admin.validarSesion(guardado), null, 'el hash guardado no sirve como token');
  assert.equal(admin.validarSesion(token).usuario, 'revisor');
});

test('detalle del panel: indica si el soporte tiene comprobante sin exponer la ruta interna', () => {
  const r = preinscribir('1001');
  soporte(r);
  const s = ins.detalleAdmin(ins.listar()[0].id).soportes[0];
  assert.equal(s.tiene_archivo, true);
  assert.ok(!('archivo' in s));
});

const conPago = (documento, extra = {}) => ins.inscribirConPago({
  ...cred(documento), acompanantes: [], medioPago: 'AGENCIA', agenciaPago: 'BOGOTA', recibo: 'RC-900', fechaPago: '2026-10-05',
  valorPagado: '43500', campos: { correo: 'ana@correo.com' }, autorizaDatos: true, ...extra,
}, { ip: '10.0.0.9' });

test('inscripción y pago en agencia en un solo paso: queda en revisión y ocupa cupo', () => {
  const r = conPago('1001', { acompanantes: [{ documento: '90555', nombre: 'Persona Prueba' }] });
  assert.match(r.referencia, /^EVT26-\d{6}$/);
  assert.equal(r.estado, 'EN_REVISION');
  assert.equal(r.editable, false);
  assert.equal(r.soporte.medioPago, 'AGENCIA');
  assert.equal(r.soporte.agenciaPago, 'BOGOTA');
  assert.equal(r.soporte.conArchivo, false, 'en agencia el comprobante es opcional');
  const bogota = ins.cupos().find((c) => c.agencia === 'BOGOTA');
  assert.equal(bogota.ocupados, 1); // el titular; el invitado no ocupa cupo
  assert.equal(bogota.invitados, 1);
  const fila = db.prepare('SELECT autorizacion_ip, autorizacion_version FROM inscripciones WHERE referencia = ?').get(r.referencia);
  assert.deepEqual({ ...fila }, { autorizacion_ip: '10.0.0.9', autorizacion_version: 'v-prueba' });
  const detalle = ins.detalleAdmin(db.prepare('SELECT id FROM inscripciones WHERE referencia = ?').get(r.referencia).id);
  assert.match(detalle.soportes[0].alerta, /distinto del total/, 'el valor pagado no cubre al invitado');
});

test('inscripción con pago: todo o nada (pago inválido, sin cupo o repetido no deja inscripción)', () => {
  const total = () => db.prepare('SELECT COUNT(*) AS n FROM inscripciones').get().n;
  assert.throws(() => conPago('1001', { agenciaPago: 'NO-EXISTE' }), /agencia donde realizó el pago/);
  assert.throws(() => conPago('1001', { fechaPago: '2030-01-01' }), /no puede ser futura/);
  assert.throws(() => conPago('1001', { campos: {} }), /Correo/);
  assert.throws(() => conPago('1001', { autorizaDatos: false }), /autorización/);
  assert.equal(total(), 0);
  db.prepare("UPDATE tarifas SET cupos = 0 WHERE agencia = 'BOGOTA'").run();
  assert.throws(() => conPago('1001'), /superado el límite de cupos disponibles/);
  assert.equal(total(), 0);
  assert.equal(fs.readdirSync(dirSoportes).length, 0, 'no quedan comprobantes huérfanos');
  db.prepare("UPDATE tarifas SET cupos = 100 WHERE agencia = 'BOGOTA'").run();
  conPago('1001');
  assert.throws(() => conPago('1002'), /recibo ya fue registrado/);
  assert.throws(() => conPago('1001', { recibo: 'RC-901' }), /preinscripción activa/);
  assert.equal(total(), 1);
});

test('inscripción con pago: el comprobante adjunto se guarda y se puede consultar después', () => {
  const r = conPago('1002', { archivo: { nombre: 'recibo.pdf', contenido: PDF }, valorPagado: '43500' });
  assert.equal(r.soporte.conArchivo, true);
  assert.equal(fs.readdirSync(dirSoportes).length, 1);
  assert.equal(ins.consultar(cred('1002')).referencia, r.referencia);
});

test('autorización de uso de imagen: opcional, no condiciona la inscripción y queda registrada', () => {
  const sin = ins.preinscribir({ ...cred('1001'), autorizaDatos: true }, { ip: '10.0.0.1' });
  const con = conPago('1002', { autorizaImagen: true });
  const imagen = (ref) => db.prepare('SELECT autorizacion_imagen AS v FROM inscripciones WHERE referencia = ?').get(ref).v;
  assert.equal(imagen(sin.referencia), 0);
  assert.equal(imagen(con.referencia), 1);
  assert.equal(ins.preinscribir({ ...cred('2001'), autorizaDatos: true, autorizaImagen: 'si' }).estado, 'PREINSCRITO');
  assert.equal(imagen(db.prepare("SELECT referencia FROM inscripciones WHERE documento_titular = '2001'").get().referencia), 0,
    'solo true cuenta como autorización');
  const csv = ins.exportarCsv();
  assert.match(csv.split('\r\n')[0], /Autoriza uso de imagen/);
  assert.match(csv, new RegExp(`${con.referencia};.*;Sí;`));
});

test('evento compartido: CARTAGENA y PTO. MAMONAL asisten a "CARTAGENA Y MAMONAL" y comparten sus cupos', () => {
  const { hmacFecha } = require('../src/infraestructura/secreto');
  const insertar = db.prepare(`INSERT INTO asociados (documento, nombre, agencia, estado, fecha_actualizacion, expedicion_hmac)
                               VALUES (?, ?, ?, 'ACTIVO', '2026-08-01', ?)`);
  insertar.run('3301', 'Ana Ruiz Paz', 'CARTAGENA', hmacFecha('2001-01-01'));
  insertar.run('3302', 'Luis Mora Gil', 'PTO. MAMONAL', hmacFecha('2002-02-02'));
  insertar.run('3303', 'Eva Díaz Rey', 'PTO. MAMONAL', hmacFecha('2003-03-03'));
  db.prepare("UPDATE tarifas SET cupos = 2 WHERE agencia = 'CARTAGENA Y MAMONAL'").run();
  const pago = (documento, fecha, extra = {}) => ins.inscribirConPago({
    documento, fechaExpedicion: fecha, acompanantes: [], medioPago: 'AGENCIA', agenciaPago: extra.agenciaPago || 'PTO. MAMONAL',
    fechaPago: '2026-10-05', valorPagado: '50000', campos: { correo: 'a@b.co' }, autorizaDatos: true,
  });
  const a = pago('3301', '2001-01-01');
  assert.equal(a.agencia, 'CARTAGENA Y MAMONAL', 'el evento es el compartido');
  assert.equal(a.agenciaAsociado, 'CARTAGENA', 'se conserva la agencia del asociado');
  assert.equal(a.soporte.agenciaPago, 'PTO. MAMONAL', 'se puede pagar en cualquiera de las dos agencias');
  pago('3302', '2002-02-02', { agenciaPago: 'CARTAGENA' });
  const evento = ins.cupos().find((c) => c.agencia === 'CARTAGENA Y MAMONAL');
  assert.equal(evento.ocupados, 2, 'una inscripción de cada agencia ocupa los mismos cupos');
  assert.throws(() => pago('3303', '2003-03-03'), /superado el límite de cupos disponibles para el evento de CARTAGENA Y MAMONAL/);
  assert.ok(!ins.cupos().some((c) => c.agencia === 'CARTAGENA' || c.agencia === 'PTO. MAMONAL'), 'no hay cupos separados por agencia');
});
