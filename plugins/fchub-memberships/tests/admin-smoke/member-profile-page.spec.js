import { test, expect } from '@playwright/test'

async function openMemberProfile(page) {
  await page.goto('/smoke/index.html#/members/21')
  await expect(page.getByRole('heading', { name: 'Alice Example' })).toBeVisible()
}

test('answers whether the member has access before anything else', async ({ page }) => {
  await openMemberProfile(page)

  const profile = page.locator('.member-profile-page')
  await expect(profile.locator('.profile-verdict')).toContainText('Active in Gold Plan')
  await expect(profile.locator('.profile-verdict')).toContainText('Lifetime')
})

test('shows one card per membership, not one per protected resource', async ({ page }) => {
  await openMemberProfile(page)

  const memberships = page.getByRole('region', { name: 'Memberships' })
  await expect(memberships.locator('.membership-card')).toHaveCount(1)
  await expect(memberships).toContainText('Gold Plan')
  await expect(memberships).toContainText('Manual grant')
  await expect(memberships).toContainText('2 resources')
  await expect(memberships.getByRole('button', { name: 'Pause' })).toBeVisible()
  await expect(memberships.getByRole('button', { name: 'Extend' })).toBeVisible()
  await expect(memberships.getByRole('button', { name: 'Revoke' })).toBeVisible()
})

test('discloses the underlying resources only when asked', async ({ page }) => {
  await openMemberProfile(page)

  const memberships = page.getByRole('region', { name: 'Memberships' })
  await expect(memberships.locator('.membership-resources')).toHaveCount(0)

  await memberships.getByRole('button', { name: 'Show detail' }).click()

  await expect(memberships.locator('.membership-resources li')).toHaveCount(2)
  await expect(memberships.getByRole('button', { name: 'Check providers' })).toBeVisible()
})

test('reads provider state only when the administrator asks for it', async ({ page }) => {
  const providerCalls = []
  await page.route('**/provider-state**', async (route) => {
    providerCalls.push(route.request().url())
    await route.continue()
  })

  await openMemberProfile(page)
  expect(providerCalls).toHaveLength(0)

  const memberships = page.getByRole('region', { name: 'Memberships' })
  await memberships.getByRole('button', { name: 'Show detail' }).click()
  await memberships.getByRole('button', { name: 'Check providers' }).click()

  await expect(memberships.locator('.resource-provider-state').first()).toContainText('local')
})

test('merges grant history and activity into one described timeline', async ({ page }) => {
  await openMemberProfile(page)

  const history = page.getByRole('region', { name: 'Membership history' })
  await expect(history.locator('.timeline-feed li')).toHaveCount(2)
  await expect(history).toContainText('Gold Plan extended to 31 December 2026 by tomrobak')
  await expect(history).toContainText('Gold Plan granted by tomrobak')
})

test('answers a protected-content access question with the evaluator reason', async ({ page }) => {
  await openMemberProfile(page)

  const check = page.getByRole('region', { name: 'Access check' })
  await check.getByRole('combobox').fill('members')
  await page.getByRole('option', { name: 'Members Only Lesson' }).click()
  await check.getByRole('button', { name: 'Check access' }).click()

  await expect(check.locator('.check-result')).toContainText('drip has not released it')
  await expect(check).toContainText('URL patterns and menu protection')
})

test('keeps profile actions and membership detail inside a compact mobile viewport', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await openMemberProfile(page)
  await page.getByRole('button', { name: 'Show detail' }).click()

  const geometry = await page.locator('.member-profile-page').evaluate((profile) => {
    const action = profile.querySelector('.profile-primary-action').getBoundingClientRect()
    const card = profile.querySelector('.membership-card').getBoundingClientRect()

    return {
      viewportWidth: window.innerWidth,
      documentScrollWidth: document.documentElement.scrollWidth,
      actionLeft: action.left,
      actionRight: action.right,
      cardLeft: card.left,
      cardRight: card.right,
    }
  })

  expect(geometry.documentScrollWidth).toBeLessThanOrEqual(geometry.viewportWidth)
  expect(geometry.actionLeft).toBeGreaterThanOrEqual(0)
  expect(geometry.actionRight).toBeLessThanOrEqual(geometry.viewportWidth)
  expect(geometry.cardLeft).toBeGreaterThanOrEqual(0)
  expect(geometry.cardRight).toBeLessThanOrEqual(geometry.viewportWidth)
})

test('opens a member-scoped grant dialog without asking for the user again', async ({ page }) => {
  await openMemberProfile(page)
  await page.getByRole('button', { name: 'Grant access', exact: true }).first().click()

  const dialog = page.getByRole('dialog', { name: 'Grant access' })
  await expect(dialog).toContainText('Create a manual grant for Alice Example')
  await expect(dialog.getByText('alice@example.com')).toBeVisible()
  await expect(dialog.getByPlaceholder('Search WordPress users...')).toHaveCount(0)
  await expect(dialog.getByRole('button', { name: 'Grant access', exact: true })).toBeDisabled()
})

test('sends an expiry the REST validator accepts when extending', async ({ page }) => {
  await openMemberProfile(page)

  await page.getByRole('button', { name: 'Extend' }).click()
  const dialog = page.getByRole('dialog', { name: 'Extend membership' })
  await dialog.getByRole('button', { name: '+1 year' }).click()
  await dialog.getByRole('button', { name: 'Extend', exact: true }).click()

  const sent = await page.evaluate(() =>
    window.__fchubSmokeRequests.filter((entry) => entry.url.includes('/admin/members/extend')),
  )

  expect(sent).toHaveLength(1)
  // MembershipRestArguments::isoMysqlDate() accepts this shape and nothing else.
  expect(sent[0].body.expires_at).toMatch(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/)
  expect(sent[0].body).toMatchObject({ user_id: 21, plan_id: 5 })
})

test('sends a grant expiry in the shape the API accepts', async ({ page }) => {
  await openMemberProfile(page)
  await page.getByRole('button', { name: 'Grant access', exact: true }).first().click()

  const dialog = page.getByRole('dialog', { name: 'Grant access' })
  await dialog.locator('.el-select').first().click()
  await page.getByRole('option', { name: 'Gold Plan' }).click()
  await dialog.getByPlaceholder('Use plan default').fill('2027-01-01')
  await page.keyboard.press('Enter')
  await dialog.getByRole('button', { name: 'Grant access', exact: true }).click()

  const sent = await page.evaluate(() =>
    window.__fchubSmokeRequests.filter((entry) => entry.url.includes('/admin/members/grant')),
  )

  expect(sent).toHaveLength(1)
  expect(sent[0].body.expires_at).toMatch(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/)
})

test('offers extension presets measured from the current expiry', async ({ page }) => {
  await openMemberProfile(page)
  await page.getByRole('button', { name: 'Extend' }).click()

  const dialog = page.getByRole('dialog', { name: 'Extend membership' })
  await expect(dialog.getByRole('button', { name: '+1 month' })).toBeVisible()
  await expect(dialog.getByRole('button', { name: '+1 year' })).toBeVisible()
  await expect(dialog.getByRole('button', { name: 'Extend', exact: true })).toBeDisabled()

  await dialog.getByRole('button', { name: '+1 year' }).click()
  await expect(dialog.getByRole('button', { name: 'Extend', exact: true })).toBeEnabled()
})
