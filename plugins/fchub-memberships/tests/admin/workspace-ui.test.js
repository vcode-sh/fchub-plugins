import { describe, expect, it } from 'vitest'
import { getWorkspaceSection, WORKSPACE_NAV_ITEMS } from '@/workspace/workspaceUi.js'

describe('workspace navigation policy', () => {
  it('keeps every primary plugin destination available to compact navigation', () => {
    expect(WORKSPACE_NAV_ITEMS.map(({ to }) => to)).toEqual([
      '/',
      '/plans',
      '/members',
      '/content',
      '/drip',
      '/reports',
      '/settings',
    ])
  })

  it.each([
    ['/', 'Dashboard'],
    ['/plans', 'Plans'],
    ['/plans/5/edit', 'Plans'],
    ['/members/21', 'Members'],
    ['/import', 'Members'],
    ['/content', 'Content'],
    ['/drip/calendar', 'Drip'],
    ['/reports', 'Reports'],
    ['/settings', 'Settings'],
    ['/unknown', 'Dashboard'],
  ])('maps %s to the %s workspace', (path, label) => {
    expect(getWorkspaceSection(path).label).toBe(label)
  })
})
