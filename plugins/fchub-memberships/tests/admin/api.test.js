import { beforeEach, describe, expect, it, vi } from 'vitest'
import { plans } from '@/api/plans.js'
import { members } from '@/api/members.js'
import { content } from '@/api/content.js'
import { drip } from '@/api/drip.js'
import { reports } from '@/api/reports.js'
import { settings } from '@/api/settings.js'
import { importMembers } from '@/api/importMembers.js'
import { createErrorResponse } from './support.js'

const cases = [
  ['plans.list', () => plans.list({ page: 2, search: 'gold' }), 'GET', '/admin/plans?page=2&search=gold'],
  ['plans.get', () => plans.get(5), 'GET', '/admin/plans/5'],
  ['plans.create', () => plans.create({ title: 'Gold' }), 'POST', '/admin/plans'],
  ['plans.update', () => plans.update(5, { status: 'active' }), 'PUT', '/admin/plans/5'],
  ['plans.remove', () => plans.remove(5), 'DELETE', '/admin/plans/5'],
  ['plans.duplicate', () => plans.duplicate(5), 'POST', '/admin/plans/5/duplicate'],
  ['plans.options', () => plans.options(), 'GET', '/admin/plans/options'],
  ['plans.dripSchedule', () => plans.dripSchedule(5), 'GET', '/admin/plans/5/drip-schedule'],
  ['plans.linkedProducts', () => plans.linkedProducts(5), 'GET', '/admin/plans/5/linked-products'],
  ['plans.linkProduct', () => plans.linkProduct(5, { product_id: 9 }), 'POST', '/admin/plans/5/link-product'],
  ['plans.unlinkProduct', () => plans.unlinkProduct(5, 99), 'DELETE', '/admin/plans/5/unlink-product/99'],
  ['plans.searchProducts', () => plans.searchProducts({ search: 'Course' }), 'GET', '/admin/plans/search-products?search=Course'],
  ['plans.export', () => plans.export(5), 'GET', '/admin/plans/5/export'],
  ['plans.exportAll', () => plans.exportAll(), 'GET', '/admin/plans/export-all'],
  ['plans.import', () => plans.import({ title: 'Imported' }), 'POST', '/admin/plans/import'],
  ['plans.schedule', () => plans.schedule(5, { scheduled_status: 'archived' }), 'POST', '/admin/plans/5/schedule'],
  ['plans.resolveResources', () => plans.resolveResources({ resources: [] }), 'POST', '/admin/plans/resolve-resources'],
  ['members.list', () => members.list({ page: 1 }), 'GET', '/admin/members?page=1'],
  ['members.get', () => members.get(21), 'GET', '/admin/members/21'],
  ['members.grant', () => members.grant({ user_id: 21, plan_id: 5 }), 'POST', '/admin/members/grant'],
  ['members.revoke', () => members.revoke({ user_id: 21, plan_id: 5 }), 'POST', '/admin/members/revoke'],
  ['members.extend', () => members.extend({ user_id: 21, plan_id: 5, expires_at: '2026-04-01' }), 'POST', '/admin/members/extend'],
  ['members.export', () => members.export({ status: 'active' }), 'GET', '/admin/members/export?status=active'],
  ['members.pause', () => members.pause({ grant_id: 100 }), 'POST', '/admin/members/pause'],
  ['members.resume', () => members.resume({ grant_id: 100 }), 'POST', '/admin/members/resume'],
  ['members.bulkGrant', () => members.bulkGrant({ user_ids: [21], plan_id: 5 }), 'POST', '/admin/members/bulk-grant'],
  ['members.bulkRevoke', () => members.bulkRevoke({ user_ids: [21], plan_id: 5 }), 'POST', '/admin/members/bulk-revoke'],
  ['members.bulkExtend', () => members.bulkExtend({ user_ids: [21], plan_id: 5, expires_at: '2026-04-01' }), 'POST', '/admin/members/bulk-extend'],
  ['members.bulkExport', () => members.bulkExport({ user_ids: [21] }), 'POST', '/admin/members/bulk-export'],
  ['members.activity', () => members.activity(21, { page: 2 }), 'GET', '/admin/members/21/activity?page=2'],
  ['content.list', () => content.list({ page: 1 }), 'GET', '/admin/content?page=1'],
  ['content.resourceTypes', () => content.resourceTypes(), 'GET', '/admin/content/resource-types'],
  ['content.searchResources', () => content.searchResources({ type: 'post', query: 'Post' }), 'GET', '/admin/content/search-resources?type=post&query=Post'],
  ['content.protect', () => content.protect({ resource_type: 'post', resource_id: 55 }), 'POST', '/admin/content/protect'],
  ['content.unprotectByResource', () => content.unprotectByResource({ resource_type: 'post', resource_id: 55 }), 'POST', '/admin/content/unprotect'],
  ['content.bulkProtect', () => content.bulkProtect({ resource_type: 'post', resource_ids: [55] }), 'POST', '/admin/content/bulk-protect'],
  ['content.bulkUnprotect', () => content.bulkUnprotect({ resource_type: 'post', resource_ids: [55] }), 'POST', '/admin/content/bulk-unprotect'],
  ['content.update', () => content.update(9, { plan_ids: [5] }), 'PUT', '/admin/content/9'],
  ['content.remove', () => content.remove(9), 'DELETE', '/admin/content/9'],
  ['drip.overview', () => drip.overview(), 'GET', '/admin/drip/overview'],
  ['drip.calendar', () => drip.calendar({ from: '2026-03-01', to: '2026-03-31' }), 'GET', '/admin/drip/calendar?from=2026-03-01&to=2026-03-31'],
  ['drip.queue', () => drip.queue({ date: '2026-03-20' }), 'GET', '/admin/drip/notifications?date=2026-03-20'],
  ['drip.retry', () => drip.retry(1), 'POST', '/admin/drip/notifications/1/retry'],
  ['reports.overview', () => reports.overview({ start_date: '2026-01-01', end_date: '2026-01-31' }), 'GET', '/admin/reports/overview?start_date=2026-01-01&end_date=2026-01-31'],
  ['reports.membersOverTime', () => reports.membersOverTime({ period: '30d' }), 'GET', '/admin/reports/members-over-time?period=30d'],
  ['reports.planDistribution', () => reports.planDistribution(), 'GET', '/admin/reports/plan-distribution'],
  ['reports.churn', () => reports.churn({ period: '12m' }), 'GET', '/admin/reports/churn?period=12m'],
  ['reports.revenue', () => reports.revenue(), 'GET', '/admin/reports/revenue'],
  ['reports.contentPopularity', () => reports.contentPopularity(), 'GET', '/admin/reports/content-popularity'],
  ['reports.expiringSoon', () => reports.expiringSoon({ days: 7 }), 'GET', '/admin/reports/expiring-soon?days=7'],
  ['reports.renewalRate', () => reports.renewalRate(), 'GET', '/admin/reports/renewal-rate'],
  ['reports.trialConversion', () => reports.trialConversion(), 'GET', '/admin/reports/trial-conversion'],
  ['settings.get', () => settings.get(), 'GET', '/admin/settings'],
  ['settings.save', () => settings.save({ membership_mode: 'stack' }), 'POST', '/admin/settings'],
  ['settings.generateApiKey', () => settings.generateApiKey(), 'POST', '/admin/settings/generate-api-key'],
  ['settings.regenerateWebhookSecret', () => settings.regenerateWebhookSecret(), 'POST', '/admin/settings/regenerate-webhook-secret'],
  ['settings.testWebhook', () => settings.testWebhook(), 'POST', '/admin/settings/test-webhook'],
  ['importMembers.parse', () => importMembers.parse({ content: 'email' }), 'POST', '/admin/import/parse'],
  ['importMembers.prepare', () => importMembers.prepare({ mappings: [] }), 'POST', '/admin/import/prepare'],
  ['importMembers.execute', () => importMembers.execute({ members: [], mappings: [] }), 'POST', '/admin/import/execute'],
]

describe('admin api wrappers', () => {
  beforeEach(() => {
    global.fetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      statusText: 'OK',
      json: async () => ({ data: true }),
    })
  })

  it.each(cases)('%s hits the expected endpoint', async (_label, call, method, path) => {
    await call()

    expect(global.fetch).toHaveBeenCalledTimes(1)
    const [url, options] = global.fetch.mock.calls[0]
    expect(String(url)).toContain(path)
    expect(options.method).toBe(method)
    expect(options.headers['X-WP-Nonce']).toBe('nonce')
  })

  it('propagates api errors with status and message', async () => {
    global.fetch = vi.fn().mockResolvedValue(createErrorResponse('Broken request', 422))

    await expect(plans.list()).rejects.toMatchObject({
      message: 'Broken request',
      status: 422,
    })
  })

  it('drops empty query params from GET requests', async () => {
    await reports.overview({ start_date: '2026-01-01', end_date: '', period: null })

    const [url] = global.fetch.mock.calls[0]
    expect(String(url)).toContain('start_date=2026-01-01')
    expect(String(url)).not.toContain('end_date=')
    expect(String(url)).not.toContain('period=')
  })
})
