// FFT Admin app: always loads the newest pages from the server; shows a small page when there is no internet.
const OFFLINE = '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>FFT Admin</title>'
  + '<body style="margin:0;min-height:100vh;display:grid;place-items:center;background:#08111f;color:#c3cee0;font:16px system-ui;text-align:center">'
  + '<div><div style="font-size:48px">📡</div><p><b>No internet</b><br>Check the connection, then pull down to try again.</p>'
  + '<button onclick="location.reload()" style="padding:12px 20px;border:0;border-radius:10px;background:#1f6bff;color:#fff;font-weight:700">Try again</button></div>';
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));
self.addEventListener('fetch', (e) => {
  if (e.request.mode !== 'navigate') return;   // everything else goes straight to the network
  e.respondWith(fetch(e.request).catch(() => new Response(OFFLINE, { headers: { 'Content-Type': 'text/html; charset=utf-8' } })));
});
