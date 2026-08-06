import { describe, expect, it } from 'vitest'
import {
  PLAN_BUILDER_STEPS,
  appendCommunitySpaceRules,
  buildPlanSummary,
  hasAdvancedPlanSettings,
  isOfferStepComplete,
  isAdvancedValidationField,
  isValidPlanSlug,
  nextBuilderStep,
  normaliseBuilderStep,
  previousBuilderStep,
  stepForValidationFields,
  tabForValidationFields,
} from '@/pages/Plans/planEditorUi.js'

function defaultForm(overrides = {}) {
  return {
    title: 'Gold Membership',
    slug: 'gold-membership',
    status: 'inactive',
    duration_type: 'lifetime',
    duration_days: null,
    level: 0,
    includes_plan_ids: [],
    trial_days: 0,
    grace_period_days: 0,
    meta: {
      billing_anchor_day: null,
      membership_term: { mode: 'none' },
    },
    ...overrides,
  }
}

describe('plan editor UI policy', () => {
  it('appends unique community Space rules from a selected Space Group', () => {
    const existingRules = [
      { resource_type: 'fc_space', resource_id: '2', resource_label: 'Start Here' },
      { resource_type: 'post', resource_id: '55' },
    ]

    expect(appendCommunitySpaceRules(existingRules, [
      { id: '2', label: 'Start Here' },
      { id: 3, label: 'Say Hello' },
      { id: '3', label: 'Say Hello' },
      { id: 0, label: 'Invalid' },
    ])).toEqual({
      added: ['3'],
      rules: [
        ...existingRules,
        {
          resource_type: 'fc_space',
          resource_id: '3',
          resource_label: 'Say Hello',
          drip_type: 'immediate',
          drip_delay_days: null,
          drip_date: null,
        },
      ],
    })
  })

  it('keeps advanced settings collapsed for plan defaults', () => {
    expect(hasAdvancedPlanSettings(defaultForm())).toBe(false)
  })

  it.each([
    { level: 2 },
    { includes_plan_ids: [7] },
    { trial_days: 14 },
    { grace_period_days: 3 },
    { meta: { membership_term: { mode: '1y' } } },
  ])('opens advanced settings for non-default state %#', (override) => {
    expect(hasAdvancedPlanSettings(defaultForm(override))).toBe(true)
  })

  it('routes rule validation to Access rules', () => {
    expect(tabForValidationFields({ 'rules.0.resource_type': [{}] })).toBe('rules')
  })

  it('routes plan validation to Plan details', () => {
    expect(tabForValidationFields({ title: [{}] })).toBe('general')
  })

  it.each([
    'slug',
    'level',
    'includes_plan_ids',
    'trial_days',
    'grace_period_days',
    'meta.membership_term.mode',
  ])('recognises %s as an advanced field', (field) => {
    expect(isAdvancedValidationField(field)).toBe(true)
  })

  it('keeps Title validation in the core section', () => {
    expect(isAdvancedValidationField('title')).toBe(false)
  })

  it('defines the approved builder steps in order', () => {
    expect(PLAN_BUILDER_STEPS.map(({ id }) => id)).toEqual(['offer', 'access', 'review'])
  })

  it.each([
    ['offer', 'offer'],
    ['access', 'access'],
    ['review', 'review'],
    ['unknown', 'offer'],
    ['', 'offer'],
    [undefined, 'offer'],
  ])('normalises %s to %s', (input, expected) => {
    expect(normaliseBuilderStep(input)).toBe(expected)
  })

  it('keeps builder navigation inside its boundaries', () => {
    expect(previousBuilderStep('offer')).toBe('offer')
    expect(nextBuilderStep('offer')).toBe('access')
    expect(previousBuilderStep('review')).toBe('access')
    expect(nextBuilderStep('review')).toBe('review')
  })

  it.each([
    [{ title: '   ' }, false],
    [{ slug: '' }, false],
    [{ slug: 'Gold Membership' }, false],
    [{ slug: '-gold-membership' }, false],
    [{ duration_type: 'fixed_days', duration_days: null }, false],
    [{ duration_type: 'fixed_days', duration_days: 0 }, false],
    [{ duration_type: 'fixed_days', duration_days: Number.NaN }, false],
    [{ duration_type: 'fixed_days', duration_days: 30 }, true],
    [{ duration_type: 'fixed_anchor', meta: { billing_anchor_day: 0 } }, false],
    [{ duration_type: 'fixed_anchor', meta: { billing_anchor_day: 32 } }, false],
    [{ duration_type: 'fixed_anchor', meta: { billing_anchor_day: 15 } }, true],
    [{ duration_type: 'subscription_mirror' }, true],
    [{ duration_type: 'not-a-duration' }, false],
  ])('evaluates offer completeness for %#', (override, expected) => {
    expect(isOfferStepComplete(defaultForm(override))).toBe(expected)
  })

  it('routes builder validation to the owning step', () => {
    expect(stepForValidationFields({ 'rules.0.resource_type': [{}] })).toBe('access')
    expect(stepForValidationFields({ slug: [{}] })).toBe('offer')
    expect(stepForValidationFields({})).toBe('offer')
  })

  it.each([
    ['klub-przyjaciol-psow', true],
    ['%e6%97%a5%e6%9c%ac%e8%aa%9e', true],
    ['%d0%ba%d0%bb%d1%83%d0%b1-7', true],
    ['broken%slug', false],
    ['UPPERCASE', false],
    ['', false],
  ])('validates persisted WordPress slug %s', (slug, expected) => {
    expect(isValidPlanSlug(slug)).toBe(expected)
  })

  it.each([
    ['lifetime', null, 'Lifetime access'],
    ['fixed_days', 1, '1 day'],
    ['fixed_days', 30, '30 days'],
    ['subscription_mirror', null, 'While subscription is active'],
    ['fixed_anchor', null, 'Renews monthly on day 15'],
    ['unknown', null, 'Duration not set'],
  ])('builds a safe %s duration summary', (durationType, durationDays, expected) => {
    const summary = buildPlanSummary(defaultForm({
      duration_type: durationType,
      duration_days: durationDays,
      meta: { billing_anchor_day: 15 },
    }), 0)

    expect(summary.duration).toBe(expected)
  })

  it('uses safe summary fallbacks and correct rule grammar', () => {
    const empty = buildPlanSummary(defaultForm({ title: ' ', status: 'mystery' }), 0)
    const singular = buildPlanSummary(defaultForm({ trial_days: 1 }), 1)
    const plural = buildPlanSummary(defaultForm({ trial_days: 14, status: 'active' }), 3)

    expect(empty).toMatchObject({
      title: 'Untitled plan',
      status: 'Status not set',
      contentAccess: 'No content rules',
      trial: 'No trial',
    })
    expect(singular.contentAccess).toBe('1 content rule')
    expect(singular.trial).toBe('1-day trial')
    expect(plural.contentAccess).toBe('3 content rules')
    expect(plural.trial).toBe('14-day trial')
    expect(plural.status).toBe('Active')
  })
})
