import { test, expect } from '@playwright/test'

async function openGrantDialog(page) {
  await page.goto('/smoke/index.html#/members')
  await page.getByRole('button', { name: 'Grant Access', exact: true }).click()
  const dialog = page.getByRole('dialog', { name: 'Grant Access' })
  await expect(dialog).toBeVisible()
  return dialog
}

test('turns Grant Access into a guided assignment with browse-first user search', async ({ page }) => {
  const dialog = await openGrantDialog(page)

  await expect(dialog.getByText('Create a manual membership grant')).toBeVisible()
  await expect(dialog.getByText('Browse recent users or type at least 2 characters to search.')).toBeVisible()

  await dialog.locator('.grant-user-picker').click()
  await expect(page.getByRole('option', { name: /Alice Example/ })).toBeVisible()
  await page.getByRole('option', { name: /Alice Example/ }).click()

  await dialog.locator('.grant-form-section').nth(1).locator('.el-select').click()
  await page.getByRole('option', { name: 'Gold Plan', exact: true }).click()

  await expect(dialog.getByRole('region', { name: 'Access summary' })).toContainText('Alice Example')
  await expect(dialog.getByRole('region', { name: 'Access summary' })).toContainText('Gold Plan')
  await expect(dialog.getByRole('region', { name: 'Access summary' })).toContainText('Plan default')
  await expect(dialog.getByRole('button', { name: 'Grant access', exact: true })).toBeEnabled()
})

test('keeps the dialog, dropdown, and primary action inside a compact mobile viewport', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 640 })
  const dialog = await openGrantDialog(page)
  await dialog.locator('.grant-user-picker').click()
  await expect(page.getByRole('option', { name: /Alexandria Montgomery/ })).toBeVisible()

  const geometry = await page.locator('.grant-access-dialog').evaluate((element) => {
    const dialogRect = element.getBoundingClientRect()
    const body = element.querySelector('.el-dialog__body')
    const footerRect = element.querySelector('.el-dialog__footer').getBoundingClientRect()
    const popper = document.querySelector('.grant-user-popper')
    const popperRect = popper.getBoundingClientRect()

    return {
      viewportWidth: window.innerWidth,
      viewportHeight: window.innerHeight,
      dialogLeft: dialogRect.left,
      dialogRight: dialogRect.right,
      dialogTop: dialogRect.top,
      dialogBottom: dialogRect.bottom,
      footerTop: footerRect.top,
      footerBottom: footerRect.bottom,
      bodyClientHeight: body.clientHeight,
      bodyScrollHeight: body.scrollHeight,
      popperLeft: popperRect.left,
      popperRight: popperRect.right,
      popperTop: popperRect.top,
      popperBottom: popperRect.bottom,
    }
  })

  expect(geometry.dialogLeft).toBeGreaterThanOrEqual(0)
  expect(geometry.dialogRight).toBeLessThanOrEqual(geometry.viewportWidth)
  expect(geometry.dialogTop).toBeGreaterThanOrEqual(0)
  expect(geometry.dialogBottom).toBeLessThanOrEqual(geometry.viewportHeight)
  expect(geometry.footerBottom).toBeLessThanOrEqual(geometry.viewportHeight)
  expect(geometry.bodyScrollHeight).toBeGreaterThan(geometry.bodyClientHeight)
  expect(geometry.popperLeft).toBeGreaterThanOrEqual(0)
  expect(geometry.popperRight).toBeLessThanOrEqual(geometry.viewportWidth)
  expect(geometry.popperTop).toBeGreaterThanOrEqual(geometry.dialogTop)
  expect(geometry.popperBottom).toBeLessThanOrEqual(geometry.footerTop)
})
