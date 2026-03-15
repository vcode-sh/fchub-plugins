import { inject } from 'vue';

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
        throw new Error(
          `Server returned non-JSON response (HTTP ${res.status}). The server may have timed out or encountered a fatal error.`
        );
      }
      // Try parsing anyway — some servers omit Content-Type.
      const text = await res.text();
      try {
        data = JSON.parse(text);
      } catch {
        throw new Error('Server returned non-JSON response. Check PHP error logs for details.');
      }
    } else {
      data = await res.json();
      if (!res.ok) {
        throw new Error(data.message || data.data?.message || 'Request failed');
      }
    }

    // Unwrap {data: ...} wrapper if present.
    return data.data !== undefined ? data.data : data;
  }

  return { api };
}
