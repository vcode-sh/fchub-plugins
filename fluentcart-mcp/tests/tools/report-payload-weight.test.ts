// A report about money should not spend half its budget on image links.
//
// `report_top_sold_variants` and `report_top_sold_products` are the two tools an agent reaches for
// when asked which colour, size or product is selling. Both returned an image URL on every row:
// measured on the seeded store, 34% of one payload and 43% of the other, for links nobody asked
// for and no agent can act on. The contract-backed `report_top_products` never had the problem,
// because its allowlist projection returns only the fields it names.
//
// Stripping them cut the two payloads from 2,243 to 1,334 and from 2,273 to 1,158 characters —
// 41% and 49% — with every figure unchanged.
import { describe, expect, it, vi } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import { createAllTools } from '../../src/tools/index.js'
import { dropImageUrls } from '../../src/tools/reports-insights.js'

function clientReturning(data: unknown) {
	return { get: vi.fn().mockResolvedValue({ data, status: 200 }) } as unknown as FluentCartClient
}

async function call(client: FluentCartClient, name: string) {
	const tool = createAllTools(client, {}).find((candidate) => candidate.name === name)
	if (!tool) throw new Error(`${name} is not registered`)
	const result = (await tool.handler({} as never, {} as never)) as { content: { text: string }[] }
	return result.content[0]?.text ?? ''
}

describe('sales reports drop image links', () => {
	it('removes media_url from variant rows and keeps every figure', async () => {
		const text = await call(
			clientReturning({
				topSoldVariants: [
					{
						product_id: 28,
						product_name: "Basic Men's T-Shirt",
						variation_name: 'Forest Green',
						quantity: 12,
						total_amount: 96,
						media_url: 'https://example.invalid/a-very-long-image-path/shirt-forest-green.jpeg',
					},
				],
			}),
			'fluentcart_report_top_sold_variants',
		)

		expect(text).not.toContain('media_url')
		expect(text).not.toContain('example.invalid')
		// The answer itself must survive intact.
		expect(text).toContain('Forest Green')
		expect(text).toContain('12')
		expect(text).toContain('96')
	})

	it('removes media from product rows', async () => {
		const text = await call(
			clientReturning({
				topSoldProducts: [
					{
						product_id: 28,
						product_name: "Basic Men's T-Shirt",
						quantity_sold: 12,
						total_amount: 96,
						media: 'https://example.invalid/shirt.jpeg',
					},
				],
			}),
			'fluentcart_report_top_sold_products',
		)

		expect(text).not.toContain('media')
		expect(text).toContain('96')
	})
})

describe('the transform is conservative', () => {
	const strip = dropImageUrls('rows')

	it('leaves a payload without the collection untouched', () => {
		const input = { other: [{ media: 'x' }] }
		expect(strip(input)).toEqual(input)
	})

	it('leaves a non-array collection untouched', () => {
		const input = { rows: { media: 'x' } }
		expect(strip(input)).toEqual(input)
	})

	it('preserves sibling keys on the envelope', () => {
		const result = strip({ rows: [{ a: 1, media: 'x' }], total: 7 }) as Record<string, unknown>
		expect(result.total).toBe(7)
		expect(result.rows).toEqual([{ a: 1 }])
	})

	it('passes through rows that are not objects', () => {
		expect(strip({ rows: [1, null, 'two'] })).toEqual({ rows: [1, null, 'two'] })
	})

	it('drops every image-shaped key, not just one', () => {
		const result = strip({
			rows: [{ keep: 1, media: 'a', media_url: 'b', thumbnail: 'c' }],
		}) as { rows: Record<string, unknown>[] }
		expect(result.rows[0]).toEqual({ keep: 1 })
	})
})
