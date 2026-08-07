import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { withSetup, stubLocalStorage } from './helpers/withSetup.js';

const { apiMock } = vi.hoisted(() => ({ apiMock: vi.fn() }));

vi.mock('@/composables/useApi.js', () => ({
  useApi: () => ({ api: apiMock }),
}));

const { useMigration } = await import('@/composables/useMigration.js');

let teardown;

function mount() {
  const { result, unmount } = withSetup(() => useMigration());
  teardown = unmount;
  return result;
}

/** Error shaped the way useApi shapes them. */
function apiError(message, status, payload = null) {
  const err = new Error(message);
  err.status = status;
  err.payload = payload;
  return err;
}

beforeEach(() => {
  apiMock.mockReset();
});

afterEach(() => {
  if (teardown) teardown();
  teardown = null;
  vi.useRealTimers();
});

describe('probeRetrySupport', () => {
  it('says yes when the namespace index lists a /retry route', async () => {
    apiMock.mockResolvedValue({
      routes: {
        '/cartshift/v1': {},
        '/cartshift/v1/migrate': {},
        '/cartshift/v1/retry': {},
      },
    });

    const { state, actions } = mount();

    await expect(actions.probeRetrySupport()).resolves.toBe('yes');
    expect(state.retrySupport).toBe('yes');
    expect(state.retryUnavailable).toBeNull();
  });

  it('says no — with a reason — when the index has no /retry route', async () => {
    apiMock.mockResolvedValue({
      routes: { '/cartshift/v1': {}, '/cartshift/v1/migrate': {} },
    });

    const { state, actions } = mount();

    await expect(actions.probeRetrySupport()).resolves.toBe('no');
    expect(state.retryUnavailable).toMatch(/retry endpoint is not installed/);
  });

  it('does not mistake a route that merely contains "retry" for the endpoint', async () => {
    apiMock.mockResolvedValue({
      routes: { '/cartshift/v1/retry/(?P<id>[a-z]+)': {}, '/cartshift/v1/retryable': {} },
    });

    const { state, actions } = mount();

    await actions.probeRetrySupport();

    expect(state.retrySupport).toBe('no');
  });

  it('stays optimistic — unknown, not no — when the probe fails', async () => {
    apiMock.mockRejectedValue(apiError('offline', 0));

    const { state, actions } = mount();

    await expect(actions.probeRetrySupport()).resolves.toBe('unknown');
    expect(state.retryUnavailable).toBeNull();
  });

  it('stays unknown when the index answers without a routes map', async () => {
    apiMock.mockResolvedValue({ namespace: 'cartshift/v1' });

    const { state, actions } = mount();

    await actions.probeRetrySupport();

    expect(state.retrySupport).toBe('unknown');
  });

  it('caches a settled answer instead of probing again', async () => {
    apiMock.mockResolvedValue({ routes: { '/cartshift/v1/retry': {} } });

    const { actions } = mount();

    await actions.probeRetrySupport();
    await actions.probeRetrySupport();

    expect(apiMock).toHaveBeenCalledTimes(1);
  });

  it('re-probes while the answer is still unknown', async () => {
    apiMock.mockRejectedValue(apiError('offline', 0));

    const { actions } = mount();

    await actions.probeRetrySupport();
    await actions.probeRetrySupport();

    expect(apiMock).toHaveBeenCalledTimes(2);
  });
});

describe('startRetry request body', () => {
  beforeEach(() => {
    apiMock.mockImplementation(async (method, endpoint) => {
      if (method === 'POST' && endpoint === 'retry') {
        return { continue: false, entities: {}, entity_types: ['order'] };
      }
      return { status: 'completed', entities: {} };
    });
  });

  function bodyOf() {
    return apiMock.mock.calls.find((c) => c[0] === 'POST' && c[1] === 'retry')[2];
  }

  it('defaults to the error status and a live run', async () => {
    const { state, actions } = mount();
    state.progress = { migration_id: 'src-1' };

    await expect(actions.startRetry()).resolves.toBe(true);

    expect(bodyOf()).toEqual({
      migration_id: 'src-1',
      statuses: ['error'],
      dry_run: false,
    });
  });

  it('carries statuses, entity types, codes and the dry-run flag when given', async () => {
    const { state, actions } = mount();
    state.progress = { migration_id: 'src-1' };

    await actions.startRetry({
      statuses: ['error', 'warning'],
      entityTypes: ['order'],
      codes: ['missing_email'],
      dryRun: true,
    });

    expect(bodyOf()).toEqual({
      migration_id: 'src-1',
      statuses: ['error', 'warning'],
      dry_run: true,
      entity_types: ['order'],
      codes: ['missing_email'],
    });
  });

  it('omits empty optional arrays rather than sending an empty filter', async () => {
    const { state, actions } = mount();
    state.progress = { migration_id: 'src-1' };

    await actions.startRetry({ statuses: [], entityTypes: [], codes: [] });

    const body = bodyOf();

    expect(body.statuses).toEqual(['error']);
    expect(body).not.toHaveProperty('entity_types');
    expect(body).not.toHaveProperty('codes');
  });

  it('moves to the progress screen and marks retry supported on success', async () => {
    const { state, actions } = mount();
    state.progress = { migration_id: 'src-1' };
    state.finalized = true;
    state.finalizeStats = { anything: true };

    await actions.startRetry();

    expect(state.screen).toBe('progress');
    expect(state.retrySupport).toBe('yes');
    expect(state.retryUnavailable).toBeNull();
    expect(state.finalized).toBe(false);
    expect(state.finalizeStats).toBeNull();
    expect(state.selectedEntities).toEqual(['order']);
    expect(state.retrying).toBe(false);
  });
});

describe('startRetry failure branches', () => {
  it('refuses when the source run has no id', async () => {
    const { state, actions } = mount();
    state.progress = null;

    await expect(actions.startRetry()).resolves.toBe(false);
    expect(state.error).toMatch(/no ID/i);
    expect(apiMock).not.toHaveBeenCalled();
  });

  it.each([404, 501])('stops offering retry after HTTP %i', async (status) => {
    apiMock.mockRejectedValue(apiError('No route', status, null));

    const { state, actions } = mount();
    state.progress = { migration_id: 'src-1' };

    await expect(actions.startRetry()).resolves.toBe(false);

    expect(state.retrySupport).toBe('no');
    expect(state.retryUnavailable).toMatch(/retry endpoint is not installed/);
    expect(state.retrying).toBe(false);
    // A missing endpoint is not a migration error — it does not belong in the
    // red banner.
    expect(state.error).toBeNull();
  });

  it('treats a 409 as a recoverable in-flight run, not a failure', async () => {
    const progress = {
      migration_id: 'other',
      status: 'running',
      background_pending: false,
      entities: {},
    };

    apiMock.mockRejectedValue(apiError('Already running', 409, { progress }));

    const { state, actions } = mount();
    state.progress = { migration_id: 'src-1' };

    await expect(actions.startRetry()).resolves.toBe(false);

    expect(state.screen).toBe('progress');
    expect(state.progress).toEqual(progress);
    expect(state.interrupted).toBe(true);
    expect(state.background).toBe(false);
    expect(state.migrating).toBe(false);
    expect(state.retrySupport).toBe('unknown');
  });

  it('follows along instead of driving when the 409 run is background-pending', async () => {
    vi.useFakeTimers();

    const progress = {
      migration_id: 'other',
      status: 'running',
      background_pending: true,
      entities: {},
    };

    apiMock.mockRejectedValue(apiError('Already running', 409, { progress }));

    const { state, actions } = mount();
    state.progress = { migration_id: 'src-1' };

    await actions.startRetry();

    expect(state.background).toBe(true);
    expect(state.interrupted).toBe(false);
    expect(state.migrating).toBe(true);
  });

  it('surfaces an unclassified failure without disabling the control', async () => {
    apiMock.mockRejectedValue(apiError('Database is on fire', 500, null));

    const { state, actions } = mount();
    state.progress = { migration_id: 'src-1' };

    await expect(actions.startRetry()).resolves.toBe(false);

    expect(state.error).toBe('Database is on fire');
    expect(state.retrySupport).toBe('unknown');
    expect(state.retryUnavailable).toBeNull();
  });

  it('does not swallow a 409 that carries no progress payload', async () => {
    apiMock.mockRejectedValue(apiError('Conflict', 409, {}));

    const { state, actions } = mount();
    state.progress = { migration_id: 'src-1' };

    await expect(actions.startRetry()).resolves.toBe(false);
    expect(state.error).toBe('Conflict');
  });
});

describe('startMigration conflict handling', () => {
  it('lands a 409 on the progress screen with its recovery controls', async () => {
    const progress = { status: 'running', background_pending: false, entities: {} };

    apiMock.mockRejectedValue(apiError('Already running', 409, { progress }));

    const { state, actions } = mount();
    state.selectedEntities = ['product'];

    await actions.startMigration();

    expect(state.screen).toBe('progress');
    expect(state.interrupted).toBe(true);
    expect(state.migrating).toBe(false);
    expect(state.error).toBeNull();
    expect(state.batchError).toBeNull();
  });

  it('reports a genuine start failure as an error', async () => {
    apiMock.mockRejectedValue(apiError('Nope', 500, null));

    const { state, actions } = mount();
    state.selectedEntities = ['product'];

    await actions.startMigration();

    expect(state.error).toBe('Nope');
    expect(state.batchError).toBe(true);
    expect(state.migrating).toBe(false);
  });
});

describe('background availability flags', () => {
  it('adopts the server booleans and ignores anything that is not one', async () => {
    apiMock.mockImplementation(async (method, endpoint) => {
      if (method === 'POST' && endpoint === 'migrate') {
        return {
          continue: false,
          entities: {},
          background_available: true,
          background_pending: 'maybe',
        };
      }
      return { status: 'completed', entities: {} };
    });

    const { state, actions } = mount();
    state.selectedEntities = ['product'];

    await actions.startMigration();

    expect(state.backgroundAvailable).toBe(true);
    expect(state.backgroundPending).toBe(false);
  });
});

describe('bootstrap recovery', () => {
  it('flags an abandoned foreground run as interrupted', async () => {
    apiMock.mockImplementation(async (_method, endpoint) => {
      if (endpoint === 'progress') {
        return {
          status: 'running',
          background_pending: false,
          entity_types: ['product', 'order'],
          dry_run: true,
          entities: {},
        };
      }
      throw new Error(`Unexpected ${endpoint}`);
    });

    const { state, actions } = mount();

    await actions.bootstrap();

    expect(state.screen).toBe('progress');
    expect(state.interrupted).toBe(true);
    expect(state.migrating).toBe(false);
    expect(state.selectedEntities).toEqual(['product', 'order']);
    expect(state.dryRun).toBe(true);
  });

  it('follows a live background run rather than claiming it is interrupted', async () => {
    vi.useFakeTimers();

    apiMock.mockImplementation(async (_method, endpoint) => {
      if (endpoint === 'progress') {
        return { status: 'running', background_pending: true, entities: {} };
      }
      throw new Error(`Unexpected ${endpoint}`);
    });

    const { state, actions } = mount();

    await actions.bootstrap();

    expect(state.interrupted).toBe(false);
    expect(state.background).toBe(true);
    expect(state.migrating).toBe(true);
  });

  it('surfaces an unacknowledged finished run', async () => {
    const restore = stubLocalStorage();

    apiMock.mockImplementation(async (_method, endpoint) => {
      if (endpoint === 'progress') {
        return { status: 'completed', migration_id: 'done-1', entities: {} };
      }
      return {};
    });

    const { state, actions } = mount();

    await actions.bootstrap();

    expect(state.previousRun.migration_id).toBe('done-1');

    actions.dismissPreviousRun();

    expect(state.previousRun).toBeNull();
    expect(window.localStorage.getItem('cartshift_ack_migration')).toBe('done-1');

    restore();
  });

  it('stays quiet about a finished run the admin already acknowledged', async () => {
    const restore = stubLocalStorage();
    window.localStorage.setItem('cartshift_ack_migration', 'done-1');

    apiMock.mockImplementation(async (_method, endpoint) => {
      if (endpoint === 'progress') {
        return { status: 'completed', migration_id: 'done-1', entities: {} };
      }
      return {};
    });

    const { state, actions } = mount();

    await actions.bootstrap();

    expect(state.previousRun).toBeNull();

    restore();
  });

  it('survives a locked-down localStorage rather than failing to boot', async () => {
    const restore = stubLocalStorage({ throws: true });

    apiMock.mockImplementation(async (_method, endpoint) => {
      if (endpoint === 'progress') {
        return { status: 'completed', migration_id: 'done-1', entities: {} };
      }
      return {};
    });

    const { state, actions } = mount();

    await actions.bootstrap();

    // Unreadable ack means "not acknowledged", so the run is offered once more.
    expect(state.previousRun.migration_id).toBe('done-1');

    expect(() => actions.dismissPreviousRun()).not.toThrow();
    expect(state.previousRun).toBeNull();

    restore();
  });
});

describe('resetMigration', () => {
  it('separates a blocked reset from a genuine error', async () => {
    const progress = { status: 'running', entities: {} };

    apiMock.mockImplementation(async (method, endpoint) => {
      if (method === 'POST' && endpoint === 'reset') {
        throw apiError('Still running', 409, { message: 'A run is still alive', progress });
      }
      return {};
    });

    const { state, actions } = mount();

    await expect(actions.resetMigration()).resolves.toBeNull();

    expect(state.resetBlocked).toBe('A run is still alive');
    expect(state.error).toBeNull();
    expect(state.progress).toEqual(progress);
  });

  it('reports a non-409 reset failure as an error', async () => {
    apiMock.mockImplementation(async (method, endpoint) => {
      if (method === 'POST' && endpoint === 'reset') {
        throw apiError('Boom', 500, null);
      }
      return {};
    });

    const { state, actions } = mount();

    await actions.resetMigration();

    expect(state.error).toBe('Boom');
    expect(state.resetBlocked).toBeNull();
  });

  it('forwards the force flag and clears state on success', async () => {
    apiMock.mockImplementation(async (method, endpoint) => {
      if (method === 'POST' && endpoint === 'reset') {
        return { message: 'Cleared.' };
      }
      return {};
    });

    const { state, actions } = mount();
    state.retrySupport = 'yes';

    await actions.resetMigration(true);

    const body = apiMock.mock.calls.find((c) => c[1] === 'reset')[2];

    expect(body).toEqual({ force: true });
    expect(state.resetMessage).toBe('Cleared.');
    expect(state.screen).toBe('preflight');
    // Retry support describes the install, not the run — a reset must not
    // silently un-learn it.
    expect(state.retrySupport).toBe('yes');
  });
});
