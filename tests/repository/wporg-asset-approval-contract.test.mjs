import assert from "node:assert/strict";
import crypto from "node:crypto";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import test from "node:test";

import { validateAssetApproval } from "../../scripts/wporg/check-asset-approval.mjs";

const repositoryRoot = path.resolve(import.meta.dirname, "../..");

function sha256(filePath) {
  return crypto.createHash("sha256").update(fs.readFileSync(filePath)).digest("hex");
}

test("the repository records either an untouched draft or an exact owner approval", () => {
  const manifest = JSON.parse(
    fs.readFileSync(path.join(repositoryRoot, "wporg", "asset-review.json"), "utf8"),
  );

  assert.ok(["draft", "approved"].includes(manifest.status));
  if (manifest.status === "draft") {
    assert.equal(manifest.approvedBy, null);
    assert.equal(manifest.approvedAt, null);
    assert.deepEqual(manifest.assets, {});
    return;
  }

  assert.doesNotThrow(() => validateAssetApproval({ repositoryRoot }));
});

test("approval binds every reviewed PNG to its exact bytes", () => {
  const fixtureRoot = fs.mkdtempSync(path.join(os.tmpdir(), "fchub-wporg-assets-"));
  const assetDirectory = path.join(fixtureRoot, "wporg", "assets", "example-plugin");
  const assetPath = path.join(assetDirectory, "banner-772x250.png");
  const manifestPath = path.join(fixtureRoot, "wporg", "asset-review.json");

  fs.mkdirSync(assetDirectory, { recursive: true });
  fs.writeFileSync(assetPath, Buffer.from("reviewed-image"));
  fs.writeFileSync(
    manifestPath,
    JSON.stringify({
      schemaVersion: 1,
      status: "approved",
      approvedBy: "Vibe Code",
      approvedAt: "2026-07-26T18:00:00Z",
      assets: {
        "wporg/assets/example-plugin/banner-772x250.png": sha256(assetPath),
      },
    }),
  );

  assert.doesNotThrow(() => validateAssetApproval({ repositoryRoot: fixtureRoot }));

  fs.appendFileSync(assetPath, "changed-after-approval");
  assert.throws(
    () => validateAssetApproval({ repositoryRoot: fixtureRoot }),
    /changed after approval/,
  );
});

test("approval rejects missing and unreviewed PNG files", () => {
  const fixtureRoot = fs.mkdtempSync(path.join(os.tmpdir(), "fchub-wporg-assets-"));
  const assetDirectory = path.join(fixtureRoot, "wporg", "assets", "example-plugin");
  const manifestPath = path.join(fixtureRoot, "wporg", "asset-review.json");

  fs.mkdirSync(assetDirectory, { recursive: true });
  fs.writeFileSync(path.join(assetDirectory, "icon-128x128.png"), Buffer.from("icon"));
  fs.writeFileSync(
    manifestPath,
    JSON.stringify({
      schemaVersion: 1,
      status: "approved",
      approvedBy: "Vibe Code",
      approvedAt: "2026-07-26T18:00:00Z",
      assets: {},
    }),
  );

  assert.throws(
    () => validateAssetApproval({ repositoryRoot: fixtureRoot }),
    /was not reviewed/,
  );
});
