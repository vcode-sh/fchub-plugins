import { computed, nextTick, ref } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import { useMemberAccessCheck } from '@/composables/members/useMemberAccessCheck.js'
import { useMemberActivity } from '@/composables/members/useMemberActivity.js'
import { useMemberProfile } from '@/composables/members/useMemberProfile.js'

function membership(overrides = {}) {
  return {
    key: 'plan:5',
    plan_id: 5,
    plan_title: 'Gold Plan',
    status: 'active',
    expires_at: null,
    grant_ids: [100, 101],
    resources: [],
    source: { label: 'Manual grant', url: null, subscription: null },
    ...overrides,
  }
}

function profileApi(overrides = {}) {
  return {
    get: vi.fn().mockResolvedValue({
      data: { user: { id: 21, display_name: 'Alice Example' }, memberships: [membership()] },
    }),
    grant: vi.fn().mockResolvedValue({ data: {} }),
    revoke: vi.fn().mockResolvedValue({ data: {} }),
    extend: vi.fn().mockResolvedValue({ data: {} }),
    pause: vi.fn().mockResolvedValue({ data: {} }),
    resume: vi.fn().mockResolvedValue({ data: {} }),
    dripTimeline: vi.fn().mockResolvedValue({ data: [{ id: 1, title: 'Welcome', status: 'unlocked' }] }),
    providerState: vi.fn().mockResolvedValue({ data: [{ provider: 'wordpress_core', classification: 'local_only' }] }),
    ...overrides,
  }
}

function setup(overrides = {}) {
  const membersApi = profileApi(overrides)
  const plansApi = { options: vi.fn().mockResolvedValue({ data: [{ id: 5, label: 'Gold Plan' }] }) }
  const notify = { success: vi.fn(), error: vi.fn() }

  return {
    membersApi,
    plansApi,
    notify,
    profile: useMemberProfile(computed(() => '21'), { membersApi, plansApi, notify }),
  }
}

describe('member profile composable', () => {
  it('reads memberships as the server composed them and refreshes only the member after a grant', async () => {
    const { profile, membersApi, plansApi } = setup()

    await profile.fetchMember()
    await profile.fetchPlanOptions()

    expect(profile.member.value.display_name).toBe('Alice Example')
    expect(profile.memberships.value).toHaveLength(1)
    expect(profile.verdict.value.headline).toBe('Active in Gold Plan')
    expect(profile.planOptions.value).toEqual([{ id: 5, title: 'Gold Plan' }])

    profile.grantForm.value.plan_id = 5
    await profile.handleGrant()

    expect(membersApi.grant).toHaveBeenCalledWith({ user_id: 21, plan_id: 5 })
    expect(membersApi.get).toHaveBeenCalledTimes(2)
    expect(plansApi.options).toHaveBeenCalledTimes(1)
  })

  it('keeps the grant dialog open and the form intact when granting fails', async () => {
    const { profile, notify } = setup({ grant: vi.fn().mockRejectedValue(new Error('plan is archived')) })

    profile.grantDialogVisible.value = true
    profile.grantForm.value.plan_id = 5
    await profile.handleGrant()

    expect(profile.grantDialogVisible.value).toBe(true)
    expect(profile.grantForm.value.plan_id).toBe(5)
    expect(notify.error).toHaveBeenCalledWith('plan is archived')
  })

  it('pauses a membership through one of its grants rather than looping over every row', async () => {
    const { profile, membersApi } = setup()

    await profile.fetchMember()
    await profile.handlePause(profile.memberships.value[0])

    expect(membersApi.pause).toHaveBeenCalledTimes(1)
    expect(membersApi.pause).toHaveBeenCalledWith({ grant_id: 100 })
  })

  it('revokes and extends by plan, which is the scope the card displays', async () => {
    const { profile, membersApi } = setup()

    await profile.fetchMember()
    await profile.handleRevoke(profile.memberships.value[0])
    profile.openExtendDialog(profile.memberships.value[0])
    profile.extendDate.value = '2027-01-01'
    await profile.handleExtend()

    expect(membersApi.revoke).toHaveBeenCalledWith({ user_id: 21, plan_id: 5 })
    expect(membersApi.extend).toHaveBeenCalledWith({
      user_id: 21,
      plan_id: 5,
      expires_at: '2027-01-01 23:59:59',
    })
  })

  it('sends an expiry the REST validator accepts, whichever way the date was chosen', async () => {
    const { profile, membersApi } = setup()
    await profile.fetchMember()

    for (const picked of ['2027-01-01', '2028-02-29', '2026-12-31 08:30:00']) {
      profile.openExtendDialog(profile.memberships.value[0])
      profile.extendDate.value = picked
      await profile.handleExtend()
    }

    for (const call of membersApi.extend.mock.calls) {
      expect(call[0].expires_at).toMatch(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/)
    }
  })

  it('sends a grant expiry in the same accepted shape', async () => {
    const { profile, membersApi } = setup()

    profile.grantForm.value.plan_id = 5
    profile.grantForm.value.expires_at = '2027-01-01'
    await profile.handleGrant()

    expect(membersApi.grant).toHaveBeenCalledWith({
      user_id: 21,
      plan_id: 5,
      expires_at: '2027-01-01 23:59:59',
    })
  })

  it('omits the expiry entirely for a lifetime grant rather than sending an empty string', async () => {
    const { profile, membersApi } = setup()

    profile.grantForm.value.plan_id = 5
    await profile.handleGrant()

    expect(membersApi.grant).toHaveBeenCalledWith({ user_id: 21, plan_id: 5 })
  })

  it('revokes every current membership once, without repeating a shared plan', async () => {
    const { profile, membersApi } = setup({
      get: vi.fn().mockResolvedValue({
        data: {
          user: { id: 21 },
          memberships: [
            membership(),
            membership({ key: 'plan:6', plan_id: 6 }),
            membership({ key: 'plan:7', plan_id: 7, status: 'expired' }),
          ],
        },
      }),
    })

    await profile.fetchMember()
    await profile.handleRevokeAll()

    expect(membersApi.revoke).toHaveBeenCalledTimes(2)
    expect(profile.revokingAll.value).toBe(false)
  })

  it('loads drip only when a card is opened, and only once', async () => {
    const { profile, membersApi } = setup()

    await profile.fetchMember()
    expect(membersApi.dripTimeline).not.toHaveBeenCalled()

    const [card] = profile.memberships.value
    await profile.toggleExpanded(card)
    await profile.toggleExpanded(card)
    await profile.toggleExpanded(card)

    expect(membersApi.dripTimeline).toHaveBeenCalledTimes(1)
    expect(profile.expandedKeys.value).toEqual(['plan:5'])
    expect(profile.dripByKey.value['plan:5']).toHaveLength(1)
  })

  it('never asks the providers while loading the page', async () => {
    const { profile, membersApi } = setup()

    await profile.fetchMember()
    await profile.toggleExpanded(profile.memberships.value[0])

    expect(membersApi.providerState).not.toHaveBeenCalled()

    await profile.checkProviders(profile.memberships.value[0])

    expect(membersApi.providerState).toHaveBeenCalledTimes(1)
    expect(profile.providerStateByKey.value['plan:5']).toHaveLength(1)
    expect(profile.providerCheckPending.value).toBe('')
  })

  it('reports a failed provider check instead of leaving a spinner running', async () => {
    const { profile, notify } = setup({
      providerState: vi.fn().mockRejectedValue(new Error('community unreachable')),
    })

    await profile.fetchMember()
    await profile.checkProviders(profile.memberships.value[0])

    expect(notify.error).toHaveBeenCalledWith('community unreachable')
    expect(profile.providerCheckPending.value).toBe('')
  })
})

describe('member activity composable', () => {
  it('replaces the first page and keeps loaded events after a later page fails', async () => {
    const userId = ref('21')
    const membersApi = {
      activity: vi.fn()
        .mockResolvedValueOnce({ data: [{ type: 'granted', date: '2026-03-01' }], total: 3 })
        .mockResolvedValueOnce({ data: [{ type: 'extended', date: '2026-03-02' }] })
        .mockRejectedValueOnce(new Error('network unavailable')),
    }
    const activity = useMemberActivity(userId, { membersApi })

    await activity.fetchActivity()
    await activity.loadMoreActivity()
    await activity.loadMoreActivity()
    await nextTick()

    expect(membersApi.activity).toHaveBeenNthCalledWith(1, '21', { page: 1, per_page: 50 })
    expect(membersApi.activity).toHaveBeenNthCalledWith(3, '21', { page: 3, per_page: 50 })
    expect(activity.events.value).toEqual([
      { type: 'granted', date: '2026-03-01' },
      { type: 'extended', date: '2026-03-02' },
    ])
    expect(activity.total.value).toBe(3)
    expect(activity.loadingMore.value).toBe(false)
  })
})

describe('member access check composable', () => {
  function checkSetup(overrides = {}) {
    const contentApi = {
      list: vi.fn().mockResolvedValue({
        data: [{ resource_type: 'post', resource_id: '55', title: 'Members only' }],
      }),
    }
    const accessCheckApi = {
      check: vi.fn().mockResolvedValue({
        has_access: false,
        reason: 'drip_locked',
        drip_available_at: '2026-09-20 00:00:00',
      }),
      ...overrides,
    }

    return { contentApi, accessCheckApi, check: useMemberAccessCheck(ref(21), { contentApi, accessCheckApi }) }
  }

  it('does not search on a single character', async () => {
    const { check, contentApi } = checkSetup()

    await check.search('a')

    expect(contentApi.list).not.toHaveBeenCalled()
    expect(check.options.value).toEqual([])
  })

  it('turns protected content into options and answers with the evaluator reason', async () => {
    const { check, accessCheckApi } = checkSetup()

    await check.search('members')
    check.selected.value = 'post:55'
    await check.check()

    expect(check.options.value[0].label).toBe('Members only')
    expect(accessCheckApi.check).toHaveBeenCalledWith({
      user_id: 21,
      resource_type: 'post',
      resource_id: '55',
    })
    expect(check.result.value.allowed).toBe(false)
    expect(check.result.value.detail).toContain('Unlocks')
    expect(check.result.value.resource).toBe('Members only')
  })

  it('checks nothing when the selection does not match a loaded option', async () => {
    const { check, accessCheckApi } = checkSetup()

    check.selected.value = 'post:999'
    await check.check()

    expect(accessCheckApi.check).not.toHaveBeenCalled()
    expect(check.result.value).toBeNull()
  })

  it('reports a failed check rather than pretending the member has no access', async () => {
    const { check } = checkSetup({ check: vi.fn().mockRejectedValue(new Error('evaluator unavailable')) })

    await check.search('members')
    check.selected.value = 'post:55'
    await check.check()

    expect(check.result.value.headline).toBe('Could not be checked')
    expect(check.result.value.detail).toBe('evaluator unavailable')
    expect(check.checking.value).toBe(false)
  })
})
