import { readFileSync } from 'node:fs'
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import WebhookEndpointCard from '@/components/settings/WebhookEndpointCard.vue'

const endpointCardSource = readFileSync(
  'resources/admin/components/settings/WebhookEndpointCard.vue',
  'utf8',
)

const endpoint = {
  id: 'we_1',
  name: 'CRM receiver',
  url: 'https://crm.example/webhook',
  status: 'paused',
  secret_configured: true,
  requires_rotation: false,
  last_test_status: 'succeeded',
  last_tested_at: '2026-07-24 08:00:00',
}

describe('webhook endpoint card', () => {
  it('keeps primary action labels readable on hover', () => {
    expect(endpointCardSource).toMatch(
      /\.endpoint-button\.is-primary:hover:not\(:disabled\)\s*\{[^}]*color:\s*#fff;/,
    )
  })

  it('makes the endpoint identity, state and next action obvious', () => {
    const wrapper = mount(WebhookEndpointCard, { props: { endpoint, busy: {} } })

    expect(wrapper.get('[data-endpoint-name]').text()).toBe('CRM receiver')
    expect(wrapper.get('[data-endpoint-url]').text()).toBe('https://crm.example/webhook')
    expect(wrapper.get('[data-endpoint-status]').text()).toBe('Paused')
    expect(wrapper.get('[data-endpoint-secret]').text()).toContain('Independent secret')
    expect(wrapper.get('[data-activate-endpoint]').exists()).toBe(true)
    expect(wrapper.get('[data-test-endpoint]').text()).toBe('Test endpoint')
  })

  it('emits focused actions for one endpoint', async () => {
    const wrapper = mount(WebhookEndpointCard, { props: { endpoint, busy: {} } })

    await wrapper.get('[data-test-endpoint]').trigger('click')
    await wrapper.get('[data-activate-endpoint]').trigger('click')
    await wrapper.get('[data-rotate-endpoint-secret]').trigger('click')
    await wrapper.get('[data-delete-endpoint]').trigger('click')

    expect(wrapper.emitted('test')).toEqual([['we_1']])
    expect(wrapper.emitted('activate')).toEqual([['we_1']])
    expect(wrapper.emitted('rotate-secret')).toEqual([['we_1']])
    expect(wrapper.emitted('delete')).toEqual([['we_1']])
  })

  it('makes legacy shared-secret migration explicit and offers rotation', () => {
    const wrapper = mount(WebhookEndpointCard, {
      props: {
        endpoint: { ...endpoint, requires_rotation: true },
        busy: {},
      },
    })

    expect(wrapper.get('[data-endpoint-secret]').text()).toContain('Shared legacy secret detected')
    expect(wrapper.get('[data-endpoint-secret]').text()).toContain('give this endpoint its own secret')
    expect(wrapper.get('[data-rotate-endpoint-secret]').text()).toBe('Rotate secret')
  })
})
