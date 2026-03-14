const config = window.fchubMembershipsAdmin || {}
const baseUrl = (config.rest_url || '/wp-json/fchub-memberships/v1/').replace(/\/$/, '')
const nonce = config.nonce || ''

async function request(method, endpoint, { params, body } = {}) {
  let url = `${baseUrl}/${endpoint}`

  if (params) {
    const qs = new URLSearchParams(
      Object.fromEntries(Object.entries(params).filter(([, value]) => value != null && value !== '')),
    ).toString()

    if (qs) {
      url += `?${qs}`
    }
  }

  const options = {
    method,
    headers: { 'X-WP-Nonce': nonce },
  }

  if (body !== undefined) {
    options.headers['Content-Type'] = 'application/json'
    options.body = JSON.stringify(body)
  }

  const response = await fetch(url, options)

  if (!response.ok) {
    const error = await response.json().catch(() => ({}))
    throw Object.assign(new Error(error.message || response.statusText), {
      status: response.status,
      data: error,
    })
  }

  return response.json()
}

export const apiClient = {
  get: (endpoint, params) => request('GET', endpoint, { params }),
  post: (endpoint, data) => request('POST', endpoint, { body: data }),
  put: (endpoint, data) => request('PUT', endpoint, { body: data }),
  del: (endpoint) => request('DELETE', endpoint),
}
