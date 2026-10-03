// Punto de entrada: arma el servidor (src/http/servidor.js) con la configuración del entorno y escucha en HOST:PORT.
// Lo usan npm start, npm run dev y el servicio systemd. Las pruebas importan crearServidor directamente.
const path = require('node:path');
const { abrirBaseDatos, vigilarCambios, leerConfig, DATA_DIR } = require('./src/infraestructura/db');
const { crearFiltroRedes } = require('./src/infraestructura/redes');
const { validarClave } = require('./src/infraestructura/secreto');
const { crearServidor } = require('./src/http/servidor');

function iniciar() {
  validarClave(); // en producción exige EVENTO_SECRETO antes de arrancar
  const PORT = Number(process.env.PORT || 3000);
  // En producción: HOST=127.0.0.1 (solo el proxy llega al servidor) y TRUST_PROXY=1.
  const HOST = process.env.HOST || '0.0.0.0';
  const TRUST_PROXY = process.env.TRUST_PROXY === '1';
  const db = abrirBaseDatos(process.env.EVENTO_DB || undefined);
  vigilarCambios(db);
  const { config, camposSoporte, habeasData } = leerConfig();
  const panelRedes = crearFiltroRedes(process.env.PANEL_REDES);
  if (panelRedes) console.log(`Panel restringido a las redes: ${process.env.PANEL_REDES}`);
  // Subdirectorio público (p. ej. /portal-eventos) cuando el proxy publica la aplicación bajo una ruta y la quita al reenviar.
  const RUTA_BASE = String(process.env.RUTA_BASE || '').trim().replace(/\/+$/, '');
  if (RUTA_BASE && !/^(\/[A-Za-z0-9._-]+)+$/.test(RUTA_BASE)) throw new Error(`RUTA_BASE inválida: ${RUTA_BASE} (ej.: /portal-eventos)`);
  const proxyConfiable = crearFiltroRedes(process.env.PROXY_CONFIABLE, 'PROXY_CONFIABLE');
  if (proxyConfiable) console.log(`Cabecera X-Real-IP aceptada solo desde: ${process.env.PROXY_CONFIABLE}`);
  if (config.fecha_supresion_datos && new Date().toISOString().slice(0, 10) >= config.fecha_supresion_datos) {
    console.warn(`Aviso: se cumplió la fecha de supresión de datos personales (${config.fecha_supresion_datos}). Ejecute deploy/limpiar.sh todo.`);
  }
  crearServidor({
    db, config, camposSoporte, habeasData, trustProxy: TRUST_PROXY, panelRedes, proxyConfiable, rutaBase: RUTA_BASE,
    cabeceraIp: String(process.env.CABECERA_IP || 'x-real-ip').toLowerCase(), ipSaltos: Math.max(1, Number(process.env.IP_SALTOS) || 1),
    dirSoportes: process.env.SOPORTES_DIR || path.join(DATA_DIR, 'soportes'),
  }).listen(PORT, HOST, () => {
    console.log(`Simulador Coopetrol en http://${HOST === '0.0.0.0' ? 'localhost' : HOST}:${PORT}${TRUST_PROXY ? ' (detrás de proxy)' : ''}`);
  });
}

module.exports = { crearServidor, iniciar };

if (require.main === module) iniciar();
