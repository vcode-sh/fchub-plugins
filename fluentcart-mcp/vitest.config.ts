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
			thresholds: {
				statements: 79,
				branches: 68,
				functions: 84,
				lines: 81,
				'src/api/capabilities.ts': {
					statements: 96,
					branches: 86,
					functions: 100,
					lines: 97,
				},
				'src/commerce/context.ts': {
					statements: 100,
					branches: 100,
					functions: 100,
					lines: 100,
				},
				'src/security/guarded-action.ts': {
					statements: 90,
					branches: 86,
					functions: 85,
					lines: 93,
				},
				'src/tools/_factory.ts': {
					statements: 92,
					branches: 87,
					functions: 83,
					lines: 93,
				},
				'src/tools/dynamic.ts': {
					statements: 96,
					branches: 90,
					functions: 100,
					lines: 96,
				},
				'src/tools/orders-refunds.ts': {
					statements: 97,
					branches: 86,
					functions: 100,
					lines: 96,
				},
				'src/tools/subscriptions-cancellation.ts': {
					statements: 98,
					branches: 79,
					functions: 100,
					lines: 98,
				},
				'src/transport/http.ts': {
					statements: 74,
					branches: 66,
					functions: 63,
					lines: 74,
				},
			},
		},
	},
})
