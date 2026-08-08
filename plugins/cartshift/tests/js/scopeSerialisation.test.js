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
  it('records that /preview is not installed, without claiming a failure', async () => {
    apiMock.mockImplementation(async (method, endpoint) => {
      if (endpoint === 'preview') {
        throw apiError('No route was found', 404);
      }
      return { counts: { order: 699 } };
    });

    const { state, actions } = mount();
    state.selectedEntities = ['order'];
    await actions.refreshPreview();

    expect(state.previewSupport).toBe('no');
    expect(state.error).toBeNull();
    // No preview means no answer, not somebody else's answer. The receipt
    // reads this and says so; it used to quietly print the whole-shop counts.
    expect(state.preview).toBeNull();
  });

  it('asks about the run that will happen, not the boxes that are ticked', async () => {
    // startMigration() runs autoIncludeDependencies() on the way out, so
    // ticking Orders alone migrates products and customers too. A preview of
    // the raw ticks describes a run nobody is going to get.
    apiMock.mockResolvedValue({ counts: {}, consequences: [], closure: {}, too_large: false });

    const { state, actions } = mount();
    state.selectedEntities = ['order'];
    await actions.refreshPreview();

    const post = apiMock.mock.calls.find((c) => c[1] === 'preview');

    expect(post[2].entity_types).toEqual(['product', 'customer', 'order']);
  });

  it('asks nothing at all when nothing is ticked', async () => {
    // An empty entity_types list is read by the server as "no narrowing" and
    // answered for all five entities — which is how arriving with nothing
    // ticked came to show 25 products and 699 orders under "What will come
    // across", beside a Start button that refuses for want of a selection.
    apiMock.mockResolvedValue({ counts: { product: 25 } });

    const { state, actions } = mount();
    state.preview = { counts: { product: 25 }, consequences: [] };
    await actions.refreshPreview();

    expect(apiMock.mock.calls.find((c) => c[1] === 'preview')).toBeUndefined();
    expect(state.preview).toBeNull();
    expect(state.previewLoading).toBe(false);
  });

  it('drops a preview that answered the previous question when a refresh fails', async () => {
    apiMock.mockImplementation(async (method, endpoint) => {
      if (endpoint === 'preview') {
        throw apiError('Internal Server Error', 500);
      }
      return {};
    });

    const { state, actions } = mount();
    state.selectedEntities = ['order'];
    state.preview = { counts: { order: 699 }, consequences: [], too_large: false };

    await actions.refreshPreview();

    expect(state.preview).toBeNull();
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

  it('keeps state.error silent for a {silent: true} call that fails for a real reason', async () => {
    // The select screen's speculative on-mount prime — the owner did not ask
    // for this call, so a 500/timeout/dropped-connection failure must not
    // greet them with an error banner about nothing they did.
    apiMock.mockImplementation(async (method, endpoint) => {
      if (endpoint === 'preview') {
        throw apiError('Internal Server Error', 500);
      }
      return {};
    });

    const { state, actions } = mount();
    state.selectedEntities = ['order'];
    await actions.refreshPreview({ silent: true });

    expect(state.error).toBeNull();
    expect(state.preview).toBeNull();
  });

  it('still reports a real failure loudly when the caller does not ask for silence', async () => {
    // The guard against over-correcting: silence belongs to the speculative
    // call, not to the feature. An owner-initiated refresh (no options, or
    // {silent: false}) must keep behaving exactly as before {silent} existed.
    apiMock.mockImplementation(async (method, endpoint) => {
      if (endpoint === 'preview') {
        throw apiError('Internal Server Error', 500);
      }
      return {};
    });

    const { state, actions } = mount();
    state.selectedEntities = ['order'];
    await actions.refreshPreview();

    expect(state.error).toBe('Internal Server Error');
  });

  it('still detects a missing /preview endpoint under {silent: true}', async () => {
    // 404/501 is feature detection, not an error — silent must not swallow
    // it, because every caller (including the debounced watch) needs to see
    // previewSupport flip to 'no' to fall back correctly.
    apiMock.mockImplementation(async (method, endpoint) => {
      if (endpoint === 'preview') {
        throw apiError('No route was found', 404);
      }
      return { counts: { order: 699 } };
    });

    const { state, actions } = mount();
    state.selectedEntities = ['order'];
    await actions.refreshPreview({ silent: true });

    expect(state.previewSupport).toBe('no');
    expect(state.error).toBeNull();
  });

  it('returns to the select screen when the closure is refused', async () => {
    // startMigration() switches to the progress screen before the request goes
    // out, so a refusal that only sets state.error leaves the owner watching a
    // progress bar for a run that was never started.
    //
    // The payload is the shape useApi.js actually delivers — it unwraps one
    // `data` level, so the controller's `['data' => ['code' => …]]` arrives
    // flat. The earlier version of this fixture nested a second `data`, a
    // shape no real response can produce, and so passed against a branch that
    // could never run. See useMigration.closureRefusal.test.js for the same
    // case driven through the real useApi and a real fetch response.
    apiMock.mockRejectedValue(
      apiError('Selection is too large', 422, {
        code: 'scope_closure_too_large',
        message: 'Narrow the selection, then try again. Nothing was migrated.',
        scope: { mode: 'explicit' },
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
