// The pagination envelope is stripped; the pagination facts are not.
//
// FluentCart serves Laravel's LengthAwarePaginator on every paginated route, and half of it
// addresses pages by URL: `links[]` (one object per page, each with url/label/active), the four
// *_page_url keys, and `path`. Measured live on the development store:
//
//   order_list          {per_page: 1}  2,086 chars | envelope 1,708 = 82%
//   role_user_list      {}               612 chars | envelope   514 = 84%
//   shipping_class_list {}               627 chars | envelope   518 = 83%
//   coupon_list         {}               749 chars | envelope   482 = 64%
//
// It is worst exactly when it should be cheapest: `links[]` grows as pages multiply, so asking
// for a SMALLER per_page costs MORE tokens. `path` additionally published the store's REST URL
// in every list response. An agent pages by number through the tool's own schema and never
// dereferences any of it.
import { describe, expect, it, vi } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import { createAllTools } from '../../src/tools/index.js'

const STORE_URL = 'https://store.example/wp-json/fluent-cart/v2/orders'

/** The paginator shape as FluentCart actually serves it, verified against eleven live routes. */
function paginator(rows: unknown[], pages: number): Record<string, unknown> {
	return {
		current_page: 1,
		data: rows,
		first_page_url: `${STORE_URL}/?page=1`,
		from: 1,
		last_page: pages,
		last_page_url: `${STORE_URL}/?page=${pages}`,
		links: Array.from({ length: pages + 2 }, (_, index) => ({
			url: `${STORE_URL}/?page=${index}`,
			label: String(index),
			active: index === 1,
		})),
		next_page_url: `${STORE_URL}/?page=2`,
		path: STORE_URL,
		per_page: 1,
		prev_page_url: null,
		to: 1,
		total: pages,
	}
}

const LINK_KEYS = [
	'links',
	'first_page_url',
	'last_page_url',
	'next_page_url',
	'prev_page_url',
	'path',
]
const KEPT_KEYS = ['current_page', 'last_page', 'per_page', 'total', 'from', 'to']

async function call(name: string, payload: unknown, input: Record<string, unknown> = {}) {
	const request = vi.fn().mockResolvedValue({ data: payload, status: 200 })
	const client = {
		get: request,
		post: request,
		put: request,
		delete: request,
	} as unknown as FluentCartClient
	const tool = createAllTools(client, {}).find((candidate) => candidate.name === name)
	if (!tool) throw new Error(`${name} is not registered`)
	const result = await tool.handler(input)
	const text = result.content[0]?.text ?? ''
	return { text, parsed: JSON.parse(text) as Record<string, unknown>, isError: result.isError }
}

describe('the URL half of the envelope is removed', () => {
	it('drops all six keys from a wrapped paginator', async () => {
		const { parsed } = await call('fluentcart_order_list', {
			orders: paginator([{ id: 1, status: 'completed' }], 34),
		})

		const orders = parsed.orders as Record<string, unknown>
		for (const key of LINK_KEYS) expect(orders).not.toHaveProperty(key)
	})

	it('keeps every pagination fact a caller pages on', async () => {
		const { parsed } = await call('fluentcart_order_list', {
			orders: paginator([{ id: 1, status: 'completed' }], 34),
		})

		const orders = parsed.orders as Record<string, unknown>
		for (const key of KEPT_KEYS) expect(orders).toHaveProperty(key)
		expect(orders.current_page).toBe(1)
		expect(orders.total).toBe(34)
		expect(orders.last_page).toBe(34)
		expect(Array.isArray(orders.data)).toBe(true)
		expect((orders.data as unknown[])[0]).toMatchObject({ id: 1 })
	})

	it('stops publishing the store REST URL in a list response', async () => {
		const { text } = await call('fluentcart_order_list', {
			orders: paginator([{ id: 1 }], 34),
		})

		expect(text).not.toContain('wp-json')
		expect(text).not.toContain('store.example')
	})

	it('handles a paginator at the top level as well as under a wrapper', async () => {
		// subscription_list serves `{data: {current_page, data: [...], links, ...}}`.
		const { parsed } = await call('fluentcart_subscription_list', {
			data: paginator([{ id: 7 }], 12),
		})

		const inner = parsed.data as Record<string, unknown>
		for (const key of LINK_KEYS) expect(inner).not.toHaveProperty(key)
		expect(inner.total).toBe(12)
	})

	it('recovers the measured share of a small page', async () => {
		// The per_page 1 case, where the envelope was 82% of the payload.
		const before = JSON.stringify({ orders: paginator([{ id: 1, status: 'completed' }], 34) })
		const { text } = await call('fluentcart_order_list', {
			orders: paginator([{ id: 1, status: 'completed' }], 34),
		})

		expect(text.length).toBeLessThan(before.length / 3)
	})
})

describe('nothing that merely resembles an envelope is touched', () => {
	it('leaves path and links on rows that are not paginators', async () => {
		// `path` and `links` are ordinary words. A file row owns its path, and deleting it by name
		// would destroy the answer rather than shrink it.
		const { parsed } = await call('fluentcart_file_list', {
			files: [
				{ id: 1, path: '/uploads/receipt.pdf', links: ['self'] },
				{ id: 2, path: '/uploads/invoice.pdf', links: [] },
			],
		})

		const files = parsed.files as Record<string, unknown>[]
		expect(files[0]?.path).toBe('/uploads/receipt.pdf')
		expect(files[0]?.links).toEqual(['self'])
	})

	it('leaves row-level path intact inside a paginator it is stripping', async () => {
		// shipping_class_list projects nothing, so whatever survives here survived this strip
		// rather than somebody else's field allowlist.
		const { parsed } = await call('fluentcart_shipping_class_list', {
			shipping_classes: paginator([{ id: 1, path: '/uploads/class-1.pdf' }], 3),
		})

		const classes = parsed.shipping_classes as Record<string, unknown>
		expect(classes).not.toHaveProperty('path')
		expect((classes.data as Record<string, unknown>[])[0]?.path).toBe('/uploads/class-1.pdf')
	})

	it('leaves an object with paginator keys but no data array alone', async () => {
		const { parsed } = await call('fluentcart_settings_get_modules', {
			current_page: 1,
			path: '/settings/modules',
			links: ['a'],
		})

		expect(parsed.path).toBe('/settings/modules')
		expect(parsed.links).toEqual(['a'])
	})

	it('returns a payload with no envelope unchanged', async () => {
		const payload = { modules: { turnstile: { enabled: 'yes' } }, total: 1 }
		const { parsed } = await call('fluentcart_settings_get_modules', payload)

		expect(parsed).toEqual(payload)
	})

	it('survives a deeply nested body without recursing without bound', async () => {
		const deep = JSON.parse(`{"a":${'['.repeat(2_000)}${']'.repeat(2_000)}}`) as unknown
		const { isError } = await call('fluentcart_settings_get_modules', deep)

		expect(isError).toBeUndefined()
	})
})
