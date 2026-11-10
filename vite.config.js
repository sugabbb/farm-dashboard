import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [
    laravel({ input: ['resources/js/app.tsx'], refresh: true }),
    react(),
  ],
  test: {
    environment: 'jsdom',
    setupFiles: ['./resources/js/test/setup.ts'],
    include: ['resources/js/**/*.test.tsx'],
  },
});
