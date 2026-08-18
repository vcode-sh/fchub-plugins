import assert from "node:assert/strict";
import { describe, it } from "node:test";

import { projectionElement, runProjection, textNode } from "./projection-runtime-fixture.mjs";

/**
 * Characterisation tests for converting the same element more than once.
 *
 * These matter far more than they used to. A currency switch was a page reload,
 * so every element was converted at most once per document. Switching happens in
 * place now, which means `clearProjectionMarkers` restores the original markup and
 * the projection runs again — repeatedly, on the same nodes, in one page.
 *
 * The invariant that keeps that honest is that `data-fchub-mc-original` is written
 * once and never overwritten. Lose it and the second switch converts the first
 * switch's output: 100 USD becomes 92 EUR becomes 85 GBP-of-EUR. Nobody would spot
 * that in a screenshot, and every price on the page would be wrong.
 */
function priceElement(text, attributes = {}) {
	return projectionElement({ classes: ["fct-item-price"], text, attributes });
}

async function convert(element, rate, extraConfig = {}) {
	const { window } = await runProjection({
		config: { rate, ...extraConfig },
		priceElements: [element],
	});

	return window;
}

describe("converting the same element more than once", () => {
	it("converts from the original amount on every pass, never from the last one", async () => {
		const element = priceElement("$100.00");
		const window = await convert(element, "0.5");

		assert.equal(element.textContent, "€50.00", "First pass halves the base amount.");

		// What an in-page switch does: restore, then apply the next currency.
		window.fchubMcConfig.currencyTable = {
			EUR: { ...window.fchubMcConfig, rate: "0.25", displayCurrency: "EUR" },
		};
		window.fchubMcApplyCurrency("EUR");

		assert.equal(
			element.textContent,
			"€25.00",
			"Second pass must quarter the original $100, not halve the €50 the first pass wrote.",
		);
	});

	it("keeps the original markup so a restore is exact, not approximate", async () => {
		const element = priceElement("$100.00");
		await convert(element, "0.5");

		assert.equal(
			element.getAttribute("data-fchub-mc-original"),
			"$100.00",
			"The stored original is the base-currency render, not the converted one.",
		);
	});

	/**
	 * Within one pass the marker is the guard. Across a switch it is not — and must
	 * not be, because `applyCurrency` clears every marker before projecting so the
	 * next currency starts from the original. Two different jobs, one attribute.
	 */
	it("does not convert twice inside a single pass", async () => {
		const element = priceElement("$100.00");
		const window = await convert(element, "0.5");

		assert.equal(element.textContent, "€50.00");
		window.fchubMcProjectPrices();

		assert.equal(element.textContent, "€50.00", "A second pass over a marked element changes nothing.");
	});
});

describe("price surfaces that carry text around the amount", () => {
	it("replaces only the amount in subscription payment info, keeping the sentence", async () => {
		const element = projectionElement({
			classes: ["fct_item_payment_info"],
			text: "$80.00 per month, until cancelled",
		});
		await convert(element, "0.5");

		assert.equal(element.textContent, "€40.00 per month, until cancelled");
	});

	it("converts every amount in a receipt line, not just the first", async () => {
		const element = projectionElement({
			classes: ["fct-thank-you-page-order-items-list-payment-info"],
			text: "$80.00 now, then $20.00 per month",
		});
		await convert(element, "0.5");

		assert.equal(element.textContent, "€40.00 now, then €10.00 per month");
	});

	it("leaves text alone when it carries no recognisable amount", async () => {
		const element = priceElement("Free");
		await convert(element, "0.5");

		assert.equal(element.textContent, "Free");
		assert.equal(element.getAttribute("data-fchub-mc-projected"), null);
	});
});

describe("variant prices", () => {
	/**
	 * `clearProjectionMarkers` restores the innerHTML captured at first render, which
	 * may carry an `is-hidden` class from whichever variation tab was open then.
	 * Without this sync, switching currency reopens a tab the visitor had closed.
	 */
	it("keeps variation content matching the parent's current tab state", async () => {
		const child = projectionElement({ classes: ["fluent-cart-product-variation-content"] });
		const parent = projectionElement({
			classes: ["fct-product-item-price", "is-hidden"],
			children: { del: null },
			lists: { ".fluent-cart-product-variation-content": [child] },
			childNodes: [textNode("$80.00")],
		});

		await convert(parent, "0.5");

		assert.equal(
			child.classList.contains("is-hidden"),
			true,
			"A hidden parent must not leave its restored children visible.",
		);
	});
});
