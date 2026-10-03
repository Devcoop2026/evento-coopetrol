const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { DatabaseSync } = require('node:sqlite');
const { abrirBaseDatos } = require('../src/infraestructura/db');
const { crearBases } = require('../src/aplicacion/bases');
const { crearSimulador, ErrorValidacion } = require('../src/aplicacion/simulador');
const { hmacFecha } = require('../src/infraestructura/secreto');

const XLSX = fs.readFileSync(path.join(__dirname, 'fixtures', 'asociados.xlsx'));
const HOY = new Date(2026, 8, 30);
const nueva = () => {
  const db = abrirBaseDatos(':memory:');
  return { db, bases: crearBases(db, { ahora: () => HOY }), simulador: crearSimulador(db, { hoy: () => HOY }) };
};

test('vista previa de Excel: valida filas sin guardar nada', () => {
  const { db, bases } = nueva();
  const r = bases.cargar('asociados', XLSX);
  assert.equal(r.guardado, false);
  assert.equal(r.registros, 3);
  assert.equal(r.activos, 2);
  assert.equal(r.omitidas, 2);
  assert.match(r.errores.join('|'), /MARTE/);
  assert.match(r.errores.join('|'), /repetido/);
  assert.match(r.advertencias.join('|'), /1 asociado\(s\) sin fecha de expedición/);
  assert.equal(db.prepare('SELECT COUNT(*) AS n FROM asociados').get().n, 0);
});

test('al confirmar reemplaza la base, guarda la fecha como HMAC y registra la carga', () => {
  const { db, bases, simulador } = nueva();
  bases.cargar('asociados', XLSX, { confirmar: true, archivo: 'asociados.xlsx', usuario: 'tesoreria' });
  const fila = db.prepare("SELECT * FROM asociados WHERE documento = '5550002'").get();
  assert.equal(fila.nombre, 'Prueba Dos');
  assert.equal(fila.fecha_actualizacion, '2026-06-01');
  assert.equal(fila.expedicion_hmac, hmacFecha('1999-08-15'));
  assert.ok(!Object.values(fila).includes('1999-08-15'), 'la fecha de expedición no debe quedar en texto plano');
  assert.equal(simulador.consultarAsociado('5550002', '1999-08-15').asociado.nombre, 'Prueba Dos');
  assert.throws(() => simulador.consultarAsociado('5550002', '1999-08-16'), ErrorValidacion);
  const estado = bases.estado();
  assert.equal(estado.asociados.total, 3);
  assert.equal(estado.asociados.ultimaCarga.usuario, 'tesoreria');
});

test('CSV con punto y coma, tildes y fechas de texto', () => {
  const { bases } = nueva();
  const csv = Buffer.from('Cédula;Nombre;Agencia;Asociado;Última actualización de datos;Fecha expedición\n'
    + '777;Ana Pérez;BOGOTA;SI;15/02/2026;2008-03-14\n888;Luis;CALI;SI;31/02/2026;2008-03-14\n', 'latin1');
  const r = bases.cargar('asociados', csv);
  assert.equal(r.registros, 1);
  assert.match(r.errores[0], /Fila 3: fecha no válida/);
});

test('rechaza archivos sin las columnas requeridas o sin registros', () => {
  const { bases } = nueva();
  assert.throws(() => bases.cargar('asociados', Buffer.from('Nombre;Agencia\nAna;BOGOTA\n')), /Faltan columnas: cedula/);
  assert.throws(() => bases.cargar('asociados', Buffer.from('')), /vacío/);
  assert.throws(() => bases.cargar('asociados', Buffer.from([0x50, 0x4b, 0x03, 0x04, 1, 2, 3])), ErrorValidacion);
  assert.throws(() => bases.cargar('otra', XLSX), /Tipo de base/);
});

test('Coopetrolitos: carga y advierte cédulas de asociado desconocidas', () => {
  const { bases, simulador } = nueva();
  bases.cargar('asociados', XLSX, { confirmar: true, usuario: 'admin' });
  const csv = Buffer.from('Documento,Nombre,Cedula asociado\n1100009,Hija Prueba,5550001\n1100010,Otro,999\n');
  const r = bases.cargar('coopetrolitos', csv, { confirmar: true, usuario: 'admin' });
  assert.equal(r.registros, 2);
  assert.match(r.advertencias[0], /1 Coopetrolito/);
  const sim = simulador.simular({
    documento: '5550001', fechaExpedicion: '2001-05-20',
    acompanantes: [{ documento: '1100009', nombre: 'Hija Prueba', tipo: 'COOPETROLITO' }],
  });
  assert.equal(sim.acompanantes[0].tipo, 'COOPETROLITO');
});

test('sin DATOS_PRUEBA no se cargan asociados ficticios', () => {
  const { db } = nueva();
  assert.equal(db.prepare('SELECT COUNT(*) AS n FROM asociados').get().n, 0);
});

test('migración: fechas de expedición en texto plano pasan a HMAC y se elimina la columna', () => {
  const archivo = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'migra-')), 'antigua.db');
  const antigua = new DatabaseSync(archivo);
  antigua.exec(`CREATE TABLE asociados (documento TEXT PRIMARY KEY, nombre TEXT NOT NULL, agencia TEXT NOT NULL,
    estado TEXT NOT NULL DEFAULT 'ACTIVO', fecha_actualizacion TEXT, fecha_expedicion TEXT);
    INSERT INTO asociados VALUES ('42', 'Antiguo', 'BOGOTA', 'ACTIVO', '2026-05-01', '2005-01-02');`);
  antigua.close();
  const db = abrirBaseDatos(archivo);
  const columnas = db.prepare('PRAGMA table_info(asociados)').all().map((c) => c.name);
  assert.ok(!columnas.includes('fecha_expedicion'));
  assert.equal(db.prepare("SELECT expedicion_hmac FROM asociados WHERE documento = '42'").get().expedicion_hmac, hmacFecha('2005-01-02'));
  db.close();
  assert.ok(!fs.readFileSync(archivo).includes('2005-01-02'), 'la fecha no debe quedar en el archivo de la base');
});

test('carga masiva: omite filas con documento no numérico o nombre con números', () => {
  const { bases } = nueva();
  const csv = Buffer.from('Cedula;Nombre;Agencia;Asociado;Ultima actualizacion de datos;Fecha expedicion\n'
    + 'AB123;Ana Pérez;BOGOTA;SI;15/02/2026;14/03/2008\n777;Luis 2 Gómez;CALI;SI;15/02/2026;14/03/2008\n888;Luis Gómez;CALI;SI;15/02/2026;14/03/2008\n');
  const r = bases.cargar('asociados', csv);
  assert.equal(r.registros, 1);
  assert.match(r.errores.join('|'), /Fila 2: el documento "AB123" solo debe contener números/);
  assert.match(r.errores.join('|'), /Fila 3: el nombre "Luis 2 Gómez" solo debe contener letras/);
});

test('formato nuevo de columnas (actualizacion_datos, fecha_expedicion, fecha_nacimiento) y anterior sin nacimiento', () => {
  const { db, bases, simulador } = nueva();
  const nuevo = 'Cedula;Nombre;Agencia;Asociado;actualizacion_datos;fecha_expedicion;fecha_nacimiento\n'
    + '7001;Ana María Ruiz;BOGOTA;SI;15/02/2026;14/03/2008;20/05/1985\n'
    + '7002;Luis Pérez Gil;CALI;NO;20/11/2025;22/07/2010;\n'
    + '7003;Eva Díaz Mora;CALI;SI;20/11/2025;22/07/2010;30/02/1990\n'
    + '7004;Juan Gómez Ríos;CALI;SI;20/11/2025;22/07/2010;01/01/2999\n';
  const r = bases.cargar('asociados', Buffer.from(nuevo), { confirmar: true, archivo: 'a.csv', usuario: 'p' });
  assert.equal(r.registros, 2);
  assert.match(r.errores.join('|'), /Fila 4: fecha no válida/);
  assert.match(r.errores.join('|'), /Fila 5: la fecha de nacimiento 2999-01-01 no es válida/);
  const ana = db.prepare("SELECT * FROM asociados WHERE documento = '7001'").get();
  assert.deepEqual([ana.fecha_actualizacion, ana.fecha_nacimiento], ['2026-02-15', '1985-05-20']);
  assert.equal(db.prepare("SELECT fecha_nacimiento AS f FROM asociados WHERE documento = '7002'").get().f, null);
  assert.equal(simulador.consultarAsociado('7001', '2008-03-14').asociado.nombre, 'Ana María Ruiz');
  // El formato anterior (sin fecha de nacimiento) sigue funcionando.
  const anterior = 'Cedula;Nombre;Agencia;Asociado;Ultima actualizacion de datos;Fecha expedicion\n8001;Rosa Vega Paz;BOGOTA;SI;15/02/2026;14/03/2008\n';
  assert.equal(bases.cargar('asociados', Buffer.from(anterior)).registros, 1);
  // Coopetrolitos con fecha de nacimiento.
  const coop = 'Documento;Nombre;Cedula asociado;fecha_nacimiento\n1100009001;Sara Ruiz Paz;7001;10/06/2015\n';
  bases.cargar('coopetrolitos', Buffer.from(coop), { confirmar: true, archivo: 'c.csv', usuario: 'p' });
  assert.equal(db.prepare("SELECT fecha_nacimiento AS f FROM coopetrolitos WHERE documento = '1100009001'").get().f, '2015-06-10');
  assert.match(bases.plantilla('asociados'), /actualizacion_datos;fecha_expedicion;fecha_nacimiento/);
  assert.match(bases.plantilla('coopetrolitos'), /Cedula asociado;fecha_nacimiento/);
});

test('resume las agencias no reconocidas con su cantidad de filas', () => {
  const { bases } = nueva();
  const csv = 'Cedula;Nombre;Agencia;Asociado;actualizacion_datos;fecha_expedicion\n'
    + '9001;Ana Ruiz Paz;PTO. LUNA;SI;15/02/2026;14/03/2008\n9002;Luis Mora Gil;PTO. LUNA;SI;15/02/2026;14/03/2008\n'
    + '9003;Eva Díaz Rey;MARTE;SI;15/02/2026;14/03/2008\n9004;Juan Gil Paz;PTO. MAMONAL;SI;15/02/2026;14/03/2008\n';
  const r = bases.cargar('asociados', Buffer.from(csv));
  assert.equal(r.registros, 1, 'PTO. MAMONAL asiste al evento CARTAGENA Y MAMONAL');
  assert.match(r.advertencias.join('|'), /Agencias no reconocidas: PTO\. LUNA \(2\), MARTE \(1\)/);
});
