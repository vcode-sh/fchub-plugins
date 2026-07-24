import { enableAutoUnmount, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import SettingsConnectionGuideDialog from '@/components/settings/SettingsConnectionGuideDialog.vue'

enableAutoUnmount(afterEach)

function mountGuide(topic) {
  return mount(SettingsConnectionGuideDialog, {
    attachTo: document.body,
    props: {
      topic,
      apiRoot: 'https://shop.example.com/wp-json/fchub-memberships/v1/',
    },
  })
}

describe('settings connection guide dialog', () => {
  beforeEach(() => {
    navigator.clipboard.writeText.mockClear()
  })

  it('shows the exact access endpoint, authentication boundary, and safe request shape', async () => {
    const wrapper = mountGuide('api')

    expect(wrapper.get('[role="dialog"]').attributes('aria-modal')).toBe('true')
    expect(wrapper.text()).toContain('Connect to the Access API')
    expect(wrapper.text()).toContain('https://shop.example.com/wp-json/fchub-memberships/v1/check-access')
    expect(wrapper.text()).toContain('X-API-Key')
    expect(wrapper.text()).toContain('email=member@example.com')
    expect(wrapper.text()).toContain('plan=pro')
    expect(wrapper.text()).toContain('WordPress Application Passwords')
    expect(wrapper.text()).toContain('Idempotency-Key')
    expect(wrapper.text()).toContain('cannot create or edit memberships')

    await wrapper.get('[data-copy-api-endpoint]').trigger('click')
    expect(navigator.clipboard.writeText).toHaveBeenCalledWith(
      'https://shop.example.com/wp-json/fchub-memberships/v1/check-access',
    )
  })

  it('explains that webhook destinations belong to the receiver and freezes the delivery contract', () => {
    const wrapper = mountGuide('webhooks')

    expect(wrapper.text()).toContain('Connect a webhook receiver')
    expect(wrapper.text()).toContain('your other system')
    expect(wrapper.text()).toContain('Add one endpoint')
    expect(wrapper.text()).toContain('No other endpoint shares it')
    expect(wrapper.text()).toContain('Send a one-shot test')
    expect(wrapper.text()).toContain('Activate the endpoint')
    expect(wrapper.text()).toContain('grant_created')
    expect(wrapper.text()).toContain('X-FCHub-Signature')
    expect(wrapper.text()).toContain('X-FCHub-Delivery')
    expect(wrapper.text()).toContain('at least once')
    expect(wrapper.text()).toContain('2xx')
  })

  it('closes from the close control and Escape without mutating settings', async () => {
    const wrapper = mountGuide('api')

    await wrapper.get('[data-close-guide]').trigger('click')
    expect(wrapper.emitted('close')).toHaveLength(1)

    await wrapper.get('[role="dialog"]').trigger('keydown', { key: 'Escape' })
    expect(wrapper.emitted('close')).toHaveLength(2)
  })
})
