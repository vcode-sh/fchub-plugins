import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive, ref, nextTick } from 'vue';
import SelectScreen from '@/components/SelectScreen.vue';
import ScopePicker from '@/components/ScopePicker.vue';
import MigrationReceipt from '@/components/MigrationReceipt.vue';

// PageHeader (rendered by every screen) does a required inject('theme') —
// App.vue always provides it in the real app, so the fix here is the test
// providing the same shape, not loosening the component's contract.
function fakeTheme() {
  return { themeMode: ref('light'), changeTheme: vi.fn() };
}

function context(overrides = {}) {
  const state = reactive({
    selectedEntities: ['product', 'customer', 'coupon', 'order'],
    counts: { product: 27, customer: 363, coupon: 50, order: 699, subscription: 30 },
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
    ...overrides,
  });

  const actions = {
    setScopeMode: vi.fn((mode) => {
      state.scope.mode = mode;
    }),
    refreshPreview: vi.fn(),
    applyRemedy: vi.fn(),
    startMigration: vi.fn(),
    goToScreen: vi.fn(),
    toggleEntity: vi.fn(),
  };

  return { state, actions };
}

function mountScreen(ctx) {
  return mount(SelectScreen, { global: { provide: { migration: ctx, theme: fakeTheme() } } });
}

describe('SelectScreen', () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => vi.useRealTimers());

  it('offers three doors and starts on everything', () => {
    const ctx = context();
    const wrapper = mountScreen(ctx);

    const radios = wrapper.findAll('input[type="radio"][name="cartshift-scope-mode"]');

    expect(radios).toHaveLength(3);
    expect(radios.map((r) => r.attributes('value'))).toEqual(['everything', 'since', 'explicit']);
    expect(radios[0].element.checked).toBe(true);
  });

  it('shows no date input and no pickers on the default door', () => {
    const wrapper = mountScreen(context());

    expect(wrapper.find('input[type="date"].cartshift-scope-since').exists()).toBe(false);
    expect(wrapper.findAllComponents(ScopePicker)).toHaveLength(0);
  });

  it('reveals the date input on the since door', async () => {
    const ctx = context();
    const wrapper = mountScreen(ctx);

    await wrapper.findAll('input[type="radio"][name="cartshift-scope-mode"]')[1].setValue();

    expect(ctx.actions.setScopeMode).toHaveBeenCalledWith('since');
    expect(wrapper.find('input[type="date"].cartshift-scope-since').exists()).toBe(true);
    expect(wrapper.findAllComponents(ScopePicker)).toHaveLength(0);
  });

  it('reveals a product picker and a customer picker on the explicit door', async () => {
    const ctx = context();
    const wrapper = mountScreen(ctx);

    await wrapper.findAll('input[type="radio"][name="cartshift-scope-mode"]')[2].setValue();

    const pickers = wrapper.findAllComponents(ScopePicker);

    expect(ctx.actions.setScopeMode).toHaveBeenCalledWith('explicit');
    expect(pickers).toHaveLength(2);
    expect(pickers[0].props('kind')).toBe('product');
    expect(pickers[1].props('kind')).toBe('customer');
  });

  it('refreshes the preview once on arrival, so the receipt is not blank on the default door', () => {
    const ctx = context();
    mountScreen(ctx);

    expect(ctx.actions.refreshPreview).toHaveBeenCalledTimes(1);
  });

  it('debounces the preview refresh rather than asking per keystroke', async () => {
    const ctx = context();
    const wrapper = mountScreen(ctx);

    // The mount-time refresh above already happened; everything from here
    // is about edits after arrival.
    ctx.actions.refreshPreview.mockClear();

    await wrapper.findAll('input[type="radio"][name="cartshift-scope-mode"]')[1].setValue();
    await wrapper.find('input[type="date"].cartshift-scope-since').setValue('2024-03-01');

    expect(ctx.actions.refreshPreview).not.toHaveBeenCalled();

    vi.advanceTimersByTime(300);
    await nextTick();

    expect(ctx.actions.refreshPreview).toHaveBeenCalledTimes(1);
  });

  it('also refreshes when an entity is unticked, not only on a scope edit', async () => {
    const ctx = context();
    const wrapper = mountScreen(ctx);

    ctx.actions.refreshPreview.mockClear();

    // toggleEntity() is not routed through the stubbed action (same pattern
    // as before Task 14), so drive the same mutation the checkbox handler
    // makes and let the watch on state.selectedEntities pick it up.
    ctx.state.selectedEntities = ctx.state.selectedEntities.filter((k) => k !== 'coupon');
    await nextTick();

    expect(ctx.actions.refreshPreview).not.toHaveBeenCalled();

    vi.advanceTimersByTime(300);
    await nextTick();

    expect(ctx.actions.refreshPreview).toHaveBeenCalledTimes(1);
  });

  it('withholds the upward offer until products are picked', async () => {
    const ctx = context();
    const wrapper = mountScreen(ctx);

    await wrapper.findAll('input[type="radio"][name="cartshift-scope-mode"]')[2].setValue();

    expect(wrapper.find('input.cartshift-upward-offer').exists()).toBe(false);

    ctx.state.scope.products = [{ id: 12, label: 'Blue Hoodie' }];
    // ScopeResolver only computes the products-containing-orders closure once
    // includeOrdersForProducts is true (seedOrderPredicate()) — before that
    // tick, the closure of the picks alone is what a real preview reports
    // here, i.e. 0/0. The offer must not quote it either way.
    ctx.state.preview = {
      counts: {},
      consequences: [],
      closure: { products: 0, customers: 0 },
      too_large: false,
    };
    await nextTick();

    expect(wrapper.find('input.cartshift-upward-offer').exists()).toBe(true);

    // Qualitative, not quantitative: the closure preview() just returned
    // cannot answer "how many would this add", so the offer must not quote
    // a number here — least of all a bare 0, which would read as "nothing
    // extra" when the truth is simply "not computed yet".
    const offerText = wrapper.find('.cartshift-upward-offer-box').text();
    expect(offerText).not.toMatch(/\b0\b/);
    expect(offerText).toContain('the summary will show exactly how many');
  });

  it('passes a remedy from the receipt straight through to the action', async () => {
    const ctx = context({
      preview: {
        counts: {},
        consequences: [],
        closure: { products: 0, customers: 0 },
        too_large: false,
      },
    });
    const wrapper = mountScreen(ctx);

    const remedy = { action: 'add_products', label: 'Bring those 9 products too', product_ids: [1, 2] };
    wrapper.getComponent(MigrationReceipt).vm.$emit('apply-remedy', remedy);
    await nextTick();

    expect(ctx.actions.applyRemedy).toHaveBeenCalledWith(remedy);
  });

  it('keeps the dry-run and background checkboxes bound to the same state as before', async () => {
    const ctx = context();
    const wrapper = mountScreen(ctx);

    await wrapper.find('input.cartshift-dry-run').setValue(true);
    await wrapper.find('input.cartshift-background').setValue(true);

    expect(ctx.state.dryRun).toBe(true);
    expect(ctx.state.useBackground).toBe(true);
  });

  it('keeps the subscription entity disabled when WooCommerce Subscriptions is absent', () => {
    const ctx = context({ preflight: { checks: { wc_subscriptions: { active: false } } } });
    const wrapper = mountScreen(ctx);

    expect(wrapper.text()).toContain('WooCommerce Subscriptions is not active');
  });

  it('still offers the Back button', async () => {
    const ctx = context();
    const wrapper = mountScreen(ctx);

    await wrapper.findAll('button').find((b) => b.text() === 'Back').trigger('click');

    expect(ctx.actions.goToScreen).toHaveBeenCalledWith('preflight');
  });

  it('surfaces the 422 scope-too-large refusal instead of failing silently', () => {
    // startMigration() (useMigration.js) sets state.screen back to 'select'
    // and state.error to the server's message on a 422 — this screen is
    // where that message has to land, or the owner sees the screen flicker
    // to progress and snap back with nothing said at all.
    const ctx = context({ error: 'This selection is too large to migrate in one run.' });
    const wrapper = mountScreen(ctx);

    expect(wrapper.find('[role="alert"]').exists()).toBe(true);
    expect(wrapper.text()).toContain('This selection is too large to migrate in one run.');
  });

  it('surfaces the empty-selection guard the same way', () => {
    const ctx = context({ error: 'Please select at least one entity type to migrate.' });
    const wrapper = mountScreen(ctx);

    expect(wrapper.text()).toContain('Please select at least one entity type to migrate.');
  });

  it('shows no error banner when there is nothing to say', () => {
    const wrapper = mountScreen(context());

    expect(wrapper.find('[role="alert"]').exists()).toBe(false);
  });
});
