import { defineConfig } from 'vite'
import { configDefaults } from 'vitest/config'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  // Relative, because WordPress serves the build from the plugin directory and
  // nobody can tell it where that is until runtime.
  base: './',
  build: {
    manifest: true,
    outDir: 'assets/dist',
    emptyOutDir: true,
    rollupOptions: {
      // The object key names the output chunk; the manifest is keyed by the
      // source path, which is what AdminMenu::ENTRY_KEY looks up.
      input: {
        admin: 'resources/admin/main.js',
      },
    },
  },
  test: {
    environment: 'jsdom',
    setupFiles: ['tests/admin/setup.js'],
    globals: true,
    css: true,
    include: ['tests/admin/**/*.test.js'],
    // Spread rather than replaced: `exclude` overwrites Vitest's defaults, so
    // a plain array would quietly re-enable collecting tests out of
    // node_modules the moment `include` widens.
    exclude: [...configDefaults.exclude],
  },
})
