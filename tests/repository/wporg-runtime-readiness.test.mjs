import assert from 'node:assert/strict'
import { execFile } from 'node:child_process'
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { promisify } from 'node:util'
import test from 'node:test'

const execFileAsync = promisify(execFile)
const helper = new URL('../../scripts/wporg/runtime-readiness.sh', import.meta.url)
const source = await readFile(helper, 'utf8').catch(() => '')

function shellQuote(value) {
  return `'${value.replaceAll("'", "'\\''")}'`
}

async function runScenario(body, timeout = 5_000) {
  const temporary = await mkdtemp(join(tmpdir(), 'wporg-readiness-test-'))
  const logPath = join(temporary, 'runtime.log')
  const statePath = join(temporary, 'attempts.log')
  await Promise.all([writeFile(logPath, ''), writeFile(statePath, '')])

  const startedAt = Date.now()
  let result
  let error
  try {
    result = await execFileAsync(
      'bash',
      [
        '-c',
        `set -Eeuo pipefail
source ${shellQuote(helper.pathname)}
log_path=${shellQuote(logPath)}
state_file=${shellQuote(statePath)}
${body}`,
      ],
      { timeout },
    )
  } catch (caught) {
    error = caught
  }

  const scenario = {
    elapsed: Date.now() - startedAt,
    error,
    result,
    log: await readFile(logPath, 'utf8'),
  }
  await rm(temporary, { recursive: true, force: true })
  return scenario
}

test('readiness waits for completed shared WordPress files', () => {
  assert.notEqual(source, '')
  assert.match(source, /wp-load\.php/)
  assert.match(source, /wp-admin\/includes\/upgrade\.php/)
  assert.match(source, /wp-includes\/version\.php/)
  assert.match(source, /run_with_deadline/)
})

test('readiness retries a delayed success instead of racing WP-CLI', async () => {
  const scenario = await runScenario(`
dc() {
  case "$1" in
    exec)
      attempts="$(wc -l <"$state_file" | tr -d ' ')"
      printf 'attempt\\n' >>"$state_file"
      [ "$attempts" -ge 2 ]
      ;;
    *) return 0 ;;
  esac
}
wait_for_wordpress_filesystem "$log_path" 3 0 1
`)

  assert.equal(scenario.error, undefined)
  assert.match(scenario.log, /became ready after 3 attempt\(s\)/)
})

test('a stalled readiness call is bounded per attempt', async () => {
  const scenario = await runScenario(`
dc() {
  case "$1" in
    exec) sleep 10 ;;
    *) return 0 ;;
  esac
}
wait_for_wordpress_filesystem "$log_path" 1 0 1
`)

  assert.ok(scenario.error)
  assert.ok(scenario.elapsed < 4_000, `stalled attempt took ${scenario.elapsed}ms`)
  assert.match(scenario.log, /exceeded its 1-second command deadline/)
})
