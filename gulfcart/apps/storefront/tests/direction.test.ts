import { describe, expect, it } from 'vitest'
import { directionFor } from '~/utils/direction'

describe('directionFor', () => {
  it.each(['ar', 'ar-AE', 'ar-SA', 'AR'])('returns rtl for %s', (locale) => {
    expect(directionFor(locale)).toBe('rtl')
  })
  it.each(['en', 'en-AE', 'en-SA'])('returns ltr for %s', (locale) => {
    expect(directionFor(locale)).toBe('ltr')
  })
})
