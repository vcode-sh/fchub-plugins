import { defineConfig } from 'vitest/config'

// This configuration reads the child-process environment only. It never loads an env file;
// scripts/run-live-tests.mjs is the single place allowed to parse .env.test.local.
if (process.env.FLUENTCART_RUN_INTEGRATION !== 'yes') {
	throw new Error('Live integration tests require scripts/run-live-tests.mjs')
}

export default defineConfig({
	test: {
		globals: true,
		include: ['tests/integration/**/*.test.ts'],
		testTimeout: 60_000,
		hookTimeout: 60_000,
		fileParallelism: false,
	},
})
