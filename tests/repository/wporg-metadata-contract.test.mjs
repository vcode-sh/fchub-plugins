import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const root = new URL('../../', import.meta.url)
const readJson = async (path) =>
  JSON.parse(await readFile(new URL(path, root), 'utf8'))

const expectedVersions = {
  'fchub-memberships': '1.4.6',
  'fchub-wishlist': '1.0.3',
  'fchub-multi-currency': '1.4.6',
  'fchub-p24': '1.0.5',
  'fchub-fakturownia': '1.1.3',
}

const expectedPreviousReleases = {
  'fchub-memberships': {
    version: '1.4.5',
    tag: 'fchub-memberships/v1.4.5',
    commit: '08e4e4bd7671b8da379474ae0b49fd839d3d0c3d',
    asset: 'fchub-memberships-1.4.5.zip',
    sha256: '328e67e884ce5afafd263bb683e74d6f34aca6d1caf126c3d5c2a755fae0d1a9',
  },
  'fchub-wishlist': {
    version: '1.0.2',
    tag: 'fchub-wishlist/v1.0.2',
    commit: '08e4e4bd7671b8da379474ae0b49fd839d3d0c3d',
    asset: 'fchub-wishlist-1.0.2.zip',
    sha256: '44ce62f2b8f75def910221ae720791c60803ace786a89a1557de45de9d6f892b',
  },
  'fchub-multi-currency': {
    version: '1.4.5',
    tag: 'fchub-multi-currency/v1.4.5',
    commit: '6bbe775ce443519588a65939ba83958568998e50',
    asset: 'fchub-multi-currency-1.4.5.zip',
    sha256: 'a5440d4c4dfacaa8711f442d9efbf2b382ec471ea7979504fd3b793e2a5a47bc',
  },
  'fchub-p24': {
    version: '1.0.4',
    tag: 'fchub-p24/v1.0.4',
    commit: '08e4e4bd7671b8da379474ae0b49fd839d3d0c3d',
    asset: 'fchub-p24-1.0.4.zip',
    sha256: 'deff34579a8f91663ea49f7c025bd1c9716535fc5216d654340fe8020d4f505d',
  },
  'fchub-fakturownia': {
    version: '1.1.2',
    tag: 'fchub-fakturownia/v1.1.2',
    commit: '08e4e4bd7671b8da379474ae0b49fd839d3d0c3d',
    asset: 'fchub-fakturownia-1.1.2.zip',
    sha256: 'ee55bc3a20f4988d3a56b58e29a6be914a99f95c97cdb4f1c369e41307b260a0',
  },
}

test('WordPress.org bridge metadata matches the candidate manifest', async () => {
  const manifest = await readJson('wporg/plugins.json')
  const products = await readJson('web-docs/lib/fchub-products.json')
  const versions = await readJson('web-docs/lib/versions.json')

  for (const [slug, version] of Object.entries(expectedVersions)) {
    const candidate = manifest.plugins[slug]
    const release = versions.plugins[slug]
    const product = products.products[slug]

    assert.equal(candidate.firstWpOrgVersion, version)
    assert.deepEqual(candidate.previousRelease, expectedPreviousReleases[slug])
    assert.equal(release.version, version)
    assert.equal(release.tagName, `${slug}/v${version}`)
    assert.equal(release.zipFilename, `${slug}-${version}.zip`)
    assert.equal(product.requires_wp, candidate.requiresWordPress)
    assert.equal(product.requires_php, candidate.requiresPhp)
    assert.deepEqual(product.dependencies, ['fluentcart'])
  }
})
