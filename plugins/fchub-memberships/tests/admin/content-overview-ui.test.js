import { readFileSync } from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const source = readFileSync(
  path.resolve(process.cwd(), 'resources/admin/pages/Content/ContentOverview.vue'),
  'utf8',
)

describe('content protection overview', () => {
  it('uses the shared operational summary instead of the sparse stats bar', () => {
    expect(source).toContain('<OperationsSummary label="Protection health" :items="summaryItems" />')
    expect(source).not.toContain('class="stats-bar"')
    expect(source).not.toContain('.stats-bar {')
  })

  it('uses the aggregate API summary rather than rebuilding counts from paginated rows', () => {
    expect(source).toContain("stats.totalRules = Number(summary.total_rules) || 0")
    expect(source).toContain("stats.typeCounts = { ...(summary.type_counts ?? {}) }")
    expect(source).not.toContain('updateStats(data)')
  })

  it('keeps the seven quick filters balanced instead of orphaning the final card', () => {
    expect(source).toContain('grid-template-columns: repeat(7, minmax(0, 1fr));')
    expect(source).toContain('@media (max-width: 1100px)')
    expect(source).toContain('grid-template-columns: repeat(4, minmax(0, 1fr));')
    expect(source).toContain('@media (max-width: 640px)')
    expect(source).toContain('grid-template-columns: repeat(2, minmax(0, 1fr));')
  })
})
