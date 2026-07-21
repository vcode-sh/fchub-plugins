import { expect, test } from '@playwright/test'

function dashboardPayload(overrides = {}) {
  return {
    summary: {
      active_members: 12,
      new_members_30d: 4,
      grants_30d: 6,
      expiring_7d: 2,
      failed_notifications: 1,
    },
    readiness: {
      active_plans: 2,
      protected_items: 8,
      has_active_plan: true,
      has_protected_content: true,
      has_active_members: true,
    },
    attention: [],
    trend: [
      { date: '2026-07-19', count: 10 },
      { date: '2026-07-20', count: 12 },
    ],
    plan_distribution: [
      { plan_id: 2, plan_title: 'Starter', count: 3 },
      { plan_id: 5, plan_title: 'Gold', count: 8 },
    ],
    activity: [
      {
        id: 91,
        action: 'created',
        entity_type: 'plan',
        entity_id: 3,
        actor_type: 'system',
        actor_id: 0,
        occurred_at: '2026-07-20 13:45:00',
      },
      {
        id: 90,
        action: 'expired',
        entity_type: 'grant',
        entity_id: 42,
        actor_type: 'cron',
        actor_id: 0,
        occurred_at: '2026-07-19 08:15:00',
      },
    ],
    ...overrides,
  }
}

async function installDashboardFixture(page, fixture) {
  await page.goto('/smoke/index.html#/plans')
  await expect(page.getByRole('heading', { name: 'Plans', exact: true })).toBeVisible()

  await page.evaluate((initialFixture) => {
    const baseFetch = window.fetch
    window.__dashboardFixture = initialFixture
    window.__dashboardRequests = []
    window.fetch = async (input, init = {}) => {
      const url = String(input)
      window.__dashboardRequests.push(url)
      if (!url.includes('/admin/dashboard')) {
        return baseFetch(input, init)
      }

      const current = window.__dashboardFixture
      if (current.delay) {
        await new Promise((resolve) => window.setTimeout(resolve, current.delay))
      }
      if (current.error) {
        return {
          ok: false,
          status: 503,
          statusText: 'Service unavailable',
          json: async () => ({ message: current.error }),
        }
      }
      return {
        ok: true,
        status: 200,
        statusText: 'OK',
        json: async () => current.response ?? { data: current.payload },
      }
    }
    if (initialFixture.darkMode) {
      document.body.classList.add('dark')
    }
    window.location.hash = '#/'
  }, fixture)

  await expect(page.getByRole('heading', { name: 'Dashboard', exact: true })).toBeVisible()
}

test('keeps loading, failure, and successful zero states honest', async ({ page }) => {
  await installDashboardFixture(page, { error: 'Membership data is temporarily unavailable', delay: 250 })
  await expect(page.locator('.dashboard-skeleton')).toBeVisible()
  const failure = page.getByRole('alert')
  await expect(failure).toContainText('Dashboard unavailable')
  await expect(failure).toContainText('Membership data is temporarily unavailable')
  await expect(page.getByRole('region', { name: 'Membership summary' })).toHaveCount(0)

  await page.evaluate((payload) => {
    window.__dashboardFixture = { payload }
  }, dashboardPayload({
    summary: {
      active_members: 0,
      new_members_30d: 0,
      grants_30d: 0,
      expiring_7d: 0,
      failed_notifications: 0,
    },
    readiness: {
      active_plans: 0,
      protected_items: 0,
      has_active_plan: false,
      has_protected_content: false,
      has_active_members: false,
    },
    trend: [],
    plan_distribution: [],
    activity: [],
  }))
  await page.getByRole('button', { name: 'Try again' }).click()

  await expect(page.getByRole('alert')).toHaveCount(0)
  await expect(page.getByRole('region', { name: 'Membership summary' }).locator('.summary-value')).toHaveText(['0', '0', '0', '0'])
})

for (const malformedCase of [
  { name: 'null data', response: { data: null } },
  { name: 'missing sections', response: { data: { summary: dashboardPayload().summary } } },
  { name: 'unwrapped raw payload', response: dashboardPayload() },
]) {
  test(`rejects ${malformedCase.name} instead of inventing a healthy zero state`, async ({ page }) => {
    await installDashboardFixture(page, { response: malformedCase.response })

    await expect(page.getByRole('alert')).toContainText('Dashboard data is incomplete')
    await expect(page.getByRole('region', { name: 'Membership summary' })).toHaveCount(0)
    await expect(page.getByText('Nothing urgent')).toHaveCount(0)
  })
}

test('switches contextual actions between setup and operating states', async ({ page }) => {
  await installDashboardFixture(page, {
    payload: dashboardPayload({
      readiness: {
        active_plans: 0,
        protected_items: 0,
        has_active_plan: false,
        has_protected_content: false,
        has_active_members: false,
      },
    }),
  })
  await expect(page.getByRole('link', { name: 'Create first plan' })).toHaveAttribute('href', '#/plans/new')
  await expect(page.locator('.dashboard-action')).toHaveCount(1)

  await page.evaluate((payload) => {
    window.__dashboardFixture = { payload }
    window.location.hash = '#/plans'
    window.setTimeout(() => { window.location.hash = '#/' }, 0)
  }, dashboardPayload())
  await expect(page.getByRole('link', { name: 'Grant access' })).toHaveAttribute('href', '#/members')
  await expect(page.getByRole('link', { name: 'Protect content' })).toHaveAttribute('href', '#/content')
  await expect(page.locator('.dashboard-action')).toHaveCount(2)
})

test('shows four operational metrics and attention destinations before the summary', async ({ page }) => {
  await installDashboardFixture(page, {
    payload: dashboardPayload({
      attention: [{
        key: 'failed_notifications',
        severity: 'error',
        title: 'Notification delivery failed',
        description: 'Three notices need another look.',
        count: 3,
        destination: '/drip',
      }],
    }),
  })

  const attention = page.getByRole('region', { name: 'Needs attention' })
  const summary = page.getByRole('region', { name: 'Membership summary' })
  await expect(attention.getByRole('link', { name: /Notification delivery failed/ })).toHaveAttribute('href', '#/drip')
  await expect(attention).toContainText('Critical')
  await expect(summary.locator('.summary-metric')).toHaveCount(4)
  await expect(summary).toContainText('6 access grants issued')
  await expect(summary.locator('.summary-label', { hasText: 'Access grants' })).toHaveCount(0)
  expect(await attention.evaluate((node, next) => Boolean(node.compareDocumentPosition(next) & Node.DOCUMENT_POSITION_FOLLOWING), await summary.elementHandle())).toBe(true)
})

test('uses an honest insufficient-history state instead of blank axes', async ({ page }) => {
  await installDashboardFixture(page, {
    payload: dashboardPayload({ trend: [{ date: '2026-07-20', count: 12 }] }),
  })

  const trend = page.getByRole('region', { name: 'Member trend' })
  await expect(trend.locator('canvas')).toHaveCount(0)
  await expect(trend).toContainText('Not enough history yet')
  await expect(trend.getByRole('link', { name: 'View members' })).toHaveAttribute('href', '#/members')
})

test('names the trend chart and provides a concise textual equivalent', async ({ page }) => {
  await installDashboardFixture(page, { payload: dashboardPayload() })

  const trend = page.getByRole('region', { name: 'Member trend' })
  const chart = trend.getByRole('img', { name: 'Member count over the last 30 days' })
  await expect(chart).toHaveAttribute('aria-describedby', 'member-trend-summary')
  await expect(trend.locator('#member-trend-summary')).toContainText('Members increased from 10 to 12 across 2 recorded days.')
})

test('ranks plans and replaces an empty distribution with a truthful member state', async ({ page }) => {
  await installDashboardFixture(page, { payload: dashboardPayload() })
  const distribution = page.getByRole('region', { name: 'Plan distribution' })
  const rows = distribution.locator('.distribution-row')
  await expect(rows).toHaveCount(2)
  await expect(rows.nth(0)).toContainText('Gold')
  await expect(rows.nth(0)).toContainText('8')
  await expect(rows.nth(1)).toContainText('Starter')

  await page.evaluate((payload) => {
    window.__dashboardFixture = { payload }
    window.location.hash = '#/plans'
    window.setTimeout(() => { window.location.hash = '#/' }, 0)
  }, dashboardPayload({ plan_distribution: [] }))
  await expect(page.getByRole('region', { name: 'Plan distribution' })).toContainText('No active members to compare yet')
})

test('renders real dashboard activity without fetching the member list', async ({ page }) => {
  await installDashboardFixture(page, { payload: dashboardPayload() })
  const activity = page.getByRole('region', { name: 'Recent activity' })

  await expect(activity).toContainText('Plan created')
  await expect(activity).toContainText('Plan #3')
  await expect(activity).toContainText('Access expired')
  await expect(activity).toContainText('Access #42')
  await expect(activity).toContainText('2026-07-20 13:45')
  await expect(activity).not.toContainText('Gold')
  await expect(activity.locator('time').first()).toHaveAttribute('datetime', '2026-07-20T13:45:00')

  const requests = await page.evaluate(() => window.__dashboardRequests)
  expect(requests.filter((url) => url.includes('/admin/dashboard'))).toHaveLength(1)
  expect(requests.some((url) => url.includes('/admin/members'))).toBe(false)
})

test('uses inherited dark-theme colours for statuses and the chart', async ({ page }) => {
  await installDashboardFixture(page, {
    darkMode: true,
    payload: dashboardPayload({
      attention: [{
        key: 'failed_notifications',
        severity: 'error',
        title: 'Notification delivery failed',
        description: 'One notice needs another look.',
        count: 1,
        destination: '/drip',
      }],
    }),
  })

  const styles = await page.evaluate(() => {
    const attention = document.querySelector('.attention-item--critical')
    const severity = attention.querySelector('.severity-label')
    const trend = document.querySelector('.trend-panel')
    return {
      borderColor: getComputedStyle(attention).borderLeftColor,
      severityColor: getComputedStyle(severity).color,
      chartPrimary: getComputedStyle(trend).getPropertyValue('--dashboard-chart-primary').trim(),
      themeChartPrimary: getComputedStyle(document.body).getPropertyValue('--fchub-chart-primary').trim(),
    }
  })

  expect(styles.borderColor).not.toBe('rgb(217, 54, 62)')
  expect(styles.severityColor).not.toBe('rgb(180, 35, 42)')
  expect(styles.chartPrimary).toBe(styles.themeChartPrimary)
  expect(styles.themeChartPrimary.toLowerCase()).toBe('#6d8cff')
})

test('keeps the operations home inside a 390px viewport', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await installDashboardFixture(page, { payload: dashboardPayload() })
  await expect(page.getByRole('region', { name: 'Readiness' })).toBeVisible()

  const geometry = await page.evaluate(() => ({
    viewportWidth: window.innerWidth,
    documentWidth: document.documentElement.scrollWidth,
    bodyWidth: document.body.scrollWidth,
  }))
  expect(geometry.documentWidth).toBeLessThanOrEqual(geometry.viewportWidth)
  expect(geometry.bodyWidth).toBeLessThanOrEqual(geometry.viewportWidth)
})
