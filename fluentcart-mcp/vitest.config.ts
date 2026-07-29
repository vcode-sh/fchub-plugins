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
					'src/tools/coupons-writes.ts': {
					statements: 75,
					branches: 65,
					functions: 80,
					lines: 75,
				},
				'src/tools/customers-writes.ts': {
					statements: 75,
					branches: 65,
					functions: 80,
					lines: 75,
				},
				'src/tools/products-variant-writes.ts': {
					statements: 75,
					branches: 65,
					functions: 80,
					lines: 75,
				},
				'src/transport/http-config.ts': {
					statements: 82,
					branches: 80,
					functions: 85,
					lines: 85,
				},
				'src/transport/http-service.ts': {
					statements: 92,
					branches: 69,
					functions: 93,
					lines: 94,
				},
			},
		},
	},
})
