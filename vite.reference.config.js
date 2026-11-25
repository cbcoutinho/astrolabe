import vue from '@vitejs/plugin-vue'
import base from './vite.config.js'

// The reference widget (MCP App viewer in the Assistant chat, POC) is loaded on
// other apps' pages (Files, Assistant, Talk, …). It is built separately so it
// ships only its own CSS: astrolabe-main.css carries @nextcloud/vue's global
// layout rules (body, .content, :root), which broke the Files app layout.
export default {
  ...base,
  plugins: [vue()],
  build: {
    ...base.build,
    rollupOptions: {
      input: { 'astrolabe-reference': 'src/reference.js' },
      output: {
        entryFileNames: 'js/[name].mjs',
        chunkFileNames: 'js/reference-[name]-[hash].chunk.mjs',
        assetFileNames: (a) => (a.name?.endsWith('.css') ? 'css/astrolabe-reference.css' : 'js/[name][extname]'),
      },
    },
  },
}
