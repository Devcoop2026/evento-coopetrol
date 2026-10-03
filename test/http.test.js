// Pruebas de la capa HTTP: cabeceras de seguridad, códigos de estado, límites por IP, sesión y aislamiento de soportes.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { abrirBaseDatos } = require('../src/infraestructura/db');
const { crearAdministracion } = require('../src/aplicacion/admin');
const { crearServidor } = require('../server');

let servidor;
let base;
let db;

function abrir(limites, extra = {}) {
  db = abrirBaseDatos(':memory:', { datosPrueba: true });
  crearAdministracion(db).crearUsuario('revisor', 'Revisor Prueba', 'clave-segura-123');
  const s = crearServidor({
    db,
    config: { validar_periodo_inscripcion: false, ...extra.config },
    camposSoporte: [],
    habeasData: { version: 'v' },
    dirSoportes: fs.mkdtempSync(path.join(os.tmpdir(), 'soportes-http-')),
    limites,
    registrar: () => {},
    ...extra,
  });
  return new Promise((resolve) => s.listen(0, '127.0.0.1', () => resolve(s)));
}

before(async () => {
  servidor = await abrir({ loginPorIp: 3, identidadPorIp: 4, apiPorIp: 1000 });
  base = `http://127.0.0.1:${servidor.address().port}`;
});
after(() => servidor.close());

const postJson = (ruta, cuerpo, cabeceras = {}) => fetch(base + ruta, {
  method: 'POST', headers: { 'Content-Type': 'application/json', ...cabeceras }, body: JSON.stringify(cuerpo),
});

// Solicitud con la ruta tal cual (fetch normaliza las URL mal codificadas).
const crudo = (ruta) => new Promise((resolve, reject) => {
  http.get({ host: '127.0.0.1', port: servidor.address().port, path: ruta }, (res) => { res.resume(); resolve(res.statusCode); })
    .on('error', reject);
});

test('cabeceras de seguridad y CSP estricta en las páginas', async () => {
  const res = await fetch(`${base}/`);
  const csp = res.headers.get('content-security-policy');
  assert.match(csp, /default-src 'self'/);
  assert.match(csp, /script-src 'self'(;|$)/);
  assert.match(csp, /object-src 'none'/);
  assert.equal(res.headers.get('x-content-type-options'), 'nosniff');
  assert.equal(res.headers.get('x-frame-options'), 'SAMEORIGIN');
  assert.ok(res.headers.get('permissions-policy'));
});

test('URL mal codificada responde 400, no 500', async () => {
  assert.equal(await crudo('/%E0%A4%A'), 400);
  assert.equal(await crudo('/api/admin/asociados/%E0%A4%A'), 401); // sin sesión, antes de decodificar
});

test('rechaza cuerpos que no son JSON (415)', async () => {
  const res = await fetch(`${base}/api/simular`, { method: 'POST', headers: { 'Content-Type': 'text/plain' }, body: '{"documento":"1001"}' });
  assert.equal(res.status, 415);
});

test('el panel exige sesión (401) y la cookie es HttpOnly y SameSite=Strict', async () => {
  assert.equal((await fetch(`${base}/api/admin/inscripciones`)).status, 401);
  const res = await postJson('/api/admin/login', { usuario: 'revisor', clave: 'clave-segura-123' });
  assert.equal(res.status, 200);
  const cookie = res.headers.get('set-cookie');
  assert.match(cookie, /HttpOnly/);
  assert.match(cookie, /SameSite=Strict/);
  assert.match(cookie, /Path=\/api\/admin/);
  const sid = cookie.split(';')[0];
  assert.equal((await fetch(`${base}/api/admin/sesion`, { headers: { Cookie: sid } })).status, 200);
});

test('mensaje genérico de identidad: no revela si el documento existe o tiene fecha registrada', async () => {
  db.prepare("UPDATE asociados SET expedicion_hmac = NULL WHERE documento = '1003'").run();
  const sinFecha = await (await postJson('/api/identificar', { documento: '1003', fechaExpedicion: '2012-01-09', autorizaDatos: true })).json();
  const inexistente = await (await postJson('/api/identificar', { documento: '8888888', fechaExpedicion: '2012-01-09', autorizaDatos: true })).json();
  assert.equal(sinFecha.error, inexistente.error);
});

test('límite de fallos de identificación por IP (429 con Retry-After)', async () => {
  // Las dos solicitudes de la prueba anterior ya contaron como fallos; se completa el máximo (4) con documentos distintos.
  for (const doc of ['7000001', '7000002']) {
    assert.equal((await postJson('/api/identificar', { documento: doc, fechaExpedicion: '2000-01-01', autorizaDatos: true })).status, 422);
  }
  const bloqueada = await postJson('/api/identificar', { documento: '1001', fechaExpedicion: '2008-03-14', autorizaDatos: true });
  assert.equal(bloqueada.status, 429);
  assert.ok(Number(bloqueada.headers.get('retry-after')) > 0);
  assert.equal((await fetch(`${base}/api/cupos`)).status, 200, 'las consultas públicas sin identidad siguen disponibles');
});

test('límite de intentos de ingreso al panel por IP', async () => {
  // Una solicitud ya se usó en la prueba de la cookie; quedan dos antes del bloqueo (máximo 3).
  for (let i = 0; i < 2; i++) assert.equal((await postJson('/api/admin/login', { usuario: 'revisor', clave: 'mala' })).status, 422);
  assert.equal((await postJson('/api/admin/login', { usuario: 'revisor', clave: 'clave-segura-123' })).status, 429);
});

test('límite general de la API por IP', async () => {
  const otro = await abrir({ loginPorIp: 10, identidadPorIp: 20, apiPorIp: 3 });
  const url = `http://127.0.0.1:${otro.address().port}/api/cupos`;
  for (let i = 0; i < 3; i++) assert.equal((await fetch(url)).status, 200);
  assert.equal((await fetch(url)).status, 429);
  otro.close();
});

test('los soportes de imagen se sirven aislados (CSP sandbox) y solo con sesión', async () => {
  const otro = await abrir({ loginPorIp: 10, identidadPorIp: 20, apiPorIp: 1000 });
  const url = (r) => `http://127.0.0.1:${otro.address().port}${r}`;
  const post = (r, c, h = {}) => fetch(url(r), { method: 'POST', headers: { 'Content-Type': 'application/json', ...h }, body: JSON.stringify(c) });
  const cred = { documento: '1001', fechaExpedicion: '2008-03-14' };
  const ins = await (await post('/api/inscripciones', { ...cred, autorizaDatos: true })).json();
  const png = Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]), Buffer.alloc(32)]).toString('base64');
  const soporte = await post('/api/inscripciones/soporte', {
    ...cred, cus: '123123123', banco: 'Bancolombia', fechaPago: '2026-10-01', valorPagado: ins.total,
    archivo: { nombre: 'pago.png', contenido: png }, autorizaDatos: true,
  });
  assert.equal(soporte.status, 200);
  const idSoporte = db.prepare('SELECT id FROM soportes').get().id;
  assert.equal((await fetch(url(`/api/admin/soportes/${idSoporte}`))).status, 401);
  const sid = (await post('/api/admin/login', { usuario: 'revisor', clave: 'clave-segura-123' })).headers.get('set-cookie').split(';')[0];
  const archivo = await fetch(url(`/api/admin/soportes/${idSoporte}`), { headers: { Cookie: sid } });
  assert.equal(archivo.status, 200);
  assert.equal(archivo.headers.get('content-type'), 'image/png');
  assert.match(archivo.headers.get('content-security-policy'), /sandbox/);
  assert.equal(archivo.headers.get('cross-origin-resource-policy'), 'same-origin');
  otro.close();
});

test('roles: el revisor no puede gestionar bases, usuarios ni anular; el administrador sí', async () => {
  const otro = await abrir({ loginPorIp: 50, identidadPorIp: 50, apiPorIp: 1000 });
  crearAdministracion(db).crearUsuario('rev', 'Revisora', 'clave-segura-456', { rol: 'REVISOR' });
  const url = (r) => `http://127.0.0.1:${otro.address().port}${r}`;
  const pedir = (r, c, sid, metodo = c ? 'POST' : 'GET') => fetch(url(r), {
    method: metodo, headers: { 'Content-Type': 'application/json', Cookie: sid || '' }, body: c && JSON.stringify(c),
  });
  const ingresar = async (u, k) => (await pedir('/api/admin/login', { usuario: u, clave: k })).headers.get('set-cookie').split(';')[0];
  const rev = await ingresar('rev', 'clave-segura-456');
  const adm = await ingresar('revisor', 'clave-segura-123');
  const ins = await (await pedir('/api/inscripciones', { documento: '1001', fechaExpedicion: '2008-03-14', autorizaDatos: true })).json();
  const id = db.prepare('SELECT id FROM inscripciones WHERE referencia = ?').get(ins.referencia).id;
  assert.equal((await (await pedir('/api/admin/sesion', undefined, rev)).json()).rol, 'REVISOR');
  assert.equal((await pedir('/api/admin/inscripciones', undefined, rev)).status, 200);
  for (const ruta of ['/api/admin/asociados', '/api/admin/usuarios', '/api/admin/bases', '/api/admin/auditoria']) {
    assert.equal((await pedir(ruta, undefined, rev)).status, 403, ruta);
    assert.equal((await pedir(ruta, undefined, adm)).status, 200, ruta);
  }
  assert.equal((await pedir('/api/admin/cupos/BOGOTA', { cupos: 400 }, rev)).status, 403);
  assert.equal((await pedir(`/api/admin/inscripciones/${id}/revision`, { accion: 'ANULAR', motivo: 'x' }, rev)).status, 403);
  assert.equal((await pedir(`/api/admin/inscripciones/${id}/revision`, { accion: 'ANULAR', motivo: 'Prueba' }, adm)).status, 200);
  otro.close();
});

test('restricción del panel por red: fuera de las redes permitidas responde 403; la parte pública sigue abierta', async () => {
  const { crearFiltroRedes } = require('../src/infraestructura/redes');
  const otro = await abrir({ loginPorIp: 50, identidadPorIp: 50, apiPorIp: 1000 }, { panelRedes: crearFiltroRedes('10.0.0.0/8') });
  const url = (r) => `http://127.0.0.1:${otro.address().port}${r}`;
  assert.equal((await fetch(url('/admin.html'))).status, 403);
  assert.equal((await fetch(url('/api/admin/login'), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' })).status, 403);
  assert.equal((await fetch(url('/'))).status, 200);
  assert.equal((await fetch(url('/api/cupos'))).status, 200);
  otro.close();
});

test('registro de seguridad: ingresos fallidos e identidad fallida con documento enmascarado', async () => {
  const eventos = [];
  const otro = await abrir({ loginPorIp: 50, identidadPorIp: 50, apiPorIp: 1000 }, { registrar: (e, d) => eventos.push({ e, ...d }) });
  const url = (r) => `http://127.0.0.1:${otro.address().port}${r}`;
  const post = (r, c) => fetch(url(r), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(c) });
  await post('/api/admin/login', { usuario: 'revisor', clave: 'incorrecta' });
  await post('/api/identificar', { documento: '12345678', fechaExpedicion: '2000-01-01', autorizaDatos: true });
  assert.ok(eventos.some((x) => x.e === 'login_fallido' && x.usuario === 'revisor'));
  assert.equal(eventos.find((x) => x.e === 'identidad_fallida').documento, '*****678');
  const texto = JSON.stringify(eventos);
  assert.ok(!texto.includes('2000-01-01') && !texto.includes('incorrecta'), 'no registra fechas ni claves');
  otro.close();
});

test('aviso de supresión de datos: solo para administradores y solo cuando se cumplió la fecha', async () => {
  const otro = await abrir({ loginPorIp: 50, identidadPorIp: 50, apiPorIp: 1000 }, { config: { fecha_supresion_datos: '2026-01-31' } });
  crearAdministracion(db).crearUsuario('rev2', 'Revisora Dos', 'clave-segura-789', { rol: 'REVISOR' });
  const url = (r) => `http://127.0.0.1:${otro.address().port}${r}`;
  const ingresar = async (u, k) => (await fetch(url('/api/admin/login'), {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ usuario: u, clave: k }),
  })).headers.get('set-cookie').split(';')[0];
  const sesion = async (sid) => (await fetch(url('/api/admin/sesion'), { headers: { Cookie: sid } })).json();
  assert.match((await sesion(await ingresar('revisor', 'clave-segura-123'))).avisos[0], /fecha de supresión/);
  assert.deepEqual((await sesion(await ingresar('rev2', 'clave-segura-789'))).avisos, []);
  otro.close();
});

test('X-Real-IP: se acepta del proxy confiable y se ignora de cualquier otro origen', async () => {
  const { crearFiltroRedes } = require('../src/infraestructura/redes');
  const intentos = async (s, ips) => {
    const url = `http://127.0.0.1:${s.address().port}/api/admin/login`;
    const estados = [];
    for (const ip of ips) {
      const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Real-IP': ip }, body: '{"usuario":"x","clave":"y"}' });
      estados.push(r.status);
    }
    s.close();
    return estados;
  };
  // Conexión desde 127.0.0.1, que es el proxy confiable: cada IP de la cabecera tiene su propio límite.
  const confiable = await abrir({ loginPorIp: 1, identidadPorIp: 50, apiPorIp: 1000 },
    { trustProxy: true, proxyConfiable: crearFiltroRedes('127.0.0.0/8', 'PROXY_CONFIABLE') });
  assert.deepEqual(await intentos(confiable, ['203.0.113.1', '203.0.113.2']), [422, 422]);
  // Conexión que no viene del proxy (p. ej. directa al puerto del contenedor): la cabecera se ignora y cambiarla
  // no evita el límite por IP.
  const directo = await abrir({ loginPorIp: 1, identidadPorIp: 50, apiPorIp: 1000 },
    { trustProxy: true, proxyConfiable: crearFiltroRedes('172.16.0.0/12', 'PROXY_CONFIABLE') });
  assert.deepEqual(await intentos(directo, ['203.0.113.1', '203.0.113.2']), [422, 429]);
});

test('RUTA_BASE: la cookie de sesión queda limitada al panel bajo el subdirectorio público', async () => {
  const s = await abrir({ loginPorIp: 50, identidadPorIp: 50, apiPorIp: 1000 }, { rutaBase: '/portal-eventos' });
  const r = await fetch(`http://127.0.0.1:${s.address().port}/api/admin/login`, {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ usuario: 'revisor', clave: 'clave-segura-123' }),
  });
  assert.equal(r.status, 200);
  assert.match(r.headers.get('set-cookie'), /; Path=\/portal-eventos\/api\/admin;/);
  s.close();
});

test('X-Forwarded-For (Render): se toma la IP agregada por el proxy, no la que escribe el cliente', async () => {
  const intentos = async (s, cabeceras) => {
    const url = `http://127.0.0.1:${s.address().port}/api/admin/login`;
    const estados = [];
    for (const xff of cabeceras) {
      const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Forwarded-For': xff }, body: '{"usuario":"x","clave":"y"}' });
      estados.push(r.status);
    }
    s.close();
    return estados;
  };
  const opciones = { trustProxy: true, cabeceraIp: 'x-forwarded-for', ipSaltos: 1 };
  // Mismo cliente real (203.0.113.9, la última IP) aunque cambie lo que escribe a la izquierda: comparte el límite.
  const mismo = await abrir({ loginPorIp: 1, identidadPorIp: 50, apiPorIp: 1000 }, opciones);
  assert.deepEqual(await intentos(mismo, ['1.1.1.1, 203.0.113.9', '2.2.2.2, 203.0.113.9']), [422, 429]);
  // Clientes reales distintos: límites independientes.
  const distintos = await abrir({ loginPorIp: 1, identidadPorIp: 50, apiPorIp: 1000 }, opciones);
  assert.deepEqual(await intentos(distintos, ['203.0.113.1', '203.0.113.2']), [422, 422]);
});
