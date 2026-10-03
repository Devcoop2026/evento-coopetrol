// Service worker: cachea la interfaz para abrirla sin conexión.
// La API (api/* bajo el alcance del service worker, p. ej. /portal-eventos/api/) siempre va a la red porque las
// validaciones dependen de la base de datos.
const CACHE = 'evento-coopetrol-v27';
const RUTA_API = new URL('api/', self.registration.scope).pathname; // funciona en la raíz o bajo un subdirectorio
const ARCHIVOS = ['./', 'index.html', 'styles.css', 'app.js', 'comun.js', 'dialogo.js', 'notificacion.js', 'validacion.js', 'admin.html', 'admin.js', 'manifest.webmanifest', 'img/logo-coopetrol.png', 'img/icon.svg'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(ARCHIVOS)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys()
    .then((claves) => Promise.all(claves.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
    .then(() => self.clients.claim()));
});

self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);
  if (e.request.method !== 'GET' || url.origin !== location.origin || url.pathname.startsWith(RUTA_API)) return;
  // Red primero; si no hay conexión, se usa la copia en caché.
  e.respondWith(
    fetch(e.request)
      .then((res) => {
        const copia = res.clone();
        caches.open(CACHE).then((c) => c.put(e.request, copia));
        return res;
      })
      .catch(() => caches.match(e.request)),
  );
});
