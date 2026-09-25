import { defineConfig } from 'vite';

import react from '@vitejs/plugin-react-swc';

// Built output is committed to ../editor and served as static files by the
// odb web server - no Node at runtime. Relative base so it works under any
// mount path (/odb/edm/email-builder/editor/ locally and in production).
export default defineConfig({
  plugins: [react()],
  base: './',
  build: {
    outDir: '../editor',
    emptyOutDir: true,
    chunkSizeWarningLimit: 2000,
  },
});
