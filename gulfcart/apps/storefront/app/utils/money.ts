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
