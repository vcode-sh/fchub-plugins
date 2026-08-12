import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { h, inject, ref } from 'vue';

const { apiMock, clipboardMock } = vi.hoisted(() => ({
  apiMock: vi.fn(),
  clipboardMock: vi.fn(),
}));

vi.mock('@/composables/useApi.js', () => ({
  useApi: () => ({ api: apiMock }),
}));

const { default: TransferSafetyScreen } = await import('@/components/TransferSafetyScreen.vue');

function mountScreen() {
  return mount(TransferSafetyScreen, {
    global: {
      provide: {
        config: { sourceKey: 'shop-alpha' },
        theme: { themeMode: ref('light'), changeTheme: vi.fn() },
      },
    },
  });
}

beforeEach(() => {
  apiMock.mockReset();
  clipboardMock.mockReset();
  Object.defineProperty(globalThis, 'localStorage', {
    configurable: true,
    value: {
      getItem: vi.fn(() => null),
      setItem: vi.fn(),
    },
  });
  Object.defineProperty(window, 'matchMedia', {
    configurable: true,
    value: vi.fn(() => ({
      matches: false,
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
    })),
  });
  Object.defineProperty(navigator, 'clipboard', {
    configurable: true,
    value: { writeText: clipboardMock },
  });
  apiMock.mockImplementation(async (method, endpoint) => {
    if (method === 'GET' && endpoint === 'preflight?operation=subscription') {
      return {
        ready: false,
        checks: {
          woo: { label: 'WooCommerce', severity: 'pass', message: 'Loaded.' },
          schema: { label: 'Schema', severity: 'fail', message: 'Upgrade required.' },
        },
      };
    }
    if (method === 'GET' && endpoint === 'subscriptions/audit?source=live&source_key=shop-alpha') {
      return {
        source: {
          source_key: 'shop-alpha',
          source_fingerprint: 'a'.repeat(64),
          selection_fingerprint: 'b'.repeat(64),
        },
        closure: {
          set_level: true,
          set_level_codes: ['dataset_foreign_source_key'],
          reason_codes: ['required_reference_missing'],
        },
        target: {
          ready: false,
          approval_fingerprint: 'c'.repeat(64),
          errors: [{ code: 'system_collection_unavailable' }],
        },
      };
    }
    throw new Error(`Unexpected request: ${method} ${endpoint}`);
  });
});

describe('TransferSafetyScreen', () => {
  it('is read-only and reports exact fingerprints, blocker codes, and v2 commands', async () => {
    const wrapper = mountScreen();
    await flushPromises();

    expect(apiMock.mock.calls).toEqual([
      ['GET', 'preflight?operation=subscription'],
      ['GET', 'subscriptions/audit?source=live&source_key=shop-alpha'],
    ]);
    expect(apiMock.mock.calls.every(([method]) => method === 'GET')).toBe(true);

    const text = wrapper.text();
    expect(text).toContain('dataset_foreign_source_key');
    expect(text).toContain('required_reference_missing');
    expect(text).toContain('system_collection_unavailable');
    expect(text).toContain('a'.repeat(64));
    expect(text).toContain('b'.repeat(64));
    expect(text).toContain('c'.repeat(64));
    expect(text).toContain('wp cartshift transfer compatibility --role=source --format=json');
    expect(text).toContain('wp cartshift transfer audit --role=source --source-key=shop-alpha --all-kinds --format=json');

    for (const forbidden of ['Stage', 'Reconcile', 'Promote', 'Rollback', 'Reset', 'Retry', 'Dry run']) {
      expect(wrapper.findAll('button').map((button) => button.text())).not.toContain(forbidden);
    }
  });

  it('copies the command bytes shown on screen and performs no mutation request', async () => {
    const wrapper = mountScreen();
    await flushPromises();

    await wrapper.find('[data-command="compatibility-source"] button').trigger('click');

    expect(clipboardMock).toHaveBeenCalledWith(
      'wp cartshift transfer compatibility --role=source --format=json'
    );
    expect(apiMock.mock.calls.every(([method]) => method === 'GET')).toBe(true);
  });

  it('the app mounts only the safety surface, not the legacy wizard', async () => {
    const { default: App } = await import('@/App.vue');
    const ThemeProbe = {
      setup() {
        const theme = inject('theme');

        return () => h('div', { 'data-test': 'transfer-safety' }, theme?.themeMode?.value ?? 'missing');
      },
    };
    const wrapper = mount(App, {
      global: {
        stubs: {
          TransferSafetyScreen: ThemeProbe,
        },
      },
    });

    expect(wrapper.find('[data-test="transfer-safety"]').exists()).toBe(true);
    expect(wrapper.find('[data-test="transfer-safety"]').text()).not.toBe('missing');
    expect(wrapper.findComponent({ name: 'PreflightScreen' }).exists()).toBe(false);
    expect(wrapper.findComponent({ name: 'ProgressScreen' }).exists()).toBe(false);
    expect(wrapper.findComponent({ name: 'ResultsScreen' }).exists()).toBe(false);
  });
});
