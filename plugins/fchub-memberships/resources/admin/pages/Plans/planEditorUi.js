const ADVANCED_FIELDS = new Set([
  'slug',
  'level',
  'includes_plan_ids',
  'trial_days',
  'grace_period_days',
])

export const PLAN_BUILDER_STEPS = Object.freeze([
  Object.freeze({ id: 'offer', number: 1, eyebrow: 'STEP 1', label: 'The offer', description: 'Name, duration and availability' }),
  Object.freeze({ id: 'access', number: 2, eyebrow: 'STEP 2', label: 'Content access', description: 'What members can unlock' }),
  Object.freeze({ id: 'review', number: 3, eyebrow: 'STEP 3', label: 'Review', description: 'Check and create the plan' }),
])

const BUILDER_STEP_IDS = PLAN_BUILDER_STEPS.map(({ id }) => id)
const VALID_SLUG = /^[a-z0-9]+(?:-[a-z0-9]+)*$/
const VALID_DURATION_TYPES = new Set([
  'lifetime',
  'fixed_days',
  'subscription_mirror',
  'fixed_anchor',
])

export function normaliseBuilderStep(step) {
  return BUILDER_STEP_IDS.includes(step) ? step : 'offer'
}

export function nextBuilderStep(step) {
  const index = BUILDER_STEP_IDS.indexOf(normaliseBuilderStep(step))
  return BUILDER_STEP_IDS[Math.min(index + 1, BUILDER_STEP_IDS.length - 1)]
}

export function previousBuilderStep(step) {
  const index = BUILDER_STEP_IDS.indexOf(normaliseBuilderStep(step))
  return BUILDER_STEP_IDS[Math.max(index - 1, 0)]
}

export function stepForValidationFields(fields = {}) {
  return Object.keys(fields).some((field) => field.startsWith('rules.'))
    ? 'access'
    : 'offer'
}

export function isOfferStepComplete(form = {}) {
  const title = String(form.title || '').trim()
  const slug = String(form.slug || '').trim()
  const durationType = form.duration_type

  if (!title || !VALID_SLUG.test(slug) || !VALID_DURATION_TYPES.has(durationType)) {
    return false
  }

  if (durationType === 'fixed_days') {
    return Number.isFinite(Number(form.duration_days)) && Number(form.duration_days) > 0
  }

  if (durationType === 'fixed_anchor') {
    const anchorDay = Number(form.meta?.billing_anchor_day)
    return Number.isInteger(anchorDay) && anchorDay >= 1 && anchorDay <= 31
  }

  return true
}

function durationLabel(form) {
  switch (form.duration_type) {
    case 'lifetime':
      return 'Lifetime access'
    case 'fixed_days': {
      const days = Number(form.duration_days)
      if (!Number.isFinite(days) || days <= 0) return 'Duration not set'
      return `${days} ${days === 1 ? 'day' : 'days'}`
    }
    case 'subscription_mirror':
      return 'While subscription is active'
    case 'fixed_anchor': {
      const anchorDay = Number(form.meta?.billing_anchor_day)
      return Number.isInteger(anchorDay) && anchorDay >= 1 && anchorDay <= 31
        ? `Renews monthly on day ${anchorDay}`
        : 'Billing day not set'
    }
    default:
      return 'Duration not set'
  }
}

export function buildPlanSummary(form = {}, ruleCount = 0) {
  const count = Number.isFinite(Number(ruleCount)) && Number(ruleCount) > 0
    ? Math.floor(Number(ruleCount))
    : 0
  const trialDays = Number(form.trial_days)
  const statuses = {
    active: 'Active',
    inactive: 'Inactive',
    archived: 'Archived',
  }

  return {
    title: String(form.title || '').trim() || 'Untitled plan',
    status: statuses[form.status] || 'Status not set',
    duration: durationLabel(form),
    contentAccess: count === 0
      ? 'No content rules'
      : `${count} content ${count === 1 ? 'rule' : 'rules'}`,
    trial: Number.isFinite(trialDays) && trialDays > 0
      ? `${trialDays}-day trial`
      : 'No trial',
  }
}

export function hasAdvancedPlanSettings(form) {
  return Number(form.level || 0) > 0
    || (form.includes_plan_ids?.length || 0) > 0
    || Number(form.trial_days || 0) > 0
    || Number(form.grace_period_days || 0) > 0
    || (form.meta?.membership_term?.mode || 'none') !== 'none'
}

export function tabForValidationFields(fields = {}) {
  return Object.keys(fields).some((field) => field.startsWith('rules.'))
    ? 'rules'
    : 'general'
}

export function isAdvancedValidationField(field = '') {
  return ADVANCED_FIELDS.has(field) || field.startsWith('meta.membership_term')
}
