import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { it } from "node:test";

const projectionCss = readFileSync(
	new URL("../../assets/css/currency-projection.css", import.meta.url),
	"utf8",
);
const switcherCss = readFileSync(
	new URL("../../assets/css/currency-switcher.css", import.meta.url),
	"utf8",
);
const projectionSource = readFileSync(
	new URL("../../assets/js/currency-projection.js", import.meta.url),
	"utf8",
);

it("masks stale prices for the full cached-context recovery lifecycle", () => {
	assert.match(
		projectionCss,
		/:is\(\.fchub-mc-recovering, \.fchub-mc-projecting\)[^{]*\.fct-item-price/,
	);
	assert.match(projectionCss, /\[data-fchub-mc-base\][^}]*\{\s*visibility: hidden;/);

	const selectorList = projectionSource.match(/const PRICE_SELECTORS = \[([\s\S]*?)\]\.join/)?.[1] || "";
	const runtimeSelectors = [...selectorList.matchAll(/"([^"]+)"/g)].map((match) => match[1]);
	assert.ok(runtimeSelectors.length > 20);
	for (const selector of runtimeSelectors) {
		assert.ok(projectionCss.includes(selector), `Missing recovery shield selector: ${selector}`);
	}
});

it("dims both selector variants while cached context is recovering", () => {
	assert.match(switcherCss, /\.fchub-mc-switcher--loading[^{}]*\.fchub-mc-switcher__trigger/);
	assert.match(
		switcherCss,
		/\.fchub-mc-selector-buttons--loading[^{}]*\.fchub-mc-selector-buttons__button/,
	);
});
