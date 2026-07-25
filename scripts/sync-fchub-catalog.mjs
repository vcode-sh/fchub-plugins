#!/usr/bin/env node
// Builds the FCHub product catalogue from the two committed sources and writes
// the same formatted JSON to the plugin's offline fallback and the website's
// public endpoint data. Both copies must stay byte-identical.
//
// Usage:
//   node scripts/sync-fchub-catalog.mjs           # build and write both copies
//   node scripts/sync-fchub-catalog.mjs --check   # fail if committed output has drifted

import { mkdir, readFile, writeFile } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = join(__dirname, "..");

const METADATA_FILE = join(ROOT, "web-docs/lib/fchub-products.json");
const VERSIONS_FILE = join(ROOT, "web-docs/lib/versions.json");
const WEBSITE_OUTPUT = join(ROOT, "web-docs/lib/fchub-catalog.json");

export const STABLE_SLUGS = Object.freeze([
  "fchub-p24",
  "fchub-fakturownia",
  "fchub-memberships",
  "fchub-portal-extender",
  "fchub-wishlist",
  "fchub-multi-currency",
]);

export function buildCatalogue(metadata, versions) {
  const products = Object.fromEntries(
    STABLE_SLUGS.map((slug) => {
      const product = metadata.products[slug];
      const release = versions.plugins[slug];
      if (!product || !release) {
        throw new Error(`Missing catalogue source for ${slug}`);
      }

      const base = "https://github.com/vcode-sh/fchub-plugins";
      const asset = `${base}/releases/download/${release.tagName}/${release.zipFilename}`;

      return [
        slug,
        {
          ...product,
          version: release.version,
          docs_url: `https://fchub.co${product.docs_path}`,
          release_url: `${base}/releases/tag/${release.tagName}`,
          package_url: asset,
          checksum_url: `${asset}.sha256`,
        },
      ];
    }),
  );

  const hubRelease = versions.plugins.fchub;
  const hubBase = "https://github.com/vcode-sh/fchub-plugins";
  const hubAsset = `${hubBase}/releases/download/${hubRelease.tagName}/${hubRelease.zipFilename}`;

  return {
    schema_version: 1,
    hub: {
      version: hubRelease.version,
      plugin_file: "fchub/fchub.php",
      release_url: `${hubBase}/releases/tag/${hubRelease.tagName}`,
      package_url: hubAsset,
      checksum_url: `${hubAsset}.sha256`,
    },
    products,
  };
}

export function validateCatalogue(catalogue) {
  if (
    catalogue?.schema_version !== 1 ||
    catalogue?.hub?.plugin_file !== "fchub/fchub.php" ||
    !catalogue.products
  ) {
    return false;
  }

  return Object.entries(catalogue.products).every(
    ([slug, product]) =>
      STABLE_SLUGS.includes(slug) &&
      product.status === "stable" &&
      product.plugin_file === `${slug}/${slug}.php` &&
      /^https:\/\/fchub\.co\/docs\//.test(product.docs_url) &&
      /^https:\/\/github\.com\/vcode-sh\/fchub-plugins\/releases\//.test(
        product.package_url,
      ),
  );
}

async function readJson(path) {
  return JSON.parse(await readFile(path, "utf8"));
}

function serialise(catalogue) {
  return `${JSON.stringify(catalogue, null, 2)}\n`;
}

async function main() {
  const metadata = await readJson(METADATA_FILE);
  const versions = await readJson(VERSIONS_FILE);
  const catalogue = buildCatalogue(metadata, versions);

  if (!validateCatalogue(catalogue)) {
    console.error("Generated catalogue failed validation. Check the source JSON files.");
    process.exit(1);
  }

  const output = serialise(catalogue);
  // The plugin bundles its own copy, fetched from the published endpoint by
  // its own build. It used to be written here, back when the source lived in
  // this repository; comparing two files this script wrote said less than
  // comparing what ships against what is served.
  const targets = [WEBSITE_OUTPUT];

  if (process.argv.includes("--check")) {
    const drifted = [];
    for (const file of targets) {
      const existing = await readFile(file, "utf8").catch(() => null);
      if (existing !== output) {
        drifted.push(file);
      }
    }

    if (drifted.length > 0) {
      console.error("Catalogue drift detected. The following files do not match the generated output:");
      for (const file of drifted) {
        console.error(`  - ${file}`);
      }
      console.error("Run `node scripts/sync-fchub-catalog.mjs` to regenerate them.");
      process.exit(1);
    }

    console.log("Catalogue is in sync with its sources.");
    return;
  }

  for (const file of targets) {
    await mkdir(dirname(file), { recursive: true });
    await writeFile(file, output);
  }

  console.log(`Wrote catalogue (schema v${catalogue.schema_version}) to:`);
  for (const file of targets) {
    console.log(`  - ${file}`);
  }
}

const isCliEntrypoint = process.argv[1] === fileURLToPath(import.meta.url);
if (isCliEntrypoint) {
  main().catch((error) => {
    console.error(error instanceof Error ? error.message : error);
    process.exit(1);
  });
}
