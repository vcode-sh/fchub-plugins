import { readFileSync } from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const dashboardSource = readFileSync(
  path.resolve(process.cwd(), 'resources/admin/pages/Dashboard.vue'),
  'utf8',
)

describe('operator provider health integration', () => {
  it('renders the read-only provider health workspace on the existing dashboard', () => {
    expect(dashboardSource).toContain("import ProviderHealthCards from '@/components/dashboard/ProviderHealthCards.vue'")
    expect(dashboardSource).toContain('<ProviderHealthCards />')
  })
})
