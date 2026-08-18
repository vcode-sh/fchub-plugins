/**
 * A single-process stand-in for "shared full-page cache in front of WordPress".
 *
 * The point is not fidelity to any one host. The point is that the cache is
 * allowed to be hostile in the exact ways this plugin has actually been bitten
 * by, as reported on issue #72:
 *
 *   1. the page cache never varies on Cookie, so a guest's currency cookie
 *      cannot influence the HTML they receive;
 *   2. the cache key ignores the query string, so `?currency=EUR` returns
 *      whatever copy was stored for the bare path;
 *   3. an optimizer defers every script, so "put it in the head" buys nothing;
 *   4. an optimizer strips CSS rules whose selectors are absent from the served
 *      HTML — which is every rule this plugin's JavaScript adds at runtime.
 *
 * Each behaviour is a flag, so a test can attribute a symptom to one of them
 * rather than to "hosting".
 *
 * The page itself is built from `.fixture/fixture.json`, which
 * `generate-fixture.php` produces from the plugin's own renderers. Nothing here
 * hand-draws markup that production also draws.
 */

import { createServer } from "node:http";
import { readFileSync, existsSync } from "node:fs";
import { extname, join, normalize } from "node:path";
import { fileURLToPath } from "node:url";

const PLUGIN_DIR = fileURLToPath(new URL("../../", import.meta.url));
const ASSET_DIR = join(PLUGIN_DIR, "assets");
const CRITICAL_CSS = readFileSync(join(ASSET_DIR, "css/currency-critical.css"), "utf8");
const BOOTSTRAP_JS = readFileSync(join(ASSET_DIR, "js/currency-bootstrap.js"), "utf8");
const FIXTURE_PATH = fileURLToPath(new URL("./.fixture/fixture.json", import.meta.url));

const MIME = {
	".js": "text/javascript; charset=utf-8",
	".mjs": "text/javascript; charset=utf-8",
	".css": "text/css; charset=utf-8",
	".svg": "image/svg+xml",
};

/**
 * An ordinary commercial theme: it brands the switcher orange and dims disabled
 * buttons. Both are unremarkable on their own.
 *
 * `disabledBackground` is the interesting knob. Plenty of themes give a disabled
 * button its own greyed background rather than only fading it, and 1.4.7 is the
 * release that started setting `disabled` on the trigger during recovery. If a
 * mid-window colour change reproduces only when that rule is present, the flicker
 * belongs to the plugin's new lock rather than to any host or optimizer.
 */
function themeCss(disabledBackground) {
	return `
:root { --theme-brand: #f06a1e; }
body { font-family: system-ui, sans-serif; margin: 0; padding: 2rem; }
.fchub-mc-switcher__trigger {
	background: var(--theme-brand);
	color: #fff;
	border: 1px solid var(--theme-brand);
	border-radius: 6px;
	padding: 0.5rem 0.75rem;
}
button:disabled {
	opacity: 0.5;
${disabledBackground ? `	background: ${disabledBackground};
	color: #333;` : ""}
}
.product { margin: 1.5rem 0; }
.fct-item-price { font-size: 1.5rem; font-weight: 700; }
`;
}

function loadFixture() {
	if (!existsSync(FIXTURE_PATH)) {
		throw new Error(
			`Fixture missing at ${FIXTURE_PATH}. Run: php tests/e2e/generate-fixture.php`,
		);
	}

	return JSON.parse(readFileSync(FIXTURE_PATH, "utf8"));
}

/**
 * Approximates an optimizer's "remove unused CSS" pass: a rule survives only if
 * every class it names appears in the served HTML. Runtime-added classes such as
 * `fchub-mc-switcher--loading` therefore do not survive, which is the behaviour
 * under test rather than an incidental detail of any product's implementation.
 */
function stripRulesUnusedInHtml(css, html) {
	const present = new Set(
		[...html.matchAll(/class="([^"]*)"/g)].flatMap((match) => match[1].split(/\s+/)).filter(Boolean),
	);

	return css.replace(/([^{}]+)\{([^{}]*)\}/g, (rule, selector) => {
		if (selector.trim().startsWith("@") || selector.includes(":root")) return rule;

		const classes = [...selector.matchAll(/\.([A-Za-z0-9_-]+)/g)].map((match) => match[1]);
		return classes.every((name) => present.has(name)) ? rule : "";
	});
}

/**
 * The storefront page WordPress would emit for a visitor resolved to `currency`,
 * with the plugin's real 1.4.7 asset order: projection stylesheet and the context
 * runtime in the head, switcher stylesheet printed late by `print_late_styles()`,
 * then the footer scripts.
 */
function renderPage({ fixture, deferScripts, stripUnusedCss, disabledBackground }) {
	const variant = fixture.page;
	const defer = deferScripts ? " defer" : "";
	const switcher = variant.switcherHtml.replaceAll(
		"http://localhost/wp-content/plugins/fchub-multi-currency/assets/",
		"/assets/",
	);
	const config = { ...variant.config, restUrl: "/wp-json/fchub-mc/v1", flagBaseUrl: "/assets/flags/4x3/" };

	const body = `
	<h1>Pricing</h1>
	<div class="switcher-slot">${switcher}</div>
	<div class="product">
		<span class="fct-item-price">$49.00</span>
	</div>
	<div class="product">
		<span class="fct-item-price">$149.00</span>
	</div>
`;

	const theme = themeCss(disabledBackground);
	const themeStyles = stripUnusedCss ? stripRulesUnusedInHtml(theme, body) : theme;

	return `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Pricing</title>
<style id="theme-css">${themeStyles}</style>
<style id="fchub-mc-critical-inline-css">${CRITICAL_CSS}</style>
<script id="fchub-mc-bootstrap-js">window.fchubMcConfig = ${JSON.stringify(config)};
${BOOTSTRAP_JS}</script>
</head>
<body>
${body}
<link rel="stylesheet" href="/assets/css/currency-switcher.css${stripUnusedCss ? "?rucss=1" : ""}">
<script src="/assets/js/currency-context.js"${defer}></script>
<script src="/assets/js/currency-projection.js"${defer}></script>
<script src="/assets/js/currency-switcher.js"${defer}></script>
</body>
</html>`;
}

/**
 * Which currency the origin would resolve for this request. Mirrors the plugin's
 * resolver priority: URL parameter first, then the guest cookie, then base. Only
 * the origin sees this; a cache hit never reaches it, which is the whole problem.
 */
function resolveRequestCurrency(fixture, url, cookieHeader) {
	const wanted = (url.searchParams.get("currency") || "").toUpperCase();
	if (fixture.currencies.includes(wanted)) return wanted;

	const match = /(?:^|;\s*)fchub_mc_currency=([^;]+)/.exec(cookieHeader || "");
	const fromCookie = decodeURIComponent(match?.[1] || "").toUpperCase();
	if (fixture.currencies.includes(fromCookie)) return fromCookie;

	return fixture.baseCurrency;
}

const sleep = (ms) => (ms > 0 ? new Promise((resolve) => setTimeout(resolve, ms)) : Promise.resolve());

/**
 * @param {object} [options]
 * @param {'ignored'|'bypassed'|'keyed'} [options.queryStringMode] How the edge treats `?currency=`.
 *   `ignored`  — the cache key drops it, so the visitor gets somebody else's copy of the bare path.
 *   `bypassed` — a query string means "do not serve from cache", so every switch pays a full origin render.
 *   `keyed`    — the query string is part of the cache key, the well-behaved case.
 * @param {boolean} [options.deferScripts] Every script tag gets `defer`. Models "Load JavaScript deferred".
 * @param {boolean} [options.stripUnusedCss] Drop CSS rules whose classes are absent from the HTML.
 * @param {number} [options.originLatencyMs] Cost of an uncached page render at the origin.
 * @param {number} [options.restLatencyMs] Cost of a REST round trip.
 * @param {string|null} [options.themeDisabledBackground] Theme colour for `button:disabled`.
 *   Set it to model a theme that greys disabled buttons rather than only fading them.
 * @param {number} [options.lateAssetLatencyMs] Delay on the late-printed switcher stylesheet only.
 *   A real sales page prints that stylesheet behind a queue of other footer assets; this is the knob
 *   that expresses "it arrives well after first paint" without pretending to model one product.
 * @param {string} [options.primeCurrency] Currency the cache was primed with before this test.
 */
export async function startHostileOrigin({
	queryStringMode = "ignored",
	deferScripts = false,
	stripUnusedCss = false,
	originLatencyMs = 0,
	restLatencyMs = 0,
	lateAssetLatencyMs = 0,
	themeDisabledBackground = null,
	primeCurrency = null,
} = {}) {
	const fixture = loadFixture();
	const pageCache = new Map();
	const requests = [];

	const server = createServer(async (req, res) => {
		const url = new URL(req.url, "http://localhost");
		const entry = { method: req.method, path: url.pathname + url.search, startedAt: Date.now() };
		requests.push(entry);

		const finish = (status, headers, payload) => {
			entry.endedAt = Date.now();
			entry.status = status;
			res.writeHead(status, headers);
			res.end(payload);
		};

		if (url.pathname.startsWith("/assets/")) {
			const path = normalize(join(ASSET_DIR, url.pathname.slice("/assets/".length)));
			if (!path.startsWith(ASSET_DIR) || !existsSync(path)) {
				entry.cacheStatus = "asset";
				return finish(404, { "Content-Type": "text/plain" }, "not found");
			}

			entry.cacheStatus = "asset";
			if (lateAssetLatencyMs > 0 && url.pathname.endsWith("/currency-switcher.css")) {
				await sleep(lateAssetLatencyMs);
			}
			let payload = readFileSync(path);
			if (stripUnusedCss && extname(path) === ".css") {
				const html = pageCache.values().next().value || "";
				payload = Buffer.from(stripRulesUnusedInHtml(payload.toString("utf8"), html), "utf8");
			}

			return finish(
				200,
				{
					"Content-Type": MIME[extname(path)] || "application/octet-stream",
					"Cache-Control": "public, max-age=31536000",
				},
				payload,
			);
		}

		if (url.pathname === "/wp-json/fchub-mc/v1/context") {
			// The REST path is never cached, so unlike the page it does receive the cookie.
			await sleep(restLatencyMs);
			entry.cacheStatus = "origin";

			if (req.method === "POST") {
				const currency = await readJsonCurrency(req);
				return finish(
					200,
					{
						"Content-Type": "application/json",
						"Cache-Control": "no-store",
						"Set-Cookie": `fchub_mc_currency=${currency}; Path=/; Max-Age=7776000; SameSite=Lax`,
					},
					JSON.stringify({
						data: {
							code: "preference_saved",
							message: "Currency preference saved.",
							currency,
							persisted: true,
						},
					}),
				);
			}

			const currency = resolveRequestCurrency(fixture, url, req.headers.cookie);
			return finish(
				200,
				{ "Content-Type": "application/json", "Cache-Control": "no-store" },
				JSON.stringify(fixture.restContext[currency]),
			);
		}

		const bypassed = queryStringMode === "bypassed" && url.search !== "";
		const key = queryStringMode === "keyed" ? url.pathname + url.search : url.pathname;
		if (bypassed || !pageCache.has(key)) {
			// Only a miss costs an origin render; that asymmetry is why the switch
			// navigating to a never-before-seen URL is expensive.
			await sleep(originLatencyMs);
			const html = renderPage({
				fixture,
				deferScripts,
				stripUnusedCss,
				disabledBackground: themeDisabledBackground,
			});
			entry.cacheStatus = "miss";
			if (!bypassed) pageCache.set(key, html);

			return finish(
				200,
				{ "Content-Type": "text/html; charset=utf-8", "Cache-Control": bypassed ? "no-store" : "public, max-age=600" },
				html,
			);
		}

		entry.cacheStatus = "hit";

		return finish(
			200,
			{ "Content-Type": "text/html; charset=utf-8", "Cache-Control": "public, max-age=600" },
			pageCache.get(key),
		);
	});

	await new Promise((resolve) => server.listen(0, "127.0.0.1", resolve));
	const origin = `http://127.0.0.1:${server.address().port}`;

	if (primeCurrency) {
		// Someone else visited first and their copy is what the cache now holds.
		pageCache.set(
			"/pricing",
			renderPage({ fixture, deferScripts, stripUnusedCss, disabledBackground: themeDisabledBackground }),
		);
	}

	const close = () => new Promise((resolve) => server.close(resolve));

	return {
		url: origin,
		fixture,
		requests,
		close,
		[Symbol.asyncDispose]: close,
		/** Origin work the visitor paid for: page misses and REST calls, never cache hits or static assets. */
		originHits: () => requests.filter((r) => r.cacheStatus === "miss" || r.cacheStatus === "origin"),
	};
}

function readJsonCurrency(req) {
	return new Promise((resolve) => {
		let raw = "";
		req.on("data", (chunk) => {
			raw += chunk;
		});
		req.on("end", () => {
			try {
				resolve(String(JSON.parse(raw).currency || "").toUpperCase());
			} catch {
				resolve("");
			}
		});
	});
}
