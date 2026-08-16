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
