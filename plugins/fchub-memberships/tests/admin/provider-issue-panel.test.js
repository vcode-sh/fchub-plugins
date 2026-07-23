import fs from 'node:fs'
import path from 'node:path'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { providers } from '@/api/providers.js'
import ProviderIssuePanel from '@/components/integrations/ProviderIssuePanel.vue'

const componentPath = path.resolve(
  process.cwd(),
  'resources/admin/components/integrations/ProviderIssuePanel.vue',
)

function response(data) {
  return {
    ok: true,
    status: 200,
    statusText: 'OK',
    json: async () => ({ data }),
  }
}

describe('provider issue APIs', () => {
  beforeEach(() => {
    global.fetch = vi.fn()
  })

  it('loads FluentCRM health from the existing read endpoint', async () => {
    global.fetch.mockResolvedValue(response({ status: 'healthy' }))

    await providers.fluentCrmHealth()

    expect(global.fetch).toHaveBeenCalledWith(
      expect.stringContaining('/admin/integrations/fluentcrm/health'),
      expect.objectContaining({ method: 'GET' }),
    )
  })

  it('loads a bounded provider reconciliation page', async () => {
    global.fetch.mockResolvedValue(response({ items: [], next_cursor: null }))

    await providers.reconciliationPage({ limit: 100 })

    expect(global.fetch).toHaveBeenCalledWith(
      expect.stringContaining('/admin/provider-reconciliation?limit=100'),
      expect.objectContaining({ method: 'GET' }),
    )
  })
})

describe('ProviderIssuePanel', () => {
  beforeEach(() => {
    global.fetch = vi.fn()
  })

  it('provides the compact integration issue surface', () => {
    expect(fs.existsSync(componentPath)).toBe(true)
  })

  it('shows safe FluentCRM guidance and non-negative health metrics', async () => {
    global.fetch.mockResolvedValue(response({
      action: 'Run a dry reconciliation and resolve failures.',
      pending_projections: 2,
      failed_projections: -4,
      failed_reconciliations: 'not-a-number',
      drift: 3,
    }))

    const wrapper = mount(ProviderIssuePanel, { props: { provider: 'fluentcrm' } })
    await flushPromises()

    expect(wrapper.text()).toContain('FluentCRM issues')
    expect(wrapper.text()).toContain('Run a dry reconciliation and resolve failures.')
    expect(wrapper.text()).toContain('Pending projections')
    expect(wrapper.text()).toContain('2')
    expect(wrapper.text()).toContain('Failed projections')
    expect(wrapper.text()).toContain('Failed reconciliations')
    expect(wrapper.text()).toContain('Detected drift')
    expect(wrapper.findAll('.provider-issue-metric__value').map((item) => item.text())).toEqual([
      '2',
      '0',
      '0',
      '3',
    ])
    expect(wrapper.findAll('button')).toHaveLength(0)
  })

  it('does not expose unknown FluentCRM action or request errors', async () => {
    global.fetch
      .mockRejectedValueOnce(new Error('token=private-backend-detail'))
      .mockResolvedValueOnce(response({
        action: 'SecretService token=do-not-render',
        pending_projections: 0,
        failed_projections: 0,
        failed_reconciliations: 0,
        drift: 0,
      }))

    const wrapper = mount(ProviderIssuePanel, { props: { provider: 'fluentcrm' } })
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('Provider issue details could not be loaded')
    expect(wrapper.text()).not.toContain('private-backend-detail')

    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Review the integration configuration and retry this check.')
    expect(wrapper.text()).not.toContain('SecretService')
    expect(wrapper.text()).not.toContain('do-not-render')
  })

  it('shows at most five safe FluentCommunity issues and reports further pages', async () => {
    global.fetch.mockResolvedValue(response({
      items: [
        {
          provider: 'fluent_community',
          user_id: 12,
          resource_type: 'fc_space',
          resource_id: '34',
          classification: 'internal_active_provider_absent',
        },
        {
          provider: 'fluent_community',
          user_id: 13,
          resource_type: 'fc_course',
          resource_id: 'course-1',
          classification: 'operation_pending',
        },
        {
          provider: 'fluent_community',
          user_id: 14,
          resource_type: 'fc_badge',
          resource_id: 'founding-member',
          classification: 'unknown_ownership',
        },
        {
          provider: 'fluent_community',
          user_id: 15,
          resource_type: 'fc_space',
          resource_id: '56',
          classification: 'operation_stale',
        },
        {
          provider: 'fluent_community',
          user_id: '<script>member</script>',
          resource_type: 'unknown_type',
          resource_id: '<script>secret</script>',
          classification: 'raw_provider_class_name',
        },
        {
          provider: 'fluent_community',
          user_id: 16,
          resource_type: 'fc_course',
          resource_id: '78',
          classification: 'operation_terminal_failed',
        },
        {
          provider: 'fluent_community',
          user_id: 17,
          resource_type: 'fc_space',
          resource_id: '90',
          classification: 'healthy',
        },
        {
          provider: 'learndash',
          user_id: 18,
          resource_type: 'ld_course',
          resource_id: '91',
          classification: 'provider_uncertified',
        },
      ],
      next_cursor: 'opaque-next-page',
    }))

    const wrapper = mount(ProviderIssuePanel, { props: { provider: 'fluent_community' } })
    await flushPromises()

    expect(wrapper.text()).toContain('FluentCommunity issues')
    expect(wrapper.findAll('.provider-issue-item')).toHaveLength(5)
    expect(wrapper.text()).toContain('Access missing in provider')
    expect(wrapper.text()).toContain('Operation pending')
    expect(wrapper.text()).toContain('Ownership needs review')
    expect(wrapper.text()).toContain('Operation stalled')
    expect(wrapper.text()).toContain('Issue needs review')
    expect(wrapper.text()).toContain('Member #12')
    expect(wrapper.text()).toContain('Space #34')
    expect(wrapper.findAll('.provider-issue-item')[1].text()).toContain('Resource unavailable')
    expect(wrapper.text()).not.toContain('Course course-1')
    expect(wrapper.text()).toContain('Badge founding-member')
    expect(wrapper.text()).toContain('Member unavailable')
    expect(wrapper.text()).toContain('Resource unavailable')
    expect(wrapper.text()).toContain('This panel shows the first five issues from this page.')
    expect(wrapper.text()).toContain('More resources remain to be checked.')
    expect(wrapper.text()).not.toContain('raw_provider_class_name')
    expect(wrapper.text()).not.toContain('<script>')
    expect(wrapper.text()).not.toContain('LearnDash')
    expect(wrapper.findAll('button')).toHaveLength(0)
  })

  it('renders an honest empty FluentCommunity issue state', async () => {
    global.fetch.mockResolvedValue(response({
      items: [
        {
          provider: 'fluent_community',
          classification: 'healthy',
        },
      ],
      next_cursor: null,
    }))

    const wrapper = mount(ProviderIssuePanel, { props: { provider: 'fluent_community' } })
    await flushPromises()

    expect(wrapper.text()).toContain('No FluentCommunity access issues were found in the checked resources.')
  })

  it('does not claim more issues merely because another resource page exists', async () => {
    global.fetch.mockResolvedValue(response({
      items: [{
        provider: 'fluent_community',
        user_id: 12,
        resource_type: 'fc_space',
        resource_id: '34',
        classification: 'operation_pending',
      }],
      next_cursor: 'opaque-next-page',
    }))

    const wrapper = mount(ProviderIssuePanel, { props: { provider: 'fluent_community' } })
    await flushPromises()

    expect(wrapper.text()).toContain('More resources remain to be checked.')
    expect(wrapper.text()).not.toContain('first five issues')
  })

  it('follows bounded cursors until it finds a FluentCommunity issue', async () => {
    global.fetch
      .mockResolvedValueOnce(response({
        items: [{
          provider: 'fluentcrm',
          classification: 'operation_pending',
        }],
        next_cursor: 'page-two',
      }))
      .mockResolvedValueOnce(response({
        items: [{
          provider: 'fluent_community',
          user_id: 12,
          resource_type: 'fc_space',
          resource_id: '34',
          classification: 'operation_pending',
        }],
        next_cursor: null,
      }))

    const wrapper = mount(ProviderIssuePanel, { props: { provider: 'fluent_community' } })
    await flushPromises()

    expect(global.fetch).toHaveBeenCalledTimes(2)
    expect(global.fetch.mock.calls[1][0]).toContain('cursor=page-two')
    expect(wrapper.text()).toContain('Operation pending')
    expect(wrapper.text()).toContain('Member #12')
    expect(wrapper.text()).not.toContain('No FluentCommunity access issues')
  })
})
