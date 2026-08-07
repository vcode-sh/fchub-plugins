import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { ref } from 'vue';
import { withSetup } from './helpers/withSetup.js';

// Integration coverage for the {silent: true} on-mount prime: SelectScreen's
// onMounted() calls the *real* useMigration().actions.refreshPreview(), so a
// stubbed action (as in selectScreen.test.js) cannot prove the failure
// actually stays quiet. This file wires the real composable through to a
// mocked useApi, the same way scopeSerialisation.test.js does for
// useMigration in isolation.

const { apiMock } = vi.hoisted(() => ({ apiMock: vi.fn() }));

vi.mock('@/composables/useApi.js', () => ({ useApi: () => ({ api: apiMock }) }));

const { useMigration } = await import('@/composables/useMigration.js');
const { default: SelectScreen } = await import('@/components/SelectScreen.vue');

function apiError(message, status) {
  const err = new Error(message);
  err.status = status;
  return err;
}

function fakeTheme() {
  return { themeMode: ref('light'), changeTheme: vi.fn() };
}

let migrationTeardown;
let wrapper;

/**
 * A real useMigration() instance, seeded past bootstrap()/preflight so
 * SelectScreen can mount directly against it, the way App.vue would once
 * the owner reaches this screen.
 */
function mountWithRealMigration() {
  const { result: migration, unmount } = withSetup(() => useMigration());
  migrationTeardown = unmount;

  migration.state.screen = 'select';
  migration.state.selectedEntities = ['product'];
  migration.state.counts = { product: 1 };
  migration.state.preflight = { checks: { wc_subscriptions: { active: true } } };
  migration.state.backgroundAvailable = true;

  wrapper = mount(SelectScreen, {
    global: { provide: { migration, theme: fakeTheme() } },
  });

  return migration;
}

beforeEach(() => {
  apiMock.mockReset();
});

afterEach(() => {
  if (wrapper) wrapper.unmount();
  wrapper = null;
  if (migrationTeardown) migrationTeardown();
  migrationTeardown = null;
});

describe('SelectScreen on-mount preview prime', () => {
  it('fails quietly on a 500: no banner, receipt simply unprimed', async () => {
    apiMock.mockImplementation(async (method, endpoint) => {
      if (endpoint === 'preview') {
        throw apiError('Internal Server Error', 500);
      }
      return {};
    });

    const migration = mountWithRealMigration();
    await flushPromises();

    expect(migration.state.error).toBeNull();
    expect(migration.state.preview).toBeNull();
    expect(wrapper.find('[role="alert"]').exists()).toBe(false);
  });

  it('still flips previewSupport to "no" on a 404 — silent must not swallow feature detection', async () => {
    apiMock.mockImplementation(async (method, endpoint) => {
      if (endpoint === 'preview') {
        throw apiError('No route was found', 404);
      }
      return {};
    });

    const migration = mountWithRealMigration();
    await flushPromises();

    expect(migration.state.previewSupport).toBe('no');
    expect(migration.state.error).toBeNull();
    expect(wrapper.find('[role="alert"]').exists()).toBe(false);
  });

  it('an owner-initiated refresh that fails still shows the banner — silence does not leak past the mount call', async () => {
    // First call (the silent on-mount prime) succeeds, so nothing is primed
    // yet to confuse the assertion; the second call, driven by an owner edit
    // through the debounced watch, is the one that fails.
    let calls = 0;

    apiMock.mockImplementation(async (method, endpoint) => {
      if (endpoint === 'preview') {
        calls += 1;
        if (calls === 1) {
          return { counts: {}, consequences: [], closure: { products: 0, customers: 0 }, too_large: false };
        }
        throw apiError('Internal Server Error', 500);
      }
      return {};
    });

    const migration = mountWithRealMigration();
    await flushPromises();

    expect(migration.state.error).toBeNull();

    // Drive the owner-initiated path directly — the debounced watch in
    // SelectScreen ultimately calls the same un-silenced action.
    await migration.actions.refreshPreview();
    await flushPromises();
    await wrapper.vm.$nextTick();

    expect(migration.state.error).toBe('Internal Server Error');
    expect(wrapper.find('[role="alert"]').exists()).toBe(true);
    expect(wrapper.text()).toContain('Internal Server Error');
  });
});
