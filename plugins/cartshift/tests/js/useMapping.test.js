import { describe, it, expect, vi, beforeEach } from 'vitest';

const { apiMock } = vi.hoisted(() => ({ apiMock: vi.fn() }));

vi.mock('@/composables/useApi.js', () => ({ useApi: () => ({ api: apiMock }) }));

const { useMapping } = await import('@/composables/useMapping.js');

describe('useMapping', () => {
  beforeEach(() => {
    apiMock.mockReset();
  });

  function row(overrides = {}) {
    return {
      wc_id: 1,
      name: 'Blue Hoodie',
      wc_type: 'simple',
      sku: '',
      variations: 1,
      order_count: 12,
      band: 'likely',
      suggested: 900,
      candidates: [
        {
          id: 900,
          label: 'Blue Hoodie',
          band: 'likely',
          score: 1.0,
          variant: { matched: 1, total: 1, adds: 0, map: { 1: 501 }, orphans: [] },
        },
      ],
      variant: { matched: 1, total: 1, adds: 0, map: { 1: 501 }, orphans: [] },
      decision: null,
      ...overrides,
    };
  }

  it('loads rows and records the catalogue size', async () => {
    apiMock.mockResolvedValue({ rows: [row()], total: 1, fc_product_count: 28 });

    const { state, loadRows } = useMapping();
    await loadRows();

    expect(apiMock).toHaveBeenCalledWith('GET', 'mapping/rows?page=1&per_page=200');
    expect(state.rows).toHaveLength(1);
    expect(state.fcProductCount).toBe(28);
  });

  it('sends the scope so the screen shows what the run will migrate', async () => {
    apiMock.mockResolvedValue({ rows: [], total: 0, fc_product_count: 0 });

    const { loadRows } = useMapping();
    await loadRows({ mode: 'explicit', product_ids: [12, 13] });

    const url = apiMock.mock.calls[0][1];

    expect(url).toContain('scope=');
    expect(JSON.parse(decodeURIComponent(url.split('scope=')[1]))).toEqual({
      mode: 'explicit',
      product_ids: [12, 13],
    });
  });

  // A visible pager and a "Link all 19 in this band" button cannot both be
  // honest: the band header counts what is loaded. So every in-scope product
  // is walked before the screen groups anything.
  it('keeps loading pages until the whole catalogue is on screen', async () => {
    apiMock
      .mockResolvedValueOnce({
        rows: [row({ wc_id: 1 }), row({ wc_id: 2 })],
        total: 3,
        fc_product_count: 5,
      })
      .mockResolvedValueOnce({ rows: [row({ wc_id: 3 })], total: 3, fc_product_count: 5 });

    const { state, loadRows, summary } = useMapping();
    await loadRows();

    expect(apiMock).toHaveBeenCalledTimes(2);
    expect(apiMock.mock.calls[1][1]).toContain('page=2');
    expect(state.rows.map((r) => r.wc_id)).toEqual([1, 2, 3]);
    expect(summary.value.complete).toBe(true);
  });

  it('stops rather than spinning when a page comes back empty', async () => {
    apiMock
      .mockResolvedValueOnce({ rows: [row({ wc_id: 1 })], total: 99, fc_product_count: 1 })
      .mockResolvedValueOnce({ rows: [], total: 99, fc_product_count: 1 });

    const { loadRows, summary } = useMapping();
    await loadRows();

    expect(apiMock).toHaveBeenCalledTimes(2);
    expect(summary.value.loaded).toBe(1);
    expect(summary.value.total).toBe(99);
    expect(summary.value.complete).toBe(false);
  });

  it('groups rows by band', async () => {
    apiMock.mockResolvedValue({
      rows: [
        row({ wc_id: 1, band: 'strong' }),
        row({ wc_id: 2, band: 'likely' }),
        row({ wc_id: 3, band: 'strong' }),
        row({ wc_id: 4, band: 'none', suggested: null, candidates: [] }),
      ],
      total: 4,
      fc_product_count: 5,
    });

    const { loadRows, bandRows } = useMapping();
    await loadRows();

    expect(bandRows('strong').map((r) => r.wc_id)).toEqual([1, 3]);
    expect(bandRows('likely').map((r) => r.wc_id)).toEqual([2]);
    expect(bandRows('none').map((r) => r.wc_id)).toEqual([4]);
  });

  it('sends the variant map with a link decision', async () => {
    apiMock.mockResolvedValue({ rows: [row()], total: 1, fc_product_count: 1 });

    const { state, loadRows, decide } = useMapping();
    await loadRows();

    apiMock.mockResolvedValue({ saved: true, decision: { decision: 'link', fc_post_id: 900 } });

    await decide(state.rows[0], 'link');

    expect(apiMock).toHaveBeenCalledWith('POST', 'mapping/decide', {
      wc_id: 1,
      wc_type: 'simple',
      decision: 'link',
      band: 'likely',
      fc_post_id: 900,
      variant_map: { 1: 501 },
      orphans: [],
    });
  });

  it('carries orphan variations back so promotion can create them', async () => {
    apiMock.mockResolvedValue({
      rows: [
        row({
          variant: {
            matched: 2,
            total: 3,
            adds: 1,
            map: { 11: 501, 12: 502 },
            orphans: [{ id: 13, sku: 'TS-XL', name: 'XL' }],
          },
        }),
      ],
      total: 1,
      fc_product_count: 1,
    });

    const { state, loadRows, decide } = useMapping();
    await loadRows();

    apiMock.mockResolvedValue({ saved: true, decision: { decision: 'link' } });

    await decide(state.rows[0], 'link');

    // Dropping these is how "adds XL" becomes an order line pointing at nothing.
    expect(apiMock.mock.calls.at(-1)[2].orphans).toEqual([{ id: 13, sku: 'TS-XL', name: 'XL' }]);
  });

  // ── Choosing a different candidate ──────────────────────
  //
  // FluentCart variation IDs are global in fct_product_variations, so a
  // selection that moves the product without moving the variant map saves
  // ENTITY_VARIATION rows aimed at another product's variants — and every
  // historical line item for that product follows them there.

  function twoCandidateRow() {
    return row({
      wc_id: 7,
      band: 'likely',
      suggested: 900,
      candidates: [
        {
          id: 900,
          label: 'Blue Hoodie',
          band: 'likely',
          score: 1.2,
          variant: { matched: 2, total: 2, adds: 0, map: { 11: 501, 12: 502 }, orphans: [] },
          downloads_lost: false,
        },
        {
          id: 901,
          label: 'Red Hoodie',
          band: 'likely',
          score: 0.9,
          variant: {
            matched: 1,
            total: 2,
            adds: 1,
            map: { 11: 601 },
            orphans: [{ id: 12, sku: '', name: 'XL' }],
          },
          downloads_lost: true,
        },
      ],
      variant: { matched: 2, total: 2, adds: 0, map: { 11: 501, 12: 502 }, orphans: [] },
      downloads_lost: false,
    });
  }

  it('changing the candidate changes the variant map and orphans that get saved', async () => {
    apiMock.mockResolvedValue({ rows: [twoCandidateRow()], total: 1, fc_product_count: 2 });

    const { state, loadRows, chooseCandidate, decide } = useMapping();
    await loadRows();

    chooseCandidate(state.rows[0], 901);

    apiMock.mockResolvedValue({ saved: true, decision: { decision: 'link' } });
    await decide(state.rows[0], 'link');

    const body = apiMock.mock.calls.at(-1)[2];

    expect(body.fc_post_id).toBe(901);
    expect(body.variant_map).toEqual({ 11: 601 });
    expect(body.orphans).toEqual([{ id: 12, sku: '', name: 'XL' }]);
  });

  it('changing the candidate changes the summary the row displays', async () => {
    apiMock.mockResolvedValue({ rows: [twoCandidateRow()], total: 1, fc_product_count: 2 });

    const { state, loadRows, chooseCandidate } = useMapping();
    await loadRows();

    expect(state.rows[0].variant.matched).toBe(2);
    expect(state.rows[0].variant.adds).toBe(0);

    chooseCandidate(state.rows[0], 901);

    // The label read "2/2 matched" while the link it would save matched one.
    expect(state.rows[0].variant.matched).toBe(1);
    expect(state.rows[0].variant.adds).toBe(1);
  });

  it('changing the candidate changes whether files are reported lost', async () => {
    apiMock.mockResolvedValue({ rows: [twoCandidateRow()], total: 1, fc_product_count: 2 });

    const { state, loadRows, chooseCandidate } = useMapping();
    await loadRows();

    expect(state.rows[0].downloads_lost).toBe(false);

    // Whether the Woo product's files survive depends entirely on which
    // FluentCart product is on the other end, so a warning left describing the
    // previous candidate is worse than none.
    chooseCandidate(state.rows[0], 901);

    expect(state.rows[0].downloads_lost).toBe(true);
  });

  it('choosing something that is not on offer clears the suggestion rather than keeping a stale map', async () => {
    apiMock.mockResolvedValue({ rows: [twoCandidateRow()], total: 1, fc_product_count: 2 });

    const { state, loadRows, chooseCandidate } = useMapping();
    await loadRows();

    chooseCandidate(state.rows[0], 999);

    expect(state.rows[0].suggested).toBeNull();
    expect(state.rows[0].variant).toBeNull();
    expect(state.rows[0].downloads_lost).toBe(false);
  });

  it('applies a bulk action to one band only', async () => {
    apiMock.mockResolvedValue({
      rows: [row({ wc_id: 1, band: 'strong' }), row({ wc_id: 2, band: 'likely' })],
      total: 2,
      fc_product_count: 2,
    });

    const { loadRows, bulk } = useMapping();
    await loadRows();

    apiMock.mockResolvedValue({ saved: 1, decisions: [] });

    await bulk('strong', 'link');

    expect(apiMock).toHaveBeenCalledWith('POST', 'mapping/bulk', {
      band: 'strong',
      decision: 'link',
      rows: [{ wc_id: 1, wc_type: 'simple', fc_post_id: 900, variant_map: { 1: 501 }, orphans: [] }],
    });
  });

  // One shape, whichever button made it: bulk used to synthesise
  // {decision, fc_post_id} locally while decide() adopted the server's full
  // ProductMapDecision::toArray().
  it('adopts the server decisions a bulk press returns', async () => {
    apiMock.mockResolvedValue({
      rows: [row({ wc_id: 1, band: 'strong' })],
      total: 1,
      fc_product_count: 1,
    });

    const { state, loadRows, bulk } = useMapping();
    await loadRows();

    const saved = {
      wc_id: 1,
      wc_type: 'simple',
      decision: 'link',
      fc_post_id: 900,
      band: 'strong',
      variant_map: { 1: 501 },
      orphans: [],
    };

    apiMock.mockResolvedValue({ saved: 1, decisions: [saved] });

    await bulk('strong', 'link');

    expect(state.rows[0].decision).toEqual(saved);
  });

  it('a per-row decision survives a later bulk action on its band', async () => {
    apiMock.mockResolvedValue({
      rows: [row({ wc_id: 1, band: 'likely' }), row({ wc_id: 2, band: 'likely' })],
      total: 2,
      fc_product_count: 2,
    });

    const { state, loadRows, decide, bulk } = useMapping();
    await loadRows();

    apiMock.mockResolvedValue({ saved: true, decision: { decision: 'skip', fc_post_id: null } });
    await decide(state.rows[0], 'skip');

    apiMock.mockResolvedValue({ saved: 1, decisions: [] });
    await bulk('likely', 'link');

    const bulkCall = apiMock.mock.calls.at(-1);

    expect(bulkCall[2].rows.map((r) => r.wc_id)).toEqual([2]);
    expect(state.rows[0].decision.decision).toBe('skip');
  });

  it('bulk link omits rows with no candidate', async () => {
    apiMock.mockResolvedValue({
      rows: [row({ wc_id: 1, band: 'none', suggested: null, candidates: [], variant: null })],
      total: 1,
      fc_product_count: 0,
    });

    const { loadRows, bulk } = useMapping();
    await loadRows();

    apiMock.mockResolvedValue({ saved: 0, decisions: [] });

    await bulk('none', 'link');

    expect(apiMock.mock.calls.at(-1)[2].rows).toEqual([]);
  });

  it('clearing forgets every decision on screen', async () => {
    apiMock.mockResolvedValue({
      rows: [row({ wc_id: 1, decision: { decision: 'link' } })],
      total: 1,
      fc_product_count: 1,
    });

    const { state, loadRows, clearAll } = useMapping();
    await loadRows();

    apiMock.mockResolvedValue({ cleared: true });

    await clearAll();

    expect(apiMock).toHaveBeenCalledWith('POST', 'mapping/clear', {});
    expect(state.rows[0].decision).toBeNull();
  });

  it('counts decided rows for the summary', async () => {
    apiMock.mockResolvedValue({
      rows: [row({ wc_id: 1 }), row({ wc_id: 2, decision: { decision: 'create' } })],
      total: 2,
      fc_product_count: 3,
    });

    const { loadRows, summary } = useMapping();
    await loadRows();

    expect(summary.value.decided).toBe(1);
    expect(summary.value.loaded).toBe(2);
    expect(summary.value.total).toBe(2);
  });

  // ── Run mode ────────────────────────────────────────────

  async function loadedForRunMode() {
    apiMock.mockResolvedValue({
      rows: [
        row({ wc_id: 1, decision: { decision: 'link', fc_post_id: 900 } }),
        row({ wc_id: 2, decision: { decision: 'create' } }),
        row({ wc_id: 3, decision: { decision: 'skip' } }),
        row({ wc_id: 4, decision: null }),
      ],
      total: 4,
      fc_product_count: 2,
    });

    const mapping = useMapping();
    await mapping.loadRows();

    return mapping;
  }

  function migrationState() {
    return {
      scope: {
        mode: 'everything',
        since: null,
        products: [],
        customers: [],
        includeOrdersForProducts: false,
      },
    };
  }

  it('leaves the scope alone under "create the rest"', async () => {
    const { applyRunMode } = await loadedForRunMode();
    const shared = migrationState();

    applyRunMode(shared);

    expect(shared.scope.mode).toBe('everything');
    expect(shared.scope.products).toEqual([]);
  });

  it('"only what I mapped" becomes an explicit scope over the decided set', async () => {
    const { state, applyRunMode } = await loadedForRunMode();
    const shared = migrationState();

    state.runMode = 'only-mapped';
    applyRunMode(shared);

    expect(shared.scope.mode).toBe('explicit');
    // 1 linked and 2 explicitly created are in; 3 was skipped and 4 untouched.
    expect(shared.scope.products).toEqual([{ id: 1 }, { id: 2 }]);
  });

  // Without this, ScopeResolver builds the order set from picked customers
  // alone — and a product-only explicit scope picks none, so the run migrates
  // the products and none of the history they exist to carry.
  it('"only what I mapped" brings the orders for those products with it', async () => {
    const { state, applyRunMode } = await loadedForRunMode();
    const shared = migrationState();

    state.runMode = 'only-mapped';
    applyRunMode(shared);

    expect(shared.scope.includeOrdersForProducts).toBe(true);
  });

  // A scope is one of three exclusive modes: rewriting `since` to `explicit`
  // makes serializeScope() emit `since: null`, and ScopeResolver's explicit
  // branch has no date component at all — so an owner who limited the run to
  // "orders since 2024-03-01" would silently get the entire order history for
  // every product they mapped.
  it('a since cutoff survives applyRunMode untouched', async () => {
    const { state, applyRunMode } = await loadedForRunMode();
    const shared = migrationState();

    shared.scope.mode = 'since';
    shared.scope.since = '2024-03-01';

    state.runMode = 'only-mapped';
    applyRunMode(shared);

    expect(shared.scope.mode).toBe('since');
    expect(shared.scope.since).toBe('2024-03-01');
    expect(shared.scope.products).toEqual([]);
    expect(shared.scope.includeOrdersForProducts).toBe(false);
  });

  it('reports the run mode as unavailable for a date-limited scope', async () => {
    const { runModeAvailable } = await loadedForRunMode();

    expect(runModeAvailable({ mode: 'since', since: '2024-03-01' })).toBe(false);
    expect(runModeAvailable({ mode: 'everything' })).toBe(true);
    expect(runModeAvailable({ mode: 'explicit' })).toBe(true);
  });

  it('surfaces a failed load rather than silently showing nothing', async () => {
    apiMock.mockRejectedValue(new Error('boom'));

    const { state, loadRows } = useMapping();
    await loadRows();

    expect(state.error).toBe('boom');
    expect(state.loading).toBe(false);
  });
});
