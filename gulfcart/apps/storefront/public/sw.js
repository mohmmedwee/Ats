/*
 * GulfCart service worker: makes the site installable and fast on repeat visits.
 * - Never caches the API, payments, admin or anything non-GET: prices, stock and carts are always live.
 * - Build assets (/_nuxt/, hashed) and images: cache first.
 * - Pages: network first, falling back to the last copy, then an offline page.
 */
const VERSION = 'gc-v1'
const STATIC = `${VERSION}-static`
const PAGES = `${VERSION}-pages`
const OFFLINE = '/offline.html'

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(PAGES).then(c => c.add(OFFLINE)).then(() => self.skipWaiting()))
})

self.addEventListener('activate', (event) => {
  event.waitUntil(caches.keys()
    .then(keys => Promise.all(keys.filter(k => !k.startsWith(VERSION)).map(k => caches.delete(k))))
    .then(() => self.clients.claim()))
})

const NEVER = ['/api/', '/sanctum/', '/admin', '/payment', '/checkout', '/feeds/', '/sitemap', '/robots']

self.addEventListener('fetch', (event) => {
  const req = event.request
  const url = new URL(req.url)
  if (req.method !== 'GET' || url.origin !== self.location.origin) return
  if (NEVER.some(p => url.pathname.startsWith(p) || url.pathname.includes(p))) return

  if (url.pathname.startsWith('/_nuxt/') || /\.(png|jpe?g|webp|avif|svg|gif|ico|woff2?)$/.test(url.pathname)) {
    event.respondWith(caches.open(STATIC).then(async (cache) => {
      const hit = await cache.match(req)
      if (hit) return hit
      const res = await fetch(req)
      if (res.ok) cache.put(req, res.clone())
      return res
    }))
    return
  }

  if (req.mode === 'navigate') {
    event.respondWith(fetch(req).then((res) => {
      if (res.ok) caches.open(PAGES).then(c => c.put(req, res.clone()))
      return res
    }).catch(async () => (await caches.match(req)) ?? (await caches.match(OFFLINE))))
  }
})
