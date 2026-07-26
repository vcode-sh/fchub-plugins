import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const repositoryRoot = path.resolve(import.meta.dirname, "../..");
const screenshotCounts = {
  "fchub-wishlist": 3,
  "fchub-multi-currency": 3,
  "fchub-memberships": 4,
  "fchub-p24": 3,
  "fchub-fakturownia": 3,
};

function pngDimensions(imagePath) {
  const image = fs.readFileSync(imagePath);
  assert.deepEqual(
    [...image.subarray(0, 8)],
    [137, 80, 78, 71, 13, 10, 26, 10],
    `${imagePath} must be a PNG`,
  );

  return {
    width: image.readUInt32BE(16),
    height: image.readUInt32BE(20),
  };
}

for (const [slug, count] of Object.entries(screenshotCounts)) {
  test(`${slug} has one consistently framed real screenshot per readme caption`, () => {
    const readme = fs.readFileSync(
      path.join(repositoryRoot, "plugins", slug, "readme.txt"),
      "utf8",
    );
    const screenshotSection = readme.match(
      /^== Screenshots ==\s*$([\s\S]*?)(?=^== |\Z)/m,
    )?.[1];
    const captions = screenshotSection?.match(/^\d+\.\s+.+$/gm) ?? [];
    assert.equal(captions.length, count, "readme screenshot caption count differs");

    const assetDirectory = path.join(repositoryRoot, "wporg", "assets", slug);
    for (let index = 1; index <= count; index += 1) {
      const imagePath = path.join(assetDirectory, `screenshot-${index}.png`);
      assert.equal(fs.existsSync(imagePath), true, `${imagePath} is missing`);
      assert.deepEqual(
        pngDimensions(imagePath),
        { width: 1280, height: 900 },
        `${imagePath} must use the shared 1280x900 frame`,
      );
    }
  });
}
