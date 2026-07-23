import { computed, ref } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import MembershipDashboard from '@portal/components/MembershipDashboard.vue'

const portalHarness = vi.hoisted(() => ({ state: null }))

vi.mock('@portal/composables/useMyAccess.js', () => ({
  useMyAccess: () => portalHarness.state,
}))

function community(overrides = {}) {
  return {
    state: 'available',
    profile: { is_verified: true },
    spaces: [],
    courses: [],
    pending_access_count: 0,
    capabilities: {
      spaces: { status: 'available' },
      courses: { status: 'available' },
      profile_verification_read: { status: 'available' },
      badges: { status: 'inactive' },
      points: { status: 'inactive' },
      leaderboard_levels: { status: 'inactive' },
    },
    ...overrides,
  }
}

function portalState(overrides = {}) {
  const plans = ref(overrides.plans || [])
  const history = ref(overrides.history || [])
  const communityState = ref(overrides.community || community())

  return {
    plans,
    history,
    community: communityState,
    loading: ref(Boolean(overrides.loading)),
    error: ref(overrides.error || null),
    refresh: overrides.refresh || vi.fn(),
    hasPlans: computed(() => plans.value.length > 0),
    hasHistory: computed(() => history.value.length > 0),
    hasCommunity: computed(() => (
      communityState.value.spaces.length > 0
      || communityState.value.courses.length > 0
      || communityState.value.pending_access_count > 0
      || communityState.value.profile !== null
    )),
  }
}

function plan(key, title) {
  return {
    membership_key: key,
    plan_id: Number(key.split(':')[0]),
    plan_title: title,
    description: '',
    status: 'active',
    source_type: 'manual',
    source_id: 0,
    expires_at: null,
    access_date_kind: 'lifetime',
    is_lifetime: true,
    next_billing_date: null,
    cancellation_effective_at: null,
    progress: null,
    timeline: [],
    action: null,
  }
}

describe('MembershipDashboard Plan 015 states', () => {
  beforeEach(() => {
    portalHarness.state = portalState()
  })

  it('keeps loading, failure, retry, and empty states explicit', async () => {
    portalHarness.state = portalState({ loading: true })
    const loading = mount(MembershipDashboard)
    expect(loading.find('.fchub-loading').exists()).toBe(true)

    const refresh = vi.fn()
    portalHarness.state = portalState({ error: 'Account access is unavailable', refresh })
    const failed = mount(MembershipDashboard)
    expect(failed.get('[role="alert"]').text()).toContain('Account access is unavailable')
    await failed.get('button').trigger('click')
    expect(refresh).toHaveBeenCalledOnce()

    portalHarness.state = portalState({ community: community({ profile: null }) })
    const empty = mount(MembershipDashboard)
    expect(empty.text()).toContain('No memberships yet')
  })

  it('renders stacked plans and recovery history together', () => {
    portalHarness.state = portalState({
      plans: [plan('5:manual:0', 'Gold'), plan('6:manual:0', 'Workshop')],
      history: [{
        membership_key: '2:order:44',
        plan_id: 2,
        plan_title: 'Starter',
        status: 'expired',
        source_type: 'order',
        source_id: 44,
        updated_at: '2026-07-20 10:00:00',
        action: null,
      }],
      community: community({ profile: null }),
    })

    const wrapper = mount(MembershipDashboard)

    expect(wrapper.findAll('.fchub-plan-card')).toHaveLength(2)
    expect(wrapper.text()).toContain('Past Memberships')
  })

  it('shows core Community access and hides unavailable Pro engagement data', async () => {
    portalHarness.state = portalState({
      community: community({
        capabilities: {
          spaces: { status: 'available' },
          courses: { status: 'available' },
          profile_verification_read: { status: 'available' },
          badges: { status: 'inactive', available: true },
          points: { status: 'inactive', available: true },
          leaderboard_levels: { status: 'inactive', available: true },
        },
        profile: {
          is_verified: true,
          badges: [{ slug: 'founder', title: 'Founder' }],
          total_points: 9001,
          level: { title: 'Legend' },
        },
        spaces: [{
          id: 12,
          title: 'Member Lounge',
          plan_ids: [5, 6],
          ownership: 'fchub',
          operation_state: 'applied',
        }],
        courses: [{
          id: 17,
          title: 'Launch Course',
          plan_ids: [5],
          ownership: 'fchub',
          operation_state: 'pending',
          progress: 42,
        }, {
          id: 18,
          title: 'Deferred Course',
          plan_ids: [5],
          ownership: 'fchub',
          operation_state: 'pending',
          progress: null,
        }],
        pending_access_count: 1,
      }),
    })

    const wrapper = mount(MembershipDashboard)
    await flushPromises()

    expect(wrapper.get('[aria-label="Community access"]').text()).toContain('Member Lounge')
    expect(wrapper.text()).toContain('Launch Course')
    expect(wrapper.text()).toContain('42%')
    expect(wrapper.text()).toContain('Deferred Course')
    expect(wrapper.text()).toContain('Access being prepared')
    expect(wrapper.text()).toContain('Verified profile')
    expect(wrapper.text()).toContain('1 access update needs attention')
    expect(wrapper.text()).not.toContain('Founder')
    expect(wrapper.text()).not.toContain('9001')
    expect(wrapper.text()).not.toContain('Legend')
  })
})
