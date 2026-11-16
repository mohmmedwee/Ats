/** Currency of each storefront country (launch countries plus the prepared GCC ones). */
const CURRENCIES: Record<string, string> = { AE: 'AED', SA: 'SAR', KW: 'KWD', QA: 'QAR', BH: 'BHD', OM: 'OMR' }

export function currencyOf(country: string | null | undefined): string {
  return CURRENCIES[(country ?? '').toUpperCase()] ?? 'AED'
}
