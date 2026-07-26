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
    for (const phpVersion of phpVersions) {
      assert.match(
        phpunit,
        new RegExp(
          `- plugin: ${slug}\\n\\s+php_version: '${phpVersion.replace(".", "\\.")}'\\n\\s+fetch_depth: 0`,
        ),
        `Missing ${slug} PHP ${phpVersion} matrix entry`,
      );
    }
  }
  for (const slug of targetSlugs) {
    assert.doesNotMatch(
      phpunit,
      new RegExp(`- plugin: ${slug}\\n\\s+php_version: '8\\.3'`),
      `${slug} cannot install PHPUnit 13 on PHP 8.3`,
    );
  }
  const streamEntries = phpunit.match(/- plugin: fchub-stream[\s\S]*?fetch_depth: 1/g);
  assert.equal(
    streamEntries?.length,
    1,
    "The reversible-hiatus marker must remain a single inert depth-1 entry",
  );
  assert.doesNotMatch(
    phpunit,
    /- plugin: fchub-stream\n\s+php_version: '8\.[45]'/,
  );
});

test("static checks run on PHP 8.5 for every target that defines them", () => {
  const phpunit = job(ci, "phpunit");
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
    assert.match(packages, new RegExp(`- plugin: ${slug}(?:\\n|$)`));
  }
  assert.match(packages, /bash build\.sh "\$\{\{ matrix\.plugin \}\}"/);
  assert.match(build, /scripts\/wporg\/check-package\.mjs/);
  assert.match(packages, /upload-artifact@v4/);
  assert.match(packages, /if: failure\(\)/);
});

test("WordPress.org targets never receive the shared updater", () => {
  for (const slug of targetSlugs) {
    assert.match(build, new RegExp(`"${slug}"`));
  }
  assert.match(build, /is_wordpress_org_plugin/);

  const syncStep = release.match(
    /- name: Sync GitHubUpdater[\s\S]*?(?=\n      - name:)/,
  )?.[0];
  assert.ok(syncStep, "Release workflow must retain updater sync for non-targets");
  const tagClassifier = release.match(
    /- name: Parse tag[\s\S]*?(?=\n      - name:)/,
  )?.[0];
  assert.ok(tagClassifier, "Release workflow must classify WordPress.org tags");
  assert.match(tagClassifier, /wporg\/plugins\.json/);
  assert.match(tagClassifier, /Object\.hasOwn\(manifest\.plugins/);
  assert.match(syncStep, /needs\.prepare\.outputs\.is_wporg != 'true'/);
});

test("Stream is absent from WordPress.org package matrices and path triggers", () => {
  assert.doesNotMatch(job(ci, "wporg-package"), /fchub-stream/);
  const pullRequestPaths = ci.match(
    /pull_request:\n\s+paths:\n([\s\S]*?)(?=\n\njobs:)/,
  )?.[1];
  assert.ok(pullRequestPaths);
  assert.doesNotMatch(pullRequestPaths, /fchub-stream/);
});
