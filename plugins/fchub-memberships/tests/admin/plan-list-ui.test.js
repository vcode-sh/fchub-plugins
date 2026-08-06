import { describe, expect, it } from 'vitest'
import {
  durationLabel,
  normalisePlanListResponse,
  readinessLabel,
  readinessState,
  statusTagType,
} from '@/pages/Plans/planListUi.js'

describe('plan list UI policy', () => {
  it('maps plan status and duration without relying on component state', () => {
    expect(statusTagType('active')).toBe('success')
    expect(statusTagType('unknown')).toBe('info')
    expect(durationLabel({ duration_type: 'fixed_days', duration_days: 30 })).toBe('30 days access')
    expect(durationLabel({ duration_type: 'subscription_mirror' })).toBe('Subscription access')
    expect(durationLabel({ duration_type: 'fixed_anchor' })).toBe('Calendar anchored')
    expect(durationLabel({ duration_type: 'lifetime' })).toBe('Lifetime access')
  })

  it('prioritises archived and scheduled readiness before content checks', () => {
    expect(readinessState({ status: 'archived' })).toBe('archived')
    expect(readinessState({ status: 'active', scheduled_status: 'inactive', scheduled_at: '2026-08-08' })).toBe('scheduled')
    expect(readinessState({ status: 'active', rules_count: 0 })).toBe('attention')
    expect(readinessState({ status: 'active', rules_count: 2 })).toBe('ready')
    expect(readinessLabel({ status: 'inactive' })).toBe('Not published')
  })

  it('normalises absent list response fields honestly', () => {
    expect(normalisePlanListResponse({})).toEqual({
      rows: [],
      total: 0,
      summary: { total: 0, active: 0, needs_content: 0, scheduled: 0 },
    })
    expect(normalisePlanListResponse({
      data: [{ id: 5 }],
      total: 1,
      summary: { total: 4, active: 2, needs_content: 1, scheduled: 1 },
    })).toEqual({
      rows: [{ id: 5 }],
      total: 1,
      summary: { total: 4, active: 2, needs_content: 1, scheduled: 1 },
    })
  })
})
