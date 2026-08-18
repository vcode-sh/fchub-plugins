import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import vm from "node:vm";
import { describe, it } from "node:test";

const fixture = JSON.parse(
	readFileSync(new URL("./presentation-fixture.json", import.meta.url), "utf8"),
);
const scriptPath = new URL("../../assets/js/currency-context.js", import.meta.url);

/**
 * The browser renders the currency surfaces now that no recovery response carries
 * them pre-rendered. These assertions are the contract with the PHP renderers:
 * for one fixed context, every variant must come out byte-identical to what
 * CurrencyContextPresentation produces. Anything looser lets the two drift, and
 * the symptom would be a rate badge that reads differently before and after a
 * switch on the same page.
 *
 * Regenerate the fixture with: php tests/js/generate-presentation-fixture.php
 */
function surfaces(attribute, value, extraAttributes = {}) {
	const rendered = [];
	const root = {
		innerHTML: "",
		getAttribute: (name) => (name === attribute ? value : (extraAttributes[name] ?? null)),
		set html(value) {
			rendered.push(value);
		},
	};

	Object.defineProperty(root, "innerHTML", {
		get: () => rendered.at(-1) ?? "",
		set: (html) => rendered.push(html),
	});

	return { root, rendered };
}

function runContext(selectors) {
	const sandbox = {
		window: {
			fchubMcConfig: {
				displayCurrency: fixture.displayCurrency,
				baseCurrency: fixture.baseCurrency,
				currencyTable: { [fixture.displayCurrency]: fixture.entry },
				presentationTemplates: fixture.templates,
			},
		},
		document: {
			readyState: "complete",
			addEventListener() {},
			querySelectorAll: (selector) => selectors[selector] ?? [],
		},
		console,
	};

	vm.runInNewContext(readFileSync(scriptPath, "utf8"), sandbox, { filename: scriptPath.pathname });

	// The subject is the rendered output, not load ordering: paint the fixture
	// context through the exported API, the way the projection runtime does.
	sandbox.window.fchubMcSyncLabels(sandbox.window.fchubMcConfig);

	return sandbox;
}

describe("browser-rendered currency surfaces match the PHP renderers", () => {
	for (const [mode, expected] of Object.entries(fixture.current)) {
		it(`renders the current-currency block in ${mode} mode`, () => {
			const { root, rendered } = surfaces("data-fchub-mc-context-current", mode);
			runContext({ "[data-fchub-mc-context-current]": [root] });

			assert.equal(rendered.at(-1), expected);
		});
	}

	for (const [format, byPrecision] of Object.entries(fixture.rate)) {
		for (const [precision, expected] of Object.entries(byPrecision)) {
			it(`renders the ${format} rate at precision ${precision}`, () => {
				const { root, rendered } = surfaces("data-fchub-mc-context-rate", format, {
					"data-fchub-mc-rate-precision": precision,
				});
				runContext({ "[data-fchub-mc-context-rate]": [root] });

				assert.equal(rendered.at(-1), expected);
			});
		}
	}

	for (const [mode, expected] of Object.entries(fixture.notice)) {
		it(`renders the ${mode} notice`, () => {
			const { root, rendered } = surfaces("data-fchub-mc-context-notice", mode);
			runContext({ "[data-fchub-mc-context-notice]": [root] });

			assert.equal(rendered.at(-1), expected);
		});
	}
});
