/** Registers the service worker in production builds (dev keeps hot reload uncached). */
export default defineNuxtPlugin(() => {
  if (import.meta.dev || !('serviceWorker' in navigator)) return
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch(() => undefined)
  })
})
