import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'path'
import { cpSync, readFileSync } from 'fs'

/**
 * Ship the PDF.js runtime assets that are loaded at run time rather than
 * bundled.
 *
 * Scanned PDFs — the ones this app is mostly asked to preview — store pages as
 * JPEG 2000 or JBIG2, which PDF.js decodes with an OpenJPEG/JBIG2 WASM module
 * fetched from `wasmUrl`. Without these files the decoder fails to initialise
 * and every scanned page renders as a correctly-sized but completely blank
 * canvas, with only a console warning to show for it. cmaps/standard_fonts/iccs
 * are the same story for CJK text, non-embedded fonts and colour profiles.
 */
function pdfjsAssets() {
  const dirs = ['wasm', 'cmaps', 'standard_fonts', 'iccs']
  return {
    name: 'astrolabe:pdfjs-assets',
    apply: 'build',
    closeBundle() {
      for (const dir of dirs) {
        cpSync(
          resolve(__dirname, 'node_modules/pdfjs-dist', dir),
          resolve(__dirname, 'pdfjs', dir),
          { recursive: true },
        )
      }
    },
  }
}

// Read app info from info.xml for @nextcloud/vue
const infoXml = readFileSync(resolve(__dirname, 'appinfo/info.xml'), 'utf-8')
const appName = infoXml.match(/<id>([^<]+)<\/id>/)?.[1] || 'astrolabe'
const appVersion = infoXml.match(/<version>([^<]+)<\/version>/)?.[1] || ''

export default defineConfig({
  plugins: [vue(), pdfjsAssets()],
  // Nextcloud serves an app from /custom_apps/<app>/ (or /apps/<app>/), never
  // from the server root. With the default base of '/', Vite emits
  // root-absolute URLs for dynamically imported chunks, so every one of them is
  // requested from /js/… and 404s. A relative base makes chunk URLs resolve
  // against the importing module's own URL, which lands in the app directory
  // wherever the app happens to be installed.
  base: './',
  define: {
    appName: JSON.stringify(appName),
    appVersion: JSON.stringify(appVersion),
  },
  build: {
    outDir: '.',
    emptyOutDir: false,
    cssCodeSplit: false,  // Bundle all CSS into entry points (Nextcloud doesn't load CSS chunks)
    rollupOptions: {
      input: {
        'astrolabe-main': resolve(__dirname, 'src/main.js'),
        'astrolabe-adminSettings': resolve(__dirname, 'src/adminSettings.js'),
        'astrolabe-personalSettings': resolve(__dirname, 'src/personalSettings.js'),
      },
      output: {
        entryFileNames: 'js/[name].mjs',
        chunkFileNames: 'js/[name]-[hash].chunk.mjs',
        assetFileNames: (assetInfo) => {
          // With cssCodeSplit:false, all CSS goes to a single file
          // Name it astrolabe-main.css to match Nextcloud's Util::addStyle expectation
          if (assetInfo.name && assetInfo.name.endsWith('.css')) {
            return 'css/astrolabe-main.css';
          }
          return 'js/[name][extname]';
        },
      },
    },
    sourcemap: true,
    minify: 'terser',
  },
})
