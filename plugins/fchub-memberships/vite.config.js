import path from 'node:path'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import Components from 'unplugin-vue-components/vite'
import { ElementPlusResolver } from 'unplugin-vue-components/resolvers'

export default defineConfig({
  plugins: [
    vue(),
    Components({
      dts: false,
      resolvers: [
        ElementPlusResolver({
          importStyle: 'css',
          directives: true,
        }),
      ],
    }),
  ],
  resolve: {
    alias: {
      '@': path.resolve(process.cwd(), 'resources/admin'),
      '@portal': path.resolve(process.cwd(), 'resources/portal'),
    },
  },
  optimizeDeps: {
    noDiscovery: true,
    include: [
      '@element-plus/icons-vue',
      '@lucide/vue',
      '@tiptap/extension-link',
      '@tiptap/extension-placeholder',
      '@tiptap/extension-underline',
      '@tiptap/starter-kit',
      '@tiptap/vue-3',
      'chart.js',
      'element-plus',
      'element-plus/es',
      'element-plus/es/components/**/style/css',
      'vue',
      'vue-chartjs',
      'vue-router',
    ],
  },
  base: './',
  build: {
    manifest: true,
    outDir: 'assets/dist',
    emptyOutDir: true,
    rollupOptions: {
      input: {
        admin: 'resources/admin/main.js',
        portal: 'resources/portal/main.js',
      },
    },
  },
  test: {
    environment: 'jsdom',
    setupFiles: ['tests/admin/setup.js'],
    globals: true,
    css: true,
    include: ['tests/admin/**/*.test.js'],
    exclude: ['tests/admin-smoke/**'],
  },
})
