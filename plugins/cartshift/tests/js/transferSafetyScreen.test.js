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

const MIGRATION_PREFLIGHT = 'preflight?operation=migration';
const SUBSCRIPTION_PREFLIGHT = 'preflight?operation=subscription_dataset';
const LIVE_AUDIT = 'subscriptions/audit?source=live&source_key=shop-alpha';

function mountScreen(sourceKey = 'shop-alpha') {
  return mount(TransferSafetyScreen, {
    global: {
      provide: {
        config: { sourceKey },
        theme: { themeMode: ref('light'), changeTheme: vi.fn() },
      },
    },
  });
}

/**
 * The general preflight, exactly as `PreflightCheck::run('migration')` shapes it.
 *
 * `wc_subscriptions` is severity `pass` whether or not the add-on is installed —
 * it is an optional dependency and the check says so with `optional: true`. The
 * screen has to read `active`, not severity, or an absent add-on reads as a
 * healthy subscription runtime.
 */
function migrationPreflight({ wcsActive, ready = true, extraChecks = {} }) {
  return {
    operation: 'migration',
    ready,
    checks: {
      woocommerce: { label: 'WooCommerce', severity: 'pass', pass: true, message: 'WooCommerce 11.0.1 is active.' },
      fluentcart: { label: 'FluentCart', severity: 'pass', pass: true, message: 'FluentCart 1.6.1 is active.' },
      order_storage: { label: 'Order Storage (HPOS)', severity: 'pass', pass: true, hpos: true },
      wc_subscriptions: {
        label: 'WooCommerce Subscriptions',
        severity: 'pass',
        pass: true,
        optional: true,
        active: wcsActive,
        version: wcsActive ? '7.9.0' : null,
      },
      ...extraChecks,
    },
  };
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
});

/**
 * A runtime that answers the real endpoints and refuses everything else.
 *
 * The refusals are the point. `subscriptions/audit?source=live` throws the 409
 * the controller really returns on a runtime with no `wcs_get_subscriptions()`,
 * and any preflight operation that is not one of `PreflightCheck::OPERATIONS`
 * throws the 400 the controller really returns — so a screen that asks for an
 * invented operation fails here instead of being handed a helpful stub.
 */
function serve({ wcsActive, audit = null, preflight = {} }) {
  apiMock.mockImplementation(async (method, endpoint) => {
    if (method !== 'GET') {
      throw new Error(`This screen reads. It attempted ${method} ${endpoint}.`);
    }

    if (endpoint === MIGRATION_PREFLIGHT) {
      return migrationPreflight({ wcsActive, ...preflight });
    }

    if (endpoint === SUBSCRIPTION_PREFLIGHT) {
      return { operation: 'subscription_dataset', ready: true, checks: {} };
    }

    if (endpoint === LIVE_AUDIT) {
      if (!wcsActive) {
        throw new Error(
          'This runtime cannot read subscriptions: wcs_get_subscriptions, wcs_get_subscription missing.'
        );
      }

      return audit;
    }

    if (endpoint.startsWith('preflight?operation=')) {
      throw new Error(
        `Unknown preflight operation "${endpoint.slice('preflight?operation='.length)}". `
          + 'Use one of: migration, subscription_dataset.'
      );
    }

    throw new Error(`Unexpected request: ${method} ${endpoint}`);
  });
}

const BLOCKED_AUDIT = {
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

describe('TransferSafetyScreen without WooCommerce Subscriptions', () => {
  beforeEach(() => serve({ wcsActive: false }));

  it('runs the general migration preflight and asks for no live subscription audit', async () => {
    const wrapper = mountScreen();
    await flushPromises();

    expect(apiMock.mock.calls).toEqual([['GET', MIGRATION_PREFLIGHT]]);
    expect(wrapper.find('.notice-error').exists()).toBe(false);
  });

  it('reports the missing add-on as a skipped capability, not as a blocked transfer', async () => {
    const wrapper = mountScreen();
    await flushPromises();

    expect(wrapper.text()).toContain(
      'WooCommerce Subscriptions is not active. Subscription migration will be skipped.'
    );
    expect(wrapper.text()).toContain('Read-only checks are ready.');
    expect(wrapper.text()).not.toContain('Transfer is blocked.');
    expect(wrapper.text()).toContain('no_reported_blockers');
  });

  it('keeps every non-subscription command on the screen', async () => {
    const wrapper = mountScreen();
    await flushPromises();

    for (const id of ['compatibility-source', 'compatibility-target', 'audit', 'export', 'validate-package', 'prepare']) {
      expect(wrapper.find(`[data-command="${id}"]`).exists()).toBe(true);
    }
  });

  /**
   * A shop whose generic migration really is blocked must still say so. The fix
   * relaxes the subscription gate, not the HPOS one — otherwise "not blocked by
   * a missing add-on" quietly becomes "never blocked".
   */
  it('still reports a genuine migration blocker', async () => {
    serve({
      wcsActive: false,
      preflight: {
        ready: false,
        extraChecks: {
          order_storage: { label: 'Order Storage (HPOS)', severity: 'fail', pass: false, hpos: false },
        },
      },
    });

    const wrapper = mountScreen();
    await flushPromises();

    expect(wrapper.text()).toContain('Transfer is blocked.');
    expect(wrapper.text()).toContain('order_storage');
  });
});

describe('TransferSafetyScreen with WooCommerce Subscriptions active', () => {
  beforeEach(() => serve({ wcsActive: true, audit: BLOCKED_AUDIT }));

  it('asks for the subscription_dataset operation and the live audit', async () => {
    const wrapper = mountScreen();
    await flushPromises();

    expect(apiMock.mock.calls).toEqual([
      ['GET', MIGRATION_PREFLIGHT],
      ['GET', SUBSCRIPTION_PREFLIGHT],
      ['GET', LIVE_AUDIT],
    ]);
    expect(wrapper.find('[data-test="wcs-unavailable"]').exists()).toBe(false);
  });

  it('reports exact fingerprints, blocker codes, and v2 commands', async () => {
    const wrapper = mountScreen();
    await flushPromises();

    const text = wrapper.text();
    expect(text).toContain('Transfer is blocked.');
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
});

/**
 * `local` is the shipped default (`AdminMenu.php` filters
 * `cartshift/transfer/source_key` from it), and `SourceIdentity` refuses that
 * literal — so on an unconfigured site every `--source-key=local` command the
 * screen printed errored the moment it was pasted, beside a blocker code saying
 * exactly that. An offer withdrawn in the same paragraph is not an offer.
 */
describe('TransferSafetyScreen on a site with no transfer source key', () => {
  beforeEach(() => serve({ wcsActive: false }));

  it('offers no command it knows will be refused', async () => {
    const wrapper = mountScreen('local');
    await flushPromises();

    expect(wrapper.find('[data-command="audit"]').exists()).toBe(false);
    expect(wrapper.find('[data-command="export"]').exists()).toBe(false);

    // Scoped to what is copyable. The note below the list quotes the flag on
    // purpose, to say which one is refused — that is the explanation, not an
    // offer, and a page-wide assertion would forbid explaining anything.
    const copyable = wrapper.findAll('[data-command] code').map((node) => node.text());

    expect(copyable).not.toHaveLength(0);
    expect(copyable.some((command) => command.includes('--source-key'))).toBe(false);
  });

  it('says why, and names the filter that fixes it', async () => {
    const wrapper = mountScreen('local');
    await flushPromises();

    const note = wrapper.find('[data-test="source-key-unset"]');

    expect(note.exists()).toBe(true);
    expect(note.text()).toContain('retired_local_source_namespace');
    expect(note.text()).toContain('cartshift/transfer/source_key');
  });

  it('keeps every command that needs no source key, and stops calling this a blocker', async () => {
    const wrapper = mountScreen('local');
    await flushPromises();

    for (const id of ['compatibility-source', 'compatibility-target', 'validate-package', 'prepare']) {
      expect(wrapper.find(`[data-command="${id}"]`).exists()).toBe(true);
    }

    // The screen used to list this beside real blockers while also reporting
    // "ready" — a label and a list disagreeing on the same screen.
    expect(wrapper.text()).toContain('no_reported_blockers');
    expect(wrapper.text()).toContain('Read-only checks are ready.');
  });

  it('restores both commands as soon as the site has a name', async () => {
    const wrapper = mountScreen('shop-alpha');
    await flushPromises();

    expect(wrapper.find('[data-command="audit"]').exists()).toBe(true);
    expect(wrapper.find('[data-command="export"]').exists()).toBe(true);
    expect(wrapper.find('[data-test="source-key-unset"]').exists()).toBe(false);
    expect(wrapper.text()).toContain('--source-key=shop-alpha');
  });
});

describe('TransferSafetyScreen mounting', () => {
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
