import { describe, it, expect, vi, beforeEach } from 'vitest';

const { apiMock } = vi.hoisted(() => ({ apiMock: vi.fn() }));

vi.mock('@/composables/useApi.js', () => ({
  useApi: () => ({ api: apiMock }),
}));

const { useLogViewer } = await import('@/composables/useLogViewer.js');

/** Route a fake backend by endpoint prefix. */
function routeApi(routes) {
  apiMock.mockImplementation(async (_method, endpoint) => {
    for (const prefix of Object.keys(routes)) {
      if (endpoint.startsWith(prefix)) {
        const handler = routes[prefix];
        return typeof handler === 'function' ? handler(endpoint) : handler;
      }
    }

    throw new Error(`Unrouted endpoint: ${endpoint}`);
  });
}

beforeEach(() => {
  apiMock.mockReset();
});

describe('codeBreakdown source preference', () => {
  it('prefers the server breakdown and reports it as not derived', async () => {
    routeApi({
      'log/stats': {
        total: 40,
        error: 12,
        code_breakdown: [{ error_code: 'sku_collision', count: 12, label: 'SKU clash' }],
      },
      log: { data: [{ id: 1, error_code: 'missing_email' }], total: 1, per_page: 50 },
    });

    const { codeBreakdown, hasBreakdown, loadInitial } = useLogViewer();
    await loadInitial('mig-1');

    expect(hasBreakdown.value).toBe(true);
    expect(codeBreakdown.value.derived).toBe(false);
    expect(codeBreakdown.value.covers).toBe(40);
    expect(codeBreakdown.value.rows.map((r) => r.code)).toEqual(['sku_collision']);
  });

  it.each(['codes', 'by_code', 'error_codes', 'breakdown', 'reasons'])(
    'accepts %s as an alternate breakdown key',
    async (key) => {
      routeApi({
        'log/stats': { total: 5, [key]: { missing_email: 5 } },
        log: { data: [], total: 0, per_page: 50 },
      });

      const { codeBreakdown, loadInitial } = useLogViewer();
      await loadInitial('mig-1');

      expect(codeBreakdown.value.rows.map((r) => r.code)).toEqual(['missing_email']);
    }
  );

  it('derives a breakdown from loaded entries when the server sends none', async () => {
    routeApi({
      'log/stats': { total: 3, error: 3 },
      log: {
        data: [
          { id: 1, error_code: 'missing_email' },
          { id: 2, details: { code: 'missing_email' } },
          { id: 3, error_code: 'sku_collision' },
        ],
        total: 3,
        per_page: 50,
      },
    });

    const { codeBreakdown, loadInitial } = useLogViewer();
    await loadInitial('mig-1');

    expect(codeBreakdown.value.derived).toBe(true);
    expect(codeBreakdown.value.covers).toBe(3);
    expect(codeBreakdown.value.rows.map((r) => [r.code, r.count])).toEqual([
      ['missing_email', 2],
      ['sku_collision', 1],
    ]);
  });

  it('stays empty — not broken — when nothing carries a code', async () => {
    routeApi({
      'log/stats': { total: 2 },
      log: { data: [{ id: 1, message: 'ok' }], total: 1, per_page: 50 },
    });

    const { codeBreakdown, hasBreakdown, loadInitial } = useLogViewer();
    await loadInitial('mig-1');

    expect(hasBreakdown.value).toBe(false);
    expect(codeBreakdown.value).toEqual({ rows: [], derived: false, covers: 0 });
  });

  it('exposes a code → descriptor map for rows that only carry a slug', async () => {
    routeApi({
      'log/stats': {
        total: 1,
        code_breakdown: [{ code: 'missing_email', count: 1, label: 'No email address' }],
      },
      log: { data: [], total: 0, per_page: 50 },
    });

    const { codeDescriptors, loadInitial } = useLogViewer();
    await loadInitial('mig-1');

    expect(codeDescriptors.value.missing_email.label).toBe('No email address');
  });
});

describe('stats merging', () => {
  it('zero-fills every card key the server omitted', async () => {
    routeApi({
      'log/stats': { success: 10, error: 2, total: 12 },
      log: { data: [], total: 0, per_page: 50 },
    });

    const { state, loadInitial } = useLogViewer();
    await loadInitial('mig-1');

    expect(state.stats).toMatchObject({
      success: 10,
      skipped: 0,
      warning: 0,
      error: 2,
      'dry-run': 0,
      total: 12,
    });
  });

  it('keeps the zero-filled defaults when the stats call fails outright', async () => {
    apiMock.mockImplementation(async (_method, endpoint) => {
      if (endpoint.startsWith('log/stats')) throw new Error('boom');
      return { data: [], total: 0, per_page: 50 };
    });

    const { state, loadInitial } = useLogViewer();
    await loadInitial('mig-1');

    expect(state.stats).toEqual({
      success: 0,
      skipped: 0,
      warning: 0,
      error: 0,
      'dry-run': 0,
      total: 0,
    });
  });
});

describe('code filter fallback', () => {
  const entries = [
    { id: 1, error_code: 'missing_email' },
    { id: 2, error_code: 'sku_collision' },
    { id: 3, message: 'no code at all' },
  ];

  it('trusts the server when every returned row matches the filter', async () => {
    routeApi({
      'log/stats': { total: 1 },
      log: { data: [{ id: 1, error_code: 'missing_email' }], total: 1, per_page: 50 },
    });

    const { state, filteredEntries, loadInitial, setCodeFilter } = useLogViewer();
    await loadInitial('mig-1');
    await setCodeFilter('missing_email');

    expect(state.codeFilterServerSide).toBe(true);
    expect(filteredEntries.value).toHaveLength(1);
  });

  it('notices an ignored code parameter and filters client-side instead', async () => {
    routeApi({
      'log/stats': { total: 3 },
      log: { data: entries, total: 3, per_page: 50 },
    });

    const { state, filteredEntries, loadInitial, setCodeFilter } = useLogViewer();
    await loadInitial('mig-1');
    await setCodeFilter('missing_email');

    expect(state.codeFilterServerSide).toBe(false);
    expect(filteredEntries.value.map((e) => e.id)).toEqual([1]);
  });

  it('assumes support when the filtered result is empty', async () => {
    routeApi({
      'log/stats': { total: 0 },
      log: { data: [], total: 0, per_page: 50 },
    });

    const { state, loadInitial, setCodeFilter } = useLogViewer();
    await loadInitial('mig-1');
    await setCodeFilter('missing_email');

    expect(state.codeFilterServerSide).toBe(true);
  });

  it('does not judge support off uncoded rows alone', async () => {
    routeApi({
      'log/stats': { total: 1 },
      log: { data: [{ id: 9, message: 'legacy row' }], total: 1, per_page: 50 },
    });

    const { state, loadInitial, setCodeFilter } = useLogViewer();
    await loadInitial('mig-1');
    await setCodeFilter('missing_email');

    expect(state.codeFilterServerSide).toBe(true);
  });

  it('sends the code parameter and resets it when a status filter is chosen', async () => {
    routeApi({
      'log/stats': { total: 0 },
      log: { data: [], total: 0, per_page: 50 },
    });

    const { state, loadInitial, setCodeFilter, setFilter } = useLogViewer();
    await loadInitial('mig-1');

    await setCodeFilter('coupon_code_collision');
    const withCode = apiMock.mock.calls.map((c) => c[1]).filter((e) => e.startsWith('log?'));
    expect(withCode[withCode.length - 1]).toContain('code=coupon_code_collision');

    await setFilter('error');
    expect(state.codeFilter).toBe('');
    const withStatus = apiMock.mock.calls.map((c) => c[1]).filter((e) => e.startsWith('log?'));
    expect(withStatus[withStatus.length - 1]).toContain('status=error');
    expect(withStatus[withStatus.length - 1]).not.toContain('code=');
  });
});

describe('search filtering', () => {
  it('matches message, wc_id, entity type and code case-insensitively', async () => {
    routeApi({
      'log/stats': { total: 3 },
      log: {
        data: [
          { id: 1, message: 'Duplicate SKU', wc_id: 101, entity_type: 'product' },
          { id: 2, message: 'fine', wc_id: 202, entity_type: 'order', error_code: 'missing_email' },
          { id: 3, message: 'fine', wc_id: 303, entity_type: 'customer' },
        ],
        total: 3,
        per_page: 50,
      },
    });

    const { filteredEntries, loadInitial, setSearch } = useLogViewer();
    await loadInitial('mig-1');

    setSearch('sku');
    expect(filteredEntries.value.map((e) => e.id)).toEqual([1]);

    setSearch('202');
    expect(filteredEntries.value.map((e) => e.id)).toEqual([2]);

    setSearch('MISSING_EMAIL');
    expect(filteredEntries.value.map((e) => e.id)).toEqual([2]);

    setSearch('customer');
    expect(filteredEntries.value.map((e) => e.id)).toEqual([3]);

    setSearch('');
    expect(filteredEntries.value).toHaveLength(3);
  });
});

describe('paging', () => {
  it('appends a page and stops when the total is reached', async () => {
    routeApi({
      'log/stats': { total: 3 },
      log: (endpoint) =>
        endpoint.includes('page=2')
          ? { data: [{ id: 3 }], total: 3, per_page: 2 }
          : { data: [{ id: 1 }, { id: 2 }], total: 3, per_page: 2 },
    });

    const { state, loadInitial, loadMore } = useLogViewer();
    await loadInitial('mig-1');

    expect(state.hasMore).toBe(true);

    await loadMore();

    expect(state.entries.map((e) => e.id)).toEqual([1, 2, 3]);
    expect(state.hasMore).toBe(false);
  });

  it('reverts the page counter when loading more fails', async () => {
    apiMock.mockImplementation(async (_method, endpoint) => {
      if (endpoint.startsWith('log/stats')) return { total: 3 };
      if (endpoint.includes('page=2')) throw new Error('network');
      return { data: [{ id: 1 }], total: 3, per_page: 1 };
    });

    const { state, loadInitial, loadMore } = useLogViewer();
    await loadInitial('mig-1');
    await loadMore();

    expect(state.page).toBe(1);
    expect(state.entries.map((e) => e.id)).toEqual([1]);
    expect(state.loadingMore).toBe(false);
  });

  it('empties the list rather than half-rendering when a reload fails', async () => {
    apiMock.mockImplementation(async (_method, endpoint) => {
      if (endpoint.startsWith('log/stats')) return { total: 0 };
      throw new Error('network');
    });

    const { state, loadInitial } = useLogViewer();
    await loadInitial('mig-1');

    expect(state.entries).toEqual([]);
    expect(state.hasMore).toBe(false);
    expect(state.loading).toBe(false);
  });

  it('accepts either the data or entries envelope key', async () => {
    routeApi({
      'log/stats': { total: 1 },
      log: { entries: [{ id: 7 }], total: 1, per_page: 50 },
    });

    const { state, loadInitial } = useLogViewer();
    await loadInitial('mig-1');

    expect(state.entries.map((e) => e.id)).toEqual([7]);
  });
});
