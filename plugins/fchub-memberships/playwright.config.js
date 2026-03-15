import { defineConfig } from '@playwright/test'

export default defineConfig({
  testDir: './tests/admin-smoke',
  use: {
    baseURL: 'http://127.0.0.1:4173',
    headless: true,
  },
  webServer: {
    command: 'npm exec vite -- --host 127.0.0.1 --port 4173',
    port: 4173,
    reuseExistingServer: true,
  },
})
