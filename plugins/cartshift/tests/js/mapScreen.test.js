import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick, ref } from 'vue';

const { apiMock } = vi.hoisted(() => ({ apiMock: vi.fn() }));

vi.mock('@/composables/useApi.js', () => ({ useApi: () => ({ api: apiMock }) }));

const { default: MapRow } = await import('@/components/MapRow.vue');
const { default: MapScreen } = await import('@/components/MapScreen.vue');

function row(overrides = {}) {
  return {
    wc_id: 1,
    name: 'Blue Hoodie',
    wc_type: 'variable',
    sku: 'HOOD-1',
    variations: 3,
    order_count: 88,
    band: 'likely',
    suggested: 900,
    candidates: [
      {
        id: 900,
        label: 'Blue Hoodie',
        band: 'likely',
        score: 1.2,
        variant: { matched: 2, total: 3, adds: 1, map: { 11: 501, 12: 502 }, orphans: [] },
      },
      {
        id: 901,
        label: 'Red Hoodie',
        band: 'likely',
        score: 0.6,
        variant: { matched: 3, total: 3, adds: 0, map: { 11: 601, 12: 602, 13: 603 }, orphans: [] },
      },
    ],
    variant: { matched: 2, total: 3, adds: 1, map: { 11: 501, 12: 502 }, orphans: [] },
    decision: null,
    ...overrides,
  };
}

describe('MapRow', () => {
  it('shows the order count, because that is what says which rows matter', () => {
    const wrapper = mount(MapRow, { props: { row: row() } });

    expect(wrapper.text()).toContain('88');
  });

  it('warns before adding a variant to a hand-made product', () => {
    const wrapper = mount(MapRow, { props: { row: row() } });

    expect(wrapper.text()).toContain('2/3');
    expect(wrapper.text()).toContain('adds 1');
  });

  it('warns when the linked product has no files of its own', () => {
    const wrapper = mount(MapRow, { props: { row: row({ downloads_lost: true }) } });

    // CartShift will not write the Woo product's files into a product the
    // owner built by hand, so the customer-facing consequence has to be on
    // the row before anything is pressed.
    expect(wrapper.text()).toContain('no files');
  });

  it('says nothing about files when none are lost', () => {
    const wrapper = mount(MapRow, { props: { row: row() } });

    expect(wrapper.text()).not.toContain('no files');
  });

  it('offers no link button when there is no candidate', () => {
    const wrapper = mount(MapRow, {
      props: { row: row({ band: 'none', suggested: null, candidates: [], variant: null }) },
    });

    expect(wrapper.find('[data-action="link"]').exists()).toBe(false);
    expect(wrapper.find('[data-action="create"]').exists()).toBe(true);
    expect(wrapper.find('[data-action="skip"]').exists()).toBe(true);
  });

  it('emits the chosen decision', async () => {
    const wrapper = mount(MapRow, { props: { row: row() } });

    await wrapper.find('[data-action="skip"]').trigger('click');

    expect(wrapper.emitted('decide')[0]).toEqual(['skip']);
  });

  it('changing the candidate select updates the row suggestion', async () => {
    const r = row();
    const wrapper = mount(MapRow, { props: { row: r } });

    await wrapper.find('select').setValue('901');

    expect(wrapper.emitted('suggest')[0]).toEqual([901]);
  });

  // ── Subscription rows ───────────────────────────────────
  //
  // A subscription's variation is a billing contract, not a size. The operator
  // has to see what each target variation would charge, how often, with what
  // trial and for how many cycles — and why the ones that do not fit do not.

  function subscriptionRow(overrides = {}) {
    return row({
      wc_id: 770002,
      name: 'Klubu Przyjaciol Psow rocznie',
      wc_type: 'subscription',
      band: 'none',
      suggested: 88,
      candidates: [{ id: 88, label: 'Klubu Przyjaciol Psow', band: 'none', score: 0 }],
      variant: {
        matched: 1,
        total: 1,
        adds: 0,
        map: { 770002: 4102 },
        orphans: [],
        errors: [],
        warnings: [],
        sources: [
          {
            id: 770002,
            name: 'Default',
            subscription: true,
            interval: 'yearly',
            selected: 4102,
            options: [
              {
                id: 4101,
                name: 'Miesiecznie',
                payment_type: 'subscription',
                repeat_interval: 'monthly',
                price: 29,
                trial_days: 0,
                times: 0,
                compatible: false,
                errors: ['target_variation_contract_mismatch'],
                warnings: [],
              },
              {
                id: 4102,
                name: 'Rocznie',
                payment_type: 'subscription',
                repeat_interval: 'yearly',
                price: 290,
                trial_days: 14,
                times: 3,
                compatible: true,
                errors: [],
                warnings: ['target_price_differs_from_source'],
              },
            ],
          },
        ],
      },
      ...overrides,
    });
  }

  it('lists every target variation with its billing contract', () => {
    const wrapper = mount(MapRow, { props: { row: subscriptionRow() } });

    const monthly = wrapper.find('[data-variation="4101"]');
    const yearly = wrapper.find('[data-variation="4102"]');

    expect(monthly.text()).toContain('Miesiecznie');
    expect(monthly.text()).toContain('monthly');
    expect(monthly.text()).toContain('29');

    expect(yearly.text()).toContain('Rocznie');
    expect(yearly.text()).toContain('yearly');
    expect(yearly.text()).toContain('290');
    // Trial and finite-cycle detail, which is the difference between a plan
    // that bills three times and one that bills for ever. Asserted on the
    // option itself, because '3' appears elsewhere on every row.
    expect(yearly.text()).toContain('14');
    expect(yearly.text()).toContain('3 payments');
    expect(monthly.text()).toContain('runs until cancelled');
  });

  it('says why an incompatible variation cannot be chosen', () => {
    const wrapper = mount(MapRow, { props: { row: subscriptionRow() } });

    const option = wrapper.find('[data-variation="4101"]');

    expect(option.exists()).toBe(true);
    expect(option.text()).toContain('interval');
    expect(option.find('input').attributes('disabled')).toBeDefined();
  });

  // Reserved is not a cadence problem, and saying "this product bills monthly
  // and the chosen variation bills monthly" is how a screen loses an owner's
  // trust in one sentence.
  it('says when a variation is refused because another one already took it', () => {
    const taken = subscriptionRow();

    taken.variant.sources[0].selected = null;
    taken.variant.sources[0].options[1].compatible = false;
    taken.variant.sources[0].options[1].errors = ['target_variation_contract_collision'];

    const wrapper = mount(MapRow, { props: { row: taken } });
    const option = wrapper.find('[data-variation="4102"]');

    expect(option.text()).toContain('Already used by another variation');
    expect(option.text()).not.toContain('interval');
    expect(option.find('input').attributes('disabled')).toBeDefined();
  });

  it('says the source contract is preserved when the list price differs', () => {
    const wrapper = mount(MapRow, { props: { row: subscriptionRow() } });

    expect(wrapper.find('[data-variation="4102"]').text()).toContain('preserved');
  });

  // trigger('change') rather than setValue(): the yearly option is already the
  // selected one, and Vue Test Utils' setChecked() returns early on a radio
  // that is checked — which would assert nothing at all.
  it('emits the chosen variation', async () => {
    const wrapper = mount(MapRow, { props: { row: subscriptionRow() } });

    await wrapper.find('[data-variation="4102"] input').trigger('change');

    expect(wrapper.emitted('choose-variation')[0]).toEqual([770002, 4102]);
  });

  it('offers no link button while a source variation has no compatible target', () => {
    const blocked = subscriptionRow();

    blocked.variant.map = {};
    blocked.variant.sources[0].selected = null;
    blocked.variant.errors = [
      { code: 'target_variation_missing', source_variation_id: 770002, message: 'No compatible variation.' },
    ];

    const wrapper = mount(MapRow, { props: { row: blocked } });

    expect(wrapper.find('[data-action="link"]').exists()).toBe(false);
    expect(wrapper.text()).toContain('No compatible variation.');
  });

  /**
   * And Create is not the way round it.
   *
   * The Create route runs ProductMigrator and VariationMapper, which read the
   * cadence leniently — week/2 becomes weekly, month/2 and month/12 become
   * monthly — so an operator answering "CartShift cannot express this contract"
   * with Create would get a FluentCart product quietly claiming a different
   * one. The run refuses it anyway, which made the button's only possible
   * outcome a product silently dropped from the migration.
   */
  it('offers no create button on a blocked subscription row', () => {
    const blocked = subscriptionRow();

    blocked.variant.map = {};
    blocked.variant.sources[0].selected = null;
    blocked.variant.errors = [
      { code: 'unsupported_billing_cadence', source_variation_id: 770002, message: 'Cannot express this.' },
    ];

    const wrapper = mount(MapRow, { props: { row: blocked } });

    expect(wrapper.find('[data-action="create"]').exists()).toBe(false);
    expect(wrapper.find('[data-action="skip"]').exists()).toBe(true);
  });

  it('still offers create on a subscription row with nothing blocking it', () => {
    expect(
      mount(MapRow, { props: { row: subscriptionRow() } }).find('[data-action="create"]').exists(),
    ).toBe(true);
  });

  it('offers the shared-target opt-in only for subscription rows', () => {
    expect(mount(MapRow, { props: { row: subscriptionRow() } }).find('[data-shared-target]').exists()).toBe(true);
    expect(mount(MapRow, { props: { row: row() } }).find('[data-shared-target]').exists()).toBe(false);
  });

  it('emits the shared-target opt-in', async () => {
    const wrapper = mount(MapRow, { props: { row: subscriptionRow() } });

    await wrapper.find('[data-shared-target] input').setValue(true);

    expect(wrapper.emitted('share-target')[0]).toEqual([true]);
  });

  it('offers a manual search for a row the matcher gave nothing', () => {
    const wrapper = mount(MapRow, {
      props: { row: row({ band: 'none', suggested: null, candidates: [], variant: null }) },
    });

    expect(wrapper.find('[data-action="search"]').exists()).toBe(true);
  });
});

describe('MapScreen', () => {
  beforeEach(() => {
    apiMock.mockReset();
  });

  // PageHeader (rendered by every screen, MapScreen included) does a required
  // inject('theme') — App.vue always provides it in the real app, the same
  // gap selectScreen.test.js's mountScreen() already had to close. Without
  // it, mounting throws (`Cannot read properties of undefined (reading
  // 'themeMode')`) before a single assertion runs.
  function fakeTheme() {
    return { themeMode: ref('light'), changeTheme: vi.fn() };
  }

  // App.vue provides the shared wizard state; MapScreen injects it rather than
  // calling useMigration() again, so the test has to supply it. The actions
  // object defaults to a fresh stub but can be supplied by the caller so a
  // test can keep its own reference and assert on it afterwards (see the
  // Continue/Back test below). stateOverrides lets a test put the shared
  // migration state (not useMapping's own state) into whatever shape it needs
  // — e.g. { migrating: true } for the double-submit guard test.
  // The scope is not optional furniture: MapScreen serialises it on mount so
  // the screen shows the products the run will actually migrate, and
  // applyRunMode() writes back into it on Continue.
  function fakeScope() {
    return {
      mode: 'everything',
      since: null,
      products: [],
      customers: [],
      includeOrdersForProducts: false,
    };
  }

  function mountScreen(actions = { startMigration: vi.fn(), goToScreen: vi.fn() }, stateOverrides = {}) {
    return mount(MapScreen, {
      global: {
        provide: {
          migration: {
            state: { screen: 'map', scope: fakeScope(), ...stateOverrides },
            actions,
          },
          theme: fakeTheme(),
        },
      },
    });
  }

  /**
   * The subscription audit sends blocked rows here. A bare screen change left
   * the operator to find one product among two thousand from a number they
   * were shown on the previous screen — the same complaint the stale-variation
   * refusal message had, arriving through a button instead of a message.
   */
  it('names the row the subscription audit sent the operator here to re-decide', async () => {
    apiMock.mockResolvedValue({ rows: [row({ wc_id: 770001 })], total: 1, fc_product_count: 4 });

    const clearMapFocus = vi.fn();
    const wrapper = mountScreen(
      { startMigration: vi.fn(), goToScreen: vi.fn(), clearMapFocus },
      { mapFocus: { wc_id: 770001, name: 'Monthly subscription' } }
    );

    await nextTick();
    await nextTick();

    const banner = wrapper.find('[data-map-focus]');

    expect(banner.exists()).toBe(true);
    expect(banner.text()).toContain('Monthly subscription');
    expect(banner.text()).toContain('770001');

    await banner.find('[data-action="clear-focus"]').trigger('click');

    expect(clearMapFocus).toHaveBeenCalled();
  });

  it('shows no focus banner when the operator came here on their own', async () => {
    apiMock.mockResolvedValue({ rows: [row()], total: 1, fc_product_count: 4 });

    const wrapper = mountScreen();
    await nextTick();
    await nextTick();

    expect(wrapper.find('[data-map-focus]').exists()).toBe(false);
  });

  it('offers a way back to the subscription audit', async () => {
    apiMock.mockResolvedValue({ rows: [row()], total: 1, fc_product_count: 4 });

    const actions = { startMigration: vi.fn(), goToScreen: vi.fn() };
    const wrapper = mountScreen(actions);
    await nextTick();
    await nextTick();

    await wrapper.find('[data-action="subscription-audit"]').trigger('click');

    expect(actions.goToScreen).toHaveBeenCalledWith('subscription-audit');
  });

  it('renders a band header with a bulk button per populated band', async () => {
    apiMock.mockResolvedValue({
      rows: [row({ wc_id: 1, band: 'strong' }), row({ wc_id: 2, band: 'none', suggested: null, candidates: [], variant: null })],
      total: 2,
      fc_product_count: 4,
    });

    const wrapper = mountScreen();
    await nextTick();
    await nextTick();

    expect(wrapper.find('[data-band="strong"]').exists()).toBe(true);
    expect(wrapper.find('[data-band="none"]').exists()).toBe(true);
    expect(wrapper.find('[data-band="likely"]').exists()).toBe(false);
  });

  it('does not offer a bulk link on the no-candidate band', async () => {
    apiMock.mockResolvedValue({
      rows: [row({ wc_id: 2, band: 'none', suggested: null, candidates: [], variant: null })],
      total: 1,
      fc_product_count: 0,
    });

    const wrapper = mountScreen();
    await nextTick();
    await nextTick();

    const band = wrapper.find('[data-band="none"]');

    expect(band.find('[data-bulk="link"]').exists()).toBe(false);
    expect(band.find('[data-bulk="create"]').exists()).toBe(true);
    expect(band.find('[data-bulk="skip"]').exists()).toBe(true);
  });

  it('surfaces a load error', async () => {
    apiMock.mockRejectedValue(new Error('nope'));

    const wrapper = mountScreen();
    await nextTick();
    await nextTick();

    expect(wrapper.text()).toContain('nope');
  });

  // The architectural trap this task is built around: MapScreen must inject
  // the wizard's shared actions, not call useMigration() a second time. If it
  // ever did, this Continue button would call a private startMigration() that
  // nothing else observes, and would silently do nothing from the owner's
  // point of view. Asserting on the injected stub is what would catch that.
  it('Continue advances the wizard via the injected startMigration, Back returns to select', async () => {
    apiMock.mockResolvedValue({ rows: [], total: 0, fc_product_count: 0 });

    const actions = { startMigration: vi.fn(), goToScreen: vi.fn() };
    const wrapper = mountScreen(actions);
    await nextTick();
    await nextTick();

    const buttons = wrapper.find('.cartshift-map-continue').findAll('button');

    await buttons[0].trigger('click');
    await buttons[1].trigger('click');

    expect(actions.startMigration).toHaveBeenCalledTimes(1);
    expect(actions.goToScreen).toHaveBeenCalledWith('select');
  });

  // SelectScreen's own primary button guards against a double-submit with
  // :disabled="state.migrating" (the same flag startMigration() itself flips
  // true as its first action). Continue calls the same startMigration()
  // directly, and landing a second one here is worse than elsewhere — it
  // fires right after the owner has committed a set of mapping decisions.
  // This follows the identical, established convention rather than inventing
  // a separate submitting flag.
  it('disables Continue while a migration is already starting, mirroring SelectScreen\'s guard', async () => {
    apiMock.mockResolvedValue({ rows: [], total: 0, fc_product_count: 0 });

    const actions = { startMigration: vi.fn(), goToScreen: vi.fn() };
    const wrapper = mountScreen(actions, { migrating: true });
    await nextTick();
    await nextTick();

    const continueButton = wrapper.find('.cartshift-map-continue button.button-primary');

    expect(continueButton.attributes('disabled')).toBeDefined();

    // A disabled button must not fire its handler even if something still
    // dispatches a click at it.
    await continueButton.trigger('click');

    expect(actions.startMigration).not.toHaveBeenCalled();
  });

  // "Only what I mapped" builds its whitelist from the rows on screen, and the
  // screen fills page by page — so Continue pressed halfway through would
  // silently drop everything not yet loaded.
  it('will not start a run while the product list is still loading', async () => {
    let release;
    apiMock.mockImplementation(
      () =>
        new Promise((resolve) => {
          release = resolve;
        })
    );

    const actions = { startMigration: vi.fn(), goToScreen: vi.fn() };
    const wrapper = mountScreen(actions);
    await nextTick();

    const continueButton = wrapper.find('.cartshift-map-continue button.button-primary');

    expect(continueButton.attributes('disabled')).toBeDefined();

    await continueButton.trigger('click');

    expect(actions.startMigration).not.toHaveBeenCalled();

    release({ rows: [], total: 0, fc_product_count: 0 });
    await nextTick();
    await nextTick();

    expect(
      wrapper.find('.cartshift-map-continue button.button-primary').attributes('disabled')
    ).toBeUndefined();
  });

  // The screen has to say which run it is describing, or its counts belong to
  // a catalogue the run was never going to touch.
  it('asks for the products the run is scoped to, not the whole catalogue', async () => {
    apiMock.mockResolvedValue({ rows: [], total: 0, fc_product_count: 0 });

    const actions = { startMigration: vi.fn(), goToScreen: vi.fn() };
    const scope = { ...fakeScope(), mode: 'explicit', products: [{ id: 12 }, { id: 13 }] };

    mountScreen(actions, { scope });
    await nextTick();
    await nextTick();

    const url = apiMock.mock.calls[0][1];

    expect(url).toContain('scope=');
    expect(JSON.parse(decodeURIComponent(url.split('scope=')[1])).product_ids).toEqual([12, 13]);
  });

  // "Only what I mapped" and "everything since a date" are two modes of one
  // exclusive scope, so offering both means silently discarding whichever the
  // owner chose second — and the one discarded is the date cutoff, which
  // *widens* the run to every order those products ever had.
  it('does not offer "only what I mapped" for a date-limited run', async () => {
    apiMock.mockResolvedValue({
      rows: [row({ wc_id: 1, decision: { decision: 'link', fc_post_id: 900 } })],
      total: 1,
      fc_product_count: 2,
    });

    const actions = { startMigration: vi.fn(), goToScreen: vi.fn() };
    const scope = { ...fakeScope(), mode: 'since', since: '2024-03-01' };
    const wrapper = mountScreen(actions, { scope });
    await nextTick();
    await nextTick();

    expect(wrapper.find('input[value="only-mapped"]').attributes('disabled')).toBeDefined();
    expect(wrapper.find('[data-run-mode-blocked]').exists()).toBe(true);

    await wrapper.find('.cartshift-map-continue button.button-primary').trigger('click');

    expect(scope.mode).toBe('since');
    expect(scope.since).toBe('2024-03-01');
    expect(actions.startMigration).toHaveBeenCalledTimes(1);
  });

  // The control was declared, commented and bound to two radios, and nothing
  // read it: Continue serialised useMigration's untouched scope either way.
  it('"only what I mapped" turns the decided set into an explicit scope before starting', async () => {
    apiMock.mockResolvedValue({
      rows: [
        row({ wc_id: 1, decision: { decision: 'link', fc_post_id: 900 } }),
        row({ wc_id: 2, decision: { decision: 'skip' } }),
        row({ wc_id: 3, decision: null }),
      ],
      total: 3,
      fc_product_count: 2,
    });

    const actions = { startMigration: vi.fn(), goToScreen: vi.fn() };
    const scope = fakeScope();
    const wrapper = mountScreen(actions, { scope });
    await nextTick();
    await nextTick();

    await wrapper.find('input[value="only-mapped"]').setValue();
    await wrapper.find('.cartshift-map-continue button.button-primary').trigger('click');

    expect(scope.mode).toBe('explicit');
    expect(scope.products).toEqual([{ id: 1 }]);
    expect(actions.startMigration).toHaveBeenCalledTimes(1);
  });

  it('the default run mode still migrates everything the scope already said', async () => {
    apiMock.mockResolvedValue({
      rows: [row({ wc_id: 1, decision: { decision: 'link', fc_post_id: 900 } })],
      total: 1,
      fc_product_count: 2,
    });

    const actions = { startMigration: vi.fn(), goToScreen: vi.fn() };
    const scope = fakeScope();
    const wrapper = mountScreen(actions, { scope });
    await nextTick();
    await nextTick();

    await wrapper.find('.cartshift-map-continue button.button-primary').trigger('click');

    expect(scope.mode).toBe('everything');
  });

  // reset() deliberately spares the staging table, so without this control a
  // stale decision set governs every later run for ever.
  it('offers a guarded way to clear every mapping', async () => {
    apiMock.mockResolvedValue({
      rows: [row({ wc_id: 1, decision: { decision: 'link' } })],
      total: 1,
      fc_product_count: 2,
    });

    const wrapper = mountScreen();
    await nextTick();
    await nextTick();

    apiMock.mockClear();

    await wrapper.find('[data-action="clear"]').trigger('click');
    await nextTick();

    // Guarded: the click opens a confirmation, it does not clear anything.
    expect(apiMock).not.toHaveBeenCalled();
    expect(wrapper.find('.cartshift-modal').exists()).toBe(true);

    apiMock.mockResolvedValue({ cleared: true });

    await wrapper.find('.cartshift-modal .button-link-delete').trigger('click');
    await nextTick();

    expect(apiMock).toHaveBeenCalledWith('POST', 'mapping/clear', {});
  });

  // ── Manual catalogue search ─────────────────────────────
  //
  // ProductMatcher scored both Lapka source products `band=none` and rows()
  // drops every `none` candidate, so without this the only two products the
  // migration exists for cannot be mapped at all.

  it('opens a catalogue search for a row with no candidate and links what is picked', async () => {
    const noCandidate = row({
      wc_id: 770002,
      name: 'Klubu Przyjaciol Psow rocznie',
      wc_type: 'subscription',
      band: 'none',
      suggested: null,
      candidates: [],
      variant: null,
    });

    apiMock.mockResolvedValue({ rows: [noCandidate], total: 1, fc_product_count: 1 });

    const wrapper = mountScreen();
    await nextTick();
    await nextTick();

    apiMock.mockResolvedValue({
      products: [{ id: 88, name: 'Klubu Przyjaciol Psow', variations: [] }],
      total: 1,
      page: 1,
      per_page: 50,
    });

    await wrapper.find('[data-action="search"]').trigger('click');
    await nextTick();

    const dialog = wrapper.find('[data-catalogue-search]');

    expect(dialog.exists()).toBe(true);
    expect(dialog.text()).toContain('Klubu Przyjaciol Psow');

    apiMock.mockResolvedValue({
      variant: { matched: 1, total: 1, adds: 0, map: { 770002: 4102 }, orphans: [], errors: [], warnings: [], sources: [] },
      label: 'Klubu Przyjaciol Psow',
    });

    await dialog.find('[data-catalogue-product="88"]').trigger('click');
    await nextTick();

    expect(apiMock).toHaveBeenCalledWith('GET', 'mapping/variants?wc_id=770002&fc_post_id=88');
  });

  it('says so when it could not load every product it counted', async () => {
    apiMock
      .mockResolvedValueOnce({ rows: [row({ wc_id: 1 })], total: 40, fc_product_count: 2 })
      .mockResolvedValueOnce({ rows: [], total: 40, fc_product_count: 2 });

    const wrapper = mountScreen();
    await nextTick();
    await nextTick();
    await nextTick();

    const notice = wrapper.find('[data-partial-load]');

    expect(notice.exists()).toBe(true);
    expect(notice.text()).toContain('1 of 40');
  });
});
