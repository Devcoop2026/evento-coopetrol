// Respaldo de la base de inscripciones, los soportes de pago y los datos cargados.
// Genera una copia consistente de SQLite en caliente (VACUUM INTO) y un .tar.gz con los soportes y los JSON de data/.
// Elimina respaldos con más de RETENCION_DIAS días.
//
// Variables: EVENTO_DB, SOPORTES_DIR, RESPALDO_DIR (por defecto ./respaldos), RETENCION_DIAS (por defecto 30) y
// RESPALDO_CLAVE (64 hex): si está definida, los archivos se cifran con AES-256-GCM (.enc) y se borran los originales.
// Para restaurar: node scripts/restaurar.js <carpeta_respaldo> <destino>.
// Uso: node scripts/respaldo.js
const { DatabaseSync } = require('node:sqlite');
const { crearTarGz } = require('../src/infraestructura/empaquetar');
const { claveRespaldo, cifrarArchivo } = require('../src/infraestructura/cifrado');
const fs = require('node:fs');
const path = require('node:path');

const RAIZ = path.join(__dirname, '..');
const DATA_DIR = path.join(RAIZ, 'data');
const origenDb = process.env.EVENTO_DB || path.join(DATA_DIR, 'evento.db');
const soportes = process.env.SOPORTES_DIR || path.join(DATA_DIR, 'soportes');
const destinoRaiz = process.env.RESPALDO_DIR || path.join(RAIZ, 'respaldos');
const retencionDias = Number(process.env.RETENCION_DIAS || 30);

const marca = new Date().toISOString().replace(/[-:]/g, '').replace('T', '-').slice(0, 13); // AAAAMMDD-HHMM
const destino = path.join(destinoRaiz, marca);
fs.mkdirSync(destino, { recursive: true, mode: 0o700 });

// 1. Base de datos (copia consistente aunque el servidor esté escribiendo).
const copiaDb = path.join(destino, 'evento.db');
const db = new DatabaseSync(origenDb, { readOnly: true });
db.exec(`VACUUM INTO '${copiaDb.replace(/'/g, "''")}'`);
db.close();

(async () => {
  // 2. Soportes de pago y JSON de data/ (formulario, habeas data, tarifas).
  // Empaquetado propio (src/infraestructura/empaquetar.js): no depende de tar.exe dentro del contenedor.
  if (fs.existsSync(soportes)) await crearTarGz(path.join(destino, 'soportes.tar.gz'), path.dirname(soportes), [path.basename(soportes)]);
  const jsons = fs.readdirSync(DATA_DIR).filter((f) => f.endsWith('.json'));
  if (jsons.length) await crearTarGz(path.join(destino, 'datos.tar.gz'), DATA_DIR, jsons);
  // Configuración propia del servidor fuera de data/ (EVENTO_CONFIG; p. ej. C:\datos\config.json en el contenedor Windows).
  if (process.env.EVENTO_CONFIG && fs.existsSync(process.env.EVENTO_CONFIG)) {
    fs.copyFileSync(process.env.EVENTO_CONFIG, path.join(destino, 'config-servidor.json'));
  }
  for (const f of fs.readdirSync(destino)) fs.chmodSync(path.join(destino, f), 0o600);

  // Cifrado (datos personales): en producción RESPALDO_CLAVE la genera instalar.sh.
  const clave = claveRespaldo();
  if (clave) {
    for (const f of fs.readdirSync(destino)) {
      const ruta = path.join(destino, f);
      cifrarArchivo(ruta, `${ruta}.enc`, clave);
      fs.rmSync(ruta);
    }
  } else {
    console.warn('Aviso: RESPALDO_CLAVE no está definida; el respaldo NO está cifrado.');
  }

  // 3. Retención.
  const limite = Date.now() - retencionDias * 86400000;
  for (const carpeta of fs.readdirSync(destinoRaiz)) {
    const ruta = path.join(destinoRaiz, carpeta);
    if (/^\d{8}-\d{4}$/.test(carpeta) && fs.statSync(ruta).mtimeMs < limite) fs.rmSync(ruta, { recursive: true, force: true });
  }

  const tamano = fs.readdirSync(destino).reduce((t, f) => t + fs.statSync(path.join(destino, f)).size, 0);
  console.log(`Respaldo creado en ${destino} (${(tamano / 1024 / 1024).toFixed(2)} MB)`);
})().catch((err) => {
  console.error(`No se pudo crear el respaldo: ${err.message}`);
  process.exit(1);
});
