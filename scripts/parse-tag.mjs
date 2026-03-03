#!/usr/bin/env node
// Universal tag parser for the monorepo.
// Handles slug/vX.Y.Z format (canonical convention).
//
// Usage:
//   node scripts/parse-tag.mjs "fchub-p24/v1.0.1"
//   GITHUB_REF_NAME="fchub-p24/v1.0.1" node scripts/parse-tag.mjs
//
// Output (JSON):
//   {"slug":"fchub-p24","version":"1.0.1"}

const tag = process.argv[2] || process.env.GITHUB_REF_NAME;

if (!tag) {
  console.error("Usage: parse-tag.mjs <tag> or set GITHUB_REF_NAME");
  process.exit(1);
}

const match = tag.match(/^(.+)\/v(.+)$/);
if (!match) {
  console.error(`Invalid tag format: "${tag}". Expected: slug/vX.Y.Z`);
  process.exit(1);
}

const [, slug, version] = match;
console.log(JSON.stringify({ slug, version }));
