import { createApp } from 'vue'
import VideoUploadButton from './components/VideoUploadButton.vue'
import VideoUploadDialog from './components/VideoUploadDialog.vue'
import VideoMediaPreview from './components/VideoMediaPreview.vue'
import { usePortalIntegration } from './composables/usePortalIntegration'
import { useFloatingPlayer } from './composables/useFloatingPlayer'
import {
  isInCommentContext,
  isInDialogContext,
  isInEditFeedDialog,
  detectUploadContext,
  detectExistingVideoInModal,
  matchesEndpoint,
  clearContextCache,
  EVENTS,
  API_ENDPOINTS,
  UPLOAD_CONTEXT,
} from './utils/constants'
import { logger } from './utils/logger'

/**
 * Initialize FCHub Stream Portal Integration
 */
function initFCHubStreamPortal() {
  const { waitForPortal, findMediaActionsContainer, isVideoUploadEnabled, isCommentVideoEnabled, insertShortcodeIntoEditor, removeShortcodeFromEditor } = usePortalIntegration()

  // Define initializeUploadButton before waitForPortal to avoid scope issues
  function initializeUploadButton() {
    // Setup MutationObserver immediately to detect composer opening faster
    setupComposerListener()
    
    // Initial check is now handled by setupComposerListener's checkAndMountButtons()
    
    function mountButton(mediaActionsContainer, existingVideoData = null) {
      // Check if button already exists to avoid duplicates
      const existingButtonContainer = mediaActionsContainer.querySelector('.fchub-stream-button-container')
      if (existingButtonContainer) {
        // If button was hidden due to existing video, keep it hidden
        if (existingButtonContainer.dataset.fchubHiddenForExistingVideo === 'true') {
          return
        }
        return
      }

      // Use detectUploadContext to properly detect if this is POST or COMMENT context
      // CRITICAL: Edit feed dialogs should be treated as POST, not COMMENT
      const uploadContext = detectUploadContext(mediaActionsContainer)
      const inCommentContext = uploadContext === UPLOAD_CONTEXT.COMMENT

      // If in comment context, check if comment video is enabled
      if (inCommentContext && !isCommentVideoEnabled()) {
        logger.log('[FCHub Stream] Skipping button mount - comment video is disabled', {
          container: mediaActionsContainer,
          uploadContext,
          inCommentContext,
          commentVideoEnabled: isCommentVideoEnabled()
        })
        return
      }

      // CRITICAL: If editing post with existing FCHub Stream video, don't mount button
      // This prevents accidental video replacement which could cause data loss
      // User must remove old video through FluentCommunity UI first
      if (isInEditFeedDialog(mediaActionsContainer)) {
        // Always check for existing video in modal, even if existingVideoData is null
        // (it might not have been passed from checkAndMountButtons)
        const modal = mediaActionsContainer.closest('.el-dialog[data-feed-id]') ||
                      mediaActionsContainer.closest('.fcom_feed_modal') ||
                      mediaActionsContainer.closest('[role="dialog"][data-feed-id]') ||
                      mediaActionsContainer.closest('.fcom_modal') ||
                      mediaActionsContainer.closest('.el-dialog') ||
                      document.querySelector('.el-dialog[data-feed-id]') ||
                      document.querySelector('.fcom_feed_modal')
        
        if (modal) {
          const detectedVideo = existingVideoData || detectExistingVideoInModal(modal)
          if (detectedVideo) {
            logger.log('[FCHub Stream] mountButton: CRITICAL - Skipping button mount - post already has FCHub Stream video. User must remove old video first.', {
              existingVideoId: detectedVideo.video_id,
              existingProvider: detectedVideo.provider,
              passedExistingVideoData: !!existingVideoData,
              detectedInModal: !existingVideoData
            })
            return
          }
        }
      }

      // Debug log for comment context detection
      if (inCommentContext) {
        logger.log('[FCHub Stream] Mounting button in comment context', {
          container: mediaActionsContainer,
          commentVideoEnabled: isCommentVideoEnabled()
        })
      }

      // Create button container
      const buttonContainer = document.createElement('div')
      buttonContainer.className = 'fchub-stream-button-container'
      buttonContainer.style.display = 'inline-block'
      
      // Store existing video data in container for later use (for non-edit contexts)
      if (existingVideoData && !isInEditFeedDialog(mediaActionsContainer)) {
        buttonContainer.dataset.existingVideoId = existingVideoData.video_id
        buttonContainer.dataset.existingVideoProvider = existingVideoData.provider
      }

      // Insert button after the first button (after image upload) - do this immediately
      const buttons = mediaActionsContainer.querySelectorAll('button')
      if (buttons.length > 0) {
        buttons[0].insertAdjacentElement('afterend', buttonContainer)
      } else {
        mediaActionsContainer.appendChild(buttonContainer)
      }

      // Create Vue app for upload button - mount immediately, no delays
      // Pass existingVideoData as prop if available
      const buttonApp = createApp(VideoUploadButton, {
        existingVideoData: existingVideoData || null
      })
      buttonApp.mount(buttonContainer)

      // Create dialog container (only once) - mount immediately
      if (!document.getElementById('fchub-stream-dialog-root')) {
        const dialogContainer = document.createElement('div')
        dialogContainer.id = 'fchub-stream-dialog-root'
        document.body.appendChild(dialogContainer)

        // Create Vue app for upload dialog
        const dialogApp = createApp(VideoUploadDialog)
        dialogApp.mount(dialogContainer)
      }
      
      // Setup video preview system
      setupVideoPreview(mediaActionsContainer, existingVideoData)
    }
    
    function setupVideoPreview(mediaActionsContainer, existingVideoData = null) {
      let currentVideoData = existingVideoData || null
      let previewApp = null
      
      // If we have existing video data, we're in edit mode
      // Don't show preview for existing video - FluentCommunity already shows it
      // But track it so we can handle replacement
      if (existingVideoData) {
        logger.log('[FCHub Stream] Edit mode detected with existing video:', existingVideoData)
        // Store existing video data for replacement handling
        currentVideoData = {
          video_id: existingVideoData.video_id,
          provider: existingVideoData.provider,
          is_existing: true
        }
      }
      
      // Listen for video upload success
      window.addEventListener('fchub-stream-video-added', (event) => {
        const newVideoData = event.detail
        
        // If we're replacing an existing video, mark it for removal
        if (currentVideoData?.is_existing && newVideoData) {
          logger.log('[FCHub Stream] Replacing existing video:', {
            old: currentVideoData,
            new: newVideoData
          })
          // Store old video ID for backend removal if needed
          newVideoData.replaces_video_id = currentVideoData.video_id
          newVideoData.replaces_provider = currentVideoData.provider
        }
        
        currentVideoData = newVideoData
        
        // Find textarea first
        const textarea = document.querySelector('[contenteditable="true"]') ||
                        document.querySelector('.fcom_editor_content')
        
        if (!textarea) {
          logger.error('[FCHub Stream] Textarea not found, composer may be closed')
          // Don't insert shortcode - fetch interceptor will add it to media.html
          return
        }
        
        // NOTE: We don't insert shortcode into editor content anymore.
        // Fetch interceptor adds shortcode to media.html in the request body,
        // and backend processes it before saving. This prevents shortcode from
        // appearing in the editor UI while still ensuring it's sent to backend.
        
        // Use detectUploadContext to properly detect if this is POST or COMMENT context
        // CRITICAL: Edit feed dialogs should be treated as POST, not COMMENT
        const uploadContext = detectUploadContext(textarea)
        const isComment = uploadContext === UPLOAD_CONTEXT.COMMENT
        
        // Find composer container (parent of textarea) for preview display
        const composer = textarea.closest('[data-composer]') ||
                        textarea.closest('.fcom_new_feed_inline_module') ||
                        textarea.closest('.fcom_feed_composer') ||
                        textarea.parentElement?.parentElement ||
                        textarea.parentElement

        // Detect if we're in a dialog/modal using centralized helper
        const isInDialog = isInDialogContext(textarea)

        // Use simple notification for comments OR dialogs
        // CRITICAL: If we detected comment, ALWAYS use simple notification
        const useSimpleNotification = isComment || isInDialog
        
        // Find or create preview container
        let previewContainer = composer.querySelector('.fchub-stream-preview-container')
        if (!previewContainer) {
          previewContainer = document.createElement('div')
          previewContainer.className = 'fchub-stream-preview-container'
          
          // Find the best place to insert preview
          // Priority: between textarea and buttons
          const mediaActions = composer.querySelector('.fcom_media_actions') ||
                              composer.querySelector('.fcom_composer_apps') ||
                              composer.querySelector('.fcom_action_btn')?.parentElement
          
          if (useSimpleNotification) {
            // In comment/dialog: Add compact class
            previewContainer.classList.add('fchub-stream-preview-compact')
          }
          
          if (mediaActions) {
            // Insert BEFORE media actions (between textarea and buttons) - BEST
            mediaActions.parentNode.insertBefore(previewContainer, mediaActions)
          } else if (textarea && textarea.parentNode) {
            // Fallback: after textarea
            textarea.parentNode.insertBefore(previewContainer, textarea.nextSibling)
          } else {
            composer.appendChild(previewContainer)
          }
        }
        
        // Mount preview component (full preview for create post, simple text for comments/dialogs)
        // CRITICAL: Unmount any existing preview first
        if (previewApp) {
          previewApp.unmount()
          previewApp = null
        }
        
        // CRITICAL: For comments, ALWAYS use simple notification, NEVER mount VideoMediaPreview
        if (useSimpleNotification) {
          // In comment/dialog: Show simple text notification instead of full preview
          // Clear any existing preview content first (including VideoMediaPreview)
          previewContainer.innerHTML = ''
          
          // Detect context to show correct message
          const uploadContext = detectUploadContext(mediaActionsContainer)
          const isComment = uploadContext === UPLOAD_CONTEXT.COMMENT
          const publishText = isComment ? 'Just publish a comment.' : 'Just publish the post.'
          
          const notificationDiv = document.createElement('div')
          notificationDiv.style.cssText = 'padding: 12px; background: #f0fdf4; border: 1px solid #86efac; border-radius: 6px; margin: 12px 0;'
          notificationDiv.innerHTML = `
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
              <svg style="width: 20px; height: 20px; color: #16a34a;" viewBox="0 0 24 24" fill="currentColor">
                <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
              </svg>
              <span style="color: #166534; font-weight: 500; font-size: 14px;">Video uploaded and encoding</span>
            </div>
            <p style="color: #15803d; font-size: 13px; margin: 0 0 8px 28px;">It will be available shortly. ${publishText}</p>
            <button 
              class="fchub-stream-remove-video" 
              style="margin-left: 28px; padding: 4px 12px; background: white; border: 1px solid #86efac; color: #dc2626; border-radius: 4px; font-size: 13px; cursor: pointer;"
            >
              Remove Video
            </button>
          `
          previewContainer.appendChild(notificationDiv)
          
          // Add click handler for remove button
          previewContainer.querySelector('.fchub-stream-remove-video')?.addEventListener('click', () => {
            // CRITICAL: Remove shortcode from editor content before removing preview
            if (currentVideoData?.video_id) {
              removeShortcodeFromEditor(currentVideoData.video_id)
            } else {
              // Fallback: remove all shortcodes if video_id not available
              removeShortcodeFromEditor()
            }
            
            currentVideoData = null
            if (previewContainer && previewContainer.parentNode) {
              previewContainer.parentNode.removeChild(previewContainer)
            }
          })
        } else {
          // Normal create post: Full preview with thumbnail
          previewApp = createApp(VideoMediaPreview, {
            videoData: currentVideoData,
            onRemove: () => {
              // CRITICAL: Remove shortcode from editor content before removing preview
              if (currentVideoData?.video_id) {
                removeShortcodeFromEditor(currentVideoData.video_id)
              } else {
                // Fallback: remove all shortcodes if video_id not available
                removeShortcodeFromEditor()
              }
              
              currentVideoData = null
              if (previewApp) {
                previewApp.unmount()
                previewApp = null
              }
              if (previewContainer && previewContainer.parentNode) {
                previewContainer.parentNode.removeChild(previewContainer)
              }
            }
          })
          previewApp.mount(previewContainer)
        }
      })

      // ============================================================================
      // GAP #12: Listen for feed updates to refresh preview
      // ============================================================================
      // When post is updated (edit dialog save), refresh video preview with latest data
      window.addEventListener('fchub-stream:feed-updated', (event) => {
        const feedData = event.detail
        logger.log('[FCHub Stream] Feed updated event in setupVideoPreview:', feedData)

        if (feedData.meta?.media_preview) {
          // Feed has video - update preview
          const videoData = feedData.meta.media_preview

          logger.log('[FCHub Stream] Updating video preview with feed data:', videoData)

          // Update current video data
          currentVideoData = {
            video_id: videoData.video_id,
            provider: videoData.provider,
            status: videoData.status,
            thumbnail_url: videoData.image,
            html: videoData.html,
            is_existing: true
          }

          // Find editor and composer
          const textarea = document.querySelector('[contenteditable="true"]') ||
                          document.querySelector('.fcom_editor_content')

          if (!textarea) {
            logger.warn('[FCHub Stream] Cannot update preview - editor not found')
            return
          }

          const composer = textarea.closest('[data-composer]') ||
                          textarea.closest('.fcom_feed_composer') ||
                          textarea.closest('.fcom_new_feed_inline_module') ||
                          textarea.parentElement?.parentElement ||
                          textarea.parentElement

          if (!composer) {
            logger.warn('[FCHub Stream] Cannot update preview - composer not found')
            return
          }

          // Detect context
          const uploadContext = detectUploadContext(textarea)
          const isComment = uploadContext === UPLOAD_CONTEXT.COMMENT
          const isInDialog = isInDialogContext(textarea)
          const useSimpleNotification = isComment || isInDialog

          // Find or create preview container
          let previewContainer = composer.querySelector('.fchub-stream-preview-container')

          if (!previewContainer) {
            previewContainer = document.createElement('div')
            previewContainer.className = 'fchub-stream-preview-container'

            if (useSimpleNotification) {
              previewContainer.classList.add('fchub-stream-preview-compact')
            }

            const mediaActions = composer.querySelector('.fcom_media_actions') ||
                                composer.querySelector('.fcom_composer_apps')

            if (mediaActions && mediaActions.parentNode) {
              mediaActions.parentNode.insertBefore(previewContainer, mediaActions)
            } else if (textarea && textarea.parentNode) {
              textarea.parentNode.insertBefore(previewContainer, textarea.nextSibling)
            } else {
              composer.appendChild(previewContainer)
            }
          }

          // Unmount existing preview
          if (previewApp) {
            previewApp.unmount()
            previewApp = null
          }

          // Clear existing content
          previewContainer.innerHTML = ''

          // Mount updated preview
          if (useSimpleNotification) {
            // Simple notification for comments/dialogs
            const publishText = isComment ? 'Just publish a comment.' : 'Just publish the post.'

            const notificationDiv = document.createElement('div')
            notificationDiv.style.cssText = 'padding: 12px; background: #f0fdf4; border: 1px solid #86efac; border-radius: 6px; margin: 12px 0;'
            notificationDiv.innerHTML = `
              <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                <svg style="width: 20px; height: 20px; color: #16a34a;" viewBox="0 0 24 24" fill="currentColor">
                  <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
                </svg>
                <span style="color: #166534; font-weight: 500; font-size: 14px;">Video ${videoData.status === 'ready' ? 'ready' : 'encoding'}</span>
              </div>
              <p style="color: #15803d; font-size: 13px; margin: 0 0 8px 28px;">${videoData.status === 'ready' ? 'Video is ready to view.' : 'Video is encoding and will be available shortly.'} ${publishText}</p>
              <button
                class="fchub-stream-remove-video"
                style="margin-left: 28px; padding: 4px 12px; background: white; border: 1px solid #86efac; color: #dc2626; border-radius: 4px; font-size: 13px; cursor: pointer;"
              >
                Remove Video
              </button>
            `
            previewContainer.appendChild(notificationDiv)

            // Add click handler for remove button
            previewContainer.querySelector('.fchub-stream-remove-video')?.addEventListener('click', () => {
              if (currentVideoData?.video_id) {
                removeShortcodeFromEditor(currentVideoData.video_id)
              }
              currentVideoData = null
              if (previewContainer && previewContainer.parentNode) {
                previewContainer.parentNode.removeChild(previewContainer)
              }
            })
          } else {
            // Full preview for create post
            previewApp = createApp(VideoMediaPreview, {
              videoData: currentVideoData,
              onRemove: () => {
                if (currentVideoData?.video_id) {
                  removeShortcodeFromEditor(currentVideoData.video_id)
                }
                currentVideoData = null
                if (previewApp) {
                  previewApp.unmount()
                  previewApp = null
                }
                if (previewContainer && previewContainer.parentNode) {
                  previewContainer.parentNode.removeChild(previewContainer)
                }
              }
            })
            previewApp.mount(previewContainer)
          }

          logger.log('[FCHub Stream] Video preview updated successfully')
        } else if (currentVideoData) {
          // Feed was updated but no video in meta - remove preview
          logger.log('[FCHub Stream] Feed updated without video - removing preview')

          if (previewApp) {
            previewApp.unmount()
            previewApp = null
          }

          const previewContainer = document.querySelector('.fchub-stream-preview-container')
          if (previewContainer && previewContainer.parentNode) {
            previewContainer.parentNode.removeChild(previewContainer)
          }

          currentVideoData = null
        }
      })

      // Clean up preview after post is submitted
      document.addEventListener('click', (e) => {
        const isPostButton = e.target.matches('button.fcom_btn_submit') ||
                            e.target.closest('button.fcom_btn_submit')

        if (isPostButton && currentVideoData) {
          // Delay cleanup to allow form submission
          setTimeout(() => {
            if (previewApp) {
              previewApp.unmount()
              previewApp = null
            }
            const previewContainer = document.querySelector('.fchub-stream-preview-container')
            if (previewContainer && previewContainer.parentNode) {
              previewContainer.parentNode.removeChild(previewContainer)
            }
            currentVideoData = null
          }, 1000)
        }
      }, true)
    }
    
    function setupComposerListener() {
      // Track mounted containers to avoid duplicates
      const mountedContainers = new Set()
      
      // Find all media action containers (there might be multiple - post composer + comment composers)
      function findAllMediaContainers() {
        const selectors = [
          '.fcom_media_actions',
          '.fcom_composer_apps',
          '[data-media-actions]',
          '.fcom-composer-actions'
        ]
        
        const containers = []
        selectors.forEach(selector => {
          const elements = document.querySelectorAll(selector)
          elements.forEach(el => {
            if (!containers.includes(el)) {
              containers.push(el)
            }
          })
        })
        
        return containers
      }
      
      // Cache for detected videos in modals to prevent repeated checks
      const modalVideoCache = new Map() // Map<modalElement, {video_id, provider} | null>
      
      // Set of containers that should NEVER have buttons mounted (because they have video)
      const blockedContainers = new WeakSet()
      
      // Check and mount buttons for all containers
      function checkAndMountButtons() {
        const containers = findAllMediaContainers()
        for (const container of containers) {
          // Check if container exists
          if (!container) continue
          
          // CRITICAL: Skip containers that are blocked (have video in edit modal)
          if (blockedContainers.has(container)) {
            continue
          }
          
          // Check if button already exists
          const existingButton = container.querySelector('.fchub-stream-button-container')
          
          // Determine if this is a comment context
          // Use detectUploadContext to properly detect if this is POST or COMMENT context
          // CRITICAL: Edit feed dialogs should be treated as POST, not COMMENT
          const uploadContext = detectUploadContext(container)
          const inCommentContext = uploadContext === UPLOAD_CONTEXT.COMMENT
          const isEditDialog = isInEditFeedDialog(container)
          
          // Enhanced edit dialog detection - check multiple ways
          const isEditDialogEnhanced = isInEditFeedDialog(container) ||
                                       isInEditFeedDialog(container.parentElement) ||
                                       !!document.querySelector('.el-dialog[data-feed-id]') ||
                                       !!document.querySelector('.fcom_feed_modal')
          
          // Context detection (verbose logging removed for production)
          
          // CRITICAL: Edit feed dialogs are ALWAYS POST context, never COMMENT
          // Even if comment video is disabled, edit feed dialogs should show button
          if (isEditDialog || isEditDialogEnhanced) {
            // Force POST context for edit dialogs - don't check comment settings
          } else {
            // Only check comment settings for non-edit-dialog contexts
            // If button exists but should be removed (comment context + disabled)
            if (existingButton && inCommentContext && !isCommentVideoEnabled()) {
              logger.log('[FCHub Stream] Removing button - comment video is disabled')
              existingButton.remove()
              mountedContainers.delete(container)
              continue
            }
          }
          
          // Check if this is an edit feed modal and detect existing FCHub Stream video
          let existingVideoData = null
          if (isEditDialog || isEditDialogEnhanced) {
            // Try multiple modal selectors - FluentCommunity may use different structures
            const modal = container.closest('.el-dialog[data-feed-id]') ||
                          container.closest('.fcom_feed_modal') ||
                          container.closest('[role="dialog"][data-feed-id]') ||
                          container.closest('.fcom_modal') ||
                          container.closest('.el-dialog') ||
                          document.querySelector('.el-dialog[data-feed-id]') ||
                          document.querySelector('.fcom_feed_modal')
            
            if (modal) {
              // Check cache first to avoid repeated DOM queries
              let cachedVideo = modalVideoCache.get(modal)
              if (!cachedVideo) {
                // Cache miss - detect video and cache result
                const detectedVideo = detectExistingVideoInModal(modal)
                if (detectedVideo) {
                  modalVideoCache.set(modal, detectedVideo)
                  cachedVideo = detectedVideo
                } else {
                  // Cache null result to avoid repeated checks
                  modalVideoCache.set(modal, null)
                }
              }
              
              existingVideoData = cachedVideo

              if (existingVideoData) {
                // CRITICAL: Block this container from ever mounting a button
                // This prevents accidental replacement which could cause video loss
                blockedContainers.add(container)
                
                // CRITICAL: Hide upload button when editing post with existing FCHub Stream video
                // This prevents accidental replacement which could cause video loss
                // User must remove old video through FluentCommunity UI first if they want to replace
                if (existingButton) {
                  logger.log('[FCHub Stream] Hiding upload button - post already has FCHub Stream video. User must remove old video first.')
                  existingButton.style.display = 'none'
                  // Mark as hidden so we don't try to mount again
                  existingButton.dataset.fchubHiddenForExistingVideo = 'true'
                  mountedContainers.delete(container)
                }
                // Don't mount button if video exists - prevent accidental replacement
                // This is a safety measure to prevent data loss
                continue // CRITICAL: Skip this container to prevent button mounting
              } else {
                // No existing video detected - unblock container and show button if it was hidden before
                blockedContainers.delete(container) // Remove from blocked set
                if (existingButton && existingButton.dataset.fchubHiddenForExistingVideo === 'true') {
                  existingButton.style.display = 'inline-block'
                  delete existingButton.dataset.fchubHiddenForExistingVideo
                  // Clear cache when video is removed
                  modalVideoCache.delete(modal)
                }
              }
            }
          }
          
          // If button doesn't exist and should be mounted
          // CRITICAL: Don't mount if button was hidden due to existing video OR if existing video was detected OR container is blocked
          if (!existingButton && !mountedContainers.has(container) && !blockedContainers.has(container)) {
            // Double-check: if we detected existing video, don't mount button
            if (existingVideoData) {
              logger.log('[FCHub Stream] Skipping button mount - existing video detected:', existingVideoData)
              blockedContainers.add(container) // Block container to prevent future mounts
              continue // Don't mount button if video exists
            }
            
            logger.log('[FCHub Stream] Mounting button - no existing video detected')
            mountButton(container, existingVideoData)
            mountedContainers.add(container)
            
          }
        }
      }
      
      // Continuous MutationObserver - NEVER disconnect
      // Also watch for video removal in edit modals
      // Throttle to prevent excessive calls
      let observerTimeout = null
      let lastCheckTime = 0
      const THROTTLE_MS = 300 // Increased throttle to 300ms
      
      const observer = new MutationObserver((mutations) => {
        // CRITICAL: Watch for modal removal - clear all state to prevent stale cache
        // This fixes GAP #8 (context cache) and GAP #3 (modal state reset)
        mutations.forEach((mutation) => {
          mutation.removedNodes.forEach((node) => {
            if (node.nodeType === Node.ELEMENT_NODE) {
              // Check if removed node is a modal/dialog
              if (node.classList && (
                node.classList.contains('el-dialog') ||
                node.classList.contains('fcom_feed_modal') ||
                node.classList.contains('fcom_feed_edit_dialog') ||
                node.classList.contains('fcom_modal')
              )) {
                logger.log('[FCHub Stream] Modal removed from DOM, clearing all state (context cache, modal cache, mounted containers)')

                // GAP #8: Clear context cache
                clearContextCache()

                // GAP #3: Clear modal state
                modalVideoCache.clear()
                mountedContainers.clear()

                // blockedContainers is WeakSet - will be garbage collected automatically
                // No need to clear explicitly, but log for debugging
                logger.log('[FCHub Stream] Modal state reset complete - blockedContainers will be garbage collected')
              }
            }
          })
        })

        // Throttle observer calls to prevent spam
        const now = Date.now()
        if (now - lastCheckTime < THROTTLE_MS) {
          // Too soon, skip this check
          return
        }

        if (observerTimeout) {
          clearTimeout(observerTimeout)
        }
        observerTimeout = setTimeout(() => {
          lastCheckTime = Date.now()
          checkAndMountButtons()
        }, THROTTLE_MS)
        
        // CRITICAL: Watch for video removal in edit modals
        // If user removes video through FluentCommunity UI, show button again
        // Also hide "Remove Media" links for FCHub Stream videos
        // Check multiple modal selectors
        const activeModals = document.querySelectorAll(
          '.el-dialog[data-feed-id], ' +
          '.fcom_feed_modal, ' +
          '[role="dialog"][data-feed-id], ' +
          '.fcom_modal .fcom_feed_composer'
        )
        
        if (activeModals.length > 0 || isInEditFeedDialog(document.activeElement)) {
          activeModals.forEach(modal => {
            // Check cache first, then detect if needed
            let existingVideoData = modalVideoCache.get(modal)
            if (existingVideoData === undefined) {
              // Not in cache - detect and cache
              existingVideoData = detectExistingVideoInModal(modal)
              modalVideoCache.set(modal, existingVideoData || null)
            }
            
            const buttonContainer = modal.querySelector('.fchub-stream-button-container') ||
                                   modal.querySelector('.fcom_media_actions .fchub-stream-button-container') ||
                                   modal.querySelector('.fcom_composer_apps .fchub-stream-button-container')
            
            if (buttonContainer) {
              if (existingVideoData) {
                // Video exists - keep button hidden
                if (buttonContainer.style.display !== 'none') {
                  logger.log('[FCHub Stream] MutationObserver: Hiding button - video detected in modal', existingVideoData)
                  buttonContainer.style.display = 'none'
                  buttonContainer.dataset.fchubHiddenForExistingVideo = 'true'
                }
              } else {
                // No video - show button if it was hidden
                if (buttonContainer.dataset.fchubHiddenForExistingVideo === 'true') {
                  logger.log('[FCHub Stream] MutationObserver: Showing button - video was removed from modal')
                  buttonContainer.style.display = 'inline-block'
                  delete buttonContainer.dataset.fchubHiddenForExistingVideo
                  // Clear cache when video is removed
                  modalVideoCache.delete(modal)
                }
              }
            }
            
            // CRITICAL: Hide "Remove Media" links for FCHub Stream videos in edit dialogs
            // Prevents accidental video removal during post editing
            if (existingVideoData) {
              // Find all potential "Remove Media" links/buttons in modal
              // Use valid CSS selectors only (no :contains() pseudo-selector)
              const potentialRemoveLinks = modal.querySelectorAll(
                'a[href*="remove"], ' +
                '.remove-media, ' +
                'button[class*="remove"], ' +
                '[class*="remove-media"], ' +
                'a, button'
              )
              
              // Filter by text content (since :contains() is not valid CSS)
              potentialRemoveLinks.forEach(link => {
                const linkText = link.textContent?.toLowerCase() || ''
                if ((linkText.includes('remove') || linkText.includes('usuń')) && 
                    !link.dataset.fchubHiddenForVideo) {
                  logger.log('[FCHub Stream] MutationObserver: Hiding "Remove Media" link for FCHub Stream video')
                  link.style.display = 'none'
                  link.dataset.fchubHiddenForVideo = 'true'
                }
              })
              
              // Also check for links near FCHub Stream iframes
              const fchubIframes = modal.querySelectorAll('iframe[src*="cloudflarestream.com"], iframe[src*="bunny.net"]')
              fchubIframes.forEach(iframe => {
                // Find parent container and look for remove links
                const mediaContainer = iframe.closest('.feed_media, .feed_media_iframe_html, .fcom_top_media')
                if (mediaContainer) {
                  const removeLinks = mediaContainer.querySelectorAll('a, button, [class*="remove"]')
                  removeLinks.forEach(link => {
                    const linkText = link.textContent?.toLowerCase() || ''
                    if ((linkText.includes('remove') || linkText.includes('usuń')) && 
                        !link.dataset.fchubHiddenForVideo) {
                      logger.log('[FCHub Stream] MutationObserver: Hiding "Remove Media" link near FCHub Stream iframe')
                      link.style.display = 'none'
                      link.dataset.fchubHiddenForVideo = 'true'
                    }
                  })
                }
              })
            } else {
              // No video - show remove links if they were hidden
              const hiddenRemoveLinks = modal.querySelectorAll('[data-fchub-hidden-for-video="true"]')
              hiddenRemoveLinks.forEach(link => {
                logger.log('[FCHub Stream] MutationObserver: Showing "Remove Media" link - video was removed')
                link.style.display = ''
                delete link.dataset.fchubHiddenForVideo
              })
            }
          })
        }
      })
      
      // Observe body continuously
      observer.observe(document.body, {
        childList: true,
        subtree: true,
        attributes: true, // Watch for style changes
        attributeFilter: ['style', 'data-fchub-hidden-for-existing-video']
      })
      
      // Also check periodically (every 2 seconds) as backup
      setInterval(() => {
        checkAndMountButtons()
      }, 2000)
      
      // Initial check
      checkAndMountButtons()
    }
  }

  // Intercept fetch to add media object to POST requests
  setupFetchInterceptor()

  // ============================================================================
  // GAP #1: FluentCommunity Event Listeners Integration
  // ============================================================================
  // Listen for FluentCommunity events to ensure proper synchronization

  // Listen for feed updated events (GAP #10)
  // This is emitted by fetch interceptor after successful UPDATE request
  window.addEventListener('fchub-stream:feed-updated', (event) => {
    const feedData = event.detail
    logger.log('[FCHub Stream] Received feed-updated event:', feedData)

    // Clear modal video cache to force re-detection
    // This ensures button state is correctly updated after feed update
    modalVideoCache.clear()

    // Re-check and mount buttons with fresh data
    // Small delay to allow DOM to update from FluentCommunity's side
    setTimeout(() => {
      checkAndMountButtons()
    }, 300)
  })

  // Listen for FluentCommunity's native feed updated event (emitted by PHP)
  // This is emitted by ShortcodeProcessor::handle_feed_updated() (GAP #7)
  window.addEventListener('fluent_community/feed/updated', (event) => {
    const { feed_id, meta } = event.detail || {}
    logger.log('[FCHub Stream] FluentCommunity feed/updated event:', { feed_id, meta })

    if (feed_id) {
      // Clear cache for this specific feed
      modalVideoCache.clear()

      // Re-check UI state
      setTimeout(() => {
        checkAndMountButtons()
      }, 300)
    }
  })

  // Listen for modal/dialog close events (GAP #2)
  // FluentCommunity may emit these when user closes modals
  window.addEventListener('fluent_community/feed_modal_closed', () => {
    logger.log('[FCHub Stream] FluentCommunity modal closed event')

    // Clear all state (GAP #2, #3)
    modalVideoCache.clear()
    mountedContainers.clear()
    clearContextCache()

    logger.log('[FCHub Stream] Cleared all state after modal close')
  })

  // Listen for composer close events
  window.addEventListener('fluent_community/composer_closed', () => {
    logger.log('[FCHub Stream] FluentCommunity composer closed event')

    // Clear state related to composer
    modalVideoCache.clear()
    clearContextCache()
  })

  // Listen for feed data change events (if FluentCommunity emits them)
  window.addEventListener('fluent_community/feed_data_changed', (event) => {
    logger.log('[FCHub Stream] FluentCommunity feed data changed:', event.detail)

    // Clear cache and re-check
    modalVideoCache.clear()
    setTimeout(() => {
      checkAndMountButtons()
    }, 200)
  })

  // ============================================================================
  // GAP #2: Enhanced Cache Invalidation on Navigation
  // ============================================================================
  // Clear cache when user navigates (Vue Router uses hash or history)

  // Listen for hash changes (Vue Router hash mode)
  window.addEventListener('hashchange', () => {
    const newHash = window.location.hash
    logger.log('[FCHub Stream] Route changed (hashchange):', newHash)

    // Clear all cache on navigation to prevent stale data
    modalVideoCache.clear()
    mountedContainers.clear()
    clearContextCache()

    // Re-check buttons after navigation
    setTimeout(() => {
      checkAndMountButtons()
    }, 500) // Longer delay for navigation
  })

  // Listen for popstate (Vue Router history mode, back/forward)
  window.addEventListener('popstate', () => {
    logger.log('[FCHub Stream] Route changed (popstate):', window.location.pathname)

    // Clear cache on back/forward navigation
    modalVideoCache.clear()
    mountedContainers.clear()
    clearContextCache()

    // Re-check buttons
    setTimeout(() => {
      checkAndMountButtons()
    }, 500)
  })

  // Listen for page visibility changes (user switches tabs)
  // Clear cache when user returns to ensure fresh state
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      logger.log('[FCHub Stream] Page became visible - refreshing state')

      // Clear cache when user returns to tab
      modalVideoCache.clear()

      // Re-check buttons
      setTimeout(() => {
        checkAndMountButtons()
      }, 300)
    }
  })

  function setupFetchInterceptor() {
    let pendingVideoData = null
    
    // Listen for video upload
    window.addEventListener('fchub-stream-video-added', (e) => {
      pendingVideoData = e.detail
    })
    
    // Intercept fetch
    const originalFetch = window.fetch
    window.fetch = async function(...args) {
      const [url, options] = args

      // Check if it's a feed/comment POST/PUT request using centralized endpoint matching
      const isFeedOrCommentRequest = matchesEndpoint(url, [API_ENDPOINTS.FEEDS, API_ENDPOINTS.COMMENTS])
      
      // Check if it's an UPDATE request (PUT or POST with feed ID in URL)
      const isUpdateRequest = (options?.method === 'PUT' || options?.method === 'POST') && 
                              url.includes(API_ENDPOINTS.FEEDS) && 
                              /\d+/.test(url) // URL contains feed ID (numeric)

      if ((options?.method === 'POST' || options?.method === 'PUT') && isFeedOrCommentRequest) {
        if (pendingVideoData) {
          try {
            const body = JSON.parse(options.body || '{}')

            // CRITICAL: For UPDATE requests, check if post already has FCHub Stream video
            // If yes, this should NOT happen (button should be hidden), but add extra safety check
            if (isUpdateRequest) {
              // Extract feed ID from URL
              const feedIdMatch = url.match(/\/feeds\/(\d+)/)
              if (feedIdMatch) {
                const feedId = feedIdMatch[1]
                logger.log('[FCHub Stream] UPDATE request detected for feed ID:', feedId)
                // Note: We can't check existing video here (would require API call)
                // But button should already be hidden by checkAndMountButtons()
                // This is just a safety log
              }
            }

            // Detect context from URL - UPDATE requests are always POST context, not COMMENT
            // Check if URL contains feed ID (UPDATE) vs just /feeds (CREATE)
            let context = UPLOAD_CONTEXT.POST
            if (url.includes(API_ENDPOINTS.COMMENTS)) {
              context = UPLOAD_CONTEXT.COMMENT
            } else if (isUpdateRequest) {
              // UPDATE request - always POST context, not comment
              context = UPLOAD_CONTEXT.POST
            } else {
              // CREATE request
              context = UPLOAD_CONTEXT.POST
            }

            // Add media object (like YouTube)
            body.media = {
              type: 'iframe_html',
              provider: pendingVideoData.provider,
              html: pendingVideoData.shortcode, // Backend will process this
              image: pendingVideoData.thumbnail_url,
              video_id: pendingVideoData.video_id,
              status: pendingVideoData.status || 'pending', // Send video status to backend
              customer_subdomain: pendingVideoData.customer_subdomain || '', // For Cloudflare encoding overlay
              context: context, // Add context for backend validation
            }
            
            // If this is replacing an existing video, add replacement info
            // NOTE: This should rarely happen now since button is hidden for existing videos
            if (pendingVideoData.replaces_video_id) {
              body.media.replaces_video_id = pendingVideoData.replaces_video_id
              body.media.replaces_provider = pendingVideoData.replaces_provider
              logger.log('[FCHub Stream] WARNING: Replacing video in UPDATE request - this should be prevented by UI:', {
                old_video_id: pendingVideoData.replaces_video_id,
                new_video_id: pendingVideoData.video_id,
                feed_id: isUpdateRequest ? url.match(/\/feeds\/(\d+)/)?.[1] : 'N/A'
              })
            }

            options.body = JSON.stringify(body)

            logger.log('[FCHub Stream] Added video to ' + (isUpdateRequest ? 'UPDATE' : 'CREATE') + ' request with context:', context)

            // Clear pending video
            pendingVideoData = null
          } catch (e) {
            logger.error('[FCHub Stream] Failed to parse/modify request body:', e)
          }
        }
      }

      // Execute request
      const response = await originalFetch.apply(this, args)

      // CRITICAL: Process response for UPDATE requests (GAP #10)
      // This fixes the issue where UI doesn't refresh after feed update
      if (isUpdateRequest && isFeedOrCommentRequest && response.ok) {
        try {
          // Clone response to read it without consuming original
          const responseData = await response.clone().json()

          if (responseData.feed?.meta?.media_preview) {
            logger.log('[FCHub Stream] Feed updated, emitting event with new data', {
              feed_id: responseData.feed.id,
              video_id: responseData.feed.meta.media_preview.video_id,
              provider: responseData.feed.meta.media_preview.provider
            })

            // Emit event with updated feed data
            window.dispatchEvent(new CustomEvent('fchub-stream:feed-updated', {
              detail: {
                feed_id: responseData.feed.id,
                meta: responseData.feed.meta,
                message: responseData.feed.message,
                message_rendered: responseData.feed.message_rendered
              }
            }))
          } else {
            // Feed was updated but no video in response
            logger.log('[FCHub Stream] Feed updated without video', {
              feed_id: responseData.feed?.id
            })
          }
        } catch (e) {
          logger.error('[FCHub Stream] Failed to process response:', e)
        }
      }

      return response
    }
    
    // Also intercept XMLHttpRequest (if FluentCommunity uses axios/jQuery)
    const originalOpen = XMLHttpRequest.prototype.open
    const originalSend = XMLHttpRequest.prototype.send
    
    XMLHttpRequest.prototype.open = function(method, url, ...rest) {
      this._method = method
      this._url = url
      return originalOpen.call(this, method, url, ...rest)
    }
    
    XMLHttpRequest.prototype.send = function(body) {
      // Check if it's a feed/comment POST/PUT request using centralized endpoint matching
      const isFeedOrCommentRequest = matchesEndpoint(this._url, [API_ENDPOINTS.FEEDS, API_ENDPOINTS.COMMENTS])
      
      // Check if it's an UPDATE request (PUT or POST with feed ID in URL)
      const isUpdateRequest = (this._method === 'PUT' || this._method === 'POST') && 
                              this._url.includes(API_ENDPOINTS.FEEDS) && 
                              /\d+/.test(this._url) // URL contains feed ID (numeric)

      if ((this._method === 'POST' || this._method === 'PUT') && isFeedOrCommentRequest) {
        try {
          // Parse body once
          const bodyObj = JSON.parse(body || '{}')
          
          // Detect context from URL - UPDATE requests are always POST context, not COMMENT
          let context = UPLOAD_CONTEXT.POST
          if (this._url.includes(API_ENDPOINTS.COMMENTS)) {
            context = UPLOAD_CONTEXT.COMMENT
          } else if (isUpdateRequest) {
            // UPDATE request - always POST context, not comment
            context = UPLOAD_CONTEXT.POST
          } else {
            // CREATE request
            context = UPLOAD_CONTEXT.POST
          }

          // Scenario 1: New video upload (pendingVideoData exists)
          if (pendingVideoData) {
            // Add media object
            bodyObj.media = {
              type: 'iframe_html',
              provider: pendingVideoData.provider,
              html: pendingVideoData.shortcode,
              image: pendingVideoData.thumbnail_url,
              video_id: pendingVideoData.video_id,
              status: pendingVideoData.status || 'pending', // Send video status to backend
              customer_subdomain: pendingVideoData.customer_subdomain || '', // For Cloudflare encoding overlay
              context: context, // Add context for backend validation
            }

            const newBody = JSON.stringify(bodyObj)
            logger.log('[FCHub Stream] Added video to XHR ' + (isUpdateRequest ? 'UPDATE' : 'CREATE') + ' request with context:', context)
            
            // Clear pending
            pendingVideoData = null
            return originalSend.call(this, newBody)
          }
          
          // Scenario 2: Update existing post (no new video, but might have existing one)
          // CRITICAL FIX: If we are updating a post (isUpdateRequest) and there is NO pendingVideoData,
          // we must check if there was an existing video that should be preserved.
          // FluentCommunity frontend might not include the video in the update payload if it's an iframe.
          else if (isUpdateRequest && !pendingVideoData) {
            // Check if we are in an edit dialog with an existing FCHub Stream video
            // Find the active edit modal
            const activeModal = document.querySelector('.el-dialog[data-feed-id]') || 
                               document.querySelector('.fcom_feed_modal') ||
                               document.querySelector('[role="dialog"][data-feed-id]')
            
            if (activeModal) {
              // Check for existing video in this modal
              const existingVideo = detectExistingVideoInModal(activeModal)
              
              if (existingVideo) {
                logger.log('[FCHub Stream] Preserving existing video in XHR UPDATE request:', existingVideo)
                
                // Construct media object for existing video
                // We need to reconstruct the shortcode/HTML for the backend
                // The backend mainly needs provider and video_id to reconstruct the iframe
                
                // Determine shortcode format based on provider
                let shortcode = ''
                if (existingVideo.provider === 'cloudflare_stream') {
                  // Reconstruct basic iframe for backend validation/storage
                  shortcode = `<iframe src="https://cloudflarestream.com/${existingVideo.video_id}/iframe" style="border: none" allow="accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture;" allowfullscreen="true"></iframe>`
                } else if (existingVideo.provider === 'bunny_stream') {
                  shortcode = `<iframe src="https://iframe.mediadelivery.net/embed/${existingVideo.video_id}" style="border: none" allow="accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture;" allowfullscreen="true"></iframe>`
                }
                
                bodyObj.media = {
                  type: 'iframe_html',
                  provider: existingVideo.provider,
                  html: shortcode, // Reconstructed shortcode
                  // image: '', // We might not have the thumbnail URL, backend should handle this or keep existing
                  video_id: existingVideo.video_id,
                  status: 'ready', // Existing videos are ready
                  context: context,
                  is_existing: true // Flag for backend to know this is an existing video preservation
                }
                
                const newBody = JSON.stringify(bodyObj)
                return originalSend.call(this, newBody)
              }
            }
          }
        } catch (e) {
          logger.error('[FCHub Stream] XHR intercept error:', e)
        }
      }

      return originalSend.call(this, body)
    }
  }

  // Check settings immediately - don't wait
  const enabled = isVideoUploadEnabled()
  
  if (enabled) {
    initializeUploadButton()
  } else {
    // Settings not ready yet, check quickly (max 1 second)
    let attempts = 0
    const maxAttempts = 10 // 10 attempts * 100ms = 1 second
    
    const checkSettings = () => {
      attempts++
      const isEnabled = isVideoUploadEnabled()
      
      if (isEnabled) {
        initializeUploadButton()
      } else if (attempts < maxAttempts) {
        // Check faster - 100ms instead of 200ms
        setTimeout(checkSettings, 100)
      }
    }
    
    checkSettings()
  }
}

/**
 * Initialize automatic video status polling for rendered posts
 * Detects elements with class "fchub-stream-encoding" and polls for status updates
 */
function initVideoStatusPolling() {
  const pollingElements = new Map() // Track elements already being polled

  async function checkAndUpdateVideo(element) {
    const videoId = element.dataset.videoId
    const provider = element.dataset.provider || 'cloudflare_stream'

    if (!videoId) {
      return
    }

    try {
      const settings = window.fchubStreamSettings ||
                       window.fcom_portal_general?.fchubStreamSettings ||
                       window.fluentComAdmin?.fchubStreamSettings ||
                       {}
      
      const baseUrl = settings.rest_url || '/wp-json/fluent-community/v2/stream'
      const nonce = settings.rest_nonce || ''

      // Use fetch instead of axios for vanilla JS
      const response = await fetch(`${baseUrl}/video-status/${videoId}?provider=${provider}`, {
        headers: {
          'X-WP-Nonce': nonce
        }
      })

      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`)
      }

      const data = await response.json()

      if (data.success && data.data) {
        const videoData = data.data

        // If video is ready, replace encoding HTML with player iframe
        // CRITICAL: Even though pctComplete=100, Cloudflare manifest URLs may return 404
        // for additional 10-30 seconds during CDN propagation. We MUST probe manifest first.
        if (videoData.readyToStream || videoData.status === 'ready') {
          // Get the player HTML from response or construct iframe
          const playerHtml = videoData.html || videoData.playerUrl

          if (playerHtml) {
            // CRITICAL FIX: Probe manifest URL before rendering iframe
            // Extract video ID and customer subdomain from playerHtml or videoData
            const videoIdFromElement = element.dataset.videoId
            
            if (!videoIdFromElement) {
              logger.warn('[FCHub Stream] No video ID found in element dataset, skipping probe')
              return false
            }

            // Extract customer subdomain from playerHtml (iframe src attribute)
            // Example: <iframe src="https://customer-efgtuz47vfed9702.cloudflarestream.com/VIDEO_ID/iframe"
            const iframeSrcMatch = playerHtml.match(/https:\/\/(customer-[a-z0-9]+)\.cloudflarestream\.com/)
            const customerSubdomain = iframeSrcMatch ? iframeSrcMatch[1] : null
            
            if (!customerSubdomain) {
              logger.warn('[FCHub Stream] Could not extract customer subdomain from playerHtml, rendering without probe')
              element.outerHTML = playerHtml
              pollingElements.delete(element)
              if (element._pollingInterval) {
                clearInterval(element._pollingInterval)
                delete element._pollingInterval
              }
              return true
            }

            // Construct manifest URL (Cloudflare Stream uses .m3u8 for HLS)
            const manifestUrl = `https://${customerSubdomain}.cloudflarestream.com/${videoIdFromElement}/manifest/video.m3u8`
            
            logger.log('[FCHub Stream] Probing manifest URL:', manifestUrl)
            
            // Probe manifest - only render iframe if manifest is accessible (200)
            fetch(manifestUrl, { method: 'HEAD' })
              .then(response => {
                if (response.ok) {
                  logger.log('[FCHub Stream] Manifest accessible (HTTP 200), rendering iframe for video:', videoIdFromElement)
                  
                  // CRITICAL: Update database status to 'ready' so next page load shows iframe immediately
                  // This prevents encoding overlay flash on refresh for videos that are already encoded
                  const updateUrl = `${baseUrl}/video-update-status`
                  fetch(updateUrl, {
                    method: 'POST',
                    headers: {
                      'Content-Type': 'application/json',
                      'X-WP-Nonce': nonce
                    },
                    body: JSON.stringify({
                      video_id: videoIdFromElement,
                      provider: provider,
                      status: 'ready',
                      html: playerHtml
                    })
                  })
                    .then(res => res.json())
                    .then(data => {
                      if (data.success) {
                        logger.log('[FCHub Stream] Database updated to ready for video:', videoIdFromElement)
                      }
                    })
                    .catch(err => {
                      logger.warn('[FCHub Stream] Failed to update database status:', err.message)
                      // Non-critical - video still works, just will flash encoding on next refresh
                    })
                  
                  // Replace the entire encoding wrapper with ready player
                  element.outerHTML = playerHtml

                  // Remove from polling map
                  pollingElements.delete(element)

                  // Stop polling interval for this element
                  if (element._pollingInterval) {
                    clearInterval(element._pollingInterval)
                    delete element._pollingInterval
                  }
                } else {
                  logger.warn('[FCHub Stream] Manifest not accessible yet (HTTP ' + response.status + '), continuing polling for video:', videoIdFromElement)
                  // Continue polling - manifest not ready yet
                }
              })
              .catch(err => {
                logger.warn('[FCHub Stream] Manifest probe failed, continuing polling:', err.message)
                // Continue polling on error
              })

            // Return false to continue polling until probe succeeds
            return false
          }
        }

        // If failed, show error
        if (videoData.status === 'failed') {
          const errorDiv = document.createElement('div')
          errorDiv.style.cssText = 'padding: 20px; text-align: center; color: #dc2626;'
          errorDiv.textContent = 'Video encoding failed'
          element.outerHTML = errorDiv.outerHTML
          pollingElements.delete(element)
          if (element._pollingInterval) {
            clearInterval(element._pollingInterval)
            delete element._pollingInterval
          }

          return true
        }
      }
    } catch (error) {
      logger.error('[FCHub Stream] Video status check failed:', error.message)
    }

    return false
  }

  /**
   * Start polling for a single encoding element
   */
  function startPollingForElement(element) {
    // Skip if already polling
    if (pollingElements.has(element) || element._pollingInterval) {
      return
    }

    pollingElements.set(element, true)

    // Get polling interval from settings (default 30 seconds = 30000ms)
    const settings = window.fchubStreamSettings ||
                     window.fcom_portal_general?.fchubStreamSettings ||
                     window.fluentComAdmin?.fchubStreamSettings ||
                     {}
    const interval = settings.upload?.polling_interval || 30000

    // Immediate check
    checkAndUpdateVideo(element)

    // Start interval polling
    element._pollingInterval = setInterval(() => {
      // Check if element still exists in DOM
      if (!document.contains(element)) {
        clearInterval(element._pollingInterval)
        delete element._pollingInterval
        pollingElements.delete(element)
        return
      }

      checkAndUpdateVideo(element).then((updated) => {
        if (updated) {
          // Already cleaned up in checkAndUpdateVideo
        }
      })
    }, interval)
  }

  /**
   * Check if video is already ready (has iframe player)
   * This prevents unnecessary polling when video is already ready but HTML
   * still has encoding overlay class (e.g., after feed reload)
   */
  function isVideoAlreadyReady(element) {
    // Check if element or its children already contain an iframe (player)
    // This indicates video is ready and player is already rendered
    if (element.querySelector('iframe')) {
      return true
    }
    
    // Check if element's parent contains iframe (player might be outside encoding wrapper)
    const parent = element.parentElement
    if (parent && parent.querySelector('iframe')) {
      return true
    }
    
    // Check if element has data-status="ready" attribute (if backend adds it)
    if (element.dataset.status === 'ready') {
      return true
    }
    
    // Check if element's innerHTML contains iframe (player HTML)
    if (element.innerHTML.includes('<iframe')) {
      return true
    }
    
    return false
  }

  /**
   * Find and initialize polling for all encoding elements
   */
  function findAndInitEncodingElements() {
    const encodingElements = document.querySelectorAll('.fchub-stream-encoding')
    
    encodingElements.forEach((element) => {
      // Skip if already being polled
      if (pollingElements.has(element)) {
        return
      }

      // Check if video is already ready (has iframe player)
      // This prevents unnecessary polling on feed reload when video is already ready
      if (isVideoAlreadyReady(element)) {
        logger.log('[FCHub Stream] Skipping polling for video - already ready (iframe found)', element.dataset.videoId)
        return
      }

      startPollingForElement(element)
    })
  }

  // Initial scan
  findAndInitEncodingElements()

  // Watch for new elements added to DOM (e.g., infinite scroll, new posts)
  const observer = new MutationObserver(() => {
    findAndInitEncodingElements()
  })

  observer.observe(document.body, {
    childList: true,
    subtree: true
  })
}

/**
 * Initialize floating video player (sticky player on scroll)
 */
function initFloatingVideoPlayer() {
  const { init } = useFloatingPlayer()
  init()
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => {
    initFCHubStreamPortal()
    initVideoStatusPolling()
    initFloatingVideoPlayer()
  })
} else {
  initFCHubStreamPortal()
  initVideoStatusPolling()
  initFloatingVideoPlayer()
}

// Also listen for Portal loaded event (if available)
window.addEventListener('fluent_community_portal_loaded', () => {
  initFCHubStreamPortal()
  initVideoStatusPolling()
  initFloatingVideoPlayer()
})

// Listen for DOMContentLoaded to check if settings are available later
document.addEventListener('DOMContentLoaded', () => {
  // Wait a bit for FluentCommunity to expose vars
  setTimeout(() => {
    const settings = window.fchubStreamSettings ||
                    window.fcom_portal_general?.fchubStreamSettings ||
                    window.fluentComAdmin?.fchubStreamSettings
    
    if (settings && settings.enabled) {
      initFCHubStreamPortal()
    }
  }, 500) // Wait 500ms for FluentCommunity to expose vars
})
