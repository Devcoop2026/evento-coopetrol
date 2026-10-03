// Cifrado de archivos de respaldo con AES-256-GCM (confidencialidad + integridad).
// Formato: "EVTENC1\n" (8 bytes) | IV (12 bytes) | etiqueta de autenticación (16 bytes) | datos cifrados.
const crypto = require('node:crypto');
const fs = require('node:fs');

const MAGIA = Buffer.from('EVTENC1\n');

function claveRespaldo(hex = process.env.RESPALDO_CLAVE) {
  if (!hex) return null;
  if (!/^[0-9a-f]{64}$/i.test(hex)) throw new Error('RESPALDO_CLAVE debe tener 64 caracteres hexadecimales (32 bytes).');
  return Buffer.from(hex, 'hex');
}

function cifrarArchivo(origen, destino, clave) {
  const iv = crypto.randomBytes(12);
  const cifrador = crypto.createCipheriv('aes-256-gcm', clave, iv);
  const datos = Buffer.concat([cifrador.update(fs.readFileSync(origen)), cifrador.final()]);
  fs.writeFileSync(destino, Buffer.concat([MAGIA, iv, cifrador.getAuthTag(), datos]), { mode: 0o600 });
}

function descifrarArchivo(origen, destino, clave) {
  const contenido = fs.readFileSync(origen);
  if (!contenido.subarray(0, MAGIA.length).equals(MAGIA)) throw new Error(`${origen} no es un respaldo cifrado válido.`);
  const iv = contenido.subarray(8, 20);
  const etiqueta = contenido.subarray(20, 36);
  const descifrador = crypto.createDecipheriv('aes-256-gcm', clave, iv);
  descifrador.setAuthTag(etiqueta);
  try {
    fs.writeFileSync(destino, Buffer.concat([descifrador.update(contenido.subarray(36)), descifrador.final()]), { mode: 0o600 });
  } catch {
    throw new Error(`No se pudo descifrar ${origen}: la clave no corresponde o el archivo está alterado.`);
  }
}

module.exports = { claveRespaldo, cifrarArchivo, descifrarArchivo };
