import { readFileSync } from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const componentSource = readFileSync(
  path.resolve(process.cwd(), 'resources/admin/components/dashboard/DashboardTrendPanel.vue'),
  'utf8',
)
const styleSource = readFileSync(
  path.resolve(process.cwd(), 'resources/admin/pages/Dashboard.css'),
  'utf8',
)

describe('dashboard member trend layout', () => {
  it('gives the responsive chart its own sizing container above the summary', () => {
    expect(componentSource).toMatch(/<div class="trend-plot">\s*<Line[\s\S]*?<\/div>\s*<p id="member-trend-summary"/)
    expect(styleSource).toContain('.trend-plot {')
    expect(styleSource).toContain('position: relative;')
    expect(styleSource).toContain('min-height: 0;')
    expect(styleSource).toContain('flex: 1;')
  })

  it('keeps the plot and summary in separate vertical flex regions', () => {
    expect(styleSource).toMatch(/\.trend-chart \{[\s\S]*?display: flex;[\s\S]*?flex-direction: column;/)
    expect(styleSource).not.toContain('grid-template-rows: minmax(0, 1fr) auto;')
  })
})
