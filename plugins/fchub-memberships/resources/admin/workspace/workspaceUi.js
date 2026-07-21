export const WORKSPACE_NAV_ITEMS = [
  { to: '/', label: 'Dashboard' },
  { to: '/plans', label: 'Plans' },
  { to: '/members', label: 'Members' },
  { to: '/content', label: 'Content' },
  { to: '/drip', label: 'Drip' },
  { to: '/reports', label: 'Reports' },
  { to: '/settings', label: 'Settings' },
]

export function getWorkspaceSection(path = '/') {
  if (path === '/') return WORKSPACE_NAV_ITEMS[0]
  if (path === '/import') return WORKSPACE_NAV_ITEMS[2]

  return WORKSPACE_NAV_ITEMS.find((item) => item.to !== '/' && path.startsWith(item.to))
    ?? WORKSPACE_NAV_ITEMS[0]
}
