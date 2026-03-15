import { reactive, computed } from 'vue';
import { useApi } from './useApi.js';

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
    searchQuery: '',
    stats: { success: 0, skipped: 0, error: 0, 'dry-run': 0, total: 0 },
    migrationId: null,
  });

  const filteredEntries = computed(() => {
    if (!state.searchQuery) return state.entries;

    const q = state.searchQuery.toLowerCase();

    return state.entries.filter((entry) => {
      const message = (entry.message || '').toLowerCase();
      const wcId = String(entry.wc_id || '').toLowerCase();
      const entityType = (entry.entity_type || '').toLowerCase();

      return message.includes(q) || wcId.includes(q) || entityType.includes(q);
    });
  });

  async function loadStats(migrationId) {
    try {
      const data = await api('GET', `log/stats?migration_id=${migrationId}`);
      state.stats = data;
    } catch {
      // Stats are best-effort.
    }
  }

  async function loadInitial(migrationId) {
    state.migrationId = migrationId;
    state.page = 1;
    state.entries = [];
    state.allEntries = [];
    state.loading = true;
    state.searchQuery = '';

    try {
      const [logData] = await Promise.all([
        api('GET', buildLogUrl(1)),
        loadStats(migrationId),
      ]);

      const entries = logData.data || logData.entries || [];
      const total = logData.total || 0;
      const perPage = logData.per_page || state.perPage;

      state.entries = entries;
      state.allEntries = entries.slice();
      state.total = total;
      state.perPage = perPage;
      state.hasMore = entries.length < total;
    } catch {
      state.entries = [];
      state.allEntries = [];
    } finally {
      state.loading = false;
    }
  }

  async function loadMore() {
    if (state.loadingMore || !state.hasMore) return;

    state.loadingMore = true;
    state.page += 1;

    try {
      const data = await api('GET', buildLogUrl(state.page));
      const entries = data.data || data.entries || [];

      state.entries.push(...entries);
      state.allEntries.push(...entries);
      state.total = data.total || state.total;
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
    state.page = 1;
    state.entries = [];
    state.allEntries = [];
    state.loading = true;
    state.searchQuery = '';

    try {
      const data = await api('GET', buildLogUrl(1));
      const entries = data.data || data.entries || [];
      const total = data.total || 0;

      state.entries = entries;
      state.allEntries = entries.slice();
      state.total = total;
      state.hasMore = entries.length < total;
    } catch {
      state.entries = [];
      state.allEntries = [];
    } finally {
      state.loading = false;
    }
  }

  function setSearch(query) {
    state.searchQuery = query;
  }

  function setPerPage(perPage) {
    state.perPage = perPage;
    // Reload with the new per_page.
    if (state.migrationId) {
      loadInitial(state.migrationId);
    }
  }

  async function exportCsv() {
    if (!state.migrationId) return;

    let allEntries = [];
    let page = 1;
    let hasMore = true;

    while (hasMore) {
      const params = [`page=${page}`, `per_page=100`, `migration_id=${state.migrationId}`];
      if (state.statusFilter) {
        params.push(`status=${state.statusFilter}`);
      }

      try {
        const data = await api('GET', `log?${params.join('&')}`);
        const entries = data.data || data.entries || [];
        allEntries.push(...entries);

        const total = data.total || 0;
        hasMore = allEntries.length < total;
        page += 1;
      } catch {
        hasMore = false;
      }
    }

    const headers = ['ID', 'Entity Type', 'WC ID', 'Status', 'Message', 'Details', 'Created At'];
    const rows = allEntries.map((entry) => [
      entry.id,
      entry.entity_type,
      entry.wc_id || '',
      entry.status,
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
      params.push(`migration_id=${state.migrationId}`);
    }

    if (state.statusFilter) {
      params.push(`status=${state.statusFilter}`);
    }

    return `log?${params.join('&')}`;
  }

  return {
    state,
    filteredEntries,
    loadInitial,
    loadMore,
    setFilter,
    setSearch,
    setPerPage,
    exportCsv,
  };
}
