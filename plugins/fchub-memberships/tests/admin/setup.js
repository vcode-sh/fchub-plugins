import { vi } from 'vitest'

vi.mock('vue-chartjs', () => ({
  Line: { template: '<div data-chart="line" />' },
  Doughnut: { template: '<div data-chart="doughnut" />' },
  Bar: { template: '<div data-chart="bar" />' },
}))

Object.defineProperty(window, 'matchMedia', {
  writable: true,
  value: (query) => ({
    matches: false,
    media: query,
    onchange: null,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    addListener: vi.fn(),
    removeListener: vi.fn(),
    dispatchEvent: vi.fn(),
  }),
})

Object.assign(window, {
  fchubMembershipsAdmin: {
    rest_url: 'https://example.com/wp-json/fchub-memberships/v1/',
    nonce: 'nonce',
    locale: 'en_US',
    date_format: 'Y-m-d',
    time_format: 'H:i',
    currency: {
      code: 'USD',
      symbol: '$',
      position: 'before',
      decimal_sep: '.',
      thousand_sep: ',',
    },
  },
})

Object.assign(navigator, {
  clipboard: {
    writeText: vi.fn().mockResolvedValue(undefined),
  },
})

class ResizeObserverMock {
  observe() {}
  unobserve() {}
  disconnect() {}
}

Object.assign(globalThis, {
  ResizeObserver: ResizeObserverMock,
})
