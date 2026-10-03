// Descifra un respaldo (carpeta AAAAMMDD-HHMM con archivos .enc) en una carpeta de destino.
// Uso: node scripts/restaurar.js <carpeta_respaldo> <carpeta_destino>     (requiere RESPALDO_CLAVE)
// Después siga los pasos de restauración de DESPLIEGUE.md con los archivos descifrados.
const fs = require('node:fs');
const path = require('node:path');
const { claveRespaldo, descifrarArchivo } = require('../src/infraestructura/cifrado');

const [origen, destino] = process.argv.slice(2);
if (!origen || !destino) {
  console.error('Uso: node scripts/restaurar.js <carpeta_respaldo> <carpeta_destino>');
  process.exit(1);
}
try {
  const clave = claveRespaldo();
  if (!clave) throw new Error('Falta RESPALDO_CLAVE.');
  const archivos = fs.readdirSync(origen).filter((f) => f.endsWith('.enc'));
  if (!archivos.length) throw new Error(`No hay archivos .enc en ${origen}.`);
  fs.mkdirSync(destino, { recursive: true, mode: 0o700 });
  for (const f of archivos) {
    descifrarArchivo(path.join(origen, f), path.join(destino, f.replace(/\.enc$/, '')), clave);
    console.log(`Descifrado: ${f.replace(/\.enc$/, '')}`);
  }
  console.log(`Listo en ${destino}. Borre esta carpeta cuando termine la restauración (contiene datos personales).`);
} catch (err) {
  console.error(err.message);
  process.exit(1);
}
