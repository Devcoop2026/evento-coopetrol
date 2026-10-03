const test = require('node:test');
const assert = require('node:assert');
const { DatabaseSync } = require('node:sqlite');
const { migrar, VERSION_ACTUAL } = require('../src/infraestructura/migraciones');
const { coincideFecha } = require('../src/infraestructura/secreto');

const version = (db) => db.prepare('PRAGMA user_version').get().user_version;
const columnas = (db, tabla) => db.prepare(`PRAGMA table_info(${tabla})`).all().map((c) => c.name);

test('base nueva: aplica todas las migraciones y queda en la versión actual', () => {
  const db = new DatabaseSync(':memory:');
  assert.deepStrictEqual(migrar(db), Array.from({ length: VERSION_ACTUAL }, (_, i) => i + 1));
  assert.strictEqual(version(db), VERSION_ACTUAL);
  assert.ok(columnas(db, 'administradores').includes('rol'));
  assert.ok(columnas(db, 'soportes').includes('medio_pago'));
  assert.deepStrictEqual(migrar(db), [], 'una segunda ejecución no aplica nada');
});

test('base anterior a las migraciones versionadas: completa columnas y convierte la fecha de expedición a HMAC', () => {
  const db = new DatabaseSync(':memory:');
  db.exec(`
    CREATE TABLE tarifas (agencia TEXT PRIMARY KEY, cupos INTEGER NOT NULL, valor_invitado INTEGER NOT NULL, valor_asociado INTEGER NOT NULL);
    CREATE TABLE asociados (documento TEXT PRIMARY KEY, nombre TEXT NOT NULL, agencia TEXT NOT NULL, estado TEXT NOT NULL DEFAULT 'ACTIVO',
                            fecha_expedicion TEXT);
    CREATE TABLE administradores (usuario TEXT PRIMARY KEY, nombre TEXT NOT NULL, salt TEXT NOT NULL, hash TEXT NOT NULL);
    INSERT INTO asociados (documento, nombre, agencia, fecha_expedicion) VALUES ('1001', 'Ana Pérez', 'BOGOTA', '2008-03-14');
    INSERT INTO administradores VALUES ('admin', 'Admin', 's', 'h');
  `);
  const avisos = [];
  migrar(db, { registrar: (m) => avisos.push(m) });
  assert.strictEqual(version(db), VERSION_ACTUAL);
  assert.ok(!columnas(db, 'asociados').includes('fecha_expedicion'), 'la columna en texto plano se elimina');
  const a = db.prepare('SELECT * FROM asociados').get();
  assert.ok(coincideFecha('2008-03-14', a.expedicion_hmac));
  assert.strictEqual(db.prepare('SELECT rol FROM administradores').get().rol, 'ADMINISTRADOR');
  assert.ok(avisos.length >= 1, 'en bases existentes se informan las migraciones aplicadas');
});

test('rechaza una base de una versión posterior de la aplicación', () => {
  const db = new DatabaseSync(':memory:');
  db.exec(`PRAGMA user_version = ${VERSION_ACTUAL + 1}`);
  assert.throws(() => migrar(db), /posterior a esta aplicación/);
});
