import { readFileSync } from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const source = readFileSync(
  path.resolve(process.cwd(), 'resources/admin/components/content/ContentProtectionListCard.vue'),
  'utf8',
)

describe('content protection list responsiveness', () => {
  it('switches from the wide table to record cards based on available component width', () => {
    expect(source).toContain('container-name: protected-content-list;')
    expect(source).toContain('container-type: inline-size;')
    expect(source).toContain('@container protected-content-list (max-width: 1020px)')
    expect(source).toContain('.list-card :deep(.el-table) { display: none; }')
    expect(source).toContain('.mobile-content-list { display: grid;')
  })

  it('keeps the record cards readable when the component becomes narrow', () => {
    expect(source).toContain('grid-template-columns: repeat(2, minmax(0, 1fr));')
    expect(source).toContain('@container protected-content-list (max-width: 640px)')
    expect(source).toContain('grid-template-columns: minmax(0, 1fr);')
  })
})
