import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import MigrationReceipt from '@/components/MigrationReceipt.vue';

const preview = (overrides = {}) => ({
  counts: { product: 1204, customer: 856, order: 3102, coupon: 18 },
  consequences: [],
  closure: { products: 0, customers: 0 },
  too_large: false,
  ...overrides,
});

describe('MigrationReceipt', () => {
  it('lists a count per entity', () => {
    const wrapper = mount(MigrationReceipt, { props: { preview: preview(), loading: false } });

    expect(wrapper.text()).toContain('1,204');
    expect(wrapper.text()).toContain('Nothing left behind.');
  });

  it('renders a consequence descriptor it has never seen before', () => {
    // The server owns the vocabulary. A code the bundle predates must still
    // render, or every new error code needs a front-end release.
    const wrapper = mount(MigrationReceipt, {
      props: {
        preview: preview({
          consequences: [
            {
              code: 'some_future_code',
              label: 'Something new',
              hint: 'Do the new thing.',
              severity: 'warning',
              category: 'order',
              count: 3,
              remedy: null,
            },
          ],
        }),
        loading: false,
      },
    });

    expect(wrapper.text()).toContain('Something new');
    expect(wrapper.text()).toContain('3');
  });

  it('hides zero-count consequences', () => {
    const wrapper = mount(MigrationReceipt, {
      props: {
        preview: preview({
          consequences: [
            { code: 'product_link_missing', label: 'No product link', hint: '', severity: 'warning', category: 'product', count: 0, remedy: null },
          ],
        }),
        loading: false,
      },
    });

    expect(wrapper.text()).not.toContain('No product link');
    expect(wrapper.text()).toContain('Nothing left behind.');
  });

  it('offers the remedy and emits it', async () => {
    const wrapper = mount(MigrationReceipt, {
      props: {
        preview: preview({
          consequences: [
            {
              code: 'product_link_missing',
              label: 'Order items link to no product',
              hint: '',
              severity: 'warning',
              category: 'product',
              count: 12,
              remedy: { action: 'add_products', label: 'Bring those 9 products too', product_ids: [1, 2] },
            },
          ],
        }),
        loading: false,
      },
    });

    await wrapper.find('button.cartshift-remedy').trigger('click');

    expect(wrapper.emitted('apply-remedy')[0][0].product_ids).toEqual([1, 2]);
  });

  // What the panel says when it has no preview. Whole-shop counts used to
  // stand in here, under "What will come across", while a date or a hand-picked
  // selection was active — a confident answer to a question nobody asked. Each
  // silence now names itself, and none of them carries a number.
  it('says nothing is chosen yet rather than showing whole-shop counts', () => {
    const wrapper = mount(MigrationReceipt, {
      props: { preview: null, loading: false, selected: false },
    });

    expect(wrapper.text()).toContain('Nothing is ticked yet');
    expect(wrapper.find('table').exists()).toBe(false);
  });

  it('says it is still working when a refresh is in flight', () => {
    const wrapper = mount(MigrationReceipt, {
      props: { preview: null, loading: true, selected: true },
    });

    expect(wrapper.text()).toContain('Working out what this selection includes');
  });

  it('explains an older build that has no preview endpoint', () => {
    const wrapper = mount(MigrationReceipt, {
      props: { preview: null, loading: false, selected: true, previewSupport: 'no' },
    });

    expect(wrapper.text()).toContain('cannot work out what a selection includes');
  });

  it('admits a failed lookup instead of answering a different question', () => {
    const wrapper = mount(MigrationReceipt, {
      props: { preview: null, loading: false, selected: true, previewSupport: 'yes' },
    });

    expect(wrapper.text()).toContain('could not be worked out');
    expect(wrapper.text()).not.toMatch(/\d/);
  });

  // The server states minimality on the wire, via `is_minimum` on the
  // descriptor — never inferred from `code` here. These are property tests,
  // not a test naming product_link_missing: a *different* code carrying the
  // flag must still read "at least N", and product_link_missing itself
  // without the flag must not. That is what keeps a future floor-producing
  // consequence safe without a front-end release.
  it('renders "at least N" for any descriptor flagged is_minimum, whatever its code', () => {
    const wrapper = mount(MigrationReceipt, {
      props: {
        preview: preview({
          consequences: [
            {
              code: 'some_other_future_floor',
              label: 'Some other floor',
              hint: '',
              severity: 'warning',
              category: 'order',
              count: 12,
              remedy: null,
              is_minimum: true,
            },
          ],
        }),
        loading: false,
      },
    });

    expect(wrapper.text()).toContain('At least 12');
  });

  it('renders a bare number for product_link_missing when it does not carry is_minimum', () => {
    const wrapper = mount(MigrationReceipt, {
      props: {
        preview: preview({
          consequences: [
            {
              code: 'product_link_missing',
              label: 'Order items link to no product',
              hint: '',
              severity: 'warning',
              category: 'product',
              count: 12,
              remedy: null,
              is_minimum: false,
            },
          ],
        }),
        loading: false,
      },
    });

    expect(wrapper.text()).not.toContain('At least 12');
    expect(wrapper.text()).toContain('12');
  });

  it('renders a bare number when is_minimum is absent entirely (older payload shape)', () => {
    const wrapper = mount(MigrationReceipt, {
      props: {
        preview: preview({
          consequences: [
            {
              code: 'subscription_paused_missing_product',
              label: 'Subscription paused: product not migrated',
              hint: '',
              severity: 'warning',
              category: 'subscription',
              count: 5,
              remedy: null,
            },
          ],
        }),
        loading: false,
      },
    });

    expect(wrapper.text()).not.toContain('At least 5');
    expect(wrapper.text()).toContain('5');
  });

  it('shows a plain block when the closure is too large, above the counts', () => {
    const wrapper = mount(MigrationReceipt, {
      props: {
        preview: preview({ too_large: true }),
        loading: false,
      },
    });

    expect(wrapper.text()).toContain('too large');
    expect(wrapper.text()).not.toContain('Nothing left behind.');
  });

  it('states the closure note in counts, never using mechanism words', () => {
    const wrapper = mount(MigrationReceipt, {
      props: {
        preview: preview({ closure: { products: 31, customers: 12 } }),
        loading: false,
      },
    });

    const text = wrapper.text();
    expect(text).toContain('31 more products');
    expect(text).toContain('12 more customers');
    expect(text.toLowerCase()).not.toContain('id map');
    expect(text.toLowerCase()).not.toContain('closure');
  });

  // aria-live coverage: everything the panel updates on a debounce as the
  // owner edits their selection has to be announced, not just the
  // consequence list — the counts table and the "Nothing left behind."
  // all-clear are exactly the two things a screen-reader user would
  // otherwise miss while narrowing a selection down to nothing.
  it('announces the counts table politely', () => {
    const wrapper = mount(MigrationReceipt, { props: { preview: preview(), loading: false } });

    const region = wrapper.findAll('[aria-live="polite"]').find((el) => el.text().includes('1,204'));
    expect(region).toBeTruthy();
  });

  it('announces "Nothing left behind." politely, not just the consequence list', () => {
    const wrapper = mount(MigrationReceipt, { props: { preview: preview(), loading: false } });

    const region = wrapper.findAll('[aria-live="polite"]').find((el) => el.text().includes('Nothing left behind.'));
    expect(region).toBeTruthy();
  });

  it('announces a populated consequence list politely', () => {
    const wrapper = mount(MigrationReceipt, {
      props: {
        preview: preview({
          consequences: [
            {
              code: 'product_link_missing',
              label: 'Order items link to no product',
              hint: '',
              severity: 'warning',
              category: 'product',
              count: 12,
              remedy: null,
              is_minimum: true,
            },
          ],
        }),
        loading: false,
      },
    });

    const region = wrapper.findAll('[aria-live="polite"]').find((el) => el.text().includes('At least 12'));
    expect(region).toBeTruthy();
  });

  it('keeps the too_large notice as an interrupting alert, not a polite region', () => {
    const wrapper = mount(MigrationReceipt, {
      props: { preview: preview({ too_large: true }), loading: false },
    });

    const alert = wrapper.find('[role="alert"]');
    expect(alert.exists()).toBe(true);
    expect(alert.text()).toContain('too large');
  });
});
