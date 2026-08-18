import { test, expect } from "@playwright/test";
import { writeFileSync } from "node:fs";
import { startHostileOrigin } from "./hostile-cache-server.mjs";

/**
 * The journey a visitor actually experiences: click a currency, wait until the
 * page is telling the truth in that currency.
 *
 * Earlier measurements for issue #72 timed a fragment of this — an already-loaded
 * document, from the start of one recovery request to the corrected paint — and
 * reported the result as evidence about the whole thing. It was not. This lane
 * starts the stopwatch at the click and stops it at the corrected price, because
 * that is the only interval a visitor can perceive.
 */

/** A slow origin behind a fast cache is why the site is cached at all. */
const ORIGIN_LATENCY_MS = 1200;
/** A REST round trip on a shared host, uncached by definition. */
const REST_LATENCY_MS = 400;

/**
 * The budget. Nothing about switching a currency requires a server: the browser
 * already knows what the visitor picked. One second is generous.
 */
const CLICK_TO_STABLE_BUDGET_MS = 1000;

async function report(testInfo, name, payload) {
	const path = testInfo.outputPath(`${name}.json`);
	writeFileSync(path, JSON.stringify(payload, null, 2));
	await testInfo.attach(name, { path, contentType: "application/json" });
	console.log(`\n--- ${name} ---\n${JSON.stringify(payload, null, 2)}\n`);
}

async function switchToEuro(page) {
	await page.locator("[data-fchub-mc-trigger]").click();
	await page.locator("[role='option'][data-value='EUR']").click();
	await expect(page.locator(".fct-item-price").first()).toHaveText(/€/);
}

/**
 * How an edge may treat the `?currency=` parameter the switcher navigates to.
 * The three cases cost wildly different amounts, so the lane measures all three
 * rather than assuming which one a given host implements.
 */
const QUERY_MODES = [
	{
		mode: "ignored",
		note: "cache key drops the query, so the visitor is served somebody else's copy",
	},
	{
		mode: "bypassed",
		note: "a query string disables the cache, so every switch pays a full origin render",
	},
	{ mode: "keyed", note: "the query string is part of the cache key, the well-behaved case" },
];

for (const { mode, note } of QUERY_MODES) {
	test(`guest switch cost when the edge has ?currency= ${mode}`, async ({ page, context }, testInfo) => {
		await using origin = await startHostileOrigin({
			queryStringMode: mode,
			deferScripts: true,
			originLatencyMs: ORIGIN_LATENCY_MS,
			restLatencyMs: REST_LATENCY_MS,
		});
		const har = await context.tracing.startHar(testInfo.outputPath("journey.har"));

		// Someone else already primed the cache, which is the normal state of a
		// cached sales page and the reason the visitor's own cookie never gets a say.
		await page.goto(`${origin.url}/pricing`, { waitUntil: "load" });
		await expect(page.locator(".fct-item-price").first()).toHaveText(/\$/);

		const journeyFrom = origin.requests.length;
		const started = Date.now();
		await switchToEuro(page);
		const clickToStableMs = Date.now() - started;

		const journey = origin.requests.slice(journeyFrom);
		const originHits = journey.filter((r) => r.cacheStatus === "miss" || r.cacheStatus === "origin");

		await har.dispose();
		await report(testInfo, `journey-${mode}`, {
			queryStringMode: mode,
			note,
			clickToStableMs,
			budgetMs: CLICK_TO_STABLE_BUDGET_MS,
			originHits: originHits.length,
			originLatencyMs: ORIGIN_LATENCY_MS,
			restLatencyMs: REST_LATENCY_MS,
			requests: journey.map((r) => ({
				method: r.method,
				path: r.path,
				cache: r.cacheStatus,
				ms: r.endedAt - r.startedAt,
			})),
		});

		expect(
			originHits.length,
			"A currency switch should cost the origin nothing: the browser already knows the choice.",
		).toBe(0);
		expect(clickToStableMs).toBeLessThan(CLICK_TO_STABLE_BUDGET_MS);
	});
}

/**
 * Samples what the visitor can see, once per frame. A frame sampler rather than a
 * MutationObserver, because the failure under investigation is a stylesheet
 * arriving late: computed style changes with no DOM mutation to observe.
 */
function installPaintRecorder(page) {
	return page.addInitScript(() => {
		window.__fchubTimeline = [];
		const start = performance.now();
		const keys = ["price", "priceVisible", "trigger", "background", "opacity", "disabled"];

		const sample = () => {
			const price = document.querySelector(".fct-item-price");
			const trigger = document.querySelector("[data-fchub-mc-trigger]");
			if (price || trigger) {
				const triggerStyle = trigger ? getComputedStyle(trigger) : null;
				const priceStyle = price ? getComputedStyle(price) : null;
				const frame = {
					t: Math.round(performance.now() - start),
					price: price ? price.textContent.trim() : null,
					priceVisible: priceStyle ? priceStyle.visibility !== "hidden" : null,
					trigger: trigger ? trigger.textContent.replace(/\s+/g, " ").trim() : null,
					background: triggerStyle ? triggerStyle.backgroundColor : null,
					opacity: triggerStyle ? triggerStyle.opacity : null,
					disabled: trigger ? trigger.disabled === true : null,
				};

				const previous = window.__fchubTimeline.at(-1);
				if (!previous || keys.some((key) => previous[key] !== frame[key])) {
					window.__fchubTimeline.push(frame);
				}
			}

			requestAnimationFrame(sample);
		};

		requestAnimationFrame(sample);
	});
}

/** Distinct visual treatments of the switcher across the window, in order. */
function visualStates(timeline) {
	const states = [];
	for (const frame of timeline) {
		const key = `${frame.background} @ ${frame.opacity}`;
		if (states.at(-1) !== key) states.push(key);
	}

	return states;
}

/**
 * The reported symptom is that the lock changes appearance part-way through the
 * correction window. Three candidate conditions, run side by side so the timeline
 * attributes the change to one of them instead of to "hosting":
 *
 *   control  — nothing unusual; anything visible here is the plugin on its own.
 *   late-css — the switcher stylesheet, which WordPress prints late, arrives after
 *              first paint. Note this can only bite a first-time visitor: on the
 *              post-switch navigation the browser already holds the file.
 *   theme    — the theme gives disabled buttons their own background, and 1.4.7 is
 *              the release that started setting `disabled` on the trigger.
 */
const PAINT_CASES = [
	{ name: "control", lateAssetLatencyMs: 0, themeDisabledBackground: null },
	{ name: "late switcher stylesheet", lateAssetLatencyMs: 600, themeDisabledBackground: null },
	{ name: "theme greys disabled buttons", lateAssetLatencyMs: 0, themeDisabledBackground: "#e9e9ed" },
];

for (const { name, lateAssetLatencyMs, themeDisabledBackground } of PAINT_CASES) {
	test(`switcher keeps one appearance through the correction window — ${name}`, async ({
		page,
	}, testInfo) => {
		await using origin = await startHostileOrigin({
			queryStringMode: "ignored",
			deferScripts: true,
			originLatencyMs: ORIGIN_LATENCY_MS,
			restLatencyMs: REST_LATENCY_MS,
			lateAssetLatencyMs,
			themeDisabledBackground,
		});

		await installPaintRecorder(page);
		await page.goto(`${origin.url}/pricing`, { waitUntil: "load" });
		await expect(page.locator(".fct-item-price").first()).toHaveText(/\$/);

		await switchToEuro(page);

		// addInitScript reinstalls the recorder on the post-switch document, so this
		// timeline covers exactly the window the reporter described.
		const timeline = await page.evaluate(() => window.__fchubTimeline);
		const states = visualStates(timeline);
		const stalePaints = timeline.filter((frame) => frame.priceVisible && frame.price?.includes("$"));

		await report(testInfo, `paint-timeline-${name.replaceAll(" ", "-")}`, {
			case: name,
			lateAssetLatencyMs,
			themeDisabledBackground,
			distinctVisualStates: states,
			stalePaints: stalePaints.length,
			timeline,
		});

		expect(stalePaints, "No frame after the switch may show a readable pre-switch amount.").toHaveLength(
			0,
		);
		expect(
			states.length,
			`The switcher should look locked, then settled — not ${states.length} ways: ${states.join(" -> ")}`,
		).toBeLessThanOrEqual(2);
	});
}

/**
 * A design assertion, free of any optimizer model: every CSS rule the plugin needs
 * during the correction window keys off a class that its own JavaScript adds at
 * runtime, so those classes are absent from the served HTML. Any tool that
 * computes "used CSS" from the document — and every serious performance plugin
 * offers one — is entitled to drop them, leaving the lock invisible.
 *
 * This is a fragility finding, not the cause of the reported flicker: the paint
 * timelines above show the colour change comes from the trigger's `disabled`
 * attribute meeting a theme rule, with no optimizer involved. Both want the same
 * repair — critical state should not ride on a deferrable stylesheet.
 */
test("critical-window CSS does not depend on classes missing from the served HTML", async ({
	request,
}, testInfo) => {
	await using origin = await startHostileOrigin({ queryStringMode: "ignored" });

	const html = await (await request.get(`${origin.url}/pricing`)).text();
	const served = new Set(
		[...html.matchAll(/class="([^"]*)"/g)].flatMap((match) => match[1].split(/\s+/)).filter(Boolean),
	);

	const sheets = {
		"currency-switcher.css": await (
			await request.get(`${origin.url}/assets/css/currency-switcher.css`)
		).text(),
		"currency-projection.css": await (
			await request.get(`${origin.url}/assets/css/currency-projection.css`)
		).text(),
	};

	// Only the runtime state classes are the point. A price class missing from this
	// minimal page is an artefact of the fixture, not a fragility in the plugin.
	const stateClasses = [
		"fchub-mc-switcher--loading",
		"fchub-mc-selector-buttons--loading",
		"fchub-mc-recovering",
		"fchub-mc-projecting",
	];
	const orphans = [];
	for (const [sheet, source] of Object.entries(sheets)) {
		const css = source.replace(/\/\*[\s\S]*?\*\//g, "");
		for (const [, selector] of css.matchAll(/([^{}]+)\{[^{}]*\}/g)) {
			for (const cls of stateClasses) {
				if (!selector.includes(`.${cls}`) || served.has(cls)) continue;
				orphans.push({ sheet, selector: selector.replace(/\s+/g, " ").trim().slice(0, 90), missingClass: cls });
			}
		}
	}

	await report(testInfo, "critical-css-orphans", {
		servedClassCount: served.size,
		orphanCount: orphans.length,
		orphans,
	});

	expect(
		orphans,
		"Critical-window rules must not key off classes absent from the HTML; a used-CSS pass may drop them.",
	).toHaveLength(0);
});
