#!/usr/bin/env node

import { createHash } from 'node:crypto'
import { execFileSync } from 'node:child_process'
import { mkdir, readFile, writeFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const scriptPath = fileURLToPath(import.meta.url)
const repository = path.resolve(path.dirname(scriptPath), '../..')
const defaultManifestPath = path.join(repository, 'wporg/plugins.json')

export function previousReleaseFor(manifest, slug) {
  if (!/^[a-z0-9][a-z0-9-]*$/.test(slug)) {
    throw new Error(`Invalid WordPress.org plugin slug: ${slug}`)
  }

  const release = manifest.plugins?.[slug]?.previousRelease
  if (!release) {
    throw new Error(`Missing previous release metadata for ${slug}`)
  }

  const expectedAsset = `${slug}-${release.version}.zip`
  if (
    !/^\d+\.\d+\.\d+$/.test(release.version)
    || release.tag !== `${slug}/v${release.version}`
    || release.asset !== expectedAsset
    || !/^[a-f0-9]{40}$/.test(release.commit)
    || !/^[a-f0-9]{64}$/.test(release.sha256)
  ) {
    throw new Error(`Invalid previous release metadata for ${slug}`)
  }

  return release
}

export function verifyPreviousReleaseBytes(bytes, release) {
  const actual = createHash('sha256').update(bytes).digest('hex')
  if (actual !== release.sha256) {
    throw new Error(
      `Previous release checksum mismatch for ${release.asset}: expected ${release.sha256}, received ${actual}`,
    )
  }
  return actual
}

function resolveLocalTag(tag) {
  return execFileSync('git', ['rev-parse', '--verify', `${tag}^{commit}`], {
    cwd: repository,
    encoding: 'utf8',
  }).trim()
}

export async function fetchPreviousRelease({
  slug,
  outputDirectory,
  manifestPath = defaultManifestPath,
  fetchImpl = globalThis.fetch,
  resolveTag = resolveLocalTag,
}) {
  if (!path.isAbsolute(outputDirectory)) {
    throw new Error('The previous release output directory must be absolute')
  }

  const manifest = JSON.parse(await readFile(manifestPath, 'utf8'))
  const release = previousReleaseFor(manifest, slug)
  const resolvedCommit = resolveTag(release.tag)
  if (resolvedCommit !== release.commit) {
    throw new Error(
      `Previous release tag ${release.tag} resolves to ${resolvedCommit}, expected ${release.commit}`,
    )
  }

  const url = `https://github.com/vcode-sh/fchub-plugins/releases/download/${encodeURIComponent(release.tag)}/${release.asset}`
  const response = await fetchImpl(url, { redirect: 'follow' })
  if (!response.ok) {
    throw new Error(
      `Unable to download ${release.asset}: HTTP ${response.status}`,
    )
  }

  const bytes = Buffer.from(await response.arrayBuffer())
  verifyPreviousReleaseBytes(bytes, release)

  await mkdir(outputDirectory, { recursive: true })
  const outputPath = path.join(outputDirectory, release.asset)
  await writeFile(outputPath, bytes, { mode: 0o644 })
  return outputPath
}

async function main() {
  const [slug, outputDirectory] = process.argv.slice(2)
  if (!slug || !outputDirectory) {
    throw new Error(
      'Usage: fetch-previous-release.mjs <plugin-slug> <absolute-output-directory>',
    )
  }

  const outputPath = await fetchPreviousRelease({ slug, outputDirectory })
  process.stdout.write(`${outputPath}\n`)
}

if (process.argv[1] && path.resolve(process.argv[1]) === scriptPath) {
  main().catch((error) => {
    process.stderr.write(`${error.message}\n`)
    process.exitCode = 1
  })
}
