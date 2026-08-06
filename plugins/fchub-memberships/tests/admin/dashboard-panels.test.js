import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import DashboardActivityPanel from '@/components/dashboard/DashboardActivityPanel.vue'
import DashboardAttentionPanel from '@/components/dashboard/DashboardAttentionPanel.vue'
import DashboardDistributionPanel from '@/components/dashboard/DashboardDistributionPanel.vue'
import DashboardReadinessPanel from '@/components/dashboard/DashboardReadinessPanel.vue'
import DashboardSummaryPanel from '@/components/dashboard/DashboardSummaryPanel.vue'
import DashboardTrendPanel from '@/components/dashboard/DashboardTrendPanel.vue'

const RouterLink = {
  props: ['to'],
  template: '<a :href="`#${to}`"><slot /></a>',
}

function mountPanel(component, props) {
  return mount(component, {
    props,
    global: { stubs: { RouterLink } },
  })
}

describe('dashboard visual panels', () => {
  it('renders attention items with safe routes and severity classes', () => {
    const wrapper = mountPanel(DashboardAttentionPanel, {
      items: [{
        key: 'delivery',
        severity: 'error',
        title: 'Delivery failed',
        description: 'One notification needs attention.',
        count: 1,
        destination: 'https://example.com',
      }],
    })

    expect(wrapper.classes()).toEqual(['attention-section', 'panel'])
    expect(wrapper.get('.attention-item').classes()).toContain('attention-item--critical')
    expect(wrapper.get('.attention-item').attributes('href')).toBe('#/')
    expect(wrapper.get('.severity-label').text()).toBe('Critical')
  })

  it('renders all membership summary destinations and values', () => {
    const wrapper = mountPanel(DashboardSummaryPanel, {
      summary: {
        active_members: 12,
        new_members_30d: 4,
        grants_30d: 6,
        expiring_7d: 2,
        failed_notifications: 1,
      },
    })

    expect(wrapper.classes()).toEqual(['summary-grid'])
    expect(wrapper.findAll('.summary-metric')).toHaveLength(4)
    expect(wrapper.findAll('.summary-value').map((node) => node.text())).toEqual(['12', '4', '2', '1'])
    expect(wrapper.findAll('.summary-metric').map((node) => node.attributes('href'))).toEqual([
      '#/members',
      '#/members',
      '#/members',
      '#/drip',
    ])
  })

  it('renders readiness actions only for incomplete steps', () => {
    const wrapper = mountPanel(DashboardReadinessPanel, {
      completedSteps: 1,
      steps: [
        { key: 'plans', title: 'Active plans', count: 1, complete: true, description: 'Ready.', action: 'Create plan', destination: '/plans/new' },
        { key: 'content', title: 'Protected items', count: 0, complete: false, description: 'Protect content.', action: 'Protect content', destination: '/content' },
      ],
    })

    expect(wrapper.classes()).toEqual(['panel', 'readiness-panel'])
    expect(wrapper.get('.readiness-score').text()).toBe('1/3 ready')
    expect(wrapper.findAll('.readiness-step')).toHaveLength(2)
    expect(wrapper.findAll('.text-action')).toHaveLength(1)
    expect(wrapper.get('.text-action').attributes('href')).toBe('#/content')
  })

  it('renders the chart and its accessible summary without an extra root wrapper', () => {
    const wrapper = mountPanel(DashboardTrendPanel, {
      hasTrend: true,
      chartColours: { primary: '#6d8cff' },
      chartData: { labels: ['1 Aug', '2 Aug'], datasets: [{ data: [10, 12] }] },
      chartOptions: { responsive: true },
      changeLabel: '+2 members',
      summary: 'Members increased from 10 to 12 across 2 recorded days.',
    })

    expect(wrapper.classes()).toEqual(['panel', 'trend-panel'])
    expect(wrapper.attributes('style')).toContain('--dashboard-chart-primary: #6d8cff')
    expect(wrapper.get('[data-chart="line"]').attributes('aria-describedby')).toBe('member-trend-summary')
    expect(wrapper.get('#member-trend-summary').text()).toContain('Members increased from 10 to 12')
  })

  it('renders ranked plan widths and the existing empty-state destination', () => {
    const populated = mountPanel(DashboardDistributionPanel, {
      plans: [{ plan_id: 2, plan_title: 'Gold', count: 8 }],
      hasActivePlan: true,
      distributionWidth: () => 100,
    })
    expect(populated.classes()).toEqual(['panel', 'distribution-panel'])
    expect(populated.get('.distribution-track span').attributes('style')).toContain('width: 100%')

    const empty = mountPanel(DashboardDistributionPanel, {
      plans: [],
      hasActivePlan: false,
      distributionWidth: () => 0,
    })
    expect(empty.get('.text-action').attributes('href')).toBe('#/plans/new')
  })

  it('renders formatted activity labels and honest empty activity', () => {
    const populated = mountPanel(DashboardActivityPanel, {
      activity: [{
        id: 1,
        action: 'plan_created',
        entity_type: 'record',
        entity_id: 3,
        actor_type: 'wp_cli',
        actor_id: 7,
        occurred_at: '2026-07-20 13:45:00',
      }],
    })
    expect(populated.classes()).toEqual(['panel', 'activity-panel'])
    expect(populated.get('.activity-copy').text()).toContain('Plan created')
    expect(populated.get('time').attributes('datetime')).toBe('2026-07-20T13:45:00')

    const empty = mountPanel(DashboardActivityPanel, { activity: [] })
    expect(empty.text()).toContain('No recorded activity yet')
  })
})
