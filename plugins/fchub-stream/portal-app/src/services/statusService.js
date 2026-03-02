import axios from 'axios'

/**
 * Get API configuration from window object
 * Refreshes nonce from window settings on each call
 */
function getApiConfig() {
  const settings = window.fchubStreamSettings ||
                   window.fcom_portal_general?.fchubStreamSettings ||
                   window.fluentComAdmin?.fchubStreamSettings ||
                   {}
  return {
    baseUrl: settings.rest_url || '/wp-json/fluent-community/v2/stream',
    nonce: settings.rest_nonce || ''
  }
}

/**
 * Check video encoding status
 *
 * @param {string} videoId - Video ID from provider
 * @param {string} provider - Provider name (cloudflare_stream or bunny_stream)
 * @returns {Promise<Object>} Video status data
 */
export async function checkVideoStatus(videoId, provider) {
  const { baseUrl, nonce } = getApiConfig()

  try {
    const response = await axios.get(`${baseUrl}/video-status/${videoId}`, {
      params: { provider },
      headers: {
        'X-WP-Nonce': nonce
      }
    })

    if (response.data.success) {
      return response.data.data
    } else {
      throw new Error(response.data.message || 'Failed to check status')
    }
  } catch (error) {
    const errorMessage = error.response?.data?.message ||
                        error.response?.data?.error ||
                        error.message ||
                        'Failed to check status'
    throw new Error(errorMessage)
  }
}
