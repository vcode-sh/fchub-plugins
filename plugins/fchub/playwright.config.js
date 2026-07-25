import { defineConfig } from '@playwright/test'

/**
 * The browser layer for FCHub's admin interface.
 *
 * Everything here is served from disk: the built bundle out of `assets/dist`,
 * every REST response out of `tests/e2e/fixtures`. No network, no WordPress, no
 * Docker, and no long-lived playground — the suite is worthless as evidence if
 * it depends on what happens to be installed on somebody's machine today.
 */
export default defineConfig({
  testDir: './tests/e2e',

  // Deterministic on purpose. A retry that turns red into green is not a pass,
  // it is a flaky test wearing a disguise, and this suite exists to be trusted.
  retries: 0,
  forbidOnly: Boolean(process.env.CI),
  fullyParallel: true,
  reporter: [['list']],

  // Screenshots live beside the spec rather than in a directory named after it.
  //
  // The platform suffix is Playwright's own, and both sets are committed:
  // `-darwin` for local work, `-linux` for CI. Chromium rasterises text
  // differently on the two, down to fractional glyph metrics that move a line
  // break, so one set cannot stand in for the other and any tolerance loose
  // enough to bridge them would be loose enough to hide a layout change.
  //
  // Regenerate macOS:
  //   npx playwright test --update-snapshots=changed
  //
  // Regenerate Linux — in the image matching the pinned @playwright/test, so
  // the bytes come from the same Chromium build CI uses (never `:latest`):
  //   docker run --rm --ipc=host -v "$PWD":/work -w /work \
  //     mcr.microsoft.com/playwright:v1.55.1-noble \
  //     npx playwright test --update-snapshots=changed
  //
  // Use `=missing` instead of `=changed` when adding a platform from scratch,
  // so an existing set cannot be overwritten by accident. Both runs mount the
  // plugin directory and need no network: the browsers ship in the image and
  // every response comes from tests/e2e/fixtures.
  //
  // The container runs `npx playwright test`, never `npm run test:smoke`. That
  // is deliberate twice over: the Linux baselines must come from the same
  // bundle the macOS ones did rather than a rebuild, and the mounted
  // node_modules was installed for the host's platform, so `vite build` in
  // there dies on a missing rolldown native binding. Build on the host; compare
  // in the container.
  snapshotPathTemplate: 'tests/e2e/screenshots/{arg}{-projectName}{-snapshotSuffix}{ext}',

  expect: {
    timeout: 5000,
    toHaveScreenshot: {
      animations: 'disabled',
      caret: 'hide',
      scale: 'css',
      // Zero pixels may differ. Animations are off, the font is bundled and
      // awaited, the clock is never rendered and the timezone is pinned, so two
      // runs of the same fixture on the same platform produce the same bytes.
      maxDiffPixels: 0,
      // …but "differ" is decided by `threshold`, and this is Playwright's
      // default rather than a considered number. It is a per-pixel perceptual
      // tolerance in YIQ space, so `maxDiffPixels: 0` does not mean byte-exact:
      // it means no pixel may differ by MORE than this. Two pale pastels can sit
      // inside it. Swapping a card note from #FFEBF4 to #FFF3EB — a deliberate
      // severity change — passed sixteen screenshots without one of them
      // noticing, which is why the note tones are now asserted as computed
      // colours in admin-ui.spec.js as well.
      //
      // Dropping this to 0 would make the comparison genuinely byte-exact. It
      // is left alone here because the committed Linux baselines are produced in
      // mcr.microsoft.com/playwright:v1.55.1-noble while CI compares them from
      // an ubuntu-latest runner with its own font stack, and nobody has
      // established that those two agree to the byte. Worth establishing, then
      // worth tightening.
      threshold: 0.2,
    },
  },

  use: {
    baseURL: 'http://127.0.0.1:4173',
    headless: true,
    viewport: { width: 1440, height: 1000 },
    deviceScaleFactor: 1,
    // Pinned, because SystemPage formats `last_refresh` through Intl and an
    // unpinned timezone would move "Last checked" by an hour somewhere in
    // Europe and by a day somewhere in New Zealand.
    locale: 'en-US',
    timezoneId: 'UTC',
    colorScheme: 'light',
    trace: 'retain-on-failure',
  },

  projects: [
    {
      name: 'smoke',
      // Named exactly, not globbed. tests/e2e/fchub-lifecycle.spec.js lives in
      // this same directory and wants Docker and a couple of minutes;
      // `npm run test:smoke` must keep wanting neither. The project that runs
      // it is gated below, so a bare `playwright test` cannot pick it up
      // either.
      testMatch: /admin-ui\.spec\.js$/,
    },

    // The disposable-WordPress suite. Not run by name alone — tests/e2e/
    // run-lifecycle.sh sets FCHUB_LIFECYCLE along with the base URL, the
    // Compose project and the fixture directory the spec needs, and running it
    // without those is a failure with an explanation rather than a mystery.
    //
    //   bash tests/e2e/run-lifecycle.sh
    ...(process.env.FCHUB_LIFECYCLE
      ? [
          {
            name: 'lifecycle',
            testMatch: /fchub-lifecycle\.spec\.js$/,
            // Nine serial steps, two real plugin installs and a container
            // stack that may still be warming up. The per-step budget is
            // generous on purpose; the assertions inside it are not.
            timeout: 10 * 60 * 1000,
            expect: { timeout: 15000 },
            use: {
              baseURL: process.env.FCHUB_LIFECYCLE_BASE_URL,
              navigationTimeout: 60000,
              // Tracing off, and not as a preference.
              //
              // With the inherited `retain-on-failure`, a failing test in this
              // suite writes a truncated trace.zip and then never returns: the
              // worker sits at zero CPU until the global timeout kills the run,
              // and the failure is never reported — so the one thing a red run
              // owes you, the reason it went red, is exactly what you do not
              // get. Reproduced on Playwright 1.55.1 with `--trace=off` clean
              // and `--trace=retain-on-failure` hung, output directory inside
              // the project and outside it alike.
              //
              // A screenshot covers the same ground for a browser step and
              // costs one CDP round trip. The WP-CLI assertions, which are most
              // of this suite, print their own evidence either way.
              trace: 'off',
              screenshot: 'only-on-failure',
              video: 'off',
            },
          },
        ]
      : []),
  ],

  // The smoke host serves the built bundle to admin-ui.spec.js. A lifecycle run
  // talks to a real WordPress in Docker, has no use for it, and has no business
  // failing because port 4173 happened to be occupied — so it is not started.
  webServer: process.env.FCHUB_LIFECYCLE ? undefined : {
    command: 'node smoke/server.js',
    // The identity route, not the smoke host itself. `reuseExistingServer`
    // will happily adopt whatever is already listening on 4173 — including a
    // `node smoke/server.js` left running from another checkout, whose build
    // and fixtures are somebody else's. Waiting on a route only this host
    // serves turns that into a failed wait, and `the smoke host is this
    // checkout` turns a *different clone of the same code* into a named
    // failure rather than sixteen baselines mismatching for no visible reason.
    url: 'http://127.0.0.1:4173/__smoke/identity',
    reuseExistingServer: !process.env.CI,
    stdout: 'ignore',
    stderr: 'pipe',
  },
})
