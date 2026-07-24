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

test('opens a contextual provider configuration link on Integrations', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 1000 })
  await page.goto('/smoke/index.html#/settings?category=integrations&provider=fluentcrm')

  await expect(page.getByRole('heading', { name: 'Settings', exact: true })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Integrations', exact: true })).toBeVisible()
  await expect(page.locator('#integration-fluentcrm')).toHaveClass(/is-focused/)
  await expect(page.locator('#integration-fluent-community')).not.toHaveClass(/is-focused/)
  await expect(page.locator('#integration-fluentcrm')).toBeFocused()
})

test('loads, confirms, and saves active runtime settings without badge payloads', async ({ page }) => {
  await openSettings(page)

  const expiryNotice = page.getByRole('spinbutton', { name: 'Access expiry notice days' })
  const trialNotice = page.getByRole('spinbutton', { name: 'Trial expiry notice days' })
  const hideFromArchives = page.getByRole('switch', { name: 'Hide protected content from archives' })
  await expect(expiryNotice).toHaveValue('7')
  await expect(trialNotice).toHaveValue('3')
  await expect(hideFromArchives).toHaveAttribute('aria-checked', 'false')

  await expiryNotice.fill('9')
  await trialNotice.fill('2')
  await hideFromArchives.locator('..').click()

  await page.getByRole('navigation', { name: 'Settings categories' })
    .getByRole('button', { name: /Advanced/ })
    .click()
  const removeOnUninstall = page.getByRole('switch', { name: 'Remove plugin data on uninstall' })
  await expect(removeOnUninstall).toHaveAttribute('aria-checked', 'false')

  await removeOnUninstall.locator('..').click()
  await page.getByRole('dialog').getByRole('button', { name: 'Keep data' }).click()
  await expect(removeOnUninstall).toHaveAttribute('aria-checked', 'false')

  await removeOnUninstall.locator('..').click()
  await page.getByRole('dialog').getByRole('button', { name: 'Enable data removal' }).click()
  await expect(removeOnUninstall).toHaveAttribute('aria-checked', 'true')

  await page.getByRole('region', { name: 'Unsaved settings' }).getByRole('button', { name: 'Save' }).click()
  const request = await page.evaluate(() => window.__fchubSmokeRequests.find(({ url }) => url.endsWith('/admin/settings')))
  expect(request.body).toEqual(expect.objectContaining({
    expiry_warning_days: 9,
    trial_expiry_notice_days: 2,
    hide_protected_in_archive: 'yes',
    uninstall_remove_data: 'yes',
  }))
  expect(request.body).not.toHaveProperty('fc_badge_mappings')
  expect(request.body).not.toHaveProperty('fc_remove_badge_on_revoke')

  await page.reload()
  await expect(page.getByRole('heading', { name: 'Settings', exact: true })).toBeVisible()
  await expect(page.getByRole('spinbutton', { name: 'Access expiry notice days' })).toHaveValue('9')
  await expect(page.getByRole('spinbutton', { name: 'Trial expiry notice days' })).toHaveValue('2')
  await expect(page.getByRole('switch', { name: 'Hide protected content from archives' }))
    .toHaveAttribute('aria-checked', 'true')

  await page.getByRole('navigation', { name: 'Settings categories' })
    .getByRole('button', { name: /Advanced/ })
    .click()
  await expect(page.getByRole('switch', { name: 'Remove plugin data on uninstall' }))
    .toHaveAttribute('aria-checked', 'true')
})

test('opens the standalone Email Studio with the complete notification catalogue', async ({ page }) => {
  await openSettings(page)

  await page.getByRole('navigation', { name: 'Settings categories' })
    .getByRole('button', { name: /Notifications/ })
    .click()

  const openEmailStudio = page.getByRole('button', { name: 'Open Email Studio' })
  await expect(openEmailStudio).toBeVisible()
  await openEmailStudio.press('Enter')

  await expect(page).toHaveURL(/#\/settings\/email-studio$/)
  await expect(page.getByRole('heading', { name: 'Email Studio', exact: true })).toBeVisible()
  const notifications = [
    ['Access granted', 'Welcome to {plan_name}!'],
    ['Access expiring', 'Your {plan_name} access expires in {days} days'],
    ['Access revoked', 'Your {plan_name} access has ended'],
    ['Membership paused', 'Your {plan_name} membership is paused'],
    ['Membership resumed', 'Your {plan_name} membership is active again'],
    ['Trial expiring', 'Your {plan_name} trial ends in {days} days'],
    ['Trial converted', 'Welcome to your paid {plan_name} membership'],
    ['Drip content unlocked', 'New content is available: {resource_title}'],
  ]

  await expect(page.locator('.notification-card')).toHaveCount(notifications.length)
  for (const [label, subject] of notifications) {
    const card = page.locator('.notification-card').filter({ has: page.getByRole('heading', { name: label, exact: true }) })
    await expect(card).toHaveCount(1)
    await expect(card.locator('.notification-subject-preview')).toHaveText(subject)
  }

  await expect(page.getByRole('combobox', { name: 'Access granted delivery' })).toBeVisible()
  await page.getByRole('button', { name: 'Edit Access granted' }).click()
  await expect(page.getByLabel('Subject')).toHaveValue('Welcome to {plan_name}!')

  await page.getByRole('button', { name: 'Variable' }).first().click()
  const variableMenu = page.locator('.el-dropdown-menu:visible')
  await expect(variableMenu.locator('.el-dropdown-menu__item')).toHaveCount(7)
  for (const [label, code] of [
    ['Member name', '{user_name}'],
    ['Member email', '{user_email}'],
    ['Plan name', '{plan_name}'],
    ['Site name', '{site_name}'],
    ['Account URL', '{account_url}'],
    ['Protected resources', '{resources_list}'],
    ['Drip schedule', '{drip_schedule}'],
  ]) {
    const option = variableMenu.locator('.el-dropdown-menu__item').filter({ hasText: label })
    await expect(option).toContainText(code)
  }
})

test('uses public metadata and loads truthful webhook health and history on demand', async ({ page }) => {
  await openSettings(page)

  const webhooksCategory = page.getByRole('navigation', { name: 'Settings categories' })
    .getByRole('button', { name: /Webhooks & API/ })
  await expect(webhooksCategory).toContainText('API ready')
  expect(await page.evaluate(() => window.__fchubSmokeWebhookHistoryReads)).toBe(0)

  await webhooksCategory.click()
  await expect(page.getByText('Off', { exact: true })).toBeVisible()
  await expect(page.getByText('Configured (never reveal again)', { exact: true })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Latest webhook deliveries' })).toBeVisible()
  await expect(page.getByRole('button', { name: /Retry Access\.revoked delivery/ })).toBeVisible()

  const readsBeforeRetry = await page.evaluate(() => window.__fchubSmokeWebhookHistoryReads)
  await page.getByRole('button', { name: /Retry Access\.revoked delivery/ }).click()
  await expect(page.getByLabel('Delivery status: Pending')).toBeVisible()
  expect(await page.evaluate(() => window.__fchubSmokeWebhookHistoryReads)).toBeGreaterThan(readsBeforeRetry)
})

test('explains webhook destinations and exposes the exact Access API endpoint in context', async ({ page }) => {
  await openSettings(page)

  await page.getByRole('navigation', { name: 'Settings categories' })
    .getByRole('button', { name: /Webhooks & API/ })
    .click()

  await page.locator('[data-open-webhook-guide]').click()
  const webhookGuide = page.getByRole('dialog', { name: 'Connect a webhook receiver' })
  await expect(webhookGuide).toContainText('The webhook URL is the public HTTPS endpoint in your other system.')
  await expect(webhookGuide).toContainText('X-FCHub-Signature')
  await expect(webhookGuide).toContainText('X-FCHub-Delivery')
  await webhookGuide.getByRole('button', { name: 'Close connection guide' }).click()

  await page.locator('[data-open-api-guide]').click()
  const apiGuide = page.getByRole('dialog', { name: 'Connect to the Access API' })
  await expect(apiGuide.locator('[data-api-endpoint]')).toHaveText(
    'https://example.com/wp-json/fchub-memberships/v1/check-access',
  )
  await expect(apiGuide).toContainText('WordPress Application Passwords')
  await expect(apiGuide).toContainText('Idempotency-Key')
  await apiGuide.getByRole('button', { name: 'Copy URL' }).click()
  await expect(apiGuide.getByRole('button', { name: 'Copied' })).toBeVisible()
})

test('requires a saved signed destination before webhooks can be enabled', async ({ page }) => {
  await openSettings(page)

  await page.getByRole('navigation', { name: 'Settings categories' })
    .getByRole('button', { name: /Webhooks & API/ })
    .click()

  const enableWebhooks = page.getByRole('switch', { name: 'Enable webhooks' })
  await expect(page.getByRole('textbox', { name: 'Webhook URLs' })).toHaveValue('https://127.0.0.1/webhook')
  await expect(enableWebhooks).toBeDisabled()
  await page.getByRole('textbox', { name: 'Webhook URLs' }).fill('https://hooks.example.com/memberships')
  await expect(enableWebhooks).toBeDisabled()

  await page.getByRole('region', { name: 'Unsaved settings' }).getByRole('button', { name: 'Save' }).click()
  await expect(enableWebhooks).toBeEnabled()
  await page.getByRole('button', { name: 'Send test' }).click()
  const testResults = page.getByLabel('Webhook test results')
  await expect(testResults).toContainText('hooks.example.com/memberships')
  await expect(testResults).toContainText('Retrying')

  const settingsMutations = await page.evaluate(() => window.__fchubSmokeRequests.filter(({ url }) => url.endsWith('/admin/settings')))
  expect(settingsMutations).toHaveLength(1)
  expect(settingsMutations[0].body.webhook_urls).toBe('https://hooks.example.com/memberships')
  expect(settingsMutations[0].body).not.toHaveProperty('api_key')
  expect(settingsMutations[0].body).not.toHaveProperty('webhook_secret')
})

test('shows generated credentials once and clears them after acknowledgement and reload', async ({ page }) => {
  await openSettings(page)
  await page.getByRole('navigation', { name: 'Settings categories' })
    .getByRole('button', { name: /Webhooks & API/ })
    .click()

  await page.getByRole('button', { name: 'Regenerate key' }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Regenerate' }).click()

  const oneTimeDialog = page.getByRole('dialog', { name: 'Save the new API key' })
  await expect(oneTimeDialog).toContainText('fchub_one_time_smoke_key')
  await expect(oneTimeDialog.getByRole('button', { name: 'Done' })).toBeDisabled()

  await page.getByRole('navigation', { name: 'Settings categories' })
    .getByRole('button', { name: /General/ })
    .click({ force: true })
  await expect(page.getByRole('heading', { name: 'Webhooks & API', exact: true })).toBeVisible()
  await expect(oneTimeDialog).toContainText('fchub_one_time_smoke_key')

  await oneTimeDialog.getByRole('button', { name: /Copy/ }).click()
  await oneTimeDialog.getByRole('checkbox', { name: 'I have copied and safely stored this key.' }).check()
  await oneTimeDialog.getByRole('button', { name: 'Done' }).click()
  await expect(page.getByText('fchub_one_time_smoke_key')).toHaveCount(0)

  await page.reload()
  await expect(page.getByRole('heading', { name: 'Settings', exact: true })).toBeVisible()
  await expect(page.getByText('fchub_one_time_smoke_key')).toHaveCount(0)
  await expect(page.getByText('webhook_one_time_smoke_secret')).toHaveCount(0)
})

test('ignores a late credential response after leaving and returning to Webhooks', async ({ page }) => {
  await openSettings(page)
  const navigation = page.getByRole('navigation', { name: 'Settings categories' })
  await navigation.getByRole('button', { name: /Webhooks & API/ }).click()
  await page.evaluate(() => { window.__fchubSmokeHoldCredentials = true })

  await page.getByRole('button', { name: 'Regenerate key' }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Regenerate' }).click()
  await expect.poll(() => page.evaluate(() => typeof window.__fchubSmokeReleaseCredential)).toBe('function')

  await navigation.getByRole('button', { name: /General/ }).click()
  await navigation.getByRole('button', { name: /Webhooks & API/ }).click()
  await expect(page.getByRole('button', { name: 'Regenerate key' })).toBeEnabled()

  await page.evaluate(() => {
    window.__fchubSmokeHoldCredentials = false
    window.__fchubSmokeReleaseCredential()
  })
  await page.waitForTimeout(50)
  await expect(page.getByText('fchub_one_time_smoke_key')).toHaveCount(0)
  await expect(page.getByText('fchub_abc123')).toBeVisible()
})

test('serializes competing credential mutations and preserves the first one-time value', async ({ page }) => {
  await openSettings(page)
  await page.getByRole('navigation', { name: 'Settings categories' })
    .getByRole('button', { name: /Webhooks & API/ })
    .click()
  await page.evaluate(() => { window.__fchubSmokeHoldCredentials = true })

  await page.getByRole('button', { name: 'Regenerate key' }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Regenerate' }).click()
  await expect.poll(() => page.evaluate(() => typeof window.__fchubSmokeReleaseCredential)).toBe('function')

  const regenerateSecret = page.getByRole('button', { name: 'Regenerate secret' })
  const revokeKey = page.getByRole('button', { name: 'Revoke', exact: true })
  await expect(regenerateSecret).toBeDisabled()
  await expect(revokeKey).toBeDisabled()

  await regenerateSecret.dispatchEvent('click')
  await expect(page.getByRole('dialog', { name: 'Regenerate webhook secret' })).toHaveCount(0)
  await revokeKey.dispatchEvent('click')
  await expect(page.getByRole('dialog', { name: 'Revoke API key' })).toHaveCount(0)

  const competingRequests = await page.evaluate(() => window.__fchubSmokeRequests.filter(({ url }) => (
    url.includes('/admin/settings/regenerate-webhook-secret')
    || url.includes('/admin/settings/revoke-api-key')
  )))
  expect(competingRequests).toHaveLength(0)

  await page.evaluate(() => {
    window.__fchubSmokeHoldCredentials = false
    window.__fchubSmokeReleaseCredential()
  })
  const oneTimeDialog = page.getByRole('dialog', { name: 'Save the new API key' })
  await expect(oneTimeDialog).toContainText('fchub_one_time_smoke_key')
  await expect(page.getByText('webhook_one_time_smoke_secret')).toHaveCount(0)
})

test('keeps a one-time credential mounted when the mobile category selector attempts to leave', async ({ page }) => {
  await openSettings(page, 390, 844)
  const category = page.getByRole('combobox', { name: 'Settings category' })
  await category.press('Enter')
  await page.getByRole('option', { name: 'Webhooks & API' }).click()

  await page.getByRole('button', { name: 'Regenerate key' }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Regenerate' }).click()
  const oneTimeDialog = page.getByRole('dialog', { name: 'Save the new API key' })
  await expect(oneTimeDialog).toContainText('fchub_one_time_smoke_key')

  await category.press('Enter')
  await category.press('ArrowUp')
  await category.press('ArrowUp')
  await category.press('ArrowUp')
  await category.press('Enter')
  await expect(page.getByRole('heading', { name: 'Webhooks & API', exact: true })).toBeVisible()
  await expect(oneTimeDialog).toContainText('fchub_one_time_smoke_key')
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
  await category.press('Enter')
  await page.getByRole('option', { name: 'Webhooks & API' }).click()
  await expect(page.getByRole('heading', { name: 'Latest webhook deliveries' })).toBeVisible()
  await expect(page.getByText('fchub_one_time_smoke_key')).toHaveCount(0)
  await expect(page.getByText('webhook_one_time_smoke_secret')).toHaveCount(0)

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
