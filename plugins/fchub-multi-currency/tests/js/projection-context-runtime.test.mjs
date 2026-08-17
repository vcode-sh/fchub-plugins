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

function runtime(config = {}) {
	const names = new Set();
	const additions = [];
	const timers = [];
	const errors = [];
	const context = deferred();
	const document = {
		documentElement: {
			classList: {
				add: (name) => {
					additions.push(name);
					names.add(name);
				},
				remove: (name) => names.delete(name),
				contains: (name) => names.has(name),
			},
		},
	};
	names.add("fchub-mc-recovering");
	const window = {
		fchubMcContextReady: context.promise,
		fchubMcCompleteRecovery() {
			document.documentElement.classList.remove("fchub-mc-recovering");
		},
		fchubMcConfig: {
			displayCurrency: "USD",
			baseCurrency: "USD",
			isBaseDisplay: true,
			...config,
		},
	};
	const execution = vm.runInNewContext(source, {
		window,
		document,
		console: { error: (...args) => errors.push(args) },
		setTimeout(callback, delay) {
			timers.push({ callback, delay });
			return timers.length;
		},
		clearTimeout() {},
	});

	return { additions, context, document, errors, execution, timers };
}

it("keeps the recovery shield in place until recovered prices finish projecting", async () => {
	const state = runtime();

	assert.equal(state.document.documentElement.classList.contains("fchub-mc-projecting"), false);
	assert.equal(state.document.documentElement.classList.contains("fchub-mc-recovering"), true);
	assert.equal(state.additions.length, 0);
	assert.equal(state.timers.length, 0);

	state.context.resolve();
	await state.execution;

	assert.deepEqual(state.additions, ["fchub-mc-projecting"]);
	assert.equal(state.timers[0]?.delay, 2000);
	assert.equal(state.document.documentElement.classList.contains("fchub-mc-projecting"), false);
	assert.equal(state.document.documentElement.classList.contains("fchub-mc-recovering"), false);
});

it("releases the shield when recovered context needs no projection", async () => {
	const state = runtime();

	state.context.resolve();
	await state.execution;

	assert.equal(state.document.documentElement.classList.contains("fchub-mc-projecting"), false);
});

it("releases both shields when the first projection pass throws", async () => {
	const state = runtime({
		displayCurrency: "EUR",
		baseCurrency: "USD",
		isBaseDisplay: false,
		rate: 0.92,
	});
	state.document.querySelectorAll = () => {
		throw new Error("broken storefront DOM");
	};
	state.document.readyState = "complete";

	state.context.resolve();
	await assert.doesNotReject(state.execution);

	assert.equal(state.errors.length, 1);
	assert.equal(state.document.documentElement.classList.contains("fchub-mc-projecting"), false);
	assert.equal(state.document.documentElement.classList.contains("fchub-mc-recovering"), false);
});
