// Which budget binds on which tool, and why it is not one number for everything.
//
// Before this, `assertResponseBudget` (24,000 characters) was reached from exactly two places —
// `commerce/search.ts` and `commerce/reference-data.ts` — and every other read was bounded only
// by the 40,000-character emergency cap. Measured on the development store,
// `fluentcart_customer_list` at per_page 100 returned 24,749 characters and nothing objected.
//
// Applying 24,000 everywhere was rejected on evidence from that same store: the reads that sit
// between the two limits are the ones with nothing to page. `fluentcart_settings_print_templates_get`
// with `include_content` returns 38,669 characters and has no per_page to shrink, so a uniform
// budget would have replaced a working tool with an error whose only advice cannot be followed.
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import { clearCache } from '../../src/cache.js'
import {
	DEFAULT_RESPONSE_BUDGET,
	EMERGENCY_RESPONSE_CAP,
} from '../../src/commerce/response-budget.js'
import { createAllTools } from '../../src/tools/index.js'

/** A payload of a known serialised size that no projection strips. */
function payloadOfSize(characters: number): Record<string, unknown> {
	const envelope = { note: '' }
	const overhead = JSON.stringify(envelope).length
	return { note: 'x'.repeat(Math.max(0, characters - overhead)) }
}

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
	return { text: result.content[0]?.text ?? '', isError: result.isError === true }
}

/** Present in the schema means the caller can act on "ask for fewer rows". */
function schemaOf(name: string): Record<string, unknown> {
	const client = {} as unknown as FluentCartClient
	const tool = createAllTools(client, {}).find((candidate) => candidate.name === name)
	if (!tool) throw new Error(`${name} is not registered`)
	return tool.schema.shape as Record<string, unknown>
}

beforeEach(() => {
	clearCache()
	vi.restoreAllMocks()
})

describe('a pageable read is held to the default budget', () => {
	it('names customer_list as pageable, which is what the budget keys on', () => {
		expect(Object.hasOwn(schemaOf('fluentcart_customer_list'), 'per_page')).toBe(true)
	})

	it('refuses the measured 24,749-character page instead of returning it', async () => {
		const { text, isError } = await call('fluentcart_customer_list', payloadOfSize(24_749), {
			per_page: 100,
		})

		expect(isError).toBe(true)
		expect(text).toContain('RESPONSE_TOO_LARGE')
		expect(text).toContain('24749 characters')
		expect(text).toContain(`over the ${DEFAULT_RESPONSE_BUDGET.maxCharacters} character limit`)
	})

	it('advises a smaller per_page, naming the tool, and nothing the caller cannot do', async () => {
		const { text } = await call('fluentcart_customer_list', payloadOfSize(30_000))

		expect(text).toContain('a smaller per_page for fluentcart_customer_list')
		expect(text).not.toContain('a narrower query')
	})

	it('still returns a page that fits the default budget', async () => {
		const { text, isError } = await call('fluentcart_customer_list', payloadOfSize(23_900))

		expect(isError).toBe(false)
		expect(text.length).toBeGreaterThan(23_000)
	})

	it('applies to a custom-handler tool that pages too, not only endpoint tools', async () => {
		// variant_list_all pages in this server rather than upstream; the caller still has a
		// per_page that works, so the advice is real and the tighter budget applies.
		expect(Object.hasOwn(schemaOf('fluentcart_variant_list_all'), 'per_page')).toBe(true)

		const rows = Array.from({ length: 50 }, (_, index) => ({
			id: index,
			variation_title: 'y'.repeat(600),
		}))
		const { text, isError } = await call('fluentcart_variant_list_all', rows, { per_page: 50 })

		expect(isError).toBe(true)
		expect(text).toContain('a smaller per_page for fluentcart_variant_list_all')
	})
})

describe('a read with nothing to page keeps the emergency cap', () => {
	it('names settings_get_modules as unpageable', () => {
		expect(Object.hasOwn(schemaOf('fluentcart_settings_get_modules'), 'per_page')).toBe(false)
	})

	it('returns a payload between the two limits rather than failing it', async () => {
		// The regression this guards: a uniform 24,000 budget would turn every read in this band
		// into a permanent error. On the development store that is print templates with
		// include_content at 38,669 characters, and it has no argument that could make it smaller.
		const { text, isError } = await call('fluentcart_settings_get_modules', payloadOfSize(38_669))

		expect(isError).toBe(false)
		expect(text.length).toBe(38_669)
	})

	it('still refuses a payload over the emergency cap', async () => {
		const { text, isError } = await call('fluentcart_settings_get_modules', payloadOfSize(45_000))

		expect(isError).toBe(true)
		expect(text).toContain(`over the ${EMERGENCY_RESPONSE_CAP} character limit`)
	})

	it('never advises a page size it does not have', async () => {
		const { text } = await call('fluentcart_settings_get_modules', payloadOfSize(45_000))

		expect(text).not.toContain('per_page')
		expect(text).not.toContain('page size')
		expect(text).toContain('a narrower query for fluentcart_settings_get_modules')
	})
})

describe('write responses are not held to a page budget', () => {
	it('keeps the emergency cap for a write, which has no page to shrink', async () => {
		expect(Object.hasOwn(schemaOf('fluentcart_settings_print_templates_save'), 'per_page')).toBe(
			false,
		)

		const { isError } = await call(
			'fluentcart_settings_print_templates_save',
			payloadOfSize(30_000),
			{
				templates: { a: 1 },
			},
		)

		expect(isError).toBe(false)
	})
})
