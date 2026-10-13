import tailwindcss from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vitest/config'

// The dashboard is built into dist/ (committed) and served by the package (or published to public/vendor/request-tracer).
// app.js and app.css have fixed names so the Blade view does not need a manifest; the fonts are emitted next to them with a hash, and `base: './'` makes the
// CSS name them relative to itself, so they resolve under whatever prefix the dashboard is served from (`/request-tracer/assets/`).
export default defineConfig({
  base: './',
  plugins: [vue(), tailwindcss()],
  publicDir: 'resources/public',
  define: {
    __VUE_OPTIONS_API__: 'false',
    __VUE_PROD_DEVTOOLS__: 'false',
    __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false',
  },
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    sourcemap: false,
    cssCodeSplit: false,
    target: 'es2022',
    chunkSizeWarningLimit: 900,
    rollupOptions: {
      input: 'resources/js/main.ts',
      output: {
        entryFileNames: 'app.js',
        assetFileNames: (asset) => (asset.names.some((name) => name.endsWith('.css')) ? 'app.css' : '[name]-[hash][extname]'),
        inlineDynamicImports: true,
      },
    },
  },
  test: {
    environment: 'jsdom',
    include: ['resources/js/**/*.test.ts'],
  },
})
