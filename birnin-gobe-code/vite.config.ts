import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import path from 'node:path';

export default defineConfig({
  resolve: { alias: { '@': path.resolve(__dirname, 'resources/js') } },
  plugins: [
    laravel({ input: ['resources/css/app.css', 'resources/js/app.tsx'], refresh: true }),
    react(),
    tailwindcss(),
  ],
});
