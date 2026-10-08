import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  // Scan lazy routes before serving so newly discovered dependencies do not reload the page.
  optimizeDeps: { entries: ['index.html', 'src/**/*.{js,jsx}'] },
  server: {
    host: 'localhost',
    port: 5173,
    strictPort: true,
    hmr: process.env.LOCAL_DISABLE_HMR === 'true' ? false : undefined,
    ws: process.env.LOCAL_DISABLE_HMR === 'true' ? false : undefined,
    proxy: { '/api': { target: process.env.LOCAL_API_PROXY || 'http://localhost:8082', changeOrigin: true } },
  },
})

