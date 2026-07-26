import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import crypto from "node:crypto";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import test from "node:test";

import { inspectZip } from "../../scripts/wporg/check-package.mjs";

const slug = "fchub-wishlist";
const version = "1.0.2";
const workspace = fs.mkdtempSync(path.join(os.tmpdir(), "fchub-wporg-"));
const zipDirectory = path.join(workspace, "zips");
fs.mkdirSync(zipDirectory);

test.after(() => fs.rmSync(workspace, { recursive: true, force: true }));

const mainFile = `<?php
/**
 * Plugin Name: FCHub Wishlist
 * Description: Let customers save FluentCart products and return to them later.
 * Version: ${version}
 * Requires at least: 7.0
 * Requires PHP: 8.3
 * Tested up to: 7.0
 * Requires Plugins: fluent-cart
 * License: GPLv2 or later
 * Text Domain: fchub-wishlist
 */
defined('ABSPATH') || exit;
`;

const readme = `=== FCHub Wishlist ===
Contributors: vcodesh
Tags: fluentcart, wishlist
Requires at least: 7.0
Tested up to: 7.0
Stable tag: ${version}
Requires PHP: 8.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let customers save FluentCart products and return to them later.

== Description ==

Adds a local wishlist to FluentCart.
`;

const baseFiles = {
  [`${slug}/${slug}.php`]: mainFile,
  [`${slug}/readme.txt`]: readme,
  [`${slug}/LICENSE`]: "GNU GENERAL PUBLIC LICENSE\nVersion 2, June 1991\n",
};

function createZip(name, extraFiles = {}, options = {}) {
  const fixtureDirectory = path.join(workspace, name.replace(/\.zip$/, ""));
  fs.mkdirSync(fixtureDirectory, { recursive: true });

  for (const [relativePath, contents] of Object.entries({
    ...baseFiles,
    ...extraFiles,
  })) {
    if (contents === undefined) {
      continue;
    }
    const absolutePath = path.join(fixtureDirectory, relativePath);
    fs.mkdirSync(path.dirname(absolutePath), { recursive: true });
    fs.writeFileSync(absolutePath, contents);
  }

  const zipPath = path.join(zipDirectory, name);
  const zipArgs = options.store ? ["-0qr", zipPath, "."] : ["-qr", zipPath, "."];
  execFileSync("zip", zipArgs, { cwd: fixtureDirectory });
  return zipPath;
}

const validZip = createZip("valid.zip");
const twoRootsZip = createZip("two-roots.zip", {
  "another-plugin/another.php": "<?php defined('ABSPATH') || exit;",
});
const updaterZip = createZip("updater.zip", {
  [`${slug}/lib/GitHubUpdater.php`]: "<?php defined('ABSPATH') || exit;",
});
const updateUriZip = createZip("update-uri.zip", {
  [`${slug}/${slug}.php`]: mainFile.replace(
    " * Version:",
    " * Update URI: https://example.com/update\n * Version:",
  ),
});
const missingReadmeZip = createZip("missing-readme.zip", {
  [`${slug}/readme.txt`]: undefined,
});
const remoteFontZip = createZip("remote-font.zip", {
  [`${slug}/assets/app.css`]:
    '@import url("https://fonts.googleapis.com/css2?family=Inter");',
});
const devLitterZip = createZip("dev-litter.zip", {
  [`${slug}/.DS_Store`]: "litter",
});
const topLevelDistZip = createZip("top-level-dist.zip", {
  [`${slug}/dist/bundle.js`]: "console.log('package build');",
});
const shellScriptZip = createZip("shell-script.zip", {
  [`${slug}/bin/release.sh`]: "#!/usr/bin/env bash\n",
});
const runtimeAssetsZip = createZip("runtime-assets-dist.zip", {
  [`${slug}/assets/dist/app.js`]: "console.log('runtime');",
});
const inertIndexAndUninstallZip = createZip("inert-index-and-uninstall.zip", {
  [`${slug}/index.php`]: "<?php\n// Silence is golden.\n",
  [`${slug}/uninstall.php`]:
    "<?php\nif (!defined('WP_UNINSTALL_PLUGIN')) { exit; }\n",
});

const oversizedFixtureDirectory = path.join(workspace, "oversized");
fs.mkdirSync(path.join(oversizedFixtureDirectory, slug), { recursive: true });
for (const [relativePath, contents] of Object.entries(baseFiles)) {
  const absolutePath = path.join(oversizedFixtureDirectory, relativePath);
  fs.mkdirSync(path.dirname(absolutePath), { recursive: true });
  fs.writeFileSync(absolutePath, contents);
}
fs.writeFileSync(
  path.join(oversizedFixtureDirectory, slug, "payload.bin"),
  crypto.randomBytes(10_100_000),
);
const oversizedZip = path.join(zipDirectory, "oversized.zip");
execFileSync("zip", ["-0qr", oversizedZip, "."], {
  cwd: oversizedFixtureDirectory,
});

test("accepts a minimal valid WordPress.org package", async () => {
  const result = await inspectZip(validZip, slug);
  assert.equal(result.slug, slug);
  assert.equal(result.version, version);
  assert.match(result.sha256, /^[a-f0-9]{64}$/);
});

test("rejects packages with multiple roots", async () => {
  await assert.rejects(
    () => inspectZip(twoRootsZip, slug),
    /exactly one top-level directory/,
  );
});

test("rejects the shared GitHub updater", async () => {
  await assert.rejects(() => inspectZip(updaterZip, slug), /GitHubUpdater\.php/);
});

test("rejects an off-directory Update URI", async () => {
  await assert.rejects(() => inspectZip(updateUriZip, slug), /Update URI/);
});

test("requires the WordPress.org readme", async () => {
  await assert.rejects(() => inspectZip(missingReadmeZip, slug), /readme\.txt/);
});

test("rejects remote font stylesheets", async () => {
  await assert.rejects(
    () => inspectZip(remoteFontZip, slug),
    /fonts\.googleapis\.com/,
  );
});

test("rejects development litter", async () => {
  await assert.rejects(() => inspectZip(devLitterZip, slug), /\.DS_Store/);
});

test("rejects a package-build dist directory at the plugin root", async () => {
  await assert.rejects(
    () => inspectZip(topLevelDistZip, slug),
    /forbidden package-build directory/,
  );
});

test("rejects shell application files", async () => {
  await assert.rejects(
    () => inspectZip(shellScriptZip, slug),
    /application file.*\.sh/,
  );
});

test("rejects archives at or above the directory size limit", async () => {
  await assert.rejects(
    () => inspectZip(oversizedZip, slug),
    /compressed archive must be smaller than 10000000 bytes/,
  );
});

test("permits nested runtime bundles under assets/dist", async () => {
  await assert.doesNotReject(() => inspectZip(runtimeAssetsZip, slug));
});

test("permits an inert index sentinel and guarded uninstall entrypoint", async () => {
  await assert.doesNotReject(() => inspectZip(inertIndexAndUninstallZip, slug));
});
