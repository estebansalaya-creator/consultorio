/*
 * Service worker de la app instalable.
 * - Las páginas y la API van siempre a la red: nunca se guardan datos de pacientes en el dispositivo.
 * - Si no hay conexión, muestra offline.html.
 * - Estilos, scripts, fuentes e íconos se guardan para que la app abra más rápido
 *   (llevan ?v=fecha, así que al actualizar el sistema se bajan los nuevos).
 */
const CACHE = 'centro-medico-v1';
const PRECARGA = [
  'offline.html',
  'assets/img/icono.svg',
  'assets/img/app-192.png',
  'assets/fonts/hanken-grotesk-latin-400-normal.woff2',
  'assets/fonts/hanken-grotesk-latin-500-normal.woff2',
  'assets/fonts/hanken-grotesk-latin-600-normal.woff2',
  'assets/fonts/hanken-grotesk-latin-700-normal.woff2'
];

self.addEventListener('install', (ev) => {
  ev.waitUntil(caches.open(CACHE).then((c) => c.addAll(PRECARGA)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (ev) => {
  ev.waitUntil(
    caches.keys()
      .then((claves) => Promise.all(claves.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (ev) => {
  const req = ev.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== location.origin) return;

  // Páginas: siempre de la red; sin conexión, el aviso
  if (req.mode === 'navigate') {
    ev.respondWith(fetch(req).catch(() => caches.match('offline.html')));
    return;
  }

  // Archivos estáticos: primero lo guardado, si no, la red (y se guarda)
  if (url.pathname.indexOf('/assets/') !== -1) {
    ev.respondWith(
      caches.open(CACHE).then((c) => c.match(req).then((guardado) => guardado || fetch(req).then((resp) => {
        if (resp.ok) c.put(req, resp.clone());
        return resp;
      })))
    );
  }
  // Todo lo demás (PHP, API) va directo a la red sin pasar por acá
});
