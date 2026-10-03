// Migraciones versionadas del esquema SQLite. La versión aplicada se guarda en PRAGMA user_version.
//
// Reglas para agregar un cambio de esquema:
//   1. Añada un paso al final de MIGRACIONES con la versión siguiente (nunca edite un paso ya desplegado).
//   2. Cada paso corre en su propia transacción; si falla, la base queda en la versión anterior.
//   3. Los pasos deben ser idempotentes (comprobar la columna antes de agregarla): las bases creadas antes de existir
//      las migraciones versionadas (user_version = 0) pueden tener ya parte de los cambios.
//   4. `compactar: true` ejecuta VACUUM al terminar (salvo que `aplicar` devuelva false: no hubo cambios) (p. ej. tras borrar datos sensibles), fuera de la transacción.
const { hmacFecha } = require('./secreto');

const columnas = (db, tabla) => db.prepare(`PRAGMA table_info(${tabla})`).all().map((c) => c.name);
const agregarColumna = (db, tabla, columna, definicion) => {
  if (!columnas(db, tabla).includes(columna)) db.exec(`ALTER TABLE ${tabla} ADD COLUMN ${columna} ${definicion}`);
};

const MIGRACIONES = [
  {
    version: 1,
    descripcion: 'Esquema inicial',
    aplicar: (db) => db.exec(`
      CREATE TABLE IF NOT EXISTS evento (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        nombre TEXT NOT NULL,
        inscripciones TEXT,
        cuenta_contable TEXT,
        concepto TEXT
      );
      CREATE TABLE IF NOT EXISTS tarifas (
        agencia TEXT PRIMARY KEY,
        cupos INTEGER NOT NULL,
        valor_invitado INTEGER NOT NULL,
        valor_asociado INTEGER NOT NULL
      );
      CREATE TABLE IF NOT EXISTS asociados (
        documento TEXT PRIMARY KEY,
        nombre TEXT NOT NULL,
        agencia TEXT NOT NULL REFERENCES tarifas(agencia),
        estado TEXT NOT NULL DEFAULT 'ACTIVO'
      );
      -- Coopetrolitos: hijos de asociados (se cargan desde el panel de administración).
      CREATE TABLE IF NOT EXISTS coopetrolitos (
        documento TEXT PRIMARY KEY,
        nombre TEXT NOT NULL,
        documento_asociado TEXT NOT NULL -- documento del asociado (padre, madre o acudiente)
      );
      -- Estados: ver src/dominio/inscripcion.js.
      CREATE TABLE IF NOT EXISTS inscripciones (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        referencia TEXT NOT NULL UNIQUE,
        documento_titular TEXT NOT NULL,
        nombre_titular TEXT NOT NULL,
        agencia TEXT NOT NULL,          -- agencia del evento al que asiste (define tarifa y cupo)
        total INTEGER NOT NULL,
        estado TEXT NOT NULL,
        motivo TEXT,
        revisado_por TEXT,
        revisado_en TEXT,
        autorizacion_version TEXT, -- versión del texto de habeas data aceptado
        autorizacion_en TEXT,      -- fecha y hora de aceptación
        autorizacion_ip TEXT,
        creada_en TEXT NOT NULL,
        actualizada_en TEXT NOT NULL
      );
      CREATE INDEX IF NOT EXISTS ix_inscripciones_estado ON inscripciones (estado, agencia);
      CREATE TABLE IF NOT EXISTS inscripcion_personas (
        inscripcion_id INTEGER NOT NULL REFERENCES inscripciones(id),
        documento TEXT NOT NULL,
        nombre TEXT NOT NULL,
        tipo TEXT NOT NULL, -- TITULAR, ASOCIADO, COOPETROLITO, INVITADO
        valor INTEGER NOT NULL,
        PRIMARY KEY (inscripcion_id, documento)
      );
      CREATE INDEX IF NOT EXISTS ix_personas_documento ON inscripcion_personas (documento);
      CREATE TABLE IF NOT EXISTS soportes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        inscripcion_id INTEGER NOT NULL REFERENCES inscripciones(id),
        cus TEXT NOT NULL,
        banco TEXT NOT NULL,
        fecha_pago TEXT NOT NULL,
        valor_pagado INTEGER NOT NULL,
        campos TEXT NOT NULL DEFAULT '{}', -- campos adicionales del formulario (JSON)
        archivo TEXT NOT NULL,             -- nombre interno en SOPORTES_DIR ('' si no hay comprobante)
        tipo_archivo TEXT NOT NULL,
        nombre_original TEXT,
        alerta TEXT,
        autorizacion_version TEXT,
        cargado_en TEXT NOT NULL
      );
      CREATE INDEX IF NOT EXISTS ix_soportes_cus ON soportes (cus);
      -- Cupos editados desde el panel; tienen prioridad sobre los del Excel de tarifas.
      CREATE TABLE IF NOT EXISTS cupos_ajustados (
        agencia TEXT PRIMARY KEY,
        cupos INTEGER NOT NULL,
        actualizado_por TEXT,
        actualizado_en TEXT
      );
      -- Registro de cargas de las bases de asociados y Coopetrolitos.
      CREATE TABLE IF NOT EXISTS cargas_bases (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tipo TEXT NOT NULL,
        archivo TEXT,
        registros INTEGER NOT NULL,
        usuario TEXT NOT NULL,
        cargada_en TEXT NOT NULL
      );
      -- Registro de cambios manuales hechos desde el panel (quién, qué y cuándo).
      CREATE TABLE IF NOT EXISTS auditoria (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        usuario TEXT NOT NULL,
        accion TEXT NOT NULL,   -- CREAR, EDITAR, ELIMINAR
        entidad TEXT NOT NULL,  -- asociado, coopetrolito, usuario
        clave TEXT NOT NULL,    -- documento o usuario afectado
        detalle TEXT,
        fecha TEXT NOT NULL
      );
      CREATE TABLE IF NOT EXISTS administradores (
        usuario TEXT PRIMARY KEY,
        nombre TEXT NOT NULL,
        salt TEXT NOT NULL,
        hash TEXT NOT NULL
      );
      -- Solo se guarda el SHA-256 del token de sesión.
      CREATE TABLE IF NOT EXISTS sesiones (
        token TEXT PRIMARY KEY,
        usuario TEXT NOT NULL,
        expira_en TEXT NOT NULL
      );
    `),
  },
  {
    version: 2,
    descripcion: 'Asociados: fecha de actualización de datos y HMAC de la fecha de expedición',
    aplicar: (db) => {
      agregarColumna(db, 'asociados', 'fecha_actualizacion', 'TEXT'); // AAAA-MM-DD
      agregarColumna(db, 'asociados', 'expedicion_hmac', 'TEXT'); // nunca en texto plano
    },
  },
  {
    version: 3,
    descripcion: 'Asociados: convertir la fecha de expedición en texto plano a HMAC y eliminar la columna',
    compactar: true, // elimina del archivo los restos de las fechas en texto plano
    aplicar: (db) => {
      if (!columnas(db, 'asociados').includes('fecha_expedicion')) return false; // nada que convertir
      const actualizar = db.prepare('UPDATE asociados SET expedicion_hmac = ? WHERE documento = ?');
      for (const a of db.prepare('SELECT documento, fecha_expedicion FROM asociados WHERE fecha_expedicion IS NOT NULL').all()) {
        actualizar.run(hmacFecha(a.fecha_expedicion), a.documento);
      }
      db.exec('ALTER TABLE asociados DROP COLUMN fecha_expedicion');
    },
  },
  {
    version: 4,
    descripcion: 'Soportes: medio de pago (PSE o AGENCIA), agencia de pago y recibo de caja',
    aplicar: (db) => {
      agregarColumna(db, 'soportes', 'medio_pago', "TEXT NOT NULL DEFAULT 'PSE'");
      agregarColumna(db, 'soportes', 'agencia_pago', 'TEXT');
      agregarColumna(db, 'soportes', 'recibo', 'TEXT');
    },
  },
  {
    version: 5,
    descripcion: 'Inscripciones: agencia a la que pertenece el asociado',
    aplicar: (db) => agregarColumna(db, 'inscripciones', 'agencia_asociado', 'TEXT'),
  },
  {
    version: 6,
    descripcion: 'Usuarios del panel: rol (ADMINISTRADOR o REVISOR)',
    aplicar: (db) => agregarColumna(db, 'administradores', 'rol', "TEXT NOT NULL DEFAULT 'ADMINISTRADOR'"),
  },
  {
    version: 7,
    descripcion: 'Inscripciones: autorización opcional de uso de imagen (fotografías y videos del evento)',
    aplicar: (db) => agregarColumna(db, 'inscripciones', 'autorizacion_imagen', 'INTEGER NOT NULL DEFAULT 0'), // 1 = autoriza
  },
  {
    version: 8,
    descripcion: 'Asociados y Coopetrolitos: fecha de nacimiento',
    aplicar: (db) => { // AAAA-MM-DD; no es factor de identidad
      agregarColumna(db, 'asociados', 'fecha_nacimiento', 'TEXT');
      agregarColumna(db, 'coopetrolitos', 'fecha_nacimiento', 'TEXT');
    },
  },
  {
    version: 9,
    descripcion: 'Agencias que asisten a cada evento (eventos compartidos, p. ej. CARTAGENA Y MAMONAL)',
    aplicar: (db) => {
      db.exec(`
        CREATE TABLE IF NOT EXISTS agencias_evento (
          agencia TEXT PRIMARY KEY,          -- agencia o punto de atención (como en la base de asociados)
          evento TEXT NOT NULL REFERENCES tarifas(agencia)
        );
        INSERT OR IGNORE INTO agencias_evento (agencia, evento) SELECT agencia, agencia FROM tarifas;
      `);
      // La agencia del asociado ya no tiene que ser un evento (p. ej. MAMONAL asiste a "CARTAGENA Y MAMONAL"): se quita la
      // llave foránea a tarifas reconstruyendo la tabla (SQLite no permite quitarla con ALTER TABLE). La aplicación valida
      // la agencia contra agencias_evento.
      const nuevas = ['documento', 'nombre', 'agencia', 'estado', 'fecha_actualizacion', 'expedicion_hmac', 'fecha_nacimiento'];
      const comunes = columnas(db, 'asociados').filter((c) => nuevas.includes(c)).join(', ');
      db.exec(`
        CREATE TABLE asociados_nueva (
          documento TEXT PRIMARY KEY,
          nombre TEXT NOT NULL,
          agencia TEXT NOT NULL,             -- agencia o punto de atención del asociado
          estado TEXT NOT NULL DEFAULT 'ACTIVO',
          fecha_actualizacion TEXT,          -- AAAA-MM-DD
          expedicion_hmac TEXT,              -- HMAC de la fecha de expedición; nunca en texto plano
          fecha_nacimiento TEXT              -- AAAA-MM-DD
        );
        INSERT INTO asociados_nueva (${comunes}) SELECT ${comunes} FROM asociados;
        DROP TABLE asociados;
        ALTER TABLE asociados_nueva RENAME TO asociados;
      `);
    },
  },
];

const VERSION_ACTUAL = MIGRACIONES.at(-1).version;

// Aplica las migraciones pendientes y devuelve la lista de las aplicadas.
function migrar(db, { registrar = () => {} } = {}) {
  const inicial = db.prepare('PRAGMA user_version').get().user_version;
  if (inicial > VERSION_ACTUAL) {
    throw new Error(`La base de datos está en la versión ${inicial}, posterior a esta aplicación (${VERSION_ACTUAL}). Actualice la aplicación.`);
  }
  // Base con datos previos (incluidas las anteriores a user_version): se informan las migraciones aplicadas.
  const existente = inicial > 0 || db.prepare("SELECT COUNT(*) AS n FROM sqlite_master WHERE type = 'table'").get().n > 0;
  const aplicadas = [];
  for (const paso of MIGRACIONES.filter((m) => m.version > inicial)) {
    let cambio;
    db.exec('BEGIN IMMEDIATE');
    try {
      cambio = paso.aplicar(db);
      db.exec(`PRAGMA user_version = ${paso.version}`);
      db.exec('COMMIT');
    } catch (err) {
      db.exec('ROLLBACK');
      throw new Error(`Falló la migración ${paso.version} (${paso.descripcion}): ${err.message}`);
    }
    if (paso.compactar && cambio !== false) db.exec('VACUUM');
    aplicadas.push(paso.version);
    if (existente) registrar(`Migración ${paso.version}: ${paso.descripcion}`);
  }
  return aplicadas;
}

module.exports = { MIGRACIONES, VERSION_ACTUAL, migrar };
