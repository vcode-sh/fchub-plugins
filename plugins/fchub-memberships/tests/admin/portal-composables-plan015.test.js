import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { getMyAccess } from '@portal/api/client.js'
import { useCountdown } from '@portal/composables/useCountdown.js'
import { useMyAccess } from '@portal/composables/useMyAccess.js'

vi.mock('@portal/api/client.js', () => ({
  getMyAccess: vi.fn(),
}))

function accessPayload() {
  return {
    plans: [{ membership_key: '5:subscription:88', plan_title: 'Gold' }],
    history: [{ membership_key: '2:order:44', plan_title: 'Starter' }],
    community: {
      state: 'available',
      profile: { is_verified: true },
      spaces: [{
        id: 12,
        title: 'Member Lounge',
        plan_ids: [5],
        ownership: 'fchub',
        operation_state: 'applied',
      }],
      courses: [{
        id: 17,
        title: 'Launch Course',
        plan_ids: [5],
        ownership: 'fchub',
        operation_state: 'applied',
        progress: 42,
      }],
      pending_access_count: 0,
      capabilities: {
        spaces: { status: 'available' },
        courses: { status: 'available' },
        profile_verification_read: { status: 'available' },
        badges: { status: 'inactive' },
        points: { status: 'inactive' },
        leaderboard_levels: { status: 'inactive' },
      },
    },
  }
}

describe('useMyAccess', () => {
  beforeEach(() => {
    getMyAccess.mockReset()
  })

  it('loads the complete membership and Community account payload', async () => {
    getMyAccess.mockResolvedValueOnce(accessPayload())

    const result = useMyAccess()
    expect(result.loading.value).toBe(true)

    await flushPromises()

    expect(result.loading.value).toBe(false)
    expect(result.error.value).toBeNull()
    expect(result.plans.value).toHaveLength(1)
    expect(result.history.value).toHaveLength(1)
    expect(result.community.value.spaces[0].title).toBe('Member Lounge')
    expect(result.hasCommunity.value).toBe(true)
  })

  it('reports a failed read and retries the same account request', async () => {
    getMyAccess
      .mockRejectedValueOnce(new Error('Membership data is unavailable'))
      .mockResolvedValueOnce(accessPayload())

    const result = useMyAccess()
    await flushPromises()

    expect(result.loading.value).toBe(false)
    expect(result.error.value).toBe('Membership data is unavailable')
    expect(result.plans.value).toEqual([])

    await result.refresh()

    expect(getMyAccess).toHaveBeenCalledTimes(2)
    expect(result.error.value).toBeNull()
    expect(result.community.value.courses).toHaveLength(1)
  })
})

describe('useCountdown', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-07-23T12:00:00.000Z'))
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('crosses minute and expiry boundaries using the live clock', () => {
    const countdown = useCountdown('2026-07-23T12:01:01.000Z')

    expect(countdown.label.value).toBe('1m 1s')
    expect(countdown.isExpired.value).toBe(false)

    vi.advanceTimersByTime(1_000)
    expect(countdown.label.value).toBe('1m 0s')

    vi.advanceTimersByTime(60_000)
    expect(countdown.label.value).toBe('Available now')
    expect(countdown.isExpired.value).toBe(true)
  })
})
