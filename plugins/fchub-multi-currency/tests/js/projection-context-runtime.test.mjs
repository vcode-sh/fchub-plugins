import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { it } from "node:test";
import vm from "node:vm";

const source = readFileSync(new URL("../../assets/js/currency-projection.js", import.meta.url), "utf8");

function deferred() {
	let resolve;
	const promise = new Promise((done) => {
		resolve = done;
	});

	return { promise, resolve };
}

function runtime() {
	const names = new Set();
	const timers = [];
	const context = deferred();
	const document = {
		documentElement: {
			classList: {
				add: (name) => names.add(name),
				remove: (name) => names.delete(name),
				contains: (name) => names.has(name),
			},
		},
	};
	const window = {
		fchubMcContextReady: context.promise,
		fchubMcConfig: {
			displayCurrency: "USD",
			baseCurrency: "USD",
			isBaseDisplay: true,
		},
	};
	const execution = vm.runInNewContext(source, {
		window,
		document,
		setTimeout(callback, delay) {
			timers.push({ callback, delay });
			return timers.length;
		},
		clearTimeout() {},
	});

	return { context, document, execution, timers };
}

it("shields stale prices immediately and releases them after two seconds", async () => {
	const state = runtime();

	assert.equal(state.document.documentElement.classList.contains("fchub-mc-projecting"), true);
	assert.equal(state.timers[0]?.delay, 2000);

	state.timers[0].callback();
	assert.equal(state.document.documentElement.classList.contains("fchub-mc-projecting"), false);

	state.context.resolve();
	await state.execution;
});

it("releases the shield when recovered context needs no projection", async () => {
	const state = runtime();

	state.context.resolve();
	await state.execution;

	assert.equal(state.document.documentElement.classList.contains("fchub-mc-projecting"), false);
});
