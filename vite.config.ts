import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [react()],
  publicDir: false,            // NO copiar public/ (front-controller Slim) dentro de dist/
  optimizeDeps: {
    exclude: ['lucide-react'],
  },
});
