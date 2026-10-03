// Limpia registros (p. ej. los de prueba antes de abrir inscripciones). Siempre hace un respaldo antes de borrar.
//
// Uso: node scripts/limpiar.js <qué> [--confirmar]
//   inscripciones  preinscripciones, personas inscritas y soportes de pago (incluidos los archivos)
//   bases          asociados, Coopetrolitos y el historial de cargas
//   auditoria      registro de cambios manuales del panel
//   cupos          cupos ajustados desde el panel (vuelven al valor del Excel)
//   todo           todo lo anterior. Se conservan usuarios del panel, tarifas y configuración.
// Sin --confirmar solo muestra cuántos registros se borrarían.
// En producción: sudo bash deploy/limpiar.sh <qué> [--confirmar]
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { abrirBaseDatos, DATA_DIR } = require('../src/infraestructura/db');

const GRUPOS = {
  inscripciones: ['soportes', 'inscripcion_personas', 'inscripciones'],
  bases: ['coopetrolitos', 'asociados', 'cargas_bases'],
  auditoria: ['auditoria'],
  cupos: ['cupos_ajustados'],
};
GRUPOS.todo = [...GRUPOS.inscripciones, ...GRUPOS.bases, ...GRUPOS.auditoria, ...GRUPOS.cupos];

const [que, bandera] = process.argv.slice(2);
if (!GRUPOS[que]) {
  console.error('Uso: node scripts/limpiar.js <inscripciones|bases|auditoria|cupos|todo> [--confirmar]');
  process.exit(1);
}
const confirmar = bandera === '--confirmar';
const tablas = GRUPOS[que];
const db = abrirBaseDatos(process.env.EVENTO_DB || undefined, { datosPrueba: false, importarAnteriores: false });
const dirSoportes = process.env.SOPORTES_DIR || path.join(DATA_DIR, 'soportes');
const conteos = tablas.map((t) => [t, db.prepare(`SELECT COUNT(*) AS n FROM ${t}`).get().n]);
const archivos = tablas.includes('soportes') && fs.existsSync(dirSoportes) ? fs.readdirSync(dirSoportes) : [];

console.log(`Se ${confirmar ? 'borrarán' : 'borrarían'}:`);
for (const [t, n] of conteos) console.log(`  ${t}: ${n}`);
if (tablas.includes('soportes')) console.log(`  archivos de soportes: ${archivos.length}`);
if (!confirmar) {
  console.log('Vista previa: no se borró nada. Agregue --confirmar para limpiar (se hará un respaldo antes).');
  process.exit(0);
}

console.log('Respaldo previo…');
db.close();
execFileSync(process.execPath, ['--disable-warning=ExperimentalWarning', path.join(__dirname, 'respaldo.js')], { stdio: 'inherit' });

const db2 = abrirBaseDatos(process.env.EVENTO_DB || undefined, { datosPrueba: false, importarAnteriores: false });
db2.exec('BEGIN IMMEDIATE');
try {
  for (const t of tablas) db2.exec(`DELETE FROM ${t}`);
  if (tablas.includes('inscripciones')) db2.exec("DELETE FROM sqlite_sequence WHERE name IN ('inscripciones', 'soportes')"); // referencias desde EVT..-000001
  db2.exec('COMMIT');
} catch (err) {
  db2.exec('ROLLBACK');
  throw err;
}
db2.exec('VACUUM'); // elimina del archivo los restos de los datos borrados
for (const f of archivos) fs.rmSync(path.join(dirSoportes, f), { force: true });
console.log(`Listo: se limpió "${que}".`);
