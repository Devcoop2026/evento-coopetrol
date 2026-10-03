// Servidor HTTP sin dependencias. Compone los casos de uso con sus dependencias y atiende:
//   /api/admin/...  panel (controladorPanel.js): sesión, roles y restricción por red
//   /api/...        parte pública (controladorPublico.js): límites por IP y fallos de identidad
//   resto           archivos de /public
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const { crearSimulador, MAX_ACOMPANANTES } = require('../aplicacion/simulador');
const { crearInscripciones } = require('../aplicacion/inscripciones');
const { crearAdministracion } = require('../aplicacion/admin');
const { crearBases } = require('../aplicacion/bases');
const { crearGestion } = require('../aplicacion/gestion');
const { crearRepositorioPadron } = require('../infraestructura/repositorioPadron');
const { crearLimitador } = require('../infraestructura/limitador');
const { crearRegistro, enmascarar } = require('../infraestructura/registro');
const { crearEnrutador } = require('./enrutador');
const { rutasPublicas } = require('./controladorPublico');
const { rutasPanel } = require('./controladorPanel');
const {
  TIPOS, CABECERAS_SEGURIDAD, ErrorAutorizacion, ErrorHttp, LIMITE_JSON, responderJson, leerJson, leerCookie, responderError,
} = require('./respuestas');

const PUBLIC_DIR = path.join(__dirname, '..', '..', 'public');
const PREFIJO_PANEL = '/api/admin';
const NO_ENCONTRADO = () => new ErrorHttp(404, 'Recurso no encontrado.');

function crearServidor({
  db, config = {}, camposSoporte = [], habeasData = {}, dirSoportes, trustProxy = false,
  limites = { loginPorIp: 10, identidadPorIp: 20, apiPorIp: 600 },
  panelRedes = null, // función ip -> boolean (PANEL_REDES); null = sin restricción por red
  rutaBase = '', // RUTA_BASE: subdirectorio público si el proxy publica la aplicación bajo una ruta (p. ej. /portal-eventos)
  cabeceraIp = 'x-real-ip', // CABECERA_IP: cabecera con la IP del cliente que fija el proxy
  ipSaltos = 1, // IP_SALTOS: con x-forwarded-for, posición de la IP del cliente contada desde la derecha
  proxyConfiable = null, // función ip -> boolean (PROXY_CONFIABLE): de dónde se acepta X-Real-IP; null = de cualquiera
  registrar = crearRegistro(),
  ahora = () => new Date(),
}) {
  const padron = crearRepositorioPadron(db);
  const simulador = crearSimulador(db, { padron });
  const inscripciones = crearInscripciones(db, simulador, { config, camposSoporte, habeasData, dirSoportes, padron });
  const administracion = crearAdministracion(db);
  const bases = crearBases(db);
  const gestion = crearGestion(db, administracion);

  // Límites por IP: ingresos al panel, fallos de identificación (documento + fecha) y volumen general de la API.
  // Son amplios para no afectar a varias personas que salen a internet por la misma IP (p. ej. una agencia).
  const limiteLogin = crearLimitador({ maximo: limites.loginPorIp, ventanaMs: 15 * 60000 });
  const limiteIdentidad = crearLimitador({ maximo: limites.identidadPorIp, ventanaMs: 15 * 60000 });
  const limiteApi = crearLimitador({ maximo: limites.apiPorIp, ventanaMs: 5 * 60000 });
  const limpieza = setInterval(() => [limiteLogin, limiteIdentidad, limiteApi].forEach((l) => l.purgar()), 5 * 60000);
  limpieza.unref();

  // IP real del cliente. Detrás de Nginx se usa X-Real-IP, que el proxy fija con la IP de la conexión
  // (a diferencia de X-Forwarded-For, el cliente no puede falsificarlo si el servidor solo escucha en 127.0.0.1).
  // Con PROXY_CONFIABLE solo se cree la cabecera si la conexión viene del proxy (p. ej. la red NAT de Docker en Windows,
  // donde el puerto del contenedor no se puede limitar a 127.0.0.1): un cliente directo no puede falsificar su IP.
  const desdeProxy = (req) => trustProxy && (!proxyConfiable || proxyConfiable(req.socket.remoteAddress));
  // IP del cliente según el proxy: X-Real-IP (Nginx, IIS) o X-Forwarded-For (Render y otros PaaS). En X-Forwarded-For cada
  // proxy agrega una IP al final: se toma la que está `ipSaltos` posiciones desde la derecha (las de la izquierda las puede
  // escribir el cliente).
  function ipDeCabecera(req) {
    const valor = String(req.headers[cabeceraIp] || '').trim();
    if (cabeceraIp !== 'x-forwarded-for') return valor;
    const ips = valor.split(',').map((ip) => ip.trim()).filter(Boolean);
    return ips[Math.max(0, ips.length - ipSaltos)] || '';
  }
  const ipCliente = (req) => (desdeProxy(req) ? ipDeCabecera(req) : '') || req.socket.remoteAddress;

  function exigirLimite(limitador, clave, mensaje = 'Demasiadas solicitudes. Espere unos minutos e intente de nuevo.') {
    const espera = limitador.bloqueada(clave);
    if (espera) throw new ErrorHttp(429, mensaje, { 'Retry-After': String(espera) });
  }

  function cookieSesion(req, valor, maxAge) {
    const segura = (desdeProxy(req) && req.headers['x-forwarded-proto'] === 'https') || req.socket.encrypted ? '; Secure' : '';
    return `sid=${valor}; HttpOnly; SameSite=Strict; Path=${rutaBase}/api/admin; Max-Age=${maxAge}${segura}`;
  }

  const panel = crearEnrutador(rutasPanel({ inscripciones, administracion, bases, gestion, config, registrar, ahora, cookieSesion }));
  const publico = crearEnrutador(rutasPublicas({
    simulador, inscripciones, padron, config, camposSoporte, habeasData, maxAcompanantes: MAX_ACOMPANANTES,
  }));

  // Ejecuta el manejador y responde JSON con lo que devuelva (salvo que haya escrito la respuesta él mismo).
  // El cuerpo leído queda en contexto.datos (para registrar el documento enmascarado si falla la identidad).
  async function ejecutar(encontrada, contexto) {
    const { ruta } = encontrada;
    const resultado = await ruta.manejador({
      ...contexto,
      get params() { return encontrada.params; },
      cuerpo: async () => (contexto.datos = await leerJson(contexto.req, ruta.limiteCuerpo || LIMITE_JSON)),
    });
    if (!contexto.res.writableEnded) responderJson(contexto.res, ruta.estado || 200, resultado);
  }

  async function atenderPanel(req, res, url, ip) {
    const encontrada = panel(req.method, url.pathname.slice(PREFIJO_PANEL.length));
    const sid = leerCookie(req, 'sid');
    const contexto = { req, res, url, ip, sid };
    if (encontrada?.ruta.limiteLogin) {
      if (limiteLogin.bloqueada(ip)) registrar('login_bloqueado', { ip });
      exigirLimite(limiteLogin, ip, 'Demasiados intentos. Espere unos minutos.');
      limiteLogin.registrar(ip);
    }
    if (!encontrada?.ruta.publica) {
      // La sesión se valida antes de responder 404 o de decodificar la URL: sin sesión no se revela nada.
      const sesion = administracion.validarSesion(sid);
      if (!sesion) throw new ErrorAutorizacion();
      // Acciones reservadas al rol ADMINISTRADOR (el REVISOR consulta, aprueba o rechaza pagos y exporta).
      contexto.sesion = sesion;
      contexto.exigirAdmin = (accion) => {
        if (sesion.rol === 'ADMINISTRADOR') return;
        registrar('permiso_denegado', { usuario: sesion.usuario, rol: sesion.rol, accion, ip });
        throw new ErrorHttp(403, 'Su rol no tiene permiso para esta acción.');
      };
    }
    if (!encontrada) throw NO_ENCONTRADO();
    if (encontrada.ruta.soloAdmin) contexto.exigirAdmin(encontrada.ruta.soloAdmin);
    return ejecutar(encontrada, contexto);
  }

  async function atenderPublico(req, res, url, ip) {
    exigirLimite(limiteApi, ip);
    limiteApi.registrar(ip);
    const encontrada = publico(req.method, url.pathname);
    if (!encontrada) throw NO_ENCONTRADO();
    if (!encontrada.ruta.identidad) return ejecutar(encontrada, { req, res, url, ip });

    if (limiteIdentidad.bloqueada(ip)) registrar('ip_bloqueada', { ip, ruta: url.pathname });
    exigirLimite(limiteIdentidad, ip, 'Demasiados intentos fallidos desde su conexión. Espere unos minutos e intente de nuevo.');
    const contexto = { req, res, url, ip };
    try {
      return await ejecutar(encontrada, contexto);
    } catch (err) {
      if (err.identidad) {
        limiteIdentidad.registrar(ip);
        registrar('identidad_fallida', { documento: enmascarar(contexto.datos?.documento), ip, ruta: url.pathname });
      }
      throw err;
    }
  }

  function estatico(req, res, url) {
    const relativo = url.pathname === '/' ? 'index.html' : decodeURIComponent(url.pathname).replace(/^\/+/, '');
    const archivo = path.normalize(path.join(PUBLIC_DIR, relativo));
    if (!archivo.startsWith(PUBLIC_DIR + path.sep)) return responderJson(res, 403, { error: 'Prohibido.' });
    fs.readFile(archivo, (err, contenido) => {
      if (err) return responderJson(res, 404, { error: 'No encontrado.' });
      res.writeHead(200, { 'Content-Type': TIPOS[path.extname(archivo)] || 'application/octet-stream' });
      res.end(contenido);
    });
  }

  const esPanel = (pathname) => pathname.startsWith(`${PREFIJO_PANEL}/`) || pathname === '/admin.html' || pathname === '/admin.js';

  const servidor = http.createServer(async (req, res) => {
    for (const [nombre, valor] of Object.entries(CABECERAS_SEGURIDAD)) res.setHeader(nombre, valor);
    try {
      const url = new URL(req.url, 'http://localhost');
      const ip = ipCliente(req);
      if (esPanel(url.pathname) && panelRedes && !panelRedes(ip)) {
        registrar('acceso_panel_denegado', { ip, ruta: url.pathname });
        throw new ErrorHttp(403, 'El panel solo está disponible desde la red de Coopetrol.');
      }
      if (url.pathname.startsWith(`${PREFIJO_PANEL}/`)) return await atenderPanel(req, res, url, ip);
      if (url.pathname.startsWith('/api/')) return await atenderPublico(req, res, url, ip);
      estatico(req, res, url);
    } catch (err) {
      responderError(res, err);
    }
  });
  servidor.on('close', () => clearInterval(limpieza));
  return servidor;
}

module.exports = { crearServidor };
