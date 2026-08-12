import { reactive } from 'vue';
import { useApi } from './useApi.js';

/** Retained for read-only previews rendered by legacy components and tests. */
export const ENTITIES = [
  { key: 'product', label: 'Products', dep: '' },
  { key: 'customer', label: 'Customers', dep: '' },
  { key: 'coupon', label: 'Coupons', dep: 'Requires: Products' },
  { key: 'order', label: 'Orders', dep: 'Requires: Products, Customers' },
  { key: 'subscription', label: 'Subscriptions', dep: 'Requires: Products, Customers, Orders' },
];

export function serializeScope(scope) {
  return {
    mode: scope.mode,
    since: scope.mode === 'since' ? scope.since : null,
    product_ids: scope.mode === 'explicit' ? scope.products.map((product) => Number(product.id)) : [],
    customer_ids:
      scope.mode === 'explicit'
        ? scope.customers.filter((customer) => customer.kind === 'registered').map((customer) => Number(customer.id))
        : [],
    guest_emails:
      scope.mode === 'explicit'
        ? scope.customers.filter((customer) => customer.kind === 'guest').map((customer) => String(customer.id))
        : [],
    include_orders_for_products: scope.mode === 'explicit' && !!scope.includeOrdersForProducts,
  };
}

export function resolveEntityDependencies(selected) {
  const requested = new Set(selected);
  const known = new Set(ENTITIES.map((entity) => entity.key));
  for (const key of [...requested]) if (!known.has(key)) requested.delete(key);
  if (requested.has('coupon')) requested.add('product');
  if (requested.has('order')) {
    requested.add('product');
    requested.add('customer');
  }
  if (requested.has('subscription')) {
    requested.add('product');
    requested.add('customer');
    requested.add('order');
  }
  return ['product', 'customer', 'coupon', 'order', 'subscription'].filter((key) => requested.has(key));
}

function emptyScope() {
  return {
    mode: 'everything',
    since: null,
    products: [],
    customers: [],
    includeOrdersForProducts: false,
  };
}

export function useMigration() {
  const { api } = useApi();
  const state = reactive({
    screen: 'preflight',
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
    useBackground: false,
    background: false,
    backgroundAvailable: false,
    backgroundPending: false,
    interrupted: false,
    stalledPolls: 0,
    stalled: false,
    resetBlocked: null,
    resetMessage: null,
    previousRun: null,
    retrySupport: 'no',
    retryUnavailable: 'Legacy retry is closed. Use `wp cartshift transfer stage` with a prepared descriptor.',
    retrying: false,
    mapFocus: null,
    scope: emptyScope(),
    preview: null,
    previewLoading: false,
    previewSupport: 'unknown',
  });

  function refuseLegacyWrite(nextCommand) {
    state.migrating = false;
    state.retrying = false;
    state.finalizing = false;
    state.error = `legacy_generic_migration_closed: continue with \`${nextCommand}\`.`;
    return false;
  }

  async function bootstrap() {
    await runPreflight();
  }

  async function runPreflight() {
    state.loading = true;
    state.error = null;
    try {
      const [preflight, counts] = await Promise.all([api('GET', 'preflight'), api('GET', 'counts')]);
      state.preflight = preflight;
      state.counts = counts.counts || counts;
    } catch (error) {
      state.error = error.message;
    } finally {
      state.loading = false;
    }
  }

  function setScopeMode(mode) {
    state.scope.mode = mode;
  }

  async function refreshPreview(options = {}) {
    const entityTypes = resolveEntityDependencies(state.selectedEntities);
    if (entityTypes.length === 0) {
      state.preview = null;
      state.previewLoading = false;
      return;
    }
    state.previewLoading = true;
    try {
      state.preview = await api('POST', 'preview', {
        entity_types: entityTypes,
        scope: serializeScope(state.scope),
      });
      state.previewSupport = 'yes';
    } catch (error) {
      state.preview = null;
      if (error.status === 404 || error.status === 501) state.previewSupport = 'no';
      else if (options.silent !== true) state.error = error.message;
    } finally {
      state.previewLoading = false;
    }
  }

  async function applyRemedy(remedy) {
    if (!Array.isArray(remedy?.product_ids)) return;
    state.scope.mode = 'explicit';
    const known = new Set(state.scope.products.map((product) => String(product.id)));
    for (const id of remedy.product_ids) {
      if (!known.has(String(id))) state.scope.products.push({ id });
      known.add(String(id));
    }
    await refreshPreview();
  }

  async function startMigration() {
    if (state.selectedEntities.length === 0) {
      state.error = 'Please select at least one entity type to migrate.';
      return false;
    }
    state.selectedEntities = resolveEntityDependencies(state.selectedEntities);
    return refuseLegacyWrite('wp cartshift transfer prepare');
  }

  function advanceFromSelect() {
    return refuseLegacyWrite('wp cartshift transfer prepare');
  }

  async function startRetry() {
    state.retrySupport = 'no';
    return refuseLegacyWrite('wp cartshift transfer stage');
  }

  async function probeRetrySupport() {
    state.retrySupport = 'no';
    return 'no';
  }

  async function cancelMigration() {
    return refuseLegacyWrite('wp cartshift transfer status');
  }

  function resumeMigration() {
    return refuseLegacyWrite('wp cartshift transfer stage');
  }

  async function resetMigration() {
    refuseLegacyWrite('wp cartshift transfer status');
    return null;
  }

  async function finalize() {
    return refuseLegacyWrite('wp cartshift transfer promote');
  }

  async function rollback() {
    return refuseLegacyWrite('wp cartshift transfer rollback');
  }

  async function loadLog(page) {
    if (page !== undefined) state.logPage = page;
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

  function goToMapping(focus) {
    state.mapFocus = focus && Number(focus.wc_id) > 0 ? { ...focus, wc_id: Number(focus.wc_id) } : null;
    state.screen = 'map';
  }

  function clearMapFocus() {
    state.mapFocus = null;
  }

  function retryBatch() {
    return refuseLegacyWrite('wp cartshift transfer stage');
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
    state.mapFocus = null;
    state.scope = emptyScope();
    state.preview = null;
  }

  function backFromError() {
    resetState();
  }

  function viewPreviousRun() {
    state.screen = 'results';
  }

  function dismissPreviousRun() {
    state.previousRun = null;
  }

  return {
    state,
    actions: {
      bootstrap,
      runPreflight,
      setScopeMode,
      refreshPreview,
      applyRemedy,
      startMigration,
      advanceFromSelect,
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
      goToMapping,
      clearMapFocus,
      retryBatch,
      resetState,
      backFromError,
    },
  };
}
