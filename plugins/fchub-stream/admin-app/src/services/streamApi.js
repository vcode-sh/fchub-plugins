import axios from 'axios'
import { logger } from '../utils/logger'

const apiClient = axios.create({
  baseURL: window.fchubStream?.restUrl || '/wp-json/fluent-community/v2/stream',
  headers: {
    'Content-Type': 'application/json',
    'X-WP-Nonce': window.fchubStream?.nonce || '',
  },
})

// Add request interceptor to log requests
apiClient.interceptors.request.use(
  async config => {
    return config
  },
  async error => {
    logger.error('[FCHub Stream] Request error:', error)
    logger.error('[FCHub Stream] Request error details:', {
      message: error.message,
      code: error.code,
      config: error.config,
      response: error.response,
    })

    return Promise.reject(error)
  }
)

// Add response interceptor to log responses and capture errors
apiClient.interceptors.response.use(
  response => {
    return response
  },
  async error => {
    logger.error('[FCHub Stream] Response error:', error)
    logger.error('[FCHub Stream] Response error details:', {
      message: error.message,
      code: error.code,
      status: error.response?.status,
      statusText: error.response?.statusText,
      data: error.response?.data,
      headers: error.response?.headers,
      config: error.config,
    })

    return Promise.reject(error)
  }
)

export const streamApi = {
  /**
   * Get stream configuration (public - without sensitive data)
   */
  async getConfig() {
    const response = await apiClient.get('/config')
    return response.data
  },

  /**
   * Save Cloudflare configuration
   */
  async saveCloudflareConfig(config, testConnection = false) {
    const dataToSend = {
      cloudflare: config.cloudflare || {},
      test_connection: testConnection,
    }

    const response = await apiClient.post('/config/cloudflare', dataToSend)
    return response.data
  },

  /**
   * Save Bunny.net configuration
   */
  async saveBunnyConfig(config, testConnection = false) {
    const dataToSend = {
      bunny: config.bunny || {},
      test_connection: testConnection,
    }

    const response = await apiClient.post('/config/bunny', dataToSend)
    return response.data
  },

  /**
   * Test Cloudflare API connection
   */
  async testCloudflareConnection(accountId, apiToken) {
    const requestData = {
      cloudflare: {
        account_id: accountId,
      },
    }

    // Only include api_token if provided (null means use saved token)
    if (apiToken !== null) {
      requestData.cloudflare.api_token = apiToken
    }

    const response = await apiClient.post('/config/cloudflare/test', requestData)
    return response.data
  },

  /**
   * Test Bunny.net API connection
   */
  async testBunnyConnection(libraryId, apiKey) {
    const requestData = {
      bunny: {
        library_id: libraryId,
      },
    }

    // Only include api_key if provided (null means use saved key)
    if (apiKey !== null) {
      requestData.bunny.api_key = apiKey
    }

    const response = await apiClient.post('/config/bunny/test', requestData)
    return response.data
  },

  /**
   * Get Bunny.net Collections
   */
  async getBunnyCollections(libraryId, apiKey = null) {
    const params = {
      library_id: libraryId,
    }

    if (apiKey) {
      params.api_key = apiKey
    }

    const response = await apiClient.get('/config/bunny/collections', { params })
    return response.data
  },

  /**
   * Remove Cloudflare configuration
   */
  async removeCloudflareConfig() {
    const response = await apiClient.delete('/config/cloudflare')
    return response.data
  },

  /**
   * Remove Bunny.net configuration
   */
  async removeBunnyConfig() {
    const response = await apiClient.delete('/config/bunny')
    return response.data
  },

  /**
   * Remove all configuration (deprecated - use provider-specific methods)
   * @deprecated Use removeCloudflareConfig() or removeBunnyConfig() instead
   */
  async removeConfig() {
    const response = await apiClient.delete('/config')
    return response.data
  },

  /**
   * Update Cloudflare enabled status
   */
  async updateCloudflareEnabled(enabled) {
    const response = await apiClient.patch('/config/cloudflare/enabled', {
      enabled: enabled,
    })
    return response.data
  },

  /**
   * Update Bunny.net enabled status
   */
  async updateBunnyEnabled(enabled) {
    const response = await apiClient.patch('/config/bunny/enabled', {
      enabled: enabled,
    })
    return response.data
  },

  /**
   * Update active provider
   */
  async updateProvider(provider) {
    const response = await apiClient.patch('/config/provider', {
      provider: provider,
    })
    return response.data
  },

  /**
   * Get upload settings
   */
  async getUploadSettings() {
    const response = await apiClient.get('/settings/upload')
    return response.data
  },

  /**
   * Save upload settings
   */
  async saveUploadSettings(settings) {
    try {
      const response = await apiClient.post('/settings/upload', settings)
      return response.data
    } catch (error) {
      logger.error('[FCHub Stream] streamApi.saveUploadSettings() - ERROR:', error)
      logger.error('[FCHub Stream] streamApi.saveUploadSettings() - Error response:', error.response?.data)
      throw error
    }
  },

  /**
   * Reset upload settings to defaults
   */
  async resetUploadSettings() {
    const response = await apiClient.post('/settings/upload/reset')
    return response.data
  },

  /**
   * Activate Cloudflare Stream webhook
   */
  async activateCloudflareWebhook() {
    try {
      const response = await apiClient.post('/config/cloudflare/webhook')
      return response.data
    } catch (error) {
      logger.error('[FCHub Stream] ========== ACTIVATE WEBHOOK ERROR ==========')
      logger.error('[FCHub Stream] streamApi.activateCloudflareWebhook() - ERROR:', error)
      logger.error('[FCHub Stream] streamApi.activateCloudflareWebhook() - Error name:', error.name)
      logger.error('[FCHub Stream] streamApi.activateCloudflareWebhook() - Error message:', error.message)
      logger.error('[FCHub Stream] streamApi.activateCloudflareWebhook() - Error code:', error.code)
      logger.error('[FCHub Stream] streamApi.activateCloudflareWebhook() - Error response:', error.response)
      logger.error('[FCHub Stream] streamApi.activateCloudflareWebhook() - Error response status:', error.response?.status)
      logger.error('[FCHub Stream] streamApi.activateCloudflareWebhook() - Error response statusText:', error.response?.statusText)
      logger.error('[FCHub Stream] streamApi.activateCloudflareWebhook() - Error response data:', error.response?.data)
      logger.error('[FCHub Stream] streamApi.activateCloudflareWebhook() - Error response headers:', error.response?.headers)
      logger.error('[FCHub Stream] streamApi.activateCloudflareWebhook() - Error config:', error.config)
      logger.error('[FCHub Stream] streamApi.activateCloudflareWebhook() - Error stack:', error.stack)
      logger.error('[FCHub Stream] ========== ACTIVATE WEBHOOK ERROR END ==========')
      throw error
    }
  },

  /**
   * Get comment video settings
   */
  async getCommentVideoSettings() {
    const response = await apiClient.get('/settings/comment-video')
    return response.data
  },

  /**
   * Save comment video settings
   */
  async saveCommentVideoSettings(settings) {
    try {
      const response = await apiClient.post('/settings/comment-video', settings)
      return response.data
    } catch (error) {
      logger.error('[FCHub Stream] streamApi.saveCommentVideoSettings() - ERROR:', error)
      logger.error('[FCHub Stream] streamApi.saveCommentVideoSettings() - Error response:', error.response?.data)
      throw error
    }
  },

  /**
   * Reset comment video settings to defaults
   */
  async resetCommentVideoSettings() {
    const response = await apiClient.post('/settings/comment-video/reset')
    return response.data
  },
}

export default streamApi
