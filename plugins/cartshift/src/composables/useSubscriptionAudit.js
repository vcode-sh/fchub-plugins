import { reactive } from 'vue';
import { useApi } from './useApi.js';

/**
 * The read-only subscription audit.
 *
 * Everything here is a GET, with exactly one exception that is not audit at
 * all: preparing a package writes four strings into a CartShift option, and it
 * is labelled as a configuration write everywhere it appears — in this
 * composable, in the screen, and in the endpoint's own response. That
 * separation is the point of the mode, and it survives only if nothing quietly
 * blurs it.
 *
 * The endpoints are stateless: the source mode, source key and package path go
 * out with every request and nothing is remembered server-side between them. So
 * paging through records does not re-run the whole assessment, and re-running
 * the whole assessment after a mapping decision costs one request.
 */

const DEFAULT_PER_PAGE = 25;

function emptyRecords() {
  return {
    records: [],
    page: 1,
    per_page: DEFAULT_PER_PAGE,
    total: 0,
    filtered: 0,
  };
}

export function useSubscriptionAudit() {
  const { api } = useApi();

  const state = reactive({
    // Where the dataset comes from. `live` reads WooCommerce in this runtime;
    // `package` reads the private NDJSON file a source runtime exported.
    source: 'live',
    sourceKey: '',
    file: '',

    document: null,
    records: emptyRecords(),
    filters: { outcome: '', code: '' },

    loading: false,
    recordsLoading: false,
    error: null,

    // Set only by preparePackage(), and only to say what it wrote. Never set
    // by anything that calls itself audit.
    configurationWrite: null,
  });

  function sourceQuery() {
    const params = new URLSearchParams();

    params.set('source', state.source);

    if (state.sourceKey) {
      params.set('source_key', state.sourceKey);
    }

    if (state.file) {
      params.set('file', state.file);
    }

    return params;
  }

  function recordsQuery(page) {
    const params = sourceQuery();

    params.set('page', String(page));
    params.set('per_page', String(state.records.per_page || DEFAULT_PER_PAGE));

    if (state.filters.outcome) {
      params.set('outcome', state.filters.outcome);
    }

    if (state.filters.code) {
      params.set('code', state.filters.code);
    }

    return params.toString();
  }

  /**
   * Adopt whatever came back, without assuming it is well formed.
   *
   * A build of CartShift older than this screen answers the records route with
   * something else entirely, and a screen that then rendered `undefined.length`
   * would look like a bug in the audit rather than a version mismatch.
   */
  function adoptRecords(data) {
    state.records = {
      records: Array.isArray(data?.records) ? data.records : [],
      page: Number(data?.page) || 1,
      per_page: Number(data?.per_page) || DEFAULT_PER_PAGE,
      total: Number(data?.total) || 0,
      filtered: Number(data?.filtered) || 0,
    };
  }

  /**
   * Run the audit and fetch the first page of records.
   *
   * A failure leaves `document` null rather than half-populated: an audit that
   * could not read its source must not leave a screen full of zeroes, because
   * "no subscribers" and "this runtime cannot see the source" look identical
   * once they are both rendered as 0.
   */
  async function load() {
    state.loading = true;
    state.error = null;

    try {
      state.document = await api('GET', `subscriptions/audit?${sourceQuery().toString()}`);
    } catch (err) {
      state.document = null;
      state.error = err.message;
      state.loading = false;
      return false;
    }

    state.loading = false;

    await loadRecords(1);

    return true;
  }

  async function loadRecords(page) {
    state.recordsLoading = true;

    try {
      adoptRecords(await api('GET', `subscriptions/audit/records?${recordsQuery(page)}`));
    } catch (err) {
      state.error = err.message;
    } finally {
      state.recordsLoading = false;
    }
  }

  async function goToPage(page) {
    await loadRecords(Math.max(1, Number(page) || 1));
  }

  async function filterByCode(code) {
    state.filters.code = code || '';

    await loadRecords(1);
  }

  async function filterByOutcome(outcome) {
    state.filters.outcome = outcome || '';

    await loadRecords(1);
  }

  async function clearFilters() {
    state.filters.code = '';
    state.filters.outcome = '';

    await loadRecords(1);
  }

  /**
   * The one call here that is not a GET, and not audit.
   *
   * It writes the package descriptor — source key, private path, checksum,
   * selection fingerprint — so the mapping screen can read the package's
   * product candidates across requests. The audit is re-run afterwards because
   * the descriptor is what a package-mode audit resolves its file through.
   *
   * @return {Promise<boolean>} Whether the descriptor was written.
   */
  async function preparePackage(file) {
    state.error = null;
    state.configurationWrite = null;

    let response;

    try {
      response = await api('POST', 'subscriptions/packages/prepare', { file });
    } catch (err) {
      state.error = err.message;
      return false;
    }

    state.configurationWrite =
      'Prepared. That was a CartShift configuration write, not part of the audit: four strings ' +
      '(source key, private path, checksum, selection fingerprint) and nothing else.';

    state.source = 'package';
    state.file = response?.path || file;

    if (response?.source_key) {
      state.sourceKey = response.source_key;
    }

    await load();

    return true;
  }

  return {
    state,
    load,
    goToPage,
    filterByCode,
    filterByOutcome,
    clearFilters,
    preparePackage,
  };
}
