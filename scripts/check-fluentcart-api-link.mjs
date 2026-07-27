import assert from "node:assert/strict";
import { existsSync } from "node:fs";
import { readFile } from "node:fs/promises";
import { dirname, join } from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";

const repositoryRoot = join(dirname(fileURLToPath(import.meta.url)), "..");
const docsRoot = join(repositoryRoot, "web-docs");
const officialApiUrl = "https://dev.fluentcart.com/restapi/";
const localApiPath = "/docs/fluentcart-api";

async function read(relativePath) {
  return readFile(join(repositoryRoot, relativePath), "utf8");
}

test("FluentCart API entry points use the official developer documentation", async () => {
  const [homeLayout, homePage] = await Promise.all([
    read("web-docs/app/(home)/layout.tsx"),
    read("web-docs/app/(home)/page.tsx"),
  ]);
  const escapedOfficialApiUrl = officialApiUrl.replaceAll(".", "\\.");

  assert.match(homeLayout, new RegExp(escapedOfficialApiUrl));
  assert.match(homePage, new RegExp(escapedOfficialApiUrl));
  assert.doesNotMatch(homeLayout, new RegExp(localApiPath));
  assert.doesNotMatch(homePage, new RegExp(localApiPath));

  assert.equal(
    [
      ...homeLayout.matchAll(
        new RegExp(
          `url: "${escapedOfficialApiUrl}",\\s+external: true,`,
          "g",
        ),
      ),
    ].length,
    2,
    "both Fumadocs API navigation items must explicitly open externally",
  );
  assert.match(
    homeLayout,
    new RegExp(
      `<Link\\s+href="${escapedOfficialApiUrl}"\\s+target="_blank"\\s+rel="noopener noreferrer"\\s*/>`,
    ),
    "the custom desktop API navigation link must open in a new tab",
  );
  assert.match(
    homePage,
    new RegExp(
      `href="${escapedOfficialApiUrl}"\\s+target="_blank"\\s+rel="noopener noreferrer"`,
    ),
    "the homepage API card must open in a new tab",
  );
});

test("the website does not bundle a local FluentCart API reference", async () => {
  const [packageJson, lockfile, source, globalCss, mdxComponents, docsLayout] =
    await Promise.all([
      read("web-docs/package.json"),
      read("web-docs/bun.lock"),
      read("web-docs/lib/source.ts"),
      read("web-docs/app/global.css"),
      read("web-docs/mdx-components.tsx"),
      read("web-docs/app/docs/layout.tsx"),
    ]);

  assert.equal(
    existsSync(join(docsRoot, "content/docs/fluentcart-api")),
    false,
    "the local FluentCart API content directory must stay removed",
  );
  assert.equal(
    existsSync(join(docsRoot, "scripts/generate-openapi.mts")),
    false,
    "the OpenAPI page generator must stay removed",
  );
  assert.equal(
    existsSync(join(docsRoot, "lib/openapi.ts")),
    false,
    "the local OpenAPI loader must stay removed",
  );
  assert.equal(
    existsSync(join(docsRoot, "components/api-page.tsx")),
    false,
    "the local API page renderer must stay removed",
  );
  assert.equal(
    existsSync(join(docsRoot, "components/api-page.client.tsx")),
    false,
    "the local API page client must stay removed",
  );

  for (const [file, sourceText] of [
    ["web-docs/package.json", packageJson],
    ["web-docs/bun.lock", lockfile],
    ["web-docs/lib/source.ts", source],
    ["web-docs/app/global.css", globalCss],
    ["web-docs/mdx-components.tsx", mdxComponents],
    ["web-docs/app/docs/layout.tsx", docsLayout],
  ]) {
    assert.doesNotMatch(
      sourceText,
      /fumadocs-openapi|openapi-sampler|@fumari|fluentcart-api|APIPage|openapiPlugin/,
      `${file} still references the removed local API documentation`,
    );
  }
});
