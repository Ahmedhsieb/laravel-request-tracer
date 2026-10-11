import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'

// The dashboard is built into dist/ (committed) and served by the package (or published to public/vendor/request-tracer).
// File names are fixed (app.js / app.css) so the Blade view does not need a manifest.
export default defineConfig({
  plugins: [vue()],
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
    target: 'es2020',
    chunkSizeWarningLimit: 600,
    rollupOptions: {
      input: 'resources/js/main.ts',
      output: {
        entryFileNames: 'app.js',
        assetFileNames: 'app.[ext]',
        inlineDynamicImports: true,
      },
    },
  },
  test: {
    environment: 'jsdom',
    include: ['resources/js/**/*.test.ts'],
  },
})
