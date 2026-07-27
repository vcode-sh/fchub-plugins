import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const observer = await readFile(
  new URL('../../scripts/wporg/lifecycle-http-observer.php', import.meta.url),
  'utf8',
)

test('lifecycle HTTP observer records only target-plugin request origins', () => {
  assert.match(observer, /pre_http_request/)
  assert.match(observer, /wporg_lifecycle_observed_slug/)
  assert.match(observer, /wporg_lifecycle_http_attempts/)
  assert.match(observer, /\/wp-content\/plugins\//)
  assert.match(observer, /debug_backtrace\(DEBUG_BACKTRACE_IGNORE_ARGS\)/)
  assert.match(observer, /WP_Error/)
})
