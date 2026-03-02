import axios from 'axios'
import { logger } from '../utils/logger'

/**
 * Get API configuration from window object
 */
function getApiConfig() {
  // Try multiple locations for settings
  const settings = window.fchubStreamSettings || 
                   window.fcom_portal_general?.fchubStreamSettings ||
                   window.fluentComAdmin?.fchubStreamSettings ||
                   {}
  
  // Try to get nonce from FluentCommunity if not in settings
  let nonce = settings.rest_nonce
  if (!nonce) {
    // Try FluentCommunity's REST nonce
    nonce = window.fcom_portal_general?.rest_nonce ||
            window.fluentComAdmin?.rest_nonce ||
            ''
  }
  
  return {
    baseUrl: settings.rest_url || '/wp-json/fluent-community/v2/stream',
    nonce: nonce
  }
}

/**
 * Upload video file to provider API
 *
 * @param {File} file - Video file to upload
 * @param {Object} options - Upload options
 * @param {Function} options.onProgress - Progress callback (loaded, total)
 * @param {AbortSignal} options.signal - Abort signal for cancellation
 * @returns {Promise<Object>} Upload response data
 */
export async function uploadVideo(file, options = {}) {
  const { baseUrl, nonce } = getApiConfig()
  const formData = new FormData()
  formData.append('file', file)

  // Don't set Content-Type header - let browser set it with boundary for multipart/form-data
  const config = {
    headers: {
      'X-WP-Nonce': nonce
    },
    onUploadProgress: (progressEvent) => {
      if (options.onProgress) {
        const loaded = progressEvent.loaded
        const total = progressEvent.total || file.size
        options.onProgress(loaded, total)
      }
    },
    signal: options.signal
  }

  try {
    logger.log('[FCHub Stream] Uploading file:', file.name, file.size, 'to:', `${baseUrl}/video-upload`)
    const response = await axios.post(`${baseUrl}/video-upload`, formData, config)

    if (response.data.success) {
      return response.data.data
    } else {
      throw new Error(response.data.message || 'Upload failed')
    }
  } catch (error) {
    if (axios.isCancel(error) || error.name === 'AbortError') {
      throw new Error('Upload cancelled')
    }

    const errorMessage = error.response?.data?.message ||
                        error.response?.data?.error ||
                        error.message ||
                        'Upload failed'
    
    logger.error('[FCHub Stream] Upload error:', errorMessage, error.response?.data)
    throw new Error(errorMessage)
  }
}

/**
 * Cancel an ongoing upload
 * Note: This is handled by the AbortController in the composable
 */
export function cancelUpload() {
  // Cancellation is handled by AbortController
}
