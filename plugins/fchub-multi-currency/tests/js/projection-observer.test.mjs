import assert from "node:assert/strict";
import { describe, it } from "node:test";

import { projectionElement, runProjection, textNode } from "./projection-runtime-fixture.mjs";

/**
 * The MutationObserver is the safety net for DOM changes nothing announced.
 * A safety net must not cost more than what it protects: most visitors browse
 * in the base currency and convert nothing, and most mutations — countdown
 * timers, carousels, lazy images — touch no price. Neither may keep the
 * projection machinery running.
 */
function flushPendingTimers(timers) {
	for (const timer of [...timers]) {
		if (!timer.cancelled && !timer.ran) {
			timer.ran = true;
			timer.callback();
		}
	}
}

function elementOutsideAnyPriceSurface() {
	return {
		nodeType: 1,
		closest: () => null,
		matches: () => false,
		querySelector: () => null,
	};
}

describe("mutation observer lifecycle", () => {
	it("attaches no observer while the visitor browses the base currency", async () => {
		const { observers } = await runProjection({
			config: { displayCurrency: "USD", isBaseDisplay: true, rate: "1" },
		});

		assert.equal(observers.length, 0, "Nothing converts, so nothing needs watching.");
	});

	it("attaches the observer once a conversion is actually active", async () => {
		const { observers } = await runProjection({
			priceElements: [projectionElement({ classes: ["fct-item-price"], text: "$100.00" })],
		});

		assert.equal(observers.length, 1);
	});
});

describe("mutation relevance", () => {
	it("ignores a ticking text mutation outside any price surface", async () => {
		const element = projectionElement({ classes: ["fct-item-price"], text: "$100.00" });
		const { observers, documentEvents, timers } = await runProjection({
			priceElements: [element],
		});
		flushPendingTimers(timers);
		const projectionEvents = () =>
			documentEvents.filter((event) => event.type === "fchub_mc:prices_projected").length;
		const eventsBefore = projectionEvents();

		const countdownText = { ...textNode("00:59"), parentElement: { closest: () => null } };
		observers[0].deliver([
			{ type: "characterData", target: countdownText, addedNodes: [], attributeName: null },
		]);
		flushPendingTimers(timers);

		assert.equal(projectionEvents(), eventsBefore, "A countdown tick must not re-run the projection.");
	});

	it("re-projects when an added subtree carries a price element", async () => {
		const priceElements = [projectionElement({ classes: ["fct-item-price"], text: "$100.00" })];
		const { observers, documentEvents, timers } = await runProjection({ priceElements });
		flushPendingTimers(timers);
		const projectionEvents = () =>
			documentEvents.filter((event) => event.type === "fchub_mc:prices_projected").length;
		const eventsBefore = projectionEvents();

		// FluentCart re-rendered a wrapper whose subtree contains a fresh price.
		const freshPrice = projectionElement({ classes: ["fct-item-price"], text: "$40.00" });
		priceElements.push(freshPrice);
		const addedWrapper = {
			nodeType: 1,
			closest: () => null,
			matches: () => false,
			querySelector: (selector) => (selector.includes(".fct-item-price") ? freshPrice : null),
		};
		observers[0].deliver([
			{
				type: "childList",
				target: elementOutsideAnyPriceSurface(),
				addedNodes: [addedWrapper],
				attributeName: null,
			},
		]);
		flushPendingTimers(timers);

		assert.equal(projectionEvents(), eventsBefore + 1, "New price markup gets converted.");
		assert.equal(freshPrice.textContent, "€80.00");
	});

	it("re-projects when text changes inside an existing price surface", async () => {
		const element = projectionElement({ classes: ["fct-item-price"], text: "$100.00" });
		const { observers, documentEvents, timers } = await runProjection({
			priceElements: [element],
		});
		flushPendingTimers(timers);
		const projectionEvents = () =>
			documentEvents.filter((event) => event.type === "fchub_mc:prices_projected").length;
		const eventsBefore = projectionEvents();

		const priceText = {
			...textNode("$120.00"),
			parentElement: { closest: (selector) => (selector.includes(".fct-item-price") ? element : null) },
		};
		observers[0].deliver([
			{ type: "characterData", target: priceText, addedNodes: [], attributeName: null },
		]);
		flushPendingTimers(timers);

		assert.equal(projectionEvents(), eventsBefore + 1, "A price rewritten by the store re-converts.");
	});
});
