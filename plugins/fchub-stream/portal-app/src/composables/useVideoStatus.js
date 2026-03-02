import { ref, onUnmounted } from 'vue'
import { checkVideoStatus } from '../services/statusService'
import { ENCODING_STATUS, DEFAULT_POLLING_INTERVAL } from '../utils/constants'
import { logger } from '../utils/logger'

/**
 * Get polling interval from settings
 */
function getPollingInterval() {
  const settings = window.fchubStreamSettings ||
                   window.fcom_portal_general?.fchubStreamSettings ||
                   window.fluentComAdmin?.fchubStreamSettings ||
                   {}
  
  // Settings stores polling_interval in milliseconds (converted from seconds in PHP)
  // So return as-is if present, otherwise use default
  return settings.upload?.polling_interval || DEFAULT_POLLING_INTERVAL
}

/**
 * Composable for video encoding status tracking
 */
export function useVideoStatus(videoId, provider) {
  const status = ref(ENCODING_STATUS.PENDING)
  const readyToStream = ref(false)
  const playerUrl = ref(null)
  const thumbnailUrl = ref(null)
  const error = ref(null)
  const isPolling = ref(false)

  let pollingInterval = null
  let pollingStartTime = null
  let consecutiveErrors = 0
  const MAX_CONSECUTIVE_ERRORS = 5 // Stop polling after 5 consecutive errors

  /**
   * Check the video encoding status
   */
  async function checkStatus() {
    try {
      error.value = null
      const result = await checkVideoStatus(videoId, provider)
      
      // Reset error counter on successful check
      consecutiveErrors = 0

      status.value = result.status || ENCODING_STATUS.PENDING
      readyToStream.value = result.readyToStream || false
      playerUrl.value = result.playerUrl || result.html || null
      thumbnailUrl.value = result.thumbnailUrl || result.thumbnail_url || null

      // Stop polling if video is ready or failed
      if (readyToStream.value || status.value === ENCODING_STATUS.READY) {
        stopPolling()
        return true
      }

      if (status.value === ENCODING_STATUS.FAILED) {
        stopPolling()
        error.value = 'Video encoding failed'
        return false
      }

      return false
    } catch (err) {
      consecutiveErrors++
      
      // For 401/403 errors (authentication), try to refresh nonce and continue polling
      // This handles cases where nonce expires during long polling
      const statusCode = err.response?.status
      if (statusCode === 401 || statusCode === 403) {
        logger.warn('[FCHub Stream] Status check auth error (401/403), attempting to refresh nonce...')
        
        // Try to refresh nonce from window settings
        try {
          const settings = window.fchubStreamSettings ||
                           window.fcom_portal_general?.fchubStreamSettings ||
                           window.fluentComAdmin?.fchubStreamSettings ||
                           {}
          
          if (settings.rest_nonce) {
            // Nonce refreshed, continue polling
            logger.log('[FCHub Stream] Nonce refreshed, continuing polling...')
            error.value = null // Clear error, will retry on next poll
            return false // Continue polling
          }
        } catch (e) {
          logger.error('[FCHub Stream] Failed to refresh nonce:', e)
        }
      }
      
      // Log error but don't stop polling unless too many consecutive errors
      if (consecutiveErrors >= MAX_CONSECUTIVE_ERRORS) {
        error.value = err.message || 'Failed to check video status after multiple attempts'
        stopPolling()
        logger.error('[FCHub Stream] Stopping polling after', consecutiveErrors, 'consecutive errors')
        return false
      }
      
      // Continue polling but log error
      error.value = err.message || 'Failed to check video status'
      logger.warn('[FCHub Stream] Status check error (continuing polling):', err.message, `(${consecutiveErrors}/${MAX_CONSECUTIVE_ERRORS})`)
      return false
    }
  }

  /**
   * Start polling for video status
   */
  function startPolling(interval = null) {
    if (pollingInterval) {
      return // Already polling
    }

    // Use provided interval, or get from settings, or use default
    const pollingIntervalMs = interval || getPollingInterval()

    isPolling.value = true
    pollingStartTime = Date.now()

    // Immediate check
    checkStatus()

    // Start interval
    pollingInterval = setInterval(() => {
      checkStatus()
    }, pollingIntervalMs)
  }

  /**
   * Stop polling for video status
   */
  function stopPolling() {
    if (pollingInterval) {
      clearInterval(pollingInterval)
      pollingInterval = null
    }
    isPolling.value = false
  }

  /**
   * Reset status
   */
  function reset() {
    stopPolling()
    consecutiveErrors = 0
    status.value = ENCODING_STATUS.PENDING
    readyToStream.value = false
    playerUrl.value = null
    thumbnailUrl.value = null
    error.value = null
  }

  // Cleanup on unmount
  onUnmounted(() => {
    stopPolling()
  })

  return {
    // State
    status,
    readyToStream,
    playerUrl,
    thumbnailUrl,
    error,
    isPolling,

    // Methods
    checkStatus,
    startPolling,
    stopPolling,
    reset
  }
}
