import { z } from 'zod'
import type { FluentCartClient } from '../api/client.js'
import { FluentCartApiError } from '../api/errors.js'
import { TTL } from '../cache.js'
import { createTool, getTool, invalidateToolCache, type ToolDefinition } from './_factory.js'
import { composite, op } from './endpoints.js'

/**
 * Global tax configuration.
 *
 * The save route is three separate traps, all confirmed in TaxConfigurationController::saveSettings,
 * and every one of them fails silently with HTTP 200:
 *
 * 1. It is a wholesale replacement. The method ends in `update_option(..., $settings, true)` with
 *    no merge, so any field left out of the payload is deleted. Sending `{tax_display_label}`
 *    alone would drop `enable_tax` and stop the store charging tax.
 * 2. Omitting `settings` entirely is worse still: the controller falls back to
 *    `$this->defaultSettings()`, whose `enable_tax` is `'no'`. A malformed request does not fail,
 *    it turns tax off.
 * 3. An out-of-range enum is coerced to the first allowed value rather than rejected. A typo in
 *    `tax_inclusion` silently switches the store to `included` and reports success.
 *
 * So this tool never builds a payload from the caller's fields. It reads the current settings,
 * applies the named changes to that blob, sends the whole thing back, and reads it again to
 * confirm what actually landed. Every enum is validated by Zod first, so a bad value is refused
 * here rather than quietly changing how tax is charged.
 */

const TAX_INCLUSION = ['included', 'excluded'] as const
const CALCULATION_BASIS = ['shipping', 'billing', 'store'] as const
const ROUNDING = ['item', 'total', 'subtotal'] as const
const BREAKDOWN = ['itemized', 'simplified'] as const
const YES_NO = ['yes', 'no'] as const
const PRICE_MODE = ['fixed', 'dynamic'] as const

/** Top-level fields the tool may change, and the enum each is checked against. */
const SCALAR_FIELDS = [
	'tax_inclusion',
	'tax_calculation_basis',
	'tax_rounding',
	'checkout_tax_breakdown_display',
	'tax_display_label',
	'enable_tax',
] as const

const EU_FIELDS = [
	'require_vat_number',
	'local_reverse_charge',
	'reverse_charge_price_mode',
] as const

type Settings = Record<string, unknown>

function readSettings(payload: unknown): Settings | null {
	const record = (payload ?? {}) as Record<string, unknown>
	const settings = record.settings
	return settings && typeof settings === 'object' ? (settings as Settings) : null
}

function changed(before: Settings, after: Settings): string[] {
	const keys = new Set([...Object.keys(before), ...Object.keys(after)])
	return [...keys]
		.filter((key) => JSON.stringify(before[key]) !== JSON.stringify(after[key]))
		.sort()
}

/**
 * Apply the requested fields on top of the settings the store currently holds.
 *
 * Separated from the handler so the merge is readable on its own: the save endpoint replaces
 * the whole option, so this function is the only thing standing between a one-field request
 * and a configuration rewrite.
 */
function mergeSettings(
	before: Settings,
	change: {
		scalars: readonly (typeof SCALAR_FIELDS)[number][]
		input: Record<string, unknown>
		euChanges: readonly string[]
		euInput: Record<string, unknown>
	},
): Settings {
	const merged: Settings = { ...before }
	for (const field of change.scalars) merged[field] = change.input[field]

	if (change.euChanges.length > 0) {
		const currentEu = (before.eu_vat_settings ?? {}) as Record<string, unknown>
		const nextEu: Record<string, unknown> = { ...currentEu }
		for (const field of change.euChanges) nextEu[field] = change.euInput[field]
		merged.eu_vat_settings = nextEu
	}

	return merged
}

export function taxConfigurationTools(client: FluentCartClient): ToolDefinition[] {
	return [
		getTool(client, {
			name: 'fluentcart_tax_settings_get',
			title: 'Get Tax Settings',
			description:
				'Get global tax settings: whether tax is enabled, tax-inclusive or exclusive pricing, ' +
				'which address the calculation is based on, rounding, the customer-facing label and the ' +
				'EU VAT reverse-charge configuration.',
			schema: z.object({}),
			endpoint: '/tax/configuration/settings',
			cache: { key: 'tax_settings', ttlMs: TTL.MEDIUM },
		}),

		createTool(client, {
			name: 'fluentcart_tax_settings_save',
			title: 'Save Tax Settings',
			description:
				'Change one or more global tax settings. Name only the settings you want to change: ' +
				'the tool reads the current configuration, applies your changes to it and writes the ' +
				'whole thing back, because the store replaces its tax settings wholesale and would ' +
				'otherwise delete every field you did not send. Values are checked before the request, ' +
				'since the store silently rewrites an invalid value to its first allowed option rather ' +
				'than refusing it. The previous value of every field is returned so the change can be ' +
				'undone. One caveat: on a store that has never had tax configured, enabling tax also ' +
				'creates a "Standard" tax class, and putting the settings back does not remove it.',
			schema: z.object({
				tax_inclusion: z
					.enum(TAX_INCLUSION)
					.optional()
					.describe('Whether catalogue prices already include tax: included, excluded'),
				tax_calculation_basis: z
					.enum(CALCULATION_BASIS)
					.optional()
					.describe('Which address decides the rate: shipping, billing, store'),
				tax_rounding: z
					.enum(ROUNDING)
					.optional()
					.describe('Where rounding is applied: item, total, subtotal'),
				checkout_tax_breakdown_display: z
					.enum(BREAKDOWN)
					.optional()
					.describe('How tax is shown at checkout: itemized, simplified'),
				tax_display_label: z
					.string()
					.min(1)
					.optional()
					.describe('Customer-facing label for tax, e.g. "Tax" or "VAT"'),
				enable_tax: z
					.enum(YES_NO)
					.optional()
					.describe('Whether the store charges tax at all: yes, no. Changes what customers pay.'),
				eu_vat_settings: z
					.object({
						require_vat_number: z.enum(YES_NO).optional().describe('Require a VAT number: yes, no'),
						local_reverse_charge: z
							.enum(YES_NO)
							.optional()
							.describe('Apply local reverse charge: yes, no'),
						reverse_charge_price_mode: z
							.enum(PRICE_MODE)
							.optional()
							.describe('Reverse-charge price handling: fixed, dynamic'),
					})
					.optional()
					.describe('EU VAT reverse-charge settings; unnamed fields are left as they are'),
			}),
			annotations: { openWorldHint: true, idempotentHint: true },
			routes: composite(
				op('GET', '/tax/configuration/settings'),
				op('POST', '/tax/configuration/settings'),
			),
			handler: async (c, input) => {
				const scalars = SCALAR_FIELDS.filter((field) => input[field] !== undefined)
				const euInput = (input.eu_vat_settings ?? {}) as Record<string, unknown>
				const euChanges = EU_FIELDS.filter((field) => euInput[field] !== undefined)

				if (scalars.length === 0 && euChanges.length === 0) {
					throw new FluentCartApiError(
						'VALIDATION_ERROR',
						'Validation error: name at least one setting to change. Sending nothing would rewrite ' +
							'the store’s tax configuration with defaults, which disables tax.',
						422,
					)
				}

				// Read first, always. Writing without the current blob in hand cannot be made safe:
				// the payload IS the new configuration, so a failed read must abort the write.
				const before = readSettings((await c.get('/tax/configuration/settings')).data)
				if (!before) {
					throw new FluentCartApiError(
						'SERVER_ERROR',
						'Could not read the current tax settings, so there is nothing safe to merge into. ' +
							'Refusing to write, because a partial payload would replace the whole configuration.',
					)
				}

				const merged = mergeSettings(before, { scalars, input, euChanges, euInput })

				await c.post('/tax/configuration/settings', { settings: merged })
				invalidateToolCache(c, 'tax_settings')

				const after = readSettings((await c.get('/tax/configuration/settings')).data)
				if (!after) {
					throw new FluentCartApiError(
						'SERVER_ERROR',
						'The save was accepted but the settings could not be read back, so the result is unverified.',
					)
				}

				const applied = Object.fromEntries(
					scalars.map((field) => [field, { from: before[field], to: after[field] }]),
				)
				const unexpected = changed(before, after).filter(
					(key) =>
						!scalars.includes(key as (typeof SCALAR_FIELDS)[number]) && key !== 'eu_vat_settings',
				)

				const warnings: string[] = []
				for (const field of scalars) {
					if (JSON.stringify(after[field]) !== JSON.stringify(input[field])) {
						warnings.push(
							`${field} was saved as ${JSON.stringify(after[field])} rather than the requested ${JSON.stringify(input[field])}; the store rewrote it.`,
						)
					}
				}
				if (unexpected.length > 0) {
					warnings.push(`Fields changed that were not requested: ${unexpected.join(', ')}.`)
				}
				if (input.enable_tax === 'yes' && before.enable_tax !== 'yes') {
					warnings.push(
						'Enabling tax on a store that was never configured also creates a "Standard" tax class. ' +
							'Restoring these settings does not remove it.',
					)
				}

				return {
					applied,
					eu_vat_settings:
						euChanges.length > 0
							? { from: before.eu_vat_settings, to: after.eu_vat_settings }
							: undefined,
					previous_settings: before,
					warnings,
					reversal:
						'Call this tool again with the values under `previous_settings` to restore the prior configuration.',
				}
			},
		}),
	]
}
