// A missing licensing module should read as a missing module, not as a broken store.
//
// The three license reports are registered routes everywhere, but their tables exist only when the
// licensing module is active. Without it FluentCart answers HTTP 200 carrying a PHP fatal, which
// the client can only classify as CONNECTION_ERROR — so the caller was told the store was
// unreachable, and handed raw SQL plus an absolute path out of the plugin's stack trace:
//
//   Error [CONNECTION_ERROR]: Table 'wordpress.wp_fct_licenses' doesn't exist (SQL: select
//   count(*) ...): {"recovered":{"code":"plugin_exception","data":{"file":"/var/www/html/...
//
// Route pruning cannot catch this — the REST index says the route exists, and it does; it is the
// storage behind it that is absent.
import { describe, expect, it, vi } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import { FluentCartApiError } from '../../src/api/errors.js'
import { createAllTools } from '../../src/tools/index.js'

const LICENSE_TOOLS = [
	'fluentcart_report_license_summary',
	'fluentcart_report_license_chart',
	'fluentcart_report_license_pie_chart',
]

function clientRejecting(message: string) {
	const get = vi.fn().mockRejectedValue(new FluentCartApiError('CONNECTION_ERROR', message))
	return { get } as unknown as FluentCartClient
}

async function run(client: FluentCartClient, name: string) {
	const tool = createAllTools(client, {}).find((candidate) => candidate.name === name)
	if (!tool) throw new Error(`${name} is not registered`)
	const result = (await tool.handler({} as never, {} as never)) as {
		isError?: boolean
		content: { text: string }[]
	}
	return { isError: Boolean(result.isError), text: result.content[0]?.text ?? '' }
}

const MISSING_TABLE =
	'Table \'wordpress.wp_fct_licenses\' doesn\'t exist (SQL: select count(*) as aggregate from `wp_fct_licenses`): {"recovered":{"code":"plugin_exception","data":{"file":"/var/www/html/wp-content/plugins/fluent-cart/vendor/x.php"}}}'

describe('license reports explain an absent module', () => {
	for (const name of LICENSE_TOOLS) {
		it(`${name} says the module is not active`, async () => {
			const result = await run(clientRejecting(MISSING_TABLE), name)

			expect(result.isError).toBe(true)
			expect(result.text).toMatch(/licensing is not active/i)
		})

		it(`${name} forwards neither the SQL nor the server path`, async () => {
			const result = await run(clientRejecting(MISSING_TABLE), name)

			// A caller can act on "the module is off". It can do nothing with our filesystem layout.
			expect(result.text).not.toMatch(/var\/www|wp-content\/plugins/)
			expect(result.text).not.toMatch(/SQL:|select /i)
			expect(result.text).not.toMatch(/plugin_exception/)
		})
	}

	it('leaves every other failure untouched', async () => {
		// Only the missing-table signature is rewritten. A genuine outage must still look like one,
		// or this helper would swallow real problems behind a reassuring sentence.
		const result = await run(clientRejecting('fetch failed'), LICENSE_TOOLS[0] as string)

		expect(result.isError).toBe(true)
		expect(result.text).not.toMatch(/licensing is not active/i)
		expect(result.text).toMatch(/fetch failed/)
	})

	it('leaves a successful response untouched', async () => {
		const get = vi.fn().mockResolvedValue({ data: { total: 3 }, status: 200 })
		const result = await run({ get } as unknown as FluentCartClient, LICENSE_TOOLS[0] as string)

		expect(result.isError).toBe(false)
		expect(result.text).toContain('3')
	})
})
