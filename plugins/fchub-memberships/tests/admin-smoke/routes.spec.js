import { test, expect } from '@playwright/test'

const routes = [
  ['/', 'Dashboard'],
  ['/plans', 'Plans'],
  ['/plans/new', 'Create Plan'],
  ['/plans/5/edit', 'Edit Plan'],
  ['/members', 'Alice Example'],
  ['/members/21', 'Alice Example'],
  ['/import', 'Import Members'],
  ['/content', 'Content Protection'],
  ['/drip', 'Drip Content'],
  ['/drip/calendar', 'Drip Calendar'],
  ['/reports', 'Reports'],
  ['/settings', 'Settings'],
]

for (const [path, text] of routes) {
  test(`renders ${path}`, async ({ page }) => {
    page.on('pageerror', (error) => {
      console.error(`pageerror ${path}: ${error.message}`)
    })

    await page.goto(`/smoke/index.html#${path}`)
    await expect(page.locator('body')).toContainText(text)
  })
}
