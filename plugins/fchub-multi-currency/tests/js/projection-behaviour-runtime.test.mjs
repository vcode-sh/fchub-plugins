import assert from "node:assert/strict";
import { describe, it } from "node:test";

import {
	projectRenderedPrice,
	projectionElement,
	runProjection,
	textNode,
} from "./projection-runtime-fixture.mjs";

describe("production projection parsing", () => {
	it("preserves a translated prefix while converting a left-signed price", async () => {
		const { projected } = await projectRenderedPrice({
			price: "From $80.00",
			config: { rate: "0.93" },
		});

		assert.equal(projected.textContent, "From €74.40");
	});

	it("converts both ends of a rendered product range", async () => {
		const { projected } = await projectRenderedPrice({
			price: "$80.00 – $120.00",
			config: { rate: "0.5" },
		});

		assert.equal(projected.textContent, "€40.00 – €60.00");
	});

	it("parses FluentCart's comma-decimal shop format independently of display format", async () => {
		const { projected } = await projectRenderedPrice({
			price: "1.234,50€",
			config: {
				baseCurrency: "EUR",
				baseCurrencyCode: "EUR",
				baseCurrencySign: "€",
				baseDecimalSep: ",",
				baseThousandSep: ".",
				displayCurrency: "USD",
				rate: "2",
				symbol: "$",
			},
		});

		assert.equal(projected.textContent, "$2,469.00");
	});

	it("applies half-down at the exact tie instead of delegating to Math.round", async () => {
		const { projected } = await projectRenderedPrice({
			price: "$1",
			config: { decimals: 0, rate: "2.5", roundingMode: "half_down" },
		});

		assert.equal(projected.textContent, "€2");
	});

	it("rounds signed exact ties symmetrically and keeps the sign before the currency", async () => {
		const halfUp = await projectRenderedPrice({
			price: "-$1",
			config: { decimals: 0, rate: "2.5", roundingMode: "half_up" },
		});
		const halfDown = await projectRenderedPrice({
			price: "-$1",
			config: { decimals: 0, rate: "2.5", roundingMode: "half_down" },
		});

		assert.equal(halfUp.projected.textContent, "-€3");
		assert.equal(halfDown.projected.textContent, "-€2");
	});
});

describe("production projection DOM preservation", () => {
	it("updates a variant's compare price and bare price text without removing markup", async () => {
		const compare = projectionElement({ text: "$120.00" });
		const priceText = textNode("$100.00");
		const variant = projectionElement({
			classes: ["fct-product-item-price"],
			childNodes: [priceText],
			children: { del: compare },
			innerHTML: '<del aria-hidden="true">$120.00</del>$100.00',
			lists: {
				".fct-compare-price": [compare],
				".fluent-cart-product-variation-content": [],
			},
		});

		await runProjection({ priceElements: [variant] });

		assert.equal(compare.textContent, "€240.00");
		assert.equal(priceText.textContent, "€200.00");
		assert.equal(variant.getAttribute("data-fchub-mc-original"), '<del aria-hidden="true">$120.00</del>$100.00');
		assert.equal(compare.getAttribute("data-fchub-mc-projected"), "1");
	});

	it("converts every subscription price while leaving the cycle count alone", async () => {
		const terms = textNode("300.00$ per year for 12 cycles + 100.00$ setup fee");
		const paymentType = projectionElement({ childNodes: [terms] });
		const variant = projectionElement({
			classes: ["fct-product-item-price"],
			children: { ".fct-product-payment-type": paymentType },
			lists: { ".fluent-cart-product-variation-content": [] },
		});

		await runProjection({ priceElements: [variant] });

		assert.equal(terms.textContent, "€600.00 per year for 12 cycles + €200.00 setup fee");
	});

	it("updates only FluentCart's styled price child", async () => {
		const styledPrice = projectionElement({ text: "$75.00" });
		const wrapper = projectionElement({
			children: { ".fct_line_item_total, .fct_summary_value, .fct_coupon_price": styledPrice },
			text: "Price: $75.00",
		});

		await runProjection({ priceElements: [wrapper] });

		assert.equal(styledPrice.textContent, "€150.00");
		assert.equal(wrapper.textContent, "Price: $75.00");
		assert.equal(styledPrice.getAttribute("data-fchub-mc-projected"), "1");
	});

	it("marks totals as approximate but leaves ordinary prices unmarked", async () => {
		const total = projectionElement({ text: "$100.00", total: true });
		const ordinary = projectionElement({ text: "$100.00" });

		await runProjection({ priceElements: [total, ordinary] });

		assert.equal(total.textContent, "≈ €200.00");
		assert.equal(ordinary.textContent, "€200.00");
	});

	it("keeps the currency symbol in FluentCart's separate sup element exactly once", async () => {
		const sign = projectionElement({ text: "$" });
		const interval = projectionElement({ text: "per month" });
		const amount = textNode("12.00");
		const mixed = projectionElement({
			childNodes: [sign, amount, interval],
			children: {
				"span.repeat-interval": interval,
				sup: sign,
			},
			innerHTML: "<sup>$</sup>12.00<span class=\"repeat-interval\">per month</span>",
			text: "$12.00 per month",
		});

		await runProjection({ priceElements: [mixed] });

		assert.equal(sign.textContent, "€");
		assert.equal(amount.textContent, "24.00");
		assert.equal(interval.textContent, "per month");
	});
});

describe("production projection adapters", () => {
	it("leaves the shop price filter inputs and sign in base currency", async () => {
		// FluentCart submits these inputs verbatim as base-currency bounds
		// (FormData → filters[price_range_*] → Helper::toCent → min_price BETWEEN),
		// and noUiSlider writes base values into the same inputs on every drag.
		// Projecting them corrupted every filtered query for non-base visitors.
		const input = projectionElement({ value: "100" });
		const sign = projectionElement({ text: "$" });

		await runProjection({
			selectors: {
				".fc_price_range_input": [input],
				".fct-shop-currency-sign": [sign],
			},
		});

		assert.equal(input.value, "100");
		assert.equal(input.getAttribute("data-fchub-mc-base"), null);
		assert.equal(sign.textContent, "$");
		assert.equal(sign.getAttribute("data-fchub-mc-projected"), null);
	});

	it("converts multiple receipt payment amounts through the real thank-you selector path", async () => {
		const payment = projectionElement({
			classes: ["fct-thank-you-page-order-items-list-payment-info"],
			text: "$50.00 per year + $10.00 setup",
		});

		await runProjection({ priceElements: [payment] });

		assert.equal(payment.textContent, "€100.00 per year + €20.00 setup");
	});

	it("reprojects variant buttons from FluentCart's unchanged base attributes", async () => {
		const visible = projectionElement({ text: "$10.00" });
		const compare = projectionElement({ text: "$20.00" });
		const button = projectionElement({
			attributes: {
				"data-compare-price": "20.00",
				"data-item-price": "10.00",
			},
			children: {
				".fct-product-variant-compare-price span": compare,
				".fct-product-variant-item-price span": visible,
			},
		});

		const runtime = await runProjection({
			config: {
				baseCurrencySign: "$",
				displayCurrency: "CAD",
				rate: "1.35",
				symbol: "$",
			},
			selectors: {
				"[data-fluent-cart-product-variant][data-item-price]": [button],
				"[data-fchub-mc-projected]": [button],
			},
		});

		assert.equal(button.getAttribute("data-item-price"), "10.00");
		assert.equal(button.getAttribute("data-compare-price"), "20.00");
		assert.equal(visible.textContent, "$13.50");
		assert.equal(compare.textContent, "$27.00");

		runtime.windowListeners.get("fluentCartSingleProductVariationChanged")[0]();
		runtime.timers.find((timer) => timer.delay === 200).callback();

		assert.equal(button.getAttribute("data-item-price"), "10.00");
		assert.equal(button.getAttribute("data-compare-price"), "20.00");
		assert.equal(visible.textContent, "$13.50");
		assert.equal(compare.textContent, "$27.00");
	});

	it("projects pricing-table payment terms through their production selector", async () => {
		const terms = projectionElement({ text: "$40.00 per year for 12 cycles" });
		const paymentType = projectionElement({ lists: { span: [terms] } });

		await runProjection({
			selectors: { ".fluent-cart-pricing-table-variant-payment-type": [paymentType] },
		});

		assert.equal(terms.textContent, "€80.00 per year for 12 cycles");
	});

	it("registers the observed FluentCart window events behind one debounce owner", async () => {
		const runtime = await runProjection();
		const cartListener = runtime.windowListeners.get("fluentCartFragmentsReplaced")?.[0];
		const checkoutListener = runtime.windowListeners.get("fluentCartCheckoutDataChanged")?.[0];

		assert.equal(typeof cartListener, "function");
		assert.equal(typeof checkoutListener, "function");
		cartListener();
		checkoutListener();

		const eventTimers = runtime.timers.filter((timer) => timer.delay === 100);
		assert.equal(eventTimers.length, 2);
		assert.equal(eventTimers[0].cancelled, true);
		assert.equal(eventTimers[1].cancelled, false);
	});
});
