import { defineConfig } from 'vitest/config'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['src/test/setup.ts'],
    css: true,
  },
  server: {
    host: '0.0.0.0',
    port: 3000,
    watch: {
      usePolling: true,
    },
    // só para dev (quando usares Caddy -> Vite)
    allowedHosts: ['app.vendaslive.com'],
  },
  preview: {
    host: '0.0.0.0',
    port: 4173,
  },
  build: {
    // deixar o Vite tratar dos chunks sozinho
    chunkSizeWarningLimit: 1000,
    sourcemap: false,
    target: 'es2018',   // seguro para Emotion/MUI
    // sem minify manual, sem terser, sem manualChunks
  },
})
