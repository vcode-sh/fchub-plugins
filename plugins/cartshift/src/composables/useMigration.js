import { reactive } from 'vue';
import { useApi } from './useApi.js';
import { usePolling } from './usePolling.js';

// Entity definitions with SINGULAR keys (matching backend).
export const ENTITIES = [
  { key: 'product', label: 'Products', dep: '' },
  { key: 'customer', label: 'Customers', dep: '' },
  // CouponMapper resolves product and category restrictions through the id-map,
  // so coupons migrated without products silently lose every restriction.
  { key: 'coupon', label: 'Coupons', dep: 'Requires: Products' },
  { key: 'order', label: 'Orders', dep: 'Requires: Products, Customers' },
  {
    key: 'subscription',
    label: 'Subscriptions',
    dep: 'Requires: Products, Customers, Orders',
  },
];

// Poll cadence for background runs, and how many still polls count as a stall.
const POLL_INTERVAL = 2000;
const STALL_POLLS = 15;

// Remembers which finished run the admin has already been shown.
const ACK_KEY = 'cartshift_ack_migration';

const FINISHED_STATUSES = ['completed', 'failed', 'cancelled'];

function readAck() {
  try {
    return window.localStorage.getItem(ACK_KEY);
  } catch {
    return null;
  }
}

function writeAck(migrationId) {
  try {
    window.localStorage.setItem(ACK_KEY, migrationId);
  } catch {
    // Private mode or a locked-down browser — nagging once more is survivable.
  }
}

/**
 * Cheap signature of the per-entity counters, used to tell a slow background
 * run apart from a stalled one.
 */
function progressFingerprint(data) {
  if (!data || !data.entities) return '';

  return Object.keys(data.entities)
    .map((key) => {
      const e = data.entities[key];
      return `${key}:${e.status}:${e.processed}:${e.skipped}:${e.errors}`;
    })
    .join('|');
}

export function useMigration() {
  const { api } = useApi();
  const { startPolling, stopPolling } = usePolling();

  let batchTimer = null;
  let lastFingerprint = '';

  const state = reactive({
    screen: 'preflight', // preflight | select | progress | results
    preflight: null,
    counts: null,
    selectedEntities: [],
    progress: null,
    error: null,
    migrating: false,
    dryRun: false,
    batchError: null,
    finalized: false,
    finalizing: false,
    finalizeStats: null,
    log: [],
    logPage: 1,
    logPages: 1,
    loading: false,

    // Background processing.
    useBackground: false, // what the admin asked for on the select screen
    background: false, // what the server actually did
    backgroundAvailable: false,
    backgroundPending: false,

    // Recovery from an abandoned run.
    interrupted: false, // status is 'running' but nothing is driving it
    stalledPolls: 0,
    stalled: false,
    resetBlocked: null,
    resetMessage: null,
    previousRun: null, // finished run the admin has not seen yet

    // Retry — re-running only the records that failed. The endpoint is newer
    // than this screen, so support is a tri-state: 'unknown' until something
    // tells us otherwise, and the UI stays optimistic while it is unknown.
    retrySupport: 'unknown', // unknown | yes | no
    retryUnavailable: null, // why the control is off, in words
    retrying: false,
  });

  // ── Internal helpers ──

  function clearBatchTimer() {
    if (batchTimer !== null) {
      clearTimeout(batchTimer);
      batchTimer = null;
    }
  }

  function stopEverything() {
    clearBatchTimer();
    stopPolling();
    state.migrating = false;
  }

  function adoptProgress(data) {
    state.progress = data;

    if (typeof data.background_available === 'boolean') {
      state.backgroundAvailable = data.background_available;
    }
    if (typeof data.background_pending === 'boolean') {
      state.backgroundPending = data.background_pending;
    }
  }

  // ── Actions ──

  /**
   * Boot the app. Before anything else, find out whether a migration is already
   * in flight — a run whose browser tab was closed leaves the server on
   * 'running' forever, and landing straight on the preflight screen hides both
   * the problem and every control that could fix it.
   */
  async function bootstrap() {
    state.loading = true;

    try {
      const data = await api('GET', 'progress');
      adoptProgress(data);

      if (data.status === 'running') {
        state.screen = 'progress';
        state.selectedEntities = data.entity_types || [];
        state.dryRun = !!data.dry_run;
        lastFingerprint = progressFingerprint(data);
        state.stalledPolls = 0;
        state.stalled = false;

        if (data.background_pending) {
          // Action Scheduler still has work queued: follow along, don't drive.
          state.background = true;
          state.migrating = true;
          state.interrupted = false;
          startPolling(pollProgress, POLL_INTERVAL);
        } else {
          // Nobody is driving this run. Offer resume, cancel or reset.
          state.background = false;
          state.migrating = false;
          state.interrupted = true;
        }

        state.loading = false;
        return;
      }

      state.progress = null;

      if (
        FINISHED_STATUSES.indexOf(data.status) !== -1 &&
        data.migration_id &&
        readAck() !== data.migration_id
      ) {
        state.previousRun = data;
      }
    } catch {
      // Progress is a courtesy at boot. Preflight is the real gate — carry on.
    }

    await runPreflight();
  }

  async function runPreflight() {
    state.loading = true;
    state.error = null;

    try {
      const [preflightData, countsData] = await Promise.all([
        api('GET', 'preflight'),
        api('GET', 'counts'),
      ]);
      state.preflight = preflightData;
      state.counts = countsData.counts || countsData;
    } catch (err) {
      state.error = err.message;
    } finally {
      state.loading = false;
    }
  }

  function autoIncludeDependencies(selected) {
    const set = new Set(selected);

    // Coupons carry product and category restrictions that are remapped through
    // the id-map — without products they migrate stripped of every restriction.
    if (set.has('coupon')) {
      set.add('product');
    }

    // Orders require products + customers
    if (set.has('order')) {
      set.add('product');
      set.add('customer');
    }

    // Subscriptions require products + customers + orders
    if (set.has('subscription')) {
      set.add('product');
      set.add('customer');
      set.add('order');
    }

    // Return in canonical dependency order
    const canonicalOrder = ['product', 'customer', 'coupon', 'order', 'subscription'];
    return canonicalOrder.filter((e) => set.has(e));
  }

  async function startMigration() {
    if (state.selectedEntities.length === 0) {
      state.error = 'Please select at least one entity type to migrate.';
      return;
    }

    state.migrating = true;
    state.selectedEntities = autoIncludeDependencies(state.selectedEntities);

    state.screen = 'progress';
    state.progress = null;
    state.error = null;
    state.batchError = null;
    state.interrupted = false;
    state.stalledPolls = 0;
    state.stalled = false;
    state.resetBlocked = null;
    state.resetMessage = null;

    try {
      const data = await api('POST', 'migrate', {
        entity_types: state.selectedEntities,
        dry_run: state.dryRun,
        background: state.useBackground,
      });

      driveRun(data);
    } catch (err) {
      state.migrating = false;

      // 409 means a run is already in flight or was abandoned. That is a
      // recoverable situation, not an error — show it with the resume, cancel
      // and reset controls rather than a dead end.
      if (err.status === 409 && err.payload?.progress) {
        const existing = err.payload.progress;

        adoptProgress(existing);
        state.background = !!existing.background_pending;
        state.interrupted = existing.status === 'running' && !existing.background_pending;

        if (state.background) {
          state.migrating = true;
          startPolling(pollProgress, POLL_INTERVAL);
        }

        return;
      }

      state.error = err.message;
      state.batchError = true;
    }
  }

  /**
   * Take the response from whichever endpoint started a run and drive it.
   *
   * /migrate and /retry answer with the same envelope on purpose, so a retry
   * gets the same batch loop, the same polling and the same finish handling as
   * any other migration. Nothing downstream needs to know which one it was.
   */
  function driveRun(data) {
    adoptProgress(data);
    state.background = !!data.background;
    lastFingerprint = progressFingerprint(data);

    if (!data.continue) {
      migrationFinished();
    } else if (state.background) {
      // Action Scheduler owns the run now — poll for progress and let the admin
      // close the tab if they like.
      startPolling(pollProgress, POLL_INTERVAL);
    } else {
      // Foreground: the browser drives the batches, which is what gives
      // real-time progress.
      runNextBatch();
    }
  }

  /**
   * Ask the REST namespace index whether /retry exists.
   *
   * Cheaper and quieter than finding out by firing a POST at a route that is not
   * there, and it lets the results screen decide whether to offer the control
   * before the admin clicks anything. A failed or unrecognised probe leaves the
   * answer at 'unknown', which the UI treats as "offer it and handle a 404".
   */
  async function probeRetrySupport() {
    if (state.retrySupport !== 'unknown') {
      return state.retrySupport;
    }

    try {
      const index = await api('GET', '');
      const routes = index && typeof index === 'object' ? index.routes : null;

      if (routes && typeof routes === 'object') {
        const found = Object.keys(routes).some((route) => /\/retry$/.test(route));

        state.retrySupport = found ? 'yes' : 'no';
        state.retryUnavailable = found
          ? null
          : 'This build of CartShift cannot re-run failed records — the retry endpoint is not installed. Update the plugin, or fix the causes and run a fresh migration.';
      }
    } catch {
      // The index is a courtesy. Leave it unknown and let the POST decide.
    }

    return state.retrySupport;
  }

  /**
   * Re-run only the records that did not make it.
   *
   * This starts a brand new migration — its own id, its own log, its own
   * rollback — that happens to carry `retry_of` pointing at the run it came
   * from. The source run is never modified.
   *
   * @param {{statuses?: string[], dryRun?: boolean, entityTypes?: string[], codes?: string[]}} options
   * @return {Promise<boolean>} Whether a run actually started.
   */
  async function startRetry(options = {}) {
    const sourceId = state.progress?.migration_id;

    if (!sourceId) {
      state.error = 'No migration to retry — this run has no ID.';
      return false;
    }

    const statuses =
      Array.isArray(options.statuses) && options.statuses.length > 0
        ? options.statuses
        : ['error'];

    const body = {
      migration_id: sourceId,
      statuses,
      dry_run: !!options.dryRun,
    };

    if (Array.isArray(options.entityTypes) && options.entityTypes.length > 0) {
      body.entity_types = options.entityTypes;
    }

    // Narrowing to one reason is a nicety, not a contract. A backend that does
    // not know the parameter drops it and retries everything matching the
    // statuses — which is why the dialog says so before you press the button.
    if (Array.isArray(options.codes) && options.codes.length > 0) {
      body.codes = options.codes;
    }

    state.retrying = true;
    state.error = null;
    state.batchError = null;

    try {
      const data = await api('POST', 'retry', body);

      state.retrySupport = 'yes';
      state.retryUnavailable = null;

      state.screen = 'progress';
      // Read the resolved flag off the response, not off what we asked for. The
      // two can differ, and when they do the response is the one telling the
      // truth about what the run is doing.
      state.dryRun = !!(data.dry_run ?? body.dry_run);
      state.selectedEntities = data.entity_types || state.selectedEntities;
      state.finalized = false;
      state.finalizeStats = null;
      state.interrupted = false;
      state.stalled = false;
      state.stalledPolls = 0;
      state.resetBlocked = null;
      state.resetMessage = null;
      state.migrating = true;

      driveRun(data);

      return true;
    } catch (err) {
      state.migrating = false;

      // The endpoint is not there. Say so once and stop offering the button,
      // rather than throwing something cryptic at the admin.
      if (err.status === 404 || err.status === 501) {
        state.retrySupport = 'no';
        state.retryUnavailable =
          'This build of CartShift cannot re-run failed records — the retry endpoint is not installed. Update the plugin, or fix the causes and run a fresh migration.';
        return false;
      }

      // Same 409 story as a normal start: a run is already in flight, which is
      // recoverable and belongs on the progress screen with its controls.
      if (err.status === 409 && err.payload?.progress) {
        const existing = err.payload.progress;

        state.screen = 'progress';
        adoptProgress(existing);
        state.background = !!existing.background_pending;
        state.interrupted = existing.status === 'running' && !existing.background_pending;

        if (state.background) {
          state.migrating = true;
          startPolling(pollProgress, POLL_INTERVAL);
        }

        return false;
      }

      state.error = err.message;
      return false;
    } finally {
      state.retrying = false;
    }
  }

  async function runNextBatch() {
    if (!state.migrating) {
      return;
    }

    try {
      const data = await api('POST', 'migrate/batch');
      adoptProgress(data);

      if (data.continue) {
        batchTimer = setTimeout(runNextBatch, 50);
      } else {
        migrationFinished();
      }
    } catch (err) {
      // 409 from the batch lock means another request got there first. Wait for
      // it rather than treating a healthy race as a failure.
      if (err.status === 409 && err.payload?.locked) {
        if (err.payload.progress) {
          adoptProgress(err.payload.progress);
        }
        batchTimer = setTimeout(runNextBatch, 1000);
        return;
      }

      state.error = err.message;
      state.batchError = true;
      state.migrating = false;

      // Fetch latest progress so the UI is up to date.
      try {
        const data = await api('GET', 'progress');
        adoptProgress(data);
      } catch {
        // Network blip — nothing more we can do.
      }
    }
  }

  /**
   * Poll loop for background runs.
   */
  async function pollProgress() {
    try {
      const data = await api('GET', 'progress');
      adoptProgress(data);

      if (data.status !== 'running') {
        migrationFinished();
        return;
      }

      const fingerprint = progressFingerprint(data);

      if (fingerprint !== lastFingerprint) {
        lastFingerprint = fingerprint;
        state.stalledPolls = 0;
      } else if (!data.background_pending) {
        state.stalledPolls += 1;
      }

      state.stalled = state.stalledPolls >= STALL_POLLS;
    } catch {
      // Network blip — keep polling, don't blow up.
    }
  }

  /**
   * Re-enter the foreground batch loop. The orchestrator resumes from the
   * persisted current_entity_index and current_offset, so there is nothing to
   * restore beyond restarting the loop.
   */
  function resumeMigration() {
    stopPolling();
    clearBatchTimer();

    state.error = null;
    state.batchError = null;
    state.interrupted = false;
    state.stalled = false;
    state.stalledPolls = 0;
    state.background = false;
    state.migrating = true;
    state.screen = 'progress';

    runNextBatch();
  }

  async function migrationFinished() {
    stopEverything();
    state.interrupted = false;
    state.stalled = false;

    try {
      const data = await api('GET', 'progress');
      adoptProgress(data);
    } catch {
      // Best-effort refresh.
    }
  }

  async function cancelMigration() {
    try {
      await api('POST', 'cancel');
      stopEverything();
      state.interrupted = false;
      state.stalled = false;

      const data = await api('GET', 'progress');
      adoptProgress(data);
    } catch {
      // Swallow — cancel is best-effort.
    }
  }

  /**
   * Clear the stored migration state so a new run can start.
   *
   * Deliberately not rollback: the migrated records and their id-map entries
   * survive. Rollback is the button that deletes them.
   */
  async function resetMigration(force = false) {
    state.loading = true;
    state.resetBlocked = null;

    try {
      const data = await api('POST', 'reset', { force: !!force });

      stopEverything();
      resetState();
      state.resetMessage = data.message || 'Migration state cleared.';

      await runPreflight();

      return data;
    } catch (err) {
      if (err.status === 409) {
        state.resetBlocked = err.payload?.message || err.message;
        if (err.payload?.progress) {
          adoptProgress(err.payload.progress);
        }
      } else {
        state.error = err.message;
      }

      return null;
    } finally {
      state.loading = false;
    }
  }

  function viewPreviousRun() {
    const previous = state.previousRun;
    if (!previous) return;

    adoptProgress(previous);
    dismissPreviousRun();
    state.screen = 'results';
  }

  function dismissPreviousRun() {
    const id = state.previousRun?.migration_id;
    if (id) {
      writeAck(id);
    }
    state.previousRun = null;
  }

  async function finalize() {
    if (!state.progress || !state.progress.migration_id) {
      state.error = 'No migration ID found. Cannot finalize.';
      return;
    }

    state.finalizing = true;

    try {
      const data = await api('POST', 'finalize', {
        migration_id: state.progress.migration_id,
      });
      state.finalized = true;
      state.finalizeStats = data.stats || data;
    } catch (err) {
      state.error = 'Finalization failed: ' + err.message;
    } finally {
      state.finalizing = false;
    }
  }

  async function rollback() {
    state.loading = true;

    try {
      const data = await api('POST', 'rollback', {
        migration_id: state.progress ? state.progress.migration_id : null,
      });

      resetState();
      return data;
    } catch (err) {
      state.error = 'Rollback failed: ' + err.message;
      throw err;
    } finally {
      state.loading = false;
    }
  }

  async function loadLog(page) {
    if (page !== undefined) {
      state.logPage = page;
    }

    try {
      const data = await api('GET', `log?page=${state.logPage}`);
      state.log = data.data || data.entries || [];
      state.logPages = Math.ceil((data.total || 0) / (data.per_page || 50));
    } catch {
      state.log = [];
    }
  }

  function goToScreen(screen) {
    state.screen = screen;
  }

  function retryBatch() {
    state.error = null;
    state.batchError = null;
    state.migrating = true;
    runNextBatch();
  }

  function resetState() {
    stopEverything();

    state.screen = 'preflight';
    state.preflight = null;
    state.counts = null;
    state.progress = null;
    state.log = [];
    state.selectedEntities = [];
    state.migrating = false;
    state.error = null;
    state.batchError = null;
    state.finalized = false;
    state.finalizing = false;
    state.finalizeStats = null;
    state.dryRun = false;
    state.logPage = 1;
    state.logPages = 1;
    state.loading = false;
    state.useBackground = false;
    state.background = false;
    state.backgroundPending = false;
    state.interrupted = false;
    state.stalledPolls = 0;
    state.stalled = false;
    state.resetBlocked = null;
    state.resetMessage = null;
    state.previousRun = null;
    state.retrying = false;
    // retrySupport is a property of the install, not of the run — keep it.
    lastFingerprint = '';
  }

  function backFromError() {
    stopEverything();

    state.screen = 'preflight';
    state.error = null;
    state.batchError = null;
    state.progress = null;
    state.preflight = null;
    state.migrating = false;
    state.finalized = false;
    state.finalizing = false;
    state.finalizeStats = null;
    state.interrupted = false;
    state.stalled = false;
    state.stalledPolls = 0;
  }

  return {
    state,
    actions: {
      bootstrap,
      runPreflight,
      startMigration,
      startRetry,
      probeRetrySupport,
      cancelMigration,
      resumeMigration,
      resetMigration,
      viewPreviousRun,
      dismissPreviousRun,
      finalize,
      rollback,
      loadLog,
      goToScreen,
      retryBatch,
      resetState,
      backFromError,
    },
  };
}
