import assert from "node:assert/strict";
import { describe, it } from "node:test";

import { element, runRecovery } from "./context-recovery-fixture.mjs";

describe("cached page DOM convergence", () => {
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
