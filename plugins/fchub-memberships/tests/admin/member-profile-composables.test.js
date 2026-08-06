import { computed, nextTick, ref } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import { useMemberProfile } from '@/composables/members/useMemberProfile.js'
import { useMemberActivity } from '@/composables/members/useMemberActivity.js'

describe('member profile composables', () => {
  it('hydrates plan titles from active-plan groups and refreshes only member detail after a grant', async () => {
    const userId = computed(() => '21')
    const membersApi = {
      get: vi.fn().mockResolvedValue({
        data: {
          user: { id: 21, display_name: 'Alice Example' },
          plans: [{
            plan_id: 5,
            plan_title: 'Gold Plan',
            grants: [{ id: 100, plan_id: 5, status: 'active' }],
            progress: { items: [{ id: 'drip-1', title: 'Welcome', status: 'unlocked' }] },
          }],
          history: [{ id: 100, plan_id: 5, status: 'active' }],
        },
      }),
      grant: vi.fn().mockResolvedValue({ data: {} }),
      revoke: vi.fn(),
      extend: vi.fn(),
      pause: vi.fn(),
      resume: vi.fn(),
      dripTimeline: vi.fn(),
    }
    const plansApi = {
      options: vi.fn().mockResolvedValue({ data: [{ id: 5, label: 'Gold Plan' }] }),
    }
    const notify = { success: vi.fn(), error: vi.fn() }
    const profile = useMemberProfile(userId, { membersApi, plansApi, notify })

    await profile.fetchMember()
    await profile.fetchPlanOptions()

    expect(profile.member.value.display_name).toBe('Alice Example')
    expect(profile.allGrants.value).toEqual([{ id: 100, plan_id: 5, status: 'active', plan_title: 'Gold Plan' }])
    expect(profile.timeline.value).toEqual([{ plan_id: 5, plan_title: 'Gold Plan', items: [{ id: 'drip-1', title: 'Welcome', status: 'unlocked' }] }])
    expect(profile.planOptions.value).toEqual([{ id: 5, title: 'Gold Plan' }])

    profile.grantForm.value.plan_id = 5
    await profile.handleGrant()

    expect(membersApi.grant).toHaveBeenCalledWith({ user_id: 21, plan_id: 5 })
    expect(membersApi.get).toHaveBeenCalledTimes(2)
    expect(plansApi.options).toHaveBeenCalledTimes(1)
  })

  it('replaces the first activity page and appends later pages without discarding existing events after a load-more failure', async () => {
    const userId = ref('21')
    const membersApi = {
      activity: vi.fn()
        .mockResolvedValueOnce({ data: [{ type: 'grant_created', date: '2026-03-01' }], total: 3 })
        .mockResolvedValueOnce({ data: [{ type: 'audit_updated', date: '2026-03-02' }] })
        .mockRejectedValueOnce(new Error('network unavailable')),
    }
    const activity = useMemberActivity(userId, { membersApi })

    await activity.fetchActivity()
    await activity.loadMoreActivity()
    await activity.loadMoreActivity()
    await nextTick()

    expect(membersApi.activity).toHaveBeenNthCalledWith(1, '21', { page: 1, per_page: 50 })
    expect(membersApi.activity).toHaveBeenNthCalledWith(2, '21', { page: 2, per_page: 50 })
    expect(membersApi.activity).toHaveBeenNthCalledWith(3, '21', { page: 3, per_page: 50 })
    expect(activity.events.value).toEqual([
      { type: 'grant_created', date: '2026-03-01' },
      { type: 'audit_updated', date: '2026-03-02' },
    ])
    expect(activity.total.value).toBe(3)
    expect(activity.loadingMore.value).toBe(false)
  })
})
