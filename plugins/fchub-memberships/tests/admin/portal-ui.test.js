import { mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import MembershipHistory from '@portal/components/MembershipHistory.vue'
import PlanCard from '@portal/components/PlanCard.vue'

function plan(overrides = {}) {
  return {
    membership_key: '5:subscription:88',
    plan_id: 5,
    plan_title: 'Premium Membership',
    description: 'Everything worth reading.',
    status: 'active',
    source_type: 'subscription',
    source_id: 88,
    expires_at: null,
    access_date_kind: 'lifetime',
    is_lifetime: false,
    next_billing_date: '1 Jun 2026',
    cancellation_effective_at: null,
    progress: { unlocked: 2, total: 4 },
    timeline: [],
    action: {
      kind: 'manage_subscription',
      label: 'Manage subscription',
      url: 'https://example.com/account/subscription/88',
    },
    ...overrides,
  }
}

describe('member portal presentation', () => {
  it('shows FluentCart-owned subscription facts and one management action', () => {
    const wrapper = mount(PlanCard, { props: { plan: plan() } })

    expect(wrapper.text()).toContain('Renews')
    expect(wrapper.text()).toContain('1 Jun 2026')
    expect(wrapper.text()).not.toContain('Lifetime')
    expect(wrapper.text()).not.toContain('Remaining')

    const action = wrapper.get('a.fchub-plan-card__action')
    expect(action.text()).toBe('Manage subscription')
    expect(action.attributes('href')).toBe('https://example.com/account/subscription/88')
  })

  it('reports mixed resource dates without manufacturing an expiry date', () => {
    const wrapper = mount(PlanCard, {
      props: {
        plan: plan({
          source_type: 'manual',
          source_id: 0,
          access_date_kind: 'varies',
          next_billing_date: null,
          action: null,
        }),
      },
    })

    expect(wrapper.text()).toContain('Access')
    expect(wrapper.text()).toContain('Dates vary')
    expect(wrapper.text()).not.toContain('No date')
  })

  it('opens recovery history when requested and exposes accessible state', () => {
    const wrapper = mount(MembershipHistory, {
      props: {
        initiallyExpanded: true,
        entries: [{
          membership_key: '5:order:77',
          plan_id: 5,
          plan_title: 'Premium Membership',
          status: 'expired',
          updated_at: '2026-04-22 00:00:00',
          action: {
            kind: 'renew_membership',
            label: 'Renew membership',
            url: 'https://example.com/checkout',
          },
        }],
      },
    })

    const toggle = wrapper.get('button.fchub-history__toggle')
    expect(toggle.attributes('aria-expanded')).toBe('true')
    expect(toggle.attributes('aria-controls')).toBe('fchub-membership-history')
    expect(wrapper.get('a.fchub-history-entry__action').text()).toBe('Renew membership')
  })

  it('keeps the membership name readable beside mobile recovery actions', () => {
    const source = readFileSync(
      'resources/portal/components/HistoryEntry.vue',
      'utf8',
    )

    expect(source).toContain('@media (max-width: 480px)')
    expect(source).toMatch(/\.fchub-history-entry__title\s*\{[^}]*white-space:\s*normal/s)
    expect(source).toMatch(/\.fchub-history-entry__meta\s*\{[^}]*flex-direction:\s*row/s)
  })
})
