/**
 * Small helpers shared by admin pages: one error/notice state per page, CSRF before writes,
 * idempotency keys for endpoints that require them.
 */
export function useAdmin() {
  const { request, csrf, idempotencyKey } = useApi()
  const nuxtApp = useNuxtApp()
  const error = ref('')
  const notice = ref('')
  const busy = ref(false)
  const t = (key: string) => String(nuxtApp.$i18n.t(key))

  async function run<T>(fn: () => Promise<T>, success?: string): Promise<T | undefined> {
    error.value = ''
    // Reloads after a write (no success message) keep the write's confirmation visible.
    if (success) notice.value = ''
    busy.value = true
    try {
      const result = await fn()
      if (success) notice.value = success
      return result
    }
    catch (e) {
      error.value = apiErrorMessage(e, t('errors.generic'))
      return undefined
    }
    finally {
      busy.value = false
    }
  }

  async function send<T = unknown>(method: 'POST' | 'PATCH' | 'PUT' | 'DELETE', url: string, body?: unknown, idempotent?: string): Promise<T> {
    await csrf()
    return request<T>(url, {
      method,
      body: body as Record<string, unknown> | undefined,
      headers: idempotent ? { 'Idempotency-Key': idempotencyKey(idempotent) } : undefined,
    })
  }

  /** Translatable JSON field ({en, ar}) shown in the current locale with a fallback. */
  function tr(value: Record<string, string | null> | string | null | undefined): string {
    if (!value) return ''
    if (typeof value === 'string') return value
    const locale = String(nuxtApp.$i18n.locale.value)
    return value[locale] || value.en || value.ar || ''
  }

  return { request, send, run, error, notice, busy, tr }
}
