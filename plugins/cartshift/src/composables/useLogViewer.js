import { reactive, computed } from 'vue';
import { useApi } from './useApi.js';

/**
 * Field names an error code might arrive under, in preference order. The
 * backend lifts `error_code` to the top level of every row and leaves it null
 * where nothing classified the entry — which is every row written before the
 * taxonomy landed, and every migrator not yet wired up. The other spellings are
 * belt and braces for older or third-party payloads.
 */
const CODE_KEYS = ['error_code', 'errorCode', 'code', 'reason_code', 'reasonCode'];

/** Field names a human-readable summary of a code might arrive under. */
const LABEL_KEYS = ['label', 'title', 'summary', 'reason_label', 'description'];

/** Field names a "what to do about it" string might arrive under. */
const HINT_KEYS = ['hint', 'remedy', 'fix', 'resolution', 'suggestion', 'help', 'advice'];

/**
 * Keys the stats endpoint might use for a per-code breakdown. `code_breakdown`
 * comes first deliberately: it is a real array carrying label, hint, severity
 * and category, all translated server-side. `codes` is the same data as a bare
 * `{code: count}` map and is the fallback.
 */
const BREAKDOWN_KEYS = ['code_breakdown', 'codes', 'by_code', 'error_codes', 'breakdown', 'reasons'];

/** Severities the backend uses to say how worried the admin should be. */
const SEVERITIES = ['info', 'warning', 'error'];

/**
 * Reasons where re-running the same record can plausibly end differently.
 *
 * The test is not "did it fail" but "does the outcome depend on something the
 * admin can change" — a dependency that was not migrated yet, or source data
 * they can fix in WooCommerce. Everything left out is deterministic given the
 * same source record: retrying `unsupported_product_type` produces another
 * `unsupported_product_type`, and offering a button that can only fail again is
 * worse than offering nothing.
 *
 * `already_migrated` and `already_exists_in_fluentcart` are deliberately absent:
 * they are not failures at all, and retrying them is a no-op with a progress bar.
 *
 * The backend may eventually say so itself — a `retryable` boolean on a
 * breakdown row wins over this list, which is only here because it does not yet.
 */
const RETRYABLE_CODES = [
  'customer_not_found',
  'product_not_mapped',
  'variation_not_mapped',
  'coupon_code_missing',
  'coupon_code_too_long',
  'coupon_code_collision',
  'order_has_no_items',
  'empty_product_name',
  'missing_email',
  'term_creation_failed',
  'product_creation_failed',
  'dry_run_validation_failed',
  'unexpected_exception',
  'migration_aborted',
];

/**
 * Is this breakdown row worth a retry button?
 *
 * Trusts the server when it has an opinion, falls back to the local list when it
 * does not, and says no to anything unclassified — an unknown code is not
 * evidence that re-running will help.
 */
export function isRetryableRow(row) {
  if (!row || typeof row !== 'object') return false;

  if (typeof row.retryable === 'boolean') {
    return row.retryable;
  }

  return RETRYABLE_CODES.includes(row.code);
}

function firstString(source, keys) {
  if (!source || typeof source !== 'object') return null;

  for (const key of keys) {
    const value = source[key];
    if (typeof value === 'string' && value.trim() !== '') {
      return value.trim();
    }
  }

  return null;
}

/**
 * Pull the machine-readable code off a log entry, wherever it happens to live.
 * Returns null when the entry predates the taxonomy — which is the normal case
 * for any log written before that work landed.
 */
export function extractCode(entry) {
  if (!entry || typeof entry !== 'object') return null;

  const top = firstString(entry, CODE_KEYS);
  if (top) return top;

  const details = entry.details;
  if (details && typeof details === 'object' && !Array.isArray(details)) {
    const nested = firstString(details, CODE_KEYS);
    if (nested) return nested;

    // One level deeper, for payloads that nest under details.error.
    for (const key of ['error', 'reason', 'context']) {
      const child = details[key];
      if (child && typeof child === 'object' && !Array.isArray(child)) {
        const deep = firstString(child, CODE_KEYS);
        if (deep) return deep;
      }
    }
  }

  return null;
}

/**
 * Last-resort label for a code with no server-supplied descriptor: turn
 * `customer_not_found` into `Customer not found`. Never used when the backend
 * sends a label — its vocabulary is translated and this one is not.
 */
export function humaniseCode(code) {
  if (!code) return 'Unclassified';

  const words = String(code).replace(/[._-]+/g, ' ').trim().toLowerCase();
  if (words === '') return 'Unclassified';

  return words.charAt(0).toUpperCase() + words.slice(1);
}

function severityFrom(source) {
  const raw = firstString(source, ['severity']);
  if (!raw) return null;

  const value = raw.toLowerCase();
  return SEVERITIES.includes(value) ? value : null;
}

/**
 * Normalise whatever shape the stats endpoint uses for a per-code breakdown
 * into a plain array. Accepts an array of objects, a `{code: count}` map, and a
 * `{code: {count, label, hint}}` map. Anything else is ignored rather than
 * rendered as gibberish.
 *
 * @return {Array<{code: string, count: number, label: string, hint: string|null, severity: string|null, category: string|null, retryable: boolean|null}>}
 */
export function normaliseBreakdown(raw) {
  if (!raw) return [];

  const rows = [];

  const push = (code, count, source) => {
    const cleanCode = typeof code === 'string' ? code.trim() : '';
    const cleanCount = Number(count);

    if (cleanCode === '' || !Number.isFinite(cleanCount) || cleanCount <= 0) {
      return;
    }

    rows.push({
      code: cleanCode,
      count: cleanCount,
      label: firstString(source, LABEL_KEYS) || humaniseCode(cleanCode),
      hint: firstString(source, HINT_KEYS),
      severity: severityFrom(source),
      category: firstString(source, ['category']),
      // Only ever a boolean when the server said so. null means "no opinion",
      // which is what isRetryableRow falls back on.
      retryable: source && typeof source.retryable === 'boolean' ? source.retryable : null,
    });
  };

  if (Array.isArray(raw)) {
    for (const row of raw) {
      if (!row || typeof row !== 'object') continue;
      push(firstString(row, CODE_KEYS), row.count ?? row.total ?? row.n, row);
    }
  } else if (typeof raw === 'object') {
    for (const code of Object.keys(raw)) {
      const value = raw[code];

      if (typeof value === 'number' || typeof value === 'string') {
        push(code, value, null);
      } else if (value && typeof value === 'object') {
        push(code, value.count ?? value.total ?? value.n, value);
      }
    }
  }

  return rows.sort((a, b) => b.count - a.count);
}

/** Find a per-code breakdown anywhere in the stats payload. */
function breakdownFromStats(stats) {
  if (!stats || typeof stats !== 'object') return [];

  for (const key of BREAKDOWN_KEYS) {
    const rows = normaliseBreakdown(stats[key]);
    if (rows.length > 0) return rows;
  }

  return [];
}

/** Build a breakdown from entries already loaded, when the server offers none. */
function breakdownFromEntries(entries) {
  const tally = new Map();

  for (const entry of entries) {
    const code = extractCode(entry);
    if (!code) continue;

    const existing = tally.get(code);
    if (existing) {
      existing.count += 1;
    } else {
      tally.set(code, {
        code,
        count: 1,
        label: firstString(entry.details, LABEL_KEYS) || humaniseCode(code),
        hint: firstString(entry.details, HINT_KEYS),
        severity: severityFrom(entry.details),
        category: firstString(entry.details, ['category']),
        retryable:
          entry.details && typeof entry.details.retryable === 'boolean'
            ? entry.details.retryable
            : null,
      });
    }
  }

  return [...tally.values()].sort((a, b) => b.count - a.count);
}

export function useLogViewer() {
  const { api } = useApi();

  const state = reactive({
    entries: [],
    allEntries: [],
    loading: false,
    loadingMore: false,
    hasMore: false,
    page: 1,
    perPage: 50,
    total: 0,
    statusFilter: '',
    codeFilter: '',
    // Whether the server actually honoured the code filter. If it ignored the
    // parameter we fall back to filtering what is loaded, and say so.
    codeFilterServerSide: true,
    searchQuery: '',
    // `warning` is a status the migrators genuinely write — a subscription whose
    // product never mapped, a coupon migrated with its discount stripped — and
    // it is neither an error nor a skip. Zero-filled so the cards can read it
    // unconditionally.
    stats: { success: 0, skipped: 0, warning: 0, error: 0, 'dry-run': 0, total: 0 },
    migrationId: null,
  });

  /**
   * The grouped "why" summary. Prefers the server's breakdown, which covers the
   * whole run; falls back to counting what has been loaded, which at least beats
   * scrolling 4,000 rows. Empty when nothing carries a code at all — in which
   * case the flat list is all there is, and that is fine.
   */
  const codeBreakdown = computed(() => {
    const fromStats = breakdownFromStats(state.stats);
    if (fromStats.length > 0) {
      return { rows: fromStats, derived: false, covers: state.stats.total || 0 };
    }

    const fromEntries = breakdownFromEntries(state.allEntries);
    if (fromEntries.length > 0) {
      return { rows: fromEntries, derived: true, covers: state.allEntries.length };
    }

    return { rows: [], derived: false, covers: 0 };
  });

  const hasBreakdown = computed(() => codeBreakdown.value.rows.length > 0);

  /**
   * code → descriptor, so a row can show the translated label instead of a raw
   * slug. Log rows only carry the bare code; the vocabulary lives in stats.
   */
  const codeDescriptors = computed(() => {
    const map = {};

    for (const row of codeBreakdown.value.rows) {
      map[row.code] = row;
    }

    return map;
  });

  const filteredEntries = computed(() => {
    let entries = state.entries;

    // Only filter client-side when the server did not do it for us.
    if (state.codeFilter && !state.codeFilterServerSide) {
      entries = entries.filter((entry) => extractCode(entry) === state.codeFilter);
    }

    if (!state.searchQuery) return entries;

    const q = state.searchQuery.toLowerCase();

    return entries.filter((entry) => {
      const message = (entry.message || '').toLowerCase();
      const wcId = String(entry.wc_id || '').toLowerCase();
      const entityType = (entry.entity_type || '').toLowerCase();
      const code = (extractCode(entry) || '').toLowerCase();

      return message.includes(q) || wcId.includes(q) || entityType.includes(q) || code.includes(q);
    });
  });

  async function loadStats(migrationId) {
    try {
      const data = await api('GET', `log/stats?migration_id=${encodeURIComponent(migrationId)}`);
      if (data && typeof data === 'object') {
        // Merged, not replaced: the server zero-fills success, skipped and error
        // but sends `warning` only when the run wrote one, and the cards read
        // every key unconditionally.
        state.stats = { success: 0, skipped: 0, warning: 0, error: 0, 'dry-run': 0, total: 0, ...data };
      }
    } catch {
      // Stats are best-effort.
    }
  }

  /**
   * Did the server honour `code=`? If any returned entry carries a different
   * code, it plainly did not.
   */
  function detectCodeFilterSupport(entries) {
    if (!state.codeFilter) {
      state.codeFilterServerSide = true;
      return;
    }

    if (entries.length === 0) {
      // Nothing to judge by. Assume it worked; an empty list is honest either way.
      state.codeFilterServerSide = true;
      return;
    }

    const mismatch = entries.some((entry) => {
      const code = extractCode(entry);
      return code !== null && code !== state.codeFilter;
    });

    state.codeFilterServerSide = !mismatch;
  }

  async function fetchPage(page) {
    const data = await api('GET', buildLogUrl(page));

    return {
      entries: data?.data || data?.entries || [],
      total: Number(data?.total) || 0,
      perPage: Number(data?.per_page) || state.perPage,
    };
  }

  async function reload() {
    state.page = 1;
    state.entries = [];
    state.allEntries = [];
    state.loading = true;

    try {
      const { entries, total, perPage } = await fetchPage(1);

      state.entries = entries;
      state.allEntries = entries.slice();
      state.total = total;
      state.perPage = perPage;
      state.hasMore = entries.length < total;

      detectCodeFilterSupport(entries);
    } catch {
      state.entries = [];
      state.allEntries = [];
      state.hasMore = false;
    } finally {
      state.loading = false;
    }
  }

  async function loadInitial(migrationId) {
    state.migrationId = migrationId;
    state.searchQuery = '';
    state.codeFilter = '';
    state.codeFilterServerSide = true;
    state.loading = true;

    await Promise.all([reload(), loadStats(migrationId)]);
  }

  async function loadMore() {
    if (state.loadingMore || !state.hasMore) return;

    state.loadingMore = true;
    state.page += 1;

    try {
      const { entries, total } = await fetchPage(state.page);

      state.entries.push(...entries);
      state.allEntries.push(...entries);
      state.total = total || state.total;
      state.hasMore = state.entries.length < state.total;
    } catch {
      // Revert page on failure.
      state.page -= 1;
    } finally {
      state.loadingMore = false;
    }
  }

  async function setFilter(status) {
    state.statusFilter = status;
    state.searchQuery = '';
    state.codeFilter = '';
    state.codeFilterServerSide = true;

    await reload();
  }

  async function setCodeFilter(code) {
    state.codeFilter = code || '';
    state.searchQuery = '';

    await reload();
  }

  function setSearch(query) {
    state.searchQuery = query;
  }

  function setPerPage(perPage) {
    state.perPage = perPage;

    if (state.migrationId) {
      reload();
    }
  }

  async function exportCsv() {
    if (!state.migrationId) return;

    const allEntries = [];
    let page = 1;
    let hasMore = true;

    while (hasMore) {
      const params = [`page=${page}`, 'per_page=100', `migration_id=${encodeURIComponent(state.migrationId)}`];
      if (state.statusFilter) {
        params.push(`status=${encodeURIComponent(state.statusFilter)}`);
      }

      try {
        const data = await api('GET', `log?${params.join('&')}`);
        const entries = data?.data || data?.entries || [];
        allEntries.push(...entries);

        const total = Number(data?.total) || 0;
        hasMore = entries.length > 0 && allEntries.length < total;
        page += 1;
      } catch {
        hasMore = false;
      }
    }

    const headers = ['ID', 'Entity Type', 'WC ID', 'Status', 'Code', 'Message', 'Details', 'Created At'];
    const rows = allEntries.map((entry) => [
      entry.id,
      entry.entity_type,
      entry.wc_id || '',
      entry.status,
      extractCode(entry) || '',
      `"${(entry.message || '').replace(/"/g, '""')}"`,
      entry.details ? `"${JSON.stringify(entry.details).replace(/"/g, '""')}"` : '',
      entry.created_at,
    ]);

    const csv = [headers.join(','), ...rows.map((r) => r.join(','))].join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');

    link.href = url;
    link.download = `cartshift-log-${state.migrationId}.csv`;
    link.click();
    URL.revokeObjectURL(url);
  }

  function buildLogUrl(page) {
    const params = [`page=${page}`, `per_page=${state.perPage}`];

    if (state.migrationId) {
      params.push(`migration_id=${encodeURIComponent(state.migrationId)}`);
    }

    if (state.statusFilter) {
      params.push(`status=${encodeURIComponent(state.statusFilter)}`);
    }

    // Harmless if the backend ignores it — detectCodeFilterSupport notices and
    // the client-side filter picks up the slack.
    if (state.codeFilter) {
      params.push(`code=${encodeURIComponent(state.codeFilter)}`);
    }

    return `log?${params.join('&')}`;
  }

  return {
    state,
    filteredEntries,
    codeBreakdown,
    codeDescriptors,
    hasBreakdown,
    loadInitial,
    loadMore,
    setFilter,
    setCodeFilter,
    setSearch,
    setPerPage,
    exportCsv,
  };
}
