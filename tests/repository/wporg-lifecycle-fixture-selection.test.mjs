import assert from 'node:assert/strict'
import { execFile } from 'node:child_process'
import { promisify } from 'node:util'
import test from 'node:test'

const execFileAsync = promisify(execFile)
const selector = new URL(
  '../../scripts/wporg/lifecycle-fixtures.sh',
  import.meta.url,
)

async function select(slug) {
  return execFileAsync('bash', [selector.pathname, slug])
}

test('migration fixtures are selected only for their owning plugins', async () => {
  const multiCurrency = await select('fchub-multi-currency')
  const memberships = await select('fchub-memberships')

  assert.equal(multiCurrency.stdout.trim(), 'fchub-multi-currency')
  assert.equal(memberships.stdout.trim(), 'fchub-memberships')
})

test('generic WordPress.org lifecycle targets remain fixture-free', async () => {
  for (const slug of ['fchub-fakturownia', 'fchub-p24', 'fchub-wishlist']) {
    const result = await select(slug)
    assert.equal(result.stdout, '')
  }
})

test('an unknown lifecycle fixture target fails closed', async () => {
  await assert.rejects(
    select('made-up-plugin'),
    (error) =>
      error.code === 2 &&
      /Unsupported WordPress\.org lifecycle fixture target/.test(error.stderr),
  )
})
