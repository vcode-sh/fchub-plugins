import { readFileSync } from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const source = readFileSync(
  path.resolve(process.cwd(), 'resources/admin/pages/Dashboard.vue'),
  'utf8',
)

describe('dashboard member trend layout', () => {
  it('gives the responsive chart its own sizing container above the summary', () => {
    expect(source).toMatch(/<div class="trend-plot">\s*<Line[\s\S]*?<\/div>\s*<p id="member-trend-summary"/)
    expect(source).toContain('.trend-plot {')
    expect(source).toContain('position: relative;')
    expect(source).toContain('min-height: 0;')
    expect(source).toContain('flex: 1;')
  })

  it('keeps the plot and summary in separate vertical flex regions', () => {
    expect(source).toMatch(/\.trend-chart \{[\s\S]*?display: flex;[\s\S]*?flex-direction: column;/)
    expect(source).not.toContain('grid-template-rows: minmax(0, 1fr) auto;')
  })
})
