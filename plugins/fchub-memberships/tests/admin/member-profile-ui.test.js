import { describe, expect, it } from 'vitest'
import {
  buildExtensionPresets,
  buildMemberVerdict,
  describeAccessReason,
  describeMembershipTerm,
  getMemberInitials,
  isCurrentMembership,
  normaliseSourceLabel,
} from '@/pages/Members/memberProfileUi.js'

function membership(overrides = {}) {
  return {
    key: 'plan:1',
    plan_id: 1,
    plan_title: 'Gold Plan',
    status: 'active',
    starts_at: null,
    expires_at: null,
    paused_at: null,
    source: { label: 'Order #12', url: null, subscription: null },
    ...overrides,
  }
}

describe('member profile identity', () => {
  it('builds compact identity initials with safe fallbacks', () => {
    expect(getMemberInitials({ display_name: 'Ada Lovelace' })).toBe('AL')
    expect(getMemberInitials({ display_name: '  ' , email: 'ada@example.com' })).toBe('A')
    expect(getMemberInitials({})).toBe('?')
  })
})

describe('member verdict', () => {
  it('says plainly when nobody granted anything', () => {
    expect(buildMemberVerdict([])).toEqual({
      hasAccess: false,
      headline: 'No access',
      detail: 'This member has never held a membership.',
    })
  })

  it('names the date the last membership ended when none is current', () => {
    const verdict = buildMemberVerdict([
      membership({ status: 'expired', expires_at: '2026-03-03 00:00:00' }),
      membership({ key: 'plan:2', status: 'revoked', expires_at: '2026-01-01 00:00:00' }),
    ])

    expect(verdict.hasAccess).toBe(false)
    expect(verdict.detail).toContain('Last ended')
    expect(verdict.detail).toContain('2026')
  })

  it('names the single plan a member holds', () => {
    const verdict = buildMemberVerdict([membership({ expires_at: '2026-09-12 00:00:00' })])

    expect(verdict.headline).toBe('Active in Gold Plan')
    expect(verdict.detail).toContain('Ends')
  })

  it('reports the earliest ending when several plans are held', () => {
    const verdict = buildMemberVerdict([
      membership({ expires_at: '2026-12-01 00:00:00' }),
      membership({ key: 'plan:2', expires_at: '2026-09-12 00:00:00' }),
    ])

    expect(verdict.headline).toBe('Active in 2 plans')
    expect(verdict.detail).toContain('Ends')
  })

  it('calls a mixed lifetime and dated set the earliest ending rather than lifetime', () => {
    const verdict = buildMemberVerdict([
      membership({ expires_at: null }),
      membership({ key: 'plan:2', expires_at: '2026-09-12 00:00:00' }),
    ])

    expect(verdict.detail).toContain('Earliest ends')
  })

  it('warns that a cancelled subscription will stop the renewal', () => {
    const verdict = buildMemberVerdict([
      membership({
        expires_at: '2026-09-12 00:00:00',
        source: {
          label: 'Subscription #5',
          url: null,
          subscription: { status: 'cancelled', canceled_at: '2026-08-01 00:00:00', next_billing_date: null },
        },
      }),
    ])

    expect(verdict.detail).toContain('Subscription cancelled')
  })

  it('reports the renewal date of a live subscription', () => {
    const verdict = buildMemberVerdict([
      membership({
        expires_at: '2026-09-12 00:00:00',
        source: {
          label: 'Subscription #5',
          url: null,
          subscription: { status: 'active', canceled_at: null, next_billing_date: '2026-09-12 00:00:00' },
        },
      }),
    ])

    expect(verdict.detail).toContain('Renews')
  })

  it('counts paused memberships alongside the current ones', () => {
    const verdict = buildMemberVerdict([
      membership({ expires_at: '2026-09-12 00:00:00' }),
      membership({ key: 'plan:2', status: 'paused' }),
    ])

    expect(verdict.detail).toContain('1 paused')
  })

  it('treats a scheduled membership as current so it is not reported as no access', () => {
    expect(isCurrentMembership(membership({ status: 'scheduled' }))).toBe(true)
    expect(buildMemberVerdict([membership({ status: 'scheduled' })]).hasAccess).toBe(true)
  })
})

describe('membership term', () => {
  it('describes each state in the reader\'s terms', () => {
    expect(describeMembershipTerm(membership({ expires_at: null }))).toBe('Lifetime')
    expect(describeMembershipTerm(membership({ expires_at: '2026-09-12 00:00:00' })))
      .toContain('Active until')
    expect(describeMembershipTerm(membership({ status: 'expired', expires_at: '2026-01-01 00:00:00' })))
      .toContain('Expired')
    expect(describeMembershipTerm(membership({ status: 'paused', paused_at: '2026-03-03 00:00:00' })))
      .toContain('Paused since')
    expect(describeMembershipTerm(membership({ status: 'scheduled', starts_at: '2027-01-01 00:00:00' })))
      .toContain('Starts')
    expect(describeMembershipTerm(membership({ status: 'revoked' }))).toBe('Revoked')
  })
})

describe('extension presets', () => {
  it('measures from the current expiry so an extension never shortens access', () => {
    const presets = buildExtensionPresets(
      membership({ expires_at: '2026-12-01 00:00:00' }),
      new Date('2026-08-13T12:00:00'),
    )

    expect(presets).toEqual([
      { label: '+1 month', value: '2027-01-01' },
      { label: '+1 year', value: '2027-12-01' },
    ])
  })

  it('measures from today when the membership has already expired', () => {
    const presets = buildExtensionPresets(
      membership({ status: 'expired', expires_at: '2026-01-01 00:00:00' }),
      new Date('2026-08-13T12:00:00'),
    )

    expect(presets[0].value).toBe('2026-09-13')
  })

  it('measures from today for a lifetime membership rather than crashing', () => {
    const presets = buildExtensionPresets(membership({ expires_at: null }), new Date('2026-08-13T12:00:00'))

    expect(presets[0].value).toBe('2026-09-13')
  })

  it('clamps a month end that the next month does not have', () => {
    const presets = buildExtensionPresets(
      membership({ expires_at: '2026-10-31 00:00:00' }),
      new Date('2026-08-13T12:00:00'),
    )

    expect(presets[0].value).toBe('2026-11-30')
    expect(presets[1].value).toBe('2027-10-31')
  })
})

describe('access check answers', () => {
  it('warns that an administrator answer proves nothing about members', () => {
    const answer = describeAccessReason({ has_access: true, reason: 'admin_bypass' })

    expect(answer.allowed).toBe(true)
    expect(answer.detail).toContain('nothing about ordinary members')
  })

  it('names the plan that unlocked the resource', () => {
    const answer = describeAccessReason({
      has_access: true,
      reason: 'plan_grant',
      grant: { plan_title: 'Gold Plan' },
    })

    expect(answer.detail).toBe('Unlocked by Gold Plan.')
  })

  it('gives the drip unlock date rather than a bare refusal', () => {
    const answer = describeAccessReason({
      has_access: false,
      reason: 'drip_locked',
      drip_available_at: '2026-09-20 00:00:00',
    })

    expect(answer.allowed).toBe(false)
    expect(answer.detail).toContain('Unlocks')
  })

  it('distinguishes a paused membership from having none', () => {
    expect(describeAccessReason({ reason: 'membership_paused' }).headline)
      .toContain('paused')
    expect(describeAccessReason({ reason: 'no_grant' }).headline)
      .toContain('no membership')
  })

  it('still says something useful for a reason it does not recognise', () => {
    const answer = describeAccessReason({ has_access: false, reason: 'wormhole_collapse' })

    expect(answer.allowed).toBe(false)
    expect(answer.detail).toBe('Wormhole collapse')
  })
})

describe('source labels', () => {
  it('turns machine values into readable labels', () => {
    expect(normaliseSourceLabel('fluent_community')).toBe('Fluent community')
    expect(normaliseSourceLabel('')).toBe('Unknown')
  })
})
