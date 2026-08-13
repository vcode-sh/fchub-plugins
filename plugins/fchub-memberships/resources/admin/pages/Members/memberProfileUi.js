import { formatWpDate } from '@/utils/wpDate.js'

const ACTIVE_STATUSES = new Set(['active', 'scheduled'])

const STATUS_TAG_TYPES = {
  active: 'success',
  scheduled: 'info',
  paused: 'warning',
  expired: 'warning',
  revoked: 'danger',
}

const ACCESS_REASONS = {
  admin_bypass: () => ({
    allowed: true,
    headline: 'Can open it, as an administrator',
    detail: 'Administrators bypass protection, so this says nothing about ordinary members.',
  }),
  plan_grant: (result) => ({
    allowed: true,
    headline: 'Can open it',
    detail: planDetail(result, 'Unlocked by a plan membership.'),
  }),
  direct_grant: (result) => ({
    allowed: true,
    headline: 'Can open it',
    detail: planDetail(result, 'Unlocked by a grant on this exact resource.'),
  }),
  wildcard_grant: () => ({
    allowed: true,
    headline: 'Can open it',
    detail: 'Unlocked by a grant covering every resource of this type.',
  }),
  drip_locked: (result) => ({
    allowed: false,
    headline: 'Not yet — drip has not released it',
    detail: result.drip_available_at
      ? `Unlocks ${formatWpDate(result.drip_available_at)}.`
      : 'The unlock date has not been scheduled.',
  }),
  membership_paused: () => ({
    allowed: false,
    headline: 'No — the membership is paused',
    detail: 'Resume the membership to restore access.',
  }),
  no_grant: () => ({
    allowed: false,
    headline: 'No — no membership covers this',
    detail: 'Grant a plan that protects this resource.',
  }),
}

function planDetail(result, fallback) {
  const title = result.grant?.plan_title
  return title ? `Unlocked by ${title}.` : fallback
}

export function getMemberInitials(member = {}) {
  const name = String(member.display_name || member.name || '').trim()
  const parts = name.split(/\s+/).filter(Boolean)

  if (parts.length > 0) {
    return parts.slice(0, 2).map((part) => part.charAt(0)).join('').toUpperCase()
  }

  const email = String(member.email || member.user_email || '').trim()
  return email ? email.charAt(0).toUpperCase() : '?'
}

export function isCurrentMembership(membership) {
  return ACTIVE_STATUSES.has(membership.status)
}

export function statusTagType(status) {
  return STATUS_TAG_TYPES[status] || 'info'
}

/**
 * The one sentence an administrator opens this screen to read.
 */
export function buildMemberVerdict(memberships = []) {
  const current = memberships.filter(isCurrentMembership)

  if (current.length === 0) {
    return { hasAccess: false, headline: 'No access', detail: describeLastEnding(memberships) }
  }

  const paused = memberships.filter((membership) => membership.status === 'paused').length
  const detail = [describeEarliestEnd(current), describeRenewal(current), describePaused(paused)]
    .filter(Boolean)
    .join(' · ')

  return {
    hasAccess: true,
    headline: current.length === 1
      ? `Active in ${current[0].plan_title}`
      : `Active in ${current.length} plans`,
    detail,
  }
}

function describeEarliestEnd(current) {
  const dated = current.map((membership) => membership.expires_at).filter(Boolean).sort()

  if (dated.length < current.length) {
    return dated.length === 0 ? 'Lifetime' : `Earliest ends ${formatWpDate(dated[0])}`
  }

  return `Ends ${formatWpDate(dated[0])}`
}

function describeRenewal(current) {
  const subscriptions = current
    .map((membership) => membership.source?.subscription)
    .filter(Boolean)

  const cancelled = subscriptions.find((subscription) => subscription.canceled_at)
  if (cancelled) {
    return 'Subscription cancelled'
  }

  const renewing = subscriptions.find((subscription) => subscription.next_billing_date)
  return renewing ? `Renews ${formatWpDate(renewing.next_billing_date)}` : ''
}

function describePaused(paused) {
  if (paused === 0) return ''
  return paused === 1 ? '1 paused' : `${paused} paused`
}

function describeLastEnding(memberships) {
  const ended = memberships
    .map((membership) => membership.expires_at)
    .filter(Boolean)
    .sort()
    .reverse()

  if (ended.length === 0) {
    return memberships.length === 0
      ? 'This member has never held a membership.'
      : 'No membership is currently in force.'
  }

  return `Last ended ${formatWpDate(ended[0])}`
}

export function describeMembershipTerm(membership) {
  if (membership.status === 'paused') {
    return membership.paused_at ? `Paused since ${formatWpDate(membership.paused_at)}` : 'Paused'
  }

  if (membership.status === 'revoked') {
    return 'Revoked'
  }

  if (membership.status === 'scheduled') {
    return `Starts ${formatWpDate(membership.starts_at)}`
  }

  if (!membership.expires_at) {
    return 'Lifetime'
  }

  return membership.status === 'expired'
    ? `Expired ${formatWpDate(membership.expires_at)}`
    : `Active until ${formatWpDate(membership.expires_at)}`
}

/**
 * Preset expiry dates measured from the current expiry, so extending an
 * unexpired membership never shortens it.
 */
export function buildExtensionPresets(membership, today = new Date()) {
  const from = membership.expires_at && new Date(membership.expires_at) > today
    ? new Date(membership.expires_at)
    : today

  return [
    { label: '+1 month', value: addMonths(from, 1) },
    { label: '+1 year', value: addMonths(from, 12) },
  ]
}

function addMonths(date, months) {
  const shifted = new Date(date.getTime())
  const day = shifted.getDate()
  shifted.setMonth(shifted.getMonth() + months)

  if (shifted.getDate() < day) {
    shifted.setDate(0)
  }

  return toDateValue(shifted)
}

function toDateValue(date) {
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${date.getFullYear()}-${month}-${day}`
}

export function describeAccessReason(result = {}) {
  const describe = ACCESS_REASONS[result.reason]
  if (describe) {
    return describe(result)
  }

  return {
    allowed: Boolean(result.has_access),
    headline: result.has_access ? 'Can open it' : 'No',
    detail: normaliseSourceLabel(result.reason),
  }
}

export function normaliseSourceLabel(source) {
  const value = String(source || '').trim()
  if (!value) return 'Unknown'

  return value
    .replace(/[_-]+/g, ' ')
    .replace(/^\w/, (character) => character.toUpperCase())
}
