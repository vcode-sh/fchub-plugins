import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import path from 'node:path'
import postcss from 'postcss'
import {
  actionLabel,
  actorLabel,
  entityLabel,
  isDashboardResponse,
  safeDestination,
  safeSeverity,
  toIsoDateTime,
  withOpacity,
} from '@/pages/dashboardUi.js'

function dashboardPayload(overrides = {}) {
  return {
    summary: {
      active_members: 12,
      new_members_30d: 4,
      grants_30d: 6,
      expiring_7d: 2,
      failed_notifications: 1,
    },
    readiness: {
      active_plans: 2,
      protected_items: 8,
      has_active_plan: true,
      has_protected_content: true,
      has_active_members: true,
    },
    attention: [],
    trend: [],
    plan_distribution: [],
    activity: [],
    ...overrides,
  }
}

describe('dashboard UI policy', () => {
  it('scopes every dashboard style selector to the dashboard page', () => {
    const css = readFileSync(path.resolve(process.cwd(), 'resources/admin/pages/Dashboard.css'), 'utf8')
    const root = postcss.parse(css)
    const unscoped = []

    root.walkRules((rule) => {
      if (rule.parent?.type === 'atrule' && rule.parent.name.includes('keyframes')) return
      for (const selector of rule.selectors) {
        if (!selector.trim().startsWith('.dashboard-page')) unscoped.push(selector.trim())
      }
    })

    expect(unscoped).toEqual([])
  })

  it('accepts only complete wrapped dashboard responses', () => {
    expect(isDashboardResponse({ data: dashboardPayload() })).toBe(true)
    expect(isDashboardResponse(dashboardPayload())).toBe(false)
    expect(isDashboardResponse({ data: null })).toBe(false)
    expect(isDashboardResponse({ data: { summary: dashboardPayload().summary } })).toBe(false)
  })

  it('rejects malformed nested records', () => {
    expect(isDashboardResponse({
      data: dashboardPayload({ trend: [{ date: '2026-07-20', count: -1 }] }),
    })).toBe(false)
    expect(isDashboardResponse({
      data: dashboardPayload({ attention: [{ destination: '/members' }] }),
    })).toBe(false)
  })

  it('normalises untrusted severity and destinations', () => {
    expect(safeSeverity('error')).toBe('critical')
    expect(safeSeverity('unknown')).toBe('info')
    expect(safeDestination('/members')).toBe('/members')
    expect(safeDestination('https://example.com')).toBe('/')
  })

  it('formats activity labels without additional API data', () => {
    const entry = {
      action: 'plan_created',
      entity_type: 'record',
      entity_id: 3,
      actor_type: 'wp_cli',
      actor_id: 7,
    }
    expect(actionLabel(entry)).toBe('Plan created')
    expect(entityLabel({ entity_type: 'grant', entity_id: 42 })).toBe('Access #42')
    expect(actorLabel(entry)).toBe('Wp cli #7')
  })

  it('normalises WordPress timestamps and theme colours', () => {
    expect(toIsoDateTime('2026-07-20 13:45:00')).toBe('2026-07-20T13:45:00')
    expect(toIsoDateTime('not-a-date')).toBe('')
    expect(withOpacity('#6d8cff', 0.1)).toBe('rgba(109, 140, 255, 0.1)')
    expect(withOpacity('rgb(10, 20, 30)', 0.14)).toBe('rgba(10, 20, 30, 0.14)')
  })
})
