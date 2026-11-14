export type Direction = 'ltr' | 'rtl'

const RTL_LOCALES = new Set(['ar', 'fa', 'he', 'ur'])

/** Text direction for a BCP 47 locale such as `ar`, `ar-AE` or `en-SA`. */
export function directionFor(locale: string): Direction {
  const language = locale.toLowerCase().split('-')[0] ?? ''
  return RTL_LOCALES.has(language) ? 'rtl' : 'ltr'
}
