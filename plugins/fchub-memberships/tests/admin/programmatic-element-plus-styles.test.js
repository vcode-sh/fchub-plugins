import { readFileSync } from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const entrySource = readFileSync(
  path.resolve(process.cwd(), 'resources/admin/main.js'),
  'utf8',
)

describe('programmatic Element Plus components', () => {
  it('loads the global styles required by teleported messages and confirmation dialogs', () => {
    expect(entrySource).toContain("import 'element-plus/theme-chalk/el-overlay.css'")
    expect(entrySource).toContain("import 'element-plus/es/components/message/style/css'")
    expect(entrySource).toContain("import 'element-plus/es/components/message-box/style/css'")
  })
})
