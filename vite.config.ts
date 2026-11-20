import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [react()],
  // NO usar public/ (front-controller Slim). static/ se copia tal cual a dist/:
  // ahí van los videos y manuales de la página Ayuda (static/manuales/).
  publicDir: 'static',
  optimizeDeps: {
    exclude: ['lucide-react'],
  },
});
