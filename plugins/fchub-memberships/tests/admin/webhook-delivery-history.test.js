import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import WebhookDeliveryHistory from '@/components/settings/WebhookDeliveryHistory.vue'

function delivery(overrides = {}) {
  return {
    id: 17,
    event_id: '8f14b86a-b3ec-4fe5-a8f7-8a01b694bf15',
    event_type: 'grant_created',
    destination_url: 'https://hooks.example.com/private/path?token=not-for-dom',
    status: 'failed',
    attempt_count: 7,
    response_code: 500,
    last_attempt_at: '2026-07-23 10:00:00',
    created_at: '2026-07-22 10:00:00',
    response_body: 'payload-sentinel',
    webhook_secret: 'secret-sentinel',
    ...overrides,
  }
}

describe('webhook delivery history', () => {
  afterEach(() => vi.useRealTimers())

  it('renders only the latest twenty compact public delivery projections', () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-07-23T12:00:00Z'))
    const deliveries = Array.from({ length: 21 }, (_, index) => delivery({
      id: index + 1,
      event_id: `event-${index + 1}`,
      status: ['pending', 'processing', 'retrying', 'succeeded', 'failed'][index % 5],
      response_code: index % 2 === 0 ? 204 : null,
    }))
    const wrapper = mount(WebhookDeliveryHistory, { props: { deliveries } })

    expect(wrapper.findAll('[data-delivery-row]')).toHaveLength(20)
    expect(wrapper.text()).toContain('Grant created')
    expect(wrapper.text()).toContain('event-1')
    expect(wrapper.text()).not.toContain('event-21')
    expect(wrapper.text()).toContain('hooks.example.com')
    expect(wrapper.text()).not.toContain('/private/path')
    expect(wrapper.text()).not.toContain('not-for-dom')
    expect(wrapper.text()).not.toContain('payload-sentinel')
    expect(wrapper.text()).not.toContain('secret-sentinel')
    expect(wrapper.text()).toContain('Attempt 7')
    expect(wrapper.text()).toContain('HTTP 204')
    expect(wrapper.text()).toContain('2h ago')
    expect(wrapper.text()).not.toContain('of 21')
    expect(wrapper.text()).not.toContain('Next page')
  })

  it('maps durable states to the frozen human labels', () => {
    const wrapper = mount(WebhookDeliveryHistory, {
      props: {
        deliveries: [
          delivery({ id: 1, status: 'pending' }),
          delivery({ id: 2, status: 'processing' }),
          delivery({ id: 3, status: 'retrying' }),
          delivery({ id: 4, status: 'succeeded' }),
          delivery({ id: 5, status: 'failed' }),
        ],
      },
    })

    expect(wrapper.findAll('.webhook-delivery-status').map((node) => node.text())).toEqual([
      'Pending', 'Pending', 'Retrying', 'Delivered', 'Failed',
    ])
  })

  it('emits retry only for failed deliveries and exposes the in-flight state', async () => {
    const wrapper = mount(WebhookDeliveryHistory, {
      props: {
        deliveries: [
          delivery({ id: 17, status: 'failed' }),
          delivery({ id: 18, status: 'succeeded' }),
        ],
        retryingId: 17,
      },
    })

    const buttons = wrapper.findAll('[data-retry-delivery]')
    expect(buttons).toHaveLength(1)
    expect(buttons[0].attributes('disabled')).toBeDefined()
    expect(buttons[0].text()).toBe('Retrying…')

    await wrapper.setProps({ retryingId: null })
    await wrapper.get('[data-retry-delivery]').trigger('click')
    expect(wrapper.emitted('retry')).toEqual([[17]])
  })

  it('renders loading, error and empty states with a refresh action', async () => {
    const loading = mount(WebhookDeliveryHistory, { props: { loading: true } })
    expect(loading.get('[role="status"]').text()).toContain('Loading delivery history')

    const failed = mount(WebhookDeliveryHistory, { props: { error: 'History is temporarily unavailable.' } })
    expect(failed.get('[role="alert"]').text()).toContain('History is temporarily unavailable.')
    await failed.get('[data-refresh-history]').trigger('click')
    expect(failed.emitted('refresh')).toHaveLength(1)

    const empty = mount(WebhookDeliveryHistory)
    expect(empty.get('[role="status"]').text()).toContain('No webhook deliveries yet')
    await empty.get('[data-refresh-history]').trigger('click')
    expect(empty.emitted('refresh')).toHaveLength(1)
  })

  it('keeps long hosts and event identifiers mobile-safe inside the component', () => {
    const wrapper = mount(WebhookDeliveryHistory, {
      props: {
        deliveries: [delivery({
          event_id: 'very-long-event-identifier-that-must-wrap-without-page-overflow',
          destination_url: 'https://a-very-long-webhook-hostname-with-many-segments.example.com/path',
        })],
      },
    })

    expect(wrapper.get('.webhook-delivery-history').classes()).toContain('webhook-delivery-history')
    expect(wrapper.get('.webhook-delivery-event')).toBeTruthy()
    expect(wrapper.get('.webhook-delivery-destination')).toBeTruthy()
  })
})
