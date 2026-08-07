import { inject } from 'vue';

/**
 * Build an Error that carries the HTTP status and the unwrapped payload.
 * Callers need both: a 409 from /migrate/batch means "retry", a 409 from
 * /reset means "this run is still alive", and neither should look like a
 * generic failure.
 */
function apiError(message, status, payload) {
  const err = new Error(message);
  err.status = status;
  err.payload = payload;
  return err;
}

export function useApi() {
  const config = inject('config');

  async function api(method, endpoint, body) {
    const opts = {
      method,
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': config.nonce,
      },
    };

    if (body) {
      opts.body = JSON.stringify(body);
    }

    const res = await fetch(config.restUrl + endpoint, opts);
    const contentType = res.headers.get('Content-Type') || '';

    let data;

    if (contentType.indexOf('application/json') === -1) {
      if (!res.ok) {
        throw apiError(
          `Server returned non-JSON response (HTTP ${res.status}). The server may have timed out or encountered a fatal error.`,
          res.status,
          null
        );
      }
      // Try parsing anyway — some servers omit Content-Type.
      const text = await res.text();
      try {
        data = JSON.parse(text);
      } catch {
        throw apiError(
          'Server returned non-JSON response. Check PHP error logs for details.',
          res.status,
          null
        );
      }
    } else {
      data = await res.json();
      if (!res.ok) {
        throw apiError(
          data.message || data.data?.message || 'Request failed',
          res.status,
          data.data !== undefined ? data.data : data
        );
      }
    }

    // Unwrap {data: ...} wrapper if present.
    return data.data !== undefined ? data.data : data;
  }

  return { api };
}
