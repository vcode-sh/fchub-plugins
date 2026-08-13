import { expect, test } from '@playwright/test'

async function openCreatePlan(page) {
  await page.goto('/smoke/index.html#/plans/new')
  await expect(page.getByRole('heading', { name: 'Create membership plan' })).toBeVisible()
}

// Each rule owns a picker, so scope option lookups to the dropdown on screen.
function openOption(page, name) {
  return page.locator('.resource-picker-popper:visible').getByRole('option', { name })
}

async function completeOffer(page, { name = 'Gold Membership', durationDays } = {}) {
  await page.getByLabel('Plan name').fill(name)
  await expect(page.getByLabel('Slug')).toHaveValue(name === 'Klub Przyjaciół Psów' ? 'klub-przyjaciol-psow' : name.toLowerCase().replaceAll(' ', '-'))
  if (durationDays) {
    await page.getByRole('button', { name: /Fixed duration/ }).click()
    await page.getByLabel('Duration (days)').fill(String(durationDays))
  }
  await page.getByRole('button', { name: 'Continue' }).click()
  await expect(page.getByRole('heading', { name: 'Choose content access' })).toBeVisible()
}

test.describe('Guided Plan Builder', () => {
  test.beforeEach(async ({ page }) => {
    await openCreatePlan(page)
  })

  test('presents the approved three-step flow and live plan summary', async ({ page }) => {
    const offerStep = page.getByRole('button', { name: /The offer/ })
    await expect(offerStep).toHaveAttribute('aria-current', 'step')
    await expect(page.getByRole('button', { name: /Content access/ })).toBeVisible()
    await expect(page.getByRole('button', { name: /Review/ })).toBeVisible()
    const summary = page.locator('.plan-builder-summary-desktop .plan-summary-desktop-content')
    await expect(summary.getByText('Untitled plan', { exact: true })).toBeVisible()
    await expect(summary.getByText('Lifetime access', { exact: true })).toBeVisible()

    await page.getByLabel('Plan name').fill('Founders Club')
    await expect(summary.getByText('Founders Club', { exact: true })).toBeVisible()

    await page.getByRole('button', { name: /Fixed duration/ }).click()
    await page.getByLabel('Duration (days)').fill('30')
    await expect(summary.getByText('30 days', { exact: true })).toBeVisible()
  })

  test('keeps the user on Offer and focuses the first missing field', async ({ page }) => {
    await page.getByRole('button', { name: 'Continue' }).click()

    await expect(page.getByRole('button', { name: /The offer/ })).toHaveAttribute('aria-current', 'step')
    await expect(page.getByLabel('Plan name')).toBeFocused()
    await expect(page.getByText('Title is required')).toBeVisible()
    await expect(page.getByText('Enter a slug for this title')).toHaveCount(0)
  })

  test('creates an exact payload once after a complete offer and content rule', async ({ page }) => {
    await completeOffer(page, { durationDays: 30 })

    await page.getByRole('button', { name: 'Add content access' }).click()
    await expect(page.getByRole('heading', { name: /Posts · all of this type · immediately/ })).toBeVisible()
    await expect(page.locator('.plan-builder-summary-desktop .plan-summary-desktop-content').getByText('1 content rule', { exact: true })).toBeVisible()

    await page.getByRole('button', { name: 'Review plan' }).click()
    await expect(page.getByRole('heading', { name: 'Review the member experience' })).toBeVisible()
    await expect(page.getByRole('region', { name: 'Review the member experience' }).getByText('30 days', { exact: true })).toBeVisible()

    await page.getByRole('button', { name: 'Create plan' }).dblclick()
    await expect(page).toHaveURL(/#\/plans$/)

    const mutations = await page.evaluate(() => window.__fchubSmokeRequests)
    expect(mutations).toHaveLength(1)
    expect(mutations[0].method).toBe('POST')
    expect(Object.keys(mutations[0].body).sort()).toEqual([
      'description',
      'duration_days',
      'duration_type',
      'grace_period_days',
      'includes_plan_ids',
      'level',
      'meta',
      'rules',
      'status',
      'title',
      'trial_days',
    ])
    expect(mutations[0].body).toMatchObject({
      title: 'Gold Membership',
      duration_type: 'fixed_days',
      duration_days: 30,
      rules: [{ resource_type: 'post', resource_id: '0', drip_type: 'immediate' }],
    })
    expect(mutations[0].body).not.toHaveProperty('activeStep')
    expect(mutations[0].body).not.toHaveProperty('summary')
  })

  test('keeps content rule controls clear of every card edge', async ({ page }) => {
    await completeOffer(page)
    await page.getByRole('button', { name: 'Add content access' }).click()

    const insets = await page.locator('.rule-row').first().evaluate((card) => {
      const cardRect = card.getBoundingClientRect()
      const fields = Array.from(card.querySelectorAll('.rule-fields .el-form-item'))
        .filter((field) => field.getBoundingClientRect().width > 0)
        .map((field) => field.getBoundingClientRect())

      return {
        left: Math.min(...fields.map((field) => field.left)) - cardRect.left,
        right: cardRect.right - Math.max(...fields.map((field) => field.right)),
        bottom: cardRect.bottom - Math.max(...fields.map((field) => field.bottom)),
      }
    })

    expect(insets.left).toBeGreaterThanOrEqual(16)
    expect(insets.right).toBeGreaterThanOrEqual(16)
    expect(insets.bottom).toBeGreaterThanOrEqual(16)
  })

  test('allows a plan without content rules and warns before creation', async ({ page }) => {
    await completeOffer(page)
    await page.getByRole('button', { name: 'Review plan' }).click()

    await expect(page.getByText('This plan does not unlock protected content yet.')).toBeVisible()
    await expect(page.getByText('This plan will stay inactive.')).toBeVisible()
    await expect(page.getByRole('button', { name: 'Create plan' })).toBeVisible()
  })

  test('recovers conditional duration and drip validation inside the owning step', async ({ page }) => {
    await page.getByLabel('Plan name').fill('Timed Membership')
    await page.getByRole('button', { name: /Fixed duration/ }).click()
    await page.getByRole('button', { name: 'Continue' }).click()

    await expect(page.getByRole('button', { name: /The offer/ })).toHaveAttribute('aria-current', 'step')
    await expect(page.getByLabel('Duration (days)')).toBeFocused()
    await expect(page.getByText('Enter the access duration')).toBeVisible()

    await page.getByLabel('Duration (days)').fill('30')
    await page.getByRole('button', { name: 'Continue' }).click()
    await page.getByRole('button', { name: 'Add content access' }).click()
    await page.locator('.el-form-item', { hasText: 'Access begins' }).locator('.el-select__wrapper').click()
    await page.getByRole('option', { name: 'After a delay' }).click()
    await page.getByLabel('Delay (days)').fill('')
    await page.getByRole('button', { name: 'Review plan' }).click()

    await expect(page.getByRole('button', { name: /Content access/ })).toHaveAttribute('aria-current', 'step')
    await expect(page.getByLabel('Delay (days)')).toBeFocused()
    await expect(page.getByText('Enter the delay')).toBeVisible()
  })

  test('opens the drip preview for delayed access while creating a plan', async ({ page }) => {
    await completeOffer(page)
    await page.getByRole('button', { name: 'Add content access' }).click()
    await page.locator('.el-form-item', { hasText: 'Access begins' }).locator('.el-select__wrapper').click()
    await page.getByRole('option', { name: 'After a delay' }).click()

    await expect(page.getByRole('tab', { name: 'Drip Preview' })).toHaveAttribute('aria-selected', 'true')
    await expect(page.getByText('Day 1 after enrollment')).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Preview scheduled access' })).toBeVisible()
    await expect(page.getByRole('tab', { name: 'Linked Products' })).toHaveCount(0)
  })

  test('names the chosen resource everywhere instead of falling back to its id', async ({ page }) => {
    await completeOffer(page)
    await page.getByRole('button', { name: 'Add content access' }).click()

    const picker = page.locator('.resource-picker')
    await expect(picker.locator('.el-select__placeholder')).toHaveText('All of this type')
    await picker.click()
    await openOption(page, /Members Post/).click()

    await expect(page.getByRole('heading', { name: /Posts · Members Post · immediately/ })).toBeVisible()

    // A second search replaces the option list; the field must still name the pick.
    await picker.getByRole('combobox').fill('Mobile')
    await expect(openOption(page, /Mobile-First Design/)).toBeVisible()
    await page.keyboard.press('Escape')
    await expect(picker.locator('.el-select__placeholder')).toHaveText('Members Post')

    await page.locator('.el-form-item', { hasText: 'Access begins' }).locator('.el-select__wrapper').click()
    await page.getByRole('option', { name: 'After a delay' }).click()
    await expect(page.getByRole('heading', { name: 'Preview scheduled access' })).toBeVisible()
    await expect(page.locator('.el-timeline-item').getByText('Members Post')).toBeVisible()
    await expect(page.getByText('Post #55')).toHaveCount(0)
  })

  test('drops a rule without stealing the title of the rule below it', async ({ page }) => {
    await completeOffer(page)
    await page.getByRole('button', { name: 'Add content access' }).click()

    const picker = (index) => page.locator('.rule-row').nth(index).locator('.resource-picker')
    await picker(0).click()
    await openOption(page, /Members Post/).click()
    await expect(picker(0).locator('.el-select__placeholder')).toHaveText('Members Post')
    await expect(page.locator('.resource-picker-popper:visible')).toHaveCount(0)

    await page.getByRole('button', { name: 'Add content access' }).click()
    await picker(1).click()
    await openOption(page, /Mobile-First Design/).click()
    await expect(picker(1).locator('.el-select__placeholder')).toHaveText(/Mobile-First Design/)

    await page.getByRole('button', { name: 'Remove rule 1' }).click()

    await expect(page.locator('.rule-row')).toHaveCount(1)
    await expect(picker(0).locator('.el-select__placeholder')).toHaveText(/Mobile-First Design/)
    await expect(page.getByRole('heading', { name: /Posts · Mobile-First Design/ })).toBeVisible()
  })

  test('blocks Enter from starting a second save while the first request is pending', async ({ page }) => {
    await completeOffer(page)
    await page.getByRole('button', { name: 'Review plan' }).click()
    await page.evaluate(() => {
      window.__fchubSmokeHoldMutations = true
    })

    const createButton = page.getByRole('button', { name: 'Create plan' })
    await createButton.click()
    await createButton.press('Enter')

    expect(await page.evaluate(() => window.__fchubSmokeRequests)).toHaveLength(1)
    await page.evaluate(() => {
      window.__fchubSmokeHoldMutations = false
      window.__fchubSmokeReleaseMutation?.()
    })
    await expect(page).toHaveURL(/#\/plans$/)
    expect(await page.evaluate(() => window.__fchubSmokeRequests)).toHaveLength(1)
  })

  test('routes invalid advanced data back to the revealed Offer field', async ({ page }) => {
    await page.getByLabel('Plan name').fill('Gold Membership')
    await page.getByRole('button', { name: 'Advanced settings' }).click()
    await page.getByLabel('Slug').fill('---')
    await expect(page.getByText('The title or custom slug does not contain usable characters.').first()).toBeVisible()
    await page.getByRole('button', { name: /Review/ }).click()
    await page.getByRole('button', { name: 'Create plan' }).click()

    await expect(page.getByRole('button', { name: /The offer/ })).toHaveAttribute('aria-current', 'step')
    await expect(page.getByRole('button', { name: 'Advanced settings' })).toHaveAttribute('aria-expanded', 'true')
    await expect(page.getByLabel('Slug')).toBeFocused()
    await expect(page.getByText('The title or custom slug does not contain usable characters.').first()).toBeVisible()
  })

  test('uses the server preview for Polish plan names', async ({ page }) => {
    await completeOffer(page, { name: 'Klub Przyjaciół Psów' })

    await expect(page.getByLabel('Slug')).toHaveValue('klub-przyjaciol-psow')
  })

  test('uses Readiness Canvas-style progress cards without mobile page overflow', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 })
    await page.reload()

    await expect(page.getByRole('button', { name: /STEP 1 The offer/ })).toBeVisible()
    await expect(page.getByRole('button', { name: /STEP 2 Content access/ })).toBeVisible()
    await expect(page.getByRole('button', { name: /STEP 3 Review/ })).toBeVisible()
    await expect(page.getByRole('button', { name: /Plan at a glance Untitled plan/ })).toBeVisible()

    await page.getByRole('button', { name: /Plan at a glance Untitled plan/ }).click()
    await expect(page.locator('#mobile-plan-summary-mobile-content').getByText('No content rules', { exact: true })).toBeVisible()
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
    expect(await page.evaluate(() => {
      const nav = document.querySelector('.fchub-top-nav').getBoundingClientRect()
      const header = document.querySelector('.workspace-page-header').getBoundingClientRect()
      return nav.left >= 0 && nav.right <= window.innerWidth && header.top >= nav.bottom
    })).toBe(true)
    expect(await page.evaluate(() => {
      const rail = document.querySelector('.builder-progress').getBoundingClientRect()
      return Array.from(document.querySelectorAll('.builder-step')).every((step) => {
        const rect = step.getBoundingClientRect()
        return rect.left >= rail.left - 1 && rect.right <= rail.right + 1
      })
    })).toBe(true)

    const primaryBox = await page.getByRole('button', { name: 'Continue' }).boundingBox()
    expect(primaryBox).not.toBeNull()
    expect(primaryBox.x + primaryBox.width).toBeLessThanOrEqual(390)
  })

  test('keeps edit-only tools and opens non-default advanced settings', async ({ page }) => {
    await page.goto('/smoke/index.html#/plans/5/edit')
    await expect(page.getByRole('heading', { name: 'Edit membership plan' })).toBeVisible()

    await expect(page.getByRole('button', { name: 'Advanced settings' })).toHaveAttribute('aria-expanded', 'true')
    await expect(page.getByLabel('Level')).toHaveValue('1')
    await expect(page.getByRole('tab', { name: 'Linked Products' })).toBeVisible()
    await expect(page.getByRole('tab', { name: 'Members' })).toBeVisible()
    await expect(page.getByRole('cell', { name: 'Membership Product', exact: true })).toBeVisible()
    await expect(page.getByText('No FluentCart products linked to this plan yet.')).toHaveCount(0)
  })

  test('keeps a failed linked-products load honest and retries it', async ({ page }) => {
    await page.goto('/smoke/index.html?linkedProductsFailures=1#/plans/5/edit')

    await expect(page.getByText('Linked products could not be loaded.')).toBeVisible()
    await expect(page.getByText('Linked products are temporarily unavailable.')).toBeVisible()
    await expect(page.getByText('No FluentCart products linked to this plan yet.')).toHaveCount(0)
    await page.getByRole('button', { name: 'Retry linked products' }).click()

    await expect(page.getByRole('cell', { name: 'Membership Product', exact: true })).toBeVisible()
    expect(await page.evaluate(() => window.__fchubSmokeLinkedProductsReads)).toBe(2)
  })

  test('keeps a failed plan-members load honest and retries it', async ({ page }) => {
    await page.goto('/smoke/index.html?planMembersFailures=1#/plans/5/edit')
    await expect(page.getByRole('cell', { name: 'Membership Product', exact: true })).toBeVisible()
    await page.getByRole('tab', { name: 'Members' }).click()

    await expect(page.getByText('Plan members could not be loaded.')).toBeVisible()
    await expect(page.getByText('Plan members are temporarily unavailable.')).toBeVisible()
    await expect(page.getByText('No members have been granted this plan yet.')).toHaveCount(0)
    await page.getByRole('button', { name: 'Retry plan members' }).click()

    await expect(page.getByRole('cell', { name: 'alice@example.com', exact: true })).toBeVisible()
    expect(await page.evaluate(() => window.__fchubSmokePlanMembersReads)).toBe(2)
  })

  test('shows an existing product as already linked instead of offering a duplicate mutation', async ({ page }) => {
    await page.goto('/smoke/index.html#/plans/5/edit')
    await expect(page.getByRole('cell', { name: 'Membership Product', exact: true })).toBeVisible()
    await page.getByRole('button', { name: 'Link Product' }).click()

    const dialog = page.getByRole('dialog', { name: 'Link Product' })
    const linkedProduct = dialog.getByRole('button', { name: /Membership Product.*Already linked/ })
    await expect(linkedProduct).toBeDisabled()
    await expect(dialog.getByText('Already linked')).toBeVisible()
    await expect(dialog.getByRole('button', { name: 'Next' })).toBeDisabled()
    expect(await page.evaluate(() => window.__fchubSmokeRequests)).toHaveLength(0)
  })

  test('links an available product and refreshes the management table', async ({ page }) => {
    await page.goto('/smoke/index.html#/plans/5/edit')
    await expect(page.getByRole('cell', { name: 'Membership Product', exact: true })).toBeVisible()
    await page.getByRole('button', { name: 'Link Product' }).click()

    const dialog = page.getByRole('dialog', { name: 'Link Product' })
    await dialog.getByRole('button', { name: /Workshop Product/ }).click()
    await dialog.getByRole('button', { name: 'Next' }).click()
    await dialog.getByRole('button', { name: 'Link Product' }).click()

    await expect(page.getByRole('cell', { name: 'Workshop Product', exact: true })).toBeVisible()
    const linkRequest = await page.evaluate(() => window.__fchubSmokeRequests.find(({ url }) => url.endsWith('/admin/plans/5/link-product')))
    expect(linkRequest).toMatchObject({ method: 'POST', body: { product_id: 201 } })
    expect(await page.evaluate(() => window.__fchubSmokeLinkedProductsReads)).toBe(2)
  })

  test('unlinks the selected feed and refreshes to the truthful empty state', async ({ page }) => {
    await page.goto('/smoke/index.html#/plans/5/edit')
    await expect(page.getByRole('cell', { name: 'Membership Product', exact: true })).toBeVisible()
    await page.getByRole('button', { name: 'Unlink Membership Product' }).click()
    await page.getByRole('button', { name: 'Unlink', exact: true }).click()

    await expect(page.getByRole('cell', { name: 'Membership Product', exact: true })).toHaveCount(0)
    await expect(page.getByText('No FluentCart products linked to this plan yet.')).toBeVisible()
    const unlinkRequest = await page.evaluate(() => window.__fchubSmokeRequests.find(({ url }) => url.endsWith('/admin/plans/5/unlink-product/70')))
    expect(unlinkRequest).toMatchObject({ method: 'DELETE' })
    expect(await page.evaluate(() => window.__fchubSmokeLinkedProductsReads)).toBe(2)
  })

  test('keeps the linked product visible when unlinking fails', async ({ page }) => {
    await page.goto('/smoke/index.html?unlinkProductFailures=1#/plans/5/edit')
    await expect(page.getByRole('cell', { name: 'Membership Product', exact: true })).toBeVisible()
    await page.getByRole('button', { name: 'Unlink Membership Product' }).click()
    await page.getByRole('button', { name: 'Unlink', exact: true }).click()

    await expect(page.getByText('The product could not be unlinked.')).toBeVisible()
    await expect(page.getByRole('cell', { name: 'Membership Product', exact: true })).toBeVisible()
    await expect(page.getByText('No FluentCart products linked to this plan yet.')).toHaveCount(0)
    expect(await page.evaluate(() => window.__fchubSmokeLinkedProductsReads)).toBe(1)
  })

  test('keeps the dialog and existing table recoverable when the server detects a concurrent duplicate', async ({ page }) => {
    await page.goto('/smoke/index.html?linkProductFailures=1#/plans/5/edit')
    await expect(page.getByRole('cell', { name: 'Membership Product', exact: true })).toBeVisible()
    await page.getByRole('button', { name: 'Link Product' }).click()

    const dialog = page.getByRole('dialog', { name: 'Link Product' })
    await dialog.getByRole('button', { name: /Workshop Product/ }).click()
    await dialog.getByRole('button', { name: 'Next' }).click()
    await dialog.getByRole('button', { name: 'Link Product' }).click()

    await expect(page.getByText('This product is already linked to this plan.')).toBeVisible()
    await expect(dialog).toBeVisible()
    await expect(page.getByRole('cell', { name: 'Membership Product', exact: true })).toBeVisible()
    await expect(page.getByRole('cell', { name: 'Workshop Product', exact: true })).toHaveCount(0)
  })

  test('step buttons are keyboard-operable and never submit the form', async ({ page }) => {
    const accessStep = page.getByRole('button', { name: /Content access/ })
    await accessStep.focus()
    await page.keyboard.press('Enter')

    await expect(accessStep).toHaveAttribute('aria-current', 'step')
    expect(await page.evaluate(() => window.__fchubSmokeRequests)).toHaveLength(0)
  })
})
