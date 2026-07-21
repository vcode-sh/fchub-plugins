const ACTIVE_GRANT_STATUSES = new Set(['active', 'paused'])

export function getMemberInitials(member = {}) {
  const name = String(member.display_name || member.name || '').trim()
  const parts = name.split(/\s+/).filter(Boolean)

  if (parts.length > 0) {
    return parts.slice(0, 2).map((part) => part.charAt(0)).join('').toUpperCase()
  }

  const email = String(member.email || member.user_email || '').trim()
  return email ? email.charAt(0).toUpperCase() : '?'
}

export function buildMemberProfileSummary(grants = [], activityCount = 0) {
  const activeGrants = grants.filter((grant) => ACTIVE_GRANT_STATUSES.has(grant.status))

  return {
    activeCount: activeGrants.length,
    historyCount: grants.length,
    lifetimeCount: activeGrants.filter((grant) => !grant.expires_at).length,
    activityCount: Number(activityCount) || 0,
  }
}

export function getMemberAccessState(activeGrants = []) {
  const count = activeGrants.length

  if (count === 0) {
    return {
      hasAccess: false,
      canRevokeAll: false,
      title: 'No active access',
      description: 'Grant a plan to unlock protected content for this member.',
    }
  }

  return {
    hasAccess: true,
    canRevokeAll: true,
    title: `${count} current grant${count === 1 ? '' : 's'}`,
    description: 'Manage the plans and expiry dates currently controlling this member\'s access.',
  }
}

export function normaliseSourceLabel(source) {
  const value = String(source || '').trim()
  if (!value) return 'Unknown'

  return value
    .replace(/[_-]+/g, ' ')
    .replace(/^\w/, (character) => character.toUpperCase())
}
