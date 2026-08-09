import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const root = new URL('../../', import.meta.url)
const readJson = async (path) =>
  JSON.parse(await readFile(new URL(path, root), 'utf8'))

const expectedVersions = {
  'fchub-memberships': '1.4.4',
  'fchub-wishlist': '1.0.2',
  // 1.4.1 was prepared but never tagged, so 1.4.2 is the first version to reach WordPress.org.
  'fchub-multi-currency': '1.4.2',
  'fchub-p24': '1.0.4',
  'fchub-fakturownia': '1.1.2',
}

const expectedPreviousReleases = {
  'fchub-memberships': {
    version: '1.4.3',
    tag: 'fchub-memberships/v1.4.3',
    commit: 'cac777fe19df939fdc4f2b7c27321db5f125a161',
    asset: 'fchub-memberships-1.4.3.zip',
    sha256: 'a692436064c1e4791234890064e0d46f5a9bb681e10e073fd1177d21b27daee7',
  },
  'fchub-wishlist': {
    version: '1.0.1',
    tag: 'fchub-wishlist/v1.0.1',
    commit: '23bf80dbd3e0168083e155d7c88116c563af76a2',
    asset: 'fchub-wishlist-1.0.1.zip',
    sha256: '4a0be059a06c6c56c985b34d61cf6413295a4e58fe8ba550c4e9cd26342d7737',
  },
  'fchub-multi-currency': {
    version: '1.4.0',
    tag: 'fchub-multi-currency/v1.4.0',
    commit: 'f92a5f101c719146b06982f8e83acce4393df0ff',
    asset: 'fchub-multi-currency-1.4.0.zip',
    sha256: 'c235fc3384eb938008e5cde468a4a242d2aeecf41a2c892f375ca160230d55a0',
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
