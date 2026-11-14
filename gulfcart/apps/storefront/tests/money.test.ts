import { describe, expect, it } from 'vitest'
import { formatMoney } from '~/utils/money'

describe('formatMoney', () => {
  it('formats AED in English', () => {
    expect(formatMoney({ amount: '201.00', currency: 'AED' }, 'en')).toMatch(/AED\s?201\.00/)
  })
  it('formats SAR in Arabic with Arabic currency label', () => {
    const out = formatMoney({ amount: '89.50', currency: 'SAR' }, 'ar')
    expect(out).toMatch(/ر\.س/)
  })
  it('renders a dash for missing prices', () => {
    expect(formatMoney(null)).toBe('—')
  })
})
