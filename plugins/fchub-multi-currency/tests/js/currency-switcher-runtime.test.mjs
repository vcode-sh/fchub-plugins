import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { describe, it } from "node:test";
import vm from "node:vm";

const source = readFileSync(new URL("../../assets/js/currency-switcher.js", import.meta.url), "utf8");

async function runSwitch({ config = {}, response = { ok: true, status: 200 }, payload, historyError } = {}) {
	const fetchCalls = [];
	const events = [];
	const historyStates = [];
	const replacedUrls = [];
	let appliedContexts = 0;
	let reloads = 0;
	const location = {
		href: "https://shop.test/product?money=EUR&utm_source=test#plans",
		reload() {
			reloads++;
		},
	};
	const window = {
		fchubMcConfig: {
			restUrl: "https://shop.test/wp-json/fchub-mc/v1",
			nonce: "nonce",
			isLoggedIn: false,
			urlParamEnabled: true,
			urlParamKey: "money",
			...config,
		},
		fchubMcApplyContext() {
			appliedContexts++;
		},
		location,
		history: {
			state: { cartDrawer: "open" },
			replaceState(state, _title, url) {
				if (historyError) throw historyError;
				location.href = String(url);
				historyStates.push(state);
				replacedUrls.push(location.href);
			},
		},
		dispatchEvent(event) {
			events.push(event);
		},
		addEventListener() {},
	};
	const document = {
		readyState: "complete",
		querySelectorAll() {
			return [];
		},
		addEventListener() {},
	};
	const sandbox = {
		window,
		document,
		URL,
		CustomEvent: class CustomEvent {
			constructor(type, options = {}) {
				this.type = type;
				this.detail = options.detail;
			}
		},
		fetch: async (url, options) => {
			fetchCalls.push({ url, options });
			return {
				...response,
				json: async () => payload ?? { data: { persisted: true } },
			};
		},
		console: { warn() {} },
		requestAnimationFrame(callback) {
			callback();
		},
	};

	vm.runInNewContext(source, sandbox, { filename: "currency-switcher.js" });
	await window.fchubMcSwitchCurrency("USD", {});

	return { appliedContexts, events, fetchCalls, historyStates, href: location.href, reloads, replacedUrls };
}

describe("currency switch reload", () => {
	it("removes the configured URL preference only after the new preference is saved", async () => {
		const result = await runSwitch();

		assert.equal(result.reloads, 1);
		assert.deepEqual(result.replacedUrls, ["https://shop.test/product?utm_source=test#plans"]);
		assert.equal(result.href, "https://shop.test/product?utm_source=test#plans");
		assert.equal(result.events.at(-1)?.type, "fchub_mc:context_changed");
		assert.equal(result.fetchCalls[0].options.cache, "no-store");
		assert.deepEqual(result.historyStates, [{ cartDrawer: "open" }]);
	});

	it("omits a cached REST nonce for logged-out visitors", async () => {
		const result = await runSwitch({ config: { nonce: "expired-public-page-nonce" } });

		assert.equal(Object.hasOwn(result.fetchCalls[0].options.headers, "X-WP-Nonce"), false);
		assert.equal(result.reloads, 1);
	});

	it("keeps REST cookie authentication for logged-in visitors", async () => {
		const result = await runSwitch({ config: { isLoggedIn: true, nonce: "current-account-nonce" } });

		assert.equal(result.fetchCalls[0].options.headers["X-WP-Nonce"], "current-account-nonce");
	});

	it("does not treat a bare 2xx response as persistence confirmation", async () => {
		const result = await runSwitch({ payload: { data: { code: "preference_saved" } } });

		assert.equal(result.reloads, 0);
		assert.deepEqual(result.replacedUrls, []);
		assert.equal(result.events.at(-1)?.type, "fchub_mc:context_switch_failed");
	});

	it("does not repaint server-rendered switchers before recovery", async () => {
		const result = await runSwitch();

		assert.equal(result.appliedContexts, 0);
	});

	it("does not reload an explicit preference URL when cleanup fails", async () => {
		const result = await runSwitch({ historyError: new Error("history unavailable") });

		assert.equal(result.reloads, 0);
		assert.equal(result.href, "https://shop.test/product?money=EUR&utm_source=test#plans");
		assert.deepEqual(result.events.map((event) => event.type), ["fchub_mc:context_switch_failed"]);
	});

	it("leaves the URL alone when public URL preference resolution is disabled", async () => {
		const result = await runSwitch({ config: { urlParamEnabled: false } });

		assert.equal(result.reloads, 1);
		assert.deepEqual(result.replacedUrls, []);
		assert.equal(result.href, "https://shop.test/product?money=EUR&utm_source=test#plans");
	});

	it("does not alter the URL or reload after an unsuccessful save", async () => {
		const result = await runSwitch({
			response: { ok: false, status: 409 },
			payload: { data: { persisted: false, code: "persistence_unavailable", message: "Not saved." } },
		});

		assert.equal(result.reloads, 0);
		assert.deepEqual(result.replacedUrls, []);
		assert.equal(result.href, "https://shop.test/product?money=EUR&utm_source=test#plans");
		assert.equal(result.events.at(-1)?.type, "fchub_mc:context_switch_failed");
	});
});
