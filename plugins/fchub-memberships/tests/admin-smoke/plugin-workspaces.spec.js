import { test, expect } from '@playwright/test'

const routes = [
  ['/', 'Dashboard'],
  ['/plans', 'Plans'],
  ['/plans/new', 'Create membership plan'],
  ['/members', 'Members'],
  ['/members/21', 'Alice Example'],
  ['/import', 'Import Members'],
  ['/content', 'Content Protection'],
  ['/drip', 'Drip Content'],
  ['/drip/calendar', 'Drip Calendar'],
  ['/reports', 'Reports'],
  ['/settings', 'Settings'],
]

async function openRoute(page, route, text) {
  await page.goto(`/smoke/index.html#${route}`)
  await expect(page.locator('body')).toContainText(text)
}

test('keeps every primary destination reachable from compact navigation', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await openRoute(page, '/', 'Dashboard')

  await page.getByRole('button', { name: 'Navigate sections' }).click()
  await page.getByRole('menuitem', { name: 'Plans', exact: true }).click()

  await expect(page).toHaveURL(/#\/plans$/)
  await expect(page.getByRole('heading', { name: 'Plans', exact: true })).toBeVisible()
})

for (const [route, text] of routes) {
  test(`${route} stays inside a 390px viewport`, async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 })
    await openRoute(page, route, text)

    const geometry = await page.evaluate(() => ({
      viewportWidth: window.innerWidth,
      documentWidth: document.documentElement.scrollWidth,
      bodyWidth: document.body.scrollWidth,
    }))

    expect(geometry.documentWidth).toBeLessThanOrEqual(geometry.viewportWidth)
    expect(geometry.bodyWidth).toBeLessThanOrEqual(geometry.viewportWidth)
  })
}

test('uses purpose-built mobile cards for operational lists', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })

  await openRoute(page, '/plans', 'Plans')
  await expect(page.locator('.mobile-plan-list')).toContainText('Gold Plan')
  await expect(page.locator('.mobile-plan-list').getByRole('button', { name: 'Plan actions' })).toBeVisible()

  await openRoute(page, '/members', 'Members')
  await expect(page.locator('.mobile-member-list')).toContainText('Alice Example')
  await expect(page.locator('.mobile-member-list').getByRole('link', { name: 'Manage profile' })).toBeVisible()

  await openRoute(page, '/drip', 'Drip Content')
  await expect(page.locator('.mobile-drip-queue')).toContainText('Locked Lesson')
})

test('turns settings into focused groups with a reachable save state', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await openRoute(page, '/settings', 'Settings')

  await expect(page.getByRole('tab', { name: 'General' })).toBeVisible()
  await expect(page.getByRole('tab', { name: 'Notifications' })).toBeVisible()
  await page.getByRole('tab', { name: 'Advanced' }).click()
  await expect(page.getByText('Enable verbose logging for troubleshooting.')).toBeVisible()
  const saveState = page.getByRole('region', { name: 'Settings save status' })
  await expect(saveState).toBeVisible()
  await expect(saveState).toContainText('All changes saved')

  await page.locator('.settings-tabs .el-switch').last().click()
  await expect(saveState).toContainText('Unsaved changes')
})

test('uses compact, equal import progress controls on mobile', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await openRoute(page, '/import', 'Import Members')

  const steps = page.locator('.import-progress-step')
  await expect(steps).toHaveCount(5)
  const widths = await steps.evaluateAll((items) => items.map((item) => item.getBoundingClientRect().width))
  expect(Math.max(...widths) - Math.min(...widths)).toBeLessThanOrEqual(1)
})
