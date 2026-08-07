import { createApp } from 'vue';

/**
 * Run a composable inside a real component instance.
 *
 * The composables under test call `inject()` and `onUnmounted()`, both of which
 * need an active instance. Mounting a throwaway component is the smallest way to
 * give them one without dragging a whole screen component into the test.
 *
 * @param {Function} composable Zero-argument factory to invoke in setup().
 * @param {Object} provides Values to provide on the app, keyed by inject key.
 * @return {{result: *, unmount: Function}}
 */
export function withSetup(composable, provides = {}) {
  let result;

  const app = createApp({
    setup() {
      result = composable();
      return () => null;
    },
  });

  for (const key of Object.keys(provides)) {
    app.provide(key, provides[key]);
  }

  app.mount(document.createElement('div'));

  return { result, unmount: () => app.unmount() };
}

/**
 * Minimal stand-in for the WordPress-injected config object.
 */
export function fakeConfig(overrides = {}) {
  return {
    restUrl: 'https://example.test/wp-json/cartshift/v1/',
    nonce: 'test-nonce',
    ...overrides,
  };
}

/**
 * Give `window.localStorage` an in-memory implementation.
 *
 * The test environment does not always supply one, and the ack bookkeeping in
 * useMigration reads it through a try/catch — so without a stub the tests would
 * silently exercise the "no storage" path and prove nothing.
 *
 * Pass a throwing store to exercise that path deliberately.
 *
 * @param {{throws?: boolean}} options
 * @return {Function} Restores whatever was there before.
 */
export function stubLocalStorage({ throws = false } = {}) {
  const original = Object.getOwnPropertyDescriptor(window, 'localStorage');
  const store = new Map();

  const impl = throws
    ? {
        getItem() {
          throw new Error('Access denied');
        },
        setItem() {
          throw new Error('Access denied');
        },
        removeItem() {
          throw new Error('Access denied');
        },
      }
    : {
        getItem: (key) => (store.has(key) ? store.get(key) : null),
        setItem: (key, value) => store.set(key, String(value)),
        removeItem: (key) => store.delete(key),
      };

  Object.defineProperty(window, 'localStorage', {
    configurable: true,
    value: impl,
  });

  return () => {
    if (original) {
      Object.defineProperty(window, 'localStorage', original);
    } else {
      delete window.localStorage;
    }
  };
}

/**
 * Build a fetch Response-alike. Only the bits useApi actually touches.
 */
export function fakeResponse({ status = 200, contentType = 'application/json', body = {}, text = null }) {
  return {
    ok: status >= 200 && status < 300,
    status,
    headers: {
      get: (name) => (name.toLowerCase() === 'content-type' ? contentType : null),
    },
    json: async () => body,
    text: async () => (text !== null ? text : JSON.stringify(body)),
  };
}
