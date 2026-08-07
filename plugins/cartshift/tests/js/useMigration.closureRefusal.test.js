import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { useMigration } from '@/composables/useMigration.js';
import { withSetup, fakeConfig, fakeResponse } from './helpers/withSetup.js';

/**
 * The 422 closure refusal, end to end through the real useApi.
 *
 * Every other useMigration test mocks `@/composables/useApi.js` and hands the
 * composable a hand-built Error. That is fine for branch logic and useless for
 * payload *shape*: the refusal branch read `err.payload.data.code` for three
 * commits while its test built a payload with a nested `data` key that useApi
 * cannot produce, so the test passed whether the code was right or wrong.
 *
 * So this file mocks nothing below useMigration. `fetch` returns the exact body
 * MigrationController::migrate() sends — `['data' => ['code' => …]]` with status
 * 422 — and useApi does its own unwrapping on the way through. If the shape the
 * composable reads and the shape the controller writes ever diverge again, this
 * fails.
 */

let unmount;

function mount() {
  const mounted = withSetup(() => useMigration(), { config: fakeConfig() });
  unmount = mounted.unmount;
  return mounted.result;
}

/** Byte-for-byte the envelope MigrationController::migrate() returns on refusal. */
function refusalResponse() {
  return fakeResponse({
    status: 422,
    body: {
      data: {
        code: 'scope_closure_too_large',
        message:
          'This selection is too large to migrate in one run. Narrow it down — pick fewer '
          + 'products or customers, or migrate everything from a date instead.',
        scope: { mode: 'explicit', product_ids: [1, 2, 3] },
      },
    },
  });
}

beforeEach(() => {
  globalThis.fetch = vi.fn();
});

afterEach(() => {
  if (unmount) unmount();
  unmount = null;
});

describe('the closure refusal, through the real useApi', () => {
  it('sends the owner back to the selection with the server’s reason', async () => {
    globalThis.fetch.mockResolvedValue(refusalResponse());

    const { state, actions } = mount();
    state.selectedEntities = ['order'];

    await actions.startMigration();

    expect(state.screen).toBe('select');
    expect(state.error).toContain('too large to migrate in one run');
    expect(state.migrating).toBe(false);
    // Not a batch failure: there is no run to retry a batch of. Leaving this
    // true is what put a "Retry batch" control on a progress screen for a
    // migration that never started.
    expect(state.batchError).toBe(false);
  });

  it('leaves a 422 without that code on the generic error path', async () => {
    // The refusal branch is keyed on the code, not on the status — an
    // `entity_types is required` 422 has no code and must not be mistaken for
    // a closure refusal.
    globalThis.fetch.mockResolvedValue(
      fakeResponse({
        status: 422,
        body: { data: { message: 'No valid entity types specified.' } },
      }),
    );

    const { state, actions } = mount();
    state.selectedEntities = ['order'];

    await actions.startMigration();

    expect(state.screen).toBe('progress');
    expect(state.error).toBe('No valid entity types specified.');
    expect(state.batchError).toBe(true);
  });
});
