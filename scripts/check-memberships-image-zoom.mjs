import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const repositoryRoot = fileURLToPath(new URL('../', import.meta.url));
const docsRoot = path.join(repositoryRoot, 'web-docs/content/docs/fchub-memberships');
const imagesRoot = path.join(repositoryRoot, 'web-docs/public/memberships');
const imageZoomImport =
  "import { ImageZoom } from 'fumadocs-ui/components/image-zoom';";
const imageZoomPattern = /<ImageZoom\b[\s\S]*?\/>/g;
const membershipImagePattern = /\/memberships\/[^"')\s>]+\.webp/g;

test('every Memberships screenshot uses the native Fumadocs image lightbox', () => {
  const zoomedImages = new Set();

  for (const entry of readdirSync(docsRoot, { withFileTypes: true })) {
    if (!entry.isFile() || !entry.name.endsWith('.mdx')) {
      continue;
    }

    const file = path.join(docsRoot, entry.name);
    const source = readFileSync(file, 'utf8');
    const imageZoomTags = [...source.matchAll(imageZoomPattern)].map((match) => match[0]);
    const unwrappedSource = source.replace(imageZoomPattern, '');

    assert.doesNotMatch(
      unwrappedSource,
      membershipImagePattern,
      `${path.relative(repositoryRoot, file)}: Memberships screenshots must use <ImageZoom>`,
    );

    if (imageZoomTags.length > 0) {
      assert.ok(
        source.includes(imageZoomImport),
        `${path.relative(repositoryRoot, file)}: missing the Fumadocs ImageZoom import`,
      );
    }

    for (const tag of imageZoomTags) {
      const sourcePath = tag.match(/\bsrc="([^"]+\.webp)"/)?.[1];
      assert.ok(sourcePath, `${path.relative(repositoryRoot, file)}: ImageZoom needs a WebP src`);
      assert.match(tag, /\balt="[^"]+"/, `${sourcePath}: ImageZoom needs descriptive alt text`);
      assert.match(tag, /\bwidth=\{1492\}/, `${sourcePath}: ImageZoom must preserve its width`);
      assert.match(tag, /\bheight=\{840\}/, `${sourcePath}: ImageZoom must preserve its height`);
      zoomedImages.add(sourcePath);
    }
  }

  const publishedImages = new Set(
    readdirSync(imagesRoot)
      .filter((file) => file.endsWith('.webp'))
      .map((file) => `/memberships/${file}`),
  );

  assert.deepEqual(zoomedImages, publishedImages);
});
