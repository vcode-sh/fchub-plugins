import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const repositoryRoot = path.resolve(
  path.dirname(new URL(import.meta.url).pathname),
  "../..",
);

const manifest = JSON.parse(
  fs.readFileSync(path.join(repositoryRoot, "wporg/plugins.json"), "utf8"),
);

const submissionPaused = manifest.submissionPaused === true;
const wordPressOrgTargets = new Set(Object.keys(manifest.plugins));

/**
 * Plugins that are actually distributed to users, with their main file.
 *
 * Deliberately excludes fchub-stream, which is discontinued, and
 * fchub-thank-you, which has never been released — an updater polling for a
 * release that does not exist would be theatre.
 */
const distributedPlugins = {
  cartshift: "cartshift.php",
  "fchub-fakturownia": "fchub-fakturownia.php",
  "fchub-memberships": "fchub-memberships.php",
  "fchub-multi-currency": "fchub-multi-currency.php",
  "fchub-p24": "fchub-p24.php",
  "fchub-portal-extender": "fchub-portal-extender.php",
  "fchub-wishlist": "fchub-wishlist.php",
};

function readMainFile(slug) {
  return fs.readFileSync(
    path.join(repositoryRoot, "plugins", slug, distributedPlugins[slug]),
    "utf8",
  );
}

function distignoreLines(slug) {
  const file = path.join(repositoryRoot, "plugins", slug, ".distignore");
  if (!fs.existsSync(file)) return [];

  return fs
    .readFileSync(file, "utf8")
    .split("\n")
    .map((line) => line.trim())
    .filter((line) => line !== "" && !line.startsWith("#"));
}

/**
 * A plugin that is not listed on WordPress.org has no update channel there.
 * Shipping it without the GitHub updater leaves users stranded on whatever
 * version they installed — which is what happened when the updater was removed
 * ahead of a submission that had not been accepted yet.
 */
test("every distributed plugin can update itself while submission is paused", (t) => {
  if (!submissionPaused) {
    t.skip("WordPress.org submission has resumed");
    return;
  }

  for (const slug of Object.keys(distributedPlugins)) {
    const source = readMainFile(slug);

    assert.match(
      source,
      /^\s*\*\s*Update URI\s*:/m,
      `${slug} is missing its Update URI header`,
    );
    assert.match(
      source,
      new RegExp(`FCHub_GitHub_Updater::register\\(\\s*'${slug}'`),
      `${slug} never registers the updater`,
    );
    assert.equal(
      fs.existsSync(
        path.join(repositoryRoot, "plugins", slug, "lib/GitHubUpdater.php"),
      ),
      true,
      `${slug} is missing lib/GitHubUpdater.php — run scripts/sync-updater.sh`,
    );
  }
});

/**
 * The require must be guarded. A WordPress.org build omits the file on purpose,
 * and an unguarded require_once would turn that into a fatal on activation
 * rather than simply no automatic updates.
 */
test("the updater include is guarded so a missing file is never fatal", () => {
  for (const slug of Object.keys(distributedPlugins)) {
    const source = readMainFile(slug);
    if (!source.includes("lib/GitHubUpdater.php")) continue;

    assert.match(
      source,
      /if\s*\(\s*file_exists\(\s*__DIR__\s*\.\s*'\/lib\/GitHubUpdater\.php'\s*\)\s*\)/,
      `${slug} requires the updater without checking the file exists`,
    );
  }
});

/**
 * The updater can be present in the source tree and still be stripped out of the
 * package — which is worse than not having it, because the plugin then requires
 * a file the user never received.
 */
test("no plugin excludes lib/ from its distribution while it needs the updater", (t) => {
  if (!submissionPaused) {
    t.skip("WordPress.org submission has resumed");
    return;
  }

  for (const slug of Object.keys(distributedPlugins)) {
    assert.ok(
      !distignoreLines(slug).some((line) => line === "lib/" || line === "lib"),
      `${slug}/.distignore excludes lib/, so the shipped package would be missing the updater it requires`,
    );
  }
});

/**
 * The state to return to. WordPress.org forbids self-updating plugins, so once a
 * plugin is listed its build must drop the updater again.
 */
test("WordPress.org targets drop the updater once submission resumes", (t) => {
  if (submissionPaused) {
    t.skip("WordPress.org submission is paused");
    return;
  }

  for (const slug of Object.keys(distributedPlugins)) {
    if (!wordPressOrgTargets.has(slug)) continue;

    const source = readMainFile(slug);
    assert.doesNotMatch(source, /^\s*\*\s*Update URI\s*:/m, `${slug} still declares Update URI`);
    assert.doesNotMatch(source, /GitHubUpdater/, `${slug} still references the updater`);
  }
});

test("the pause flag is a boolean the builder and release gates can both read", () => {
  assert.equal(typeof manifest.submissionPaused, "boolean");

  const releaseWorkflow = fs.readFileSync(
    path.join(repositoryRoot, ".github/workflows/release.yml"),
    "utf8",
  );

  assert.match(
    releaseWorkflow,
    /submissionPaused !== true/,
    "release.yml must consult the pause flag before running the WordPress.org gates",
  );
});
