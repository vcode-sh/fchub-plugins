import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it, vi } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import type { ToolDefinition } from '../../src/tools/_factory.js'
import { createAllTools } from '../../src/tools/index.js'
import { pdfTemplateTools } from '../../src/tools/pdf-templates.js'

const packageRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..')
const fixture = JSON.parse(
	readFileSync(
		resolve(packageRoot, 'tests/fixtures/routes/fluentcart-1.5.5-core-pro-1.5.4.json'),
		'utf8',
	),
)
const SERVED = new Set(
	fixture.operations.map((o: { method: string; path: string }) => `${o.method} ${o.path}`),
)

const client = {} as FluentCartClient
const all: ToolDefinition[] = createAllTools(client)
const byName = new Map(all.map((tool) => [tool.name, tool]))

/** Every read accepted at the plan 06 Task 2 evidence checkpoint, with the route it claims. */
const ACCEPTED: readonly { tool: string; route: string }[] = [
	{ tool: 'fluentcart_email_digest_settings', route: 'GET /email-notification/digest-settings' },
	{ tool: 'fluentcart_email_reminder_settings', route: 'GET /email-notification/reminders' },
	{ tool: 'fluentcart_attribute_group_library', route: 'GET /options/attr/groups/library' },
	{ tool: 'fluentcart_saved_view_list', route: 'GET /saved-views' },
	{ tool: 'fluentcart_pdf_template_status', route: 'GET /settings/pdf-templates/status' },
	{ tool: 'fluentcart_pdf_template_list', route: 'GET /settings/pdf-templates/receipt' },
	{ tool: 'fluentcart_pdf_template_get', route: 'GET /settings/pdf-templates/receipt/{param}' },
	{
		tool: 'fluentcart_pdf_seller_details_status',
		route: 'GET /settings/pdf-templates/seller-details',
	},
	{ tool: 'fluentcart_product_bulk_edit_data', route: 'GET /products/bulk-edit-data' },
	{ tool: 'fluentcart_shipping_package_list', route: 'GET /shipping/packages' },
	{
		tool: 'fluentcart_shipping_class_profile',
		route: 'GET /shipping/classes/{param}/profile',
	},
	{ tool: 'fluentcart_shipping_zone_countries', route: 'GET /shipping/zone/countries' },
	{ tool: 'fluentcart_tax_product_overrides', route: 'GET /tax/product-overrides/{param}' },
	{
		tool: 'fluentcart_tax_eu_product_overrides',
		route: 'GET /tax/configuration/settings/eu-vat/product-overrides',
	},
]

/**
 * Rejected at the same checkpoint, with the reason. Asserted so a later executor cannot promote
 * one by taste: adding a tool for any of these fails here until the reason is answered.
 */
const REJECTED: readonly { route: string; reason: RegExp }[] = []

describe('accepted reads', () => {
	it.each(ACCEPTED)('$tool is registered', ({ tool }) => {
		expect(byName.has(tool)).toBe(true)
	})

	it.each(ACCEPTED)('$tool declares $route', ({ tool, route }) => {
		const declared = byName.get(tool)?.routes?.variants.map((v) => `${v.method} ${v.path}`) ?? []
		expect(declared).toContain(route)
	})

	it.each(ACCEPTED)('$tool claims only routes this store serves', ({ tool }) => {
		for (const variant of byName.get(tool)?.routes?.variants ?? []) {
			expect(SERVED.has(`${variant.method} ${variant.path}`)).toBe(true)
		}
	})

	it.each(ACCEPTED)('$tool is classified as a read', ({ tool }) => {
		expect(byName.get(tool)?.annotations.readOnlyHint).toBe(true)
		expect(byName.get(tool)?.safety.risk).toBe('read')
	})

	it.each(ACCEPTED)('$tool describes what the caller gets', ({ tool }) => {
		expect((byName.get(tool)?.description ?? '').length).toBeGreaterThan(80)
	})
})

describe('rejected reads stay excluded', () => {
	it.each(REJECTED)('no tool claims $route', ({ route }) => {
		for (const tool of all) {
			const declared = tool.routes?.variants.map((v) => `${v.method} ${v.path}`) ?? []
			expect(declared, `${tool.name} claims a rejected route`).not.toContain(route)
		}
	})

	it('records a reason for every rejection', () => {
		for (const { route, reason } of REJECTED) {
			expect(route, `${route} has no recorded reason`).toBeTruthy()
			expect(reason).toBeInstanceOf(RegExp)
		}
	})
})

describe('saved view input contract', () => {
	const listTool = () => byName.get('fluentcart_saved_view_list')

	it('requires object_type, because omitting it returns 403 rather than 422', () => {
		expect(listTool()?.schema.safeParse({}).success).toBe(false)
		expect(listTool()?.schema.safeParse({ object_type: 'order_table' }).success).toBe(true)
	})

	it('rejects a table the permission map does not know', () => {
		expect(listTool()?.schema.safeParse({ object_type: 'invoices' }).success).toBe(false)
	})
})

describe('pdf template input contract', () => {
	it('offers only the three evidenced template sources', () => {
		const tool = byName.get('fluentcart_pdf_template_list')
		for (const source of ['active', 'saved', 'factory-default']) {
			expect(tool?.schema.safeParse({ source }).success).toBe(true)
		}
		expect(tool?.schema.safeParse({ source: 'draft' }).success).toBe(false)
	})

	it('defaults to the active set when no source is named', () => {
		expect(byName.get('fluentcart_pdf_template_list')?.schema.safeParse({}).success).toBe(true)
	})

	it('requires a template name for the single-template read', () => {
		const tool = byName.get('fluentcart_pdf_template_get')
		expect(tool?.schema.safeParse({}).success).toBe(false)
		expect(tool?.schema.safeParse({ template: 'order_receipt' }).success).toBe(true)
	})

	it('keeps a template name inside one encoded route segment', async () => {
		const get = vi.fn().mockResolvedValue({ data: { name: 'escaped' }, status: 200 })
		const tools = pdfTemplateTools({ get } as unknown as FluentCartClient)
		const tool = tools.find((entry) => entry.name === 'fluentcart_pdf_template_get')

		await tool?.handler({ template: '../orders?status=paid#summary' })

		expect(get).toHaveBeenCalledWith(
			'/settings/pdf-templates/receipt/..%2Forders%3Fstatus%3Dpaid%23summary',
		)
	})

	it('rejects dot segments instead of sending them upstream', async () => {
		const get = vi.fn()
		const tools = pdfTemplateTools({ get } as unknown as FluentCartClient)
		const tool = tools.find((entry) => entry.name === 'fluentcart_pdf_template_get')

		const result = await tool?.handler({ template: '..' })

		expect(get).not.toHaveBeenCalled()
		expect(result?.isError).toBe(true)
		expect(result?.content[0]?.text).toContain('single record')
	})
})

describe('no new tool enters curated mode', () => {
	it('leaves every accepted read dynamic-only', () => {
		// Curated membership is decided in plan 06 Task 5, not here. A read that arrives in the
		// curated set without that review has skipped the budget it was supposed to be measured
		// against.
		const curated = readFileSync(resolve(packageRoot, 'src/tools/curated.ts'), 'utf8')
		for (const { tool } of ACCEPTED) {
			expect(curated, `${tool} must not be curated in this task`).not.toContain(tool)
		}
	})
})
