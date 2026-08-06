import { expect, test } from '@playwright/test'

async function openCreatePlan(page) {
  await page.goto('/smoke/index.html#/plans/new')
  await expect(page.getByRole('heading', { name: 'Create membership plan' })).toBeVisible()
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
  })

  test('step buttons are keyboard-operable and never submit the form', async ({ page }) => {
    const accessStep = page.getByRole('button', { name: /Content access/ })
    await accessStep.focus()
    await page.keyboard.press('Enter')

    await expect(accessStep).toHaveAttribute('aria-current', 'step')
    expect(await page.evaluate(() => window.__fchubSmokeRequests)).toHaveLength(0)
  })
})
