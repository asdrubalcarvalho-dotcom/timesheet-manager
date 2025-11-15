import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],

  server: {
    host: '0.0.0.0',
    port: 3000,
    watch: {
      usePolling: true
    }
  },

  preview: {
    host: '0.0.0.0',
    port: 4173
  },

  build: {
    chunkSizeWarningLimit: 750,
    sourcemap: false,

    // Minificador correto para React + Emotion
    minify: 'esbuild',
    target: 'es2018',

    rollupOptions: {
      output: {
        manualChunks(id) {
          if (id.includes('node_modules')) {
            if (id.includes('react-router-dom')) return 'react-router'
            if (id.includes('react')) return 'react'
            if (id.includes('@tanstack/react-query')) return 'react-query'
            if (id.includes('@fullcalendar')) return 'fullcalendar'
            if (id.includes('@mui/material')) return 'mui-material'
            if (id.includes('@mui/icons-material')) return 'mui-icons'
            if (id.includes('@mui/x-date-pickers')) return 'mui-date-pickers'
            if (id.includes('@mui/x-data-grid')) return 'mui-data-grid'
            if (id.includes('@emotion')) return 'emotion'
            if (id.includes('dayjs')) return 'dayjs'
          }
        }
      }
    }
  }
})