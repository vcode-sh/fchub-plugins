import assert from "node:assert/strict";
import { readdir, readFile } from "node:fs/promises";
import { dirname, join } from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";

const repositoryRoot = join(dirname(fileURLToPath(import.meta.url)), "..");
const homepageRoot = join(repositoryRoot, "web-docs/app/(home)");
const maximumProductionLines = 280;

function countPhysicalLines(source) {
  const lines = source.replaceAll("\r\n", "\n").split("\n");
  if (lines.at(-1) === "") {
    lines.pop();
  }
  return lines.length;
}

async function homepageModules() {
  const entries = await readdir(homepageRoot, { withFileTypes: true });
  return entries
    .filter(
      (entry) =>
        entry.isFile() &&
        /^home-.+\.(?:ts|tsx)$/.test(entry.name) &&
        !entry.name.endsWith(".test.ts") &&
        !entry.name.endsWith(".test.tsx"),
    )
    .map((entry) => entry.name)
    .sort();
}

test("the homepage delegates to focused local modules", async () => {
  const modules = await homepageModules();
  const [routeSource, compositionSource] = await Promise.all([
    readFile(join(homepageRoot, "page.tsx"), "utf8"),
    readFile(join(homepageRoot, "home-page.tsx"), "utf8"),
  ]);

  assert.ok(
    modules.length >= 4,
    `expected at least four home-* modules, found ${modules.length}`,
  );
  assert.doesNotMatch(
    routeSource,
    /^["']use client["'];/m,
    "the route should remain a server boundary",
  );
  assert.doesNotMatch(
    compositionSource,
    /^["']use client["'];/m,
    "the homepage composition should remain server-side",
  );
});

test("homepage production modules stay within 280 physical lines", async () => {
  const files = ["page.tsx", ...(await homepageModules())];

  for (const file of files) {
    const source = await readFile(join(homepageRoot, file), "utf8");
    assert.ok(
      countPhysicalLines(source) <= maximumProductionLines,
      `${file} exceeds ${maximumProductionLines} physical lines`,
    );
  }
});
