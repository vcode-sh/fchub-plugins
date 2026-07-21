import { test, expect } from '@playwright/test'

async function openSettings(page, width = 1440, height = 1000) {
  await page.setViewportSize({ width, height })
  await page.goto('/smoke/index.html#/settings')
  await expect(page.getByRole('heading', { name: 'Settings', exact: true })).toBeVisible()
}

test('presents settings as an operational console instead of a wide tab form', async ({ page }) => {
  await openSettings(page)

  const overview = page.getByRole('region', { name: 'Settings overview' })
  await expect(overview).toContainText('Content protection')
  await expect(overview).toContainText('Email notifications')
  await expect(overview).toContainText('Connected services')

  const navigation = page.getByRole('navigation', { name: 'Settings categories' })
  await expect(navigation.getByRole('button', { name: /General/ })).toHaveAttribute('aria-current', 'page')
  await navigation.getByRole('button', { name: /Integrations/ }).click()
  await expect(page.getByRole('heading', { name: 'Integrations', exact: true })).toBeVisible()
  await expect(page.getByText('Connect the tools that should follow membership changes.')).toBeVisible()

  await expect(page.getByRole('region', { name: 'Unsaved settings' })).toHaveCount(0)
  await expect(page.getByRole('button', { name: /Save/ })).toHaveCount(0)
})

test('offers labelled controls plus contextual save and discard actions', async ({ page }) => {
  await openSettings(page)

  await page.getByRole('navigation', { name: 'Settings categories' })
    .getByRole('button', { name: /Notifications/ })
    .click()

  const grantedEmail = page.getByRole('switch', { name: 'Email on access granted' })
  await expect(grantedEmail).toBeChecked()
  await grantedEmail.locator('..').click()

  const saveBar = page.getByRole('region', { name: 'Unsaved settings' })
  await expect(saveBar).toContainText('Unsaved changes')
  await expect(saveBar.getByRole('button', { name: 'Save' })).toBeVisible()
  await expect(saveBar.getByRole('button', { name: 'Discard' })).toBeVisible()

  await saveBar.getByRole('button', { name: 'Discard' }).click()
  await expect(grantedEmail).toBeChecked()
  await expect(saveBar).toHaveCount(0)
})

test('validates webhook URLs before a settings mutation is sent', async ({ page }) => {
  await openSettings(page)

  await page.getByRole('navigation', { name: 'Settings categories' })
    .getByRole('button', { name: /Webhooks & API/ })
    .click()
  await page.getByRole('switch', { name: 'Enable webhooks' }).locator('..').click()
  await page.getByRole('textbox', { name: 'Webhook URLs' }).fill('not-a-url')
  await page.getByRole('region', { name: 'Unsaved settings' }).getByRole('button', { name: 'Save' }).click()

  await expect(page.getByRole('alert')).toContainText('Enter a valid HTTP or HTTPS URL on each line.')
  const settingsMutations = await page.evaluate(() => window.__fchubSmokeRequests.filter(({ url }) => url.includes('/admin/settings')))
  expect(settingsMutations).toHaveLength(0)
})

test('keeps failed loads out of the editable form and provides recovery', async ({ page }) => {
  await openSettings(page)

  await page.evaluate(() => {
    const baseFetch = window.fetch
    window.fetch = async (input, init) => {
      if (String(input).includes('/admin/settings')) {
        return { ok: false, status: 503, json: async () => ({ message: 'Settings service is temporarily unavailable' }) }
      }
      return baseFetch(input, init)
    }
    window.location.hash = '#/plans'
  })
  await expect(page.getByRole('heading', { name: 'Plans', exact: true })).toBeVisible()
  await page.evaluate(() => { window.location.hash = '#/settings' })

  const error = page.getByRole('alert')
  await expect(error).toContainText('Settings service is temporarily unavailable')
  await expect(error.getByRole('button', { name: 'Try again' })).toBeVisible()
  await expect(page.getByRole('navigation', { name: 'Settings categories' })).toHaveCount(0)
})

test('uses a compact category selector and stays within a 390px viewport', async ({ page }) => {
  await openSettings(page, 390, 844)

  const category = page.getByRole('combobox', { name: 'Settings category' })
  await expect(category).toBeVisible()
  await expect(page.getByRole('navigation', { name: 'Settings categories' })).toBeHidden()

  const geometry = await page.evaluate(() => ({
    viewportWidth: window.innerWidth,
    documentWidth: document.documentElement.scrollWidth,
    bodyWidth: document.body.scrollWidth,
  }))
  expect(geometry.documentWidth).toBeLessThanOrEqual(geometry.viewportWidth)
  expect(geometry.bodyWidth).toBeLessThanOrEqual(geometry.viewportWidth)
})

test('keeps overview accents on the inherited dark palette', async ({ page }) => {
  await openSettings(page)
  await page.evaluate(() => document.body.classList.add('dark'))

  const iconBackground = await page.locator('.settings-overview-card--blue > .el-icon').evaluate((element) => getComputedStyle(element).backgroundColor)
  expect(iconBackground).not.toBe('rgb(239, 246, 255)')
})

test('keeps notification switches aligned and nests reminder timing inside its preference', async ({ page }) => {
  await openSettings(page)

  await page.getByRole('navigation', { name: 'Settings categories' })
    .getByRole('button', { name: /Notifications/ })
    .click()

  const notifications = page.getByRole('region', { name: 'Notifications' })
  await expect(notifications.locator('.notification-preference')).toHaveCount(4)

  const switchPositions = await notifications.getByRole('switch').evaluateAll((switches) => (
    switches.map((control) => control.closest('.el-switch').getBoundingClientRect().right)
  ))
  expect(Math.max(...switchPositions) - Math.min(...switchPositions)).toBeLessThanOrEqual(1)

  const expiryPreference = page.getByRole('group', { name: 'Access expiring' })
  await expect(expiryPreference.getByText('Reminder timing')).toBeVisible()
  await expect(expiryPreference.getByRole('spinbutton', { name: 'Days before access expires' })).toHaveValue('7')
  await expect(expiryPreference).toContainText('Members receive this email 7 days before access expires.')

  const desktopTimingAlignment = await expiryPreference.locator('.notification-timing').evaluate((element) => {
    const innerRight = element.getBoundingClientRect().right - Number.parseFloat(getComputedStyle(element).paddingRight)
    const unit = element.querySelector('.notification-timing-field > span')
    const preview = element.querySelector('.notification-timing-preview')
    const textRight = (node) => {
      const range = document.createRange()
      range.selectNodeContents(node)
      return range.getBoundingClientRect().right
    }

    return {
      previewGap: innerRight - textRight(preview),
      unitGap: innerRight - textRight(unit),
    }
  })

  expect(Math.abs(desktopTimingAlignment.unitGap)).toBeLessThanOrEqual(2)
  expect(Math.abs(desktopTimingAlignment.previewGap)).toBeLessThanOrEqual(2)

  await expiryPreference.getByRole('switch', { name: 'Email on access expiring' }).locator('..').click()
  await expect(expiryPreference.getByText('Reminder timing')).toHaveCount(0)
})

test('gives reminder timing a readable full-width mobile control', async ({ page }) => {
  await openSettings(page, 390, 844)

  await page.locator('.settings-mobile-category .el-select__wrapper').click()
  await page.getByRole('option', { name: 'Notifications' }).click()

  const expiryPreference = page.getByRole('group', { name: 'Access expiring' })
  const timing = expiryPreference.locator('.notification-timing')
  await expect(timing).toBeVisible()

  const geometry = await timing.evaluate((element) => {
    const field = element.querySelector('.notification-timing-field')
    const input = element.querySelector('.el-input-number')
    const rect = element.getBoundingClientRect()
    const fieldRect = field.getBoundingClientRect()
    const inputRect = input.getBoundingClientRect()
    return {
      fieldWidth: fieldRect.width,
      inputWidth: inputRect.width,
      right: rect.right,
      viewport: window.innerWidth,
    }
  })

  expect(geometry.inputWidth).toBeGreaterThanOrEqual(geometry.fieldWidth - 2)
  expect(geometry.right).toBeLessThanOrEqual(geometry.viewport)
})
