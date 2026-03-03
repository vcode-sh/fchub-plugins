#!/usr/bin/env node
// Counts MCP tool definitions from source and compares against versions.json.
//
// Usage:
//   node scripts/count-mcp-tools.mjs           # print counts
//   node scripts/count-mcp-tools.mjs --check   # exit 1 if counts diverge from versions.json

import { readdir, readFile } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = dirname(fileURLToPath(import.meta.url));
const TOOLS_DIR = join(__dirname, "../fluentcart-mcp/src/tools");
const VERSIONS_FILE = join(__dirname, "../web-docs/lib/versions.json");

const files = (await readdir(TOOLS_DIR)).filter((f) => f.endsWith(".ts"));
let total = 0;
const categories = {};

for (const file of files) {
	const content = await readFile(join(TOOLS_DIR, file), "utf-8");
	const matches = content.match(/name:\s*['"]fluentcart_/g);
	const count = matches ? matches.length : 0;
	const category = file.replace(".ts", "");
	categories[category] = count;
	total += count;
}

console.log(`Total tools: ${total}`);
console.log(`Module files: ${files.length}`);
console.log(`\nPer-category:`);
for (const [cat, count] of Object.entries(categories).sort(
	(a, b) => b[1] - a[1],
)) {
	console.log(`  ${cat}: ${count}`);
}

if (process.argv.includes("--check")) {
	const versions = JSON.parse(await readFile(VERSIONS_FILE, "utf-8"));
	const expected = versions.mcp.toolCount;
	if (total !== expected) {
		console.error(
			`\nDRIFT: source has ${total} tools, versions.json says ${expected}`,
		);
		process.exit(1);
	}
	console.log(`\nversions.json matches source (${total} tools)`);
}
