import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import WebhookSecretDialog from '@/components/settings/WebhookSecretDialog.vue'

describe('webhook secret dialog', () => {
  it('can always be closed and does not depend on clipboard permission', async () => {
    const wrapper = mount(WebhookSecretDialog, {
      props: { secret: 'one-time-secret', endpointName: 'CRM receiver' },
    })

    expect(wrapper.get('[data-close-secret-dialog]').attributes('disabled')).toBeUndefined()
    await wrapper.get('[data-close-secret-dialog]').trigger('click')
    expect(wrapper.emitted('close')).toHaveLength(1)
  })

  it('keeps the secret selectable when clipboard access fails', async () => {
    Object.assign(navigator, {
      clipboard: { writeText: vi.fn().mockRejectedValue(new Error('blocked')) },
    })
    const wrapper = mount(WebhookSecretDialog, {
      props: { secret: 'one-time-secret', endpointName: 'CRM receiver' },
    })

    await wrapper.get('[data-copy-secret]').trigger('click')

    expect(wrapper.get('[data-secret-value]').text()).toBe('one-time-secret')
    expect(wrapper.get('[role="alert"]').text()).toContain('Select the secret manually')
    expect(wrapper.get('[data-close-secret-dialog]').attributes('disabled')).toBeUndefined()
  })
})
