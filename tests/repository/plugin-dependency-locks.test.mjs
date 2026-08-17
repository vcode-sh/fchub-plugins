import assert from 'node:assert/strict'
import { readdir, readFile } from 'node:fs/promises'
import path from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

const root = new URL('../../', import.meta.url)
const pluginsDirectory = fileURLToPath(new URL('plugins/', root))
const dependencySections = [
  'dependencies',
  'devDependencies',
  'optionalDependencies',
  'peerDependencies',
]

const readJson = async (file) => JSON.parse(await readFile(file, 'utf8'))

/** Find dependency locks without walking installed dependency trees. */
async function findPackageLocks(directory) {
  const entries = await readdir(directory, { withFileTypes: true })
  const locks = []

  for (const entry of entries) {
    if (entry.name === 'node_modules' || entry.name === 'vendor') {
      continue
    }

    const entryPath = path.join(directory, entry.name)

    if (entry.isDirectory()) {
      locks.push(...await findPackageLocks(entryPath))
    } else if (entry.name === 'package-lock.json') {
      locks.push(entryPath)
    }
  }

  return locks
}

/** Compare ordinary three-part releases used by the locked security fixes. */
function compareReleases(left, right) {
  const parts = (version) => {
    const match = version.match(/^v?(\d+)\.(\d+)\.(\d+)/)
    assert.ok(match, `Expected a release version, received ${version}`)
    return match.slice(1).map(Number)
  }

  const leftParts = parts(left)
  const rightParts = parts(right)

  for (let index = 0; index < leftParts.length; index += 1) {
    if (leftParts[index] !== rightParts[index]) {
      return leftParts[index] - rightParts[index]
    }
  }

  return 0
}

test('all plugin npm lockfiles match their manifests', async () => {
  const locks = await findPackageLocks(pluginsDirectory)
  assert.ok(locks.length > 0, 'Expected plugin npm lockfiles')

  for (const lockPath of locks.sort()) {
    const manifestPath = path.join(path.dirname(lockPath), 'package.json')
    const manifest = await readJson(manifestPath)
    const lock = await readJson(lockPath)
    const lockedRoot = lock.packages?.['']
    const relativePath = path.relative(pluginsDirectory, lockPath)

    assert.ok(lockedRoot, `${relativePath} must declare its root package`)

    for (const section of dependencySections) {
      assert.deepEqual(
        lockedRoot[section] ?? {},
        manifest[section] ?? {},
        `${relativePath} has a stale ${section} snapshot`,
      )
    }
  }
})

test('Stream dependency locks exclude the disclosed vulnerable releases', async () => {
  const npmLocks = {
    'plugins/fchub-stream/admin-app/package-lock.json': {
      flatted: '3.4.2',
      nanoid: '3.3.18',
    },
    'plugins/fchub-stream/portal-app/package-lock.json': {
      nanoid: '3.3.18',
    },
  }

  for (const [lockPath, floors] of Object.entries(npmLocks)) {
    const lock = await readJson(new URL(lockPath, root))

    for (const [name, floor] of Object.entries(floors)) {
      const version = lock.packages?.[`node_modules/${name}`]?.version
      assert.ok(version, `${lockPath} must resolve ${name}`)
      assert.ok(
        compareReleases(version, floor) >= 0,
        `${lockPath} resolves vulnerable ${name} ${version}`,
      )
    }
  }
})
