import { fileURLToPath, URL } from 'node:url'

import tailwindcss from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'
// vitest's defineConfig is vite's plus the `test` block.
import { defineConfig } from 'vitest/config'

export default defineConfig({
  plugins: [vue(), tailwindcss()],
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
  server: {
    port: 5173,
    // The API is same-origin in the browser, so cookies and CSRF stay simple.
    proxy: {
      '/api': { target: process.env.VITE_API_URL ?? 'http://localhost:8000', changeOrigin: true },
      '/health': { target: process.env.VITE_API_URL ?? 'http://localhost:8000', changeOrigin: true },
    },
  },
  test: {
    environment: 'happy-dom',
    include: ['tests/**/*.spec.ts'],
  },
})
