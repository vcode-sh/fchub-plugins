function isObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}

function isCount(value) {
  return typeof value === 'number' && Number.isFinite(value) && value >= 0
}

function hasKeys(value, keys) {
  return isObject(value) && keys.every((key) => Object.prototype.hasOwnProperty.call(value, key))
}

export function isDashboardResponse(response) {
  if (!hasKeys(response, ['data']) || !isObject(response.data)) return false
  const payload = response.data
  if (!hasKeys(payload, ['summary', 'readiness', 'attention', 'trend', 'plan_distribution', 'activity'])) return false

  const summaryKeys = [
    'active_members',
    'new_members_30d',
    'grants_30d',
    'expiring_7d',
    'failed_notifications',
  ]
  const validSummary = hasKeys(payload.summary, summaryKeys)
    && summaryKeys.every((key) => isCount(payload.summary[key]))

  const validReadiness = hasKeys(payload.readiness, [
    'active_plans',
    'protected_items',
    'has_active_plan',
    'has_protected_content',
    'has_active_members',
  ])
    && isCount(payload.readiness.active_plans)
    && isCount(payload.readiness.protected_items)
    && ['has_active_plan', 'has_protected_content', 'has_active_members']
      .every((key) => typeof payload.readiness[key] === 'boolean')

  const validAttention = Array.isArray(payload.attention) && payload.attention.every((item) => (
    hasKeys(item, ['key', 'severity', 'title', 'description', 'count', 'destination'])
      && ['key', 'severity', 'title', 'description', 'destination'].every((key) => typeof item[key] === 'string')
      && isCount(item.count)
      && item.destination.startsWith('/')
  ))

  const validTrend = Array.isArray(payload.trend) && payload.trend.every((point) => (
    hasKeys(point, ['date', 'count'])
      && typeof point.date === 'string'
      && point.date.length > 0
      && isCount(point.count)
  ))

  const validDistribution = Array.isArray(payload.plan_distribution) && payload.plan_distribution.every((plan) => (
    hasKeys(plan, ['plan_id', 'plan_title', 'count'])
      && Number.isInteger(plan.plan_id)
      && typeof plan.plan_title === 'string'
      && isCount(plan.count)
  ))

  const validActivity = Array.isArray(payload.activity) && payload.activity.every((entry) => (
    hasKeys(entry, ['id', 'action', 'entity_type', 'entity_id', 'actor_type', 'actor_id', 'occurred_at'])
      && Number.isInteger(entry.id)
      && Number.isInteger(entry.entity_id)
      && Number.isInteger(entry.actor_id)
      && ['action', 'entity_type', 'actor_type'].every((key) => typeof entry[key] === 'string')
      && (entry.occurred_at === null || typeof entry.occurred_at === 'string')
  ))

  return validSummary && validReadiness && validAttention && validTrend && validDistribution && validActivity
}

export function withOpacity(colour, opacity) {
  const hex = colour.match(/^#([\da-f]{2})([\da-f]{2})([\da-f]{2})$/i)
  if (hex) {
    return `rgba(${Number.parseInt(hex[1], 16)}, ${Number.parseInt(hex[2], 16)}, ${Number.parseInt(hex[3], 16)}, ${opacity})`
  }
  const rgb = colour.match(/^rgba?\((\d+),\s*(\d+),\s*(\d+)/i)
  return rgb ? `rgba(${rgb[1]}, ${rgb[2]}, ${rgb[3]}, ${opacity})` : colour
}

export function formatCount(value) {
  return new Intl.NumberFormat().format(Number(value) || 0)
}

export function safeSeverity(severity) {
  if (severity === 'error') return 'critical'
  return ['critical', 'warning', 'info'].includes(severity) ? severity : 'info'
}

export function severityLabel(severity) {
  const labels = { critical: 'Critical', warning: 'Warning', info: 'Notice' }
  return labels[safeSeverity(severity)]
}

export function safeDestination(destination) {
  return typeof destination === 'string' && destination.startsWith('/') ? destination : '/'
}

export function entityTypeLabel(entityType) {
  const labels = {
    grant: 'Access',
    plan: 'Plan',
    protection: 'Protection',
    protection_rule: 'Protection',
    notification: 'Notification',
  }
  const value = String(entityType || 'record')
  return labels[value] || value.replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase())
}

export function actionLabel(entry) {
  let action = String(entry.action || '')
  let entityType = String(entry.entity_type || 'record')
  const compound = action.match(/^(grant|plan|protection|protection_rule|notification)_(.+)$/)
  if (compound) {
    entityType = compound[1]
    action = compound[2]
  }

  const labels = {
    grant: {
      created: 'Access granted',
      updated: 'Access updated',
      renewed: 'Access renewed',
      revoked: 'Access revoked',
      expired: 'Access expired',
      deleted: 'Access removed',
    },
    plan: {
      created: 'Plan created',
      updated: 'Plan updated',
      renewed: 'Plan renewed',
      revoked: 'Plan revoked',
      expired: 'Plan expired',
      deleted: 'Plan deleted',
    },
    protection: {
      created: 'Content protected',
      updated: 'Protection updated',
      revoked: 'Protection removed',
      expired: 'Protection expired',
      deleted: 'Protection removed',
    },
    protection_rule: {
      created: 'Content protected',
      updated: 'Protection updated',
      revoked: 'Protection removed',
      expired: 'Protection expired',
      deleted: 'Protection removed',
    },
    notification: {
      created: 'Notification created',
      updated: 'Notification updated',
      failed: 'Notification failed',
      sent: 'Notification sent',
      deleted: 'Notification removed',
    },
  }
  if (labels[entityType]?.[action]) return labels[entityType][action]

  const subject = entityTypeLabel(entityType)
  const verb = action.replaceAll('_', ' ') || 'activity recorded'
  return `${subject} ${verb}`.replace(/^./, (letter) => letter.toUpperCase())
}

export function entityLabel(entry) {
  const type = entityTypeLabel(entry.entity_type)
  return entry.entity_id ? `${type} #${entry.entity_id}` : type
}

export function actorLabel(entry) {
  const type = String(entry.actor_type || 'system')
    .replaceAll('_', ' ')
    .replace(/^./, (letter) => letter.toUpperCase())
  return entry.actor_id ? `${type} #${entry.actor_id}` : type
}

export function toIsoDateTime(value) {
  if (typeof value !== 'string' || !value.trim()) return ''
  const input = value.trim()
  const wpDateTime = input.match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})(?::(\d{2}))?$/)
  if (wpDateTime) return `${wpDateTime[1]}T${wpDateTime[2]}:${wpDateTime[3] || '00'}`

  const parsed = new Date(input)
  return Number.isNaN(parsed.getTime()) ? '' : parsed.toISOString()
}
