import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

const release = readFileSync(new URL("./release.yml", import.meta.url), "utf8");
const ci = readFileSync(new URL("./ci.yml", import.meta.url), "utf8");
const gates = readFileSync(
  new URL("./wporg-release-gates.yml", import.meta.url),
  "utf8",
);
const sourceGates = readFileSync(
  new URL("../../scripts/wporg/run-source-gates.sh", import.meta.url),
  "utf8",
);
const staticAnalysisDependencies = readFileSync(
  new URL(
    "../../scripts/wporg/install-static-analysis-dependencies.sh",
    import.meta.url,
  ),
  "utf8",
);
const targetSlugs = [
  "fchub-fakturownia",
  "fchub-memberships",
  "fchub-multi-currency",
  "fchub-p24",
  "fchub-wishlist",
];

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

test("WordPress.org tags call the reusable gate workflow before publication", () => {
  const prepare = job(release, "prepare");
  const wporgGates = job(release, "wporg-gates");
  const publish = job(release, "release");

  assert.match(prepare, /actions\/checkout@v7/);
  assert.match(prepare, /fetch-depth:\s*0/);
  assert.match(prepare, /slug:\s*\$\{\{\s*steps\.tag\.outputs\.slug\s*\}\}/);
  assert.match(prepare, /version:\s*\$\{\{\s*steps\.tag\.outputs\.version\s*\}\}/);
  assert.match(prepare, /is_wporg:\s*\$\{\{\s*steps\.tag\.outputs\.is_wporg\s*\}\}/);

  assert.match(wporgGates, /needs:\s*prepare/);
  assert.match(
    wporgGates,
    /if:\s*needs\.prepare\.outputs\.is_wporg == 'true'/,
  );
  assert.match(
    wporgGates,
    /uses:\s*\.\/\.github\/workflows\/wporg-release-gates\.yml/,
  );
  assert.match(
    wporgGates,
    /tagged_slug:\s*\$\{\{\s*needs\.prepare\.outputs\.slug\s*\}\}/,
  );
  assert.match(
    wporgGates,
    /tagged_version:\s*\$\{\{\s*needs\.prepare\.outputs\.version\s*\}\}/,
  );

  assert.match(publish, /needs:\s*\[prepare,\s*wporg-gates,\s*cartshift-contract\]/);
  assert.match(publish, /needs\.wporg-gates\.result == 'success'/);
  assert.match(publish, /needs\.wporg-gates\.result == 'skipped'/);
  assert.match(publish, /actions\/download-artifact@v8/);
  assert.match(
    publish,
    /name:\s*wporg-package-\$\{\{\s*needs\.prepare\.outputs\.slug\s*\}\}/,
  );
  assert.match(publish, /gh release create/);

  assert.ok(
    release.indexOf("wporg-gates:") < release.indexOf("  release:"),
    "The publication job must be declared after its WordPress.org gate",
  );
});

test("the called workflow packages and inspects every target on the caller tag SHA", () => {
  assert.match(gates, /workflow_call:/);
  const packages = job(gates, "package");

  for (const slug of targetSlugs) {
    assert.match(packages, new RegExp(`- ${slug}(?:\\n|$)`));
  }
  assert.doesNotMatch(packages, /fchub-stream/);
  assert.match(packages, /actions\/checkout@v7/);
  assert.match(packages, /fetch-depth:\s*0/);
  assert.doesNotMatch(packages, /ref:\s*(?:main|master)/);
  assert.match(packages, /needs:\s*\[source,\s*memberships-frontend\]/);
  assert.match(packages, /bash build\.sh "\$\{\{\s*matrix\.plugin\s*\}\}"/);
  assert.match(packages, /scripts\/wporg\/check-package\.mjs/);
  assert.match(packages, /scripts\/wporg\/run-readme-validator\.mjs/);
  assert.match(packages, /scripts\/wporg\/fetch-previous-release\.mjs/);
  assert.match(
    packages,
    /dist\/previous\/\$\{\{\s*matrix\.plugin\s*\}\}-\*\.zip/,
  );
  assert.match(packages, /actions\/upload-artifact@v7/);
});

test("source gates run the applicable PHP and Memberships frontend suites", () => {
  const source = job(gates, "source");
  const frontend = job(gates, "memberships-frontend");

  for (const slug of targetSlugs) {
    assert.match(source, new RegExp(`- ${slug}(?:\\n|$)`));
  }
  for (const version of ["8.4", "8.5"]) {
    assert.match(source, new RegExp(`- '${version.replace(".", "\\.")}'`));
  }
  assert.doesNotMatch(
    source,
    /- '8\.3'/,
    "PHPUnit 13 cannot execute on the PHP 8.3 runtime floor",
  );
  assert.match(
    source,
    /php-version:\s*\$\{\{\s*matrix\.php_version\s*\}\}/,
  );
  assert.match(source, /composer validate --strict --no-interaction/);
  assert.match(source, /composer install --no-interaction --prefer-dist/);
  assert.match(source, /composer audit --locked --no-interaction/);
  assert.match(source, /name: Install pinned static analysis dependencies/);
  assert.match(
    source,
    /bash scripts\/wporg\/install-static-analysis-dependencies\.sh "\$\{\{\s*matrix\.plugin\s*\}\}"/,
  );
  assert.ok(
    source.indexOf("install-static-analysis-dependencies.sh") <
      source.indexOf("run-source-gates.sh"),
    "Pinned external sources must exist before the PHP 8.5 static gate",
  );
  assert.match(staticAnalysisDependencies, /fluent-cart \\\n+\s+1\.6\.0 \\\n+\s+1c8463feee35527cdd344aa87558afa8cd0e9de07e1a5e14f4e1e8984784e6b4/);
  assert.match(staticAnalysisDependencies, /fluent-crm \\\n+\s+3\.1\.10 \\\n+\s+d38ccfaf9f59cc8b40f964c4b706de1c779347f305b7ade32943681f9aec99b2/);
  assert.match(staticAnalysisDependencies, /--proto '=https'/);
  assert.match(staticAnalysisDependencies, /verify_checksum/);
  assert.match(
    source,
    /bash scripts\/wporg\/run-source-gates\.sh "\$\{\{\s*matrix\.plugin\s*\}\}" "\$\{\{\s*matrix\.php_version\s*\}\}"/,
  );

  for (const slug of targetSlugs.filter((slug) => slug !== "fchub-memberships")) {
    assert.match(sourceGates, new RegExp(`${slug.replaceAll("-", "\\-")}`));
  }
  assert.match(sourceGates, /composer check/);
  assert.match(sourceGates, /fchub-memberships[\s\S]*composer test/);
  assert.match(sourceGates, /php_lane.*8\.5[\s\S]*composer check/);

  assert.match(frontend, /working-directory:\s*plugins\/fchub-memberships/);
  assert.match(frontend, /npm ci/);
  assert.match(frontend, /npm audit --audit-level=high/);
  assert.match(frontend, /npm test/);
  assert.match(frontend, /npx playwright install --with-deps chromium/);
  assert.match(frontend, /npm run test:smoke/);
  assert.match(frontend, /npm run build/);
});

test("the tag SHA runs five Plugin Check lanes and fifteen lifecycle lanes", () => {
  const runtime = job(gates, "runtime");

  assert.match(runtime, /needs:\s*package/);
  for (const slug of targetSlugs) {
    assert.match(runtime, new RegExp(`- ${slug}(?:\\n|$)`));
  }
  for (const version of ["8.3", "8.4", "8.5"]) {
    assert.match(runtime, new RegExp(`- '${version.replace(".", "\\.")}'`));
  }
  assert.doesNotMatch(runtime, /fchub-stream/);
  assert.match(runtime, /actions\/download-artifact@v8/);
  assert.match(runtime, /scripts\/wporg\/run-lifecycle\.sh/);
  assert.match(runtime, /previous_zip_path=\$PREVIOUS_ZIP_PATH/);
  assert.match(
    runtime,
    /\$\{\{\s*steps\.package\.outputs\.previous_zip_path\s*\}\}/,
  );
  assert.match(runtime, /wordpress:7\.0\.2-php\$\{\{\s*matrix\.php_version\s*\}\}-apache/);
  assert.match(runtime, /wordpress:cli-php\$\{\{\s*matrix\.php_version\s*\}\}/);
  assert.match(
    runtime,
    /if:\s*matrix\.php_version == '8\.5'[\s\S]*scripts\/wporg\/run-plugin-check\.sh/,
  );
});

test("release publication cannot bypass a failed target gate", () => {
  const publish = job(release, "release");
  assert.doesNotMatch(publish, /continue-on-error:\s*true/);
  assert.match(
    publish,
    /needs\.prepare\.result == 'success'[\s\S]*needs\.wporg-gates\.result == 'success'[\s\S]*needs\.wporg-gates\.result == 'skipped'/,
  );
});

test("pull requests validate every release-gate workflow input and contract", () => {
  for (const path of [
    ".github/workflows/release-wporg-gates-contract.test.mjs",
    ".github/workflows/wporg-release-gates.yml",
  ]) {
    assert.match(
      ci,
      new RegExp(path.replaceAll(".", "\\.").replaceAll("/", "\\/")),
      `CI must watch ${path}`,
    );
  }
  assert.match(
    ci,
    /node --test [^\n]*release-wporg-gates-contract\.test\.mjs/,
  );
});
