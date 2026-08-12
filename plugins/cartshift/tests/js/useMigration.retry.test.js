import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { withSetup } from './helpers/withSetup.js';

const { apiMock } = vi.hoisted(() => ({ apiMock: vi.fn() }));

vi.mock('@/composables/useApi.js', () => ({
  useApi: () => ({ api: apiMock }),
}));

const { useMigration } = await import('@/composables/useMigration.js');
let unmount;

function mount() {
  const mounted = withSetup(() => useMigration());
  unmount = mounted.unmount;
  return mounted.result;
}

beforeEach(() => {
  apiMock.mockReset();
});
afterEach(() => {
  if (unmount) unmount();
  unmount = null;
});

describe('retired retry and recovery UI', () => {
  it('never probes a route whose v1 callback is deliberately closed', async () => {
    const { state, actions } = mount();

    await expect(actions.probeRetrySupport()).resolves.toBe('no');
    expect(state.retrySupport).toBe('no');
    expect(state.retryUnavailable).toContain('wp cartshift transfer stage');
    expect(apiMock).not.toHaveBeenCalled();
  });

  it('cannot POST retry even with a plausible legacy run and every old option', async () => {
    const { state, actions } = mount();
    state.progress = { migration_id: 'legacy-run' };

    await expect(
      actions.startRetry({ statuses: ['error'], entityTypes: ['order'], codes: ['failure'], dryRun: true })
    ).resolves.toBe(false);

    expect(state.error).toContain('legacy_generic_migration_closed');
    expect(state.error).toContain('wp cartshift transfer stage');
    expect(apiMock).not.toHaveBeenCalled();
  });

  it.each([
    ['cancelMigration', 'wp cartshift transfer status'],
    ['resetMigration', 'wp cartshift transfer status'],
    ['finalize', 'wp cartshift transfer promote'],
    ['rollback', 'wp cartshift transfer rollback'],
  ])('%s refuses locally and points to %s', async (method, command) => {
    const { state, actions } = mount();

    await actions[method](true);

    expect(state.error).toContain(command);
    expect(apiMock).not.toHaveBeenCalled();
  });

  it('bootstrap reads preflight and counts only, never legacy progress or mutation state', async () => {
    apiMock.mockImplementation(async (method, endpoint) => {
      if (method === 'GET' && endpoint === 'preflight') return { ready: true, checks: {} };
      if (method === 'GET' && endpoint === 'counts') return { counts: { product: 3 } };
      throw new Error(`Unexpected request: ${method} ${endpoint}`);
    });
    const { state, actions } = mount();

    await actions.bootstrap();

    expect(apiMock.mock.calls).toEqual([
      ['GET', 'preflight'],
      ['GET', 'counts'],
    ]);
    expect(state.counts).toEqual({ product: 3 });
  });
});
