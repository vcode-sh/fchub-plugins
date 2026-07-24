import { enableAutoUnmount, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import SettingsWebhooksApiSection from '@/components/settings/SettingsWebhooksApiSection.vue'

enableAutoUnmount(afterEach)

const endpoint = {
  id: 'we_1',
  name: 'CRM receiver',
  url: 'https://crm.example/webhook',
  status: 'paused',
  secret_configured: true,
  requires_rotation: false,
  last_test_status: 'succeeded',
}

function actions() {
  return {
    createEndpoint: vi.fn().mockResolvedValue(true),
    rotateEndpointSecret: vi.fn(),
    testEndpoint: vi.fn(),
    activateEndpoint: vi.fn(),
    pauseEndpoint: vi.fn(),
    deleteEndpoint: vi.fn(),
    generateApiKey: vi.fn(),
    regenerateApiKey: vi.fn(),
    revokeApiKey: vi.fn(),
  }
}

function mountSection(overrides = {}) {
  return mount(SettingsWebhooksApiSection, {
    attachTo: document.body,
    props: {
      accessApi: { configured: false, prefix: '', rotated_at: '' },
      endpoints: [],
      endpointBusy: {},
      oneTimeEndpointSecret: { secret: '', name: '' },
      oneTimeCredentials: { apiKey: '' },
      busy: {},
      actions: actions(),
      history: {},
      ...overrides,
    },
  })
}

describe('settings webhooks and API section', () => {
  beforeEach(() => navigator.clipboard.writeText.mockClear())

  it('replaces global webhook controls with an explicit endpoint workflow', async () => {
    const handlers = actions()
    const wrapper = mountSection({ actions: handlers })

    expect(wrapper.text()).toContain('Every endpoint has its own secret')
    expect(wrapper.text()).toContain('No webhook endpoints yet')
    expect(wrapper.find('[aria-label="Webhook URLs"]').exists()).toBe(false)
    expect(wrapper.find('[aria-label="Enable webhooks"]').exists()).toBe(false)

    await wrapper.get('[data-add-webhook-endpoint]').trigger('click')
    expect(wrapper.get('[data-endpoint-create-form]').exists()).toBe(true)
    expect(wrapper.get('[aria-label="Endpoint URL"]').classes()).not.toContain('is-borderless')
    await wrapper.get('[aria-label="Endpoint name"]').setValue('CRM receiver')
    await wrapper.get('[aria-label="Endpoint URL"]').setValue('https://crm.example/webhook')
    await wrapper.get('[data-endpoint-create-form]').trigger('submit')

    expect(handlers.createEndpoint).toHaveBeenCalledWith({
      name: 'CRM receiver',
      url: 'https://crm.example/webhook',
    })
  })

  it('renders independently managed endpoint cards and delegates their actions', async () => {
    const handlers = actions()
    const wrapper = mountSection({ endpoints: [endpoint], actions: handlers })

    expect(wrapper.get('[data-webhook-endpoint]').text()).toContain('CRM receiver')
    expect(wrapper.get('[data-endpoint-secret]').text()).toContain('Independent secret')
    await wrapper.get('[data-test-endpoint]').trigger('click')
    await wrapper.get('[data-activate-endpoint]').trigger('click')
    await wrapper.get('[data-rotate-endpoint-secret]').trigger('click')
    await wrapper.get('[data-delete-endpoint]').trigger('click')

    expect(handlers.testEndpoint).toHaveBeenCalledWith('we_1')
    expect(handlers.activateEndpoint).toHaveBeenCalledWith('we_1')
    expect(handlers.rotateEndpointSecret).toHaveBeenCalledWith('we_1')
    expect(handlers.deleteEndpoint).toHaveBeenCalledWith('we_1')
  })

  it('keeps the one-time endpoint secret dialog closeable without a successful copy', async () => {
    const wrapper = mountSection({
      oneTimeEndpointSecret: { secret: 'one-time-secret', name: 'CRM receiver' },
    })

    expect(wrapper.get('[data-secret-value]').text()).toBe('one-time-secret')
    expect(wrapper.get('[data-close-secret-dialog]').attributes('disabled')).toBeUndefined()
    await wrapper.get('[data-close-secret-dialog]').trigger('click')
    expect(wrapper.emitted('close-endpoint-secret')).toHaveLength(1)
  })

  it('renders API metadata without exposing a stored credential and delegates actions', async () => {
    const handlers = actions()
    const wrapper = mountSection({
      accessApi: { configured: true, prefix: 'fch_live_abcd', rotated_at: '2026-07-24 09:00:00' },
      actions: handlers,
    })

    expect(wrapper.get('[data-access-api-status]').text()).toBe('Ready')
    expect(wrapper.text()).toContain('fch_live_abcd')
    await wrapper.get('[data-regenerate-api-key]').trigger('click')
    await wrapper.get('[data-revoke-api-key]').trigger('click')
    expect(handlers.regenerateApiKey).toHaveBeenCalledOnce()
    expect(handlers.revokeApiKey).toHaveBeenCalledOnce()
  })

  it('keeps server errors local and forwards delivery refresh, retry and cancellation', async () => {
    const wrapper = mountSection({
      webhookError: 'The endpoint must use HTTPS.',
      history: {
        deliveries: [{
          id: 14,
          event_id: 'event-14',
          event_type: 'grant_created',
          destination_url: 'https://hooks.example.com/private',
          status: 'retrying',
          attempt_count: 2,
          response_code: 500,
          created_at: '2026-07-24 09:00:00',
        }, {
          id: 15,
          event_id: 'event-15',
          event_type: 'grant_revoked',
          destination_url: 'https://hooks.example.com/private',
          status: 'failed',
          attempt_count: 7,
          response_code: 500,
          created_at: '2026-07-24 08:55:00',
        }],
      },
    })

    expect(wrapper.get('[data-webhook-error]').text()).toContain('must use HTTPS')
    await wrapper.get('[data-refresh-history]').trigger('click')
    await wrapper.get('[data-retry-delivery]').trigger('click')
    await wrapper.get('[data-cancel-delivery]').trigger('click')
    expect(wrapper.emitted('refresh-history')).toHaveLength(1)
    expect(wrapper.emitted('retry-delivery')).toEqual([[15]])
    expect(wrapper.emitted('cancel-delivery')).toEqual([[14]])
  })

  it('opens endpoint-aware webhook help and the exact Access API guide', async () => {
    const wrapper = mountSection()

    await wrapper.get('[data-open-webhook-guide]').trigger('click')
    expect(wrapper.get('[data-connection-guide]').text()).toContain('Each receiver is managed independently')
    expect(wrapper.get('[data-connection-guide]').text()).toContain('failed test never enters the retry queue')
    await wrapper.get('[data-close-guide]').trigger('click')

    await wrapper.get('[data-open-api-guide]').trigger('click')
    expect(wrapper.get('[data-connection-guide]').text()).toContain(
      'https://example.com/wp-json/fchub-memberships/v1/check-access',
    )
  })
})
