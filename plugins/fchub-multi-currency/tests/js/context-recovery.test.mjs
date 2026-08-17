import assert from "node:assert/strict";
import { describe, it } from "node:test";

import { element, runRecovery } from "./context-recovery-fixture.mjs";

describe("cached page DOM convergence", () => {
	it("keeps a Rocket-style stale destination locked on the visitor's selected currency until recovery settles", async () => {
		let releaseRecovery;
		const recoveryGate = new Promise((resolve) => {
			releaseRecovery = resolve;
		});
		const triggerCode = element();
		triggerCode.textContent = "USD";
		const trigger = element({
			children: { ".fchub-mc-switcher__code": triggerCode },
		});
		const usdOption = element({
			attributes: { "data-value": "USD", "aria-selected": "true" },
			classes: ["fchub-mc-switcher__option--active"],
		});
		const eurOption = element({
			attributes: { "data-value": "EUR", "aria-selected": "false" },
			children: {
				".fchub-mc-switcher__option-code": Object.assign(element(), { textContent: "EUR" }),
			},
		});
		const switcher = element({
			children: { "[data-fchub-mc-trigger]": trigger },
			lists: { "[role='option'][data-value]": [usdOption, eurOption] },
		});
		const usdButton = element({ attributes: { "data-value": "USD" }, classes: ["is-active"] });
		const eurButton = element({ attributes: { "data-value": "EUR" } });
		const buttonRoot = element({ lists: { "[data-value]": [usdButton, eurButton] } });

		const result = await runRecovery({
			storage: { fchub_mc_currency: "EUR" },
			selectors: {
				"[data-fchub-mc-switcher]": [switcher],
				"[data-fchub-mc-button-switcher]": [buttonRoot],
			},
			onFetch: () => recoveryGate,
			onPending: ({ document }) => {
				assert.equal(document.documentElement.classList.contains("fchub-mc-recovering"), true);
				assert.equal(switcher.classList.contains("fchub-mc-switcher--loading"), true);
				assert.equal(switcher.getAttribute("aria-busy"), "true");
				assert.equal(trigger.disabled, true);
				assert.equal(triggerCode.textContent, "EUR");
				assert.equal(eurOption.getAttribute("aria-selected"), "true");
				assert.equal(buttonRoot.classList.contains("fchub-mc-selector-buttons--loading"), true);
				assert.equal(buttonRoot.getAttribute("aria-busy"), "true");
				assert.equal(usdButton.disabled, true);
				assert.equal(eurButton.disabled, true);
				assert.equal(usdButton.classList.contains("is-active"), false);
				assert.equal(eurButton.classList.contains("is-active"), true);
				releaseRecovery();
			},
		});

		assert.equal(result.document.documentElement.classList.contains("fchub-mc-recovering"), true);
		assert.equal(result.config.displayCurrency, "EUR");

		result.window.fchubMcCompleteRecovery();

		assert.equal(result.document.documentElement.classList.contains("fchub-mc-recovering"), false);
		assert.equal(switcher.classList.contains("fchub-mc-switcher--loading"), false);
		assert.equal(switcher.getAttribute("aria-busy"), null);
		assert.equal(trigger.disabled, false);
		assert.equal(buttonRoot.classList.contains("fchub-mc-selector-buttons--loading"), false);
		assert.equal(buttonRoot.getAttribute("aria-busy"), null);
		assert.equal(usdButton.disabled, false);
		assert.equal(eurButton.disabled, false);
	});

	it("restores the server-rendered currency after failed recovery and then unlocks cleanly", async () => {
		const triggerCode = Object.assign(element(), { textContent: "USD" });
		const trigger = element({
			children: { ".fchub-mc-switcher__code": triggerCode },
		});
		const usdOption = element({
			attributes: { "data-value": "USD", "aria-selected": "true" },
			classes: ["fchub-mc-switcher__option--active"],
		});
		const eurOptionCode = Object.assign(element(), { textContent: "EUR" });
		const eurOption = element({
			attributes: { "data-value": "EUR", "aria-selected": "false" },
			children: { ".fchub-mc-switcher__option-code": eurOptionCode },
		});
		const switcher = element({
			children: { "[data-fchub-mc-trigger]": trigger },
			lists: { "[role='option'][data-value]": [usdOption, eurOption] },
		});

		const result = await runRecovery({
			search: "?currency=EUR&utm_source=test",
			storage: { fchub_mc_currency: "EUR" },
			response: { ok: false, status: 503 },
			selectors: { "[data-fchub-mc-switcher]": [switcher] },
		});

		assert.equal(result.config.displayCurrency, "USD");
		assert.equal(triggerCode.textContent, "USD");
		assert.equal(usdOption.getAttribute("aria-selected"), "true");
		assert.equal(result.warnings.length, 1);
		assert.equal(result.replacedUrls.length, 0);
		assert.equal(result.document.documentElement.classList.contains("fchub-mc-recovering"), true);
		assert.equal(trigger.disabled, true);

		result.window.fchubMcCompleteRecovery();

		assert.equal(result.document.documentElement.classList.contains("fchub-mc-recovering"), false);
		assert.equal(trigger.disabled, false);
	});

	it("never re-enables controls that were disabled before recovery", async () => {
		const trigger = element();
		trigger.disabled = true;
		const switcher = element({ children: { "[data-fchub-mc-trigger]": trigger } });
		const unavailableButton = element({ attributes: { "data-value": "USD" } });
		unavailableButton.disabled = true;
		const availableButton = element({ attributes: { "data-value": "EUR" } });
		const buttonRoot = element({
			lists: { "[data-value]": [unavailableButton, availableButton] },
		});

		const result = await runRecovery({
			storage: { fchub_mc_currency: "EUR" },
			selectors: {
				"[data-fchub-mc-switcher]": [switcher],
				"[data-fchub-mc-button-switcher]": [buttonRoot],
			},
		});

		result.window.fchubMcCompleteRecovery();

		assert.equal(trigger.disabled, true);
		assert.equal(unavailableButton.disabled, true);
		assert.equal(availableButton.disabled, false);
	});

	it("unlocks after context convergence when price projection is disabled", async () => {
		const trigger = element();
		const switcher = element({ children: { "[data-fchub-mc-trigger]": trigger } });

		const result = await runRecovery({
			config: { projectionEnabled: false },
			storage: { fchub_mc_currency: "EUR" },
			selectors: { "[data-fchub-mc-switcher]": [switcher] },
		});

		assert.equal(result.config.displayCurrency, "EUR");
		assert.equal(result.document.documentElement.classList.contains("fchub-mc-recovering"), false);
		assert.equal(switcher.classList.contains("fchub-mc-switcher--loading"), false);
		assert.equal(trigger.disabled, false);
	});

	it("applies recovered context to markup parsed after the head recovery request finishes", async () => {
		const selectors = {};
		const result = await runRecovery({
			documentReadyState: "loading",
			storage: { fchub_mc_currency: "EUR" },
			selectors,
		});
		const triggerCode = Object.assign(element(), { textContent: "USD" });
		const trigger = element({ children: { ".fchub-mc-switcher__code": triggerCode } });
		const eurOption = element({ attributes: { "data-value": "EUR" } });
		const switcher = element({
			children: { "[data-fchub-mc-trigger]": trigger },
			lists: { "[role='option'][data-value]": [eurOption] },
		});
		selectors["[data-fchub-mc-switcher]"] = [switcher];

		result.document.dispatchEvent({ type: "DOMContentLoaded" });

		assert.equal(triggerCode.textContent, "EUR");
		assert.equal(trigger.disabled, true);
		assert.equal(switcher.classList.contains("fchub-mc-switcher--loading"), true);
	});

	it("updates every currency surface even when dropdown option flags are hidden", async () => {
		const eurCheck = element();
		const usdCheck = element();
		const eurOption = element({
			attributes: { "data-value": "EUR", "aria-selected": "false" },
			children: { ".fchub-mc-switcher__option-check": eurCheck },
		});
		const usdOption = element({
			attributes: { "data-value": "USD", "aria-selected": "true" },
			classes: ["fchub-mc-switcher__option--active"],
			children: { ".fchub-mc-switcher__option-check": usdCheck },
		});
		const triggerCode = element();
		const triggerName = element();
		const triggerSymbol = element();
		const triggerFlag = element();
		triggerFlag.innerHTML = '<img alt="USD" src="usd.svg">';
		const trigger = element({
			children: {
				".fchub-mc-switcher__code": triggerCode,
				".fchub-mc-switcher__name": triggerName,
				".fchub-mc-switcher__symbol": triggerSymbol,
				".fchub-mc-switcher__flag": triggerFlag,
			},
		});
		const footer = element();
		const switcher = element({
			attributes: {
				"data-fchub-mc-show-rate-badge": "1",
				"data-fchub-mc-show-rate-value": "1",
				"data-fchub-mc-show-context-note": "1",
			},
			children: {
				"[data-fchub-mc-trigger]": trigger,
				"[data-fchub-mc-switcher-footer]": footer,
			},
			lists: { "[role='option'][data-value]": [usdOption, eurOption] },
		});
		const usdButton = element({ attributes: { "data-value": "USD" }, classes: ["is-active"] });
		const eurButton = element({ attributes: { "data-value": "EUR" } });
		const buttonRoot = element({ lists: { "[data-value]": [usdButton, eurButton] } });
		const currentBlock = element({ attributes: { "data-fchub-mc-context-current": "symbol_code" } });
		const rateBlock = element({
			attributes: {
				"data-fchub-mc-context-rate": "compact",
				"data-fchub-mc-rate-precision": "4",
				"data-fchub-mc-hide-when-base": "0",
			},
		});
		const noticeBlock = element({
			attributes: {
				"data-fchub-mc-context-notice": "compact",
				"data-fchub-mc-hide-when-base": "1",
			},
		});

		await runRecovery({
			cookie: "fchub_mc_currency=EUR",
			selectors: {
				"[data-fchub-mc-switcher]": [switcher],
				"[data-fchub-mc-button-switcher]": [buttonRoot],
				"[data-fchub-mc-context-current]": [currentBlock],
				"[data-fchub-mc-context-rate]": [rateBlock],
				"[data-fchub-mc-context-notice]": [noticeBlock],
			},
		});

		assert.equal(triggerCode.textContent, "EUR");
		assert.equal(triggerName.textContent, "Euro");
		assert.equal(triggerSymbol.textContent, "€");
		assert.equal(triggerFlag.innerHTML, '<img alt="EUR" src="eur.svg">');
		assert.equal(eurOption.getAttribute("aria-selected"), "true");
		assert.equal(eurCheck.textContent, "✓");
		assert.equal(usdOption.getAttribute("aria-selected"), "false");
		assert.equal(usdCheck.textContent, "");
		assert.equal(eurButton.classList.contains("is-active"), true);
		assert.equal(usdButton.classList.contains("is-active"), false);
		assert.equal(currentBlock.innerHTML, "<span>€ EUR</span>");
		assert.equal(rateBlock.innerHTML, "<span>1 USD = 0.9200 EUR</span>");
		assert.equal(noticeBlock.innerHTML, "<span>Viewing EUR</span>");
		assert.equal(
			footer.innerHTML,
			"<span>Fresh</span><span>1 USD = 0.92000000 EUR</span><span>Checkout in USD</span>",
		);
		assert.equal(footer.hidden, false);
	});
});
