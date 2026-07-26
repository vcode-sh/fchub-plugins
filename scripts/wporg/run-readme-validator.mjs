#!/usr/bin/env node

import { mkdir, readFile, writeFile } from "node:fs/promises";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const endpoint = "https://wordpress.org/plugins/developers/readme-validator/";

function decodeHtml(value) {
  return value
    .replace(/<[^>]+>/g, "")
    .replace(/&#(\d+);/g, (_, number) => String.fromCodePoint(Number(number)))
    .replace(/&quot;/g, '"')
    .replace(/&#039;|&apos;/g, "'")
    .replace(/&amp;/g, "&")
    .replace(/&lt;/g, "<")
    .replace(/&gt;/g, ">")
    .trim();
}

export function parseValidatorHtml(html) {
  const result = { errors: [], warnings: [], notes: [] };
  const noticePattern =
    /<div[^>]*class=(["'])[^"']*notice-(error|warning|info)[^"']*\1[^>]*>([\s\S]*?)<\/div>/gi;

  for (const match of html.matchAll(noticePattern)) {
    const bucket =
      match[2].toLowerCase() === "error"
        ? "errors"
        : match[2].toLowerCase() === "warning"
          ? "warnings"
          : "notes";
    for (const item of match[3].matchAll(/<li[^>]*>([\s\S]*?)<\/li>/gi)) {
      result[bucket].push(decodeHtml(item[1]));
    }
  }

  return result;
}

export async function validateReadme(readmePath, slug, outputPath) {
  const readme = await readFile(readmePath, "utf8");
  const body = new URLSearchParams({
    readme: "",
    readme_contents: Buffer.from(readme, "utf8").toString("base64"),
  });
  const response = await fetch(endpoint, {
    method: "POST",
    headers: {
      "content-type": "application/x-www-form-urlencoded",
      "user-agent": "FCHub-WordPressOrg-Readme-Validator/1.0",
    },
    body,
  });

  if (!response.ok) {
    throw new Error(
      `WordPress.org readme validator returned HTTP ${response.status}`,
    );
  }

  const notices = parseValidatorHtml(await response.text());
  const report = {
    plugin: slug,
    validator: endpoint,
    checkedAt: new Date().toISOString(),
    ...notices,
  };

  await mkdir(dirname(outputPath), { recursive: true });
  await writeFile(outputPath, `${JSON.stringify(report, null, 2)}\n`);

  if (notices.errors.length > 0 || notices.warnings.length > 0) {
    throw new Error(
      `Readme validation failed for ${slug}: ${notices.errors.length} errors, ${notices.warnings.length} warnings`,
    );
  }

  return report;
}

async function main() {
  const [, , readmeArgument, slug, outputArgument] = process.argv;
  if (!readmeArgument || !slug) {
    throw new Error(
      "Usage: node run-readme-validator.mjs <readme.txt> <slug> [output.json]",
    );
  }

  const readmePath = resolve(readmeArgument);
  const outputPath = resolve(
    outputArgument ?? `test-results/wporg/${slug}/readme-validator.json`,
  );
  const report = await validateReadme(readmePath, slug, outputPath);
  process.stdout.write(
    `Readme validator passed for ${slug} (${report.notes.length} notes).\n`,
  );
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  main().catch((error) => {
    process.stderr.write(`${error.message}\n`);
    process.exitCode = 1;
  });
}
