import { readFileSync } from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const dashboardSource = readFileSync(
  path.resolve(process.cwd(), 'resources/admin/pages/Dashboard.vue'),
  'utf8',
)
const integrationsSource = readFileSync(
  path.resolve(process.cwd(), 'resources/admin/pages/Integrations.vue'),
  'utf8',
)

describe('operator provider health integration', () => {
  it('keeps the dashboard summary compact and renders full provider health on Integrations', () => {
    expect(dashboardSource).toContain("import ProviderHealthCards from '@/components/dashboard/ProviderHealthCards.vue'")
    expect(dashboardSource).toContain('<ProviderHealthCards compact />')
    expect(integrationsSource).toContain("import ProviderHealthCards from '@/components/dashboard/ProviderHealthCards.vue'")
    expect(integrationsSource).toContain('<ProviderHealthCards />')
    expect(integrationsSource).toContain("import ProviderIssuePanel from '@/components/integrations/ProviderIssuePanel.vue'")
    expect(integrationsSource).toContain('<ProviderIssuePanel')
    expect(integrationsSource).toContain('to="/settings?category=integrations"')
  })
})
