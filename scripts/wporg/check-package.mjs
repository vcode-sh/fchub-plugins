#!/usr/bin/env node

import { execFile } from "node:child_process";
import crypto from "node:crypto";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { promisify } from "node:util";
import { pathToFileURL } from "node:url";

const execFileAsync = promisify(execFile);
const archiveLimit = 10_000_000;
const textExtensions = new Set([
  ".css",
  ".html",
  ".htm",
  ".inc",
  ".js",
  ".json",
  ".jsx",
  ".md",
  ".mjs",
  ".php",
  ".scss",
  ".svg",
  ".text",
  ".ts",
  ".tsx",
  ".txt",
  ".vue",
  ".xml",
  ".yml",
  ".yaml",
]);

const repositoryRoot = path.resolve(
  path.dirname(new URL(import.meta.url).pathname),
  "../..",
);

function readJson(filePath) {
  return JSON.parse(fs.readFileSync(filePath, "utf8"));
}

function normalizeEntry(entry) {
  return entry.replaceAll("\\", "/").replace(/^\.\//, "").replace(/\/+$/, "");
}

function parseHeader(contents) {
  const fields = {};
  for (const match of contents.matchAll(
    /^[ \t]*(?:\/?\**[ \t]*)?([A-Za-z][A-Za-z ]+):[ \t]*(.+?)[ \t]*$/gm,
  )) {
    fields[match[1].trim().toLowerCase()] = match[2].trim();
  }
  return fields;
}

function parseReadme(contents) {
  const lines = contents.replaceAll("\r\n", "\n").split("\n");
  const titleMatch = lines[0]?.match(/^===\s*(.+?)\s*===$/);
  const fields = {};
  let cursor = 1;

  for (; cursor < lines.length; cursor += 1) {
    const line = lines[cursor].trim();
    if (line === "") {
      cursor += 1;
      break;
    }
    const match = line.match(/^([A-Za-z][A-Za-z ]+):\s*(.+)$/);
    if (match) {
      fields[match[1].trim().toLowerCase()] = match[2].trim();
    }
  }

  let shortDescription = "";
  for (; cursor < lines.length; cursor += 1) {
    const line = lines[cursor].trim();
    if (line.startsWith("==")) {
      break;
    }
    if (line !== "") {
      shortDescription = line;
      break;
    }
  }

  return {
    title: titleMatch?.[1]?.trim() ?? "",
    fields,
    shortDescription,
  };
}

function requireEqual(actual, expected, label) {
  if (actual !== expected) {
    throw new Error(`${label} must be "${expected}", received "${actual ?? ""}"`);
  }
}

function isInertIndex(contents) {
  const executable = contents
    .replace(/^<\?php\s*/u, "")
    .replace(/\/\*[\s\S]*?\*\//gu, "")
    .replace(/\/\/.*$/gmu, "")
    .replace(/#.*$/gmu, "")
    .trim();
  return executable === "";
}

function walkFiles(directory) {
  const files = [];
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    const absolutePath = path.join(directory, entry.name);
    const stat = fs.lstatSync(absolutePath);
    if (stat.isSymbolicLink()) {
      throw new Error(`symbolic links are forbidden: ${absolutePath}`);
    }
    if (entry.isDirectory()) {
      files.push(...walkFiles(absolutePath));
    } else if (entry.isFile()) {
      files.push(absolutePath);
    }
  }
  return files;
}

function assertSafeEntries(rawEntries, slug) {
  const entries = rawEntries.map((rawEntry) => {
    if (
      rawEntry.startsWith("/") ||
      rawEntry.startsWith("\\") ||
      /^[A-Za-z]:[\\/]/u.test(rawEntry)
    ) {
      throw new Error(`absolute archive path is forbidden: ${rawEntry}`);
    }

    const normalized = normalizeEntry(rawEntry);
    if (
      normalized
        .split("/")
        .some((segment) => segment === ".." || segment === "")
    ) {
      throw new Error(`unsafe archive path is forbidden: ${rawEntry}`);
    }
    return normalized;
  });

  const roots = new Set(entries.map((entry) => entry.split("/")[0]));
  if (roots.size !== 1) {
    throw new Error("archive must contain exactly one top-level directory");
  }
  if (!roots.has(slug)) {
    throw new Error(`archive root must be "${slug}"`);
  }

  for (const entry of entries) {
    const relativePath = entry.slice(slug.length + 1);
    const segments = relativePath.split("/");
    const basename = segments.at(-1);

    if (basename === "GitHubUpdater.php") {
      throw new Error(`forbidden updater file: ${entry}`);
    }
    if (basename === ".DS_Store") {
      throw new Error(`forbidden development litter: ${entry}`);
    }
    if (basename?.endsWith(".map")) {
      throw new Error(`source maps are forbidden: ${entry}`);
    }
    if (basename?.endsWith(".sh")) {
      throw new Error(`application file is forbidden: ${entry}`);
    }
    if (
      segments.some((segment) =>
        [
          ".git",
          ".github",
          ".cache",
          ".phpunit.cache",
          "coverage",
          "node_modules",
          "tests",
          "vendor",
        ].includes(segment),
      )
    ) {
      throw new Error(`development directory is forbidden: ${entry}`);
    }
    if (segments[0] === "dist") {
      throw new Error(`forbidden package-build directory: ${entry}`);
    }
    if (
      basename === ".env" ||
      basename?.startsWith(".env.") ||
      ["auth.json", "credentials.json", "wp-config.php"].includes(basename)
    ) {
      throw new Error(`credential or local configuration file is forbidden: ${entry}`);
    }
  }

  return entries;
}

function assertTextSafety(filePath, relativePath) {
  const extension = path.extname(filePath).toLowerCase();
  if (!textExtensions.has(extension) || fs.statSync(filePath).size > 5_000_000) {
    return;
  }

  const contents = fs.readFileSync(filePath, "utf8");
  const checks = [
    [/^\s*(?:\*\s*)?Update URI\s*:/imu, "Update URI"],
    [/fonts\.googleapis\.com/iu, "fonts.googleapis.com"],
    [/fonts\.gstatic\.com/iu, "fonts.gstatic.com"],
    [/\beval\s*\(/iu, "eval("],
    [
      /(?:api\.)?github\.com\/[^\s"']*(?:releases|latest)|githubusercontent\.com/iu,
      "GitHub release updater hook",
    ],
  ];

  for (const [pattern, label] of checks) {
    if (pattern.test(contents)) {
      throw new Error(`${label} is forbidden in ${relativePath}`);
    }
  }
}

function assertPhpEntrypoints(pluginRoot, files, mainFile) {
  for (const filePath of files) {
    if (path.extname(filePath).toLowerCase() !== ".php") {
      continue;
    }

    const relativePath = path.relative(pluginRoot, filePath).replaceAll("\\", "/");
    const contents = fs.readFileSync(filePath, "utf8");
    if (relativePath === "index.php" && isInertIndex(contents)) {
      continue;
    }
    if (
      relativePath === "uninstall.php" &&
      /defined\s*\(\s*['"]WP_UNINSTALL_PLUGIN['"]\s*\)/u.test(contents)
    ) {
      continue;
    }

    const isRootEntrypoint = !relativePath.includes("/") || relativePath === mainFile;
    if (
      isRootEntrypoint &&
      !/defined\s*\(\s*['"]ABSPATH['"]\s*\)/u.test(contents)
    ) {
      throw new Error(`PHP entrypoint lacks direct-access guard: ${relativePath}`);
    }
  }
}

export async function inspectZip(zipPath, slug) {
  if (!path.isAbsolute(zipPath)) {
    zipPath = path.resolve(zipPath);
  }
  if (!fs.existsSync(zipPath)) {
    throw new Error(`archive does not exist: ${zipPath}`);
  }

  const compressedBytes = fs.statSync(zipPath).size;
  if (compressedBytes >= archiveLimit) {
    throw new Error(
      `compressed archive must be smaller than ${archiveLimit} bytes; received ${compressedBytes}`,
    );
  }

  const manifest = readJson(path.join(repositoryRoot, "wporg/plugins.json"));
  const publisher = readJson(path.join(repositoryRoot, "wporg/publisher.json"));
  const plugin = manifest.plugins?.[slug];
  if (!plugin) {
    throw new Error(`unknown WordPress.org plugin slug: ${slug}`);
  }

  const { stdout } = await execFileAsync("unzip", ["-Z1", zipPath], {
    encoding: "utf8",
    maxBuffer: 10 * 1024 * 1024,
  });
  const rawEntries = stdout.split(/\r?\n/u).filter(Boolean);
  const entries = assertSafeEntries(rawEntries, slug);
  const requiredEntries = [
    `${slug}/${plugin.mainFile}`,
    `${slug}/readme.txt`,
    `${slug}/LICENSE`,
  ];
  for (const requiredEntry of requiredEntries) {
    if (!entries.includes(requiredEntry)) {
      throw new Error(`archive is missing required ${requiredEntry}`);
    }
  }

  const temporaryDirectory = fs.mkdtempSync(
    path.join(os.tmpdir(), `fchub-wporg-${slug}-`),
  );
  try {
    await execFileAsync("unzip", ["-qq", zipPath, "-d", temporaryDirectory], {
      maxBuffer: 10 * 1024 * 1024,
    });
    const pluginRoot = path.join(temporaryDirectory, slug);
    const files = walkFiles(pluginRoot);

    for (const filePath of files) {
      assertTextSafety(
        filePath,
        path.relative(temporaryDirectory, filePath).replaceAll("\\", "/"),
      );
    }
    assertPhpEntrypoints(pluginRoot, files, plugin.mainFile);

    const mainContents = fs.readFileSync(
      path.join(pluginRoot, plugin.mainFile),
      "utf8",
    );
    const header = parseHeader(mainContents);
    requireEqual(header["plugin name"], plugin.displayName, "Plugin Name");
    requireEqual(header.version, plugin.firstWpOrgVersion, "Version");
    requireEqual(
      header["requires at least"],
      plugin.requiresWordPress,
      "Requires at least",
    );
    requireEqual(header["requires php"], plugin.requiresPhp, "Requires PHP");
    requireEqual(header["tested up to"], plugin.testedUpTo, "Tested up to");
    requireEqual(
      header["requires plugins"],
      plugin.requiresPlugins.join(", "),
      "Requires Plugins",
    );
    if (!header.description || header.description.length >= 140) {
      throw new Error("plugin header Description must be present and below 140 characters");
    }

    const readmeContents = fs.readFileSync(
      path.join(pluginRoot, "readme.txt"),
      "utf8",
    );
    const parsedReadme = parseReadme(readmeContents);
    requireEqual(parsedReadme.title, plugin.displayName, "readme name");
    requireEqual(
      parsedReadme.fields["stable tag"],
      plugin.firstWpOrgVersion,
      "Stable tag",
    );
    requireEqual(
      parsedReadme.fields["requires at least"],
      plugin.requiresWordPress,
      "readme Requires at least",
    );
    requireEqual(
      parsedReadme.fields["requires php"],
      plugin.requiresPhp,
      "readme Requires PHP",
    );
    requireEqual(
      parsedReadme.fields["tested up to"],
      plugin.testedUpTo,
      "readme Tested up to",
    );
    requireEqual(
      parsedReadme.fields.contributors,
      publisher.wordpressOrgUsername,
      "Contributors",
    );
    if (
      !parsedReadme.shortDescription ||
      parsedReadme.shortDescription.length >= 150
    ) {
      throw new Error(
        "readme short description must be present and below 150 characters",
      );
    }

    const uncompressedBytes = files.reduce(
      (total, filePath) => total + fs.statSync(filePath).size,
      0,
    );
    const sha256 = crypto
      .createHash("sha256")
      .update(fs.readFileSync(zipPath))
      .digest("hex");

    return {
      slug,
      version: plugin.firstWpOrgVersion,
      sha256,
      fileCount: files.length,
      compressedBytes,
      uncompressedBytes,
      checks: [
        "archive-paths",
        "required-files",
        "forbidden-files",
        "text-safety",
        "headers",
        "readme",
        "php-entrypoints",
        "size",
      ],
    };
  } finally {
    fs.rmSync(temporaryDirectory, { recursive: true, force: true });
  }
}

async function main() {
  const [zipPath, slug] = process.argv.slice(2);
  if (!zipPath || !slug) {
    throw new Error("usage: check-package.mjs <absolute-or-relative-zip> <slug>");
  }
  const result = await inspectZip(zipPath, slug);
  process.stdout.write(`${JSON.stringify(result, null, 2)}\n`);
}

if (
  process.argv[1] &&
  import.meta.url === pathToFileURL(path.resolve(process.argv[1])).href
) {
  main().catch((error) => {
    const [zipPath, slug] = process.argv.slice(2);
    process.stdout.write(
      `${JSON.stringify(
        {
          slug: slug ?? null,
          archive: zipPath ? path.resolve(zipPath) : null,
          error: error.message,
        },
        null,
        2,
      )}\n`,
    );
    process.stderr.write(`${error.message}\n`);
    process.exitCode = 1;
  });
}
