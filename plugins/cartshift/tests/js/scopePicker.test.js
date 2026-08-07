import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';

const { apiMock } = vi.hoisted(() => ({ apiMock: vi.fn() }));

vi.mock('@/composables/useApi.js', () => ({ useApi: () => ({ api: apiMock }) }));

const { default: ScopePicker } = await import('@/components/ScopePicker.vue');

describe('ScopePicker', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    apiMock.mockReset();
  });
  afterEach(() => vi.useRealTimers());

  it('searches on the debounce, not on the keystroke', async () => {
    apiMock.mockResolvedValue({ results: [], truncated: false });

    const wrapper = mount(ScopePicker, { props: { modelValue: [], kind: 'product' } });

    await wrapper.find('input[type="search"]').setValue('hood');

    expect(apiMock).not.toHaveBeenCalled();

    vi.advanceTimersByTime(300);
    await nextTick();

    // useApi's api() signature is api(method, endpoint, body) — there is no
    // separate query argument (confirmed against useApi.js and
    // useMigration.js's loadLog(), which bakes ?page=N into the endpoint the
    // same way). So the query string travels in the endpoint itself.
    expect(apiMock).toHaveBeenCalledWith('GET', 'scope/search?type=product&q=hood&limit=20');
  });

  it('emits a new array rather than mutating the prop', async () => {
    apiMock.mockResolvedValue({
      results: [{ id: '12', kind: 'product', label: 'Blue Hoodie', sublabel: 'SKU HOOD-1' }],
      truncated: false,
    });

    const modelValue = [];
    const wrapper = mount(ScopePicker, { props: { modelValue, kind: 'product' } });

    await wrapper.find('input[type="search"]').setValue('hood');
    vi.advanceTimersByTime(300);
    await vi.runAllTicks();
    await nextTick();

    await wrapper.find('button.cartshift-scope-result').trigger('click');

    const emitted = wrapper.emitted('update:modelValue');

    expect(emitted[0][0]).toEqual([
      { id: '12', kind: 'product', label: 'Blue Hoodie', sublabel: 'SKU HOOD-1' },
    ]);
    expect(modelValue).toEqual([]);
  });

  it('says there is more rather than implying it showed everything', async () => {
    apiMock.mockResolvedValue({
      results: [{ id: '12', kind: 'product', label: 'Blue Hoodie', sublabel: '' }],
      truncated: true,
    });

    const wrapper = mount(ScopePicker, { props: { modelValue: [], kind: 'product' } });

    await wrapper.find('input[type="search"]').setValue('hood');
    vi.advanceTimersByTime(300);
    await vi.runAllTicks();
    await nextTick();

    expect(wrapper.text()).toContain('keep typing');
  });

  it('removes a chosen item without touching the rest', async () => {
    const wrapper = mount(ScopePicker, {
      props: {
        modelValue: [
          { id: '12', kind: 'product', label: 'Blue Hoodie', sublabel: '' },
          { id: '44', kind: 'product', label: 'Red Hoodie', sublabel: '' },
        ],
        kind: 'product',
      },
    });

    await wrapper.findAll('button.cartshift-scope-chip-remove')[0].trigger('click');

    expect(wrapper.emitted('update:modelValue')[0][0]).toEqual([
      { id: '44', kind: 'product', label: 'Red Hoodie', sublabel: '' },
    ]);
  });
});
