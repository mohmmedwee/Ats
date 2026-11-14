export interface SiteSettings {
  support: { email: string | null, whatsapp: string | null, hours: string | null }
  pages: { slug: string, title: string }[]
  tracking: { meta_pixel_id?: string, tiktok_pixel_id?: string, snap_pixel_id?: string, ga4_id?: string }
}

/** Storefront settings from GET /site, loaded once per locale and shared across components. */
export function useSite() {
  const site = useState<SiteSettings | null>('site', () => null)
  const loadedLocale = useState<string | null>('site-locale', () => null)
  const nuxtApp = useNuxtApp()
  const { request } = useApi()

  async function load(force = false) {
    const locale = String(nuxtApp.$i18n.locale.value)
    if (!force && site.value && loadedLocale.value === locale) return site.value
    try {
      site.value = (await request<{ data: SiteSettings }>('/site')).data
      loadedLocale.value = locale
    }
    catch {
      site.value ??= { support: { email: null, whatsapp: null, hours: null }, pages: [], tracking: {} }
    }
    return site.value
  }

  return { site, load }
}
