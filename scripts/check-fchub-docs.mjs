#!/usr/bin/env node
// The contract for the FCHub product-centre documentation.
//
// FCHub docs make promises the rest of the codebase has to keep: which products
// exist, what each one needs, that removing the hub removes nothing else. This
// script is the thing that notices when a page and reality part company.
//
// Usage:
//   node scripts/check-fchub-docs.mjs   # exits 1 on the first honest failure

import { readdir, readFile } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = dirname(fileURLToPath(import.meta.url));
const DOCS_DIR = join(__dirname, "../web-docs/content/docs/fchub");
const PRODUCTS_FILE = join(__dirname, "../web-docs/lib/fchub-products.json");
const VERSIONS_FILE = join(__dirname, "../web-docs/lib/versions.json");

/**
 * Read-only. The button labels are the interface's to decide, and a doc naming
 * a button that no longer exists is this task's most likely way of going quietly
 * wrong — so they are read from the component rather than copied out of it.
 */
const PRODUCT_CARD = join(
	__dirname,
	"../plugins/fchub/resources/admin/components/ProductCard.vue",
);

/** Every page the product centre owes a reader, in reading order. */
const PAGES = [
	"index",
	"installation",
	"managing-products",
	"system-status",
	"troubleshooting",
	"changelog",
];

/**
 * The region of the overview that *is* the hub catalogue. Anything listed
 * between these markers is a claim that FCHub can install it, so the check
 * insists it matches the generated catalogue exactly — no more, no fewer.
 */
const CATALOGUE_START = "{/* fchub:catalogue-start */}";
const CATALOGUE_END = "{/* fchub:catalogue-end */}";

/**
 * Products that are not part of the hub. Naming one anywhere in these pages
 * would sooner or later be read as an offer, and FCHub cannot install any of
 * them. FCHub Stream is the single exception, handled below: it may be named,
 * but only while being called discontinued.
 */
const NOT_HUB_PRODUCTS = ["CartShift", "Redsys", "WPLove", "Thank You"];

/** Links that would quietly reintroduce an excluded product as a destination. */
const FORBIDDEN_LINKS = ["/docs/cartshift", "/docs/fchub-stream"];

/**
 * Machinery the customer neither needs nor wants. These pages explain what a
 * person can do; a leaked option key or capability name means the page slipped
 * into explaining how the plumbing works.
 */
const IMPLEMENTATION_LEAKS = [
	"fchub_catalogue",
	"wp_options",
	"fchub/v1",
	"apply_filters",
	"add_filter",
	"manage_options",
	"install_plugins",
	"activate_plugins",
	"update_plugins",
	"CatalogueRepository",
	"ProductOperationService",
	"spl_autoload",
];

/** Blanket compatibility claims the old suite page made, and got wrong. */
const RETIRED_CLAIMS = ["PHP 8.3+", "WordPress 6.8+"];

/**
 * The removal guarantee, and the three things it must actually say. Each is
 * backed by an assertion in tests/e2e/fchub-lifecycle.spec.js, so a page that
 * quietly softened or reversed one of them would be contradicting a test that
 * still passes.
 */
const REMOVAL_HEADING = "Removing FCHub safely";

const REMOVAL_PROMISES = [
	{ what: "products stay installed", pattern: /products stay installed/i },
	{ what: "FCHub never removes a product's files", pattern: /never removes a product/i },
	{ what: "products that were switched on keep running", pattern: /stay switched on/i },
];

const failures = [];

function check(condition, message) {
	if (!condition) {
		failures.push(message);
	}
}

async function readJson(file) {
	return JSON.parse(await readFile(file, "utf-8"));
}

async function readPage(name) {
	try {
		return await readFile(join(DOCS_DIR, `${name}.mdx`), "utf-8");
	} catch {
		return null;
	}
}

/** Paragraphs, so a claim can be checked against the sentence beside it. */
function paragraphs(source) {
	return source.split(/\n\s*\n/);
}

/**
 * The frontmatter block, or null when the file does not open with one. Anchored
 * to the first byte on purpose: a looser pattern happily matches a horizontal
 * rule halfway down a page and calls it frontmatter.
 */
function frontmatter(source) {
	const match = /^---\r?\n([\s\S]*?)\r?\n---\r?\n/.exec(source);

	return match ? match[1] : null;
}

/**
 * The body of one `## Heading` section, so a promise can be asserted where it is
 * actually made. Without this, "products stay installed" is satisfied by any
 * sentence anywhere containing those words — including one about a failed
 * activation, which is how the first version of this contract passed a
 * troubleshooting page with the removal guarantee cut out of it.
 */
function section(source, heading) {
	const start = source.indexOf(`## ${heading}`);

	if (start === -1) {
		return null;
	}

	const rest = source.slice(start + heading.length);
	const end = rest.indexOf("\n## ");

	return end === -1 ? rest : rest.slice(0, end);
}

/**
 * The action labels straight out of the interface's LABELS map. Parsing beats
 * hardcoding here: renaming a button in the component should break this build,
 * not the customer's understanding of it.
 *
 * @returns {string[]}
 */
async function buttonLabels() {
	const source = await readFile(PRODUCT_CARD, "utf-8");
	const map = /const LABELS = \{([\s\S]*?)\}/.exec(source);

	if (!map) {
		// Loud rather than skipped. A silently empty label list would turn this
		// assertion into decoration.
		failures.push(
			"ProductCard.vue: no LABELS map found — the docs contract can no longer verify button labels",
		);

		return [];
	}

	const labels = [...map[1].matchAll(/:\s*'([^']+)'/g)].map((entry) => entry[1]);

	// The update button never renders its LABELS entry. The primary branch
	// substitutes `Update to ${version}`, and searching the page for the bare
	// word "Update" is satisfied by the Updates filter, the "Update ready"
	// badge and half a dozen ordinary sentences — so renaming the button would
	// not have tripped this contract at all. The prefix is read out of the
	// expression that actually renders, so the assertion can fail again.
	const rendered = /label:\s*action === 'update'\s*\?\s*`([^$`]+)\$\{/.exec(source);

	if (!rendered) {
		failures.push(
			"ProductCard.vue: the update button's rendered label could not be parsed — the docs contract can no longer verify it",
		);

		return labels.filter((label) => label !== "Update");
	}

	return labels.map((label) => (label === "Update" ? rendered[1].trim() : label));
}

const products = (await readJson(PRODUCTS_FILE)).products;
const versions = await readJson(VERSIONS_FILE);

// ---------------------------------------------------------------------------
// meta.json lists the whole product centre
// ---------------------------------------------------------------------------

let meta = null;

try {
	meta = await readJson(join(DOCS_DIR, "meta.json"));
} catch {
	failures.push("meta.json: missing or not valid JSON");
}

if (meta) {
	const listed = Array.isArray(meta.pages) ? meta.pages : [];

	for (const page of PAGES) {
		check(listed.includes(page), `meta.json: does not list "${page}"`);
	}
}

// ---------------------------------------------------------------------------
// Every page exists, and says what it is
// ---------------------------------------------------------------------------

const sources = new Map();

for (const page of PAGES) {
	const source = await readPage(page);

	if (source === null) {
		failures.push(`${page}.mdx: missing`);
		continue;
	}

	sources.set(page, source);

	const front = frontmatter(source);

	if (front === null) {
		failures.push(`${page}.mdx: does not open with a frontmatter block`);
		continue;
	}

	check(/^title:/m.test(front), `${page}.mdx: no frontmatter title`);
	check(/^description:/m.test(front), `${page}.mdx: no frontmatter description`);
}

// ---------------------------------------------------------------------------
// Rules that apply to everything published under /docs/fchub
//
// Including the changelog partials. They render into a page a customer reads,
// but they carry no frontmatter of their own and — being named with a leading
// underscore — sit outside the docs collection, outside docs-ci.yml's own
// release-URL grep, and outside every rule above. Which is precisely why a
// hardcoded release URL would go unnoticed there.
// ---------------------------------------------------------------------------

const published = new Map([...sources].map(([page, source]) => [`${page}.mdx`, source]));

try {
	for (const file of await readdir(join(DOCS_DIR, "_changelog"))) {
		if (file.endsWith(".mdx")) {
			published.set(
				`_changelog/${file}`,
				await readFile(join(DOCS_DIR, "_changelog", file), "utf-8"),
			);
		}
	}
} catch {
	// No partials directory is a legitimate shape for this section.
}

for (const [file, source] of published) {
	check(
		!/releases\/(tag|download)\//.test(source),
		`${file}: hardcoded release URL — use <PluginDownload plugin="fchub" /> instead`,
	);

	for (const name of NOT_HUB_PRODUCTS) {
		check(!source.includes(name), `${file}: names "${name}", which is not a hub product`);
	}

	for (const link of FORBIDDEN_LINKS) {
		check(!source.includes(link), `${file}: links to ${link}, which is not a hub product`);
	}

	for (const leak of IMPLEMENTATION_LEAKS) {
		check(!source.includes(leak), `${file}: leaks implementation detail "${leak}"`);
	}

	// Stream may be named, but never without the word that makes it honest.
	for (const paragraph of paragraphs(source)) {
		if (/\bStream\b/.test(paragraph)) {
			check(
				/discontinued/i.test(paragraph),
				`${file}: mentions FCHub Stream without calling it discontinued`,
			);
		}
	}
}

// ---------------------------------------------------------------------------
// The overview: the catalogue, the floors, and the download
// ---------------------------------------------------------------------------

const index = sources.get("index");

if (index) {
	check(
		index.includes('<PluginDownload plugin="fchub" />'),
		'index.mdx: no <PluginDownload plugin="fchub" /> — the download link must come from versions.json',
	);

	for (const claim of RETIRED_CLAIMS) {
		check(
			!index.includes(claim),
			`index.mdx: repeats the retired suite-wide claim "${claim}"`,
		);
	}

	check(
		/without FluentCart/i.test(index),
		"index.mdx: does not say FCHub runs without FluentCart",
	);

	check(
		/\bStream\b/.test(index) && /discontinued/i.test(index),
		"index.mdx: does not say FCHub Stream is discontinued",
	);

	// The catalogue region has to list the catalogue. All of it, only it.
	const start = index.indexOf(CATALOGUE_START);
	const end = index.indexOf(CATALOGUE_END);

	if (start === -1 || end === -1 || end < start) {
		failures.push(
			`index.mdx: no catalogue region — wrap the product cards in ${CATALOGUE_START} and ${CATALOGUE_END}`,
		);
	} else {
		const region = index.slice(start, end);
		const linked = [...region.matchAll(/href="([^"]+)"/g)].map((match) => match[1]).sort();
		const expected = Object.values(products)
			.map((product) => product.docs_path)
			.sort();

		check(
			linked.join("|") === expected.join("|"),
			`index.mdx: catalogue region links [${linked.join(", ")}], catalogue says [${expected.join(", ")}]`,
		);
	}

	// The whole reason the old page was wrong: one floor for six products.
	for (const [slug, product] of Object.entries(products)) {
		const stated = index
			.split("\n")
			.some(
				(line) =>
					line.includes(product.name) &&
					line.includes(product.requires_wp) &&
					line.includes(product.requires_php),
			);

		check(
			stated,
			`index.mdx: no line stating ${product.name} (${slug}) needs WordPress ${product.requires_wp} and PHP ${product.requires_php}`,
		);
	}
}

// ---------------------------------------------------------------------------
// Installation: FCHub's own floors, which are not the products' floors
// ---------------------------------------------------------------------------

const installation = sources.get("installation");

if (installation) {
	check(
		/WordPress[^\n]*\b6\.4\b/.test(installation),
		"installation.mdx: does not state WordPress 6.4",
	);
	check(/PHP[^\n]*\b8\.1\b/.test(installation), "installation.mdx: does not state PHP 8.1");
}

// ---------------------------------------------------------------------------
// Managing products: the actions, in the words on the buttons
// ---------------------------------------------------------------------------

const managing = sources.get("managing-products");

if (managing) {
	const labels = await buttonLabels();

	check(
		labels.length > 0,
		"ProductCard.vue: LABELS map parsed as empty — the button assertions below would prove nothing",
	);

	for (const label of labels) {
		check(
			managing.includes(label),
			`managing-products.mdx: does not document the "${label}" button, which ProductCard.vue renders`,
		);
	}

	check(
		/never (deletes|removes)/i.test(managing),
		"managing-products.mdx: does not say FCHub never deletes a product",
	);
}

// ---------------------------------------------------------------------------
// System status: where the catalogue came from, in the interface's own words
// ---------------------------------------------------------------------------

const system = sources.get("system-status");

if (system) {
	// All three, not just the two unhappy ones. The healthy label is the one a
	// reader sees every ordinary day, and it can drift as quietly as the others.
	for (const line of [
		"Straight from fchub.co",
		"Using the last saved catalogue",
		"Using the catalogue included with FCHub",
	]) {
		check(system.includes(line), `system-status.mdx: does not explain "${line}"`);
	}
}

// ---------------------------------------------------------------------------
// Troubleshooting: the fallback, and the promise that removal is safe
// ---------------------------------------------------------------------------

const troubleshooting = sources.get("troubleshooting");

if (troubleshooting) {
	for (const line of ["Using the last saved catalogue", "Using the catalogue included with FCHub"]) {
		check(
			troubleshooting.includes(line),
			`troubleshooting.mdx: does not explain the catalogue fallback state "${line}"`,
		);
	}

	check(
		/remov(?:e|es|ed|ing) FCHub/i.test(troubleshooting),
		"troubleshooting.mdx: does not cover removing FCHub",
	);

	// The strongest promise on the site, so it is asserted inside the section
	// that makes it. Searched across the whole page, "still installed" is
	// satisfied by a sentence about a failed activation — which means a page
	// that had the guarantee cut out of it, or reversed, would pass.
	const removal = section(troubleshooting, REMOVAL_HEADING);

	if (removal === null) {
		failures.push(`troubleshooting.mdx: no "## ${REMOVAL_HEADING}" section`);
	} else {
		for (const promise of REMOVAL_PROMISES) {
			check(
				promise.pattern.test(removal),
				`troubleshooting.mdx: "${REMOVAL_HEADING}" does not promise ${promise.what}`,
			);
		}
	}
}

// ---------------------------------------------------------------------------
// Changelog: this release, and only this release
// ---------------------------------------------------------------------------

const changelog = sources.get("changelog");

if (changelog) {
	const version = versions.plugins.fchub.version;

	check(
		changelog.includes(version) || (await includedChangelogHas(changelog, version)),
		`changelog.mdx: does not mention FCHub ${version}`,
	);
}

async function includedChangelogHas(source, needle) {
	const includes = [...source.matchAll(/<include>([^<]+)<\/include>/g)].map((m) => m[1].trim());

	for (const relative of includes) {
		try {
			const body = await readFile(join(DOCS_DIR, relative), "utf-8");

			if (body.includes(needle)) {
				return true;
			}
		} catch {
			failures.push(`changelog.mdx: includes "${relative}", which does not exist`);
		}
	}

	return false;
}

// ---------------------------------------------------------------------------

if (failures.length) {
	console.error(`FCHub docs contract: ${failures.length} failure(s)\n`);

	for (const failure of failures) {
		console.error(`  - ${failure}`);
	}

	process.exit(1);
}

console.log(`FCHub docs contract: ${PAGES.length} pages, all assertions pass`);
