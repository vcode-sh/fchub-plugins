import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

const release = readFileSync(new URL("./release.yml", import.meta.url), "utf8");
const gates = readFileSync(
  new URL("./wporg-release-gates.yml", import.meta.url),
  "utf8",
);
const sourceGates = readFileSync(
  new URL("../../scripts/wporg/run-source-gates.sh", import.meta.url),
  "utf8",
);

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

test("Memberships release gates execute in the reusable tag-SHA workflow", () => {
  const source = job(gates, "source");
  const frontend = job(gates, "memberships-frontend");
  assert.match(source, /- fchub-memberships/);
  assert.match(
    source,
    /bash scripts\/wporg\/run-source-gates\.sh "\$\{\{\s*matrix\.plugin\s*\}\}" "\$\{\{\s*matrix\.php_version\s*\}\}"/,
  );

  assert.match(sourceGates, /fchub-memberships\)/);
  assert.match(sourceGates, /composer test/);
  for (const command of [
    "npm ci",
    "npm audit --audit-level=high",
    "npm test",
    "npx playwright install --with-deps chromium",
    "npm run test:smoke",
    "npm run build",
  ]) {
    assert.ok(
      frontend.indexOf(command) >= 0,
      `Memberships release gate must run ${command}`,
    );
  }
});

test("Memberships publication consumes the package that passed reusable gates", () => {
  const wporgGates = job(release, "wporg-gates");
  const publish = job(release, "release");

  assert.match(
    wporgGates,
    /uses:\s*\.\/\.github\/workflows\/wporg-release-gates\.yml/,
  );
  assert.match(publish, /needs:\s*\[prepare,\s*wporg-gates\]/);
  assert.match(publish, /actions\/download-artifact@v4/);
  assert.match(
    publish,
    /name:\s*wporg-package-\$\{\{\s*needs\.prepare\.outputs\.slug\s*\}\}/,
  );
  assert.match(publish, /gh release create/);
  assert.doesNotMatch(
    publish,
    /Setup PHP \(fchub-memberships\)|Run PHPUnit \(fchub-memberships\)/,
    "Memberships gates belong in the reusable workflow, not the publisher",
  );
});
