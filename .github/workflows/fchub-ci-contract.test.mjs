// What CI owes FCHub, asserted against the workflows themselves.
//
// These assertions read the workflow as a structure — jobs, steps, conditions,
// commands — rather than as a block of text with a particular indentation. Two
// spaces become four, single quotes become double, keys get reordered, a
// comment lands in the middle of a step: none of that should turn this file
// red. Deleting a gate should, and does.
//
//   node --test .github/workflows/fchub-ci-contract.test.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const read = (name) => readFileSync(new URL(`./${name}`, import.meta.url), 'utf8')

// Full-line comments go first, so a `#` that happens to read like a key or a
// list item cannot be mistaken for one. Nothing asserted below is a comment.
const strip = (yaml) => yaml.replace(/^[ \t]*#.*$/gm, '')

const ci = strip(read('ci.yml'))
const release = strip(read('release.yml'))
const docs = strip(read('docs-ci.yml'))

const unquote = (value) => value.trim().replace(/^(['"])(.*)\1$/, '$2')

/** The body of one job, from its key to the next key at the same depth. */
function job(workflow, name) {
  const header = workflow.match(new RegExp(`^([ \\t]+)${name}:[ \\t]*$`, 'm'))
  assert.ok(header, `Expected a "${name}" job`)

  const rest = workflow.slice(header.index + header[0].length)
  const end = rest.search(new RegExp(`^[ ]{0,${header[1].length}}[A-Za-z0-9_.-]+:`, 'm'))

  return end === -1 ? rest : rest.slice(0, end)
}

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

/**
 * The entries of a block list, split on the list dash rather than on any one
 * key. Splitting on `- name:` would have worked until the day somebody put
 * `if:` first.
 */
function entries(block, anchor) {
  const start = block.match(new RegExp(`^ *${anchor}: *$`, 'm'))
  assert.ok(start, `Expected a ${anchor}: list`)

  const region = block.slice(start.index + start[0].length)
  const first = region.match(/^( *)- /m)
  assert.ok(first, `Expected at least one entry under ${anchor}:`)

  const indent = first[1].length
  // The list ends where the indentation climbs back out of it.
  const end = indent > 0 ? region.search(new RegExp(`^ {0,${indent - 1}}\\S`, 'm')) : -1
  const list = end === -1 ? region : region.slice(0, end)

  const marker = new RegExp(`^ {${indent}}- `, 'gm')
  const at = []

  for (let match = marker.exec(list); match; match = marker.exec(list)) {
    at.push(match.index)
  }

  return at.map((from, index) => ({
    at: from,
    body: list.slice(from, index + 1 < at.length ? at[index + 1] : list.length),
  }))
}

/** Every step of a job, in order, each with the name it declares. */
function steps(jobBody) {
  return entries(jobBody, 'steps').map((entry) => ({ ...entry, name: value(entry.body, 'name') }))
}

function step(jobBody, name) {
  const found = steps(jobBody).find((entry) => entry.name === name)
  assert.ok(found, `Expected a "${name}" step`)
  return found.body
}

/** The path filters of one trigger, as a list, order and quoting irrelevant. */
function paths(workflow, event) {
  const start = workflow.search(new RegExp(`^[ \\t]+${event}:[ \\t]*$`, 'm'))
  assert.notEqual(start, -1, `Expected an ${event} trigger`)

  const list = workflow.slice(start).match(/^[ \t]*paths:[ \t]*\n((?:[ \t]*-[ \t]*\S.*\n?)+)/m)
  assert.ok(list, `Expected ${event} path filters`)

  return list[1]
    .split('\n')
    .map((line) => line.trim())
    .filter(Boolean)
    .map((line) => unquote(line.replace(/^-[ \t]*/, '')))
}

/** Every entry of the PHPUnit matrix, in order. */
const matrix = (jobBody) =>
  entries(jobBody, 'include').map((entry) => ({ ...entry, plugin: value(entry.body, 'plugin') }))

function matrixEntry(jobBody, plugin) {
  const found = matrix(jobBody).find((entry) => entry.plugin === plugin)

  assert.ok(found, `Expected a "${plugin}" entry in the PHPUnit matrix`)
  return found.body
}

test('Every PHPUnit matrix entry declares the history its change detector needs', () => {
  const phpunit = job(ci, 'phpunit')

  assert.match(
    step(phpunit, 'Checkout'),
    /fetch-depth: \$\{\{ matrix\.fetch_depth \}\}/,
    'Checkout depth must come from the matrix, so a job that diffs against the base can ask for the base',
  )

  const declared = matrix(phpunit)
  assert.ok(declared.length > 0, 'Expected a PHPUnit matrix')

  for (const entry of declared) {
    assert.notEqual(
      value(entry.body, 'fetch_depth'),
      null,
      `${entry.plugin} must declare fetch_depth — an undeclared one resolves to an empty checkout depth`,
    )
  }

})

test('Every job that diffs against the pull request base can actually fail', () => {
  // The class of bug this pins: `actions/checkout` defaults to depth 1, which
  // does not contain the pull request base. The diff then errors, `wc -l`
  // returns zero, and every gate guarded by that count skips itself while the
  // job reports success — a green tick for a suite that never ran.
  //
  // fchub-stream is the one permitted exception: it is discontinued and must
  // not be built or tested, so its gate is inert on purpose.
  const inertByDesign = new Set(['vite-build-stream'])

  const names = [...ci.matchAll(/^ {2}([a-z0-9-]+):$/gm)].map((m) => m[1])
  assert.ok(names.length > 0, 'Expected to find jobs in ci.yml')

  const diffing = names.filter((name) => job(ci, name).includes('pull_request.base.sha'))
  assert.ok(diffing.length > 0, 'Expected at least one job to diff against the base')

  for (const name of diffing) {
    const checkout = step(job(ci, name), 'Checkout')
    const depth = value(checkout.replace(/^.*?with:\n/s, ''), 'fetch-depth')

    if (inertByDesign.has(name)) {
      assert.notEqual(depth, '0', `${name} is discontinued and must stay inert`)
      continue
    }

    assert.ok(
      depth === '0' || depth === '${{ matrix.fetch_depth }}',
      `${name} diffs against the pull request base, so it must fetch the history that diff needs — found fetch-depth ${depth}`,
    )
  }
})

test('The repository contract job checks the catalogue and the documentation', () => {
  const contract = job(ci, 'repository-contract')

  assert.match(contract, /node --test tests\/repository\/fchub-catalog\.test\.mjs/)
  assert.match(contract, /node scripts\/sync-fchub-catalog\.mjs --check/)
  assert.match(contract, /node scripts\/check-fchub-docs\.mjs/)
  assert.doesNotMatch(contract, /continue-on-error:\s*true/)
})

test('Docs CI watches the FCHub sources its own checks read', () => {
  for (const event of ['push', 'pull_request']) {
    const filters = paths(docs, event)

    // The plugin's own sources left this repository with it. What remains
    // here is what this repository still owns and its own checks still read.
    for (const required of [
      'scripts/check-fchub-docs.mjs',
      'scripts/sync-fchub-catalog.mjs',
    ]) {
      assert.ok(filters.includes(required), `Docs CI must react to ${required} on ${event}`)
    }
  }

  const consistency = job(docs, 'consistency')
  assert.match(consistency, /node scripts\/check-fchub-docs\.mjs/)
  assert.match(consistency, /node scripts\/sync-fchub-catalog\.mjs --check/)
})

/**
 * Carried over when FCHub's source moved to its own repository. The guarantee
 * is not FCHub's: every product's release publishes a sidecar, because FCHub
 * verifies what it downloads and a release without one silently downgrades
 * every install that follows into checksum_unavailable.
 */
test('Every release publishes a SHA-256 sidecar beside its ZIP', () => {
  const bypass = /if:\s*['"]?\s*(?:\$\{\{\s*)?always\s*\(\s*\)/
  const releaseSteps = steps(job(release, 'release'))

  const named = (name) => {
    const found = releaseSteps.find((entry) => entry.name === name)
    assert.ok(found, `Expected a "${name}" step in the release job`)
    return found
  }

  const zip = named('Build ZIP')
  const sidecar = named('Create checksum sidecar')
  const publish = named('Create GitHub Release')

  assert.match(sidecar.body, /sha256sum/, 'The sidecar is produced by sha256sum on the Linux runner')
  assert.match(
    sidecar.body,
    /checksum_path=[^\n]*\.sha256[^\n]*>>[^\n]*GITHUB_ENV/,
    'The sidecar path must be exported for the release step',
  )
  assert.doesNotMatch(
    sidecar.body,
    /if: steps\.tag\.outputs\.slug/,
    'Sidecars are for every product, not one of them',
  )
  assert.doesNotMatch(sidecar.body, /continue-on-error:\s*true/)
  assert.doesNotMatch(sidecar.body, bypass)

  assert.ok(zip.at < sidecar.at, 'The sidecar describes the ZIP, so it comes after it')
  assert.ok(
    sidecar.at < publish.at,
    'The sidecar must exist before the release that publishes it — checksum_path is empty otherwise',
  )

  assert.match(publish.body, /gh release create/)
  assert.match(publish.body, /\$\{?zip_path\}?/, 'The ZIP must be published')
  assert.match(publish.body, /\$\{?checksum_path\}?/, 'The sidecar must be published alongside it')
  assert.doesNotMatch(publish.body, bypass)
})
