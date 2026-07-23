import { expect, test } from '@playwright/test'

const viewports = [
  { name: 'mobile', width: 390, height: 844 },
  { name: 'tablet', width: 768, height: 1024 },
  { name: 'desktop', width: 1280, height: 900 },
]

function accountPayload() {
  const plan = (id, title) => ({
    membership_key: `${id}:manual:0`,
    plan_id: id,
    plan_title: title,
    description: '',
    status: 'active',
    source_type: 'manual',
    source_id: 0,
    expires_at: null,
    access_date_kind: 'lifetime',
    is_lifetime: true,
    next_billing_date: null,
    cancellation_effective_at: null,
    progress: null,
    timeline: [],
    action: null,
  })

  return {
    plans: [plan(5, 'Gold'), plan(6, 'Workshop')],
    history: [],
    community: {
      state: 'available',
      profile: {
        is_verified: true,
        badges: [{ slug: 'founder', title: 'Founder' }],
        total_points: 9001,
        level: { title: 'Legend' },
      },
      spaces: [{
        id: 12,
        title: 'Member Lounge',
        plan_ids: [5, 6],
        ownership: 'fchub',
        operation_state: 'applied',
      }],
      courses: [{
        id: 17,
        title: 'Launch Course',
        plan_ids: [5],
        ownership: 'fchub',
        operation_state: 'pending',
        progress: null,
      }],
      pending_access_count: 1,
      capabilities: {
        spaces: { status: 'available' },
        courses: { status: 'available' },
        profile_verification_read: { status: 'available' },
        badges: { status: 'inactive' },
        points: { status: 'inactive' },
        leaderboard_levels: { status: 'inactive' },
      },
    },
  }
}

function dashboardPayload() {
  return {
    summary: {
      active_members: 2,
      new_members_30d: 1,
      grants_30d: 1,
      expiring_7d: 0,
      failed_notifications: 0,
    },
    readiness: {
      active_plans: 1,
      protected_items: 2,
      has_active_plan: true,
      has_protected_content: true,
      has_active_members: true,
    },
    attention: [],
    trend: [],
    plan_distribution: [],
    activity: [],
  }
}

function providerPayload() {
  return [
    {
      value: 'wordpress_core',
      label: 'WordPress Core',
      status: 'healthy',
      version: '6.8.2',
      reason: 'wordpress_core_available',
      capabilities: {
        content: { status: 'available' },
      },
      pending_operations: 0,
      failed_operations: 0,
      last_successful_reconciliation: null,
      repair_url: null,
    },
    {
      value: 'learndash',
      label: 'LearnDash',
      status: 'unverified',
      version: null,
      reason: 'learndash_runtime_not_certified',
      capabilities: {
        courses: { status: 'unverified' },
        groups: { status: 'unverified' },
      },
      pending_operations: 0,
      failed_operations: 0,
      last_successful_reconciliation: null,
      repair_url: null,
    },
    {
      value: 'fluentcrm',
      label: 'FluentCRM',
      status: 'degraded',
      version: '3.1.8',
      reason: 'projection_jobs_pending',
      capabilities: {
        lifecycle_sync: { status: 'degraded' },
      },
      pending_operations: 2,
      failed_operations: 1,
      last_successful_reconciliation: null,
      repair_url: '/integrations?provider=fluentcrm',
    },
    {
      value: 'fluent_community',
      label: 'FluentCommunity',
      status: 'healthy',
      version: '2.7.0',
      reason: 'provider_ok',
      capabilities: {
        spaces: { status: 'available' },
        courses: { status: 'available' },
        badges: { status: 'inactive' },
        points: { status: 'inactive' },
        leaderboard_levels: { status: 'inactive' },
      },
      pending_operations: 0,
      failed_operations: 0,
      last_successful_reconciliation: '2026-07-23 11:30:00',
      repair_url: null,
    },
  ]
}

function fluentCrmHealthPayload() {
  return {
    action: 'Run a dry reconciliation and resolve failures.',
    pending_projections: 2,
    failed_projections: 1,
    failed_reconciliations: 0,
    drift: 1,
  }
}

for (const viewport of viewports) {
  test(`renders member Community cards without overflow at ${viewport.name} width`, async ({ page }) => {
    await page.setViewportSize({ width: viewport.width, height: viewport.height })
    await page.route('**/wp-json/fchub-memberships/v1/my-access', async (route) => {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(accountPayload()) })
    })
    await page.goto('/smoke/portal.html')

    const community = page.getByRole('region', { name: 'Community access' })
    await expect(community).toBeVisible()
    await expect(community).toContainText('Member Lounge')
    await expect(community).toContainText('Launch Course')
    await expect(community).toContainText('Access being prepared')
    await expect(community).not.toContainText('0%')
    await expect(community).toContainText('Verified profile')
    await expect(community).toContainText('1 access update needs attention')
    await expect(page.locator('.fchub-plan-card')).toHaveCount(2)
    await expect(page.getByText('Founder')).toHaveCount(0)
    await expect(page.getByText('Legend')).toHaveCount(0)
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
  })

  test(`keeps provider health compact on Dashboard and detailed on Integrations at ${viewport.name} width`, async ({ page }) => {
    await page.setViewportSize({ width: viewport.width, height: viewport.height })
    await page.goto('/smoke/index.html#/plans')
    await expect(page.getByRole('heading', { name: 'Plans' })).toBeVisible()

    await page.evaluate(({ dashboard, providers, crmHealth }) => {
      const baseFetch = window.fetch
      window.fetch = async (input, init = {}) => {
        const url = String(input)
        if (url.includes('/admin/dashboard')) {
          return { ok: true, status: 200, json: async () => ({ data: dashboard }) }
        }
        if (url.includes('/admin/providers')) {
          return { ok: true, status: 200, json: async () => ({ data: providers }) }
        }
        if (url.includes('/admin/integrations/fluentcrm/health')) {
          return { ok: true, status: 200, json: async () => ({ data: crmHealth }) }
        }
        return baseFetch(input, init)
      }
      window.location.hash = '#/'
    }, {
      dashboard: dashboardPayload(),
      providers: providerPayload(),
      crmHealth: fluentCrmHealthPayload(),
    })

    const compactHealth = page.getByRole('region', { name: 'Provider health' })
    await expect(compactHealth).toBeVisible()
    await expect(compactHealth.locator('.provider-card')).toHaveCount(0)
    await expect(compactHealth).toContainText('2 healthy')
    await expect(compactHealth).toContainText('1 needs attention')
    await expect(compactHealth).toContainText('1 not verified')
    await expect(compactHealth).not.toContainText('FluentCommunity')
    await expect(compactHealth.getByRole('link', { name: 'View integrations' })).toHaveAttribute('href', '#/integrations')
    expect((await compactHealth.boundingBox()).height).toBeLessThan(140)
    expect(await compactHealth.evaluate((node) => node.scrollWidth <= node.clientWidth)).toBe(true)

    await compactHealth.getByRole('link', { name: 'View integrations' }).click()
    await expect(page.getByRole('heading', { name: 'Integrations', exact: true })).toBeVisible()

    const detailedHealth = page.getByRole('region', { name: 'Provider health' })
    await expect(detailedHealth.locator('.provider-card')).toHaveCount(4)
    await expect(detailedHealth).toContainText('FluentCommunity')
    await expect(detailedHealth).not.toContainText('FluentCommunity Pro')
    await expect(detailedHealth).toContainText('Healthy')
    await expect(detailedHealth).toContainText('LearnDash')
    await expect(detailedHealth).toContainText('Not yet verified')
    await expect(detailedHealth).toContainText('Needs attention')
    await expect(detailedHealth).toContainText('Inactive')
    const reviewIssues = detailedHealth.getByRole('link', { name: 'Review issues' })
    await expect(reviewIssues).toHaveCount(1)
    await expect(reviewIssues).toHaveAttribute('href', '#/integrations?provider=fluentcrm')
    await reviewIssues.click()
    const issuePanel = page.locator('.provider-issue-panel')
    await expect(issuePanel).toContainText('Run a dry reconciliation and resolve failures.')
    await expect(issuePanel).toContainText('Pending projections')
    expect(await issuePanel.evaluate((node) => node.scrollWidth <= node.clientWidth)).toBe(true)
    expect(await detailedHealth.evaluate((node) => node.scrollWidth <= node.clientWidth)).toBe(true)
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
  })
}
