import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive, nextTick } from 'vue';
import SelectScreen from '@/components/SelectScreen.vue';
import ScopePicker from '@/components/ScopePicker.vue';
import MigrationReceipt from '@/components/MigrationReceipt.vue';

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
  return mount(SelectScreen, { global: { provide: { migration: ctx } } });
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

  it('debounces the preview refresh rather than asking per keystroke', async () => {
    const ctx = context();
    const wrapper = mountScreen(ctx);

    await wrapper.findAll('input[type="radio"][name="cartshift-scope-mode"]')[1].setValue();
    await wrapper.find('input[type="date"].cartshift-scope-since').setValue('2024-03-01');

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
    ctx.state.preview = {
      counts: {},
      consequences: [],
      closure: { products: 12, customers: 31 },
      too_large: false,
    };
    await nextTick();

    expect(wrapper.find('input.cartshift-upward-offer').exists()).toBe(true);
    // The spec's sentence, and the numbers come off preview.closure.
    expect(wrapper.text()).toContain('31 customers');
    expect(wrapper.text()).toContain('12 more products');
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
});
