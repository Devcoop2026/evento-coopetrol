// Service worker: guarda en caché los recursos estáticos para que la página cargue rápido y se vea sin conexión.
// Las páginas y las acciones de Livewire siempre van a la red (dependen de la base de datos y de la sesión);
// el panel (/admin) nunca se guarda en caché. Subir CACHE al cambiar los archivos estáticos.
const CACHE = 'evento-coopetrol-v28';
const ESTATICOS = ['styles.css', 'js/interfaz.js', 'js/notificacion.js', 'js/validacion.js',
  'manifest.webmanifest', 'img/logo-coopetrol.png', 'img/icon.svg'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(ESTATICOS)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys()
    .then((claves) => Promise.all(claves.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
    .then(() => self.clients.claim()));
});

const base = new URL('./', self.registration.scope).pathname;
const esEstatico = (url) => /\.(css|js|png|svg|webmanifest)$/.test(url.pathname)
  && !url.pathname.startsWith(`${base}livewire`) && !url.pathname.startsWith(`${base}admin`);

self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);
  if (e.request.method !== 'GET' || url.origin !== location.origin || !esEstatico(url)) return;
  // Red primero; si no hay conexión, se usa la copia en caché.
  e.respondWith(
    fetch(e.request)
      .then((res) => {
        const copia = res.clone();
        caches.open(CACHE).then((c) => c.put(e.request, copia));
        return res;
      })
      .catch(() => caches.match(e.request, { ignoreSearch: true })),
  );
});
