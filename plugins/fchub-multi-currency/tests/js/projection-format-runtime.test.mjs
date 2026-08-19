import assert from "node:assert/strict";
import { describe, it } from "node:test";

import { projectRenderedPrice } from "./projection-runtime-fixture.mjs";

describe("production price projection formatting", () => {
	it("honours a zero-decimal display currency", async () => {
		const { projected } = await projectRenderedPrice({
			price: "$1,234.56",
			config: {
				decimals: 0,
				displayCurrency: "JPY",
				displayDecSep: ".",
				displayThousandSep: ",",
				position: "left",
				rate: "2",
				symbol: "¥",
			},
		});

		assert.equal(projected.textContent, "¥2,469");
	});

	it("does not invent a thousands separator when it is explicitly disabled", async () => {
		const { projected } = await projectRenderedPrice({
			price: "$1,000.00",
			config: {
				decimals: 2,
				displayCurrency: "EUR",
				displayDecSep: ",",
				displayThousandSep: "",
				position: "right_space",
				rate: "1.5",
				symbol: "€",
			},
		});

		assert.equal(projected.textContent, "1500,00 €");
	});
});

describe("charm rounding", () => {
	it("applies ending_99 after conversion", async () => {
		const { projected } = await projectRenderedPrice({
			config: { rate: "2", charmRounding: "ending_99" },
			price: "$34.55",
		});

		assert.equal(projected.textContent, "\u20ac69.99");
	});

	it("snaps large amounts to the nearest 5 and the nearest 10", async () => {
		const nearest5 = await projectRenderedPrice({
			config: { rate: "4.3063", charmRounding: "nearest_5" },
			price: "$400.00",
		});
		const nearest10 = await projectRenderedPrice({
			config: { rate: "4.3063", charmRounding: "nearest_10" },
			price: "$400.00",
		});

		assert.equal(nearest5.projected.textContent, "\u20ac1,725.00");
		assert.equal(nearest10.projected.textContent, "\u20ac1,720.00");
	});

	it("collapses endings to whole for zero-decimal currencies", async () => {
		const { projected } = await projectRenderedPrice({
			config: { rate: "2", decimals: 0, charmRounding: "ending_99" },
			price: "$34.55",
		});

		assert.equal(projected.textContent, "\u20ac69");
	});

	it("charms both ends of a range, so no bound reads as the odd one out", async () => {
		const { projected } = await projectRenderedPrice({
			config: { rate: "0.5", charmRounding: "ending_99" },
			price: "$80.00 \u2013 $120.00",
		});

		assert.equal(projected.textContent, "\u20ac39.99 \u2013 \u20ac59.99");
	});

	it("leaves a discount row negative rather than charming it positive", async () => {
		const { projected } = await projectRenderedPrice({
			config: { rate: "2", charmRounding: "ending_99" },
			price: "-$34.55",
		});

		assert.equal(projected.textContent, "-\u20ac69.10");
	});

	it("changes nothing under the shipped default", async () => {
		const { projected } = await projectRenderedPrice({
			config: { rate: "2", charmRounding: "none" },
			price: "$34.55",
		});

		assert.equal(projected.textContent, "\u20ac69.10");
	});
});
