// Almacenamiento de los comprobantes de pago en disco (SOPORTES_DIR). Nombres aleatorios: nunca el nombre original.
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');

// Tipos aceptados, reconocidos por su firma (no por la extensión ni el tipo que declara el navegador).
const TIPOS_ARCHIVO = [
  { tipo: 'application/pdf', ext: 'pdf', firma: [0x25, 0x50, 0x44, 0x46] },
  { tipo: 'image/png', ext: 'png', firma: [0x89, 0x50, 0x4e, 0x47] },
  { tipo: 'image/jpeg', ext: 'jpg', firma: [0xff, 0xd8, 0xff] },
];

const tipoPorFirma = (bytes) => TIPOS_ARCHIVO.find((t) => t.firma.every((b, i) => bytes[i] === b)) || null;

function crearAlmacenSoportes(dir) {
  return {
    // Guarda el archivo y devuelve el nombre interno.
    guardar(bytes, ext) {
      fs.mkdirSync(dir, { recursive: true });
      const nombre = `${crypto.randomUUID()}.${ext}`;
      fs.writeFileSync(path.join(dir, nombre), bytes);
      return nombre;
    },
    borrar: (nombre) => fs.rmSync(path.join(dir, nombre), { force: true }),
    ruta: (nombre) => path.join(dir, path.basename(nombre)),
  };
}

module.exports = { crearAlmacenSoportes, tipoPorFirma, TIPOS_ARCHIVO };
