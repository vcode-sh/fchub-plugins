import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import test from "node:test";

const testDirectory = dirname(fileURLToPath(import.meta.url));
const repositoryRoot = resolve(testDirectory, "../..");
const playgroundRoot = resolve(repositoryRoot, "../fchub-playground");

function readRepositoryFile(path) {
  return readFileSync(resolve(repositoryRoot, path), "utf8");
}

function readPlaygroundFile(path) {
  return readFileSync(resolve(playgroundRoot, path), "utf8");
}

test("the Stream README states the indefinite maintenance suspension", () => {
  const readme = readRepositoryFile("plugins/fchub-stream/README.md");

  assert.match(readme, /Discontinued - maintenance suspended indefinitely/i);
  assert.match(readme, /no support, bug fixes, compatibility updates, security updates, or new releases/i);
  assert.match(readme, /fork/i);
  assert.match(readme, /may return/i);
});

test("agent guidance blocks Stream work without explicit owner reactivation", () => {
  const guidanceFiles = [
    readRepositoryFile("AGENTS.md"),
    readRepositoryFile("CLAUDE.md"),
    readPlaygroundFile("AGENTS.md"),
    readPlaygroundFile("CLAUDE.md"),
  ];

  for (const guidance of guidanceFiles) {
    assert.match(guidance, /FCHub Stream.*Discontinued/is);
    assert.match(guidance, /explicitly reactivates/i);
    assert.match(guidance, /do not.*(?:fix|update|support)/is);
  }
});

test("public contribution surfaces do not solicit Stream maintenance", () => {
  const bugTemplate = readRepositoryFile(".github/ISSUE_TEMPLATE/bug_report.yml");
  const featureTemplate = readRepositoryFile(
    ".github/ISSUE_TEMPLATE/feature_request.yml",
  );
  const contributing = readRepositoryFile("CONTRIBUTING.md");
  const pullRequestTemplate = readRepositoryFile(
    ".github/PULL_REQUEST_TEMPLATE.md",
  );

  for (const issueTemplate of [bugTemplate, featureTemplate]) {
    assert.match(issueTemplate, /FCHub Stream is discontinued/i);
    assert.doesNotMatch(issueTemplate, /^\s*- Stream \(fchub-stream\)\s*$/m);
  }

  assert.match(contributing, /FCHub Stream is discontinued/i);
  assert.match(pullRequestTemplate, /FCHub Stream is discontinued/i);
});

test("the website labels Stream as discontinued on listings and every docs page", () => {
  const homePage = readRepositoryFile("web-docs/app/(home)/page.tsx");
  const docsPage = readRepositoryFile("web-docs/app/docs/[[...slug]]/page.tsx");
  const streamMeta = readRepositoryFile(
    "web-docs/content/docs/fchub-stream/meta.json",
  );
  const streamRoadmap = readRepositoryFile(
    "web-docs/content/docs/fchub-stream/roadmap.mdx",
  );

  assert.match(homePage, /discontinued\?: boolean/);
  assert.match(homePage, /title: "FCHub Stream"[\s\S]*discontinued: true/);
  assert.match(homePage, />\s*Discontinued\s*</);
  assert.match(docsPage, /isDiscontinuedStream/);
  assert.match(docsPage, /Maintenance suspended indefinitely/i);
  assert.match(streamMeta, /FCHub Stream \(Discontinued\)/);
  assert.match(streamRoadmap, /No active roadmap/i);
  assert.doesNotMatch(streamRoadmap, /Open a GitHub Issue/i);
});

test("Stream remains available for a possible future return", () => {
  const buildScript = readRepositoryFile("build.sh");
  const continuousIntegration = readRepositoryFile(".github/workflows/ci.yml");
  const releaseWorkflow = readRepositoryFile(".github/workflows/release.yml");

  assert.match(buildScript, /fchub-stream\|fchub-stream\.php/);
  assert.match(continuousIntegration, /plugin: fchub-stream/);
  assert.match(releaseWorkflow, /slug == 'fchub-stream'/);
});

test("nothing builds or publishes Stream unless somebody names it", () => {
  const buildScript = readRepositoryFile("build.sh");
  const releaseWorkflow = readRepositoryFile(".github/workflows/release.yml");

  // A bare `./build.sh` iterates ALL_PLUGINS. Stream sits in a second list
  // that only the explicit-slug paths consult, so the default run cannot copy
  // the shared updater into it, run npm in its app directories, or ZIP it.
  const allPlugins = buildScript.match(/^ALL_PLUGINS=\(\n([\s\S]*?)^\)$/m);
  assert.ok(allPlugins, "Expected an ALL_PLUGINS array in build.sh");
  assert.doesNotMatch(allPlugins[1], /fchub-stream/);

  assert.match(buildScript, /^ARCHIVED_PLUGINS=\(\n(?:.*\n)*?\s*"fchub-stream\|/m);

  // And a pushed tag is no longer enough to publish it.
  const tags = releaseWorkflow.match(
    /^[ \t]*tags:[ \t]*\n((?:[ \t]*-[ \t]*\S.*\n?)+)/m,
  );
  assert.ok(tags, "Expected tag filters on the release trigger");
  assert.doesNotMatch(tags[1], /fchub-stream/);
});
