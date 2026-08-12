import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const ci = readFileSync(new URL('./ci.yml', import.meta.url), 'utf8')
const release = readFileSync(new URL('./release.yml', import.meta.url), 'utf8')
const compose = readFileSync(
  new URL('../../plugins/cartshift/docker-compose.integration.yml', import.meta.url),
  'utf8',
)
const createStack = readFileSync(
  new URL(
    '../../plugins/cartshift/tests/Integration/scripts/create-disposable-stack.sh',
    import.meta.url,
  ),
  'utf8',
)

function job(workflow, name) {
  const match = workflow.match(
    new RegExp(`^  ${name}:\\n([\\s\\S]*?)(?=^  [A-Za-z0-9_-]+:\\n|(?![\\s\\S]))`, 'm'),
  )
  assert.ok(match, `Expected ${name} job`)
  return match[1]
}

test('CI installs the checksum-pinned CartShift candidate with every mandatory vendor', () => {
  const installed = job(ci, 'cartshift-installed-contracts')
  for (const contract of [
    'CARTSHIFT_CANDIDATE_SHA256',
    'CARTSHIFT_WOO_ARTIFACT_URL',
    'CARTSHIFT_WOO_SHA256',
    'CARTSHIFT_WCS_ARTIFACT_URL',
    'CARTSHIFT_WCS_SHA256',
    'CARTSHIFT_FLUENTCART_ARTIFACT_URL',
    'CARTSHIFT_FLUENTCART_SHA256',
    'run-installed-contracts.sh',
  ]) {
    assert.match(installed, new RegExp(contract), `Missing installed contract boundary: ${contract}`)
  }
  assert.match(installed, /curl --fail[\s\S]*?--proto '=https'[\s\S]*?sha256sum --check --status/)
  assert.match(installed, /CARTSHIFT_CONTRACT_RETAIN_EVIDENCE_DIR/)
})

test('private CartShift vendor artifacts only reach trusted main pushes', () => {
  const installed = job(ci, 'cartshift-installed-contracts')

  assert.match(ci, /push:\n\s+branches: \[main\]/)
  assert.match(installed, /^    if: github\.event_name == 'push'$/m)
})

test('the disposable runtime does not overlay source over the installed candidate', () => {
  assert.doesNotMatch(compose, /wp-content\/plugins\/cartshift:ro/)
  assert.match(compose, /CARTSHIFT_SOURCE_DIR[^\n]*:\/cartshift-source:ro/)
  assert.match(createStack, /plugin install \/cartshift-artifacts\/cartshift-candidate\.zip --force --activate/)
  assert.match(createStack, /CARTSHIFT_WCS_ZIP CARTSHIFT_WCS_SHA256/)
  assert.doesNotMatch(createStack, /if \[ -f "\$\{artifact_dir\}\/woocommerce-subscriptions\.zip" \]/)
})

test('CartShift release publication is contract-gated and consumes the tested candidate', () => {
  const publish = job(release, 'release')
  assert.match(release, /^  cartshift-contract:\n/m)
  assert.match(publish, /needs:\s*\[prepare,\s*wporg-gates,\s*cartshift-contract\]/)
  assert.match(publish, /cartshift-candidate-/)
  assert.match(publish, /if: needs\.prepare\.outputs\.is_wporg != 'true' && needs\.prepare\.outputs\.slug != 'cartshift'/)
})
