const { test, beforeEach } = require('node:test');
const assert = require('node:assert/strict');
const { abrirBaseDatos } = require('../src/infraestructura/db');
const { crearAdministracion } = require('../src/aplicacion/admin');
const { crearGestion } = require('../src/aplicacion/gestion');
const { crearSimulador, ErrorValidacion } = require('../src/aplicacion/simulador');
const { crearInscripciones } = require('../src/aplicacion/inscripciones');
const { hmacFecha } = require('../src/infraestructura/secreto');

const AHORA = new Date(2026, 9, 5);
let db, admin, g;
beforeEach(() => {
  db = abrirBaseDatos(':memory:', { datosPrueba: true });
  admin = crearAdministracion(db, { ahora: () => AHORA });
  admin.crearUsuario('jefe', 'Jefe Área', 'clave-segura-123');
  g = crearGestion(db, admin, { ahora: () => AHORA });
});

const nuevo = {
  documento: '80123456', nombre: 'Laura Gómez Díaz', agencia: 'cali', estado: 'ACTIVO',
  fechaActualizacion: '2026-08-01', fechaExpedicion: '2004-06-15',
};

test('crear asociado: valida, guarda la expedición como HMAC y no la expone', () => {
  g.crearAsociado(nuevo, 'jefe');
  const fila = db.prepare("SELECT * FROM asociados WHERE documento = '80123456'").get();
  assert.equal(fila.agencia, 'CALI');
  assert.equal(fila.expedicion_hmac, hmacFecha('2004-06-15'));
  const listado = g.listarAsociados({ q: 'Laura' });
  assert.equal(listado.total, 1);
  assert.equal(listado.filas[0].expedicion_registrada, 1);
  assert.ok(!('expedicion_hmac' in listado.filas[0]));
  const sim = crearSimulador(db, { hoy: () => AHORA });
  assert.equal(sim.consultarAsociado('80123456', '2004-06-15').asociado.nombre, 'Laura Gómez Díaz');
});

test('crear asociado: rechaza duplicado, agencia inexistente, nombre incompleto y fechas inválidas', () => {
  g.crearAsociado(nuevo, 'jefe');
  assert.throws(() => g.crearAsociado(nuevo, 'jefe'), /Ya existe/);
  assert.throws(() => g.crearAsociado({ ...nuevo, documento: '1111', agencia: 'MARTE' }), /agencia/);
  assert.throws(() => g.crearAsociado({ ...nuevo, documento: '2222', nombre: 'Laura' }), /nombres y apellidos/);
  assert.throws(() => g.crearAsociado({ ...nuevo, documento: '3333', fechaExpedicion: '2004-02-31' }), /no es válida/);
  assert.throws(() => g.crearAsociado({ ...nuevo, documento: '4444', fechaExpedicion: '' }), /fecha de expedición/);
  assert.throws(() => g.crearAsociado({ ...nuevo, documento: 'AB12' }), /números/);
});

test('editar asociado: conserva la expedición si no se envía y la cambia si se envía', () => {
  g.crearAsociado(nuevo, 'jefe');
  g.editarAsociado('80123456', { ...nuevo, nombre: 'Laura Gómez Ruiz', estado: 'INACTIVO', fechaExpedicion: '' }, 'jefe');
  let fila = db.prepare("SELECT * FROM asociados WHERE documento = '80123456'").get();
  assert.equal(fila.nombre, 'Laura Gómez Ruiz');
  assert.equal(fila.estado, 'INACTIVO');
  assert.equal(fila.expedicion_hmac, hmacFecha('2004-06-15'));
  g.editarAsociado('80123456', { ...nuevo, fechaExpedicion: '2004-06-16' }, 'jefe');
  fila = db.prepare("SELECT * FROM asociados WHERE documento = '80123456'").get();
  assert.equal(fila.expedicion_hmac, hmacFecha('2004-06-16'));
});

test('eliminar asociado: bloqueado con inscripción activa o Coopetrolitos vinculados', () => {
  const sim = crearSimulador(db, { hoy: () => AHORA });
  crearInscripciones(db, sim, { config: {}, dirSoportes: '.', ahora: () => AHORA })
    .preinscribir({ documento: '3001', fechaExpedicion: '2011-08-02', autorizaDatos: true });
  assert.throws(() => g.eliminarAsociado('3001', 'jefe'), /inscripción activa/);
  assert.throws(() => g.eliminarAsociado('1001', 'jefe'), /Coopetrolito/); // tiene hijos en los datos de prueba
  g.eliminarAsociado('5001', 'jefe');
  assert.equal(db.prepare("SELECT COUNT(*) AS n FROM asociados WHERE documento = '5001'").get().n, 0);
});

test('Coopetrolitos: CRUD y validación del asociado', () => {
  g.crearCoopetrolito({ documento: '1109999', nombre: 'Tomás Ruiz Pérez', documentoAsociado: '3001' }, 'jefe');
  assert.throws(() => g.crearCoopetrolito({ documento: '1108888', nombre: 'Ana Ruiz', documentoAsociado: '999999' }), /No existe un asociado/);
  assert.throws(() => g.crearCoopetrolito({ documento: '1109999', nombre: 'Tomás Ruiz', documentoAsociado: '3001' }), /Ya existe/);
  g.editarCoopetrolito('1109999', { nombre: 'Tomás Ruiz Gómez', documentoAsociado: '2001' }, 'jefe');
  const l = g.listarCoopetrolitos({ q: 'Tomás' });
  assert.equal(l.filas[0].documento_asociado, '2001');
  assert.equal(l.filas[0].nombre_asociado, 'Jorge Iván Ramírez');
  g.eliminarCoopetrolito('1109999', 'jefe');
  assert.equal(g.listarCoopetrolitos({ q: 'Tomás' }).total, 0);
});

test('usuarios: crear, cambiar clave cierra sesiones, no eliminar al propio ni al último', () => {
  g.crearUsuario({ usuario: 'Ana.Lopez', nombre: 'Ana López', clave: 'otra-clave-segura', rol: 'REVISOR' }, 'jefe');
  assert.throws(() => g.crearUsuario({ usuario: 'ana.lopez', nombre: 'X', clave: 'otra-clave-segura', rol: 'REVISOR' }, 'jefe'), /ya existe/);
  assert.throws(() => g.crearUsuario({ usuario: 'corto', nombre: 'X', clave: '123', rol: 'REVISOR' }, 'jefe'), /10 caracteres/);
  const { token } = admin.iniciarSesion('ana.lopez', 'otra-clave-segura');
  g.editarUsuario('ana.lopez', { nombre: 'Ana López', clave: 'clave-nueva-segura' }, 'jefe');
  assert.equal(admin.validarSesion(token), null);
  assert.ok(admin.iniciarSesion('ana.lopez', 'clave-nueva-segura').token);
  assert.throws(() => g.eliminarUsuario('jefe', 'jefe'), /propio usuario/);
  g.eliminarUsuario('ana.lopez', 'jefe');
  assert.deepEqual(g.listarUsuarios().map((u) => u.usuario), ['jefe']);
  admin.crearUsuario('otro', 'Otro', 'clave-segura-456');
  g.eliminarUsuario('jefe', 'otro');
  assert.throws(() => g.eliminarUsuario('otro', 'x'), /al menos un administrador/);
});

test('auditoría registra quién hizo cada cambio', () => {
  g.crearAsociado(nuevo, 'jefe');
  g.editarAsociado('80123456', { ...nuevo, fechaExpedicion: '2004-06-16' }, 'jefe');
  const a = g.auditoria();
  assert.deepEqual(a.map((x) => x.accion), ['EDITAR', 'CREAR']);
  assert.equal(a[0].detalle, 'incluye fecha de expedición');
  assert.ok(!JSON.stringify(a).includes('2004-06-16'), 'la auditoría no guarda la fecha de expedición');
});

test('paginación del listado de asociados', () => {
  const r = g.listarAsociados({ pagina: 1 });
  assert.equal(r.total, 10);
  assert.equal(r.paginas, 1);
  assert.throws(() => g.editarAsociado('000000', nuevo, 'jefe'), ErrorValidacion);
});

test('reglas por tipo en la gestión manual: nombres solo letras, documentos numéricos', () => {
  assert.throws(() => g.crearAsociado({ ...nuevo, documento: '5551', nombre: 'Laura G0mez' }, 'jefe'), /letras y espacios/);
  assert.throws(() => g.crearCoopetrolito({ documento: '1107777', nombre: 'Tomás Ruiz', documentoAsociado: '30O1' }, 'jefe'), /números/);
});

test('roles: el revisor se crea con su rol, no se puede quitar el último administrador ni cambiar el propio rol', () => {
  assert.throws(() => g.crearUsuario({ usuario: 'sinrol', nombre: 'Sin Rol', clave: 'clave-segura-999' }, 'jefe'), /rol/);
  g.crearUsuario({ usuario: 'rev', nombre: 'Revisora Uno', clave: 'clave-segura-999', rol: 'REVISOR' }, 'jefe');
  assert.equal(admin.iniciarSesion('rev', 'clave-segura-999').rol, 'REVISOR');
  assert.throws(() => g.editarUsuario('jefe', { nombre: 'Jefe Área', rol: 'REVISOR' }, 'jefe'), /propio rol/);
  assert.throws(() => g.editarUsuario('jefe', { nombre: 'Jefe Área', rol: 'REVISOR' }, 'rev'), /al menos un administrador/);
  const { token } = admin.iniciarSesion('rev', 'clave-segura-999');
  g.editarUsuario('rev', { nombre: 'Revisora Uno', rol: 'ADMINISTRADOR' }, 'jefe');
  assert.equal(admin.validarSesion(token), null, 'cambiar el rol cierra las sesiones');
  assert.equal(admin.iniciarSesion('rev', 'clave-segura-999').rol, 'ADMINISTRADOR');
  assert.match(g.auditoria()[0].detalle, /REVISOR → ADMINISTRADOR/);
  admin.crearUsuario('rev', 'Revisora Uno', 'clave-nueva-9999'); // cambio de clave por consola: conserva el rol
  assert.equal(admin.iniciarSesion('rev', 'clave-nueva-9999').rol, 'ADMINISTRADOR');
});

test('fecha de nacimiento: opcional en asociados y Coopetrolitos, validada y editable', () => {
  g.crearAsociado({ ...nuevo, documento: '4444', fechaNacimiento: '1985-05-20' }, 'admin');
  assert.equal(g.listarAsociados({ q: '4444' }).filas[0].fecha_nacimiento, '1985-05-20');
  g.editarAsociado('4444', { ...nuevo, fechaNacimiento: '' }, 'admin');
  assert.equal(g.listarAsociados({ q: '4444' }).filas[0].fecha_nacimiento, null);
  assert.throws(() => g.crearAsociado({ ...nuevo, documento: '4445', fechaNacimiento: '2999-01-01' }, 'admin'), /nacimiento no es válida/);
  g.crearCoopetrolito({ documento: '1100004444', nombre: 'Sara Ruiz Paz', documentoAsociado: '4444', fechaNacimiento: '2015-06-10' }, 'admin');
  assert.equal(g.listarCoopetrolitos({ q: '1100004444' }).filas[0].fecha_nacimiento, '2015-06-10');
});
