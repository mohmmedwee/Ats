import { describe, expect, it } from 'vitest'
import en from '../i18n/locales/en.json'
import ar from '../i18n/locales/ar.json'

function keys(obj: Record<string, unknown>, prefix = ''): string[] {
  return Object.entries(obj).flatMap(([k, v]) =>
    typeof v === 'object' && v !== null ? keys(v as Record<string, unknown>, `${prefix}${k}.`) : [`${prefix}${k}`])
}

describe('i18n', () => {
  it('Arabic and English have the same keys', () => {
    expect(keys(ar).sort()).toEqual(keys(en).sort())
  })

  it('Arabic is configured right-to-left and English left-to-right', async () => {
    // nuxt.config.ts calls the auto-imported defineNuxtConfig; stub it as identity.
    ;(globalThis as Record<string, unknown>).defineNuxtConfig = (c: unknown) => c
    const config = (await import('../nuxt.config')).default
    const locales = (config as { i18n: { locales: { code: string, dir: string }[] } }).i18n.locales
    expect(locales.find(l => l.code === 'ar')?.dir).toBe('rtl')
    expect(locales.find(l => l.code === 'en')?.dir).toBe('ltr')
  })
})
