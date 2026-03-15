const config = window.fchubMembershipsPortal || {}
const baseUrl = (config.rest_url || '/wp-json/fchub-memberships/v1/').replace(/\/$/, '')
const nonce = config.nonce || ''

async function request(endpoint) {
  const headers = {
    'Content-Type': 'application/json',
  }

  if (nonce) {
    headers['X-WP-Nonce'] = nonce
  }

  const response = await fetch(`${baseUrl}/${endpoint}`, { headers, credentials: 'same-origin' })

  if (!response.ok) {
    const error = new Error(`Request failed: ${response.status}`)
    error.status = response.status
    try {
      error.data = await response.json()
    } catch {
      // no parseable body
    }
    throw error
  }

  return response.json()
}

export function getMyAccess() {
  return request('my-access')
}
