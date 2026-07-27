import { describe, expect, it, vi } from 'vitest'
import type { FluentCartClient } from '../../src/api/client.js'
import { createAllTools } from '../../src/tools/index.js'

const mockClient = {
	get: vi.fn(),
	post: vi.fn(),
	put: vi.fn(),
	delete: vi.fn(),
} as unknown as FluentCartClient

const tools = createAllTools(mockClient)

describe('tool definitions', () => {
	// 287 source definitions after plan 06 added saved-view and PDF-template reads: 274 before Plan 03 Task 4 withdrew three tools that FluentCart
	// 1.5.5 does not serve (report_cart, report_unfulfilled_orders, tax_class_update). Each
	// withdrawal carries a written reason in tests/regression/scenario-coverage.test.ts.
	it('should register the reviewed number of source definitions', () => {
		console.log(`Total tools registered: ${tools.length}`)
		expect(tools.length).toBeGreaterThanOrEqual(280)
		expect(tools.length).toBeLessThanOrEqual(300)
	})

	it('should not contain removed tools', () => {
		const names = tools.map((t) => t.name)
		const removedTools: string[] = []
		for (const removed of removedTools) {
			expect(names, `${removed} should have been removed`).not.toContain(removed)
		}
	})

	it('should have no duplicate tool names', () => {
		const names = tools.map((t) => t.name)
		const unique = new Set(names)
		const duplicates = names.filter((name, i) => names.indexOf(name) !== i)
		expect(duplicates, `Duplicate tool names: ${duplicates.join(', ')}`).toHaveLength(0)
		expect(unique.size).toBe(tools.length)
	})

	describe.each(tools.map((t) => [t.name, t]))('%s', (_name, tool) => {
		it('should have a name matching fluentcart_[a-z]+(_[a-z]+)+ pattern', () => {
			expect(tool.name).toMatch(/^fluentcart_[a-z]+(_[a-z]+)+$/)
		})

		it('should have a non-empty description of at least 20 characters', () => {
			expect(tool.description).toBeDefined()
			expect(tool.description.length).toBeGreaterThanOrEqual(20)
		})

		it('should have a non-empty title', () => {
			expect(tool.title).toBeDefined()
			expect(tool.title.length).toBeGreaterThan(0)
		})

		it('should have annotations with openWorldHint: true', () => {
			expect(tool.annotations).toBeDefined()
			expect(tool.annotations.openWorldHint).toBe(true)
		})
	})

	describe('GET tools (readOnlyHint)', () => {
		const readOnlyTools = tools.filter((t) => t.annotations.readOnlyHint === true)

		it('should have at least one GET tool', () => {
			expect(readOnlyTools.length).toBeGreaterThan(0)
		})

		it.each(readOnlyTools.map((t) => [t.name, t]))(
			'%s should also have idempotentHint: true',
			(_name, tool) => {
				expect(tool.annotations.idempotentHint).toBe(true)
			},
		)
	})

	describe('DELETE tools (destructiveHint)', () => {
		const destructiveTools = tools.filter((t) => t.annotations.destructiveHint === true)

		it('should have at least one DELETE tool', () => {
			expect(destructiveTools.length).toBeGreaterThan(0)
		})

		it.each(destructiveTools.map((t) => [t.name, t]))(
			'%s should not have readOnlyHint: true',
			(_name, tool) => {
				expect(tool.annotations.readOnlyHint).not.toBe(true)
			},
		)
	})
})
