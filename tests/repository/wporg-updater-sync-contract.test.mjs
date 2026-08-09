import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import test from "node:test";

const repositoryRoot = path.resolve(
  path.dirname(new URL(import.meta.url).pathname),
  "../..",
);

function createFixture() {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), "fchub-build-contract-"));
  fs.mkdirSync(path.join(root, "lib"), { recursive: true });
  fs.mkdirSync(path.join(root, "plugins"), { recursive: true });
  fs.mkdirSync(path.join(root, "scripts/wporg"), { recursive: true });
  fs.mkdirSync(path.join(root, "wporg"), { recursive: true });
  fs.copyFileSync(path.join(repositoryRoot, "build.sh"), path.join(root, "build.sh"));
  fs.copyFileSync(
    path.join(repositoryRoot, "scripts/wporg/check-package.mjs"),
    path.join(root, "scripts/wporg/check-package.mjs"),
  );
  fs.copyFileSync(
    path.join(repositoryRoot, "wporg/plugins.json"),
    path.join(root, "wporg/plugins.json"),
  );
  fs.copyFileSync(
    path.join(repositoryRoot, "wporg/publisher.json"),
    path.join(root, "wporg/publisher.json"),
  );
  fs.writeFileSync(
    path.join(root, "lib/GitHubUpdater.php"),
    "<?php final class GitHubUpdater {}\n",
  );

  for (const [slug, mainFile] of [
    ["fchub-wishlist", "fchub-wishlist.php"],
    ["fchub-portal-extender", "fchub-portal-extender.php"],
  ]) {
    const pluginDirectory = path.join(root, "plugins", slug);
    fs.mkdirSync(pluginDirectory, { recursive: true });
    const wordpressOrgTarget = slug === "fchub-wishlist";
    fs.writeFileSync(
      path.join(pluginDirectory, mainFile),
      wordpressOrgTarget
        ? `<?php
/**
 * Plugin Name: FCHub Wishlist
 * Description: Let customers save FluentCart products and return to them later.
 * Version: 1.0.2
 * Requires at least: 7.0
 * Requires PHP: 8.3
 * Tested up to: 7.0
 * Requires Plugins: fluent-cart
 */
defined('ABSPATH') || exit;
`
        : `<?php\n/**\n * Plugin Name: ${slug}\n * Version: 1.0.0\n */\n`,
    );
    if (wordpressOrgTarget) {
      fs.writeFileSync(
        path.join(pluginDirectory, "readme.txt"),
        `=== FCHub Wishlist ===
Contributors: vcodesh
Tags: fluentcart, wishlist
Requires at least: 7.0
Tested up to: 7.0
Stable tag: 1.0.2
Requires PHP: 8.3
License: GPLv2 or later

Let customers save FluentCart products and return to them later.
`,
      );
      fs.writeFileSync(
        path.join(pluginDirectory, "LICENSE"),
        "GNU GENERAL PUBLIC LICENSE\nVersion 2, June 1991\n",
      );
    }
    fs.writeFileSync(path.join(pluginDirectory, ".distignore"), "");
  }

  return root;
}

function setSubmissionPaused(root, paused) {
  const manifestPath = path.join(root, "wporg/plugins.json");
  const manifest = JSON.parse(fs.readFileSync(manifestPath, "utf8"));
  manifest.submissionPaused = paused;
  fs.writeFileSync(manifestPath, `${JSON.stringify(manifest, null, 2)}\n`);
}

/**
 * The live state. A plugin that is not yet listed on WordPress.org has no update
 * channel there, so shipping it without the GitHub updater would leave it unable
 * to update at all — which is exactly what happened between 1.4.1 and 1.4.2.
 */
test("while submission is paused, every plugin receives the shared updater", () => {
  const root = createFixture();
  try {
    setSubmissionPaused(root, true);

    for (const slug of ["fchub-wishlist", "fchub-portal-extender"]) {
      const output = execFileSync("bash", ["build.sh", slug], {
        cwd: root,
        encoding: "utf8",
      });

      assert.match(output, /GitHubUpdater synced into 1 plugin/);
      assert.equal(
        fs.existsSync(path.join(root, "plugins", slug, "lib/GitHubUpdater.php")),
        true,
        `${slug} must receive the shared updater while submission is paused`,
      );
    }

    assert.equal(fs.existsSync(path.join(root, "plugins/fchub-stream")), false);
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

/**
 * The state to return to once the directory accepts a plugin: a listed plugin
 * must not carry a self-updater, and WordPress.org would reject the archive if
 * it did.
 */
test("once submission resumes, WordPress.org targets are excluded again", () => {
  const root = createFixture();
  try {
    setSubmissionPaused(root, false);

    const wordpressOrgOutput = execFileSync("bash", ["build.sh", "fchub-wishlist"], {
      cwd: root,
      encoding: "utf8",
    });
    assert.doesNotMatch(wordpressOrgOutput, /GitHubUpdater synced/);
    assert.equal(
      fs.existsSync(
        path.join(root, "plugins/fchub-wishlist/lib/GitHubUpdater.php"),
      ),
      false,
      "WordPress.org target must not receive the shared updater",
    );

    const maintainedOutput = execFileSync("bash", ["build.sh", "fchub-portal-extender"], {
      cwd: root,
      encoding: "utf8",
    });
    assert.match(maintainedOutput, /GitHubUpdater synced into 1 plugin/);
    assert.equal(
      fs.existsSync(
        path.join(root, "plugins/fchub-portal-extender/lib/GitHubUpdater.php"),
      ),
      true,
      "maintained non-target must retain the shared updater",
    );
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

/**
 * The flag is the single switch both the builder and the release gates read.
 * If it ever goes missing, both silently revert to treating plugins as listed.
 */
test("the manifest carries the submission-paused flag and its reason", () => {
  const manifest = JSON.parse(
    fs.readFileSync(path.join(repositoryRoot, "wporg/plugins.json"), "utf8"),
  );

  assert.equal(typeof manifest.submissionPaused, "boolean");
  assert.equal(typeof manifest.submissionPausedReason, "string");
  assert.ok(manifest.submissionPausedReason.length > 0);
});
