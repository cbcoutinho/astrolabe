import vue from '@vitejs/plugin-vue'
import base from './vite.config.js'

// The admin and personal settings sections render inside the server's own
// settings page, which is itself an NcContent (#content-vue). Built separately
// so they ship only the CSS their components need: astrolabe-main.css carries
// the main app's global layout rules (body, .content, #content-vue), which
// pushed the settings layout to the top of the window.
export default {
  ...base,
  plugins: [vue()],
  build: {
    ...base.build,
    rollupOptions: {
      input: {
        'astrolabe-adminSettings': 'src/adminSettings.js',
        'astrolabe-personalSettings': 'src/personalSettings.js',
      },
      output: {
        entryFileNames: 'js/[name].mjs',
        chunkFileNames: 'js/settings-[name]-[hash].chunk.mjs',
        assetFileNames: (a) => (a.name?.endsWith('.css') ? 'css/astrolabe-settings.css' : 'js/[name][extname]'),
      },
    },
  },
}
