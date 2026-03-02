import { loadEnv } from 'vite'
import { defineConfig } from 'vitest/config'

export default defineConfig({
  test: {
    globals: true,
    env: loadEnv('test', process.cwd(), ''),
    coverage: {
      provider: 'v8',
      include: ['src/**/*.ts'],
      exclude: ['src/index.ts', 'src/cli/**'],
      reporter: ['text', 'json-summary'],
    },
  },
})
