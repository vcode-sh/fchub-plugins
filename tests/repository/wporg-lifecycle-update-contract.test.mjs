import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const source = await readFile(
  new URL('../../scripts/wporg/run-lifecycle.sh', import.meta.url),
  'utf8',
)

function position(pattern) {
  const match = source.match(pattern)
  assert.ok(match, `Expected lifecycle source to match ${pattern}`)
  return match.index
}

test('lifecycle validates and mounts a genuine previous release archive', () => {
  assert.match(source, /previous_zip_path=.*WPORG_PREVIOUS_ZIP/)
  assert.match(source, /previous release ZIP must be an existing absolute path/)
  assert.match(source, /cp "\$previous_zip_path" "\$fixture_dir\/previous\.zip"/)
  assert.match(source, /printf 'previous=%s\\n' "\$previous_zip_path"/)
})

test('lifecycle proves dependency guard, fresh activation, and in-place update', () => {
  const dependencyGuard = position(/wp plugin activate "\$slug" >"\$absent_log"/)
  const freshCandidate = source.indexOf(
    'wp plugin install /wporg-fixture/candidate.zip >>"$runtime_log"',
    dependencyGuard,
  )
  const previousInstall = position(
    /wp plugin install \/wporg-fixture\/previous\.zip >>"\$runtime_log"/,
  )
  const updateInstall = position(
    /wp plugin install \/wporg-fixture\/candidate\.zip --force/,
  )

  assert.ok(dependencyGuard < freshCandidate)
  assert.ok(freshCandidate < previousInstall)
  assert.ok(previousInstall < updateInstall)
  assert.match(source, /installed_previous_version/)
  assert.match(source, /updated_version/)
  assert.match(source, /updated_status/)
  assert.match(source, /was not active after updating from/)
})

test('lifecycle guards early fixture cleanup and proves dependency reactivation', () => {
  const fixtureCreated = position(/fixture_dir=.*mktemp -d/)
  const earlyTrap = position(/trap cleanup_fixture_before_runtime EXIT INT TERM/)
  const candidateCopy = position(/cp "\$zip_path" "\$fixture_dir\/candidate\.zip"/)
  const observerInstall = position(/^install_http_observer$/m)
  const freshActivation = source.indexOf(
    'wp plugin activate "$slug" >>"$runtime_log"',
    observerInstall,
  )
  const firstRuntimeProbe = source.indexOf('probe_fixture_runtime', freshActivation)
  const updateInstall = position(
    /wp plugin install \/wporg-fixture\/candidate\.zip --force/,
  )
  const updateRuntimeProbe = source.indexOf('probe_fixture_runtime', updateInstall)

  assert.ok(fixtureCreated < earlyTrap)
  assert.ok(earlyTrap < candidateCopy)
  assert.ok(observerInstall < freshActivation)
  assert.ok(freshActivation < firstRuntimeProbe)
  assert.ok(updateInstall < updateRuntimeProbe)
  assert.match(source, /wp plugin deactivate "\$slug"/)
  assert.match(source, /wp plugin deactivate fluent-cart/)
  assert.match(source, /wp plugin activate fluent-cart/)
  assert.match(source, /Active dependency-loss tolerance passed/)
  assert.match(source, /WordPress dependency guard prevented active FluentCart loss/)
  assert.match(source, /The target plugin activated while FluentCart was inactive/)
  assert.doesNotMatch(source, /--skip-plugins=fluent-cart/)
  assert.match(source, /fchub-memberships-app/)
  assert.match(source, /fchub-memberships-admin/)
  assert.match(source, /Runtime dependency and no-HTTP probes passed/)
})
