import fs from 'node:fs'
import path from 'node:path'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ProviderHealthCards from '@/components/dashboard/ProviderHealthCards.vue'

const dashboardSource = fs.readFileSync(
  path.resolve(process.cwd(), 'resources/admin/pages/Dashboard.vue'),
  'utf8',
)

function provider(overrides = {}) {
  return {
    value: 'fluent_community',
    label: 'FluentCommunity',
    status: 'healthy',
    version: '2.7.0',
    reason: 'provider_ok',
    capabilities: {
      spaces: { status: 'available' },
      courses: { status: 'available' },
      badges: { status: 'unverified' },
    },
    pending_operations: 0,
    failed_operations: 0,
    last_successful_reconciliation: '2026-07-23 11:30:00',
    repair_url: null,
    ...overrides,
  }
}

function response(items) {
  return {
    ok: true,
    status: 200,
    statusText: 'OK',
    json: async () => ({ data: items }),
  }
}

function mountCards(props = {}) {
  return mount(ProviderHealthCards, {
    props,
    global: {
      stubs: {
        RouterLink: {
          props: ['to'],
          template: '<a :href=\"`#${to}`\"><slot /></a>',
        },
      },
    },
  })
}

describe('ProviderHealthCards', () => {
  beforeEach(() => {
    global.fetch = vi.fn()
  })

  it('shows safe healthy, degraded, and unverified provider facts', async () => {
    global.fetch.mockResolvedValue(response([
      provider({
        value: 'wordpress_core',
        label: 'WordPress Core',
        version: '6.8.2',
        capabilities: {
          content: { status: 'available' },
        },
        repair_url: null,
      }),
      provider({
        value: 'learndash',
        label: 'LearnDash',
        status: 'unverified',
        version: null,
        reason: 'runtime_not_certified',
        capabilities: {
          courses: { status: 'unverified' },
          groups: { status: 'unverified' },
        },
        last_successful_reconciliation: null,
      }),
      provider({
        value: 'fluentcrm',
        label: 'FluentCRM',
        status: 'degraded',
        version: '3.1.8',
        reason: 'FluentCommunity\\SecretService token=do-not-render',
        capabilities: {
          lifecycle_sync: { status: 'degraded' },
        },
        pending_operations: 2,
        failed_operations: 1,
        last_successful_reconciliation: null,
        repair_url: '/integrations?provider=fluentcrm',
      }),
      provider({
        version: '2.7.0',
        capabilities: {
          spaces: { status: 'available' },
          courses: { status: 'available' },
          badges: { status: 'inactive' },
          points: { status: 'inactive' },
          leaderboard_levels: { status: 'inactive' },
        },
      }),
    ]))

    const wrapper = mountCards()
    await flushPromises()

    expect(wrapper.text()).toContain('FluentCommunity')
    expect(wrapper.text()).toContain('Healthy')
    expect(wrapper.text()).toContain('2.7.0')
    expect(wrapper.text()).toContain('Spaces')
    expect(wrapper.text()).toContain('Available')
    expect(wrapper.text()).toContain('2 pending')
    expect(wrapper.text()).toContain('1 failed')
    expect(wrapper.text()).toContain('Not yet verified')
    expect(wrapper.text()).toContain('Inactive')
    expect(wrapper.findAll('.provider-card')).toHaveLength(4)
    expect(wrapper.text()).not.toContain('FluentCommunity Pro')
    expect(wrapper.text()).toContain('2026-07-23 11:30')
    expect(wrapper.text()).not.toContain('SecretService')
    expect(wrapper.text()).not.toContain('do-not-render')
    expect(wrapper.findAll('a')).toHaveLength(1)
    expect(wrapper.get('a').attributes('href')).toBe('#/integrations?provider=fluentcrm')
    expect(wrapper.get('a').text()).toContain('Review issues')
    expect(global.fetch).toHaveBeenCalledWith(
      expect.stringContaining('/admin/providers'),
      expect.objectContaining({ method: 'GET' }),
    )
  })

  it('renders a compact dashboard summary that links to the dedicated integrations page', async () => {
    global.fetch.mockResolvedValue(response([
      provider({ value: 'wordpress_core', label: 'WordPress Core' }),
      provider({ value: 'fluent_community', label: 'FluentCommunity' }),
      provider({ value: 'fluentcrm', label: 'FluentCRM', status: 'degraded' }),
      provider({ value: 'learndash', label: 'LearnDash', status: 'unverified' }),
    ]))

    const wrapper = mountCards({ compact: true })
    await flushPromises()

    expect(wrapper.classes()).toContain('provider-health--compact')
    expect(wrapper.findAll('.provider-card')).toHaveLength(0)
    expect(wrapper.text()).toContain('2 healthy')
    expect(wrapper.text()).toContain('1 needs attention')
    expect(wrapper.text()).toContain('1 not verified')
    expect(wrapper.text()).not.toContain('WordPress Core')
    expect(wrapper.get('a').attributes('href')).toBe('#/integrations')
    expect(wrapper.get('a').text()).toContain('View integrations')
  })

  it('uses compact provider health on the dashboard', () => {
    expect(dashboardSource).toContain('<ProviderHealthCards compact />')
  })

  it('renders an honest empty provider state', async () => {
    global.fetch.mockResolvedValue(response([]))

    const wrapper = mountCards()
    await flushPromises()

    expect(wrapper.text()).toContain('No provider information is available')
  })

  it('reports a failed supplementary read without replacing the dashboard alert', async () => {
    global.fetch
      .mockRejectedValueOnce(new Error('backend details must stay private'))
      .mockResolvedValueOnce(response([]))

    const wrapper = mountCards()
    await flushPromises()

    expect(wrapper.get('[role="status"]').text()).toContain('Provider health could not be loaded')
    expect(wrapper.text()).not.toContain('backend details')

    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('No provider information is available')
  })

  it('labels contextual settings actions as configuration', async () => {
    global.fetch.mockResolvedValue(response([
      provider({
        value: 'fluentcrm',
        label: 'FluentCRM',
        status: 'disabled',
        repair_url: '/settings?category=integrations&provider=fluentcrm',
      }),
      provider({
        value: 'fluent_community',
        label: 'FluentCommunity',
        status: 'inactive',
        repair_url: '/settings?category=integrations&provider=fluent_community',
      }),
    ]))

    const wrapper = mountCards()
    await flushPromises()

    const links = wrapper.findAll('a')
    expect(links).toHaveLength(2)
    expect(links.every((link) => link.text().includes('Configure integration'))).toBe(true)
    expect(links.map((link) => link.attributes('href'))).toEqual([
      '#/settings?category=integrations&provider=fluentcrm',
      '#/settings?category=integrations&provider=fluent_community',
    ])
  })

  it('rejects provider action links outside the exact known routes', async () => {
    global.fetch.mockResolvedValue(response([
      provider({ repair_url: '/members' }),
      provider({ value: 'absolute', repair_url: 'https://evil.example/settings' }),
      provider({ value: 'protocol_relative', repair_url: '//evil.example/settings' }),
      provider({ value: 'bad_category', repair_url: '/settings?category=advanced&provider=fluentcrm' }),
      provider({ value: 'bad_provider', repair_url: '/integrations?provider=wordpress_core' }),
      provider({ value: 'extra_query', repair_url: '/integrations?provider=fluentcrm&apply=1' }),
    ]))

    const wrapper = mountCards()
    await flushPromises()

    expect(wrapper.findAll('a')).toHaveLength(0)
  })
})
