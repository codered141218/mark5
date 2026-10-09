/**
 * Minimal service worker so the POS can be installed as an app ("Add to Home screen").
 * It caches nothing: page loads simply go to the network, so the POS always shows live data.
 */
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));
self.addEventListener('fetch', (event) => {
  if (event.request.mode === 'navigate') event.respondWith(fetch(event.request));
});
