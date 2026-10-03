// La fecha de expedición es un factor de identidad: se guarda como HMAC-SHA256 con una clave del servidor
// (EVENTO_SECRETO), nunca en texto plano. Si la base de datos se filtra, las fechas no quedan expuestas.
// Cambiar EVENTO_SECRETO invalida los HMAC guardados: habría que volver a cargar la base de asociados.
const crypto = require('node:crypto');

const DESARROLLO = 'solo-desarrollo-no-usar-en-produccion';
let avisado = false;

function clave() {
  const secreto = process.env.EVENTO_SECRETO;
  if (secreto) {
    if (secreto.length < 32) throw new Error('EVENTO_SECRETO debe tener al menos 32 caracteres.');
    return secreto;
  }
  if (process.env.NODE_ENV === 'production') throw new Error('Falta EVENTO_SECRETO en la configuración de producción.');
  if (!avisado) {
    console.warn('Aviso: EVENTO_SECRETO no está definido; se usa una clave de desarrollo.');
    avisado = true;
  }
  return DESARROLLO;
}

const hmacFecha = (fecha) => crypto.createHmac('sha256', clave()).update(`expedicion:${fecha}`).digest('hex');

function coincideFecha(fecha, hmacGuardado) {
  if (!hmacGuardado) return false;
  const a = Buffer.from(hmacFecha(fecha), 'hex');
  const b = Buffer.from(hmacGuardado, 'hex');
  return a.length === b.length && crypto.timingSafeEqual(a, b);
}

module.exports = { hmacFecha, coincideFecha, validarClave: clave };
