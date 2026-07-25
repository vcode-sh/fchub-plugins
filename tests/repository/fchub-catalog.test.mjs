import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import {
  buildCatalogue,
  validateCatalogue,
} from '../../scripts/sync-fchub-catalog.mjs'

const root = new URL('../../', import.meta.url)
const readJson = async (path) =>
  JSON.parse(await readFile(new URL(path, root), 'utf8'))

test('catalogue contains exactly the six stable FCHub products', async () => {
  const metadata = await readJson('web-docs/lib/fchub-products.json')
  const versions = await readJson('web-docs/lib/versions.json')
  const catalogue = buildCatalogue(metadata, versions)

  assert.deepEqual(Object.keys(catalogue.products).sort(), [
    'fchub-fakturownia',
    'fchub-memberships',
    'fchub-multi-currency',
    'fchub-p24',
    'fchub-portal-extender',
    'fchub-wishlist',
  ])
  assert.equal(validateCatalogue(catalogue), true)
})

test('catalogue excludes discontinued, experimental, and separate products', async () => {
  const bundled = await readJson('web-docs/lib/fchub-catalog.json')

  for (const slug of [
    'fchub-stream',
    'fchub-thank-you',
    'fchub-redsys',
    'cartshift',
  ]) {
    assert.equal(bundled.products[slug], undefined)
  }
})

