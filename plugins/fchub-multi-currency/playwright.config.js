import { defineConfig, devices } from "@playwright/test";

/**
 * The browser lane for the guest currency journey.
 *
 * It exists because every other suite in this plugin runs against a fake DOM and
 * therefore cannot see stylesheet order, paint, navigation or round trips — the
 * four things issue #72 turned out to be about.
 *
 * Timing is an assertion here, so the lane runs single-worker and unparallelised.
 * A measurement taken while three other browsers fight for the same CPU is not a
 * measurement.
 */
export default defineConfig({
	testDir: "./tests/e2e",
	testMatch: "**/*.spec.mjs",
	outputDir: "./tests/e2e/.output",
	globalSetup: "./tests/e2e/global-setup.mjs",
	fullyParallel: false,
	workers: 1,
	forbidOnly: !!process.env.CI,
	retries: 0,
	timeout: 60_000,
	expect: { timeout: 20_000 },
	reporter: [["list"], ["html", { outputFolder: "./tests/e2e/.report", open: "never" }]],
	use: {
		...devices["Desktop Chrome"],
		trace: "retain-on-failure",
		video: "off",
	},
});
