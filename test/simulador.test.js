const { test } = require('node:test');
const assert = require('node:assert/strict');
const { abrirBaseDatos } = require('../src/infraestructura/db');
const { crearSimulador, ErrorValidacion, normalizarDocumento } = require('../src/aplicacion/simulador');

const path = require('node:path');

const SEMILLA = path.join(__dirname, '..', 'data', 'asociados.seed.json');
const db = abrirBaseDatos(':memory:', { datosPrueba: true });
const HOY = new Date(2026, 8, 29); // 29/09/2026
const simulador = crearSimulador(db, { hoy: () => HOY });
// Fecha de expedición correcta de cada asociado de prueba (segunda validación).
const EXPEDICION = Object.fromEntries(require(SEMILLA).map((a) => [a.documento, a.fecha_expedicion]));
const simular = (datos) => simulador.simular({ fechaExpedicion: EXPEDICION[normalizarDocumento(datos.documento)], ...datos });

test('asociado solo paga el valor asumido por asociado de su agencia', () => {
  const r = simular({ documento: '1001', nombre: 'Persona Prueba' }); // BOGOTA
  assert.equal(r.modalidad, 'SOLO');
  assert.equal(r.resumen.total, 43500);
});

test('acompañante asociado paga tarifa de asociado; no asociado paga tarifa invitado', () => {
  const r = simular({ documento: '2001', acompanantes: [{ documento: '2002', nombre: 'Persona Prueba' }, { documento: '90777', nombre: 'Persona Prueba' }] }); // ORITO
  assert.deepEqual(r.acompanantes.map((a) => [a.tipo, a.valor]), [['ASOCIADO', 33000], ['INVITADO', 110000]]);
  assert.equal(r.resumen.total, 33000 + 33000 + 110000);
});

test('acompañante asociado de otra agencia usa la tarifa de la agencia del titular', () => {
  const r = simular({ documento: '3001', acompanantes: [{ documento: '1001', nombre: 'Persona Prueba' }] }); // CALI
  assert.equal(r.acompanantes[0].valor, 42000);
});

test('acompañante asociado inactivo se liquida como invitado', () => {
  const r = simular({ documento: '1001', acompanantes: [{ documento: '9001', nombre: 'Persona Prueba' }] });
  assert.equal(r.acompanantes[0].tipo, 'INVITADO');
  assert.equal(r.acompanantes[0].valor, 145000);
});

test('documentos se normalizan (puntos y espacios)', () => {
  assert.equal(simular({ documento: ' 1.001 ', nombre: 'Persona  1.001 ' }).asociado.documento, '1001');
});

for (const [caso, datos] of [
  ['titular inexistente', { documento: '0000', nombre: 'Persona Prueba' }],
  ['titular inactivo', { documento: '9001', nombre: 'Persona Prueba' }],
  ['titular vacío', { documento: '' }],
  ['acompañante sin documento', { documento: '1001', acompanantes: [{ documento: '' }] }],
  ['acompañante igual al titular', { documento: '1001', acompanantes: [{ documento: '1001', nombre: 'Persona Prueba' }] }],
  ['acompañantes repetidos', { documento: '1001', acompanantes: [{ documento: '90005', nombre: 'Persona Prueba' }, { documento: '90005', nombre: 'Persona Prueba' }] }],
  ['exceso de acompañantes', { documento: '1001', acompanantes: Array.from({ length: 6 }, (_, i) => ({ documento: `x${i}`, nombre: 'Persona Prueba' })) }],
]) {
  test(`rechaza: ${caso}`, () => assert.throws(() => simular(datos), ErrorValidacion));
}

test('rechaza titular con datos actualizados hace más de 12 meses', () => {
  assert.throws(() => simular({ documento: '4001', nombre: 'Persona Prueba' }), /12 meses/); // 12/06/2025
});

test('rechaza titular sin fecha de actualización de datos', () => {
  assert.throws(() => simular({ documento: '6001', nombre: 'Persona Prueba' }), /No registra actualización/);
});

test('límite de vigencia: exactamente 12 meses es válido, un día más no', () => {
  db.prepare("UPDATE asociados SET fecha_actualizacion = '2025-09-29' WHERE documento = '1002'").run();
  assert.equal(simular({ documento: '1002', nombre: 'Persona Prueba' }).resumen.total, 43500);
  db.prepare("UPDATE asociados SET fecha_actualizacion = '2025-09-28' WHERE documento = '1002'").run();
  assert.throws(() => simular({ documento: '1002', nombre: 'Persona Prueba' }), ErrorValidacion);
  db.prepare("UPDATE asociados SET fecha_actualizacion = '2026-07-01' WHERE documento = '1002'").run();
});

test('la vigencia de datos no aplica a acompañantes asociados', () => {
  const r = simular({ documento: '1001', acompanantes: [{ documento: '4001', nombre: 'Persona Prueba' }] });
  assert.equal(r.acompanantes[0].tipo, 'ASOCIADO');
});

test('rechaza fecha de expedición incorrecta con el mismo mensaje que un documento inexistente', () => {
  const mal = () => simular({ documento: '3001', fechaExpedicion: '1999-01-01' });
  const inexistente = () => simular({ documento: '888888', fechaExpedicion: '1999-01-01' });
  assert.throws(mal, /no coinciden/);
  assert.throws(inexistente, /no coinciden/);
});

test('exige la fecha de expedición', () => {
  assert.throws(() => simulador.simular({ documento: '1001', nombre: 'Persona Prueba' }), /fecha de expedición/);
});

test('bloquea el documento tras 10 intentos fallidos', () => {
  for (let i = 0; i < 10; i++) assert.throws(() => simular({ documento: '5001', fechaExpedicion: '2000-01-01' }), /no coinciden/);
  assert.throws(() => simular({ documento: '5001', nombre: 'Persona Prueba' }), /Demasiados intentos/);
});

test('el nombre del acompañante es obligatorio', () => {
  assert.throws(() => simular({ documento: '1001', acompanantes: [{ documento: '90555' }] }), /nombres y apellidos/);
  assert.throws(() => simular({ documento: '1001', acompanantes: [{ documento: '90555', nombre: '  ' }] }), /nombres y apellidos/);
});

test('Coopetrolito hijo del titular paga tarifa de asociado', () => {
  const r = simular({ documento: '1001', acompanantes: [{ documento: '1100001', nombre: 'Mariana Torres', tipo: 'COOPETROLITO' }] });
  assert.equal(r.acompanantes[0].tipo, 'COOPETROLITO');
  assert.equal(r.acompanantes[0].valor, 43500);
  assert.equal(r.resumen.coopetrolitos, 1);
  assert.equal(r.resumen.total, 43500 * 2);
});

test('rechaza Coopetrolito que no existe o que no es hijo del titular', () => {
  const hijoDeOtro = { documento: '1100003', nombre: 'Valentina Ramírez', tipo: 'COOPETROLITO' }; // hijo de 2001
  assert.throws(() => simular({ documento: '1001', acompanantes: [hijoDeOtro] }), /no figura como Coopetrolito/);
  assert.throws(() => simular({ documento: '1001', acompanantes: [{ documento: '90999', nombre: 'Niño Prueba', tipo: 'COOPETROLITO' }] }), /no figura como Coopetrolito/);
});

test('un Coopetrolito marcado como no asociado se liquida como invitado', () => {
  const r = simular({ documento: '1001', acompanantes: [{ documento: '1100001', nombre: 'Mariana Torres', tipo: 'NO_ASOCIADO' }] });
  assert.equal(r.acompanantes[0].tipo, 'INVITADO');
});

test('un acompañante asociado se detecta por documento aunque se elija otro tipo', () => {
  const r = simular({ documento: '1001', acompanantes: [{ documento: '1002', nombre: 'Carlos Pérez', tipo: 'COOPETROLITO' }] });
  assert.equal(r.acompanantes[0].tipo, 'ASOCIADO');
});

test('el documento del acompañante debe ser numérico y el nombre incluir apellidos', () => {
  assert.throws(() => simular({ documento: '1001', acompanantes: [{ documento: 'AB12345', nombre: 'Ana Pérez' }] }), /solo debe contener números/);
  assert.throws(() => simular({ documento: '1001', acompanantes: [{ documento: '12345678', nombre: 'Ana' }] }), /nombres y apellidos/);
  assert.equal(simular({ documento: '1001', acompanantes: [{ documento: '12.345.678', nombre: 'Ana Pérez' }] }).acompanantes[0].documento, '12345678');
});

test('reglas por tipo de campo: documento numérico y nombres solo con letras', () => {
  assert.throws(() => simulador.consultarAsociado('10A1', '2008-03-14'), /solo debe contener números/);
  assert.throws(() => simular({ documento: '1001', acompanantes: [{ documento: '12345678', nombre: 'Ana P3rez' }] }), /solo debe contener letras/);
  assert.equal(simular({ documento: '1001', acompanantes: [{ documento: '12345678', nombre: "María José O'Neill-Peña" }] }).acompanantes[0].nombre, "María José O'Neill-Peña");
});
