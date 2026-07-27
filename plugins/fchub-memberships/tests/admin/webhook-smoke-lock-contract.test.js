import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const source = readFileSync(
  resolve(import.meta.dirname, '../runtime/webhook-delivery-smoke.sh'),
  'utf8',
)

describe('webhook delivery smoke ownership', () => {
  it('serialises the whole shared-settings lifecycle with a bounded playground lock', () => {
    const acquire = source.indexOf('\nacquire_runtime_lock\n')
    const snapshot = source.indexOf('settings_snapshot="$(snapshot_settings)"')
    const cleanupVerification = source.indexOf("stage='cleanup verification'")
    const finalRelease = source.lastIndexOf('release_runtime_lock')
    const disableExitTrap = source.indexOf('trap - EXIT', cleanupVerification)

    expect(source).toContain('FCHUB_WEBHOOK_SMOKE_LOCK_TIMEOUT')
    expect(source).toContain('runtime_lock_owned')
    expect(source).toContain('release_runtime_lock || exit_code=1')
    expect(source).toContain('pwd -P')
    expect(acquire).toBeGreaterThan(-1)
    expect(snapshot).toBeGreaterThan(acquire)
    expect(finalRelease).toBeGreaterThan(cleanupVerification)
    expect(disableExitTrap).toBeGreaterThan(finalRelease)
  })

  it('validates a public endpoint while intercepting only the controlled request before transport', () => {
    expect(source).toContain("receiver_host='8.8.8.8'")
    expect(source).toContain("'pre_http_request'")
    expect(source).toContain('http://host.docker.internal:')
    expect(source).not.toContain("'http_api_curl'")
    expect(source).not.toContain('http_request_host_is_external')
  })

  it('proves production hook wiring and observes the original Action Scheduler action', () => {
    expect(source).toContain('do_action(\n            "fchub_memberships/grant_created"')
    expect(source).toContain('"Expected exactly one owned webhook delivery."')
    expect(source).toContain('queue_guard_path=')
    expect(source).toContain('action_scheduler_queue_runner_concurrent_batches')
    expect(source).toContain('as_get_scheduled_actions([')
    expect(source).toContain('$store->delete_action($actionId)')
    expect(source).not.toContain('$wpdb->delete($wpdb->actionscheduler_actions')
    expect(source).not.toContain('ActionScheduler::factory()->single_unique')
    expect(source).not.toContain('Tracked webhook schedule repair failed.')
    expect(source).not.toContain('->onGrantCreated(')
  })
})
