import { describe, expect, it } from 'vitest'
import {
  applyDurationSelection,
  applyMembershipTermMode,
  buildPlanSavePayload,
  createPlanForm,
  membershipTermHint,
  normalisePlanForm,
} from '@/pages/Plans/planEditorForm.js'

describe('plan editor form policy', () => {
  it('creates independent canonical defaults', () => {
    const first = createPlanForm()
    const second = createPlanForm()

    expect(first).toEqual({
      title: '',
      slug: '',
      description: '',
      status: 'inactive',
      includes_plan_ids: [],
      rules: [],
      duration_type: 'lifetime',
      duration_days: null,
      trial_days: 0,
      grace_period_days: 0,
      level: 0,
      meta: {
        billing_anchor_day: null,
        membership_term: {
          mode: 'none',
          value: null,
          unit: 'months',
          date: null,
        },
      },
    })

    first.rules.push({ resource_type: 'post' })
    expect(second.rules).toEqual([])
  })

  it('normalises a persisted plan and historical LearnDash course rules', () => {
    expect(normalisePlanForm({
      title: 'Gold',
      slug: 'gold',
      description: 'Benefits',
      status: 'active',
      level: 2,
      includes_plan_ids: [4],
      duration_type: 'fixed_days',
      duration_days: 30,
      trial_days: 7,
      grace_period_days: 3,
      meta: {
        billing_anchor_day: 12,
        membership_term: { mode: 'custom', value: 8, unit: 'weeks', date: 'ignored' },
      },
      rules: [{
        resource_type: 'sfwd-courses',
        resource_id: 41,
        resource_label: 'Course 41',
        read_only: true,
        drip_type: 'delayed',
        drip_delay_days: 2,
        drip_date: null,
      }],
    })).toEqual({
      title: 'Gold',
      slug: 'gold',
      description: 'Benefits',
      status: 'active',
      includes_plan_ids: [4],
      rules: [{
        resource_type: 'ld_course',
        resource_id: '41',
        resource_label: 'Course 41',
        read_only: true,
        drip_type: 'delayed',
        drip_delay_days: 2,
        drip_date: null,
      }],
      duration_type: 'fixed_days',
      duration_days: 30,
      trial_days: 7,
      grace_period_days: 3,
      level: 2,
      meta: {
        billing_anchor_day: 12,
        membership_term: { mode: 'custom', value: 8, unit: 'weeks', date: 'ignored' },
      },
    })
  })

  it('clears duration-specific fields when duration changes', () => {
    const form = createPlanForm()
    form.duration_days = 30
    form.meta.billing_anchor_day = 15

    expect(applyDurationSelection(form, 'subscription_mirror')).toBe(true)
    expect(form.duration_type).toBe('subscription_mirror')
    expect(form.duration_days).toBeNull()
    expect(form.meta.billing_anchor_day).toBeNull()
    expect(applyDurationSelection(form, 'unknown')).toBe(false)
    expect(form.duration_type).toBe('subscription_mirror')
  })

  it.each([
    ['none', { mode: 'none', value: null, unit: 'months', date: null }],
    ['1y', { mode: '1y', value: null, unit: 'months', date: null }],
    ['custom', { mode: 'custom', value: 1, unit: 'months', date: null }],
    ['date', { mode: 'date', value: null, unit: 'months', date: '2030-01-01' }],
  ])('normalises membership term mode %s', (mode, expected) => {
    const form = createPlanForm()
    form.meta.membership_term = {
      mode,
      value: mode === 'custom' ? null : 9,
      unit: '',
      date: '2030-01-01',
    }

    applyMembershipTermMode(form, mode)
    expect(form.meta.membership_term).toEqual(expected)
  })

  it.each([
    ['lifetime', 'Sets a maximum membership duration. Without a term, lifetime memberships never expire.'],
    ['fixed_days', 'Overrides the fixed days duration with a more flexible term. The shorter of the two wins.'],
    ['subscription_mirror', 'Caps total membership duration regardless of how many times the subscription renews.'],
    ['fixed_anchor', 'Caps total membership duration regardless of how many monthly anchor cycles pass.'],
    ['unknown', 'Sets an absolute upper bound on how long the membership can remain active.'],
  ])('returns the %s membership term hint', (durationType, expected) => {
    expect(membershipTermHint(durationType)).toBe(expected)
  })

  it('builds the exact create payload and omits an automatic slug', () => {
    const form = createPlanForm()
    Object.assign(form, {
      title: 'Gold',
      slug: 'gold',
      description: 'Benefits',
      status: 'active',
      includes_plan_ids: [4],
      rules: [{
        resource_type: 'post',
        resource_id: '5',
        resource_label: 'News',
        drip_type: 'immediate',
        drip_delay_days: null,
        drip_date: null,
      }],
      duration_type: 'fixed_anchor',
      duration_days: 99,
      trial_days: 7,
      grace_period_days: 3,
      level: 2,
    })
    form.meta.billing_anchor_day = 15
    form.meta.membership_term = { mode: 'custom', value: 6, unit: 'months', date: null }

    expect(buildPlanSavePayload(form, { isNew: true, slugManuallyEdited: false })).toEqual({
      title: 'Gold',
      description: 'Benefits',
      status: 'active',
      includes_plan_ids: [4],
      duration_type: 'fixed_anchor',
      duration_days: null,
      trial_days: 7,
      grace_period_days: 3,
      level: 2,
      meta: {
        billing_anchor_day: 15,
        membership_term: { mode: 'custom', value: 6, unit: 'months' },
      },
      rules: [{ resource_type: 'post', resource_id: '5', drip_type: 'immediate' }],
    })
  })

  it('includes a persisted slug and omits a locked legacy rule set', () => {
    const form = createPlanForm()
    form.slug = 'gold'
    form.rules = [{
      resource_type: 'sfwd-lessons',
      resource_id: '51',
      read_only: true,
      drip_type: 'immediate',
    }]

    const payload = buildPlanSavePayload(form, { isNew: false, slugManuallyEdited: true })
    expect(payload.slug).toBe('gold')
    expect(payload).not.toHaveProperty('rules')
    expect(payload.meta).toEqual({ membership_term: { mode: 'none' } })
  })
})
