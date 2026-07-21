import { test, expect } from '@playwright/test'

test('mobile navigation follows the visible WordPress toolbar edge', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/smoke/index.html#/settings')
  await expect(page.getByRole('heading', { name: 'Settings', exact: true })).toBeVisible()

  await page.evaluate(() => {
    const adminBar = document.createElement('div')
    adminBar.id = 'wpadminbar'
    adminBar.style.cssText = 'position:absolute;inset:0 0 auto;height:46px;'
    document.body.prepend(adminBar)
    window.dispatchEvent(new Event('resize'))
  })

  await expect.poll(() => page.locator('.fchub-top-nav').evaluate((nav) => nav.getBoundingClientRect().top)).toBe(46)

  await page.evaluate(() => window.scrollTo(0, 20))
  await expect.poll(() => page.locator('.fchub-top-nav').evaluate((nav) => nav.getBoundingClientRect().top)).toBe(26)

  await page.evaluate(() => window.scrollTo(0, 80))
  await expect.poll(() => page.locator('.fchub-top-nav').evaluate((nav) => nav.getBoundingClientRect().top)).toBe(0)
  const scrolledGeometry = await page.evaluate(() => {
    const adminBar = document.querySelector('#wpadminbar').getBoundingClientRect()
    const navigation = document.querySelector('.fchub-top-nav').getBoundingClientRect()

    return {
      adminBarBottom: adminBar.bottom,
      navigationTop: navigation.top,
      navigationLeft: navigation.left,
      navigationRight: navigation.right,
      viewportWidth: window.innerWidth,
    }
  })

  expect(scrolledGeometry.adminBarBottom).toBeLessThanOrEqual(0)
  expect(scrolledGeometry.navigationTop).toBe(0)
  expect(scrolledGeometry.navigationLeft).toBeGreaterThanOrEqual(0)
  expect(scrolledGeometry.navigationRight).toBeLessThanOrEqual(scrolledGeometry.viewportWidth)
})
