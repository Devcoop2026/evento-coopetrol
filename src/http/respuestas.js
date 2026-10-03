// Utilidades HTTP: errores con código, lectura de JSON, respuestas y cabeceras de seguridad.
const { ErrorValidacion } = require('../dominio/errores');

const TIPOS = {
  '.html': 'text/html; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.webmanifest': 'application/manifest+json',
  '.png': 'image/png',
  '.svg': 'image/svg+xml',
  '.ico': 'image/x-icon',
};

// Política de contenido: solo recursos propios, más las fuentes de Google. No hay scripts ni estilos en línea.
const CSP = [
  "default-src 'self'", "script-src 'self'", "style-src 'self' https://fonts.googleapis.com", "font-src https://fonts.gstatic.com",
  "img-src 'self' data: blob:", "connect-src 'self'", "frame-src 'self'", "worker-src 'self'", "manifest-src 'self'",
  "object-src 'none'", "base-uri 'self'", "form-action 'self'", "frame-ancestors 'self'",
].join('; ');

const CABECERAS_SEGURIDAD = {
  'Content-Security-Policy': CSP,
  'X-Content-Type-Options': 'nosniff',
  'Referrer-Policy': 'same-origin',
  'X-Frame-Options': 'SAMEORIGIN',
  'Permissions-Policy': 'camera=(), microphone=(), geolocation=(), payment=()',
  'Cross-Origin-Opener-Policy': 'same-origin',
};

class ErrorAutorizacion extends Error {}

// Error con código HTTP propio (400, 403, 404, 415, 429).
class ErrorHttp extends Error {
  constructor(estado, mensaje, cabeceras = {}) {
    super(mensaje);
    Object.assign(this, { estado, cabeceras });
  }
}

const LIMITE_JSON = 1e5;

function responderJson(res, estado, cuerpo, cabeceras = {}) {
  res.writeHead(estado, { 'Content-Type': TIPOS['.json'], 'Cache-Control': 'no-store', ...cabeceras });
  res.end(JSON.stringify(cuerpo));
}

// Solo se aceptan cuerpos JSON: defensa adicional contra envíos de formularios desde otros sitios.
function leerJson(req, limite = LIMITE_JSON) {
  if (!/^application\/json\b/i.test(req.headers['content-type'] || '')) {
    req.resume();
    return Promise.reject(new ErrorHttp(415, 'El contenido debe enviarse como JSON.'));
  }
  return new Promise((resolve, reject) => {
    const partes = [];
    let tamano = 0;
    req.on('data', (parte) => {
      tamano += parte.length;
      if (tamano <= limite) return partes.push(parte);
      // Se descarta el resto del cuerpo sin cortar la conexión, para que el cliente reciba el mensaje.
      req.removeAllListeners('data');
      req.resume();
      reject(new ErrorValidacion('El archivo o la solicitud supera el tamaño permitido.'));
    });
    req.on('end', () => {
      try { resolve(partes.length ? JSON.parse(Buffer.concat(partes).toString('utf8')) : {}); }
      catch { reject(new ErrorValidacion('JSON inválido.')); }
    });
    req.on('error', reject);
  });
}

const leerCookie = (req, nombre) => (req.headers.cookie || '').split(';').map((c) => c.trim().split('='))
  .find(([k]) => k === nombre)?.[1];

// Traduce cualquier error a la respuesta HTTP correspondiente.
function responderError(res, err) {
  if (res.headersSent) return res.end();
  if (err instanceof ErrorHttp) return responderJson(res, err.estado, { error: err.message }, err.cabeceras);
  if (err instanceof URIError || (err instanceof TypeError && /URL/.test(err.message))) {
    return responderJson(res, 400, { error: 'Dirección no válida.' }); // URL mal codificada
  }
  if (err instanceof ErrorValidacion) return responderJson(res, 422, { error: err.message, ...err.datos });
  if (err instanceof ErrorAutorizacion) return responderJson(res, 401, { error: 'Sesión no válida. Ingrese de nuevo.' });
  console.error(err);
  return responderJson(res, 500, { error: 'Error interno del servidor.' });
}

module.exports = {
  TIPOS, CSP, CABECERAS_SEGURIDAD, ErrorAutorizacion, ErrorHttp, LIMITE_JSON, responderJson, leerJson, leerCookie, responderError,
};
