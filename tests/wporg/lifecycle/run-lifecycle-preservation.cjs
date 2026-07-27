const assert = require('node:assert/strict')
const { execFile } = require('node:child_process')
const { access, readFile } = require('node:fs/promises')
const path = require('node:path')
const { promisify } = require('node:util')

const execFileAsync = promisify(execFile)
const repository = path.resolve(__dirname, '../../..')
const runner = path.join(repository, 'scripts/wporg/run-lifecycle.sh')

async function archiveVersion(archive, slug) {
  const { stdout } = await execFileAsync(
    'unzip',
    ['-p', archive, `${slug}/${slug}.php`],
    { maxBuffer: 2 * 1024 * 1024 },
  )
  const match = stdout.match(/^[ \t]*(?:\*[ \t]*)?Version:[ \t]*(\d+\.\d+\.\d+)/m)
  assert.ok(match, `Could not read ${slug} version from ${archive}.`)
  return match[1]
}

function lifecyclePreservationSpec({
  slug,
  candidateName,
  previousName,
  preparedMarker,
  verifiedMarker,
}) {
  const candidate =
    process.env.WPORG_LIFECYCLE_CANDIDATE ??
    path.join(repository, 'dist', candidateName)
  const previous =
    process.env.WPORG_LIFECYCLE_PREVIOUS ??
    path.join(repository, 'test-results/wporg', slug, 'previous', previousName)
  const php = process.env.WPORG_LIFECYCLE_PHP ?? '8.3'

  return async () => {
    await access(candidate)
    await access(previous)
    assert.equal(await archiveVersion(candidate, slug), '1.4.1')
    assert.equal(await archiveVersion(previous, slug), '1.4.0')

    await execFileAsync(
      'bash',
      [
        runner,
        candidate,
        slug,
        `wordpress:7.0.2-php${php}-apache`,
        `wordpress:cli-php${php}`,
        previous,
      ],
      {
        cwd: repository,
        timeout: 170_000,
        maxBuffer: 10 * 1024 * 1024,
      },
    )

    const log = await readFile(
      path.join(
        repository,
        `test-results/wporg/${slug}/lifecycle-php${php}.log`,
      ),
      'utf8',
    )
    assert.match(log, new RegExp(preparedMarker))
    assert.match(log, new RegExp(verifiedMarker))
    assert.match(
      log,
      new RegExp(`Migration preservation passed for ${slug}\\.`),
    )
  }
}

module.exports = { lifecyclePreservationSpec }
