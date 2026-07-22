import { readFileSync } from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const appSource = readFileSync(
  path.resolve(process.cwd(), 'resources/admin/App.vue'),
  'utf8',
)

const globalStyles = readFileSync(
  path.resolve(process.cwd(), 'resources/admin/styles/global.css'),
  'utf8',
)

describe('global notification positioning', () => {
  it('publishes the visible WordPress toolbar offset for teleported components', () => {
    expect(appSource).toContain("document.documentElement.style.setProperty('--fchub-admin-bar-offset'")
    expect(appSource).toContain('syncGlobalAdminBarOffset(adminBarOffset.value)')
  })

  it('clears both admin toolbars without replacing Element Plus stacking positions', () => {
    const messageRule = globalStyles.match(/\.el-message\s*\{([^}]*)\}/s)?.[1] ?? ''

    expect(messageRule).toMatch(
      /margin-top:\s*calc\(\s*var\(--fchub-admin-bar-offset,\s*0px\)\s*\+\s*var\(--fchub-toast-nav-height,\s*64px\)\s*\)/,
    )
    expect(globalStyles).toMatch(/@media \(max-width:\s*782px\)[\s\S]*--fchub-toast-nav-height:\s*54px/)
    expect(messageRule).not.toMatch(/(?:^|\n)\s*top:/)
  })
})
