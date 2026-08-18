import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { it } from "node:test";

const criticalCss = readFileSync(new URL("../../assets/css/currency-critical.css", import.meta.url), "utf8");
const switcherCss = readFileSync(new URL("../../assets/css/currency-switcher.css", import.meta.url), "utf8");
const projectionSource = readFileSync(new URL("../../assets/js/currency-projection.js", import.meta.url), "utf8");

/**
 * The shield and the runtime keep two copies of the same price selector list.
 * They drift the moment FluentCart adds a price surface and only one list is
 * updated, and the symptom is a stale amount flashing on exactly that surface.
 */
it("shields every price surface the projection runtime rewrites", () => {
	const list = projectionSource.match(/const PRICE_SELECTORS = \[([\s\S]*?)\]\.join/)?.[1] || "";
	const selectors = [...list.matchAll(/"([^"]+)"/g)].map((match) => match[1]);

	assert.ok(selectors.length > 20, "Runtime selector list not found.");
	for (const selector of selectors) {
		assert.ok(criticalCss.includes(selector), `Missing shield selector: ${selector}`);
	}
});

/**
 * Every rule that has to apply during the correction window keys off a class the
 * runtime adds, so it must ship inline. Left in a linked stylesheet, a used-CSS
 * pass may drop it and a defer pass may delay it — both leave the lock invisible.
 */
it("keeps runtime-state rules out of the linked stylesheet", () => {
	for (const stateClass of ["fchub-mc-pending"]) {
		assert.ok(!switcherCss.includes(stateClass), `${stateClass} is still linked, not inline.`);
		assert.ok(criticalCss.includes(stateClass), `${stateClass} is missing from the critical styles.`);
	}
});
