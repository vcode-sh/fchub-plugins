import assert from 'node:assert/strict'
import { readdir, readFile } from 'node:fs/promises'
import path from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

const root = new URL('../../', import.meta.url)
const pluginsDirectory = fileURLToPath(new URL('plugins/', root))

const readJson = async (file) => JSON.parse(await readFile(file, 'utf8'))

/** Read an optional repository file while preserving real read failures. */
async function readOptional(file) {
  try {
    return await readFile(file, 'utf8')
  } catch (error) {
    if (error.code === 'ENOENT') {
      return null
    }

    throw error
  }
}

test('plugin release metadata agrees on each published version', async () => {
  const directories = await readdir(pluginsDirectory, { withFileTypes: true })
  const documentedVersions = (await readJson(new URL('web-docs/lib/versions.json', root))).plugins

  for (const directory of directories.filter((entry) => entry.isDirectory())) {
    const slug = directory.name
    const pluginDirectory = path.join(pluginsDirectory, slug)
    const mainFile = await readOptional(path.join(pluginDirectory, `${slug}.php`))

    if (mainFile === null) {
      continue
    }

    const headerVersion = mainFile.match(/^\s*\*\s*Version:\s*(\S+)/m)?.[1]
    assert.ok(headerVersion, `${slug} must declare a plugin header version`)

    const versionConstants = [...mainFile.matchAll(
      /define\(\s*['"]([A-Z0-9_]+_VERSION)['"]\s*,\s*['"]([^'"]+)['"]\s*\)/g,
    )].filter(([_, name]) => !name.endsWith('_DB_VERSION'))

    assert.ok(versionConstants.length > 0, `${slug} must declare its runtime version`)
    for (const [_, name, version] of versionConstants) {
      assert.equal(version, headerVersion, `${slug} ${name} must match its plugin header`)
    }

    const readme = await readOptional(path.join(pluginDirectory, 'readme.txt'))
    const stableTag = readme?.match(/^Stable tag:\s*(\S+)/mi)?.[1]

    if (stableTag && stableTag !== 'trunk') {
      assert.equal(stableTag, headerVersion, `${slug} readme.txt has a stale Stable tag`)
    }

    const documented = documentedVersions[slug]
    if (documented) {
      assert.equal(documented.version, headerVersion, `${slug} has a stale documented version`)
      assert.equal(documented.tagName, `${slug}/v${headerVersion}`)
      assert.equal(documented.zipFilename, `${slug}-${headerVersion}.zip`)
    }
  }
})
