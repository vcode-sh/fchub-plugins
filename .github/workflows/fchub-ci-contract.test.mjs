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

test('CI runs the FCHub workflow contracts on every input that can break them', () => {
  const filters = paths(ci, 'pull_request')

  for (const required of [
    'plugins/**',
    '.github/workflows/ci.yml',
    '.github/workflows/release.yml',
    '.github/workflows/docs-ci.yml',
    '.github/workflows/fchub-ci-contract.test.mjs',
    '.github/workflows/fchub-release-contract.test.mjs',
    'scripts/sync-fchub-catalog.mjs',
    'scripts/check-fchub-docs.mjs',
    'tests/repository/fchub-catalog.test.mjs',
    'web-docs/lib/fchub-products.json',
    'web-docs/lib/versions.json',
    'build.sh',
  ]) {
    assert.ok(filters.includes(required), `CI must react to changes in ${required}`)
  }

  const contract = job(ci, 'workflow-contract')
  assert.match(
    contract,
    /node --test [^\n]*fchub-ci-contract\.test\.mjs[^\n]*fchub-release-contract\.test\.mjs/,
    'The workflow-contract job must run both FCHub contracts',
  )
})

test('FCHub joins the shared PHP matrix on the floor it declares', () => {
  const phpunit = job(ci, 'phpunit')
  const fchub = matrixEntry(phpunit, 'fchub')

  assert.equal(value(fchub, 'php_version'), '8.1', 'FCHub is tested on its composer.lock platform floor')
  assert.equal(value(fchub, 'has_tests'), 'true')
  assert.match(step(phpunit, 'Setup PHP'), /php-version: \$\{\{ matrix\.php_version \}\}/)
})

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

  assert.equal(
    value(matrixEntry(phpunit, 'fchub'), 'fetch_depth'),
    '0',
    'FCHub diffs against the pull request base, which a depth-1 checkout does not contain',
  )
})

test('FCHub JavaScript gates all run, in order, inside the plugin', () => {
  const node = job(ci, 'vite-build-fchub')

  assert.match(
    node,
    /runs-on: ubuntu-latest/,
    'The smoke suite compares committed Linux screenshots, so it must run on Linux',
  )
  assert.match(node, /cache-dependency-path: plugins\/fchub\/package-lock\.json/)
  assert.doesNotMatch(node, /vendor\/bin\/phpunit/, 'The JavaScript job is not where PHP is tested')

  const gates = [
    ['Install dependencies', /npm ci/],
    ['Audit JavaScript dependencies', /npm audit --audit-level=high/],
    ['Run JavaScript tests', /npm test/],
    ['Install Chromium', /npx playwright install --with-deps chromium/],
    ['Run browser smoke tests', /npm run test:smoke/],
    ['Build production assets', /npm run build/],
    ['Validate build output', /assets\/dist\/\.vite\/manifest\.json/],
  ]

  for (const [name, command] of gates) {
    const body = step(node, name)
    assert.match(body, command, `${name} must run its gate`)
    assert.match(body, /if: steps\.changes\.outputs\.count > 0/, `${name} must keep the changed-path guard`)
    assert.equal(value(body, 'working-directory'), 'plugins/fchub', `${name} must run inside FCHub`)
    assert.doesNotMatch(body, /continue-on-error:\s*true/, `${name} must be able to fail the job`)
  }

  const order = steps(node).map((entry) => entry.name)
  const positions = gates.map(([name]) => order.indexOf(name))

  for (let index = 1; index < positions.length; index += 1) {
    assert.ok(
      positions[index - 1] < positions[index],
      `${gates[index][0]} must follow ${gates[index - 1][0]}`,
    )
  }

  assert.match(step(node, 'Cache Playwright browsers'), /actions\/cache@v4/)
})

test('The repository contract job checks the catalogue and the documentation', () => {
  const contract = job(ci, 'repository-contract')

  assert.match(contract, /node --test tests\/repository\/fchub-catalog\.test\.mjs/)
  assert.match(contract, /node scripts\/sync-fchub-catalog\.mjs --check/)
  assert.match(contract, /node scripts\/check-fchub-docs\.mjs/)
  assert.doesNotMatch(contract, /continue-on-error:\s*true/)
})

test('The lifecycle job runs on FCHub, catalogue, build and workflow changes only', () => {
  const lifecycle = job(ci, 'fchub-lifecycle')

  const detector = step(lifecycle, 'Check for changes')

  for (const watched of [
    'plugins/fchub/',
    'scripts/sync-fchub-catalog.mjs',
    'web-docs/lib/fchub-products.json',
    'web-docs/lib/versions.json',
    'build.sh',
    '.github/workflows/ci.yml',
    // The harness is the only thing that proves a built archive installs, and
    // release.yml owns the other implementation of the ZIP build.
    '.github/workflows/release.yml',
  ]) {
    assert.ok(detector.includes(watched), `The lifecycle gate must watch ${watched}`)
  }

  const harness = step(lifecycle, 'Run the disposable WordPress lifecycle')
  assert.match(harness, /bash tests\/e2e\/run-lifecycle\.sh/)
  assert.equal(value(harness, 'working-directory'), 'plugins/fchub')
  assert.match(harness, /if: steps\.changes\.outputs\.count > 0/)
  assert.doesNotMatch(harness, /continue-on-error:\s*true/)

  assert.match(
    value(lifecycle, 'timeout-minutes') ?? '',
    /^\d+$/,
    'A job that stands up Docker needs a ceiling',
  )
})

test('No FCHub job can be neutered at job level', () => {
  // Every other assertion in this file is scoped to a step, and a step-scoped
  // assertion cannot see the cheaper edit: one line on the job disables every
  // gate inside it at once, and it is the line a hurried human reaches for when
  // a queue is blocked.
  for (const name of ['vite-build-fchub', 'repository-contract', 'fchub-lifecycle']) {
    const body = job(ci, name)

    assert.equal(
      value(body, 'continue-on-error'),
      null,
      `${name} must be able to fail the workflow — a job-level continue-on-error makes every gate in it advisory`,
    )
    assert.equal(
      value(body, 'if'),
      null,
      `${name} must not be gated off at job level — path gating belongs in Check for changes, where it is visible`,
    )
  }
})

test('FCHub change detection fails loudly rather than reporting no changes', () => {
  for (const name of ['vite-build-fchub', 'fchub-lifecycle']) {
    const body = job(ci, name)

    assert.match(
      step(body, 'Checkout'),
      /fetch-depth: 0/,
      `${name} diffs against the pull request base, which a shallow checkout does not contain`,
    )
    assert.match(
      step(body, 'Check for changes'),
      /set -o pipefail/,
      `${name} must fail on a broken diff instead of counting zero changes and skipping every gate`,
    )
  }
})

test('Docs CI watches the FCHub sources its own checks read', () => {
  for (const event of ['push', 'pull_request']) {
    const filters = paths(docs, event)

    for (const required of [
      'plugins/fchub/resources/admin/components/ProductCard.vue',
      'plugins/fchub/resources/catalog.json',
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
