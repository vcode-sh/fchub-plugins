import { buildPlanRulesPayload } from '@/utils/planRulePayload.js'

const DURATION_TYPES = new Set([
  'lifetime',
  'fixed_days',
  'subscription_mirror',
  'fixed_anchor',
])

export function createPlanForm() {
  return {
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
  }
}

export function normalisePlanForm(plan = {}) {
  const planMeta = plan.meta || {}
  const savedTerm = planMeta.membership_term || {}

  return {
    title: plan.title || '',
    slug: plan.slug || '',
    description: plan.description || '',
    status: plan.status || 'inactive',
    includes_plan_ids: plan.includes_plan_ids || [],
    rules: (plan.rules || []).map((rule) => ({
      resource_type: rule.resource_type === 'sfwd-courses' ? 'ld_course' : (rule.resource_type || 'post'),
      resource_id: String(rule.resource_id ?? '0'),
      resource_label: rule.resource_label || null,
      read_only: rule.read_only === true,
      drip_type: rule.drip_type || 'immediate',
      drip_delay_days: rule.drip_delay_days ?? null,
      drip_date: rule.drip_date ?? null,
    })),
    duration_type: plan.duration_type || 'lifetime',
    duration_days: plan.duration_days ?? null,
    trial_days: plan.trial_days ?? 0,
    grace_period_days: plan.grace_period_days ?? 0,
    level: plan.level ?? 0,
    meta: {
      billing_anchor_day: planMeta.billing_anchor_day ?? null,
      membership_term: {
        mode: savedTerm.mode || 'none',
        value: savedTerm.value ?? null,
        unit: savedTerm.unit || 'months',
        date: savedTerm.date ?? null,
      },
    },
  }
}

export function applyDurationSelection(form, value) {
  if (!DURATION_TYPES.has(value)) return false

  form.duration_type = value
  if (value !== 'fixed_days') form.duration_days = null
  if (value !== 'fixed_anchor') form.meta.billing_anchor_day = null
  return true
}

export function applyMembershipTermMode(form, mode) {
  const term = form.meta.membership_term
  term.mode = mode

  if (mode === 'none' || mode === '1y' || mode === '2y' || mode === '3y') {
    term.value = null
    term.unit = 'months'
    term.date = null
  } else if (mode === 'custom') {
    term.date = null
    if (!term.value) term.value = 1
    if (!term.unit) term.unit = 'months'
  } else if (mode === 'date') {
    term.value = null
    term.unit = 'months'
  }
}

export function membershipTermHint(durationType) {
  switch (durationType) {
    case 'lifetime':
      return 'Sets a maximum membership duration. Without a term, lifetime memberships never expire.'
    case 'fixed_days':
      return 'Overrides the fixed days duration with a more flexible term. The shorter of the two wins.'
    case 'subscription_mirror':
      return 'Caps total membership duration regardless of how many times the subscription renews.'
    case 'fixed_anchor':
      return 'Caps total membership duration regardless of how many monthly anchor cycles pass.'
    default:
      return 'Sets an absolute upper bound on how long the membership can remain active.'
  }
}

function buildMembershipTerm(term = {}) {
  const payload = { mode: term.mode || 'none' }

  if (payload.mode === 'custom') {
    payload.value = term.value
    payload.unit = term.unit
  } else if (payload.mode === 'date') {
    payload.date = term.date
  }

  return payload
}

export function buildPlanSavePayload(form, { isNew, slugManuallyEdited }) {
  const meta = form.duration_type === 'fixed_anchor'
    ? { billing_anchor_day: form.meta.billing_anchor_day }
    : {}
  meta.membership_term = buildMembershipTerm(form.meta.membership_term)

  const payload = {
    title: form.title,
    description: form.description,
    status: form.status,
    includes_plan_ids: form.includes_plan_ids,
    duration_type: form.duration_type,
    duration_days: form.duration_type === 'fixed_days' ? form.duration_days : null,
    trial_days: form.trial_days,
    grace_period_days: form.grace_period_days,
    level: form.level,
    meta,
  }

  if (!isNew || slugManuallyEdited) {
    payload.slug = form.slug
  }

  const rulesPayload = buildPlanRulesPayload(form.rules)
  if (rulesPayload !== undefined) {
    payload.rules = rulesPayload
  }

  return payload
}
