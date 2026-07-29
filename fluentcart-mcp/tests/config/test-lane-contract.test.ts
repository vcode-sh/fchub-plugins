import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

const packageRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..')

function readPackageRootFile(relativePath: string): string {
	return readFileSync(resolve(packageRoot, relativePath), 'utf8')
}

const pkg = JSON.parse(readPackageRootFile('package.json')) as {
	scripts: Record<string, string>
}
const unitConfig = readPackageRootFile('vitest.config.ts')
const integrationConfig = readPackageRootFile('vitest.integration.config.ts')

describe('test lane contract', () => {
	it('binds every npm test script to an explicit configuration', () => {
		expect(pkg.scripts.build).toBe(
			`node -e "require('node:fs').rmSync(require('node:path').resolve('dist'), { recursive: true, force: true })" && tsc`,
		)
		expect(pkg.scripts.test).toBe('vitest run --config vitest.config.ts')
		expect(pkg.scripts['test:unit']).toBe('vitest run --config vitest.config.ts')
		expect(pkg.scripts['test:integration:local']).toBe('node scripts/run-live-tests.mjs')
		expect(pkg.scripts['test:tooling']).toBe(
			'npm run build && node --test tests/tooling/*.test.mjs',
		)
		expect(pkg.scripts['test:acceptance']).toBe('node --test tests/acceptance/*.test.mjs')
		expect(pkg.scripts['typecheck:tests']).toBe('tsc --project tsconfig.tests.json')
		expect(pkg.scripts['check:routes']).toBe(
			'node scripts/check-tool-routes.mjs --fixture tests/fixtures/routes/fluentcart-1.5.5-core-pro-1.5.4.json',
		)
	})

	it('keeps the unit lane free of environment file loading', () => {
		expect(unitConfig).not.toContain('loadEnv')
		expect(unitConfig).not.toContain('loadEnvFile')
	})

	it('excludes live and manual paths from the unit lane', () => {
		expect(unitConfig).toContain("exclude: ['tests/integration/**', 'tests/manual/**']")
	})

	it('restricts the integration lane to integration tests only', () => {
		expect(integrationConfig).toContain("include: ['tests/integration/**/*.test.ts']")
	})

	it('keeps the integration lane free of environment file loading', () => {
		expect(integrationConfig).not.toContain('loadEnv')
		expect(integrationConfig).not.toContain('loadEnvFile')
	})

	it('refuses integration configuration evaluation without the launcher flag', () => {
		expect(integrationConfig).toContain("process.env.FLUENTCART_RUN_INTEGRATION !== 'yes'")
		expect(integrationConfig).toContain('Live integration tests require scripts/run-live-tests.mjs')
	})

	it('never names a credential variable in either configuration', () => {
		for (const config of [unitConfig, integrationConfig]) {
			expect(config).not.toContain('FLUENTCART_APP_PASSWORD')
			expect(config).not.toContain('FLUENTCART_USERNAME')
		}
	})

	it('gates HTTP coverage at the implementation modules rather than the compatibility adapter', () => {
		expect(unitConfig).toContain("'src/transport/http-config.ts'")
		expect(unitConfig).toContain("'src/transport/http-service.ts'")
		expect(unitConfig).not.toContain("'src/transport/http.ts'")
	})
})
