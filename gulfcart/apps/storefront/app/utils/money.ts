import type { Money } from '~/types/api'

/** Formats a decimal-string amount; never converts through arithmetic beyond display. */
export function formatMoney(money: Money | null | undefined, locale = 'en'): string {
  if (!money) return '—'
  const tag = locale.startsWith('ar') ? 'ar-AE' : 'en-AE'
  return new Intl.NumberFormat(tag, { style: 'currency', currency: money.currency, minimumFractionDigits: 2, maximumFractionDigits: 2 })
    .format(Number(money.amount))
}

export function money(amount: string, currency: string): Money {
  return { amount, currency }
}

/** Short localized date-time for admin tables. */
export function formatDateTime(iso: string | null | undefined, locale = 'en'): string {
  if (!iso) return '—'
  return new Intl.DateTimeFormat(locale.startsWith('ar') ? 'ar-AE' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso))
}

/** Whole-number discount between a sale price and the regular price, or 0 when not on sale. */
export function salePercent(price: Money | null | undefined, regular: Money | null | undefined): number {
  const p = Number(price?.amount ?? 0)
  const r = Number(regular?.amount ?? 0)
  return r > 0 && p > 0 && p < r ? Math.round((1 - p / r) * 100) : 0
}
