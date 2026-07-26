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

test("syncs the shared updater only into non-WordPress.org targets", () => {
  const root = createFixture();
  try {
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
    assert.equal(fs.existsSync(path.join(root, "plugins/fchub-stream")), false);
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});
