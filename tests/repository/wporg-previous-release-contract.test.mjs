import assert from 'node:assert/strict'
import { createHash } from 'node:crypto'
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import path from 'node:path'
import test from 'node:test'

import {
  fetchPreviousRelease,
  previousReleaseFor,
  verifyPreviousReleaseBytes,
} from '../../scripts/wporg/fetch-previous-release.mjs'

const repository = path.resolve(new URL('../../', import.meta.url).pathname)
const manifestPath = path.join(repository, 'wporg/plugins.json')
const manifest = JSON.parse(await readFile(manifestPath, 'utf8'))
const expectedSlugs = [
  'fchub-fakturownia',
  'fchub-memberships',
  'fchub-multi-currency',
  'fchub-p24',
  'fchub-wishlist',
]

test('every WordPress.org target pins a genuine previous release identity', () => {
  assert.deepEqual(Object.keys(manifest.plugins).sort(), expectedSlugs)

  for (const slug of expectedSlugs) {
    const release = previousReleaseFor(manifest, slug)
    assert.equal(release.tag, `${slug}/v${release.version}`)
    assert.equal(release.asset, `${slug}-${release.version}.zip`)
    assert.match(release.commit, /^[a-f0-9]{40}$/)
    assert.match(release.sha256, /^[a-f0-9]{64}$/)
  }
})

test('previous release bytes are bound to the pinned checksum', () => {
  const bytes = Buffer.from('genuine historical archive fixture')
  const release = {
    asset: 'example.zip',
    sha256: createHash('sha256').update(bytes).digest('hex'),
  }

  assert.equal(verifyPreviousReleaseBytes(bytes, release), release.sha256)
  assert.throws(
    () => verifyPreviousReleaseBytes(Buffer.from('changed'), release),
    /checksum mismatch/,
  )
})

test('fetching a previous release verifies tag, URL, and archive bytes', async () => {
  const temporary = await mkdtemp(path.join(tmpdir(), 'wporg-previous-release-'))
  const outputDirectory = path.join(temporary, 'previous')
  const bytes = Buffer.from('pinned release bytes')
  const sha256 = createHash('sha256').update(bytes).digest('hex')
  const fixtureManifest = {
    plugins: {
      'fchub-wishlist': {
        previousRelease: {
          version: '1.0.1',
          tag: 'fchub-wishlist/v1.0.1',
          commit: '23bf80dbd3e0168083e155d7c88116c563af76a2',
          asset: 'fchub-wishlist-1.0.1.zip',
          sha256,
        },
      },
    },
  }
  const fixtureManifestPath = path.join(temporary, 'plugins.json')
  await writeFile(fixtureManifestPath, JSON.stringify(fixtureManifest))

  let requestedUrl = ''
  const outputPath = await fetchPreviousRelease({
    slug: 'fchub-wishlist',
    outputDirectory,
    manifestPath: fixtureManifestPath,
    resolveTag: () => fixtureManifest.plugins['fchub-wishlist'].previousRelease.commit,
    fetchImpl: async (url) => {
      requestedUrl = url
      return new Response(bytes, { status: 200 })
    },
  })

  assert.equal(
    requestedUrl,
    'https://github.com/vcode-sh/fchub-plugins/releases/download/fchub-wishlist%2Fv1.0.1/fchub-wishlist-1.0.1.zip',
  )
  assert.deepEqual(await readFile(outputPath), bytes)
  await rm(temporary, { recursive: true, force: true })
})

test('fetching rejects a moved historical tag', async () => {
  const release = previousReleaseFor(manifest, 'fchub-p24')
  await assert.rejects(
    fetchPreviousRelease({
      slug: 'fchub-p24',
      outputDirectory: path.join(tmpdir(), 'wporg-previous-release-rejected'),
      resolveTag: () => '0'.repeat(40),
      fetchImpl: async () => {
        throw new Error('download must not start')
      },
    }),
    new RegExp(`resolves to ${'0'.repeat(40)}, expected ${release.commit}`),
  )
})
