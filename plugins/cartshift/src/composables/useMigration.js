import { reactive } from 'vue';
import { useApi } from './useApi.js';
import { usePolling } from './usePolling.js';

// Entity definitions with SINGULAR keys (matching backend).
export const ENTITIES = [
  { key: 'product', label: 'Products', dep: '' },
  { key: 'customer', label: 'Customers', dep: '' },
  { key: 'coupon', label: 'Coupons', dep: '' },
  { key: 'order', label: 'Orders', dep: 'Requires: Products, Customers' },
  {
    key: 'subscription',
    label: 'Subscriptions',
    dep: 'Requires: Products, Customers, Orders',
  },
];

export function useMigration() {
  const { api } = useApi();
  const { startPolling, stopPolling } = usePolling();

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
  });

  // ── Actions ──

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

    try {
      const data = await api('POST', 'migrate', {
        entity_types: state.selectedEntities,
        dry_run: state.dryRun,
      });

      state.progress = data;

      if (!data.continue) {
        migrationFinished();
      } else {
        // Always use direct REST batches from UI for real-time progress.
        // AS is used for WP-CLI / background processing, not the web UI.
        runNextBatch();
      }
    } catch (err) {
      state.error = err.message;
      state.batchError = true;
      state.migrating = false;
    }
  }

  async function runNextBatch() {
    try {
      const data = await api('POST', 'migrate/batch');
      state.progress = data;

      if (data.continue) {
        setTimeout(runNextBatch, 50);
      } else {
        migrationFinished();
      }
    } catch (err) {
      state.error = err.message;
      state.batchError = true;

      // Fetch latest progress so the UI is up to date.
      try {
        const data = await api('GET', 'progress');
        state.progress = data;
      } catch {
        // Network blip — nothing more we can do.
      }
    }
  }

  async function pollProgress() {
    try {
      const data = await api('GET', 'progress');
      state.progress = data;

      if (
        data.status === 'completed' ||
        data.status === 'failed' ||
        data.status === 'cancelled'
      ) {
        migrationFinished();
      }
    } catch {
      // Network blip — keep polling, don't blow up.
    }
  }

  async function migrationFinished() {
    stopPolling();
    state.migrating = false;

    try {
      const data = await api('GET', 'progress');
      state.progress = data;
    } catch {
      // Best-effort refresh.
    }
  }

  async function cancelMigration() {
    try {
      await api('POST', 'cancel');
      stopPolling();
      state.migrating = false;

      const data = await api('GET', 'progress');
      state.progress = data;
    } catch {
      // Swallow — cancel is best-effort.
    }
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
    runNextBatch();
  }

  function resetState() {
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
  }

  function backFromError() {
    state.screen = 'preflight';
    state.error = null;
    state.batchError = null;
    state.progress = null;
    state.preflight = null;
    state.migrating = false;
    state.finalized = false;
    state.finalizing = false;
    state.finalizeStats = null;
  }

  return {
    state,
    actions: {
      runPreflight,
      startMigration,
      cancelMigration,
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
