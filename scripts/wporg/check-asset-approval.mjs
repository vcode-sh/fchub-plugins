#!/usr/bin/env node

import crypto from "node:crypto";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const defaultRepositoryRoot = path.resolve(import.meta.dirname, "../..");

function listPngFiles(directory) {
  if (!fs.existsSync(directory)) {
    return [];
  }

  return fs
    .readdirSync(directory, { withFileTypes: true })
    .flatMap((entry) => {
      const entryPath = path.join(directory, entry.name);
      if (entry.isDirectory()) {
        return listPngFiles(entryPath);
      }

      return entry.isFile() && entry.name.toLowerCase().endsWith(".png")
        ? [entryPath]
        : [];
    })
    .sort();
}

function sha256(filePath) {
  return crypto.createHash("sha256").update(fs.readFileSync(filePath)).digest("hex");
}

export function validateAssetApproval({
  repositoryRoot = defaultRepositoryRoot,
} = {}) {
  const manifestPath = path.join(repositoryRoot, "wporg", "asset-review.json");
  const assetRoot = path.join(repositoryRoot, "wporg", "assets");
  const manifest = JSON.parse(fs.readFileSync(manifestPath, "utf8"));

  if (manifest.schemaVersion !== 1) {
    throw new Error("The asset review manifest uses an unsupported schema version.");
  }

  if (manifest.status !== "approved") {
    throw new Error("Directory artwork is still a draft and has not been approved.");
  }

  if (manifest.approvedBy !== "Vibe Code") {
    throw new Error("Directory artwork must be approved by Vibe Code.");
  }

  if (
    typeof manifest.approvedAt !== "string" ||
    Number.isNaN(Date.parse(manifest.approvedAt))
  ) {
    throw new Error("Directory artwork approval must include a valid timestamp.");
  }

  if (
    manifest.assets === null ||
    typeof manifest.assets !== "object" ||
    Array.isArray(manifest.assets)
  ) {
    throw new Error("Directory artwork approval must include an asset hash map.");
  }

  const currentAssets = listPngFiles(assetRoot).map((filePath) =>
    path.relative(repositoryRoot, filePath).replaceAll(path.sep, "/"),
  );
  const reviewedAssets = Object.keys(manifest.assets).sort();

  for (const asset of currentAssets) {
    if (!(asset in manifest.assets)) {
      throw new Error(`${asset} was not reviewed.`);
    }

    const currentHash = sha256(path.join(repositoryRoot, asset));
    if (manifest.assets[asset] !== currentHash) {
      throw new Error(`${asset} changed after approval.`);
    }
  }

  for (const asset of reviewedAssets) {
    if (!currentAssets.includes(asset)) {
      throw new Error(`${asset} was approved but is now missing.`);
    }
  }

  return {
    approvedAt: manifest.approvedAt,
    approvedBy: manifest.approvedBy,
    assetCount: currentAssets.length,
  };
}

const isCommandLine = process.argv[1]
  ? fileURLToPath(import.meta.url) === path.resolve(process.argv[1])
  : false;

if (isCommandLine) {
  try {
    const result = validateAssetApproval();
    console.log(
      `Approved ${result.assetCount} WordPress.org PNG assets by ${result.approvedBy} at ${result.approvedAt}.`,
    );
  } catch (error) {
    console.error(error instanceof Error ? error.message : String(error));
    process.exitCode = 1;
  }
}
