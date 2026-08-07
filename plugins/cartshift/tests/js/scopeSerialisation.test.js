import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { withSetup } from './helpers/withSetup.js';

const { apiMock } = vi.hoisted(() => ({ apiMock: vi.fn() }));

vi.mock('@/composables/useApi.js', () => ({ useApi: () => ({ api: apiMock }) }));

const { useMigration, serializeScope } = await import('@/composables/useMigration.js');

// beforeEach/afterEach and the teardown handle live at module scope, matching
// the established pattern in useMigration.retry.test.js — nesting them inside
// a describe() callback alongside a describe-scoped `let teardown` trips a
// false "unhandled rejection" failure in this Vitest/tinyspy combination even
// when useMigration's own catch handles the rejection correctly.
let teardown;

function mount() {
  const { result, unmount } = withSetup(() => useMigration());
  teardown = unmount;
  return result;
}

/** Error shaped the way useApi.js shapes them. */
function apiError(message, status, payload = null) {
  const err = new Error(message);
  err.status = status;
  err.payload = payload;
  return err;
}

beforeEach(() => {
  apiMock.mockReset();
});

afterEach(() => {
  if (teardown) teardown();
  teardown = null;
});

describe('serializeScope', () => {
  it('sends nothing but the mode when everything is selected', () => {
    expect(
      serializeScope({ mode: 'everything', since: '2024-03-01', products: [{ id: 12 }], customers: [] }),
    ).toEqual({
      mode: 'everything',
      since: null,
      product_ids: [],
      customer_ids: [],
      guest_emails: [],
      include_orders_for_products: false,
    });
  });

  it('splits customers into registered ids and guest emails', () => {
    const wire = serializeScope({
      mode: 'explicit',
      since: null,
      products: [{ id: '12' }],
      customers: [
        { id: '7', kind: 'registered' },
        { id: 'bob@example.com', kind: 'guest' },
      ],
      includeOrdersForProducts: true,
    });

    expect(wire.product_ids).toEqual([12]);
    expect(wire.customer_ids).toEqual([7]);
    expect(wire.guest_emails).toEqual(['bob@example.com']);
    expect(wire.include_orders_for_products).toBe(true);
  });
});

describe('preview degradation', () => {
  it('falls back to the old counts when /preview is not installed', async () => {
    apiMock.mockImplementation(async (method, endpoint) => {
      if (endpoint === 'preview') {
        throw apiError('No route was found', 404);
      }
      return { counts: { order: 699 } };
    });

    const { state, actions } = mount();
    await actions.refreshPreview();

    expect(state.previewSupport).toBe('no');
    expect(state.error).toBeNull();
  });

  it('sends the serialised scope on /migrate', async () => {
    apiMock.mockResolvedValue({ continue: false, entities: {} });

    const { state, actions } = mount();
    state.selectedEntities = ['order'];
    state.scope.mode = 'since';
    state.scope.since = '2024-03-01';
    await actions.startMigration();

    const post = apiMock.mock.calls.find((c) => c[0] === 'POST' && c[1] === 'migrate');

    expect(post[2].scope).toEqual({
      mode: 'since',
      since: '2024-03-01',
      product_ids: [],
      customer_ids: [],
      guest_emails: [],
      include_orders_for_products: false,
    });
  });

  it('returns to the select screen when the closure is refused', async () => {
    // startMigration() switches to the progress screen before the request goes
    // out, so a refusal that only sets state.error leaves the owner watching a
    // progress bar for a run that was never started.
    apiMock.mockRejectedValue(
      apiError('Selection is too large', 422, {
        data: {
          code: 'scope_closure_too_large',
          message: 'Narrow the selection, then try again. Nothing was migrated.',
        },
      }),
    );

    const { state, actions } = mount();
    state.selectedEntities = ['order'];
    await actions.startMigration();

    expect(state.screen).toBe('select');
    expect(state.error).toContain('Narrow the selection');
    expect(state.migrating).toBe(false);
  });
});
