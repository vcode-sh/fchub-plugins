import { describe, expect, it } from 'vitest'
import {
  buildMemberProfileSummary,
  getMemberInitials,
  getMemberAccessState,
  normaliseSourceLabel,
} from '@/pages/Members/memberProfileUi.js'

describe('member profile UI policy', () => {
  it('builds compact identity initials with safe fallbacks', () => {
    expect(getMemberInitials({ display_name: 'Alice Montgomery' })).toBe('AM')
    expect(getMemberInitials({ display_name: 'Alice' })).toBe('A')
    expect(getMemberInitials({ email: 'owner@example.com' })).toBe('O')
    expect(getMemberInitials({})).toBe('?')
  })

  it('summarises current access separately from historical grants', () => {
    const summary = buildMemberProfileSummary([
      { id: 1, status: 'active', expires_at: null },
      { id: 2, status: 'paused', expires_at: '2026-08-01' },
      { id: 3, status: 'expired', expires_at: '2026-03-01' },
    ], 7)

    expect(summary).toEqual({
      activeCount: 2,
      historyCount: 3,
      lifetimeCount: 1,
      activityCount: 7,
    })
  })

  it('returns a useful access-first empty state and revoke policy', () => {
    expect(getMemberAccessState([])).toEqual({
      hasAccess: false,
      canRevokeAll: false,
      title: 'No active access',
      description: 'Grant a plan to unlock protected content for this member.',
    })

    expect(getMemberAccessState([{ status: 'paused' }])).toMatchObject({
      hasAccess: true,
      canRevokeAll: true,
      title: '1 current grant',
    })
  })

  it('turns machine source values into readable labels', () => {
    expect(normaliseSourceLabel('manual')).toBe('Manual')
    expect(normaliseSourceLabel('order')).toBe('Order')
    expect(normaliseSourceLabel('subscription_renewal')).toBe('Subscription renewal')
    expect(normaliseSourceLabel('')).toBe('Unknown')
  })
})
