import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { describe, it } from "node:test";
import vm from "node:vm";

const contextSource = readFileSync(
	new URL("../../assets/js/currency-context.js", import.meta.url),
	"utf8",
);

/**
 * The rate-freshness badge in a cached document is frozen at whatever moment
 * the cache was warmed: "updated 2 hours ago" only gets older, and the stale
 * styling never appears. The browser owns time, so the browser renders the
 * badge — from the epoch the table ships — and only falls back to the
 * server-rendered string when a stale config predates the freshness fields.
 */
const NOW_SECONDS = 1_800_000_000;

function element({ attributes = {} } = {}) {
	const attrs = new Map(Object.entries(attributes));
	return {
		textContent: "",
		innerHTML: "",
		hidden: false,
		classList: {
			toggle() {},
			add() {},
			remove() {},
			contains: () => false,
		},
		getAttribute: (name) => attrs.get(name) ?? null,
		setAttribute: (name, value) => attrs.set(name, String(value)),
		querySelector: () => null,
		querySelectorAll: () => [],
	};
}

function renderFooter(entry, templateOverrides = {}) {
	const footer = element();
	const root = {
		getAttribute: (name) =>
			({
				"data-fchub-mc-show-rate-badge": "1",
				"data-fchub-mc-show-rate-value": "0",
				"data-fchub-mc-show-context-note": "0",
			})[name] ?? null,
		querySelector: (selector) =>
			selector === "[data-fchub-mc-switcher-footer]" ? footer : null,
		querySelectorAll: () => [],
	};

	const config = {
		baseCurrency: "USD",
		displayCurrency: "EUR",
		currencyTable: { EUR: entry, USD: {} },
		presentationTemplates: {
			rate: "1 %1$s = %2$s %3$s",
			rateBadgeAgo: "Rates updated %s ago",
			timeUnits: {
				min: ["%s min", "%s mins"],
				hour: ["%s hour", "%s hours"],
				day: ["%s day", "%s days"],
				week: ["%s week", "%s weeks"],
				month: ["%s month", "%s months"],
				year: ["%s year", "%s years"],
			},
			...templateOverrides,
		},
	};

	const sandbox = {
		window: { fchubMcConfig: config },
		document: {
			readyState: "complete",
			addEventListener() {},
			querySelectorAll: (selector) =>
				selector === "[data-fchub-mc-switcher]" ? [root] : [],
		},
		Date: { now: () => NOW_SECONDS * 1000 },
		console,
	};

	vm.runInNewContext(contextSource, sandbox, { filename: "currency-context.js" });
	sandbox.window.fchubMcSyncLabels(config);

	return footer.innerHTML;
}

describe("rate freshness badge rendered by the browser", () => {
	it("renders the age from the shipped epoch, not from the cached string", () => {
		const html = renderFooter({
			rate: "0.92",
			rateFetchedAt: NOW_SECONDS - 3 * 3600,
			rateStaleAfterSeconds: 24 * 3600,
			rateBadge:
				'<span class="fchub-mc-rate-badge"><span class="fchub-mc-rate-badge__dot" aria-hidden="true"></span>Rates updated 2 mins ago</span>',
		});

		assert.match(html, /Rates updated 3 hours ago/, "The age comes from the epoch at paint time.");
		assert.doesNotMatch(html, /2 mins ago/, "The cache-frozen string is not repeated.");
		assert.doesNotMatch(html, /fchub-mc-rate-badge--stale/, "Three hours against a 24h threshold is fresh.");
	});

	it("shows the stale styling once the threshold has genuinely passed", () => {
		const html = renderFooter({
			rate: "0.92",
			rateFetchedAt: NOW_SECONDS - 25 * 3600,
			rateStaleAfterSeconds: 24 * 3600,
			rateBadge: '<span class="fchub-mc-rate-badge">Rates updated 2 mins ago</span>',
		});

		assert.match(html, /fchub-mc-rate-badge--stale/);
		assert.match(html, /Rates updated 1 day ago/);
	});

	it("keeps the singular form for a single unit", () => {
		const html = renderFooter({
			rate: "0.92",
			rateFetchedAt: NOW_SECONDS - 90,
			rateStaleAfterSeconds: 24 * 3600,
			rateBadge: '<span class="fchub-mc-rate-badge">x</span>',
		});

		assert.match(html, /Rates updated 2 mins ago/);
	});

	it("falls back to the server-rendered badge when the epoch is missing", () => {
		const html = renderFooter({
			rate: "0.92",
			rateBadge: '<span class="fchub-mc-rate-badge">Server rendered</span>',
		});

		assert.match(html, /Server rendered/);
	});

	it("renders no badge when the store disabled it server-side", () => {
		const html = renderFooter({
			rate: "0.92",
			rateFetchedAt: NOW_SECONDS - 3600,
			rateStaleAfterSeconds: 24 * 3600,
			rateBadge: "",
		});

		assert.doesNotMatch(html, /fchub-mc-rate-badge/);
	});
});

/**
 * Languages with more than two plural forms pick the right one through the
 * shipped rule table: form indices for n = 0..200, with counts past 200
 * repeating the 101..200 block. Polish is the acid test — three forms whose
 * selection depends on n%10 and n%100, not just n===1.
 */
describe("native plural forms through the shipped rule table", () => {
	const polishIndex = (n) =>
		n === 1 ? 0 : n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 10 || n % 100 >= 20) ? 1 : 2;
	const polishTemplates = {
		rateBadgeAgo: "Kursy zaktualizowane %s temu",
		timeUnits: {
			min: ["%s minuta", "%s minuty", "%s minut"],
			hour: ["%s godzina", "%s godziny", "%s godzin"],
			day: ["%s dzień", "%s dni", "%s dni"],
			week: ["%s tydzień", "%s tygodnie", "%s tygodni"],
			month: ["%s miesiąc", "%s miesiące", "%s miesięcy"],
			year: ["%s rok", "%s lata", "%s lat"],
		},
		timePluralRule: Array.from({ length: 201 }, (_, n) => polishIndex(n)),
	};

	const badgeFor = (ageSeconds) =>
		renderFooter(
			{
				rate: "0.92",
				rateFetchedAt: NOW_SECONDS - ageSeconds,
				rateStaleAfterSeconds: 400 * 24 * 3600 * 300,
				rateBadge: '<span class="fchub-mc-rate-badge">x</span>',
			},
			polishTemplates,
		);

	it("selects each of the three Polish forms by count", () => {
		assert.match(badgeFor(3600), /Kursy zaktualizowane 1 godzina temu/);
		assert.match(badgeFor(2 * 3600), /Kursy zaktualizowane 2 godziny temu/);
		assert.match(badgeFor(5 * 3600), /Kursy zaktualizowane 5 godzin temu/);
		assert.match(badgeFor(12 * 3600), /Kursy zaktualizowane 12 godzin temu/, "teens take the many form");
		assert.match(badgeFor(22 * 3600), /Kursy zaktualizowane 22 godziny temu/, "few returns past the teens");
	});

	it("repeats the periodic block for counts past the table", () => {
		assert.match(badgeFor(250 * 31536000), /Kursy zaktualizowane 250 lat temu/);
		assert.match(badgeFor(222 * 31536000), /Kursy zaktualizowane 222 lata temu/);
	});

	it("falls back to the two-form pick for configs cached before the table shipped", () => {
		const { timePluralRule, ...withoutRule } = polishTemplates;
		const html = renderFooter(
			{
				rate: "0.92",
				rateFetchedAt: NOW_SECONDS - 5 * 3600,
				rateStaleAfterSeconds: 24 * 3600,
				rateBadge: '<span class="fchub-mc-rate-badge">x</span>',
			},
			withoutRule,
		);

		assert.match(html, /5 godziny temu/, "the legacy pick: imperfect grammar, never a crash");
	});
});
