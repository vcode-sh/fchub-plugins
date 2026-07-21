import { test, expect } from '@playwright/test'

async function openMemberProfile(page) {
  await page.goto('/smoke/index.html#/members/21')
  await expect(page.getByRole('heading', { name: 'Alice Example' })).toBeVisible()
}

test('presents the member as an access-first workspace', async ({ page }) => {
  await openMemberProfile(page)

  const summary = page.getByRole('region', { name: 'Membership summary' })
  await expect(summary).toContainText('Active access')
  await expect(summary).toContainText('Grant history')
  await expect(summary).toContainText('Activity')

  const currentAccess = page.getByRole('region', { name: 'Current access' })
  await expect(currentAccess).toContainText('Gold Plan')
  await expect(currentAccess).toContainText('Manual')
  await expect(currentAccess).toContainText('Lifetime')
  await expect(currentAccess.getByRole('button', { name: 'Pause' })).toBeVisible()
  await expect(currentAccess.getByRole('button', { name: 'Extend' })).toBeVisible()
  await expect(currentAccess.getByRole('button', { name: 'Revoke' })).toBeVisible()
})

test('keeps profile actions and grant details inside a compact mobile viewport', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await openMemberProfile(page)

  const geometry = await page.locator('.member-profile-page').evaluate((profile) => {
    const action = profile.querySelector('.profile-primary-action').getBoundingClientRect()
    const history = profile.querySelector('.grant-history-list').getBoundingClientRect()

    return {
      viewportWidth: window.innerWidth,
      documentScrollWidth: document.documentElement.scrollWidth,
      actionLeft: action.left,
      actionRight: action.right,
      historyLeft: history.left,
      historyRight: history.right,
    }
  })

  expect(geometry.documentScrollWidth).toBeLessThanOrEqual(geometry.viewportWidth)
  expect(geometry.actionLeft).toBeGreaterThanOrEqual(0)
  expect(geometry.actionRight).toBeLessThanOrEqual(geometry.viewportWidth)
  expect(geometry.historyLeft).toBeGreaterThanOrEqual(0)
  expect(geometry.historyRight).toBeLessThanOrEqual(geometry.viewportWidth)
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
