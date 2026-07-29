import { afterEach, describe, expect, it, vi } from 'vitest'
import { createClient } from '../../src/api/client.js'
import { resolveApiUrls } from '../../src/config/types.js'
import type { ToolDefinition } from '../../src/tools/_factory.js'
import { createAllTools } from '../../src/tools/index.js'

/**
 * What a misbehaving store can push through this server and into a tool result.
 *
 * Everything is driven through the real tool handlers with `fetch` stubbed, so the assertions are
 * about the text an agent would actually receive — not about an intermediate object nobody sees.
 */

const CONFIG = resolveApiUrls({
	url: 'https://store.example',
	username: 'admin',
	appPassword: 'never-sent-anywhere-here',
})

function stubFetch(body: string, status = 200): void {
	vi.stubGlobal(
		'fetch',
		vi.fn(async () => ({
			ok: status < 400,
			status,
			statusText: 'Internal Server Error',
			text: async () => body,
		})) as unknown as typeof fetch,
	)
}

function toolNamed(name: string): ToolDefinition {
	const tool = createAllTools(createClient(CONFIG)).find((entry) => entry.name === name)
	if (!tool) throw new Error(`${name} is not in the registry`)
	return tool
}

async function resultText(
	name: string,
	input: Record<string, unknown> = {},
): Promise<{ text: string; isError?: boolean }> {
	const result = await toolNamed(name).handler(input)
	return { text: result.content[0]?.text ?? '', isError: result.isError }
}

afterEach(() => {
	vi.unstubAllGlobals()
})

/**
 * DEFECT — upstream failure text reaches the caller verbatim, internals and all.
 *
 * `handleErrorResponse` (src/api/client.ts:69) builds the error from whatever the store sent: the
 * JSON `message` when there is one, otherwise `previewRaw(raw)`, a 220-character slice of the raw
 * body. Both land in `FluentCartApiError`, and `formatError` (src/tools/_factory.ts:215) writes
 * them into the tool result after `redactSensitive`, which only removes credential-shaped keys and
 * `Basic`/`Bearer` strings. A PHP fatal, a stack trace, an absolute filesystem path and a SQL
 * statement all pass it untouched.
 *
 * This is the same class already found in the license reports leaking `/var/www/html/...` and raw
 * SQL — but it is not specific to those tools. It is the shared client path, so every tool built
 * by the endpoint factory has it.
 *
 * The assertions name the categories rather than the exact strings, so any scrub that keeps the
 * error useful (a code, a status, a short human message) satisfies them.
 */
describe('server internals in a tool result', () => {
	const PHP_FATAL =
		'<br /><b>Fatal error</b>: Uncaught PDOException: SQLSTATE[42S02]: Base table or view ' +
		"not found: 1146 Table 'wp_fct_orders' doesn't exist in " +
		'/var/www/html/wp-content/plugins/fluent-cart/app/Http/Controllers/OrderController.php:88' +
		'\nStack trace:\n#0 /var/www/html/wp-includes/rest-api.php(1234)'

	const WP_DB_ERROR = JSON.stringify({
		code: 'db_error',
		message:
			"WordPress database error: [Table 'wordpress.wp_fct_orders' doesn't exist]\n" +
			'SELECT * FROM wp_fct_orders WHERE id = 1',
		data: {
			status: 500,
			file: '/var/www/html/wp-content/plugins/fluent-cart/app/Models/Order.php',
			line: 88,
		},
	})

	it('does not echo an absolute server path out of a PHP fatal', async () => {
		stubFetch(PHP_FATAL, 500)
		const { text } = await resultText('fluentcart_order_get', { order_id: 1 })

		expect(text).not.toContain('/var/www/html')
	})

	it('does not echo a stack trace out of a PHP fatal', async () => {
		stubFetch(PHP_FATAL, 500)
		const { text } = await resultText('fluentcart_order_get', { order_id: 1 })

		expect(text).not.toMatch(/Stack trace|PDOException|\.php:\d+/)
	})

	it('does not echo a SQL statement out of a WordPress database error', async () => {
		stubFetch(WP_DB_ERROR, 500)
		const { text } = await resultText('fluentcart_order_list')

		expect(text).not.toMatch(/SELECT .* FROM/i)
	})

	it('does not echo the failing source file out of a WordPress error payload', async () => {
		stubFetch(WP_DB_ERROR, 500)
		const { text } = await resultText('fluentcart_order_list')

		// `detail` is the whole parsed body; `data.file` is not a credential-shaped key, so
		// redactSensitive leaves it alone.
		expect(text).not.toContain('/var/www/html')
	})

	// Still reports the failure, which is the part that must survive any fix.
	it('reports the failure as an error result rather than swallowing it', async () => {
		stubFetch(PHP_FATAL, 500)
		const { text, isError } = await resultText('fluentcart_order_get', { order_id: 1 })

		expect(isError).toBe(true)
		expect(text).toContain('SERVER_ERROR')
	})
})

/**
 * SECURITY CONTRACT — successful MCP output boundaries redact upstream credentials.
 *
 * Successful tool, resource and Ability responses all redact at their real MCP output boundaries.
 * This test family keeps that contract pinned independently of optional protocol capabilities:
 * removing client-directed MCP logging must never remove response redaction with it.
 *
 * That matters because the credential-bearing risk class only covers writes. A GET is never in the
 * risk registry, so it resolves to READ_SAFETY and is exposed in every write mode including
 * `disabled` — including the two reads below, whose entire purpose is to return gateway and
 * integration configuration. A store that puts a live secret key in that payload hands it to the
 * agent, and the same secret in an *error* on the same route would have been redacted.
 */
describe('credentials on the success path', () => {
	it('redacts a gateway secret key returned by the payment settings read', async () => {
		stubFetch(
			JSON.stringify({
				settings: {
					stripe: {
						publishable_key: 'pk_live_1',
						secret_key: 'sk_live_51ABCDEFabcdef',
						webhook_secret: 'whsec_topsecret',
					},
				},
			}),
		)
		const { text } = await resultText('fluentcart_payment_get_settings', { method: 'stripe' })

		expect(text).not.toContain('sk_live_51ABCDEFabcdef')
		expect(text).not.toContain('whsec_topsecret')
	})

	it('redacts an integration API token returned by the global settings read', async () => {
		stubFetch(JSON.stringify({ settings: { api_token: 'fk_live_deadbeef', domain: 'x' } }))
		const { text } = await resultText('fluentcart_integration_get_global_settings', {
			settings_key: 'fakturownia',
		})

		expect(text).not.toContain('fk_live_deadbeef')
	})

	it('redacts credentials returned through a custom upstream handler', async () => {
		stubFetch(
			JSON.stringify({
				name: 'order_receipt',
				pdf_settings: { api_token: 'pdf_live_deadbeef', heading: 'Receipt' },
			}),
		)
		const { text } = await resultText('fluentcart_pdf_template_get', {
			template: 'order_receipt',
		})

		expect(text).not.toContain('pdf_live_deadbeef')
		expect(text).toContain('[REDACTED]')
	})

	// The asymmetry itself, isolated: same secret, same route, redacted only because it failed.
	it('redacts the same secret when the store returns it inside an error', async () => {
		stubFetch(JSON.stringify({ message: 'bad config', secret_key: 'sk_live_51ABCDEFabcdef' }), 500)
		const { text } = await resultText('fluentcart_payment_get_settings', { method: 'stripe' })

		expect(text).not.toContain('sk_live_51ABCDEFabcdef')
		expect(text).toContain('[REDACTED]')
	})

	it('redacts a Basic credential echoed back in an upstream error message', async () => {
		stubFetch(
			JSON.stringify({ message: 'rejected header Authorization: Basic YWRtaW46aHVudGVyMg==' }),
			403,
		)
		const { text } = await resultText('fluentcart_order_list')

		expect(text).not.toContain('YWRtaW46aHVudGVyMg==')
		expect(text).toContain('[REDACTED]')
	})
})

/**
 * DEFECT — a `null` JSON body reaches a response transform and fails as a runtime TypeError.
 *
 * `parseSuccessBody` treats `null` as a valid parse (it is), so `config.transform` receives it and
 * `fluentcart_order_get` destructures it. `formatError` catches the TypeError, but a non-API error
 * is written as a bare `Error: <message>` with no code — so the caller is told "Cannot destructure
 * property 'activities' of 'order' as it is null", which names this server's internals and gives
 * an agent nothing to act on.
 *
 * The assertion is on the shape every failure a caller sees should have: a code it can branch on.
 */
describe('degenerate response bodies', () => {
	it('treats a null body as an empty result rather than crashing on it', async () => {
		// `null` is valid JSON, so it parsed and reached the response transforms, and the first one
		// to destructure it failed with "Cannot destructure property 'activities' of 'order' as it
		// is null" — a bare TypeError, no error code, naming this server's internals.
		//
		// The fix normalises it at the parse boundary, where an empty body was already becoming
		// `{}`. So the outcome is better than the coded error this test originally asked for: there
		// is nothing to report, and the caller gets an empty object it can read as "no record".
		stubFetch('null')
		const { text, isError } = await resultText('fluentcart_order_get', { order_id: 1 })

		expect(isError).toBeUndefined()
		expect(text).not.toMatch(/Cannot destructure|TypeError/)
	})

	it('still reports a genuine internal failure with a code', async () => {
		// Normalising null must not swallow real errors: an unparseable body is still a coded
		// CONNECTION_ERROR, so "no code" cannot become the house style.
		stubFetch('not json at all', 200)
		const { text, isError } = await resultText('fluentcart_order_list')

		expect(isError).toBe(true)
		expect(text).toMatch(/^Error \[[A-Z_]+\]/)
	})

	// Everything else in this family already behaves. Recorded so nobody re-probes it.
	it('turns an empty body into an empty object rather than a parse failure', async () => {
		stubFetch('')
		const { text, isError } = await resultText('fluentcart_order_list')

		expect(isError).toBeUndefined()
		expect(text).toBe('{}')
	})

	it('refuses an HTML login page instead of reporting it as data', async () => {
		stubFetch('<!DOCTYPE html><html><body>Please log in</body></html>')
		const { text, isError } = await resultText('fluentcart_order_list')

		expect(isError).toBe(true)
		expect(text).toContain('Received HTML instead of JSON')
	})

	it('refuses an oversized array instead of silently shortening it', async () => {
		const rows = Array.from({ length: 5000 }, (_, index) => ({
			id: index,
			title: 'a padded row title that makes this payload large',
		}))
		stubFetch(JSON.stringify({ data: rows }))
		const { text, isError } = await resultText('fluentcart_settings_get_modules')

		expect(isError).toBe(true)
		expect(text).toContain('RESPONSE_TOO_LARGE')
	})

	it('survives a deeply nested body without throwing out of the handler', async () => {
		stubFetch(`{"a":${'['.repeat(2000)}${']'.repeat(2000)}}`)
		const result = await toolNamed('fluentcart_order_list').handler({})

		expect(result.content[0]?.type).toBe('text')
	})
})
