// What a release owes FCHub, asserted against the release workflow itself.
//
// Two promises live here. The first is that an `fchub/v*` tag cannot become a
// release without every gate FCHub's own CI runs having passed first. The
// second is smaller and easier to lose: every plugin release publishes a
// SHA-256 sidecar beside its ZIP, because FCHub's downloader treats a missing
// one as `checksum_unavailable` and installs anyway. That concession exists for
// releases published before sidecars did. It is not a shipping lane.
//
//   node --test .github/workflows/fchub-release-contract.test.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')

// Full-line comments go first, so a `#` that happens to read like a key or a
// list item cannot be mistaken for one. Nothing asserted below is a comment.
const release = read('./release.yml').replace(/^[ \t]*#.*$/gm, '')
const catalogue = JSON.parse(read('../../plugins/fchub/resources/catalog.json'))

const unquote = (value) => value.trim().replace(/^(['"])(.*)\1$/, '$2')

/**
 * A scalar by key, read at the depth of the block's own first key, so a `name:`
 * nested inside `with:` cannot be mistaken for the step's own.
 *
 * The first key rather than the shallowest one: a block's first line is always
 * its own first key, whereas the shallowest line need not belong to the block at
 * all. Reorder a job's keys so `timeout-minutes:` follows `steps:` and the
 * shallowest line inside the last step is a key belonging to the *job* — which
 * is how a reformat used to make a step's name unreadable.
 */
function value(block, key) {
  // A leading list dash is two columns of indentation wearing a hat.
  const normalised = block.replace(/^( *)-( )/, '$1 $2')
  const first = normalised.match(/^( *)[A-Za-z0-9_.-]+:/m)

  if (!first) {
    return null
  }

  const match = normalised.match(new RegExp(`^ {${first[1].length}}${key}: *(.*?) *$`, 'm'))

  return match ? unquote(match[1]) : null
}

/** The body of one job, from its key to the next key at the same depth. */
function job(workflow, name) {
  const header = workflow.match(new RegExp(`^( +)${name}: *$`, 'm'))
  assert.ok(header, `Expected a "${name}" job`)

  const rest = workflow.slice(header.index + header[0].length)
  const end = rest.search(new RegExp(`^ {0,${header[1].length}}[A-Za-z0-9_.-]+:`, 'm'))

  return end === -1 ? rest : rest.slice(0, end)
}

// Bounded to the release job rather than run to end of file. There is one job
// today; a second one added later would otherwise have its steps silently
// absorbed into this job's, and every ordering assertion below would be
// comparing positions across two jobs.
const releaseJob = job(release, 'release')

/**
 * Every step of the release job, in order, split on the list dash rather than
 * on any one key. Splitting on `- name:` would have worked until the day
 * somebody put `if:` first.
 */
const allSteps = (() => {
  const anchor = releaseJob.match(/^ *steps: *$/m)
  assert.ok(anchor, 'Expected a steps: list')

  const region = releaseJob.slice(anchor.index + anchor[0].length)
  const first = region.match(/^( *)- /m)
  assert.ok(first, 'Expected at least one release step')

  const indent = first[1].length
  // The list ends where the indentation climbs back out of it, so a job key
  // written after `steps:` is not read as part of the final step.
  const end = indent > 0 ? region.search(new RegExp(`^ {0,${indent - 1}}\\S`, 'm')) : -1
  const list = end === -1 ? region : region.slice(0, end)

  const marker = new RegExp(`^ {${indent}}- `, 'gm')
  const at = []

  for (let match = marker.exec(list); match; match = marker.exec(list)) {
    at.push(match.index)
  }

  return at.map((from, index) => {
    const body = list.slice(from, index + 1 < at.length ? at[index + 1] : list.length)
    return { at: from, body, name: value(body, 'name') }
  })
})()

function step(name) {
  const found = allSteps.find((entry) => entry.name === name)
  assert.ok(found, `Expected a "${name}" release step`)
  return found
}

/** The tag patterns the workflow triggers on, order and quoting irrelevant. */
function tags() {
  const list = release.match(/^[ \t]*tags:[ \t]*\n((?:[ \t]*-[ \t]*\S.*\n?)+)/m)
  assert.ok(list, 'Expected tag filters on the release trigger')

  return list[1]
    .split('\n')
    .map((line) => line.trim())
    .filter(Boolean)
    .map((line) => unquote(line.replace(/^-[ \t]*/, '')))
}

/** Anything that would let a red gate ship a green release. */
const bypass = /if:\s*['"]?\s*(?:\$\{\{\s*)?always\s*\(\s*\)/

test('An fchub tag triggers a release, and the other products keep theirs', () => {
  const patterns = tags()

  assert.ok(patterns.includes('fchub/v*'), 'The hub must have a release trigger of its own')

  for (const product of [
    'fchub-p24/v*',
    'fchub-fakturownia/v*',
    'fchub-memberships/v*',
    'fchub-portal-extender/v*',
    'fchub-wishlist/v*',
    'fchub-multi-currency/v*',
    'cartshift/v*',
  ]) {
    assert.ok(patterns.includes(product), `${product} must keep its release trigger`)
  }
})

test('Every FCHub gate runs, for FCHub only, and can fail the release', () => {
  const gates = [
    ['Setup PHP (fchub)', /php-version: '?8\.1'?/],
    ['Install Composer dependencies (fchub)', /composer install/],
    ['Audit Composer dependencies (fchub)', /composer audit --locked --no-interaction/],
    ['Run PHPUnit (fchub)', /\.\/vendor\/bin\/phpunit/],
    ['Install npm dependencies (fchub)', /npm ci/],
    ['Audit npm dependencies (fchub)', /npm audit --audit-level=high/],
    ['Run Vitest (fchub)', /npm run test(?:\s|$)/],
    ['Run Playwright (fchub)', /npm run test:smoke/],
    ['Build assets (fchub)', /npm run build/],
    ['Check catalogue drift (fchub)', /node scripts\/sync-fchub-catalog\.mjs --check/],
    ['Verify documentation (fchub)', /node scripts\/check-fchub-docs\.mjs/],
    ['Run lifecycle test (fchub)', /bash tests\/e2e\/run-lifecycle\.sh/],
  ]

  for (const [name, command] of gates) {
    const { body } = step(name)

    assert.match(body, command, `${name} must run its gate`)
    assert.match(
      body,
      /if: steps\.tag\.outputs\.slug == '?fchub'?/,
      `${name} must run for FCHub releases and no other product`,
    )
    assert.doesNotMatch(body, /continue-on-error:\s*true/, `${name} must be able to fail the release`)
    assert.doesNotMatch(body, bypass, `${name} must not run past an earlier failure`)
  }

  const order = [...gates.map(([name]) => name), 'Build ZIP', 'Create GitHub Release']

  for (let index = 1; index < order.length; index += 1) {
    assert.ok(
      step(order[index - 1]).at < step(order[index]).at,
      `${order[index - 1]} must precede ${order[index]}`,
    )
  }

  assert.match(
    step('Setup Node.js (fchub)').body,
    /node-version: '?2[0-9]'?/,
    'FCHub builds with Vite 8, whose floor is Node 20.19',
  )
  assert.equal(value(step('Run PHPUnit (fchub)').body, 'working-directory'), 'plugins/fchub')
  assert.equal(value(step('Run lifecycle test (fchub)').body, 'working-directory'), 'plugins/fchub')
})

test('The hub is never given the shared updater it does not use', () => {
  assert.match(
    step('Sync GitHubUpdater').body,
    /if: steps\.tag\.outputs\.slug != '?fchub'?/,
    'FCHub owns a namespaced updater; the shared one would be a second, conflicting copy',
  )
})

test('Every release publishes a SHA-256 sidecar beside its ZIP', () => {
  const sidecar = step('Create checksum sidecar')

  assert.match(sidecar.body, /sha256sum/, 'The sidecar is produced by sha256sum on the Linux runner')
  assert.match(
    sidecar.body,
    /checksum_path=[^\n]*\.sha256[^\n]*>>[^\n]*GITHUB_ENV/,
    'The sidecar path must be exported for the release step',
  )
  assert.doesNotMatch(
    sidecar.body,
    /if: steps\.tag\.outputs\.slug/,
    'Sidecars are for every product, not only the hub — FCHub verifies what it downloads',
  )
  assert.doesNotMatch(sidecar.body, /continue-on-error:\s*true/)
  assert.doesNotMatch(sidecar.body, bypass)

  assert.ok(step('Build ZIP').at < sidecar.at, 'The sidecar describes the ZIP, so it comes after it')

  const publish = step('Create GitHub Release')
  assert.ok(
    sidecar.at < publish.at,
    'The sidecar must exist before the release that publishes it — checksum_path is empty otherwise',
  )
  assert.match(publish.body, /gh release create/)
  assert.match(publish.body, /\$\{?zip_path\}?/, 'The ZIP must be published')
  assert.match(publish.body, /\$\{?checksum_path\}?/, 'The sidecar must be published alongside it')
  assert.doesNotMatch(publish.body, bypass)
})

test('The release job cannot be neutered at job level, and cannot run forever', () => {
  // Step-level guards say nothing about the job that holds them: one line here
  // makes every gate above advisory, or stops the job running at all.
  assert.equal(
    value(releaseJob, 'continue-on-error'),
    null,
    'A job-level continue-on-error would publish a release over every failed gate',
  )
  assert.equal(value(releaseJob, 'if'), null, 'The release job must not be gated off at job level')

  assert.match(
    value(releaseJob, 'timeout-minutes') ?? '',
    /^\d+$/,
    'An FCHub tag runs Docker and a Playwright install here; six hours is not a ceiling',
  )
})

test('A red FCHub release leaves something to look at', () => {
  // The tag is already pushed by the time this job fails, and with
  // maxDiffPixels: 0 the likeliest failure is sixteen screenshots.
  const upload = step('Upload FCHub failure artefacts')

  assert.match(upload.body, /actions\/upload-artifact@v4/)
  assert.match(upload.body, /if: failure\(\)/, 'It must run precisely when there is something to keep')
  assert.match(upload.body, /plugins\/fchub\/test-results/)
})

test('The sidecar is named where the catalogue says it will look', () => {
  const products = Object.entries(catalogue.products)
  assert.ok(products.length > 0, 'Expected a catalogue to check against')

  for (const [slug, product] of products) {
    assert.equal(
      product.checksum_url,
      `${product.package_url}.sha256`,
      `${slug} expects its sidecar beside the package, named <package>.sha256`,
    )
  }
})
