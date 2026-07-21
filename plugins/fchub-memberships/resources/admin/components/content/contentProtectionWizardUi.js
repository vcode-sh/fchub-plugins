export const CONTENT_PROTECTION_STEPS = Object.freeze([
  Object.freeze({ id: 0, eyebrow: 'STEP 1', label: 'Choose content', description: 'Choose what to protect' }),
  Object.freeze({ id: 1, eyebrow: 'STEP 2', label: 'Select resource', description: 'Pick the content or items' }),
  Object.freeze({ id: 2, eyebrow: 'STEP 3', label: 'Set access', description: 'Choose who can access' }),
  Object.freeze({ id: 3, eyebrow: 'STEP 4', label: 'Review', description: 'Confirm and apply the rule' }),
])

const CATEGORY_DESCRIPTIONS = Object.freeze({
  posts_pages: 'Protect individual posts and pages.',
  taxonomies: 'Restrict categories, tags, and their archives.',
  cpt: 'Protect content from custom post types.',
  menu: 'Restrict access to specific menu items.',
  url: 'Protect pages using URL patterns and wildcards.',
  special: 'Protect built-in WordPress and system pages.',
  comments: 'Restrict comments globally or on one post.',
})

export function categoryDescription(key) {
  return CATEGORY_DESCRIPTIONS[key] || 'Choose this content type.'
}

export function stepCopy(step, form = {}) {
  const copies = [
    {
      eyebrow: 'STEP 1 OF 4',
      title: 'Start with the content type',
      description: 'Choose the kind of content you want to protect. You will pick the specific item next.',
    },
    {
      eyebrow: 'STEP 2 OF 4',
      title: 'Choose a specific resource',
      description: `Find the ${form.categoryLabel || 'content'} this rule should protect.`,
    },
    {
      eyebrow: 'STEP 3 OF 4',
      title: 'Decide who gets access',
      description: 'Choose the membership plans and the experience for blocked visitors.',
    },
    {
      eyebrow: 'STEP 4 OF 4',
      title: 'Review the protection rule',
      description: 'Check the content, access, and fallback experience before applying the rule.',
    },
  ]

  return copies[Number.isInteger(step) && step >= 0 && step < copies.length ? step : 0]
}

export function hasResourceSelection(form = {}) {
  return Boolean(
    String(form.resource_type || '').trim()
    && String(form.resource_id || '').trim()
  )
}

export function canAdvanceProtectionStep(step, form = {}) {
  if (step === 0) {
    return Boolean(form.categoryKey && form.resource_type)
  }

  if (step === 1) {
    return hasResourceSelection(form)
  }

  if (step === 2) {
    return Array.isArray(form.plan_ids) && form.plan_ids.length > 0
  }

  return true
}
