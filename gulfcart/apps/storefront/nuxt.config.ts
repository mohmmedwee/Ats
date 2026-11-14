// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  devtools: { enabled: true },
  modules: ['@nuxtjs/i18n', '@nuxtjs/tailwindcss', '@pinia/nuxt', '@nuxt/eslint'],
  css: ['~/assets/css/main.css'],
  typescript: { strict: true },

  runtimeConfig: {
    public: {
      // Same-origin through Nginx in Docker; overridden by NUXT_PUBLIC_API_BASE.
      apiBase: '/api/v1',
      // Origin that serves /sanctum/csrf-cookie (empty = same origin).
      apiOrigin: '',
    },
  },

  // Local `nuxt dev` without Docker: proxy API calls to `php artisan serve`.
  nitro: {
    devProxy: {
      '/api': { target: 'http://127.0.0.1:8000/api', changeOrigin: true },
      '/sanctum': { target: 'http://127.0.0.1:8000/sanctum', changeOrigin: true },
    },
  },

  i18n: {
    defaultLocale: 'en',
    strategy: 'prefix_except_default',
    detectBrowserLanguage: { useCookie: true, cookieKey: 'gc_locale', redirectOn: 'root' },
    locales: [
      { code: 'en', language: 'en', name: 'English', dir: 'ltr', file: 'en.json' },
      { code: 'ar', language: 'ar', name: 'العربية', dir: 'rtl', file: 'ar.json' },
    ],
  },

  eslint: { config: { stylistic: false } },
})
