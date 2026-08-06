import { defineComponent, nextTick } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { dashboard } from '@/api/dashboard.js'
import { useDashboard } from '@/composables/dashboard/useDashboard.js'

vi.mock('@/api/dashboard.js', () => ({
  dashboard: {
    load: vi.fn(),
  },
}))

function dashboardPayload(overrides = {}) {
  return {
    summary: {
      active_members: 12,
      new_members_30d: 4,
      grants_30d: 6,
      expiring_7d: 2,
      failed_notifications: 1,
    },
    readiness: {
      active_plans: 2,
      protected_items: 8,
      has_active_plan: true,
      has_protected_content: true,
      has_active_members: true,
    },
    attention: [],
    trend: [
      { date: '2026-07-01', count: 10 },
      { date: '2026-07-30', count: 12 },
    ],
    plan_distribution: [
      { plan_id: 1, plan_title: 'Starter', count: 3 },
      { plan_id: 2, plan_title: 'Gold', count: 8 },
      { plan_id: 3, plan_title: 'Dormant', count: 0 },
    ],
    activity: [
      { id: 1, action: 'created', entity_type: 'plan', entity_id: 3, actor_type: 'user', actor_id: 7, occurred_at: null },
      { id: 2, action: 'created', entity_type: 'grant', entity_id: 4, actor_type: 'user', actor_id: 7, occurred_at: null },
      { id: 3, action: 'created', entity_type: 'grant', entity_id: 5, actor_type: 'user', actor_id: 7, occurred_at: null },
      { id: 4, action: 'created', entity_type: 'grant', entity_id: 6, actor_type: 'user', actor_id: 7, occurred_at: null },
      { id: 5, action: 'created', entity_type: 'grant', entity_id: 7, actor_type: 'user', actor_id: 7, occurred_at: null },
      { id: 6, action: 'created', entity_type: 'grant', entity_id: 8, actor_type: 'user', actor_id: 7, occurred_at: null },
      { id: 7, action: 'created', entity_type: 'grant', entity_id: 9, actor_type: 'user', actor_id: 7, occurred_at: null },
    ],
    ...overrides,
  }
}

function mountComposable() {
  let dashboardState
  const Harness = defineComponent({
    setup() {
      dashboardState = useDashboard()
      return () => null
    },
  })

  const wrapper = mount(Harness)
  return { dashboardState, wrapper }
}

describe('useDashboard', () => {
  beforeEach(() => {
    dashboard.load.mockReset()
    document.body.style.setProperty('--fchub-chart-primary', '#6d8cff')
    document.body.style.setProperty('--fchub-text-secondary', '#667085')
  })

  it('loads a wrapped response and derives panel-ready state', async () => {
    dashboard.load.mockResolvedValue({ data: dashboardPayload() })

    const { dashboardState } = mountComposable()
    await flushPromises()

    expect(dashboardState.loading.value).toBe(false)
    expect(dashboardState.errorMessage.value).toBe('')
    expect(dashboardState.summary.value.active_members).toBe(12)
    expect(dashboardState.readinessSteps.value).toHaveLength(3)
    expect(dashboardState.completedReadinessSteps.value).toBe(3)
    expect(dashboardState.rankedPlans.value.map((plan) => plan.plan_title)).toEqual(['Gold', 'Starter'])
    expect(dashboardState.recentActivity.value).toHaveLength(6)
    expect(dashboardState.trendChangeLabel.value).toBe('+2 members')
    expect(dashboardState.trendSummary.value).toBe('Members increased from 10 to 12 across 2 recorded days.')
    expect(dashboardState.distributionWidth(4)).toBe(50)
    expect(dashboardState.membersChartData.value.datasets[0].borderColor).toBe('#6d8cff')
  })

  it('rejects malformed responses without exposing partial dashboard state', async () => {
    dashboard.load.mockResolvedValue({ data: { summary: dashboardPayload().summary } })

    const { dashboardState } = mountComposable()
    await flushPromises()

    expect(dashboardState.loading.value).toBe(false)
    expect(dashboardState.dashboardData.value).toBeNull()
    expect(dashboardState.errorMessage.value).toBe('Dashboard data is incomplete. Please try again.')
  })

  it('refreshes chart colours on the FluentCart theme event and removes its listener', async () => {
    dashboard.load.mockResolvedValue({ data: dashboardPayload() })
    const addEventListener = vi.spyOn(window, 'addEventListener')
    const removeEventListener = vi.spyOn(window, 'removeEventListener')

    const { dashboardState, wrapper } = mountComposable()
    await flushPromises()
    document.body.style.setProperty('--fchub-chart-primary', '#a855f7')
    window.dispatchEvent(new Event('onFluentCartThemeChange'))
    await nextTick()

    expect(addEventListener).toHaveBeenCalledWith('onFluentCartThemeChange', expect.any(Function))
    expect(dashboardState.chartColours.value.primary).toBe('#a855f7')

    wrapper.unmount()
    expect(removeEventListener).toHaveBeenCalledWith('onFluentCartThemeChange', expect.any(Function))
  })
})
