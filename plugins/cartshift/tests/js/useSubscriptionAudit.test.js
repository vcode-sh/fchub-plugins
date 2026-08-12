import { describe, it, expect, vi, beforeEach } from 'vitest';

const { apiMock } = vi.hoisted(() => ({ apiMock: vi.fn() }));

vi.mock('@/composables/useApi.js', () => ({ useApi: () => ({ api: apiMock }) }));

const { useSubscriptionAudit } = await import('@/composables/useSubscriptionAudit.js');

/**
 * The composable behind the read-only audit screen.
 *
 * Two things it must never do, both of which are the whole point of the mode:
 * issue anything but a GET while auditing, and let a package prepare — which is
 * a CartShift configuration write — be mistaken for part of the audit.
 */
function auditDocument(overrides = {}) {
  return {
    mode: 'subscription_audit',
    writes: {
      nothing: true,
      statement: 'This screen reads. It writes nothing at all.',
      configuration_writes: [
        { action: 'prepare-package', writes: 'four strings' },
        { action: 'mapping-decisions', writes: 'the mapping staging table' },
        { action: 'manual-fallback-confirmation', writes: 'nothing yet' },
      ],
    },
    source: { mode: 'live', source_key: 'lapka-club', file: null, selection_fingerprint: 'abc' },
    totals: {
      selected: 8,
      assessed: 6,
      invalid: 2,
      ready: 1,
      confirmation_required: 4,
      blocked: 1,
      reconciles: true,
    },
    breakdown: { by_status: {}, by_cadence: {}, by_strategy: {}, by_collection_method: {} },
    customers: {
      assessed: 6,
      unique_identities: 5,
      guests_at_source: 1,
      resolved_in_id_map: 0,
      unresolved_in_id_map: 6,
      resolution: { matched_target_user: 2, would_create: 3, blocked: 0, blocked_reason_codes: {} },
    },
    mapping: { source_products: [], decided: 0, undecided: 0, fingerprint: 'f', shared_target_variations: [] },
    stripe: { total: 2, modern: 1, legacy: 1, missing: 0, unrecognised: 0, remote_schedule: 0 },
    paypal: { total: 1, system: 0, automatic: 0, manual_confirmation: 1, manual_accepted: 0, blocked: 0 },
    target: { approval_fingerprint: 'a'.repeat(64), approved: false, capabilities: {}, errors: [] },
    schedule: { active_next_date_past: 1, active_next_date_past_refs: ['subscription:910021'] },
    history: { mismatches: 1, records: [] },
    closure: { complete: false, reason_codes: ['invalid_source_record'] },
    confirmation: { manual_fallback_confirmed: false, awaiting: 4, remedy: 'Accept it for this cohort.' },
    reasons: [],
    ...overrides,
  };
}

describe('useSubscriptionAudit', () => {
  beforeEach(() => {
    apiMock.mockReset();
  });

  it('asks for the audit and the first page of records with GET only', async () => {
    apiMock.mockImplementation((method, endpoint) =>
      endpoint.startsWith('subscriptions/audit/records')
        ? { records: [], page: 1, per_page: 25, total: 0, filtered: 0 }
        : auditDocument()
    );

    const { state, load } = useSubscriptionAudit();
    await load();

    expect(apiMock.mock.calls.every(([method]) => method === 'GET')).toBe(true);
    expect(state.document.totals.selected).toBe(8);
    expect(state.error).toBe(null);
  });

  it('sends the source mode, source key and file so the audit is stateless', async () => {
    apiMock.mockResolvedValue(auditDocument());

    const { state, load } = useSubscriptionAudit();
    state.source = 'package';
    state.file = '/srv/private/lapka.ndjson';
    await load();

    const url = apiMock.mock.calls[0][1];

    expect(url).toContain('source=package');
    expect(url).toContain(encodeURIComponent('/srv/private/lapka.ndjson'));
  });

  it('reports a failure instead of pretending the audit came back clean', async () => {
    apiMock.mockRejectedValue(new Error('WooCommerce Subscriptions is not readable here'));

    const { state, load } = useSubscriptionAudit();
    await load();

    expect(state.document).toBe(null);
    expect(state.error).toContain('not readable');
  });

  it('paginates records without re-running the whole audit', async () => {
    apiMock.mockImplementation((method, endpoint) =>
      endpoint.startsWith('subscriptions/audit/records')
        ? { records: [{ source_ref: 'subscription:1' }], page: 2, per_page: 25, total: 30, filtered: 30 }
        : auditDocument()
    );

    const { state, load, goToPage } = useSubscriptionAudit();
    await load();

    apiMock.mockClear();
    await goToPage(2);

    expect(apiMock).toHaveBeenCalledTimes(1);
    expect(apiMock.mock.calls[0][1]).toContain('page=2');
    expect(state.records.page).toBe(2);
  });

  it('filters records by reason code', async () => {
    apiMock.mockImplementation((method, endpoint) =>
      endpoint.startsWith('subscriptions/audit/records')
        ? { records: [], page: 1, per_page: 25, total: 30, filtered: 1 }
        : auditDocument()
    );

    const { state, load, filterByCode } = useSubscriptionAudit();
    await load();
    await filterByCode('active_next_date_past');

    expect(apiMock.mock.calls.at(-1)[1]).toContain('code=active_next_date_past');
    expect(state.filters.code).toBe('active_next_date_past');
  });

  /**
   * Preparing a package writes a descriptor. It is legitimate and it is not
   * audit, so it is the one call in this composable that is not a GET and the
   * only one that reloads the audit afterwards.
   */
  it('refuses legacy package preparation without a POST', async () => {
    apiMock.mockImplementation((method, endpoint) => {
      if (endpoint === 'subscriptions/packages/prepare') {
        return { prepared: true, write: { kind: 'cartshift_configuration' } };
      }
      return endpoint.startsWith('subscriptions/audit/records')
        ? { records: [], page: 1, per_page: 25, total: 0, filtered: 0 }
        : auditDocument();
    });

    const { state, preparePackage } = useSubscriptionAudit();
    const ok = await preparePackage('/srv/private/lapka.ndjson');

    expect(ok).toBe(false);
    expect(apiMock).not.toHaveBeenCalled();
    expect(state.error).toContain('legacy_subscription_v1_package_write_closed');
    expect(state.error).toContain('wp cartshift transfer validate-package');
  });

  it('refuses before a backend-specific package error can be observed', async () => {
    apiMock.mockRejectedValue(new Error('That file is not a valid package'));

    const { state, preparePackage } = useSubscriptionAudit();
    const ok = await preparePackage('/srv/private/broken.ndjson');

    expect(ok).toBe(false);
    expect(apiMock).not.toHaveBeenCalled();
    expect(state.error).toContain('legacy_subscription_v1_package_write_closed');
  });
});
