// Crea o actualiza un usuario del panel de administración.
// Uso: node scripts/crear_admin.js <usuario> "<Nombre completo>" [--rol revisor]   (la clave se pide por consola)
// Un usuario nuevo se crea como ADMINISTRADOR salvo que se indique --rol revisor; en uno existente se conserva el rol.
const readline = require('node:readline');
const { abrirBaseDatos } = require('../src/infraestructura/db');
const { crearAdministracion } = require('../src/aplicacion/admin');

const argumentos = process.argv.slice(2);
const iRol = argumentos.indexOf('--rol');
const rol = iRol >= 0 ? String(argumentos.splice(iRol, 2)[1] || '').toUpperCase() : undefined;
const [usuario, nombre] = argumentos;
if (!usuario) {
  console.error('Uso: node scripts/crear_admin.js <usuario> "<Nombre completo>"');
  process.exit(1);
}

function preguntarClave(texto) {
  return new Promise((resolve) => {
    const rl = readline.createInterface({ input: process.stdin, output: process.stdout, terminal: true });
    rl._writeToOutput = (s) => rl.output.write(s.startsWith(texto) ? s : '*');
    rl.question(texto, (clave) => { rl.close(); process.stdout.write('\n'); resolve(clave); });
  });
}

(async () => {
  const clave = process.env.ADMIN_CLAVE || await preguntarClave('Clave (mínimo 10 caracteres): ');
  if (!process.env.ADMIN_CLAVE && clave !== await preguntarClave('Repita la clave: ')) {
    console.error('Las claves no coinciden.');
    process.exit(1);
  }
  try {
    crearAdministracion(abrirBaseDatos(process.env.EVENTO_DB || undefined)).crearUsuario(usuario, nombre, clave, { rol });
    console.log(`Usuario "${usuario}" listo${rol ? ` (rol ${rol})` : ''}. Ingrese en /admin.html`);
  } catch (err) {
    console.error(err.message);
    process.exit(1);
  }
})();
