import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

const script = fileURLToPath(new URL('./ci-scope.mjs', import.meta.url))

function select(files, ...args) {
  const result = spawnSync(process.execPath, [script, ...args], {
    encoding: 'utf8',
    input: `${files.join('\n')}\n`,
  })

  assert.equal(result.status, 0, result.stderr)
  return Object.fromEntries(
    result.stdout
      .trim()
      .split('\n')
      .map((line) => {
        const separator = line.indexOf('=')
        return [line.slice(0, separator), line.slice(separator + 1)]
      }),
  )
}

test('a CartShift change selects only its PHP and frontend package gates', () => {
  assert.deepEqual(select(['plugins/cartshift/app/Bootstrap.php']), {
    php_plugins: '["cartshift"]',
    wporg_plugins: '[]',
    cartshift: 'true',
    memberships: 'false',
    portal_extender: 'false',
  })
})

test('a Memberships change selects its PHP, frontend, and WordPress.org package gates', () => {
  assert.deepEqual(select(['plugins/fchub-memberships/src/Grant.php']), {
    php_plugins: '["fchub-memberships"]',
    wporg_plugins: '["fchub-memberships"]',
    cartshift: 'false',
    memberships: 'true',
    portal_extender: 'false',
  })
})

test('the shared build selects every distributable package without unrelated PHP suites', () => {
  assert.deepEqual(select(['build.sh']), {
    php_plugins: '[]',
    wporg_plugins:
      '["fchub-fakturownia","fchub-memberships","fchub-multi-currency","fchub-p24","fchub-wishlist"]',
    cartshift: 'true',
    memberships: 'false',
    portal_extender: 'true',
  })
})

test('workflow changes and manual runs select the complete maintained CI surface', () => {
  const expected = {
    php_plugins:
      '["cartshift","fchub-fakturownia","fchub-memberships","fchub-multi-currency","fchub-p24","fchub-portal-extender","fchub-thank-you","fchub-wishlist"]',
    wporg_plugins:
      '["fchub-fakturownia","fchub-memberships","fchub-multi-currency","fchub-p24","fchub-wishlist"]',
    cartshift: 'true',
    memberships: 'true',
    portal_extender: 'true',
  }

  assert.deepEqual(select(['.github/workflows/ci.yml']), expected)
  assert.deepEqual(select([], '--all'), expected)
})

test('unowned paths do not start plugin runners', () => {
  assert.deepEqual(select(['web-docs/app/docs/page.tsx']), {
    php_plugins: '[]',
    wporg_plugins: '[]',
    cartshift: 'false',
    memberships: 'false',
    portal_extender: 'false',
  })
})
