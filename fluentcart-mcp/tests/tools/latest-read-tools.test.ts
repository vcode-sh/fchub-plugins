import { describe, expect, it, vi } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import { createAllTools } from '../../src/tools/index.js'

function registry(payloadFor: (path: string) => unknown) {
	const get = vi.fn(async (path: string) => ({ data: payloadFor(path), status: 200 }))
	const tools = createAllTools({ get } as unknown as FluentCartClient)
	return { get, byName: new Map(tools.map((tool) => [tool.name, tool])) }
}

describe('current FluentCart read projections', () => {
	it('returns compact bulk-edit rows without HTML or media payloads', async () => {
		const { byName } = registry(() => ({
			products: [
				{
					ID: 42,
					post_title: 'Green Shirt',
					post_content: '<p>large editor body</p>',
					post_excerpt: 'Short',
					post_status: 'publish',
					view_url: 'https://store.example/product/green-shirt',
					gallery: [{ id: 99, url: 'https://cdn.example/private.jpg' }],
					detail: { variation_type: 'simple', fulfillment_type: 'physical', manage_stock: 1 },
					variants: [
						{
							id: 7,
							variation_title: 'Green',
							sku: 'GREEN-1',
							item_price: 12.5,
							compare_price: 15,
							manage_stock: 1,
							total_stock: 5,
							available: 4,
							stock_status: 'in-stock',
							media: [{ url: 'https://cdn.example/variant.jpg' }],
							other_info: { internal: true },
						},
					],
					category_terms: [{ term_id: 3, name: 'Shirts', slug: 'shirts', parent: 0 }],
					categories: ['Clothing > Shirts'],
				},
			],
			total: 1,
			per_page: 10,
			page: 1,
		}))
		const result = await byName.get('fluentcart_product_bulk_edit_data')?.handler({})
		const text = result?.content[0]?.text ?? ''

		expect(text).toContain('Green Shirt')
		expect(text).toContain('GREEN-1')
		expect(text).not.toContain('large editor body')
		expect(text).not.toContain('cdn.example')
		expect(text).not.toContain('internal')
	})

	it('reports seller-detail readiness without returning identity, contact, tax, or bank values', async () => {
		const { byName } = registry(() => ({
			seller_details: {
				zugferd_enabled: '1',
				zugferd_profile: 'en16931',
				seller_contact_name: 'Private Person',
				seller_contact_email: 'owner@example.com',
				seller_contact_phone: '+48123123123',
				seller_bank_iban: 'PL001234567890',
				seller_bank_bic: 'BANKPLPW',
				seller_vat_id: 'PL1234567890',
				seller_tax_id: '1234567890',
				seller_legal_name: 'Private Company',
			},
			store_country_set: true,
			store_settings_url: 'https://store.example/wp-admin/private',
		}))
		const result = await byName.get('fluentcart_pdf_seller_details_status')?.handler({})
		const text = result?.content[0]?.text ?? ''
		const body = JSON.parse(text)

		expect(body).toMatchObject({
			e_invoice_enabled: true,
			e_invoice_profile: 'en16931',
			store_country_configured: true,
			configured: {
				contact_name: true,
				contact_email: true,
				bank_iban: true,
				vat_id: true,
			},
		})
		expect(body).not.toHaveProperty('profile')
		for (const secret of [
			'Private Person',
			'owner@example.com',
			'+48123123123',
			'PL001234567890',
			'BANKPLPW',
			'PL1234567890',
			'Private Company',
			'/wp-admin/',
		]) {
			expect(text).not.toContain(secret)
		}
	})

	it('projects a shipping class with its zones and methods', async () => {
		const { byName } = registry(() => ({
			shipping_class: {
				id: 4,
				name: 'Bulky',
				description: 'Large parcels',
				cost: 500,
				type: 'fixed',
				created_at: 'internal timestamp',
				zones: [
					{
						id: 8,
						name: 'Poland',
						region: 'PL',
						order: '1',
						created_at: 'internal timestamp',
						methods: [
							{ id: 9, title: 'Courier', type: 'flat_rate', amount: 1200, is_enabled: true },
						],
					},
				],
			},
		}))
		const result = await byName.get('fluentcart_shipping_class_profile')?.handler({ class_id: 4 })
		const text = result?.content[0]?.text ?? ''

		expect(JSON.parse(text)).toEqual({
			shipping_class: {
				id: 4,
				name: 'Bulky',
				description: 'Large parcels',
				cost: 500,
				type: 'fixed',
				zones: [
					{
						id: 8,
						name: 'Poland',
						region: 'PL',
						order: '1',
						methods: [{ id: 9, title: 'Courier', type: 'flat_rate', amount: 1200, enabled: true }],
					},
				],
			},
		})
		expect(text).not.toContain('internal timestamp')
	})

	it('normalises a country code and projects product-category tax overrides', async () => {
		const { get, byName } = registry(() => ({
			overrides: [
				{
					id: 5,
					object_id: 3,
					object_type: 'tax_override',
					meta_key: 'product_category_override',
					meta_value: {
						country: 'PL',
						state: '',
						city: '',
						postcode: '',
						category_id: 3,
						category_name: 'Books',
						tax_label: 'VAT',
						override_state_tax: 'no',
						rate: 5,
						class_id: 2,
					},
					class_id: 2,
					class_label: 'Reduced',
					created_at: 'internal timestamp',
				},
			],
		}))
		const result = await byName
			.get('fluentcart_tax_product_overrides')
			?.handler({ country_code: 'pl' })
		const text = result?.content[0]?.text ?? ''

		expect(get).toHaveBeenCalledWith('/tax/product-overrides/PL')
		expect(JSON.parse(text)).toEqual({
			country_code: 'PL',
			overrides: [
				{
					id: 5,
					category_id: 3,
					category_name: 'Books',
					state: null,
					city: null,
					postcode: null,
					tax_label: 'VAT',
					rate: 5,
					override_state_tax: false,
					class_id: 2,
					class_label: 'Reduced',
				},
			],
			total: 1,
		})
		expect(text).not.toContain('internal timestamp')
		expect(text).not.toContain('meta_key')
	})
})
