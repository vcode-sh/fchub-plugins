#!/usr/bin/env node
// Reconciles the MCP tool counts in versions.json against the generated release contract.
//
// This used to count `name: 'fluentcart_` occurrences with a regex over src/tools/*.ts, which is a
// second way of counting one registry and was wrong in both directions: it read only the top level
// of the directory, counted names appearing in comments and curated lists, and never saw the
// meta-tools or the store-context tool the server composes outside those modules. It reported 268,
// then 274, then 281 for a registry that actually held 271.
//
// release-contract.json is generated from the built server, so it is the authority. This script no
// longer counts anything; it checks that the documentation agrees with the contract.
//
// Usage:
//   node scripts/count-mcp-tools.mjs           # print the contract's figures
//   node scripts/count-mcp-tools.mjs --check   # exit 1 if versions.json disagrees
//   node scripts/count-mcp-tools.mjs --write   # sync versions.json from the contract

import { readFile, writeFile } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = dirname(fileURLToPath(import.meta.url));
const CONTRACT_FILE = join(__dirname, "../fluentcart-mcp/release-contract.json");
const VERSIONS_FILE = join(__dirname, "../web-docs/lib/versions.json");

const REGENERATE = "cd fluentcart-mcp && node scripts/build-release-contract.mjs";

async function readJson(path, hint) {
	try {
		return JSON.parse(await readFile(path, "utf-8"));
	} catch (error) {
		console.error(`Could not read ${path}: ${error.message}`);
		if (hint) console.error(hint);
		process.exit(1);
	}
}

const contract = await readJson(
	CONTRACT_FILE,
	`release-contract.json is generated. Run: ${REGENERATE}`,
);
const versionsRaw = await readFile(VERSIONS_FILE, "utf-8");
const versions = JSON.parse(versionsRaw);

// Reuse whatever indentation the file already has. Rewriting someone else's file in a different
// style turns a two-number change into a whole-file diff nobody asked to review.
const INDENT = /\n(\t| +)"/.exec(versionsRaw)?.[1] ?? "\t";

// The three figures documentation may state, and where each one comes from.
const expected = [
	{
		label: "mcp.toolCount",
		documented: versions.mcp?.toolCount,
		contract: contract.sourceDefinitionCount,
		apply: (next) => {
			next.mcp.toolCount = contract.sourceDefinitionCount;
		},
	},
	{
		label: "mcp.moduleCount",
		documented: versions.mcp?.moduleCount,
		contract: contract.categoryCount,
		apply: (next) => {
			next.mcp.moduleCount = contract.categoryCount;
		},
	},
	{
		label: "plugins['fluentcart-mcp'].version",
		documented: versions.plugins?.["fluentcart-mcp"]?.version,
		contract: contract.packageVersion,
		apply: (next) => {
			next.plugins["fluentcart-mcp"].version = contract.packageVersion;
			next.plugins["fluentcart-mcp"].tagName = `fluentcart-mcp/v${contract.packageVersion}`;
		},
	},
];

console.log(`Release contract ${contract.sourceTreeDigest}`);
console.log(`  package version:      ${contract.packageVersion}`);
console.log(`  source definitions:   ${contract.sourceDefinitionCount}`);
console.log(`  categories:           ${contract.categoryCount}`);
console.log(
	"\nNeither count is what a store exposes; that depends on the write mode, discovered routes\nand the caller's permissions. See writePolicyExposure in the contract for exposure figures.",
);

const drift = expected.filter((row) => row.documented !== row.contract);

if (process.argv.includes("--write")) {
	if (drift.length === 0) {
		console.log("\nversions.json already matches the contract; nothing to write.");
	} else {
		const next = JSON.parse(JSON.stringify(versions));
		for (const row of drift) row.apply(next);
		await writeFile(VERSIONS_FILE, `${JSON.stringify(next, null, INDENT)}\n`);
		for (const row of drift) {
			console.log(`\nupdated ${row.label}: ${row.documented} -> ${row.contract}`);
		}
	}
} else if (process.argv.includes("--check")) {
	if (drift.length > 0) {
		console.error("\nDRIFT between versions.json and the release contract:");
		for (const row of drift) {
			console.error(`  ${row.label}: documented ${row.documented}, contract ${row.contract}`);
		}
		console.error(
			"\nIf the contract is current, run: node scripts/count-mcp-tools.mjs --write" +
				`\nIf the source tree moved since the contract was generated, regenerate first: ${REGENERATE}`,
		);
		process.exit(1);
	}
	console.log("\nversions.json matches the release contract.");
}
