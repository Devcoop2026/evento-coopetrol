// Empaquetado .tar.gz sin herramientas externas (formato ustar), para los respaldos.
// No depende de tar.exe: funciona igual en Linux, Windows Server Core y Nano Server. Los archivos se leen y
// comprimen en flujo (no se cargan completos en memoria). Se extrae con cualquier tar: tar -xzf archivo.tar.gz
const fs = require('node:fs');
const path = require('node:path');
const zlib = require('node:zlib');
const { pipeline } = require('node:stream/promises');
const { Readable } = require('node:stream');

const BLOQUE = 512;

function octal(valor, largo) {
  return `${valor.toString(8).padStart(largo - 1, '0')}\0`;
}

// Cabecera ustar de 512 bytes. Los nombres largos se parten en prefijo (155) + nombre (100).
function cabecera(nombre, { tamano = 0, mtime = 0, directorio = false } = {}) {
  let prefijo = '';
  let corto = nombre;
  if (Buffer.byteLength(nombre) > 100) {
    const corte = nombre.lastIndexOf('/', 155);
    prefijo = nombre.slice(0, corte);
    corto = nombre.slice(corte + 1);
    if (corte < 0 || Buffer.byteLength(prefijo) > 155 || Buffer.byteLength(corto) > 100) {
      throw new Error(`Ruta demasiado larga para el respaldo: ${nombre}`);
    }
  }
  const h = Buffer.alloc(BLOQUE, 0);
  h.write(corto, 0, 100, 'utf8');
  h.write(octal(directorio ? 0o700 : 0o600, 8), 100, 'ascii');
  h.write(octal(0, 8), 108, 'ascii'); // uid
  h.write(octal(0, 8), 116, 'ascii'); // gid
  h.write(octal(tamano, 12), 124, 'ascii');
  h.write(octal(Math.floor(mtime / 1000), 12), 136, 'ascii');
  h.fill(' ', 148, 156); // la suma se calcula con este campo en espacios
  h.write(directorio ? '5' : '0', 156, 'ascii');
  h.write('ustar\0', 257, 'ascii');
  h.write('00', 263, 'ascii');
  h.write(prefijo, 345, 155, 'utf8');
  let suma = 0;
  for (const byte of h) suma += byte;
  h.write(`${suma.toString(8).padStart(6, '0')}\0 `, 148, 'ascii');
  return h;
}

// Recorre `elementos` (rutas relativas a `base`, archivos o carpetas) y emite el contenido del .tar.
async function* contenidoTar(base, elementos) {
  async function* entrada(relativa) {
    const absoluta = path.join(base, relativa);
    const info = fs.statSync(absoluta);
    const nombre = relativa.split(path.sep).join('/');
    if (info.isDirectory()) {
      yield cabecera(`${nombre}/`, { mtime: info.mtimeMs, directorio: true });
      for (const hijo of fs.readdirSync(absoluta).sort()) yield* entrada(path.join(relativa, hijo));
      return;
    }
    if (!info.isFile()) return;
    yield cabecera(nombre, { tamano: info.size, mtime: info.mtimeMs });
    let escritos = 0;
    for await (const parte of fs.createReadStream(absoluta)) {
      escritos += parte.length;
      yield parte;
    }
    if (escritos !== info.size) throw new Error(`El archivo cambió durante el respaldo: ${nombre}`);
    const relleno = (BLOQUE - (info.size % BLOQUE)) % BLOQUE;
    if (relleno) yield Buffer.alloc(relleno, 0);
  }
  for (const elemento of elementos) yield* entrada(elemento);
  yield Buffer.alloc(BLOQUE * 2, 0); // fin del archivo
}

// Crea `destino` (.tar.gz) con `elementos` relativos a `base`.
async function crearTarGz(destino, base, elementos) {
  await pipeline(Readable.from(contenidoTar(base, elementos)), zlib.createGzip({ level: 6 }), fs.createWriteStream(destino, { mode: 0o600 }));
}

module.exports = { crearTarGz };
