import { test, expect } from '@playwright/test'

async function openWizard(page) {
  await page.goto('/smoke/index.html#/content')
  await page.getByRole('button', { name: 'Protect Content' }).click()
  return page.getByRole('dialog', { name: 'Content Protection' })
}

async function choosePost(dialog) {
  const category = dialog.getByRole('button', { name: /Posts & Pages/ })
  await category.click()
  await dialog.locator('.cpw-inline-types .el-radio').filter({ hasText: 'Posts' }).click()
  await expect(category).toHaveAttribute('aria-pressed', 'true')
  await expect(dialog.getByText('Posts & Pages · Posts')).toBeVisible()
}

async function chooseMembersPost(page, dialog) {
  await dialog.getByRole('button', { name: 'Continue' }).click()
  await expect(dialog.getByRole('heading', { name: 'Choose a specific resource' })).toBeVisible()
  await dialog.getByRole('combobox', { name: /Resource/ }).fill('Members')
  await page.getByRole('option', { name: 'Members Post' }).click()
}

async function chooseGoldPlan(page, dialog) {
  await dialog.getByRole('button', { name: 'Continue' }).click()
  await expect(dialog.getByRole('heading', { name: 'Decide who gets access' })).toBeVisible()
  await dialog.locator('.el-form-item').filter({ hasText: 'Membership plans' }).locator('.el-select').click()
  await page.getByRole('option', { name: 'Gold Plan' }).click()
}

async function progressCardGeometry(dialog) {
  return dialog.locator('.cpw-progress-card').evaluateAll((cards) => cards.map((card) => {
    const rect = card.getBoundingClientRect()
    return { width: rect.width, height: rect.height, left: rect.left, right: rect.right }
  }))
}

test('completes the guided protection journey and sends the exact payload once', async ({ page }) => {
  const dialog = await openWizard(page)

  await expect(dialog.getByRole('navigation', { name: 'Protection setup progress' })).toBeVisible()
  await expect(dialog.getByRole('button', { name: /Posts & Pages/ })).toHaveAttribute('aria-pressed', 'false')
  await expect(dialog.getByRole('button', { name: 'Continue' })).toBeDisabled()

  const desktopCards = await progressCardGeometry(dialog)
  expect(new Set(desktopCards.map(({ width }) => Math.round(width))).size).toBe(1)
  expect(new Set(desktopCards.map(({ height }) => Math.round(height))).size).toBe(1)
  expect(desktopCards[0].height).toBeLessThanOrEqual(70)

  await choosePost(dialog)
  await expect(dialog.getByRole('button', { name: 'Continue' })).toBeEnabled()

  await chooseMembersPost(page, dialog)
  await expect(dialog.getByRole('button', { name: 'Continue' })).toBeEnabled()

  await chooseGoldPlan(page, dialog)
  await dialog.locator('.cpw-switch-row .el-switch').click()
  await dialog.getByPlaceholder('Use the site default, or write a message for non-members').fill('Join Gold to read this post.')
  await dialog.getByPlaceholder('https://example.com/join (optional)').fill('https://example.com/join')
  await dialog.getByRole('button', { name: 'Review rule' }).click()

  await expect(dialog.getByRole('heading', { name: 'Review the protection rule' })).toBeVisible()
  await expect(dialog.getByText('Members Post')).toBeVisible()
  await expect(dialog.getByText('Gold Plan')).toBeVisible()
  await expect(dialog.getByText('Join Gold to read this post.')).toBeVisible()
  await expect(dialog.getByText('https://example.com/join')).toBeVisible()

  await page.evaluate(() => { window.__fchubSmokeHoldMutations = true })
  const submit = dialog.getByRole('button', { name: 'Protect content' })
  await submit.click()
  await submit.click({ force: true })

  await expect.poll(async () => page.evaluate(() => window.__fchubSmokeRequests.filter(({ url }) => url.includes('/admin/content/protect')).length)).toBe(1)
  await page.evaluate(() => window.__fchubSmokeReleaseMutation?.())
  await expect(dialog).toBeHidden()

  const request = await page.evaluate(() => window.__fchubSmokeRequests.find(({ url }) => url.includes('/admin/content/protect')))
  expect(request).toMatchObject({
    method: 'POST',
    body: {
      resource_type: 'post',
      resource_id: '55',
      plan_ids: [5],
      show_teaser: 'yes',
      restriction_message: 'Join Gold to read this post.',
      redirect_url: 'https://example.com/join',
    },
  })
})

test('preserves completed work when moving back but clears a stale resource after a category change', async ({ page }) => {
  const dialog = await openWizard(page)
  await choosePost(dialog)
  await chooseMembersPost(page, dialog)

  await dialog.getByRole('button', { name: 'Continue' }).click()
  await dialog.getByRole('button', { name: /Select resource/ }).click()
  await expect(dialog.getByText('Members Post')).toBeVisible()
  await dialog.getByRole('button', { name: 'Change content type' }).click()
  await dialog.getByRole('button', { name: /Categories & Tags/ }).click()

  await expect(dialog.getByRole('button', { name: 'Continue' })).toBeDisabled()
  await expect(dialog.getByText('Members Post')).toBeHidden()
  await expect(dialog.locator('.cpw-current-selection strong')).toHaveText('Categories & Tags')
})

test('keeps the modal deliberate and exposes resource-search failures', async ({ page }) => {
  const dialog = await openWizard(page)
  await choosePost(dialog)
  await dialog.getByRole('button', { name: 'Continue' }).click()

  await page.keyboard.press('Escape')
  await expect(dialog).toBeVisible()

  await page.evaluate(() => { window.__fchubSmokeFailResourceSearch = true })
  await dialog.getByRole('combobox', { name: /Resource/ }).fill('Unavailable')
  await expect(dialog.getByText('Content search is temporarily unavailable')).toBeVisible()
  await expect(dialog.getByRole('button', { name: 'Continue' })).toBeDisabled()
})

test('centres the desktop wizard inside the WordPress content canvas', async ({ page }) => {
  await page.setViewportSize({ width: 1159, height: 809 })
  await page.goto('/smoke/index.html#/content')
  await page.evaluate(() => document.body.classList.add('wp-admin'))
  await page.getByRole('button', { name: 'Protect Content' }).click()

  const geometry = await page.locator('.content-protection-wizard-overlay .el-overlay-dialog').evaluate((overlay) => {
    const dialog = overlay.querySelector('.content-protection-wizard')
    const overlayRect = overlay.getBoundingClientRect()
    const dialogRect = dialog.getBoundingClientRect()
    const contentLeft = 160
    const contentRight = window.innerWidth

    return {
      overlayLeft: overlayRect.left,
      dialogLeft: dialogRect.left,
      dialogCenter: dialogRect.left + (dialogRect.width / 2),
      contentCenter: contentLeft + ((contentRight - contentLeft) / 2),
    }
  })

  expect(geometry.overlayLeft).toBe(160)
  expect(geometry.dialogLeft).toBeGreaterThanOrEqual(160)
  expect(Math.abs(geometry.dialogCenter - geometry.contentCenter)).toBeLessThanOrEqual(1)
})

test('follows folded and auto-folded WordPress navigation widths', async ({ page }) => {
  await page.setViewportSize({ width: 1159, height: 809 })
  await page.goto('/smoke/index.html#/content')
  await page.evaluate(() => document.body.classList.add('wp-admin', 'folded'))
  await page.getByRole('button', { name: 'Protect Content' }).click()

  const overlay = page.locator('.content-protection-wizard-overlay .el-overlay-dialog')
  await expect.poll(async () => overlay.evaluate((element) => element.getBoundingClientRect().left)).toBe(36)

  await page.setViewportSize({ width: 900, height: 809 })
  await page.evaluate(() => {
    document.body.classList.remove('folded')
    document.body.classList.add('auto-fold')
  })
  await expect.poll(async () => overlay.evaluate((element) => element.getBoundingClientRect().left)).toBe(36)

  const compactGeometry = await overlay.evaluate((element) => {
    const overlayRect = element.getBoundingClientRect()
    const dialogRect = element.querySelector('.content-protection-wizard').getBoundingClientRect()
    return {
      overlayCenter: overlayRect.left + (overlayRect.width / 2),
      dialogCenter: dialogRect.left + (dialogRect.width / 2),
      dialogLeft: dialogRect.left,
      dialogRight: dialogRect.right,
      viewportWidth: window.innerWidth,
    }
  })
  expect(compactGeometry.dialogLeft).toBeGreaterThanOrEqual(52)
  expect(compactGeometry.dialogRight).toBeLessThanOrEqual(compactGeometry.viewportWidth - 16)
  expect(Math.abs(compactGeometry.dialogCenter - compactGeometry.overlayCenter)).toBeLessThanOrEqual(1)
})

test('uses a full-height, non-overflowing mobile workspace with reachable progress and actions', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  const dialog = await openWizard(page)
  await page.evaluate(() => document.body.classList.add('wp-admin', 'auto-fold'))
  await choosePost(dialog)

  const geometry = await dialog.evaluate((element) => {
    const rect = element.getBoundingClientRect()
    const progress = element.querySelector('.cpw-progress')
    const footer = element.querySelector('.cpw-footer')?.getBoundingClientRect()
    return {
      left: rect.left,
      right: rect.right,
      top: rect.top,
      bottom: rect.bottom,
      viewportWidth: window.innerWidth,
      viewportHeight: window.innerHeight,
      documentWidth: document.documentElement.scrollWidth,
      progressScrollable: progress.scrollWidth > progress.clientWidth,
      progressScrollWidth: progress.scrollWidth,
      progressClientWidth: progress.clientWidth,
      footerTop: footer?.top,
      footerBottom: footer?.bottom,
    }
  })

  expect(geometry.left).toBeGreaterThanOrEqual(0)
  expect(geometry.right).toBeLessThanOrEqual(geometry.viewportWidth)
  expect(geometry.top).toBeGreaterThanOrEqual(0)
  expect(geometry.bottom).toBeLessThanOrEqual(geometry.viewportHeight)
  expect(geometry.documentWidth).toBeLessThanOrEqual(geometry.viewportWidth)
  expect(
    geometry.progressScrollWidth - geometry.progressClientWidth,
    JSON.stringify(geometry),
  ).toBeLessThanOrEqual(1)
  expect(geometry.footerTop).toBeGreaterThanOrEqual(0)
  expect(geometry.footerBottom).toBeLessThanOrEqual(geometry.viewportHeight)
  await expect(dialog.getByRole('button', { name: 'Continue' })).toBeInViewport()

  const mobileCards = await progressCardGeometry(dialog)
  expect(new Set(mobileCards.map(({ width }) => Math.round(width))).size).toBe(1)
  expect(new Set(mobileCards.map(({ height }) => Math.round(height))).size).toBe(1)
  expect(mobileCards[0].height).toBeLessThanOrEqual(70)
  expect(mobileCards[0].left).toBeGreaterThanOrEqual(15)
  expect(mobileCards.at(-1).right).toBeLessThanOrEqual(375)
})
