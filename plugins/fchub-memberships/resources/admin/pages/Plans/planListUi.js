export function statusTagType(status) {
  const map = {
    active: 'success',
    inactive: 'info',
    archived: 'warning',
  }
  return map[status] || 'info'
}

export function durationLabel(plan) {
  if (plan.duration_type === 'fixed_days') return `${plan.duration_days || 0} days access`
  if (plan.duration_type === 'subscription_mirror') return 'Subscription access'
  if (plan.duration_type === 'fixed_anchor') return 'Calendar anchored'
  return 'Lifetime access'
}

export function readinessState(plan) {
  if (plan.status === 'archived') return 'archived'
  if (plan.scheduled_status && plan.scheduled_at) return 'scheduled'
  if (plan.status === 'active' && Number(plan.rules_count || 0) === 0) return 'attention'
  if (plan.status === 'active') return 'ready'
  return 'inactive'
}

export function readinessLabel(plan) {
  const labels = {
    archived: 'Archived',
    scheduled: 'Change scheduled',
    attention: 'Needs content',
    ready: 'Ready to grant',
    inactive: 'Not published',
  }
  return labels[readinessState(plan)]
}

export function normalisePlanListResponse(response) {
  return {
    rows: Array.isArray(response?.data) ? response.data : [],
    total: Number(response?.total) || 0,
    summary: {
      total: Number(response?.summary?.total) || 0,
      active: Number(response?.summary?.active) || 0,
      needs_content: Number(response?.summary?.needs_content) || 0,
      scheduled: Number(response?.summary?.scheduled) || 0,
    },
  }
}
