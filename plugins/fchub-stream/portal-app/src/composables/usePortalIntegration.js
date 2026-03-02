import { ref, onMounted } from 'vue'
import { logger } from '../utils/logger'

const isPortalReady = ref(false)
const portalApp = ref(null)

/**
 * Composable for FluentCommunity Portal integration
 */
export function usePortalIntegration() {
  /**
   * Find the Portal Vue app instance
   */
  function findPortalApp() {
    // Try to find Vue 3 app instance via __vue_app__
    const portalElement = document.querySelector('#fluent_com_portal') || 
                          document.querySelector('[data-v-app]') ||
                          document.querySelector('.fluent_community_portal')
    
    if (portalElement && portalElement.__vue_app__) {
      const rootInstance = portalElement.__vue_app__._instance
      if (rootInstance?.proxy) {
        portalApp.value = rootInstance.proxy
        return true
      }
    }
    
    // Fallback to window globals
    if (window.fcApp) {
      portalApp.value = window.fcApp
      return true
    }
    if (window.fluent_community?.app) {
      portalApp.value = window.fluent_community.app
      return true
    }
    
    return false
  }

  /**
   * Wait for Portal to be ready
   */
  function waitForPortal() {
    return new Promise((resolve) => {
      if (findPortalApp()) {
        isPortalReady.value = true
        resolve(portalApp.value)
        return
      }

      const checkInterval = setInterval(() => {
        if (findPortalApp()) {
          clearInterval(checkInterval)
          isPortalReady.value = true
          resolve(portalApp.value)
        }
      }, 50) // Reduced from 100ms to 50ms for faster detection

      // Timeout after 5 seconds (reduced from 10)
      setTimeout(() => {
        clearInterval(checkInterval)
        isPortalReady.value = false
        resolve(null)
      }, 5000)
    })
  }

  /**
   * Set media object in Portal Vue app (for iframe_html video)
   */
  function setMediaInPortalApp(videoData) {
    // Make sure we have Portal app
    if (!portalApp.value) {
      findPortalApp()
    }
    
    if (!portalApp.value) {
      return false
    }
    
    // Create media object compatible with FluentCommunity
    const mediaObject = {
      type: 'iframe_html',
      provider: videoData.provider || 'cloudflare_stream',
      html: videoData.html || '',
      image: videoData.thumbnail_url || '',
      width: videoData.width || 1920,
      height: videoData.height || 1080,
      video_id: videoData.video_id || '',
      player_url: videoData.player_url || ''
    }
    
    logger.log('[FCHub Stream] Setting media object:', mediaObject)
    
    // Try to set media in Portal app
    // Portal Vue app typically has media in reactive state
    try {
      const app = portalApp.value
      
      logger.log('[FCHub Stream] Portal app keys:', Object.keys(app))
      logger.log('[FCHub Stream] Checking for media property...')
      
      if (app.media !== undefined) {
        app.media = mediaObject
        logger.log('[FCHub Stream] Media set via portalApp.media')
        return true
      }
      
      // Try formData.media
      if (app.formData && app.formData.media !== undefined) {
        app.formData.media = mediaObject
        logger.log('[FCHub Stream] Media set via portalApp.formData.media')
        return true
      }
      
      // Try composer_data.media
      if (app.composer_data && app.composer_data.media !== undefined) {
        app.composer_data.media = mediaObject
        logger.log('[FCHub Stream] Media set via portalApp.composer_data.media')
        return true
      }
      
      // Try $data.media (Vue 2 compatibility)
      if (app.$data && app.$data.media !== undefined) {
        app.$data.media = mediaObject
        logger.log('[FCHub Stream] Media set via portalApp.$data.media')
        return true
      }
      
      logger.warn('[FCHub Stream] Could not find media property in Portal app')
      logger.log('[FCHub Stream] Available properties:', Object.keys(app).filter(k => !k.startsWith('$') && !k.startsWith('_')).slice(0, 20))
      return false
    } catch (error) {
      logger.error('[FCHub Stream] Error setting media:', error)
      return false
    }
  }

  /**
   * Insert shortcode into the Portal editor
   */
  function insertShortcodeIntoEditor(shortcode, videoData = null) {
    // Find the contenteditable element (Portal composer)
    const contentEditable = document.querySelector('[contenteditable="true"]') ||
                           document.querySelector('.fcom_editor_content') ||
                           document.querySelector('.fcom-feed-input')

    if (!contentEditable) {
      logger.error('FCHub Stream: Editor not found')
      return false
    }

    // Focus the editor
    contentEditable.focus()

    // Get current selection
    const selection = window.getSelection()
    const range = selection.rangeCount > 0 ? selection.getRangeAt(0) : null

    if (range) {
      // Delete current selection
      range.deleteContents()

      // Insert shortcode with newlines
      const textNode = document.createTextNode('\n\n' + shortcode + '\n\n')
      range.insertNode(textNode)

      // Move cursor after the shortcode
      range.setStartAfter(textNode)
      range.collapse(true)
      selection.removeAllRanges()
      selection.addRange(range)
    } else {
      // No selection, append to end
      const textNode = document.createTextNode('\n\n' + shortcode + '\n\n')
      contentEditable.appendChild(textNode)
    }

    // Trigger input event to notify Vue
    const inputEvent = new InputEvent('input', {
      bubbles: true,
      cancelable: true,
      inputType: 'insertText',
      data: shortcode
    })
    contentEditable.dispatchEvent(inputEvent)

    // Also trigger change event
    contentEditable.dispatchEvent(new Event('change', { bubbles: true }))

    return true
  }

  /**
   * Remove shortcode from the Portal editor
   * 
   * Removes all [fchub_stream:VIDEO_ID] shortcodes from the editor content.
   * Used when user clicks "Remove Media" to ensure shortcode is removed
   * from the message content before submission.
   * 
   * @param {string|null} specificVideoId Optional. If provided, only removes shortcode for this video ID.
   * @returns {boolean} True if shortcode was found and removed, false otherwise.
   */
  function removeShortcodeFromEditor(specificVideoId = null) {
    // Find the contenteditable element (Portal composer)
    const contentEditable = document.querySelector('[contenteditable="true"]') ||
                           document.querySelector('.fcom_editor_content') ||
                           document.querySelector('.fcom-feed-input')

    if (!contentEditable) {
      logger.warn('[FCHub Stream] Editor not found for shortcode removal')
      return false
    }

    // Get editor content
    const content = contentEditable.textContent || contentEditable.innerText || ''
    
    // Pattern to match [fchub_stream:VIDEO_ID] shortcodes
    // If specificVideoId provided, only match that specific video
    const pattern = specificVideoId 
      ? new RegExp(`\\[fchub_stream:${specificVideoId.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\]`, 'g')
      : /\[fchub_stream:[^\]]+\]/g

    // Check if shortcode exists
    if (!pattern.test(content)) {
      logger.log('[FCHub Stream] No shortcode found in editor to remove')
      return false
    }

    // Reset regex (test() advances the lastIndex)
    pattern.lastIndex = 0

    // Remove shortcodes from DOM
    // We need to walk through the DOM tree and remove text nodes containing shortcodes
    const walker = document.createTreeWalker(
      contentEditable,
      NodeFilter.SHOW_TEXT,
      null
    )

    let modified = false
    const nodesToRemove = []

    let node
    while ((node = walker.nextNode())) {
      const text = node.textContent
      if (pattern.test(text)) {
        // Reset regex
        pattern.lastIndex = 0
        
        // Replace shortcode in text node
        const newText = text.replace(pattern, '')
        
        if (newText !== text) {
          // Update text node
          node.textContent = newText
          modified = true
          
          // If text node is now empty or only whitespace, mark for removal
          if (!newText.trim()) {
            nodesToRemove.push(node)
          }
        }
      }
    }

    // Remove empty text nodes
    nodesToRemove.forEach(node => {
      if (node.parentNode) {
        node.parentNode.removeChild(node)
      }
    })

    // Also check innerHTML for any remaining shortcodes (in case they're in HTML comments or attributes)
    if (contentEditable.innerHTML) {
      const htmlContent = contentEditable.innerHTML
      const newHtmlContent = htmlContent.replace(pattern, '')
      
      if (newHtmlContent !== htmlContent) {
        contentEditable.innerHTML = newHtmlContent
        modified = true
      }
    }

    if (modified) {
      // Trigger input event to notify Vue that content changed
      const inputEvent = new InputEvent('input', {
        bubbles: true,
        cancelable: true,
        inputType: 'deleteContent'
      })
      contentEditable.dispatchEvent(inputEvent)

      // Also trigger change event
      contentEditable.dispatchEvent(new Event('change', { bubbles: true }))

      logger.log('[FCHub Stream] Shortcode removed from editor')
    }

    return modified
  }

  /**
   * Find the media actions container in Portal composer
   * Cache the result to avoid repeated DOM queries
   */
  let cachedMediaContainer = null
  
  function findMediaActionsContainer() {
    // If we have a cached container and it's still in DOM, return it
    if (cachedMediaContainer && document.contains(cachedMediaContainer)) {
      return cachedMediaContainer
    }
    
    // Otherwise, find it fresh
    cachedMediaContainer = document.querySelector('.fcom_media_actions') ||
           document.querySelector('.fcom_composer_apps') ||
           document.querySelector('[data-media-actions]') ||
           document.querySelector('.fcom-composer-actions')
    
    return cachedMediaContainer
  }

  /**
   * Find the Portal composer container
   */
  function findComposerContainer() {
    return document.querySelector('.fcom_feed_composer') ||
           document.querySelector('.fcom-composer') ||
           document.querySelector('[data-composer]')
  }

  /**
   * Check if Portal is in a specific route
   */
  function isPortalRoute(route) {
    const currentPath = window.location.hash || window.location.pathname
    return currentPath.includes(route)
  }

  /**
   * Get Portal settings
   */
  function getPortalSettings() {
    // Try to find Portal Vue app first to check if settings are in Vue state
    if (!portalApp.value) {
      findPortalApp()
    }
    
    // Try multiple locations where FluentCommunity might expose vars
    let settings = window.fchubStreamSettings ||
           window.fcom_portal_general?.fchubStreamSettings ||
           window.fluentComAdmin?.fchubStreamSettings ||
           {}
    
    // If not found in window, try Vue app state
    if (!settings || Object.keys(settings).length === 0) {
      if (portalApp.value) {
        settings = portalApp.value.fchubStreamSettings ||
                   portalApp.value.$data?.fchubStreamSettings ||
                   portalApp.value.$root?.fchubStreamSettings ||
                   {}
      }
    }
    
    return settings
  }

  /**
   * Check if video upload is enabled
   */
  function isVideoUploadEnabled() {
    const settings = getPortalSettings()
    return settings.enabled === true
  }

  /**
   * Check if comment video upload is enabled
   */
  function isCommentVideoEnabled() {
    const settings = getPortalSettings()
    return settings.comment_video?.enabled !== false
  }

  // Initialize on mount
  // Note: waitForPortal() disabled - not needed for shortcode-based integration
  // onMounted(() => {
  //   waitForPortal()
  // })

  return {
    // State
    isPortalReady,
    portalApp,

    // Methods
    findPortalApp,
    waitForPortal,
    setMediaInPortalApp,
    insertShortcodeIntoEditor,
    removeShortcodeFromEditor,
    findMediaActionsContainer,
    findComposerContainer,
    isPortalRoute,
    getPortalSettings,
    isVideoUploadEnabled,
    isCommentVideoEnabled
  }
}
