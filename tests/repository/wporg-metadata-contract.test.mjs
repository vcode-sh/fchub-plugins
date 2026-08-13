import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const root = new URL('../../', import.meta.url)
const readJson = async (path) =>
  JSON.parse(await readFile(new URL(path, root), 'utf8'))

const expectedVersions = {
  'fchub-memberships': '1.4.6',
  'fchub-wishlist': '1.0.3',
  'fchub-multi-currency': '1.4.3',
  'fchub-p24': '1.0.4',
  'fchub-fakturownia': '1.1.2',
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
    version: '1.4.2',
    tag: 'fchub-multi-currency/v1.4.2',
    commit: '541ab795d7b9007bf3db76042ef0a2d3e9840353',
    asset: 'fchub-multi-currency-1.4.2.zip',
    sha256: '76426ed287e9eed6a578948bb36213665356a78a1ec6d87b4f81bb3561a859ec',
  },
  'fchub-p24': {
    version: '1.0.3',
    tag: 'fchub-p24/v1.0.3',
    commit: '23bf80dbd3e0168083e155d7c88116c563af76a2',
    asset: 'fchub-p24-1.0.3.zip',
    sha256: 'd39712c3b0e59776b372468e0c94b10e550b7c2f727b2f53db34debd3fb6a6b4',
  },
  'fchub-fakturownia': {
    version: '1.1.1',
    tag: 'fchub-fakturownia/v1.1.1',
    commit: '57640fed9823dbc3405d15de322b9451c01fbd54',
    asset: 'fchub-fakturownia-1.1.1.zip',
    sha256: 'ecb63aea5a7662e0debca50f498584132a1e15cb2d3f8ea20b9a622c3badf793',
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
