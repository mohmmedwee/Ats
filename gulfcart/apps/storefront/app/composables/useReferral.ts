/**
 * Invite links look like /?ref=CODE. The code is kept in this browser for 30 days and applied
 * once the visitor creates an account (they then get a welcome discount code).
 */
const KEY = 'gc_ref'
const DAYS = 30

export function useReferral() {
  function capture(code: unknown) {
    if (typeof code !== 'string' || !/^[A-Za-z0-9]{4,16}$/.test(code)) return
    try {
      localStorage.setItem(KEY, JSON.stringify({ code: code.toUpperCase(), at: Date.now() }))
    }
    catch { /* storage unavailable */ }
  }

  function pending(): string | null {
    try {
      const raw = JSON.parse(localStorage.getItem(KEY) ?? 'null') as { code: string, at: number } | null
      return raw && Date.now() - raw.at < DAYS * 86400000 ? raw.code : null
    }
    catch {
      return null
    }
  }

  function clear() {
    try {
      localStorage.removeItem(KEY)
    }
    catch { /* storage unavailable */ }
  }

  /** Applies a stored invite code for the signed-in customer; returns the welcome code when it worked. */
  async function applyPending(): Promise<string | null> {
    const code = pending()
    if (!code) return null
    const { request, csrf } = useApi()
    try {
      await csrf()
      const res = await request<{ data: { code: string } }>('/loyalty/referral', { method: 'POST', body: { code } })
      return res.data.code
    }
    catch {
      return null
    }
    finally {
      clear()
    }
  }

  return { capture, pending, clear, applyPending }
}
