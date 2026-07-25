import { vi } from 'vitest'

// The one global AdminMenu injects. Everything the interface knows about the
// site arrives through it or through the REST envelope — there is no third
// source, and a test that invents one is testing a fiction.
Object.assign(window, {
  fchubAdmin: {
    rest_url: 'https://example.com/wp-json/fchub/v1/',
    nonce: 'test-nonce',
    admin_url: 'https://example.com/wp-admin/',
    version: '1.0.0',
    locale: 'en_US',
  },
})

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
