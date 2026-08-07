import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const workflow = readFileSync(new URL('./ci.yml', import.meta.url), 'utf8')
const smokeFixture = readFileSync(
  new URL('../../plugins/fchub-memberships/smoke/main.js', import.meta.url),
  'utf8',
)

function job(name) {
  const match = workflow.match(
    new RegExp(`^  ${name}:\\n([\\s\\S]*?)(?=^  [A-Za-z0-9_-]+:\\n|(?![\\s\\S]))`, 'm'),
  )

  assert.ok(match, `Expected ${name} job in ci.yml`)
  return match[1]
}

function step(jobBody, name) {
  const escapedName = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
  const match = jobBody.match(
    new RegExp(`^      - name: ${escapedName}\\n([\\s\\S]*?)(?=^      - name: |(?![\\s\\S]))`, 'm'),
  )

  assert.ok(match, `Expected ${name} step`)
  return match[0]
}

test('CI runs both workflow contracts for every owned workflow input', () => {
  assert.match(
    workflow,
    /pull_request:\n\s+paths:\n[\s\S]*?- 'plugins\/\*\*'[\s\S]*?- 'web-docs\/lib\/versions\.json'[\s\S]*?- '\.github\/workflows\/ci\.yml'[\s\S]*?- '\.github\/workflows\/ci-memberships-contract\.test\.mjs'[\s\S]*?- '\.github\/workflows\/release\.yml'[\s\S]*?- '\.github\/workflows\/release-memberships-contract\.test\.mjs'/,
  )

  const workflowContract = job('workflow-contract')
  assert.match(
    workflowContract,
    /node --test \.github\/workflows\/ci-memberships-contract\.test\.mjs \.github\/workflows\/ci-wporg-contract\.test\.mjs \.github\/workflows\/release-memberships-contract\.test\.mjs/,
  )
})

for (const name of ['Install dependencies', 'Audit Composer dependencies', 'Run PHPUnit']) {
  test(`${name} retains its matrix-plugin guard and working directory`, () => {
    const phpunit = job('phpunit')
    const phpStep = step(phpunit, name)
    assert.match(
      phpStep,
      /if: steps\.changes\.outputs\.count > 0/,
      `${name} must retain the matrix-plugin changed-path guard`,
    )
    assert.match(
      phpStep,
      /working-directory: plugins\/\$\{\{ matrix\.plugin \}\}/,
      `${name} must execute inside the matrix plugin`,
    )
  })
}

test('Memberships remains in the shared PHPUnit 13 PHP 8.4 and 8.5 gate sequence', () => {
  const phpunit = job('phpunit')

  for (const version of ['8.4', '8.5']) {
    assert.match(
      phpunit,
      new RegExp(`- plugin: fchub-memberships\\n\\s+php_version: '${version.replace('.', '\\.')}'`),
    )
  }
  assert.doesNotMatch(
    phpunit,
    /- plugin: fchub-memberships\n\s+php_version: '8\.3'/,
    'PHPUnit 13 requires PHP 8.4.1 or newer',
  )
  assert.match(
    step(phpunit, 'Setup PHP'),
    /php-version: \$\{\{ matrix\.php_version \}\}/,
    'Each plugin must use its declared PHP test runtime',
  )
  assert.match(phpunit, /name: Audit Composer dependencies[\s\S]*?run: composer audit --locked --no-interaction/)

  assert.ok(
    phpunit.indexOf('composer install --no-interaction --prefer-dist') <
      phpunit.indexOf('composer audit --locked --no-interaction'),
    'Composer audit must run after dependency installation',
  )
  assert.ok(
    phpunit.indexOf('composer audit --locked --no-interaction') < phpunit.indexOf('./vendor/bin/phpunit'),
    'PHPUnit must remain in the shared PHP job after the Composer audit',
  )
})

test('Memberships change detection inspects the Memberships plugin path', () => {
  const membershipsVite = job('vite-build-memberships')
  assert.match(
    step(membershipsVite, 'Check for changes'),
    /git diff --name-only [^\n]+ -- plugins\/fchub-memberships\/ \| wc -l/,
    'Memberships change detection must inspect the Memberships plugin path',
  )
})

test('Memberships PHP change detection includes the shared version registry', () => {
  const phpunit = job('phpunit')
  const changeDetector = step(phpunit, 'Check for changes')

  assert.match(
    changeDetector,
    /if \[ "\$\{\{ matrix\.plugin \}\}" = "fchub-memberships" \]; then/,
    'The shared version registry must only extend the Memberships detector',
  )
  assert.match(
    changeDetector,
    /-- plugins\/\$\{\{ matrix\.plugin \}\}\/ web-docs\/lib\/versions\.json \| wc -l/,
    'A versions.json-only change must set the Memberships matrix count',
  )
})

test('Memberships pull requests run every required JavaScript, smoke, and build gate', () => {
  const membershipsVite = job('vite-build-memberships')

  assert.match(membershipsVite, /run: npm ci/)
  assert.match(membershipsVite, /name: Audit JavaScript dependencies[\s\S]*?run: npm audit --audit-level=high/)
  assert.match(membershipsVite, /name: Run JavaScript tests[\s\S]*?run: npm test/)
  assert.match(membershipsVite, /actions\/cache@v6/)
  assert.match(membershipsVite, /npx playwright install --with-deps chromium/)
  assert.match(membershipsVite, /npx playwright test/)
  assert.doesNotMatch(membershipsVite, /vendor\/bin\/phpunit/)

  const guardedMembershipsSteps = [
    'Install dependencies',
    'Audit JavaScript dependencies',
    'Run JavaScript tests',
    'Cache Playwright browsers',
    'Install Chromium',
    'Run browser smoke tests',
    'Build production assets',
    'Validate build output',
  ]

  for (const name of guardedMembershipsSteps) {
    assert.match(
      step(membershipsVite, name),
      /if: steps\.changes\.outputs\.count > 0/,
      `${name} must retain the Memberships changed-path guard`,
    )
  }

  const membershipsWorkingDirectorySteps = guardedMembershipsSteps.filter(
    (name) => name !== 'Cache Playwright browsers',
  )

  for (const name of membershipsWorkingDirectorySteps) {
    assert.match(
      step(membershipsVite, name),
      /working-directory: plugins\/fchub-memberships/,
      `${name} must execute inside the Memberships plugin`,
    )
  }

  const orderedCommands = [
    'npm ci',
    'npm audit --audit-level=high',
    'npm test',
    'npx playwright install --with-deps chromium',
    'npx playwright test',
    'npm run build',
  ]

  let previousIndex = -1
  for (const command of orderedCommands) {
    const commandIndex = membershipsVite.indexOf(command)
    assert.ok(commandIndex > previousIndex, `${command} must follow the preceding Memberships gate`)
    previousIndex = commandIndex
  }

  assert.match(membershipsVite, /name: Validate build output/)
})

test('Memberships smoke resource types match the live registry contract', () => {
  const expectedResourceRows = [
    "{ key: 'menu_item', label: 'Menu Items', group: 'navigation', icon: 'menu', searchable: false, supports_bulk: false, allow_all: true, provider: 'wordpress_core', adapter: 'FChubMemberships\\\\Adapters\\\\WordPressContentAdapter', source: 'WordPress' }",
    "{ key: 'comment', label: 'Comments', group: 'advanced', icon: 'admin-comments', searchable: false, supports_bulk: false, allow_all: true, provider: 'wordpress_core', adapter: 'FChubMemberships\\\\Adapters\\\\WordPressContentAdapter', source: 'WordPress' }",
    "{ key: 'url_pattern', label: 'URL Patterns', group: 'advanced', icon: 'admin-links', searchable: false, supports_bulk: false, allow_all: true, provider: 'wordpress_core', adapter: 'FChubMemberships\\\\Adapters\\\\WordPressContentAdapter', source: '' }",
    "{ key: 'special_page', label: 'Special Pages', group: 'advanced', icon: 'admin-home', searchable: false, supports_bulk: false, allow_all: true, provider: 'wordpress_core', adapter: 'FChubMemberships\\\\Adapters\\\\WordPressContentAdapter', source: '' }",
    "{ key: 'more_tag', label: 'More Tag Content', group: 'advanced', icon: 'editor-insertmore', searchable: true, supports_bulk: true, allow_all: true, provider: 'wordpress_core', adapter: 'FChubMemberships\\\\Adapters\\\\WordPressContentAdapter', source: '' }",
  ]

  for (const row of expectedResourceRows) {
    assert.ok(smokeFixture.includes(row), `Missing exact live resource fixture row: ${row}`)
  }

  assert.match(smokeFixture, /\{ value: 'more_tag', label: 'More Tag Content', group: 'advanced', source: '' \}/)
})
