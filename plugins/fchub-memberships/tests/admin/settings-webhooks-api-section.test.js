import { enableAutoUnmount, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import SettingsWebhooksApiSection from '@/components/settings/SettingsWebhooksApiSection.vue'

enableAutoUnmount(afterEach)

function actions() {
  return {
    generateApiKey: vi.fn(),
    regenerateApiKey: vi.fn(),
    revokeApiKey: vi.fn(),
    generateWebhookSecret: vi.fn(),
    regenerateWebhookSecret: vi.fn(),
    testWebhook: vi.fn(),
  }
}

function mountSection(overrides = {}) {
  return mount(SettingsWebhooksApiSection, {
    attachTo: document.body,
    props: {
      form: { webhook_enabled: false, webhook_urls: '' },
      accessApi: { configured: false, prefix: '', rotated_at: '' },
      webhookStatus: 'off',
      webhookSecretConfigured: false,
      webhookConfigurationValid: false,
      oneTimeCredentials: { apiKey: '', webhookSecret: '' },
      busy: {},
      actions: actions(),
      history: {},
      ...overrides,
    },
  })
}

function pressKey(element, key, shiftKey = false) {
  const event = new KeyboardEvent('keydown', {
    key,
    shiftKey,
    bubbles: true,
    cancelable: true,
  })
  element.dispatchEvent(event)
  return event
}

describe('settings webhooks and API section', () => {
  beforeEach(() => {
    navigator.clipboard.writeText.mockClear()
  })

  it('keeps saved webhook configuration visible while delivery is off and prevents invalid enablement', () => {
    const wrapper = mountSection({
      form: { webhook_enabled: false, webhook_urls: 'https://hooks.example.com/member' },
    })

    expect(wrapper.get('[data-webhook-status]').text()).toBe('Off')
    expect(wrapper.get('[aria-label="Webhook URLs"]').element.value).toContain('hooks.example.com')
    expect(wrapper.get('[aria-label="Enable webhooks"]').attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('Not configured')
  })

  it.each([
    ['off', 'Off'],
    ['needs_setup', 'Needs setup'],
    ['ready', 'Ready'],
    ['degraded', 'Delivery failures'],
  ])('renders the frozen %s health state as %s', (webhookStatus, label) => {
    const wrapper = mountSection({ webhookStatus })
    expect(wrapper.get('[data-webhook-status]').text()).toBe(label)
  })

  it('renders public metadata but never reads stored credentials from the settings form', () => {
    const wrapper = mountSection({
      form: {
        webhook_enabled: false,
        webhook_urls: '',
        api_key: 'stored-api-key-sentinel',
        webhook_secret: 'stored-webhook-secret-sentinel',
      },
      accessApi: { configured: true, prefix: 'fch_live_abcd', rotated_at: '2026-07-23 12:30:00' },
      webhookSecretConfigured: true,
    })

    expect(wrapper.get('[data-access-api-status]').text()).toBe('Ready')
    expect(wrapper.text()).toContain('fch_live_abcd')
    expect(wrapper.text()).toContain('2026-07-23 12:30:00')
    expect(wrapper.get('[data-webhook-secret-status]').text()).toBe('Configured (never reveal again)')
    expect(wrapper.text()).not.toContain('stored-api-key-sentinel')
    expect(wrapper.text()).not.toContain('stored-webhook-secret-sentinel')
  })

  it('delegates credential, destructive, and real test actions to the parent', async () => {
    const handlers = actions()
    const wrapper = mountSection({
      accessApi: { configured: true, prefix: 'fch_live_abcd', rotated_at: '' },
      webhookSecretConfigured: true,
      webhookConfigurationValid: true,
      actions: handlers,
    })

    await wrapper.get('[data-regenerate-api-key]').trigger('click')
    await wrapper.get('[data-revoke-api-key]').trigger('click')
    await wrapper.get('[data-regenerate-webhook-secret]').trigger('click')
    await wrapper.get('[data-test-webhook]').trigger('click')

    expect(handlers.regenerateApiKey).toHaveBeenCalledOnce()
    expect(handlers.revokeApiKey).toHaveBeenCalledOnce()
    expect(handlers.regenerateWebhookSecret).toHaveBeenCalledOnce()
    expect(handlers.testWebhook).toHaveBeenCalledOnce()
  })

  it.each([
    [
      false,
      false,
      ['[data-generate-api-key]', '[data-generate-webhook-secret]'],
    ],
    [
      true,
      true,
      ['[data-regenerate-api-key]', '[data-revoke-api-key]', '[data-regenerate-webhook-secret]'],
    ],
  ])('disables every visible credential action under the shared mutation lock', (apiConfigured, secretConfigured, selectors) => {
    const wrapper = mountSection({
      accessApi: { configured: apiConfigured, prefix: 'fch_live_abcd', rotated_at: '' },
      webhookSecretConfigured: secretConfigured,
      busy: { credentialMutation: true },
    })

    for (const selector of selectors) {
      expect(wrapper.get(selector).attributes('disabled')).toBeDefined()
    }
  })

  it.each([
    ['apiKey', 'api-key', 'one-time-api-key', 'acknowledge-api-key'],
    ['webhookSecret', 'webhook-secret', 'one-time-webhook-secret', 'acknowledge-webhook-secret'],
  ])('locks the one-time %s dialog until copy and explicit acknowledgement', async (field, id, value, event) => {
    const wrapper = mountSection({
      oneTimeCredentials: { apiKey: '', webhookSecret: '', [field]: value },
    })
    const dialog = wrapper.get(`[data-one-time-${id}]`)

    expect(dialog.text()).toContain(value)
    expect(dialog.attributes('data-dialog-locked')).toBe('true')
    expect(dialog.find('[aria-label="Close"]').exists()).toBe(false)
    await dialog.trigger('keydown', { key: 'Escape' })
    expect(wrapper.find(`[data-one-time-${id}]`).exists()).toBe(true)
    expect(dialog.get('[data-acknowledge]').attributes('disabled')).toBeDefined()

    await dialog.get('[data-copy-one-time]').trigger('click')
    expect(navigator.clipboard.writeText).toHaveBeenCalledWith(value)
    await dialog.get('input[type="checkbox"]').setValue(true)
    expect(dialog.get('[data-acknowledge]').attributes('disabled')).toBeUndefined()

    await dialog.get('[data-acknowledge]').trigger('click')
    expect(wrapper.emitted(event)).toHaveLength(1)
  })

  it.each([
    ['apiKey', 'api-key', '[data-generate-api-key]', 'one-time-api-key'],
    ['webhookSecret', 'webhook-secret', '[data-generate-webhook-secret]', 'one-time-webhook-secret'],
  ])('focuses and keyboard-locks the one-time %s dialog, then restores its trigger', async (field, id, triggerSelector, value) => {
    const wrapper = mountSection()
    const trigger = wrapper.get(triggerSelector)
    trigger.element.focus()
    await trigger.trigger('click')
    await wrapper.setProps({
      oneTimeCredentials: { apiKey: '', webhookSecret: '', [field]: value },
    })
    await wrapper.vm.$nextTick()

    const dialog = wrapper.get(`[data-one-time-${id}]`)
    const copy = dialog.get('[data-copy-one-time]')
    expect(document.activeElement).toBe(copy.element)

    const tab = pressKey(copy.element, 'Tab')
    expect(tab.defaultPrevented).toBe(true)
    expect(document.activeElement).toBe(copy.element)

    const shiftTab = pressKey(copy.element, 'Tab', true)
    expect(shiftTab.defaultPrevented).toBe(true)
    expect(document.activeElement).toBe(copy.element)

    const escape = pressKey(copy.element, 'Escape')
    expect(escape.defaultPrevented).toBe(true)
    expect(document.activeElement).toBe(copy.element)

    await wrapper.setProps({ oneTimeCredentials: { apiKey: '', webhookSecret: '' } })
    await wrapper.vm.$nextTick()
    expect(document.activeElement).toBe(trigger.element)
  })

  it('traps focus between enabled controls before the API key is acknowledged', async () => {
    const wrapper = mountSection({
      oneTimeCredentials: { apiKey: 'one-time-api-key', webhookSecret: '' },
    })
    await wrapper.vm.$nextTick()
    const dialog = wrapper.get('[data-one-time-api-key]')
    const copy = dialog.get('[data-copy-one-time]')

    await copy.trigger('click')
    await wrapper.vm.$nextTick()
    const acknowledgement = dialog.get('input[type="checkbox"]')
    acknowledgement.element.focus()

    const tab = pressKey(acknowledgement.element, 'Tab')
    expect(tab.defaultPrevented).toBe(true)
    expect(document.activeElement).toBe(copy.element)

    const shiftTab = pressKey(copy.element, 'Tab', true)
    expect(shiftTab.defaultPrevented).toBe(true)
    expect(document.activeElement).toBe(acknowledgement.element)
  })

  it('keeps server errors local and forwards delivery history refresh and retry events', async () => {
    const wrapper = mountSection({
      webhookError: 'The saved destinations must use HTTPS.',
      history: {
        deliveries: [{
          id: 14,
          event_id: 'event-14',
          event_type: 'grant_created',
          destination_url: 'https://hooks.example.com/private',
          status: 'failed',
          attempt_count: 5,
          response_code: 500,
          created_at: '2026-07-23 10:00:00',
        }],
        loading: false,
        error: '',
        retryingId: null,
      },
    })

    expect(wrapper.get('[data-webhook-error]').text()).toContain('must use HTTPS')
    await wrapper.get('[data-refresh-history]').trigger('click')
    await wrapper.get('[data-retry-delivery]').trigger('click')
    expect(wrapper.emitted('refresh-history')).toHaveLength(1)
    expect(wrapper.emitted('retry-delivery')).toEqual([[14]])
  })

  it('opens focused connection guides from both section headings', async () => {
    const wrapper = mountSection()

    await wrapper.get('[data-open-webhook-guide]').trigger('click')
    expect(wrapper.get('[data-connection-guide]').text()).toContain('Connect a webhook receiver')
    expect(wrapper.get('[data-connection-guide]').text()).toContain('your other system')
    await wrapper.get('[data-close-guide]').trigger('click')

    await wrapper.get('[data-open-api-guide]').trigger('click')
    const guide = wrapper.get('[data-connection-guide]')
    expect(guide.text()).toContain('Connect to the Access API')
    expect(guide.text()).toContain('https://example.com/wp-json/fchub-memberships/v1/check-access')
  })
})
