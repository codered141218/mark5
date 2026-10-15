/**
 * Minimal service worker so the POS can be installed as an app ("Add to Home screen"). It caches nothing.
 *
 * It only handles opening the POS page itself: back-office pages, form posts and everything else go straight to the
 * network as on a normal website (an earlier version passed every page through here, so a dropped connection showed
 * the browser's "ERR_FAILED" page). When the POS page cannot be reached it tries once more, then shows a small
 * "reconnecting" page that reloads by itself.
 */
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

const OFFLINE = `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reconnecting…</title><style>body{font-family:system-ui,sans-serif;display:grid;place-items:center;min-height:100vh;margin:0;background:#f2f4f9;color:#111827}
div{max-width:420px;padding:24px;text-align:center}button{font:inherit;font-weight:600;padding:12px 22px;border:0;border-radius:10px;background:#ea580c;color:#fff}</style></head>
<body><div><h2>Connection problem</h2><p>The POS cannot reach the server right now. Retrying in a few seconds…</p>
<button onclick="location.reload()">Retry now</button></div><script>setTimeout(() => location.reload(), 5000)</script></body></html>`;

const wait = (ms) => new Promise((r) => setTimeout(r, ms));

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.mode !== 'navigate' || req.method !== 'GET') return;           // forms, data, files: the browser handles them
  if (!/\/pos\/?$/.test(new URL(req.url).pathname)) return;              // only the POS page (works in a sub-folder too)
  event.respondWith(
    fetch(req)
      .catch(() => wait(1500).then(() => fetch(req)))
      .catch(() => new Response(OFFLINE, { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8', 'Retry-After': '5' } }))
  );
});
