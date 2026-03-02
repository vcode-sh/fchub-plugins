import { logger } from './logger'

/**
 * Event names for cross-component communication
 */
export const EVENTS = {
  OPEN_UPLOAD_DIALOG: 'fchub-stream:open-upload-dialog',
  CLOSE_UPLOAD_DIALOG: 'fchub-stream:close-upload-dialog',
  UPLOAD_COMPLETE: 'fchub-stream:upload-complete',
  UPLOAD_ERROR: 'fchub-stream:upload-error',
  VIDEO_ADDED: 'fchubStream:videoAdded',
  VIDEO_REMOVED: 'fchubStream:videoRemoved',
}

/**
 * Video encoding statuses
 */
export const ENCODING_STATUS = {
  PENDING: 'pending',
  PROCESSING: 'processing',
  READY: 'ready',
  FAILED: 'failed'
}

/**
 * Upload status types
 */
export const STATUS_TYPE = {
  SUCCESS: 'success',
  ERROR: 'error',
  INFO: 'info',
  WARNING: 'warning'
}

/**
 * Provider names
 */
export const PROVIDERS = {
  CLOUDFLARE: 'cloudflare_stream',
  BUNNY: 'bunny_stream'
}

/**
 * Default polling interval for status checks (milliseconds)
 */
export const DEFAULT_POLLING_INTERVAL = 5000 // 5 seconds

/**
 * Maximum polling duration (milliseconds)
 */
export const MAX_POLLING_DURATION = 300000 // 5 minutes

/**
 * CSS Selectors for detecting comment contexts
 *
 * These selectors are used to determine if a video upload
 * is happening within a comment composer vs a post composer.
 */
export const COMMENT_SELECTORS = [
  '.feed_comment',
  '.feed_commentbox_main',
  '.feed_commentbox',
  '.fcom_comment_composer',
  '.fcom_comment_form',
  '.fcom_feed_comments',
  '.fcom_comment_reply_box',
  '[data-comment-form]',
  '[class*="comment"]', // Match any class containing "comment"
  '[class*="Comment"]', // Match any class containing "Comment"
  '[id*="comment"]',    // Match any ID containing "comment"
]

/**
 * CSS Selectors for detecting dialog/modal contexts
 */
export const DIALOG_SELECTORS = [
  '[role="dialog"]',
  '.fcom_modal',
  '.el-dialog',
  '.fcom_feed_modal',
]

/**
 * API Endpoints
 *
 * REST API endpoint paths for video-related operations.
 */
export const API_ENDPOINTS = {
  FEEDS: '/feeds',
  COMMENTS: '/comments',
  VIDEO_UPLOAD: '/wp-json/fluent-community/v2/stream/video-upload',
  COMMENT_VIDEO_SETTINGS: '/wp-json/fluent-community/v2/stream/settings/comment-video',
}

/**
 * Upload Context Types
 *
 * Defines where the video upload is happening.
 */
export const UPLOAD_CONTEXT = {
  POST: 'post',
  COMMENT: 'comment',
}

/**
 * Context Cache
 *
 * WeakMap to cache upload context for elements.
 * Prevents context loss when modal is removed from DOM.
 * Using WeakMap for automatic garbage collection when elements are removed.
 */
const contextCache = new WeakMap()

/**
 * Helper Functions
 */

/**
 * Check if an element is within a comment context
 */
export function isInCommentContext(element) {
  if (!element) {
    return false
  }
  
  // Check if element itself matches comment selector
  if (COMMENT_SELECTORS.some((selector) => {
    try {
      return element.matches && element.matches(selector)
    } catch (e) {
      return false
    }
  })) {
    return true
  }
  
  // Check if element is within a comment container
  return COMMENT_SELECTORS.some((selector) => {
    try {
      const closest = element.closest(selector)
      return closest !== null
    } catch (e) {
      return false
    }
  })
}

/**
 * Check if an element is within a dialog context
 */
export function isInDialogContext(element) {
  if (!element) {
    return false
  }
  return DIALOG_SELECTORS.some(
    (selector) =>
      element.closest(selector) !== null ||
      document.querySelector(selector)?.contains(element)
  )
}

/**
 * Check if an element is within an edit feed dialog context
 * Edit feed dialogs should NOT be treated as comments
 */
export function isInEditFeedDialog(element) {
  if (!element) {
    return false
  }
  
  // Check if element is within a feed edit dialog/modal
  // These selectors identify edit feed dialogs (not comment dialogs)
  const editFeedSelectors = [
    '.fcom_feed_modal',
    '.fcom_modal[data-feed-id]',
    '[data-edit-feed]',
    '.fcom-edit-feed-dialog',
    '.el-dialog[data-feed-id]', // Element UI dialog with feed ID
    '[role="dialog"][data-feed-id]', // ARIA dialog with feed ID
    '.fcom_modal .fcom_feed_composer', // Modal containing feed composer (not comment composer)
    '.fcom_modal:not(.fcom_comment_composer)', // Modal that's NOT a comment composer
    '.el-dialog', // Any Element UI dialog (fallback)
    '[role="dialog"]', // Any ARIA dialog (fallback)
  ]
  
  // First check if element itself or parent matches selectors
  const matches = editFeedSelectors.some((selector) => {
    try {
      // Check if element itself matches
      if (element.matches && element.matches(selector)) {
        return true
      }
      // Check if element is within matching parent
      const closest = element.closest(selector)
      if (closest) {
        // Additional check: make sure it's not a comment composer
        if (closest.classList.contains('fcom_comment_composer') || 
            closest.querySelector('.fcom_comment_composer')) {
          return false
        }
        return true
      }
      return false
    } catch (e) {
      return false
    }
  })
  
  // Also check document for active edit modals (fallback)
  if (!matches) {
    try {
      const activeModals = document.querySelectorAll(
        '.el-dialog[data-feed-id], ' +
        '.fcom_feed_modal, ' +
        '[role="dialog"][data-feed-id], ' +
        '.fcom_modal[data-feed-id]'
      )
      
      for (const modal of activeModals) {
        // Check if element is within this modal
        if (modal.contains(element)) {
          // Make sure it's not a comment composer
          if (!modal.querySelector('.fcom_comment_composer') && 
              !modal.classList.contains('fcom_comment_composer')) {
            return true
          }
        }
      }
    } catch (e) {
      // Silently fail
    }
  }
  
  return matches
}

/**
 * Detect upload context from an element
 * CRITICAL: Edit feed dialogs should be treated as POST context, not COMMENT
 *
 * Caches result to prevent context loss when modal is removed from DOM.
 * This fixes the issue where context changes from 'post' to 'comment' after
 * edit dialog is closed, causing button to disappear.
 *
 * @param {HTMLElement} element Element to check
 * @returns {string} UPLOAD_CONTEXT.POST or UPLOAD_CONTEXT.COMMENT
 */
export function detectUploadContext(element) {
  if (!element) {
    return UPLOAD_CONTEXT.POST
  }

  // Check cache first - prevents context loss when modal is removed
  if (contextCache.has(element)) {
    const cached = contextCache.get(element)
    logger.log('[FCHub Stream] Using cached upload context:', cached, {
      element: element.className || element.tagName
    })
    return cached
  }

  // Detect context
  let context

  // If it's an edit feed dialog, always treat as POST context
  if (isInEditFeedDialog(element)) {
    context = UPLOAD_CONTEXT.POST
  } else {
    // Otherwise, check if it's a comment context
    context = isInCommentContext(element) ? UPLOAD_CONTEXT.COMMENT : UPLOAD_CONTEXT.POST
  }

  // Cache result for this element
  contextCache.set(element, context)

  logger.log('[FCHub Stream] Detected and cached upload context:', context, {
    element: element.className || element.tagName,
    isEditDialog: isInEditFeedDialog(element),
    isComment: isInCommentContext(element)
  })

  return context
}

/**
 * Clear context cache for specific element or all elements
 * Call this when modal is closed to prevent stale cache
 *
 * @param {HTMLElement|null} element Element to clear cache for, or null to clear all
 */
export function clearContextCache(element = null) {
  if (element) {
    contextCache.delete(element)
    logger.log('[FCHub Stream] Cleared context cache for element:', element.className || element.tagName)
  } else {
    // WeakMap doesn't have .clear(), but we can log that we're relying on GC
    // The WeakMap will automatically clear when elements are garbage collected
    logger.log('[FCHub Stream] Context cache will be garbage collected automatically')
  }
}

/**
 * Check if a URL matches an API endpoint
 */
export function matchesEndpoint(url, endpoints) {
  if (!url || !Array.isArray(endpoints)) {
    return false
  }
  return endpoints.some((endpoint) => url.includes(endpoint))
}

/**
 * Dispatch a custom video event
 */
export function dispatchVideoEvent(eventName, detail = {}) {
  try {
    window.dispatchEvent(
      new CustomEvent(eventName, {
        detail,
        bubbles: true,
        cancelable: true,
      })
    )
    return true
  } catch (error) {
    logger.error('[FCHub Stream] Failed to dispatch event:', eventName, error)
    return false
  }
}

/**
 * Extract video ID and provider from iframe src URL
 * Supports Cloudflare Stream and Bunny.net Stream
 */
export function extractVideoDataFromIframe(iframe) {
  if (!iframe || !iframe.src) {
    return null
  }
  
  const src = iframe.src
  
  // Cloudflare Stream pattern: https://customer-xxx.cloudflarestream.com/VIDEO_ID/...
  const cloudflareMatch = src.match(/cloudflarestream\.com\/([a-f0-9]{32})(?:\/|$|\?)/i)
  if (cloudflareMatch) {
    return {
      video_id: cloudflareMatch[1],
      provider: PROVIDERS.CLOUDFLARE
    }
  }
  
  // Bunny.net Stream pattern: https://iframe.mediadelivery.net/embed/VIDEO_ID/...
  const bunnyMatch = src.match(/mediadelivery\.net\/embed\/([a-f0-9-]+)(?:\/|$|\?)/i)
  if (bunnyMatch) {
    return {
      video_id: bunnyMatch[1],
      provider: PROVIDERS.BUNNY
    }
  }
  
  return null
}

/**
 * Detect existing video in edit feed modal
 * Checks for iframe elements with Cloudflare Stream or Bunny.net Stream URLs
 * Also checks for FluentCommunity media_preview structure
 */
export function detectExistingVideoInModal(modal) {
  if (!modal) {
    return null
  }

  // Method 1: Find iframe elements in modal (most reliable)
  const iframes = modal.querySelectorAll('iframe')

  for (const iframe of iframes) {
    const videoData = extractVideoDataFromIframe(iframe)
    if (videoData) {
      return videoData
    }
  }
  
  // Method 2: Check for FluentCommunity media_preview structure
  // FluentCommunity may render video as div with iframe inside, or as video element
  const mediaPreviewContainers = modal.querySelectorAll(
    '.fcom_media_preview, ' +
    '[data-media-preview], ' +
    '.fcom-media-preview, ' +
    '.media-preview, ' +
    '[class*="media"], ' +
    '[class*="video"]'
  )

  for (const container of mediaPreviewContainers) {
    // Check for nested iframe
    const nestedIframe = container.querySelector('iframe')
    if (nestedIframe) {
      const videoData = extractVideoDataFromIframe(nestedIframe)
      if (videoData) {
        return videoData
      }
    }

    // Check for video element (FluentCommunity might render as <video>)
    const videoElement = container.querySelector('video')
    if (videoElement) {
      // Video element might have data attributes or src with video ID
      const videoSrc = videoElement.src || videoElement.getAttribute('src') || ''
      const cloudflareMatch = videoSrc.match(/cloudflarestream\.com\/([a-f0-9]{32})/i)
      const bunnyMatch = videoSrc.match(/mediadelivery\.net\/embed\/([a-f0-9-]+)/i)
      
      if (cloudflareMatch) {
        return {
          video_id: cloudflareMatch[1],
          provider: PROVIDERS.CLOUDFLARE
        }
      }
      if (bunnyMatch) {
        return {
          video_id: bunnyMatch[1],
          provider: PROVIDERS.BUNNY
        }
      }
    }
    
    // Check for data attributes
    const videoId = container.getAttribute('data-video-id') ||
                   container.dataset.videoId ||
                   container.getAttribute('data-fchub-video-id') ||
                   container.getAttribute('data-media-video-id')

    if (videoId) {
      // Try to determine provider from context
      const hasCloudflare = container.querySelector('iframe[src*="cloudflarestream"]') ||
                           container.innerHTML.includes('cloudflarestream') ||
                           container.innerHTML.includes('cloudflarestream.com')
      const hasBunny = container.querySelector('iframe[src*="mediadelivery"]') ||
                      container.innerHTML.includes('mediadelivery') ||
                      container.innerHTML.includes('mediadelivery.net')

      const provider = container.getAttribute('data-provider') ||
                      container.getAttribute('data-media-provider') ||
                      (hasCloudflare ? PROVIDERS.CLOUDFLARE : null) ||
                      (hasBunny ? PROVIDERS.BUNNY : null) ||
                      PROVIDERS.CLOUDFLARE // Default

      return {
        video_id: videoId,
        provider: provider
      }
    }

    // Check for img with video thumbnail (might have data attributes)
    const imgWithVideo = container.querySelector('img[data-video-id], img[data-media-video-id]')
    if (imgWithVideo) {
      const imgVideoId = imgWithVideo.getAttribute('data-video-id') ||
                        imgWithVideo.getAttribute('data-media-video-id') ||
                        imgWithVideo.dataset.videoId
      if (imgVideoId) {
        const provider = imgWithVideo.getAttribute('data-provider') ||
                        PROVIDERS.CLOUDFLARE
        return {
          video_id: imgVideoId,
          provider: provider
        }
      }
    }
  }
  
  // Method 3: Check entire modal HTML for video URLs (fallback)
  const modalHtml = modal.innerHTML || ''
  const cloudflareMatch = modalHtml.match(/cloudflarestream\.com\/([a-f0-9]{32})/i)
  if (cloudflareMatch) {
    return {
      video_id: cloudflareMatch[1],
      provider: PROVIDERS.CLOUDFLARE
    }
  }

  const bunnyMatch = modalHtml.match(/mediadelivery\.net\/embed\/([a-f0-9-]+)/i)
  if (bunnyMatch) {
    return {
      video_id: bunnyMatch[1],
      provider: PROVIDERS.BUNNY
    }
  }

  // Method 4: Check for FluentCommunity Vue component data (might be in Vue state)
  // Look for data attributes on modal itself
  const modalVideoId = modal.getAttribute('data-feed-video-id') ||
                      modal.getAttribute('data-video-id') ||
                      modal.dataset.videoId ||
                      modal.dataset.feedVideoId

  if (modalVideoId) {
    return {
      video_id: modalVideoId,
      provider: PROVIDERS.CLOUDFLARE // Default
    }
  }

  return null
}
