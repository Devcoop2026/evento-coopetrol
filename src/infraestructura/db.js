// Base de datos SQLite (módulo nativo de Node, sin dependencias).
// - Tarifas: se recargan desde data/tarifas.json en cada arranque (no son datos personales).
// - Asociados y Coopetrolitos: la base de datos es la fuente de verdad. Se cargan desde el panel de
//   administración o con scripts/cargar_base.js; nunca desde archivos del proyecto.
// - Esquema: migraciones versionadas en src/infraestructura/migraciones.js.
// - Datos de prueba (data/*.seed.json, ficticios): solo con DATOS_PRUEBA=1 o la opción datosPrueba.
const { DatabaseSync } = require('node:sqlite');
const fs = require('node:fs');
const path = require('node:path');
const { hmacFecha } = require('./secreto');
const { migrar } = require('./migraciones');

const DATA_DIR = path.join(__dirname, '..', '..', 'data');

function abrirBaseDatos(archivo = path.join(DATA_DIR, 'evento.db'), {
  datosPrueba = process.env.DATOS_PRUEBA === '1',
  importarAnteriores = archivo !== ':memory:', // las bases en memoria (pruebas) nunca leen archivos con datos reales
} = {}) {
  const db = new DatabaseSync(archivo);
  migrar(db, { registrar: console.log });
  cargarTarifas(db);
  if (datosPrueba) cargarDatosPrueba(db);
  else if (importarAnteriores) importarArchivosAnteriores(db);
  return db;
}

function cargarTarifas(db) {
  const datos = JSON.parse(fs.readFileSync(path.join(DATA_DIR, 'tarifas.json'), 'utf8'));
  db.prepare(`INSERT OR REPLACE INTO evento (id, nombre, inscripciones, cuenta_contable, concepto)
              VALUES (1, ?, ?, ?, ?)`)
    .run(datos.evento, datos.inscripciones, datos.cuenta_contable, datos.concepto);
  const upsert = db.prepare(`INSERT INTO tarifas (agencia, cupos, valor_invitado, valor_asociado)
                             VALUES (?, ?, ?, ?)
                             ON CONFLICT(agencia) DO UPDATE SET cupos = excluded.cupos,
                               valor_invitado = excluded.valor_invitado,
                               valor_asociado = excluded.valor_asociado`);
  for (const t of datos.agencias) upsert.run(t.agencia, t.cupos, t.valor_invitado, t.valor_asociado);
  cargarAgenciasEvento(db, datos.agencias.map((t) => t.agencia));
}

// Agencias que asisten a cada evento (data/agencias_evento.json). Un evento compartido (p. ej. "CARTAGENA Y MAMONAL")
// lista sus agencias; las demás agencias asisten al evento con su mismo nombre. Tarifas y cupos son los del evento.
function cargarAgenciasEvento(db, eventos) {
  const ruta = path.join(DATA_DIR, 'agencias_evento.json');
  const compartidos = fs.existsSync(ruta) ? JSON.parse(fs.readFileSync(ruta, 'utf8')) : {};
  const filas = new Map(eventos.map((e) => [e, e]));
  for (const [evento, agencias] of Object.entries(compartidos)) {
    if (evento.startsWith('_')) continue; // comentarios
    if (!filas.has(evento)) throw new Error(`agencias_evento.json: el evento "${evento}" no existe en tarifas.json`);
    filas.delete(evento); // el nombre del evento compartido no es una agencia donde se paga (sigue siendo válido como agencia)
    for (const agencia of agencias) filas.set(String(agencia).trim().toUpperCase(), evento);
  }
  const insertar = db.prepare('INSERT INTO agencias_evento (agencia, evento) VALUES (?, ?)');
  db.exec('BEGIN');
  try {
    db.exec('DELETE FROM agencias_evento');
    for (const [agencia, evento] of filas) insertar.run(agencia, evento);
    db.exec('COMMIT');
  } catch (err) {
    db.exec('ROLLBACK');
    throw err;
  }
}

function reemplazarAsociados(db, lista) {
  const insertar = db.prepare(`INSERT INTO asociados (documento, nombre, agencia, estado, fecha_actualizacion, expedicion_hmac)
                               VALUES (?, ?, ?, ?, ?, ?)`);
  db.exec('BEGIN');
  try {
    db.exec('DELETE FROM asociados');
    for (const a of lista) {
      insertar.run(a.documento, a.nombre, a.agencia, a.estado, a.fecha_actualizacion ?? null,
        a.fecha_expedicion ? hmacFecha(a.fecha_expedicion) : null);
    }
    db.exec('COMMIT');
  } catch (err) {
    db.exec('ROLLBACK');
    throw err;
  }
}

function reemplazarCoopetrolitos(db, lista) {
  const insertar = db.prepare('INSERT INTO coopetrolitos (documento, nombre, documento_asociado) VALUES (?, ?, ?)');
  db.exec('BEGIN');
  try {
    db.exec('DELETE FROM coopetrolitos');
    for (const c of lista) insertar.run(c.documento, c.nombre, c.documento_asociado);
    db.exec('COMMIT');
  } catch (err) {
    db.exec('ROLLBACK');
    throw err;
  }
}

const leerJson = (nombre) => JSON.parse(fs.readFileSync(path.join(DATA_DIR, nombre), 'utf8'));

// Datos ficticios para desarrollo y pruebas. Reemplazan las bases en cada arranque.
function cargarDatosPrueba(db) {
  reemplazarAsociados(db, leerJson('asociados.seed.json'));
  reemplazarCoopetrolitos(db, leerJson('coopetrolitos.seed.json'));
  console.log('DATOS_PRUEBA: se cargaron asociados y Coopetrolitos ficticios.');
}

// Compatibilidad: si quedaron data/asociados.json o data/coopetrolitos.json de versiones anteriores
// y la tabla está vacía, se importan una vez. Después conviene borrar esos archivos.
function importarArchivosAnteriores(db) {
  for (const [nombre, tabla, reemplazar] of [
    ['asociados.json', 'asociados', reemplazarAsociados],
    ['coopetrolitos.json', 'coopetrolitos', reemplazarCoopetrolitos],
  ]) {
    if (!fs.existsSync(path.join(DATA_DIR, nombre))) continue;
    if (db.prepare(`SELECT COUNT(*) AS n FROM ${tabla}`).get().n === 0) reemplazar(db, leerJson(nombre));
    console.warn(`Aviso: data/${nombre} contiene datos personales y ya no se usa. Cargue las bases desde el panel y elimine el archivo.`);
  }
}

// Recarga las tarifas cuando cambia data/tarifas.json, sin reiniciar el servidor.
function vigilarCambios(db) {
  let temporizador;
  fs.watch(DATA_DIR, (_evento, nombre) => {
    if (nombre !== 'tarifas.json' && nombre !== 'agencias_evento.json') return;
    clearTimeout(temporizador);
    temporizador = setTimeout(() => {
      try {
        cargarTarifas(db);
        console.log('Tarifas recargadas.');
      } catch (err) {
        console.error(`No se pudieron recargar las tarifas (se conservan las anteriores): ${err.message}`);
      }
    }, 500);
  });
}

function leerConfig() {
  const leer = (nombre, defecto) => {
    const ruta = path.join(DATA_DIR, nombre);
    return fs.existsSync(ruta) ? JSON.parse(fs.readFileSync(ruta, 'utf8')) : defecto;
  };
  return {
    // EVENTO_CONFIG permite usar otra configuración (p. ej. un ambiente de pruebas con otras fechas).
    config: process.env.EVENTO_CONFIG ? JSON.parse(fs.readFileSync(process.env.EVENTO_CONFIG, 'utf8')) : leer('config.json', {}),
    camposSoporte: leer('formulario_soporte.json', []),
    habeasData: leer('habeas_data.json', { version: 'sin-version', texto: [] }),
  };
}

module.exports = { abrirBaseDatos, vigilarCambios, leerConfig, DATA_DIR };
