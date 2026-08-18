import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { describe, it } from "node:test";
import vm from "node:vm";

const switcherSource = readFileSync(
	new URL("../../assets/js/currency-switcher.js", import.meta.url),
	"utf8",
);

/**
 * Drives `fchubMcSwitchCurrency` against stand-ins for the two runtimes it calls,
 * so these tests describe the switch itself rather than price formatting.
 */
function runSwitch({ config = {}, applyResult = true, postStatus = 200, postThrows = false } = {}) {
	const applied = [];
	const stored = [];
	const events = [];
	const posts = [];
	const warnings = [];
	const navigations = [];
	const errorsShown = [];
	let announcer = null;

	const sandbox = {
		window: {
			fchubMcConfig: {
				restUrl: "https://shop.test/wp-json/fchub-mc/v1",
				isLoggedIn: false,
				nonce: "",
				currencyTable: { USD: {}, EUR: {} },
				...config,
			},
			fchubMc: { setCurrency: (code) => stored.push(code) },
			fchubMcApplyCurrency: (code) => {
				applied.push(code);
				return applyResult;
			},
			dispatchEvent: (event) => events.push(event),
			addEventListener() {},
			location: {
				href: "https://shop.test/pricing",
				reload: () => navigations.push("reload"),
			},
		},
		document: {
			readyState: "complete",
			addEventListener() {},
			querySelectorAll: () => [],
			getElementById: (id) => (id === "fchub-mc-announcer" ? announcer : null),
			body: { appendChild: (node) => { announcer = node; } },
			createElement: () => {
				const attributes = new Map();
				return {
					id: "",
					className: "",
					textContent: "",
					setAttribute: (name, value) => attributes.set(name, value),
					getAttribute: (name) => attributes.get(name) ?? null,
					appendChild() {},
				};
			},
		},
		fetch: (url, options) => {
			posts.push({ url, options });
			if (postThrows) return Promise.reject(new Error("offline"));
			return Promise.resolve({
				ok: postStatus < 400,
				status: postStatus,
				json: () => Promise.resolve({ data: { message: "nope", code: "persistence_unavailable" } }),
			});
		},
		console: { warn: (...args) => warnings.push(args.join(" ")), error() {} },
		CustomEvent: class {
			constructor(type, options = {}) {
				this.type = type;
				this.detail = options.detail;
			}
		},
	};

	vm.runInNewContext(switcherSource, sandbox, { filename: "currency-switcher.js" });

	const root = {
		querySelector: () => null,
		appendChild: (node) => errorsShown.push(node),
		classList: { remove() {}, add() {} },
	};

	return {
		applied,
		stored,
		events,
		posts,
		warnings,
		navigations,
		get announcer() {
			return announcer;
		},
		errorsShown: { get text() { return errorsShown.map((node) => node.textContent).join(" "); }, get length() { return errorsShown.length; } },
		switch: (code) => sandbox.window.fchubMcSwitchCurrency(code, { root }),
	};
}

describe("switching currency", () => {
	it("applies the choice without navigating", async () => {
		const run = runSwitch();
		await run.switch("EUR");

		assert.deepEqual(run.applied, ["EUR"]);
		assert.deepEqual(run.stored, ["EUR"]);
		assert.deepEqual(run.navigations, [], "A switch must not cost a page load.");
		assert.equal(run.events[0].type, "fchub_mc:context_changed");
		assert.equal(run.events[0].detail.currency, "EUR");
	});

	it("shows the visitor the new prices before the server has heard about it", async () => {
		const run = runSwitch();
		const pending = run.switch("EUR");

		assert.deepEqual(run.applied, ["EUR"], "The apply must not wait on the network.");
		await pending;
		assert.equal(run.posts.length, 1);
	});

	/**
	 * A failed POST means the preference will not outlive the session, not that the
	 * visitor is looking at the wrong prices. Rolling back a correct switch to
	 * report a cookie problem would be the worse lie.
	 */
	it("keeps a correct switch when the server refuses to store it", async () => {
		const run = runSwitch({ postStatus: 409 });
		await run.switch("EUR");

		assert.deepEqual(run.applied, ["EUR"]);
		assert.equal(run.errorsShown.length, 0);
		assert.match(run.warnings.join(" "), /not stored/);
	});

	it("keeps a correct switch when the network is gone entirely", async () => {
		const run = runSwitch({ postThrows: true });
		await run.switch("EUR");

		assert.deepEqual(run.applied, ["EUR"]);
		assert.equal(run.errorsShown.length, 0);
		assert.match(run.warnings.join(" "), /not stored/);
	});

	it("reports a hard failure and stores nothing when the currency cannot be applied", async () => {
		const run = runSwitch({ applyResult: false });
		await run.switch("JPY");

		assert.deepEqual(run.stored, []);
		assert.deepEqual(run.posts, [], "Nothing to persist when nothing was applied.");
		assert.match(run.errorsShown.text, /not available/);
	});

	/**
	 * The only message a visitor can ever see from this file. It used to be an
	 * English literal in a plugin whose every other string goes through `__()`,
	 * which meant a German store showed one English sentence at the worst moment.
	 */
	it("shows the store's own translation when a currency cannot be applied", async () => {
		const run = runSwitch({
			applyResult: false,
			config: { presentationTemplates: { currencyUnavailable: "Diese Währung ist nicht verfügbar." } },
		});
		await run.switch("JPY");

		assert.match(run.errorsShown.text, /Diese Währung/);
	});

	/**
	 * Switching no longer reloads, so nothing announces itself any more. Without a
	 * live region a screen-reader user picks a currency, the dropdown closes, and
	 * every price on the page changes in silence.
	 */
	it("announces the new currency to assistive technology", async () => {
		const run = runSwitch({
			config: {
				presentationTemplates: { currencySwitched: "Prices are now shown in %s." },
				currencyTable: { USD: {}, EUR: { displayCurrencyName: "Euro" } },
			},
		});
		await run.switch("EUR");

		assert.equal(run.announcer.getAttribute("aria-live"), "polite");
		assert.equal(run.announcer.textContent, "Prices are now shown in Euro.");
	});

	it("sends the REST nonce only for a signed-in visitor", async () => {
		const guest = runSwitch();
		await guest.switch("EUR");
		assert.equal(guest.posts[0].options.headers["X-WP-Nonce"], undefined);

		const member = runSwitch({ config: { isLoggedIn: true, nonce: "abc123" } });
		await member.switch("EUR");
		assert.equal(member.posts[0].options.headers["X-WP-Nonce"], "abc123");
	});
});
