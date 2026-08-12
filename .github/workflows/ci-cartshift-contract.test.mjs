import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const ci = readFileSync(new URL('./ci.yml', import.meta.url), 'utf8')
const release = readFileSync(new URL('./release.yml', import.meta.url), 'utf8')

function job(workflow, name) {
  const match = workflow.match(
    new RegExp(`^  ${name}:\\n([\\s\\S]*?)(?=^  [A-Za-z0-9_-]+:\\n|(?![\\s\\S]))`, 'm'),
  )
  assert.ok(match, `Expected ${name} job`)
  return match[1]
}

test('public workflows never require private CartShift vendor archives', () => {
  for (const contract of [
    'CARTSHIFT_WOO_ARTIFACT_URL',
    'CARTSHIFT_WOO_SHA256',
    'CARTSHIFT_WCS_ARTIFACT_URL',
    'CARTSHIFT_WCS_SHA256',
    'CARTSHIFT_FLUENTCART_ARTIFACT_URL',
    'CARTSHIFT_FLUENTCART_SHA256',
    'run-installed-contracts.sh',
  ]) {
    assert.doesNotMatch(ci, new RegExp(contract), `CI must not require ${contract}`)
    assert.doesNotMatch(release, new RegExp(contract), `Release must not require ${contract}`)
  }
  assert.doesNotMatch(ci, /^  cartshift-installed-contracts:\n/m)
  assert.doesNotMatch(release, /^  cartshift-contract:\n/m)
})

test('CartShift CI verifies the distributable archive from public source', () => {
  const frontend = job(ci, 'vite-build-cartshift')

  assert.match(frontend, /needs: changes/)
  assert.match(frontend, /if: needs\.changes\.outputs\.cartshift == 'true'/)
  assert.doesNotMatch(frontend, /Check for changes/)
  assert.doesNotMatch(frontend, /steps\.changes/)
  assert.match(frontend, /bash tests\/build-reproducibility\.sh/)
  assert.match(frontend, /working-directory: \$\{\{ github\.workspace \}\}/)
})

test('CartShift release uses the standard public-source package path', () => {
  const publish = job(release, 'release')

  assert.match(publish, /needs:\s*\[prepare,\s*wporg-gates\]/)
  assert.match(publish, /if: needs\.prepare\.outputs\.is_wporg != 'true'/)
  assert.match(publish, /bash build\.sh "\$\{\{ needs\.prepare\.outputs\.slug \}\}"/)
  assert.doesNotMatch(publish, /cartshift-candidate-/)
  assert.doesNotMatch(publish, /slug != 'cartshift'/)
})
