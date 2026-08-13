import { test, expect } from '@playwright/test'

async function openRoute(page, route, heading) {
  await page.goto(`/smoke/index.html#${route}`)
  await expect(page.getByRole('heading', { name: heading, exact: true })).toBeVisible()
}

test('turns plans into an operations workspace with one primary action and truthful readiness', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 1000 })
  await openRoute(page, '/plans', 'Plans')

  await expect(page.getByRole('link', { name: 'Create Plan', exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Plan utilities' })).toBeVisible()

  const summary = page.getByRole('region', { name: 'Plan health' })
  await expect(summary).toContainText('Active plans')
  await expect(summary).toContainText('Needs content')
  await expect(summary).toContainText('Scheduled changes')

  await expect(page.getByRole('cell', { name: 'Needs content' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Actions for Gold Plan' })).toBeVisible()
  await expect(page.getByRole('cell', { name: 'Lifetime access' })).toBeVisible()
})

test('gives members explicit profile access, useful health filters, and premium utilities hierarchy', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 1000 })
  await openRoute(page, '/members', 'Members')

  await expect(page.getByRole('button', { name: 'Grant Access', exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Member utilities' })).toBeVisible()

  const summary = page.getByRole('region', { name: 'Access health' })
  await expect(summary).toContainText('Active access')
  await expect(summary).toContainText('Expiring in 7 days')
  await expect(summary).toContainText('Paused access')

  await expect(page.getByRole('link', { name: 'Open Alice Example profile' })).toHaveAttribute('href', '#/members/21')
  await expect(page.getByRole('combobox', { name: 'Expiry window' })).toBeVisible()
  await expect(page.getByText('Each row is one member-plan access assignment.')).toBeVisible()
})

test('reports access that has not started as scheduled rather than active or ended', async ({ page }) => {
  await openRoute(page, '/members', 'Members')

  const summary = page.getByRole('region', { name: 'Access health' })
  await expect(summary).toContainText('Scheduled access')
  await expect(summary).toContainText('Assignments that have not started yet')

  await expect(page.getByRole('link', { name: 'Open Frida Example profile' })).toBeVisible()
  await expect(page.locator('.el-table__body').getByText('scheduled')).toBeVisible()
})

test('filters the list down to scheduled access', async ({ page }) => {
  await openRoute(page, '/members', 'Members')

  await page.locator('.el-select').filter({ has: page.getByRole('combobox', { name: 'Access status' }) }).click()
  await page.getByRole('option', { name: 'Scheduled' }).click()

  await expect(page.getByRole('link', { name: 'Open Frida Example profile' })).toBeVisible()
  await expect(page.getByRole('link', { name: 'Open Alice Example profile' })).toHaveCount(0)
})

test('keeps selection and access management functional on mobile', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await openRoute(page, '/members', 'Members')

  await page.getByRole('checkbox', { name: 'Select Alice Example on Gold Plan' }).check()

  const bulkBar = page.getByRole('region', { name: 'Selected access actions' })
  await expect(bulkBar).toContainText('1 selected')
  await expect(bulkBar.getByRole('button', { name: 'Bulk Actions' })).toBeVisible()

  const geometry = await page.evaluate(() => ({
    viewportWidth: window.innerWidth,
    documentWidth: document.documentElement.scrollWidth,
    bodyWidth: document.body.scrollWidth,
  }))
  expect(geometry.documentWidth).toBeLessThanOrEqual(geometry.viewportWidth)
  expect(geometry.bodyWidth).toBeLessThanOrEqual(geometry.viewportWidth)
})

test('distinguishes filtered no-results from loading failure and offers recovery', async ({ page }) => {
  await page.setViewportSize({ width: 1100, height: 900 })
  await openRoute(page, '/members', 'Members')

  await page.evaluate(() => {
    const baseFetch = window.fetch
    window.fetch = async (input, init) => {
      const url = String(input)
      if (url.includes('/admin/members') && new URL(url).searchParams.get('search') === 'nobody') {
        return { ok: true, status: 200, json: async () => ({
          data: [],
          total: 0,
          summary: { active: 1, expiring_soon: 0, paused: 0, ended: 0 },
        }) }
      }
      return baseFetch(input, init)
    }
  })

  await page.getByRole('textbox', { name: 'Search members' }).fill('nobody')
  await expect(page.getByText('No access matches these filters')).toBeVisible()
  await page.getByRole('status').getByRole('button', { name: 'Clear filters' }).click()
  await expect(page.getByRole('link', { name: 'Open Alice Example profile' })).toBeVisible()

  await page.evaluate(() => {
    const baseFetch = window.fetch
    window.fetch = async (input, init) => {
      if (String(input).includes('/admin/members')) {
        return { ok: false, status: 503, json: async () => ({ message: 'Member data is temporarily unavailable' }) }
      }
      return baseFetch(input, init)
    }
    window.location.hash = '#/plans'
  })
  await expect(page.getByRole('heading', { name: 'Plans', exact: true })).toBeVisible()
  await page.evaluate(() => { window.location.hash = '#/members' })

  const errorState = page.getByRole('alert')
  await expect(errorState).toContainText('Member data is temporarily unavailable')
  await expect(errorState.getByRole('button', { name: 'Try again' })).toBeVisible()
})
