import { readFileSync } from 'node:fs'

const phpPlugins = [
  'cartshift',
  'fchub-fakturownia',
  'fchub-memberships',
  'fchub-multi-currency',
  'fchub-p24',
  'fchub-portal-extender',
  'fchub-thank-you',
  'fchub-wishlist',
]

const wporgPlugins = [
  'fchub-fakturownia',
  'fchub-memberships',
  'fchub-multi-currency',
  'fchub-p24',
  'fchub-wishlist',
]

const selectedPhp = new Set()
const selectedWporg = new Set()
let cartshift = false
let memberships = false
let portalExtender = false

function selectPlugin(slug) {
  if (phpPlugins.includes(slug)) {
    selectedPhp.add(slug)
  }

  if (wporgPlugins.includes(slug)) {
    selectedWporg.add(slug)
  }

  cartshift ||= slug === 'cartshift'
  memberships ||= slug === 'fchub-memberships'
  portalExtender ||= slug === 'fchub-portal-extender'
}

function selectAll() {
  phpPlugins.forEach((slug) => selectedPhp.add(slug))
  wporgPlugins.forEach((slug) => selectedWporg.add(slug))
  cartshift = true
  memberships = true
  portalExtender = true
}

if (process.argv.includes('--all')) {
  selectAll()
} else {
  const files = readFileSync(0, 'utf8').split(/\r?\n/).filter(Boolean)

  for (const file of files) {
    if (file.startsWith('.github/workflows/') || file === 'web-docs/lib/versions.json') {
      selectAll()
      continue
    }

    if (file === 'build.sh' || file === 'lib/GitHubUpdater.php') {
      wporgPlugins.forEach((slug) => selectedWporg.add(slug))
      cartshift = true
      portalExtender = true
      continue
    }

    if (file.startsWith('wporg/') || file.startsWith('scripts/wporg/')) {
      wporgPlugins.forEach((slug) => selectedWporg.add(slug))
      continue
    }

    if (file === 'scripts/ci-scope.mjs' || file === 'scripts/ci-scope.test.mjs') {
      selectAll()
      continue
    }

    const plugin = file.match(/^plugins\/([^/]+)\//)?.[1]
    if (plugin) {
      selectPlugin(plugin)
    }
  }
}

console.log(`php_plugins=${JSON.stringify(phpPlugins.filter((slug) => selectedPhp.has(slug)))}`)
console.log(`wporg_plugins=${JSON.stringify(wporgPlugins.filter((slug) => selectedWporg.has(slug)))}`)
console.log(`cartshift=${cartshift}`)
console.log(`memberships=${memberships}`)
console.log(`portal_extender=${portalExtender}`)
