const config = window.fchubPortalExtenderAdmin || {}
const baseUrl = (config.rest_url || '/wp-json/fchub-portal-extender/v1/').replace(/\/$/, '')
const nonce = config.nonce || ''

async function request(method, endpoint, { params, body } = {}) {
  let url = `${baseUrl}/${endpoint}`

  if (params) {
    const qs = new URLSearchParams(
      Object.fromEntries(Object.entries(params).filter(([, v]) => v != null && v !== ''))
    ).toString()
    if (qs) url += `?${qs}`
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

const api = {
  get: (endpoint, params) => request('GET', endpoint, { params }),
  post: (endpoint, data) => request('POST', endpoint, { body: data }),
  put: (endpoint, data) => request('PUT', endpoint, { body: data }),
  del: (endpoint) => request('DELETE', endpoint),
}

export default api

export const endpoints = {
  list: () => api.get('endpoints'),
  create: (data) => api.post('endpoints', data),
  update: (id, data) => api.put(`endpoints/${id}`, data),
  remove: (id) => api.del(`endpoints/${id}`),
  reorder: (ids) => api.post('endpoints/reorder', { ids }),
}

export const pages = {
  search: (search) => api.get('pages', { search }),
}

export const postTypes = {
  list: () => api.get('post-types'),
}

export const posts = {
  search: (postType, search) => api.get('posts', { post_type: postType, search }),
}
