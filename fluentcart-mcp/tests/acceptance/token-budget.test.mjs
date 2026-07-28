// Definition-payload budgets, measured fresh from the built server.
//
// Every figure below is what a caller actually pays to be told this server exists, before a single
// question is asked. The numbers are pinned rather than merely bounded: a budget alone would let
// the payload creep to the ceiling unremarked, and the whole argument for dynamic mode is that the
// creep is visible.

import assert from 'node:assert/strict'
import { existsSync, readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { dirname, join, resolve } from 'node:path'
import { before, describe, it } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	DEFINITION_BUDGETS,
	measureAllModes,
	REGRESSION_BASELINES,
	SERIALIZER,
	TOKENIZER,
} from '../../scripts/measure-tool-context.mjs'

const require = createRequire(import.meta.url)
const PACKAGE_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..')
const CONTRACT_PATH = join(PACKAGE_ROOT, 'release-contract.json')

/**
 * Acceptance measurements taken on 2026-07-27 against this tree, under the measurement fixture's
 * guarded write mode with both guard prerequisites present — the widest registry any legitimate
 * configuration produces, so no mode's ceiling is understated.
 *
 * Only the three gated modes are pinned. Full mode is measured and reported but never gated, and
 * every edit to any of a couple of hundred tool descriptions moves it — pinning it would turn this
 * lane into a tripwire for ordinary editing rather than a budget gate.
 *
 * ## Re-recorded 2026-07-27, later the same day. Both moves are deliberate and cheaper.
 *
 * `dynamic` 5 tools / 863 cl100k → 4 / 688, about 20% off the definition payload. The guarded
 * executor is no longer registered unconditionally: every real-money tool ships `execution: 'none'`
 * in 2.0.0, so it could only ever answer "not exposed" while advertising `destructiveHint: true`.
 * It returns automatically the moment a guard-wired action is exposed, and the count returns with
 * it.
 *
 * `curated` 20 tools / 3,652 cl100k → 19 / 4,146. Four raw report passthroughs were replaced by the
 * three contract-backed reports. Two of the four could not answer at all — `/reports/sales-growth`
 * returns HTTP 500 and `/reports/top-products-sold` is deprecated and returns an empty list — and
 * the other two handed back unlabelled payloads with no period, currency or payment scope. One
 * fewer tool costs ~494 more tokens because the replacements carry their semantics in their
 * descriptions; that is the trade, and it is the right way round.
 */
const ACCEPTED = {
	dynamic: { toolCount: 4, cl100kTokens: 688, o200kTokens: 699 },
	// 4,146 → 4,182 on 2026-07-28: `dashboard_overview` now states that its figures cover a fixed
	// 30-day window. It read as all-time before, which is the kind of description that produces a
	// confidently wrong answer rather than a missing one. 36 tokens is a fair price for that.
	// 4,182 → 4,233 on 2026-07-28: `subscription_list` now states that recurring_amount and its
	// siblings are minor units, with the example that makes it unmissable — a EUR 999/yr plan
	// arrives as 99900. Without it an agent reports a EUR 99,900 subscription, so 51 tokens buys
	// the difference between a right answer and one wrong by two orders of magnitude.
	// 4,233 → 4,257 on 2026-07-28: `timezone` became optional on the three contract-backed
	// reports, and the schema now says what it is — a label, echoed back, affecting no figure.
	// It had been required, sourced from `fluentcart_get_store_context`, which can never succeed
	// because server.ts passes `profile: null` unconditionally. Every report therefore rested on
	// the caller guessing a value that changed nothing.
	// 4,257 → 4,279 on 2026-07-28: `variant_list_all` gained a stock filter and both variant tools
	// now say "stock" and "inventory" at all. Neither did before, so "what is low on stock" returned
	// coupon settings and a PDF template while the only tool holding stock levels was invisible.
	// `report_sales_summary` also names the fields it returns, which is what makes it findable for a
	// refund rate; its prose list of the same figures was dropped in exchange, so that half is free.
	// 4,279 → 4,379 on 2026-07-28: the two curated customer tools stopped lying about sorting and
	// about what they hold. `customer_list` had advertised `purchase_value` as the sort key for
	// finding top customers; live, that key sorts a JSON longtext column as text and returned the
	// eight customers who have spent nothing while omitting the top spender entirely, so the free
	// string became a closed enum of the columns FluentCart's `$fillable` allowlist actually
	// honours. `created_at` went with it — measured, it was indistinguishable from a misspelled
	// column. The row now carries ltv, aov and both purchase dates, so the description says they
	// are cents summed across currencies. `customer_get` says it holds the per-customer figures,
	// which is what makes it findable for a stats question now that `customer_stats` is marked
	// DIAGNOSTIC and points at it. 100 tokens for a sort key that returned the exact opposite of
	// what it promised is the cheapest thing in this file.
	// 4,379 → 4,434 on 2026-07-28: `product_search_by_name` said "Search for products by name." and
	// returned 1,064 characters per row — three fields for one image, prices repeated as HTML
	// entities, Laravel's laravel_through_key. It now names what a row carries and what to call next.
	// 55 definition tokens against 80% off every response: `name=shirt` went 2,446 → 484 characters
	// and an unfiltered page 10,604 → 2,112, on the first call of the commonest product question.
	curated: { toolCount: 19, cl100kTokens: 4434, o200kTokens: 4530 },
	code: { toolCount: 2, cl100kTokens: 532, o200kTokens: 540 },
}

/** Above this, a move is a decision that needs writing down, not a rounding error. */
const EXPLANATION_THRESHOLD = 0.2

let measurements

function measurementFor(mode) {
	const found = measurements.find((entry) => entry.mode === mode)
	assert.ok(found?.available, `${mode} mode must be measurable: ${found?.reason ?? 'not measured'}`)
	return found
}

before(async () => {
	measurements = await measureAllModes()
})

describe('measurement provenance', () => {
	it('pins the serializer and the tokenizer, so runs are comparable', () => {
		assert.equal(SERIALIZER, 'mcp-tools-list-v1')
		assert.equal(TOKENIZER, 'gpt-tokenizer@3.4.0')
		assert.equal(`gpt-tokenizer@${require('gpt-tokenizer/package.json').version}`, TOKENIZER)
	})

	it('measures all four modes', () => {
		assert.deepEqual(
			measurements.map((entry) => entry.mode),
			['dynamic', 'curated', 'code', 'full'],
		)
		for (const entry of measurements) {
			assert.ok(entry.available, `${entry.mode} must be constructible to be measured`)
		}
	})
})

describe('definition budgets', () => {
	it('gates dynamic, code and curated, and never gates full', () => {
		assert.equal(DEFINITION_BUDGETS.dynamic, 1500)
		assert.equal(DEFINITION_BUDGETS.code, 1200)
		assert.equal(DEFINITION_BUDGETS.curated, 12000)
		assert.equal(DEFINITION_BUDGETS.full, null)
	})

	for (const mode of ['dynamic', 'code', 'curated']) {
		it(`holds ${mode} within ${DEFINITION_BUDGETS[mode]} tokens in both encodings`, (t) => {
			const measurement = measurementFor(mode)
			const budget = DEFINITION_BUDGETS[mode]
			t.diagnostic(
				`${mode}: ${measurement.toolCount} tools, ${measurement.cl100kTokens} cl100k, ${measurement.o200kTokens} o200k`,
			)
			assert.ok(
				measurement.cl100kTokens <= budget,
				`${mode} cl100k ${measurement.cl100kTokens} > ${budget}`,
			)
			assert.ok(
				measurement.o200kTokens <= budget,
				`${mode} o200k ${measurement.o200kTokens} > ${budget}`,
			)
		})
	}

	it('measures full mode without gating it', (t) => {
		const measurement = measurementFor('full')
		t.diagnostic(
			`full: ${measurement.toolCount} tools, ${measurement.characters} characters, ${measurement.cl100kTokens} cl100k, ${measurement.o200kTokens} o200k, reported and ungated`,
		)
		assert.ok(measurement.toolCount > 0, 'full mode must still be measured')
		assert.ok(measurement.cl100kTokens > measurementFor('curated').cl100kTokens)
		// Roughly four characters per token in both encoders: a sanity check on the tokenizer, not
		// a budget. A wild ratio would mean the payload or the encoder is not what we think it is.
		const ratio = measurement.characters / measurement.cl100kTokens
		assert.ok(ratio > 3 && ratio < 6, `suspicious character-to-token ratio ${ratio.toFixed(2)}`)
	})

	it('keeps dynamic the cheapest mode, which is the only reason it is the default', () => {
		const dynamic = measurementFor('dynamic').cl100kTokens
		for (const mode of ['curated', 'full']) {
			assert.ok(dynamic < measurementFor(mode).cl100kTokens, `dynamic must undercut ${mode}`)
		}
	})
})

describe('accepted measurements', () => {
	for (const [mode, accepted] of Object.entries(ACCEPTED)) {
		it(`records ${mode} at ${accepted.cl100kTokens} cl100k and ${accepted.o200kTokens} o200k`, () => {
			const measurement = measurementFor(mode)
			assert.deepEqual(
				{
					toolCount: measurement.toolCount,
					cl100kTokens: measurement.cl100kTokens,
					o200kTokens: measurement.o200kTokens,
				},
				accepted,
				`${mode} definitions moved; explain the change and update the accepted acceptance figures`,
			)
		})
	}

	it('records both encodings for every mode, never one alone', () => {
		for (const measurement of measurements) {
			assert.ok(Number.isInteger(measurement.cl100kTokens) && measurement.cl100kTokens > 0)
			assert.ok(Number.isInteger(measurement.o200kTokens) && measurement.o200kTokens > 0)
			assert.notEqual(measurement.cl100kTokens, measurement.o200kTokens)
		}
	})
})

/**
 * The dynamic surface has grown from three meta-tools to five since the 2026-07-27 baseline,
 * because execution was split by risk class so a read executor can never be handed a write. That
 * is a deliberate safety change, and it costs roughly twice the definition payload.
 */
describe('regression against the verified dynamic baseline', () => {
	it('keeps the baseline seeded verbatim', () => {
		assert.equal(REGRESSION_BASELINES.dynamic.cl100kTokens, 447)
		assert.equal(REGRESSION_BASELINES.dynamic.o200kTokens, 456)
		assert.equal(REGRESSION_BASELINES.dynamic.toolCount, 3)
	})

	it('is above the 20% explanation threshold, so the release contract must record the new figure', (t) => {
		const measurement = measurementFor('dynamic')
		const growth = measurement.cl100kTokens / REGRESSION_BASELINES.dynamic.cl100kTokens - 1
		t.diagnostic(`dynamic grew ${(growth * 100).toFixed(1)}% against the 447 cl100k baseline`)
		assert.ok(growth > EXPLANATION_THRESHOLD, 'baseline drift is what triggers this requirement')

		assert.ok(existsSync(CONTRACT_PATH), 'release-contract.json must record the accepted figure')
		const contract = JSON.parse(readFileSync(CONTRACT_PATH, 'utf8'))
		const recorded = contract.profiles
			.filter((profile) => profile.status === 'MEASURED')
			.map((profile) => profile.modes.dynamic)

		assert.ok(recorded.length > 0, 'no measured profile carries a dynamic figure')
		for (const row of recorded) {
			assert.equal(row.cl100kTokens, measurement.cl100kTokens, 'release contract is stale')
			assert.equal(row.o200kTokens, measurement.o200kTokens, 'release contract is stale')
		}
	})

	it('still holds the enlarged dynamic surface well inside its hard cap', () => {
		assert.ok(measurementFor('dynamic').o200kTokens <= DEFINITION_BUDGETS.dynamic)
	})
})

describe('release contract agreement', () => {
	it('publishes the gated budgets under the same serializer and tokenizer', () => {
		const contract = JSON.parse(readFileSync(CONTRACT_PATH, 'utf8'))
		assert.equal(contract.serializer, SERIALIZER)
		assert.equal(contract.tokenizer, TOKENIZER)

		// The measurement fixture uses guarded mode with both prerequisites, whose registry equals
		// the reversible profile's, so that row is the one these fresh numbers must agree with.
		// Only the gated modes are compared: full mode is reported, not published as a budget, and
		// `scripts/build-release-contract.mjs --check` owns whole-contract freshness.
		const profile = contract.profiles.find(
			(entry) => entry.name === 'core-1.5.5-pro-1.5.4-rest-reversible',
		)
		assert.ok(profile, 'the reversible profile row must exist')
		assert.equal(profile.status, 'MEASURED')

		for (const mode of ['dynamic', 'curated', 'code']) {
			const measurement = measurementFor(mode)
			assert.deepEqual(
				{
					toolCount: profile.modes[mode].toolCount,
					cl100kTokens: profile.modes[mode].cl100kTokens,
					o200kTokens: profile.modes[mode].o200kTokens,
				},
				{
					toolCount: measurement.toolCount,
					cl100kTokens: measurement.cl100kTokens,
					o200kTokens: measurement.o200kTokens,
				},
				`release-contract.json disagrees with a fresh ${mode} measurement`,
			)
		}
	})
})
