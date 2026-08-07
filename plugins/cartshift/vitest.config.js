import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import { resolve } from 'path';

export default defineConfig({
  plugins: [vue()],
  resolve: {
    // Same alias as vite.config.js, so `@/composables/...` resolves in tests
    // exactly the way it does in the shipped bundle.
    alias: {
      '@': resolve(__dirname, 'src'),
    },
  },
  test: {
    environment: 'happy-dom',
    include: ['tests/js/**/*.test.js'],
    globals: false,
    restoreMocks: true,
  },
});
