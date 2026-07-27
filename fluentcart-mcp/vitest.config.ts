import { defineConfig } from 'vitest/config'

// The unit lane is deliberately credential-free: it reads no environment file family and
// performs no network I/O. Live coverage belongs to vitest.integration.config.ts, which is
// reachable only through scripts/run-live-tests.mjs.
export default defineConfig({
	test: {
		globals: true,
		include: ['tests/**/*.test.ts'],
		exclude: ['tests/integration/**', 'tests/manual/**'],
		coverage: {
			provider: 'v8',
			include: ['src/**/*.ts'],
			exclude: ['src/index.ts', 'src/cli/**'],
			reporter: ['text', 'json-summary'],
		},
	},
})
