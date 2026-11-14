/**
 * Fetch wrapper for the GulfCart API using Sanctum SPA cookie authentication.
 * Sends credentials, Accept-Language, X-Country and the XSRF token header.
 *
 * Uses the Nuxt app instance rather than component-only composables (useI18n),
 * so it is safe to call from Pinia stores and event handlers.
 */
export function useApi() {
  const nuxtApp = useNuxtApp()
  const config = useRuntimeConfig()
  const country = useCookie<'AE' | 'SA'>('gc_country', { default: () => 'AE' })
  const xsrf = useCookie('XSRF-TOKEN')

  const request = $fetch.create({
    baseURL: config.public.apiBase,
    credentials: 'include',
    onRequest({ options }) {
      const headers = new Headers(options.headers)
      headers.set('Accept', 'application/json')
      headers.set('Accept-Language', String(nuxtApp.$i18n.locale.value))
      headers.set('X-Country', country.value)
      // Re-read the cookie: /sanctum/csrf-cookie may have just refreshed it.
      const token = import.meta.client ? readCookie('XSRF-TOKEN') : xsrf.value
      if (token) headers.set('X-XSRF-TOKEN', token)
      options.headers = headers
    },
  })

  /** Must be called once before the first state-changing request. */
  const csrf = () => $fetch(`${config.public.apiOrigin}/sanctum/csrf-cookie`, { credentials: 'include' })

  return { request, csrf, country }
}

function readCookie(name: string): string | null {
  const match = document.cookie.split('; ').find(c => c.startsWith(`${name}=`))
  return match ? decodeURIComponent(match.slice(name.length + 1)) : null
}
