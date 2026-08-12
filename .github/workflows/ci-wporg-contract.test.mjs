import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

const ci = readFileSync(new URL("./ci.yml", import.meta.url), "utf8");
const release = readFileSync(new URL("./release.yml", import.meta.url), "utf8");
const releaseGates = readFileSync(
  new URL("./wporg-release-gates.yml", import.meta.url),
  "utf8",
);
const build = readFileSync(new URL("../../build.sh", import.meta.url), "utf8");
const scope = readFileSync(new URL("../../scripts/ci-scope.mjs", import.meta.url), "utf8");

const targetSlugs = [
  "fchub-fakturownia",
  "fchub-memberships",
  "fchub-multi-currency",
  "fchub-p24",
  "fchub-wishlist",
];
const phpVersions = ["8.4", "8.5"];

function job(workflow, name) {
  const match = workflow.match(
    new RegExp(
      `^  ${name}:\\n([\\s\\S]*?)(?=^  [A-Za-z0-9_-]+:\\n|(?![\\s\\S]))`,
      "m",
    ),
  );
  assert.ok(match, `Expected ${name} job`);
  return match[1];
}

test("PHPUnit matrix covers every target on the PHPUnit 13 PHP runtimes", () => {
  const phpunit = job(ci, "phpunit");
  for (const slug of targetSlugs) {
    assert.match(scope, new RegExp(`'${slug}'`));
  }
  assert.match(phpunit, /plugin: \$\{\{ fromJSON\(needs\.changes\.outputs\.php_plugins\) \}\}/);
  assert.match(phpunit, /php_version: \['8\.4', '8\.5'\]/);
  assert.doesNotMatch(phpunit, /8\.3/, "PHPUnit 13 requires PHP 8.4.1 or newer");
  // Stream is abandoned. It used to sit here as an inert depth-1 lane proving
  // the hiatus was reversible; keeping a lane for a plugin nobody will pick up
  // again is a job slot and a matrix entry spent on nothing.
  assert.doesNotMatch(
    phpunit,
    /fchub-stream/,
    "Stream is abandoned and must not appear in the PHPUnit matrix",
  );
});

test("static checks run on PHP 8.5 for every target that defines them", () => {
  const phpunit = job(ci, "phpunit");
  assert.match(phpunit, /name: Install pinned static analysis dependencies/);
  assert.match(
    phpunit,
    /bash scripts\/wporg\/install-static-analysis-dependencies\.sh "\$\{\{\s*matrix\.plugin\s*\}\}"/,
  );
  assert.ok(
    phpunit.indexOf("install-static-analysis-dependencies.sh") <
      phpunit.indexOf("name: Run static quality checks"),
    "Pinned external sources must exist before pull-request static analysis",
  );
  const staticStep = phpunit.match(
    /- name: Run static quality checks[\s\S]*?(?=\n      - name:)/,
  )?.[0];
  assert.ok(staticStep);
  assert.match(staticStep, /matrix\.php_version == '8\.5'/);
  for (const slug of targetSlugs.filter((slug) => slug !== "fchub-memberships")) {
    assert.match(staticStep, new RegExp(slug));
  }
  assert.doesNotMatch(staticStep, /fchub-memberships/);
  assert.match(phpunit, /run: composer check/);
});

test("disposable release lifecycles retain the PHP 8.3 runtime floor", () => {
  const runtime = job(releaseGates, "runtime");
  for (const version of ["8.3", "8.4", "8.5"]) {
    assert.match(runtime, new RegExp(`- '${version.replace(".", "\\.")}'`));
  }
  assert.match(runtime, /wordpress:7\.0\.2-php\$\{\{\s*matrix\.php_version\s*\}\}-apache/);
});

test("directory asset and approval contracts run in CI", () => {
  const contracts = job(ci, "workflow-contract");
  assert.match(contracts, /wporg-directory-assets-contract\.test\.mjs/);
  assert.match(contracts, /wporg-directory-screenshots-contract\.test\.mjs/);
  assert.match(contracts, /wporg-asset-approval-contract\.test\.mjs/);
});

test("genuine previous-release and update lifecycle contracts run in CI", () => {
  const contracts = job(ci, "workflow-contract");
  assert.match(contracts, /wporg-metadata-contract\.test\.mjs/);
  assert.match(contracts, /wporg-previous-release-contract\.test\.mjs/);
  assert.match(contracts, /wporg-lifecycle-update-contract\.test\.mjs/);

  const runtime = job(releaseGates, "runtime");
  assert.match(runtime, /previous_zip_path/);
  assert.match(runtime, /scripts\/wporg\/run-lifecycle\.sh/);
});

test("every target builds and inspects its WordPress.org archive", () => {
  const packages = job(ci, "wporg-package");
  for (const slug of targetSlugs) {
    assert.match(scope, new RegExp(`'${slug}'`));
  }
  assert.match(packages, /needs: changes/);
  assert.match(packages, /if: needs\.changes\.outputs\.wporg_plugins != '\[\]'/);
  assert.match(packages, /plugin: \$\{\{ fromJSON\(needs\.changes\.outputs\.wporg_plugins\) \}\}/);
  assert.match(packages, /bash build\.sh "\$\{\{ matrix\.plugin \}\}"/);
  assert.match(build, /scripts\/wporg\/check-package\.mjs/);
  assert.match(packages, /upload-artifact@v7/);
  assert.match(packages, /if: failure\(\)/);
});

test("WordPress.org targets never receive the shared updater", () => {
  for (const slug of targetSlugs) {
    assert.match(build, new RegExp(`"${slug}"`));
  }
  assert.match(build, /is_wordpress_org_plugin/);

  assert.doesNotMatch(release, /- name: Sync GitHubUpdater/);
  const tagClassifier = release.match(
    /- name: Parse tag[\s\S]*?(?=\n      - name:)/,
  )?.[0];
  assert.ok(tagClassifier, "Release workflow must classify WordPress.org tags");
  assert.match(tagClassifier, /wporg\/plugins\.json/);
  assert.match(tagClassifier, /Object\.hasOwn\(manifest\.plugins/);
  assert.match(build, /updater_sync_count/);
});

test("Stream is excluded from the path triggers, in the order that makes it work", () => {
  assert.doesNotMatch(job(ci, "wporg-package"), /fchub-stream/);

  const pullRequestPaths = ci.match(
    /pull_request:\n\s+paths:\n((?:\s+- .*\n)+)/,
  )?.[1];
  assert.ok(pullRequestPaths, "Expected a paths filter on the pull request trigger");

  const entries = [...pullRequestPaths.matchAll(/^\s+- '([^']+)'$/gm)].map((m) => m[1]);
  assert.ok(entries.length > 0, "Expected quoted path entries");

  assert.deepEqual(
    entries.filter((e) => !e.startsWith("!") && e.includes("fchub-stream")),
    [],
    "Stream is abandoned: nothing may include it",
  );

  // GitHub applies these in sequence — a negative pattern excludes only what an
  // earlier positive one matched, and a later positive one puts it back. So the
  // exclusion has to sit after plugins/**, and nothing under plugins/ may follow
  // it. Assert the ordering, not merely the presence: a correct-looking list in
  // the wrong order silently runs the whole matrix on every Stream commit.
  const wildcard = entries.indexOf("plugins/**");
  const exclusion = entries.indexOf("!plugins/fchub-stream/**");
  assert.notEqual(wildcard, -1, "Expected the plugins/** trigger");
  assert.notEqual(exclusion, -1, "Expected Stream to be excluded from plugins/**");
  assert.ok(exclusion > wildcard, "the exclusion must follow the pattern it narrows");
  assert.deepEqual(
    entries.slice(exclusion + 1).filter((e) => e.startsWith("plugins/")),
    [],
    "a later positive plugins/ pattern would re-include Stream",
  );
});
