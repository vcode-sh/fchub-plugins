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

function renderFooter(entry) {
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
