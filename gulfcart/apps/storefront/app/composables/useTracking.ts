/**
 * Marketing tags (Meta Pixel, TikTok Pixel, Snap Pixel, Google Analytics 4), loaded only after the
 * visitor accepts cookies. IDs come from GET /site (set on the server, not in the code).
 *
 * Standard e-commerce events are mapped to each platform's names:
 *   view_item → ViewContent, add_to_cart → AddToCart, begin_checkout → InitiateCheckout, purchase → Purchase
 */
type EventName = 'view_item' | 'add_to_cart' | 'begin_checkout' | 'purchase'
interface EventData { value?: number, currency?: string, id?: string, name?: string, orderId?: string, quantity?: number }

type Win = Window & {
  fbq?: (...a: unknown[]) => void
  ttq?: { track: (...a: unknown[]) => void, page: () => void, load: (id: string) => void }
  snaptr?: (...a: unknown[]) => void
  gtag?: (...a: unknown[]) => void
  dataLayer?: unknown[]
}

const CONSENT_KEY = 'gc_consent'

function loadScript(src: string) {
  const s = document.createElement('script')
  s.async = true
  s.src = src
  document.head.appendChild(s)
}

export function useTracking() {
  const consent = useCookie<'all' | 'essential' | null>(CONSENT_KEY, { default: () => null, maxAge: 60 * 60 * 24 * 365, sameSite: 'lax' })
  const loaded = useState('tracking-loaded', () => false)
  const { site } = useSite()

  function init() {
    if (import.meta.server || loaded.value || consent.value !== 'all' || !site.value) return
    const ids = site.value.tracking
    const w = window as Win
    if (ids.meta_pixel_id) {
      const f = function (...args: unknown[]) { (f as unknown as { q: unknown[] }).q.push(args) } as unknown as Win['fbq'] & { q: unknown[] }
      f.q = []
      w.fbq = f
      loadScript('https://connect.facebook.net/en_US/fbevents.js')
      w.fbq!('init', ids.meta_pixel_id)
      w.fbq!('track', 'PageView')
    }
    if (ids.tiktok_pixel_id) {
      const q: unknown[] = []
      w.ttq = { track: (...a) => q.push(['track', ...a]), page: () => q.push(['page']), load: () => undefined }
      loadScript(`https://analytics.tiktok.com/i18n/pixel/events.js?sdkid=${encodeURIComponent(ids.tiktok_pixel_id)}&lib=ttq`)
      w.ttq.page()
    }
    if (ids.snap_pixel_id) {
      const q: unknown[] = []
      w.snaptr = (...a) => q.push(a)
      loadScript('https://sc-static.net/scevent.min.js')
      w.snaptr('init', ids.snap_pixel_id)
      w.snaptr('track', 'PAGE_VIEW')
    }
    if (ids.ga4_id) {
      w.dataLayer = w.dataLayer || []
      w.gtag = function (...args: unknown[]) { w.dataLayer!.push(args) }
      loadScript(`https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(ids.ga4_id)}`)
      w.gtag('js', new Date())
      w.gtag('config', ids.ga4_id)
    }
    loaded.value = true
  }

  function track(event: EventName, d: EventData = {}) {
    if (import.meta.server || consent.value !== 'all') return
    init()
    const w = window as Win
    const meta = { view_item: 'ViewContent', add_to_cart: 'AddToCart', begin_checkout: 'InitiateCheckout', purchase: 'Purchase' }[event]
    const snap = { view_item: 'VIEW_CONTENT', add_to_cart: 'ADD_CART', begin_checkout: 'START_CHECKOUT', purchase: 'PURCHASE' }[event]
    const common = { value: d.value, currency: d.currency, content_ids: d.id ? [d.id] : undefined, content_type: 'product' }
    w.fbq?.('track', meta, common, d.orderId ? { eventID: d.orderId } : undefined)
    w.ttq?.track(meta === 'ViewContent' ? 'ViewContent' : meta === 'AddToCart' ? 'AddToCart' : meta === 'InitiateCheckout' ? 'InitiateCheckout' : 'CompletePayment', { ...common, quantity: d.quantity })
    w.snaptr?.('track', snap, { price: d.value, currency: d.currency, item_ids: d.id ? [d.id] : undefined, transaction_id: d.orderId })
    w.gtag?.('event', event, { value: d.value, currency: d.currency, transaction_id: d.orderId, items: d.id ? [{ item_id: d.id, item_name: d.name, quantity: d.quantity ?? 1 }] : undefined })
  }

  function setConsent(value: 'all' | 'essential') {
    consent.value = value
    if (value === 'all') init()
  }

  return { consent, init, track, setConsent }
}
