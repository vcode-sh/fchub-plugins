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
    const wrapper = mount(MigrationReceipt, { props: { preview: preview(), counts: null, loading: false } });

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
        counts: null,
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
        counts: null,
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
        counts: null,
        loading: false,
      },
    });

    await wrapper.find('button.cartshift-remedy').trigger('click');

    expect(wrapper.emitted('apply-remedy')[0][0].product_ids).toEqual([1, 2]);
  });

  it('falls back to plain counts when there is no preview', () => {
    const wrapper = mount(MigrationReceipt, {
      props: { preview: null, counts: { order: 699 }, loading: false },
    });

    expect(wrapper.text()).toContain('699');
  });

  it('renders product_link_missing as a lower bound, not an exact figure', () => {
    // Reused from PreflightCheck::countOrdersAffectedByTypes(), which excludes
    // anything outside publish/draft/private — the true figure can be higher.
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
            },
          ],
        }),
        counts: null,
        loading: false,
      },
    });

    expect(wrapper.text()).toContain('At least 12');
  });

  it('does not present other consequence codes as lower bounds', () => {
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
        counts: null,
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
        counts: null,
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
        counts: null,
        loading: false,
      },
    });

    const text = wrapper.text();
    expect(text).toContain('31 more products');
    expect(text).toContain('12 more customers');
    expect(text.toLowerCase()).not.toContain('id map');
    expect(text.toLowerCase()).not.toContain('closure');
  });
});
