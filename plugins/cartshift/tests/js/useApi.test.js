import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { useApi } from '@/composables/useApi.js';
import { withSetup, fakeConfig, fakeResponse } from './helpers/withSetup.js';

let unmount;

function mountApi(config = fakeConfig()) {
  const mounted = withSetup(() => useApi(), { config });
  unmount = mounted.unmount;
  return mounted.result.api;
}

beforeEach(() => {
  globalThis.fetch = vi.fn();
});

afterEach(() => {
  if (unmount) unmount();
  unmount = null;
});

describe('useApi request shape', () => {
  it('concatenates restUrl and endpoint and sends the nonce', async () => {
    globalThis.fetch.mockResolvedValue(fakeResponse({ body: { ok: true } }));

    const api = mountApi();
    await api('GET', 'preflight');

    const [url, opts] = globalThis.fetch.mock.calls[0];

    expect(url).toBe('https://example.test/wp-json/cartshift/v1/preflight');
    expect(opts.method).toBe('GET');
    expect(opts.headers['X-WP-Nonce']).toBe('test-nonce');
    expect(opts.headers['Content-Type']).toBe('application/json');
  });

  it('serialises a body only when one is given', async () => {
    globalThis.fetch.mockResolvedValue(fakeResponse({ body: {} }));

    const api = mountApi();

    await api('POST', 'migrate', { entity_types: ['product'] });
    expect(globalThis.fetch.mock.calls[0][1].body).toBe('{"entity_types":["product"]}');

    await api('POST', 'cancel');
    expect(globalThis.fetch.mock.calls[1][1].body).toBeUndefined();
  });
});

describe('useApi response unwrapping', () => {
  it('unwraps a {data: ...} envelope', async () => {
    globalThis.fetch.mockResolvedValue(
      fakeResponse({ body: { data: { migration_id: 'abc', continue: true } } })
    );

    const api = mountApi();

    await expect(api('GET', 'progress')).resolves.toEqual({
      migration_id: 'abc',
      continue: true,
    });
  });

  it('returns the payload untouched when there is no envelope', async () => {
    globalThis.fetch.mockResolvedValue(fakeResponse({ body: { migration_id: 'abc' } }));

    const api = mountApi();

    await expect(api('GET', 'progress')).resolves.toEqual({ migration_id: 'abc' });
  });

  it('unwraps a null data value rather than returning the envelope', async () => {
    globalThis.fetch.mockResolvedValue(fakeResponse({ body: { data: null } }));

    const api = mountApi();

    await expect(api('GET', 'progress')).resolves.toBeNull();
  });

  it('unwraps an array envelope', async () => {
    globalThis.fetch.mockResolvedValue(fakeResponse({ body: { data: [1, 2], total: 2 } }));

    const api = mountApi();

    await expect(api('GET', 'log')).resolves.toEqual([1, 2]);
  });
});

describe('useApi non-JSON handling', () => {
  it('parses JSON anyway when the server omitted Content-Type', async () => {
    globalThis.fetch.mockResolvedValue(
      fakeResponse({ contentType: '', text: '{"data":{"ok":true}}' })
    );

    const api = mountApi();

    await expect(api('GET', 'progress')).resolves.toEqual({ ok: true });
  });

  it('throws with the status when a non-JSON body accompanies an HTTP error', async () => {
    globalThis.fetch.mockResolvedValue(
      fakeResponse({ status: 500, contentType: 'text/html', text: '<b>Fatal error</b>' })
    );

    const api = mountApi();
    const err = await api('GET', 'progress').catch((e) => e);

    expect(err).toBeInstanceOf(Error);
    expect(err.status).toBe(500);
    expect(err.payload).toBeNull();
    expect(err.message).toContain('HTTP 500');
  });

  it('throws a readable error when an OK response is not parseable at all', async () => {
    globalThis.fetch.mockResolvedValue(
      fakeResponse({ status: 200, contentType: 'text/html', text: '<!DOCTYPE html>' })
    );

    const api = mountApi();
    const err = await api('GET', 'progress').catch((e) => e);

    expect(err.status).toBe(200);
    expect(err.payload).toBeNull();
    expect(err.message).toContain('non-JSON');
  });
});

describe('useApi error surfacing', () => {
  it('exposes status and the unwrapped payload on a 409', async () => {
    const progress = { status: 'running', background_pending: false };

    globalThis.fetch.mockResolvedValue(
      fakeResponse({
        status: 409,
        body: { message: 'A migration is already running', data: { progress, locked: true } },
      })
    );

    const api = mountApi();
    const err = await api('POST', 'migrate/batch').catch((e) => e);

    expect(err.status).toBe(409);
    expect(err.message).toBe('A migration is already running');
    expect(err.payload.locked).toBe(true);
    expect(err.payload.progress).toEqual(progress);
  });

  it('falls back to the whole body as payload when there is no data key', async () => {
    globalThis.fetch.mockResolvedValue(
      fakeResponse({ status: 404, body: { code: 'rest_no_route', message: 'No route' } })
    );

    const api = mountApi();
    const err = await api('POST', 'retry').catch((e) => e);

    expect(err.status).toBe(404);
    expect(err.payload).toEqual({ code: 'rest_no_route', message: 'No route' });
  });

  it('reads a nested data.message when the top-level message is missing', async () => {
    globalThis.fetch.mockResolvedValue(
      fakeResponse({ status: 501, body: { data: { message: 'Not implemented here' } } })
    );

    const api = mountApi();
    const err = await api('POST', 'retry').catch((e) => e);

    expect(err.status).toBe(501);
    expect(err.message).toBe('Not implemented here');
  });

  it('uses a generic message when the server offers none', async () => {
    globalThis.fetch.mockResolvedValue(fakeResponse({ status: 500, body: {} }));

    const api = mountApi();
    const err = await api('GET', 'counts').catch((e) => e);

    expect(err.message).toBe('Request failed');
    expect(err.status).toBe(500);
  });
});
