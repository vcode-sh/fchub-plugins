import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { withSetup } from './helpers/withSetup.js';

const { apiMock } = vi.hoisted(() => ({ apiMock: vi.fn() }));

vi.mock('@/composables/useApi.js', () => ({
  useApi: () => ({ api: apiMock }),
}));

const { useMigration, ENTITIES } = await import('@/composables/useMigration.js');

let teardown;

/**
 * Run `startMigration` with a given selection and report what the dependency
 * resolver made of it.
 *
 * `autoIncludeDependencies` is not exported — it is a closure inside
 * `useMigration()` — so the only honest way at it is through the caller that
 * uses it. The POST body is the same array the backend would receive, which is
 * the thing that actually matters.
 */
async function resolve(selection) {
  apiMock.mockImplementation(async (method, endpoint) => {
    if (method === 'POST' && endpoint === 'migrate') {
      return { continue: false, entities: {} };
    }

    return { status: 'completed', entities: {} };
  });

  const { result, unmount } = withSetup(() => useMigration());
  teardown = unmount;

  result.state.selectedEntities = selection;
  await result.actions.startMigration();

  const post = apiMock.mock.calls.find((c) => c[0] === 'POST' && c[1] === 'migrate');

  return {
    sent: post ? post[2].entity_types : null,
    state: [...result.state.selectedEntities],
    error: result.state.error,
  };
}

beforeEach(() => {
  apiMock.mockReset();
});

afterEach(() => {
  if (teardown) teardown();
  teardown = null;
});

describe('autoIncludeDependencies — the six hand-verified cases', () => {
  it.each([
    [['coupon'], ['product', 'coupon']],
    [['order'], ['product', 'customer', 'order']],
    [['subscription'], ['product', 'customer', 'order', 'subscription']],
    [['subscription', 'coupon'], ['product', 'customer', 'coupon', 'order', 'subscription']],
    [['product'], ['product']],
  ])('%j resolves to %j', async (selection, expected) => {
    const { sent, state } = await resolve(selection);

    // Order is load-bearing: a dependency migrated after its dependent is a
    // dependency that was not there when the id-map was consulted.
    expect(sent).toEqual(expected);
    expect(state).toEqual(expected);
  });

  it('refuses to start on an empty selection instead of resolving it', async () => {
    // The sixth case, [] -> [], cannot be reached through startMigration: the
    // guard fires first. Pinned here as the behaviour that stands in for it.
    const { sent, error } = await resolve([]);

    expect(sent).toBeNull();
    expect(error).toBe('Please select at least one entity type to migrate.');
  });
});

describe('autoIncludeDependencies — the coupon defect specifically', () => {
  it('never lets coupons through without products', async () => {
    for (const selection of [['coupon'], ['coupon', 'customer'], ['coupon', 'subscription']]) {
      const { sent } = await resolve(selection);

      expect(sent).toContain('product');
      expect(sent.indexOf('product')).toBeLessThan(sent.indexOf('coupon'));

      if (teardown) teardown();
      teardown = null;
      apiMock.mockReset();
    }
  });

  it('documents the dependency in the entity list the select screen renders', () => {
    const coupon = ENTITIES.find((e) => e.key === 'coupon');

    expect(coupon.dep).toContain('Products');
  });
});

describe('autoIncludeDependencies — ordering and idempotence', () => {
  it('produces the canonical order regardless of input order', async () => {
    const canonical = ['product', 'customer', 'coupon', 'order', 'subscription'];

    const inputs = [
      ['subscription', 'coupon', 'order', 'customer', 'product'],
      ['coupon', 'product', 'subscription'],
      ['order', 'coupon'],
    ];

    for (const input of inputs) {
      const { sent } = await resolve(input);

      expect(sent).toEqual(canonical.filter((key) => sent.includes(key)));

      if (teardown) teardown();
      teardown = null;
      apiMock.mockReset();
    }
  });

  it('collapses duplicates', async () => {
    const { sent } = await resolve(['order', 'order', 'product', 'product']);

    expect(sent).toEqual(['product', 'customer', 'order']);
  });

  it('is idempotent — resolving an already-resolved selection changes nothing', async () => {
    const once = await resolve(['subscription', 'coupon']);

    if (teardown) teardown();
    teardown = null;
    apiMock.mockReset();

    const twice = await resolve(once.sent);

    expect(twice.sent).toEqual(once.sent);
  });

  it('drops keys that are not entities rather than passing them to the backend', async () => {
    const { sent } = await resolve(['coupon', 'nonsense', 'ORDER']);

    expect(sent).toEqual(['product', 'coupon']);
  });

  it('resolves every entity key the select screen can offer', async () => {
    const { sent } = await resolve(ENTITIES.map((e) => e.key));

    expect(sent).toEqual(['product', 'customer', 'coupon', 'order', 'subscription']);
  });
});

describe('useMigration map focus', () => {
  it('carries the row the audit sent the operator to re-decide', () => {
    const { result, unmount } = withSetup(() => useMigration());

    result.actions.goToMapping({ wc_id: 770001, name: 'Monthly subscription' });

    expect(result.state.screen).toBe('map');
    expect(result.state.mapFocus).toEqual({ wc_id: 770001, name: 'Monthly subscription' });

    result.actions.clearMapFocus();

    expect(result.state.mapFocus).toBe(null);
    expect(result.state.screen).toBe('map');

    unmount();
  });

  it('refuses to focus on nothing, rather than parking a null id on the screen', () => {
    const { result, unmount } = withSetup(() => useMigration());

    result.actions.goToMapping(null);

    expect(result.state.screen).toBe('map');
    expect(result.state.mapFocus).toBe(null);

    result.actions.goToMapping({ wc_id: 0 });

    expect(result.state.mapFocus).toBe(null);

    unmount();
  });

  it('forgets the focus when the wizard is reset', () => {
    const { result, unmount } = withSetup(() => useMigration());

    result.actions.goToMapping({ wc_id: 770002, name: 'Yearly subscription' });
    result.actions.resetState();

    expect(result.state.mapFocus).toBe(null);

    unmount();
  });
});
