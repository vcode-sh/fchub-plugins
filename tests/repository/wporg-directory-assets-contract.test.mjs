import assert from "node:assert/strict";
import crypto from "node:crypto";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const repositoryRoot = path.resolve(import.meta.dirname, "../..");
const plugins = [
  "fchub-wishlist",
  "fchub-multi-currency",
  "fchub-memberships",
  "fchub-p24",
  "fchub-fakturownia",
];
const requiredImages = new Map([
  ["icon-128x128.png", [128, 128]],
  ["icon-256x256.png", [256, 256]],
  ["banner-772x250.png", [772, 250]],
  ["banner-1544x500.png", [1544, 500]],
]);

for (const plugin of plugins) {
  test(`${plugin} has exact WordPress.org icon and banner assets`, async () => {
    const assetDirectory = path.join(repositoryRoot, "wporg", "assets", plugin);

    for (const [fileName, [width, height]] of requiredImages) {
      const assetPath = path.join(assetDirectory, fileName);
      assert.equal(fs.existsSync(assetPath), true, `${assetPath} is missing`);

      const image = fs.readFileSync(assetPath);
      assert.deepEqual(
        [...image.subarray(0, 8)],
        [137, 80, 78, 71, 13, 10, 26, 10],
        `${fileName} must be PNG`,
      );
      assert.equal(image.readUInt32BE(16), width, `${fileName} has the wrong width`);
      assert.equal(image.readUInt32BE(20), height, `${fileName} has the wrong height`);
      assert.equal(image[25], 2, `${fileName} must be an opaque truecolour PNG`);
    }
  });
}

test("approved artwork records deterministic owned-brand provenance", () => {
  const rights = JSON.parse(
    fs.readFileSync(path.join(repositoryRoot, "wporg", "assets-rights.json"), "utf8"),
  );

  assert.equal(rights.owner, "Vibe Code");
  assert.equal(rights.status, "approved");
  assert.equal(rights.method, "deterministic composition");
  assert.equal(rights.thirdPartyLogos, false);
  assert.deepEqual(rights.thirdPartyNames, ["Przelewy24", "Fakturownia"]);
  assert.equal(
    rights.generator,
    "scripts/wporg/generate-directory-assets.mjs",
  );
  assert.equal(rights.sourceAssets.length, 2);
  for (const source of rights.sourceAssets) {
    assert.match(source.path, /^web-docs\/public\/.+\.webp$/);
    assert.match(source.sha256, /^[a-f0-9]{64}$/);
    const sourcePath = path.join(repositoryRoot, source.path);
    assert.equal(fs.existsSync(sourcePath), true);
    assert.equal(
      crypto.createHash("sha256").update(fs.readFileSync(sourcePath)).digest("hex"),
      source.sha256,
    );
  }
});
