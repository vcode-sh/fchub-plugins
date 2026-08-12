import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive, ref, nextTick } from 'vue';

const { apiMock } = vi.hoisted(() => ({ apiMock: vi.fn() }));

vi.mock('@/composables/useApi.js', () => ({ useApi: () => ({ api: apiMock }) }));

const { default: SubscriptionAuditScreen } = await import('@/components/SubscriptionAuditScreen.vue');
const { default: SelectScreen } = await import('@/components/SelectScreen.vue');

function fakeTheme() {
  return { themeMode: ref('light'), changeTheme: vi.fn() };
}

function auditDocument(overrides = {}) {
  return {
    mode: 'subscription_audit',
    writes: {
      nothing: true,
      statement:
        'This screen writes nothing at all: no FluentCart row, no CartShift ID-map row — not even a simulated one — no option, no transient and no log line.',
      dry_run_note:
        'The dry run on the previous screen is a different promise: it writes CartShift simulation rows to CartShift’s own ID-map table.',
      configuration_writes: [
        {
          action: 'prepare-package',
          label: 'Prepare a package',
          writes: 'Four strings: source key, private path, checksum, selection fingerprint.',
        },
        {
          action: 'mapping-decisions',
          label: 'Save mapping decisions',
          writes: 'Rows in CartShift’s mapping staging table.',
        },
        {
          action: 'manual-fallback-confirmation',
          label: 'Accept the manual fallback',
          writes: 'Nothing yet — the acceptance is supplied at stage.',
        },
      ],
    },
    source: {
      mode: 'live',
      source_key: 'lapka-club',
      file: null,
      selection_fingerprint: 'fingerprint-abc',
      storage_authority: 'posts',
      package: null,
    },
    manifest: { counts: { subscription: 8 }, total_records: 40 },
    closure: { complete: false, counts: {}, reason_codes: ['invalid_source_record'] },
    totals: {
      selected: 564,
      assessed: 562,
      invalid: 2,
      ready: 0,
      confirmation_required: 561,
      blocked: 1,
      reconciles: true,
    },
    breakdown: {
      by_status: { active: 78, cancelled: 355, 'on-hold': 125 },
      by_cadence: { monthly: 375, yearly: 187 },
      by_strategy: { stripe: 367, paypal: 71, manual: 124 },
      by_collection_method: { manual: 562 },
    },
    customers: {
      assessed: 562,
      unique_identities: 215,
      guests_at_source: 349,
      registered_at_source: 213,
      blank_email: 0,
      resolved_in_id_map: 0,
      unresolved_in_id_map: 562,
      resolution: {
        reused_customer: 0,
        adopted_target_user: 0,
        attached_target_user: 43,
        reused_guest: 0,
        would_create_guest: 172,
        blocked: 0,
        blocked_reason_codes: {},
        matched_target_user: 43,
        would_create: 215,
      },
      note: 'The resolution figures are a forecast of section 9.1 run read-only.',
    },
    mapping: {
      source_products: [
        {
          source_product_id: 770001,
          name: 'Monthly subscription',
          subscriptions: 375,
          cadences: ['monthly'],
          target_product_id: null,
          target_variation_id: null,
          mapped: false,
          decided: false,
        },
      ],
      decided: 0,
      undecided: 1,
      mapped: 0,
      fingerprint: 'mapping-fingerprint',
      shared_target_variations: [
        // The §7.3 collision: monthly and yearly on one target variation,
        // neither opted in.
        {
          target_variation_id: 4101,
          claimants: [
            { wc_id: 770001, source_variation_id: 770001, allow_shared_target: false },
            { wc_id: 770002, source_variation_id: 770002, allow_shared_target: false }
          ],
        },
        // The benign case: two equivalent legacy products converging on
        // purpose, both decisions saying so.
        {
          target_variation_id: 4103,
          claimants: [
            { wc_id: 770003, source_variation_id: 770003, allow_shared_target: true },
            { wc_id: 770004, source_variation_id: 770004, allow_shared_target: true }
          ],
        },
      ],
    },
    stripe: { total: 367, modern: 120, legacy: 246, missing: 1, unrecognised: 0, remote_schedule: 0 },
    paypal: {
      total: 71,
      system: 0,
      automatic: 0,
      manual_confirmation: 71,
      manual_accepted: 0,
      blocked: 0,
    },
    target: {
      ready: true,
      errors: [],
      approved: false,
      approval_fingerprint: 'b'.repeat(64),
      subscription_settings: {
        raw_management_mode: 'gateway_managed',
        effective_management_mode: 'gateway_managed',
        raw_system_charge: 'no',
        effective_system_charge: false,
      },
      subscription_census: { total: 0, by_status: {} },
      capabilities: {
        stripe: {
          gateway: 'stripe',
          registered: false,
          collection_method: 'unavailable',
          reason_codes: ['system_collection_unavailable'],
        },
        paypal: {
          gateway: 'paypal',
          registered: false,
          collection_method: 'unavailable',
          reason_codes: ['system_collection_unavailable'],
        },
      },
    },
    schedule: {
      next_payment_missing: 360,
      next_payment_past: 127,
      next_payment_future: 77,
      active_next_date_missing: 0,
      active_next_date_past: 2,
      active_next_date_past_refs: ['subscription:910021'],
      active_next_date_missing_refs: [],
    },
    history: {
      mismatches: 1,
      records: [
        { source_ref: 'subscription:910002', source_payment_count: 7, included_paid_orders: 1 },
      ],
    },
    confirmation: {
      manual_fallback_confirmed: false,
      awaiting: 561,
      remedy:
        'Accept the manual fallback for this cohort, then re-run. Nothing migrates until you do.',
    },
    reasons: [
      {
        code: 'source_encoding_invalid',
        label: 'source_encoding_invalid',
        hint: '',
        known: false,
        severity: 'blocking',
        origin: 'closure',
        nested_in: 'invalid_source_record',
        count: 1,
        source_refs: ['subscription:910777'],
        source_ref_total: 1,
        truncated: false,
        expected: false,
      },
      {
        code: 'finite_term_from_product',
        label: 'Subscription length taken from the product',
        hint: 'Check it if the plan has changed since they signed up.',
        known: true,
        severity: 'warning',
        origin: 'assessment',
        nested_in: null,
        count: 562,
        source_refs: ['subscription:910001'],
        source_ref_total: 562,
        truncated: true,
        expected: true,
      },
      {
        code: 'target_variation_missing',
        label: 'target_variation_missing',
        hint: '',
        known: false,
        severity: 'blocking',
        origin: 'assessment',
        nested_in: null,
        count: 1,
        source_refs: ['subscription:910014'],
        source_ref_total: 1,
        truncated: false,
        expected: false,
      },
    ],
    ...overrides,
  };
}

function records() {
  return {
    records: [
      {
        source_ref: 'subscription:910021',
        source_subscription_id: 910021,
        status: 'active',
        gateway: 'stripe',
        cadence: 'monthly',
        outcome: 'blocked',
        strategy: 'stripe',
        collection_method: 'manual',
        error_codes: ['active_next_date_past'],
        warning_codes: ['finite_term_from_product'],
        reason_codes: ['active_next_date_past', 'finite_term_from_product'],
        next_payment_utc: '2024-09-30 07:30:00',
        mapping: {
          source_product_id: 770001,
          source_variation_id: 0,
          target_product_id: null,
          target_variation_id: null,
          needs_mapping: true,
        },
      },
    ],
    page: 1,
    per_page: 25,
    total: 564,
    filtered: 564,
    filters: { outcome: '', code: '' },
  };
}

function mountScreen(overrides = {}) {
  apiMock.mockImplementation((method, endpoint) =>
    endpoint.startsWith('subscriptions/audit/records') ? records() : auditDocument(overrides)
  );

  const migration = {
    state: reactive({ screen: 'subscription-audit', scope: { mode: 'everything' } }),
    actions: { goToScreen: vi.fn(), goToMapping: vi.fn() },
  };

  const wrapper = mount(SubscriptionAuditScreen, {
    global: { provide: { migration, theme: fakeTheme() } },
  });

  return { wrapper, migration };
}

async function settle(wrapper) {
  await nextTick();
  await nextTick();
  await nextTick();
  await nextTick();

  return wrapper;
}

describe('SubscriptionAuditScreen', () => {
  beforeEach(() => {
    apiMock.mockReset();
  });

  it('says, unambiguously, that this mode writes nothing', async () => {
    const { wrapper } = mountScreen();
    await settle(wrapper);

    const banner = wrapper.find('[data-zero-write]');

    expect(banner.exists()).toBe(true);
    expect(banner.text().toLowerCase()).toContain('writes nothing');
  });

  it('separates the configuration writes from the audit', async () => {
    const { wrapper } = mountScreen();
    await settle(wrapper);

    const panel = wrapper.find('[data-configuration-writes]');

    expect(panel.exists()).toBe(true);
    expect(panel.text()).toContain('Prepare a package');
    expect(panel.text()).toContain('Save mapping decisions');
    expect(panel.text()).toContain('Accept the manual fallback');
  });

  it('shows the source mode and source key', async () => {
    const { wrapper } = mountScreen();
    await settle(wrapper);

    const source = wrapper.find('[data-source]');

    expect(source.text()).toContain('lapka-club');
    expect(source.text().toLowerCase()).toContain('live');
  });

  it('shows the three readiness counts and states that they reconcile', async () => {
    const { wrapper } = mountScreen();
    await settle(wrapper);

    const totals = wrapper.find('[data-totals]');

    expect(totals.text()).toContain('561');
    expect(totals.text()).toContain('564');
    expect(totals.text().toLowerCase()).toContain('blocked');
  });

  it('breaks the cohort down by status, cadence and payment strategy', async () => {
    const { wrapper } = mountScreen();
    await settle(wrapper);

    const breakdown = wrapper.find('[data-breakdown]');

    expect(breakdown.text()).toContain('cancelled');
    expect(breakdown.text()).toContain('355');
    expect(breakdown.text()).toContain('yearly');
    expect(breakdown.text()).toContain('187');
    expect(breakdown.text()).toContain('stripe');
    expect(breakdown.text()).toContain('367');
  });

  it('shows the Stripe modern/legacy/missing split', async () => {
    const { wrapper } = mountScreen();
    await settle(wrapper);

    const stripe = wrapper.find('[data-stripe-split]');

    expect(stripe.text()).toContain('120');
    expect(stripe.text()).toContain('246');
    expect(stripe.text().toLowerCase()).toContain('legacy');
  });

  it('shows the PayPal system/automatic/manual-confirmation split', async () => {
    const { wrapper } = mountScreen();
    await settle(wrapper);

    const paypal = wrapper.find('[data-paypal-split]');

    expect(paypal.text().toLowerCase()).toContain('system');
    expect(paypal.text().toLowerCase()).toContain('automatic');
    expect(paypal.text()).toContain('71');
  });

  it('shows the target settings, census, capability inputs and the approval fingerprint', async () => {
    const { wrapper } = mountScreen();
    await settle(wrapper);

    const target = wrapper.find('[data-target]');

    expect(target.text()).toContain('gateway_managed');
    expect(target.text()).toContain('b'.repeat(64));
    expect(target.text().toLowerCase()).toContain('census');
    expect(target.text()).toContain('system_collection_unavailable');
  });

  it('shows schedule anomalies and history count mismatches', async () => {
    const { wrapper } = mountScreen();
    await settle(wrapper);

    expect(wrapper.find('[data-schedule]').text()).toContain('360');
    expect(wrapper.find('[data-history]').text()).toContain('910002');
  });

  /**
   * The context-nested code is the one this screen exists to reveal. It appears
   * only inside another failure's context, so a list built from the outer codes
   * shows the mangled row as a generic "invalid record" and hides the one thing
   * that says how to repair it.
   */
  it('shows a context-nested reason code, labelled with what it was nested in', async () => {
    const { wrapper } = mountScreen();
    await settle(wrapper);

    const row = wrapper.find('[data-reason="source_encoding_invalid"]');

    expect(row.exists()).toBe(true);
    expect(row.text()).toContain('invalid_source_record');
    expect(row.text()).toContain('subscription:910777');
  });

  /**
   * 562 records carrying the §9.2 product-fallback warning is the specification
   * working, not 562 problems. It belongs under notes, flagged as expected.
   */
  it('keeps the expected finite-term warning out of the blockers', async () => {
    const { wrapper } = mountScreen();
    await settle(wrapper);

    const blockers = wrapper.find('[data-reasons="blocking"]');
    const notes = wrapper.find('[data-reasons="warning"]');

    expect(blockers.text()).not.toContain('finite_term_from_product');
    expect(notes.text()).toContain('finite_term_from_product');
    expect(notes.find('[data-expected="true"]').exists()).toBe(true);
  });

  /**
   * The expected first-run state: nothing migrates until the behaviour change
   * is accepted. That is not a failure and must not read as one, but it must be
   * impossible to miss.
   */
  it('states that the whole cohort is awaiting confirmation, and what to do', async () => {
    const { wrapper } = mountScreen();
    await settle(wrapper);

    const panel = wrapper.find('[data-confirmation]');

    expect(panel.exists()).toBe(true);
    expect(panel.text()).toContain('561');
    expect(panel.text()).toContain('Accept the manual fallback for this cohort');
  });

  it('links a blocking mapping row back to the map screen', async () => {
    const { wrapper, migration } = mountScreen();
    await settle(wrapper);

    const link = wrapper.find('[data-goto-map]');

    expect(link.exists()).toBe(true);

    await link.trigger('click');

    expect(migration.actions.goToScreen).toHaveBeenCalledWith('map');
  });

  /**
   * §4.4's figure, rendered as itself. The previous field measured distinct
   * source customer refs — two namespaces on one axis — and the screen called
   * the result "distinct people", which is the strongest possible reading of
   * the weakest available measure.
   */
  it('renders the customer resolution forecast, not just a count of records', async () => {
    const { wrapper } = mountScreen();
    await settle(wrapper);

    const customers = wrapper.find('[data-customers]');

    expect(customers.text()).toContain('215');
    expect(customers.text()).toContain('distinct email identities');
    // The forecast, and the fact that it is a forecast.
    expect(customers.text()).toContain('43');
    expect(customers.text().toLowerCase()).toContain('match a wordpress user');
    expect(customers.text()).toContain('would be created');
  });

  /**
   * A count cannot tell the benign opted-in case from the monthly/yearly case
   * §7.3 says must resolve to distinct variations, and the claimants were in
   * the payload all along.
   */
  it('names the claimants of a shared target variation, and whether they opted in', async () => {
    const { wrapper } = mountScreen();
    await settle(wrapper);

    const shared = wrapper.find('[data-shared-target="4101"]');

    expect(shared.exists()).toBe(true);
    expect(shared.text()).toContain('770001');
    expect(shared.text()).toContain('770002');
    expect(shared.text()).toContain('sharing NOT allowed');
    expect(shared.find('[data-opted-in="false"]').exists()).toBe(true);
  });

  /**
   * Telling the two apart is the entire point of the panel. A screen that
   * rendered both as "claimed by more than one source" would send an operator
   * to re-decide a mapping they deliberately made.
   */
  it('distinguishes an opted-in shared variation from a collision', async () => {
    const { wrapper } = mountScreen();
    await settle(wrapper);

    const collision = wrapper.find('[data-shared-target="4101"]');
    const deliberate = wrapper.find('[data-shared-target="4103"]');

    expect(collision.find('[data-opted-in="false"]').exists()).toBe(true);
    expect(collision.find('[data-opted-in="true"]').exists()).toBe(false);

    expect(deliberate.find('[data-opted-in="true"]').exists()).toBe(true);
    expect(deliberate.find('[data-opted-in="false"]').exists()).toBe(false);
    expect(deliberate.text()).toContain('sharing allowed');
    expect(deliberate.text()).not.toContain('sharing NOT allowed');
  });

  /**
   * "215 would be created" tells an operator to worry; the split tells them
   * what the work actually is.
   */
  it('splits what would be created into attached users and new guests', async () => {
    const { wrapper } = mountScreen();
    await settle(wrapper);

    const customers = wrapper.find('[data-customers]');

    expect(customers.text()).toContain('172');
    expect(customers.text().toLowerCase()).toContain('new guest customers');
    expect(customers.text().toLowerCase()).toContain('existing wordpress user');
  });

  /**
   * Switching the picker to package leaves the live reading on screen until a
   * file is audited — deliberately, because losing a good reading to a stray
   * radio click would be worse. The screen has to say so.
   */
  it('says the figures are still the previous reading after switching source', async () => {
    const { wrapper } = mountScreen();
    await settle(wrapper);

    expect(wrapper.find('[data-source-mismatch]').exists()).toBe(false);

    await wrapper.find('[data-source-mode="package"]').trigger('change');
    await nextTick();

    const note = wrapper.find('[data-source-mismatch]');

    expect(note.exists()).toBe(true);
    expect(note.text()).toContain('live WooCommerce runtime');
    // And the source panel still reports the mode the figures were read in.
    expect(wrapper.find('[data-source]').text().toLowerCase()).toContain('live');
  });

  /**
   * The branch that matters. A breakdown that has quietly lost two records is
   * how a migration finishes "successfully" with two subscribers left behind,
   * so the copy that says do not stage has to be proven to render.
   */
  it('says do not stage when the totals do not reconcile', async () => {
    const { wrapper } = mountScreen({
      totals: {
        selected: 564,
        assessed: 560,
        invalid: 2,
        ready: 0,
        confirmation_required: 559,
        blocked: 1,
        reconciles: false,
      },
    });
    await settle(wrapper);

    const totals = wrapper.find('[data-totals]');

    expect(totals.find('[data-reconciles="false"]').exists()).toBe(true);
    expect(totals.text()).toContain('Do not stage this cohort');
  });

  it('shows the census values, not merely a census heading', async () => {
    const { wrapper } = mountScreen({
      target: {
        ...auditDocument().target,
        subscription_census: { total: 12, by_status: { active: 9, canceled: 3 } },
      },
    });
    await settle(wrapper);

    const target = wrapper.find('[data-target]');

    expect(target.text()).toContain('by_status.active');
    expect(target.text()).toContain('9');
    expect(target.text()).toContain('12');
  });

  it('renders each record\'s reason codes', async () => {
    const { wrapper } = mountScreen();
    await settle(wrapper);

    const records = wrapper.find('[data-records]');

    expect(records.text()).toContain('active_next_date_past');
    expect(records.text()).toContain('finite_term_from_product');
  });

  it('filters the record list from the readiness counts', async () => {
    const { wrapper } = mountScreen();
    await settle(wrapper);

    apiMock.mockClear();
    await wrapper.find('[data-filter-outcome="blocked"]').trigger('click');

    expect(apiMock).toHaveBeenCalledTimes(1);
    expect(apiMock.mock.calls[0][1]).toContain('outcome=blocked');
  });

  /**
   * §3's topology table and §4.1 make the package route THE route for the
   * reference target: two WordPress installations, two prefixes. A screen that
   * could only audit `live` could not audit the migration this was written for.
   */
  it('can audit a package, and prepare one', async () => {
    const { wrapper } = mountScreen();
    await settle(wrapper);

    await wrapper.find('[data-source-mode="package"]').trigger('change');
    await nextTick();

    const path = wrapper.find('[data-package-path]');

    expect(path.exists()).toBe(true);

    await path.setValue('/srv/private/source.ndjson');

    apiMock.mockClear();
    await wrapper.find('[data-action="audit-package"]').trigger('click');

    expect(apiMock.mock.calls[0][1]).toContain('source=package');
    expect(apiMock.mock.calls[0][1]).toContain(encodeURIComponent('/srv/private/source.ndjson'));
  });

  it('refuses the retired package writer locally', async () => {
    const { wrapper } = mountScreen();
    await settle(wrapper);

    await wrapper.find('[data-source-mode="package"]').trigger('change');
    await nextTick();
    await wrapper.find('[data-package-path]').setValue('/srv/private/source.ndjson');

    const prepare = wrapper.find('[data-action="prepare-package"]');

    expect(prepare.text().toLowerCase()).toContain('writes');

    apiMock.mockImplementation((method, endpoint) => {
      if (endpoint === 'subscriptions/packages/prepare') {
        return { prepared: true, source_key: 'lapka-club', path: '/srv/private/source.ndjson' };
      }
      return endpoint.startsWith('subscriptions/audit/records') ? records() : auditDocument();
    });

    apiMock.mockClear();
    await prepare.trigger('click');
    await settle(wrapper);

    expect(apiMock).not.toHaveBeenCalled();
    expect(wrapper.text()).toContain('legacy_subscription_v1_package_write_closed');
  });

  it('deep-links a blocked mapping row to the product that blocked it', async () => {
    const { wrapper, migration } = mountScreen();
    await settle(wrapper);

    await wrapper.find('[data-records] [data-goto-map]').trigger('click');

    expect(migration.actions.goToMapping).toHaveBeenCalledWith({ wc_id: 770001, name: '' });
  });

  it('reports a failed audit rather than an empty screen', async () => {
    apiMock.mockRejectedValue(new Error('WooCommerce Subscriptions is not readable here'));

    const migration = {
      state: reactive({ screen: 'subscription-audit', scope: { mode: 'everything' } }),
      actions: { goToScreen: vi.fn(), goToMapping: vi.fn() },
    };

    const wrapper = mount(SubscriptionAuditScreen, {
      global: { provide: { migration, theme: fakeTheme() } },
    });

    await settle(wrapper);

    expect(wrapper.text()).toContain('not readable');
  });
});

describe('SelectScreen subscription audit entry', () => {
  beforeEach(() => {
    apiMock.mockReset();
    apiMock.mockResolvedValue({});
  });

  function context() {
    const state = reactive({
      selectedEntities: ['product'],
      counts: { product: 27, subscription: 564 },
      dryRun: false,
      useBackground: false,
      backgroundAvailable: true,
      migrating: false,
      preflight: { checks: { wc_subscriptions: { active: true } } },
      preview: null,
      previewLoading: false,
      previewSupport: 'yes',
      scope: {
        mode: 'everything',
        since: null,
        products: [],
        customers: [],
        includeOrdersForProducts: false,
      },
    });

    const actions = {
      setScopeMode: vi.fn(),
      refreshPreview: vi.fn(),
      applyRemedy: vi.fn(),
      startMigration: vi.fn(),
      advanceFromSelect: vi.fn(),
      goToScreen: vi.fn(),
    };

    return { state, actions };
  }

  it('offers the read-only subscription audit as its own mode', async () => {
    const ctx = context();
    const wrapper = mount(SelectScreen, {
      global: { provide: { migration: ctx, theme: fakeTheme() } },
    });

    const button = wrapper.find('[data-action="subscription-audit"]');

    expect(button.exists()).toBe(true);

    await button.trigger('click');

    expect(ctx.actions.goToScreen).toHaveBeenCalledWith('subscription-audit');
  });

  /**
   * The dry-run copy was corrected in three components already; a fourth
   * phrasing would put the plugin back where it started.
   */
  it('still says the dry run writes CartShift simulation rows', () => {
    const wrapper = mount(SelectScreen, {
      global: { provide: { migration: context(), theme: fakeTheme() } },
    });

    expect(wrapper.text()).toContain('CartShift simulation rows');
  });
});
