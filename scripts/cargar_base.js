// Carga la base de asociados o de Coopetrolitos desde Excel (.xlsx) o CSV directamente a la base de datos.
// Alternativa al panel de administración para cargas masivas o automatizadas en el servidor.
//
// Uso: node scripts/cargar_base.js <asociados|coopetrolitos> <archivo> [--confirmar]
//   Sin --confirmar solo muestra la vista previa (no guarda nada).
// En producción: sudo bash deploy/cargar_base.sh <asociados|coopetrolitos> <archivo> [--confirmar]
const fs = require('node:fs');
const os = require('node:os');
const { abrirBaseDatos } = require('../src/infraestructura/db');
const { crearBases } = require('../src/aplicacion/bases');

const [tipo, archivo, bandera] = process.argv.slice(2);
if (!['asociados', 'coopetrolitos'].includes(tipo) || !archivo) {
  console.error('Uso: node scripts/cargar_base.js <asociados|coopetrolitos> <archivo.xlsx|.csv> [--confirmar]');
  process.exit(1);
}
if (!fs.existsSync(archivo)) {
  console.error(`No se encontró el archivo: ${archivo}`);
  process.exit(1);
}

try {
  const confirmar = bandera === '--confirmar';
  const bases = crearBases(abrirBaseDatos(process.env.EVENTO_DB || undefined, { datosPrueba: false }));
  const r = bases.cargar(tipo, fs.readFileSync(archivo), {
    confirmar, archivo: require('node:path').basename(archivo), usuario: `consola:${os.userInfo().username}`,
  });
  console.log(`${r.registros} registros válidos${r.activos != null ? ` (${r.activos} activos)` : ''}, ${r.omitidas} filas omitidas.`);
  for (const a of r.advertencias) console.log(`Advertencia: ${a}`);
  for (const e of r.errores) console.log(`  - ${e}`);
  console.log(confirmar ? `Base de ${tipo} reemplazada.` : 'Vista previa: no se guardó nada. Agregue --confirmar para reemplazar la base.');
} catch (err) {
  console.error(err.message);
  process.exit(1);
}
