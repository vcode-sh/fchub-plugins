/**
 * Floating Video Player Composable
 *
 * Automatically shows a floating/sticky video player when user scrolls
 * past a playing video in the feed (similar to YouTube, Facebook, LinkedIn).
 *
 * Features:
 * - Intersection Observer to detect when video leaves viewport
 * - Automatic activation after video is visible for 3+ seconds (likely playing)
 * - CSS-based positioning (NO DOM manipulation to preserve playback)
 * - Floating player with close button
 * - Returns video to original position when scrolled back
 * - Drag & drop to reposition floating player
 * - Resize functionality with corner handles
 * - Persistent memory (position & size saved to localStorage)
 */

// Constants for localStorage and constraints
const STORAGE_KEY = 'fchub_stream_floating_prefs'
const DEFAULT_WIDTH = 400
const DEFAULT_HEIGHT = 225 // 16:9 ratio
const MIN_WIDTH = 200
const MAX_WIDTH = 800
const ASPECT_RATIO = 16 / 9

/**
 * Initialize floating player for all video iframes in the feed
 */
export function useFloatingPlayer() {
  // Track active floating wrapper (only one at a time)
  let activeFloatingWrapper = null
  let closeButton = null
  let dragHandle = null
  let resizeHandles = []

  // Drag state
  let isDragging = false
  let dragOffsetX = 0
  let dragOffsetY = 0

  // Resize state
  let isResizing = false
  let resizeStartWidth = 0
  let resizeStartHeight = 0
  let resizeStartLeft = 0
  let resizeStartTop = 0
  let resizeDirection = ''

  /**
   * Load saved position and size from localStorage
   */
  function loadFloatingPrefs() {
    try {
      const saved = localStorage.getItem(STORAGE_KEY)
      if (saved) {
        const prefs = JSON.parse(saved)

        // Validate and constrain to viewport
        const viewportWidth = window.innerWidth
        const viewportHeight = window.innerHeight

        let { x, y, width, height } = prefs

        // Constrain width
        width = Math.max(MIN_WIDTH, Math.min(MAX_WIDTH, width || DEFAULT_WIDTH))
        height = width / ASPECT_RATIO

        // Ensure position is on screen (with at least 50px visible)
        x = Math.max(0, Math.min(viewportWidth - 50, x))
        y = Math.max(0, Math.min(viewportHeight - 50, y))

        return { x, y, width, height }
      }
    } catch {
      // Invalid data, use defaults
    }

    // Return defaults (bottom-right corner)
    return {
      x: window.innerWidth - DEFAULT_WIDTH - 20,
      y: window.innerHeight - DEFAULT_HEIGHT - 20,
      width: DEFAULT_WIDTH,
      height: DEFAULT_HEIGHT
    }
  }

  /**
   * Save position and size to localStorage
   */
  function saveFloatingPrefs(x, y, width, height) {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify({ x, y, width, height }))
    } catch {
      // Storage failed, continue without saving
    }
  }

  /**
   * Handle drag start
   */
  function handleDragStart(e) {
    if (!activeFloatingWrapper) return

    isDragging = true
    const rect = activeFloatingWrapper.getBoundingClientRect()
    dragOffsetX = e.clientX - rect.left
    dragOffsetY = e.clientY - rect.top

    if (dragHandle) {
      dragHandle.style.cursor = 'grabbing'
    }
    e.preventDefault()
    e.stopPropagation()
  }

  /**
   * Handle drag move
   */
  function handleDragMove(e) {
    if (!isDragging || !activeFloatingWrapper) return

    const x = e.clientX - dragOffsetX
    const y = e.clientY - dragOffsetY

    // Constrain to viewport
    const viewportWidth = window.innerWidth
    const viewportHeight = window.innerHeight
    const rect = activeFloatingWrapper.getBoundingClientRect()

    const constrainedX = Math.max(0, Math.min(viewportWidth - rect.width, x))
    const constrainedY = Math.max(0, Math.min(viewportHeight - rect.height, y))

    activeFloatingWrapper.style.left = `${constrainedX}px`
    activeFloatingWrapper.style.top = `${constrainedY}px`
    activeFloatingWrapper.style.right = 'auto'
    activeFloatingWrapper.style.bottom = 'auto'

    e.preventDefault()
  }

  /**
   * Handle drag end
   */
  function handleDragEnd() {
    if (!isDragging || !activeFloatingWrapper) return

    isDragging = false
    if (dragHandle) {
      dragHandle.style.cursor = 'grab'
    }

    // Save position
    const rect = activeFloatingWrapper.getBoundingClientRect()
    saveFloatingPrefs(rect.left, rect.top, rect.width, rect.height)
  }

  /**
   * Handle resize start
   */
  function handleResizeStart(e, direction) {
    if (!activeFloatingWrapper) return

    isResizing = true
    resizeDirection = direction

    const rect = activeFloatingWrapper.getBoundingClientRect()
    resizeStartWidth = rect.width
    resizeStartHeight = rect.height
    resizeStartLeft = rect.left
    resizeStartTop = rect.top

    e.stopPropagation()
    e.preventDefault()
  }

  /**
   * Handle resize move
   */
  function handleResizeMove(e) {
    if (!isResizing || !activeFloatingWrapper) return

    const currentX = e.clientX
    const currentY = e.clientY

    const viewportWidth = window.innerWidth
    const viewportHeight = window.innerHeight

    // Calculate edges based on resize direction
    let left, top, right, bottom

    if (resizeDirection === 'bottom-right') {
      left = resizeStartLeft
      top = resizeStartTop
      right = Math.max(left + MIN_WIDTH, Math.min(viewportWidth, currentX))
      bottom = Math.max(top + MIN_WIDTH / ASPECT_RATIO, Math.min(viewportHeight, currentY))
    } else if (resizeDirection === 'bottom-left') {
      right = resizeStartLeft + resizeStartWidth
      top = resizeStartTop
      left = Math.max(0, Math.min(right - MIN_WIDTH, currentX))
      bottom = Math.max(top + MIN_WIDTH / ASPECT_RATIO, Math.min(viewportHeight, currentY))
    } else if (resizeDirection === 'top-right') {
      left = resizeStartLeft
      bottom = resizeStartTop + resizeStartHeight
      right = Math.max(left + MIN_WIDTH, Math.min(viewportWidth, currentX))
      top = Math.max(0, Math.min(bottom - MIN_WIDTH / ASPECT_RATIO, currentY))
    } else if (resizeDirection === 'top-left') {
      right = resizeStartLeft + resizeStartWidth
      bottom = resizeStartTop + resizeStartHeight
      left = Math.max(0, Math.min(right - MIN_WIDTH, currentX))
      top = Math.max(0, Math.min(bottom - MIN_WIDTH / ASPECT_RATIO, currentY))
    }

    // Calculate width and height from edges
    let newWidth = right - left
    let newHeight = bottom - top

    // Maintain aspect ratio - use width as primary dimension
    newHeight = newWidth / ASPECT_RATIO

    // If height would exceed bounds, recalculate from height
    if (top + newHeight > viewportHeight) {
      newHeight = viewportHeight - top
      newWidth = newHeight * ASPECT_RATIO
    }
    if (bottom - newHeight < 0) {
      newHeight = bottom
      newWidth = newHeight * ASPECT_RATIO
    }

    // Apply min/max width constraints
    newWidth = Math.max(MIN_WIDTH, Math.min(MAX_WIDTH, newWidth))
    newHeight = newWidth / ASPECT_RATIO

    // Calculate final position based on which edges are fixed
    let newLeft, newTop

    if (resizeDirection === 'bottom-right') {
      newLeft = left
      newTop = top
    } else if (resizeDirection === 'bottom-left') {
      newLeft = right - newWidth
      newTop = top
    } else if (resizeDirection === 'top-right') {
      newLeft = left
      newTop = bottom - newHeight
    } else if (resizeDirection === 'top-left') {
      newLeft = right - newWidth
      newTop = bottom - newHeight
    }

    // Apply new dimensions and position
    activeFloatingWrapper.style.width = `${newWidth}px`
    activeFloatingWrapper.style.height = `${newHeight}px`
    activeFloatingWrapper.style.left = `${newLeft}px`
    activeFloatingWrapper.style.top = `${newTop}px`

    e.preventDefault()
  }

  /**
   * Handle resize end
   */
  function handleResizeEnd() {
    if (!isResizing || !activeFloatingWrapper) return

    isResizing = false
    resizeDirection = ''

    // Save size and position
    const rect = activeFloatingWrapper.getBoundingClientRect()
    saveFloatingPrefs(rect.left, rect.top, rect.width, rect.height)
  }

  /**
   * Setup global drag and resize event listeners
   */
  function setupDragResizeListeners() {
    document.addEventListener('mousemove', (e) => {
      handleDragMove(e)
      handleResizeMove(e)
    })

    document.addEventListener('mouseup', () => {
      handleDragEnd()
      handleResizeEnd()
    })
  }

  /**
   * Show floating player using CSS positioning (NO DOM manipulation of iframe)
   */
  function showFloatingPlayer(wrapper) {
    // Only one floating player at a time
    if (activeFloatingWrapper) {
      closeFloatingPlayer()
    }

    // Pause all OTHER videos by reloading their iframes
    const allWrappers = document.querySelectorAll('.fchub-stream-player-wrapper')
    allWrappers.forEach(otherWrapper => {
      if (otherWrapper !== wrapper && !otherWrapper.classList.contains('fchub-stream-encoding')) {
        const otherIframe = otherWrapper.querySelector('iframe')
        if (otherIframe) {
          const currentSrc = otherIframe.src
          otherIframe.src = ''
          setTimeout(() => {
            otherIframe.src = currentSrc
          }, 100)
        }
      }
    })

    // Store reference
    activeFloatingWrapper = wrapper

    // Create placeholder to occupy original space and be observed
    const placeholder = document.createElement('div')
    placeholder.className = 'fchub-stream-floating-placeholder'
    placeholder.style.cssText = 'width: 100%; aspect-ratio: 16/9; background: transparent; visibility: hidden;'
    placeholder.dataset.videoId = wrapper.dataset.videoId

    // Insert placeholder BEFORE wrapper in DOM
    wrapper.parentNode.insertBefore(placeholder, wrapper)
    wrapper._floatingPlaceholder = placeholder

    // Mark wrapper as floating (position: fixed)
    wrapper.classList.add('fchub-stream-floating-mode')

    // Load saved preferences for position and size
    const prefs = loadFloatingPrefs()
    wrapper.style.left = `${prefs.x}px`
    wrapper.style.top = `${prefs.y}px`
    wrapper.style.width = `${prefs.width}px`
    wrapper.style.height = `${prefs.height}px`
    wrapper.style.right = 'auto'
    wrapper.style.bottom = 'auto'

    // Create drag handle bar (top bar for dragging)
    dragHandle = document.createElement('div')
    dragHandle.className = 'fchub-stream-floating-drag-handle'
    dragHandle.addEventListener('mousedown', handleDragStart)
    wrapper.appendChild(dragHandle)

    // Create close button
    closeButton = document.createElement('button')
    closeButton.className = 'fchub-stream-floating-close'
    closeButton.innerHTML = `
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <line x1="18" y1="6" x2="6" y2="18"></line>
        <line x1="6" y1="6" x2="18" y2="18"></line>
      </svg>
    `
    closeButton.setAttribute('aria-label', 'Close floating video')
    closeButton.addEventListener('click', (e) => {
      e.stopPropagation()
      closeFloatingPlayer(true)
    })
    wrapper.appendChild(closeButton)

    // Create 4 corner resize handles only (skip on mobile)
    const isMobile = window.innerWidth <= 768
    const corners = ['top-left', 'top-right', 'bottom-right', 'bottom-left']
    resizeHandles = []

    if (!isMobile) {
      corners.forEach(corner => {
        const handle = document.createElement('div')
        handle.className = `fchub-stream-floating-resize fchub-stream-floating-resize-${corner}`
        handle.addEventListener('mousedown', (e) => handleResizeStart(e, corner))
        wrapper.appendChild(handle)
        resizeHandles.push(handle)
      })
    }

    // Trigger animation after DOM update
    requestAnimationFrame(() => {
      wrapper.classList.add('fchub-stream-floating-active')
    })
  }

  /**
   * Close floating player and return to normal position
   */
  function closeFloatingPlayer(stopVideo = false) {
    if (!activeFloatingWrapper) return

    // Get references before clearing
    const wrapper = activeFloatingWrapper
    const placeholder = wrapper._floatingPlaceholder
    const observer = wrapper._floatingPlayerObserver
    const state = wrapper._floatingPlayerState

    // Stop video playback if requested (only when user clicks X button)
    if (stopVideo) {
      const iframe = wrapper.querySelector('iframe')
      if (iframe) {
        const currentSrc = iframe.src
        iframe.src = ''
        setTimeout(() => {
          iframe.src = currentSrc
        }, 100)
      }

      // Mark video as not playing
      if (state) {
        state.isPlaying = false
      }
    }

    // Remove floating classes
    wrapper.classList.remove('fchub-stream-floating-active')

    // Reset state - keep qualifiedForFloating so it can re-float quickly
    if (state) {
      state.hasBeenFloating = false
      state.firstVisibleTime = null
      state.lastClosedTime = Date.now()
    }

    // Switch observer back to wrapper
    if (observer && placeholder) {
      observer.unobserve(placeholder)
      observer.observe(wrapper)
    }

    // Remove placeholder
    if (placeholder?.parentNode) {
      placeholder.parentNode.removeChild(placeholder)
      delete wrapper._floatingPlaceholder
    }

    // Wait for animation to complete before removing mode class
    setTimeout(() => {
      if (wrapper) {
        wrapper.classList.remove('fchub-stream-floating-mode')

        // Reset inline styles
        wrapper.style.left = ''
        wrapper.style.top = ''
        wrapper.style.width = ''
        wrapper.style.height = ''
        wrapper.style.right = ''
        wrapper.style.bottom = ''

        // Remove drag handle
        if (dragHandle?.parentNode) {
          dragHandle.parentNode.removeChild(dragHandle)
          dragHandle = null
        }

        // Remove close button
        if (closeButton?.parentNode) {
          closeButton.parentNode.removeChild(closeButton)
          closeButton = null
        }

        // Remove all resize handles
        resizeHandles.forEach(handle => {
          if (handle?.parentNode) {
            handle.parentNode.removeChild(handle)
          }
        })
        resizeHandles = []

        activeFloatingWrapper = null
      }
    }, 200) // Match CSS transition duration
  }

  /**
   * Setup Intersection Observer for a video wrapper
   */
  function setupVideoObserver(wrapper) {
    const iframe = wrapper.querySelector('iframe')
    if (!iframe) return

    // Track when video first becomes visible (store on wrapper for persistence)
    if (!wrapper._floatingPlayerState) {
      wrapper._floatingPlayerState = {
        firstVisibleTime: null,
        wasVisible: false,
        hasBeenFloating: false,
        qualifiedForFloating: false,
        lastClosedTime: null,
        isPlaying: false
      }
    }

    const state = wrapper._floatingPlayerState

    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          const isPlaceholder = entry.target.classList.contains('fchub-stream-floating-placeholder')
          const visibilityThreshold = 0.1
          const isVisible = entry.isIntersecting && entry.intersectionRatio > visibilityThreshold

          if (isPlaceholder) {
            // Observing placeholder - close floating player when placeholder becomes visible (user scrolled back)
            const placeholderVisible = entry.isIntersecting && entry.intersectionRatio > 0.5

            if (placeholderVisible) {
              closeFloatingPlayer(false) // Close but don't stop video
            }
            return
          }

          // Observing wrapper (normal mode)
          if (isVisible) {
            // Video is visible in viewport (in normal position)
            if (!state.firstVisibleTime) {
              state.firstVisibleTime = Date.now()
            }
            state.wasVisible = true

            // Reset floating state when scrolled back into view naturally
            if (state.hasBeenFloating) {
              state.hasBeenFloating = false
              state.firstVisibleTime = Date.now()
            }
          } else {
            // Video is NOT visible (scrolled out of view)
            const timeVisible = state.firstVisibleTime ? (Date.now() - state.firstVisibleTime) : 0

            // Only show floating player if conditions are met
            const meetsTimeRequirement = state.qualifiedForFloating || (state.firstVisibleTime && timeVisible >= 3000)
            const cooldownPeriod = 2000
            const timeSinceClose = state.lastClosedTime ? (Date.now() - state.lastClosedTime) : Infinity
            const cooldownExpired = timeSinceClose > cooldownPeriod

            if (
              meetsTimeRequirement &&
              state.wasVisible &&
              !activeFloatingWrapper &&
              !state.hasBeenFloating &&
              cooldownExpired &&
              state.isPlaying
            ) {
              // Mark as qualified if this is first time meeting 3-second requirement
              if (!state.qualifiedForFloating && timeVisible >= 3000) {
                state.qualifiedForFloating = true
              }

              showFloatingPlayer(wrapper)
              state.hasBeenFloating = true

              // Start observing placeholder instead of wrapper
              observer.unobserve(wrapper)
              if (wrapper._floatingPlaceholder) {
                observer.observe(wrapper._floatingPlaceholder)
              }
            }
          }
        })
      },
      {
        threshold: [0, 0.1, 0.5, 1.0],
        rootMargin: '-50px'
      }
    )

    observer.observe(wrapper)

    // Store observer on wrapper for cleanup
    wrapper._floatingPlayerObserver = observer
  }

  /**
   * Initialize floating player for all video iframes
   */
  function initFloatingPlayers() {
    const videoWrappers = document.querySelectorAll('.fchub-stream-player-wrapper')

    videoWrappers.forEach((wrapper) => {
      // Skip if already initialized
      if (wrapper._floatingPlayerObserver) return

      // Skip encoding videos (wait until ready)
      if (wrapper.classList.contains('fchub-stream-encoding')) return

      setupVideoObserver(wrapper)
    })
  }

  /**
   * Watch for new videos added to DOM (infinite scroll, new posts)
   */
  function watchForNewVideos() {
    const observer = new MutationObserver(() => {
      initFloatingPlayers()
    })

    observer.observe(document.body, {
      childList: true,
      subtree: true
    })
  }

  /**
   * Setup global play event listener to auto-pause other videos
   * Uses Cloudflare Stream Player API postMessage events
   */
  function setupAutoPlayPause() {
    window.addEventListener('message', (event) => {
      // Check if this is a Cloudflare Stream event (play or pause)
      if (event.data?.__privateUnstableMessageType === 'event' &&
          (event.data?.eventName === 'play' || event.data?.eventName === 'pause')) {

        const eventName = event.data.eventName

        // Find which iframe sent this message
        const allWrappers = document.querySelectorAll('.fchub-stream-player-wrapper')
        let eventWrapper = null

        for (const wrapper of allWrappers) {
          const iframe = wrapper.querySelector('iframe')
          if (iframe && iframe.contentWindow === event.source) {
            eventWrapper = wrapper
            break
          }
        }

        if (!eventWrapper) return

        // Handle play event
        if (eventName === 'play') {
          // Mark this video as currently playing (for floating mode eligibility)
          if (!eventWrapper._floatingPlayerState) {
            eventWrapper._floatingPlayerState = {
              firstVisibleTime: null,
              wasVisible: false,
              hasBeenFloating: false,
              qualifiedForFloating: false,
              lastClosedTime: null,
              isPlaying: true
            }
          } else {
            eventWrapper._floatingPlayerState.isPlaying = true
          }

          // Pause all OTHER videos by reloading their iframes
          allWrappers.forEach(wrapper => {
            if (wrapper === eventWrapper || wrapper.classList.contains('fchub-stream-encoding')) {
              return
            }

            // Mark other videos as NOT playing
            if (wrapper._floatingPlayerState) {
              wrapper._floatingPlayerState.isPlaying = false
            }

            const iframe = wrapper.querySelector('iframe')
            if (iframe) {
              const currentSrc = iframe.src
              iframe.src = ''
              setTimeout(() => {
                iframe.src = currentSrc
              }, 100)
            }
          })
        }

        // Handle pause event
        if (eventName === 'pause') {
          // Mark this video as NOT playing (not eligible for floating)
          if (eventWrapper._floatingPlayerState) {
            eventWrapper._floatingPlayerState.isPlaying = false
          }
        }
      }
    })
  }

  /**
   * Initialize
   */
  function init() {
    // Initial setup
    initFloatingPlayers()

    // Watch for new videos
    watchForNewVideos()

    // Setup auto-pause for multiple videos
    setupAutoPlayPause()

    // Setup global drag and resize listeners
    setupDragResizeListeners()

    // Inject CSS styles
    injectStyles()
  }

  /**
   * Inject CSS styles for floating player
   * Uses CSS positioning instead of DOM manipulation to preserve iframe playback
   */
  function injectStyles() {
    // Check if styles already injected
    if (document.getElementById('fchub-stream-floating-styles')) return

    const style = document.createElement('style')
    style.id = 'fchub-stream-floating-styles'
    style.textContent = `
      /* Floating mode: position wrapper fixed in corner */
      .fchub-stream-player-wrapper.fchub-stream-floating-mode {
        position: fixed !important;
        bottom: 20px;
        right: 20px;
        /* width and height set via inline styles for resize functionality */
        max-width: calc(100vw - 40px);
        padding-bottom: 0 !important; /* Override inline padding-bottom from backend */
        z-index: 999999;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
        border-radius: 12px;
        overflow: hidden;
        margin: 0 !important;
        transition: opacity 0.3s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.3s;
      }

      /* Initial state: hidden */
      .fchub-stream-player-wrapper.fchub-stream-floating-mode:not(.fchub-stream-floating-active) {
        opacity: 0;
        pointer-events: none;
      }

      /* Active state: visible */
      .fchub-stream-player-wrapper.fchub-stream-floating-mode.fchub-stream-floating-active {
        opacity: 1;
        pointer-events: auto;
      }

      /* Ensure iframe maintains correct size in floating mode */
      .fchub-stream-player-wrapper.fchub-stream-floating-mode iframe {
        position: absolute !important;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
      }

      /* Close button */
      .fchub-stream-floating-close {
        position: absolute;
        top: 8px;
        right: 8px;
        width: 32px;
        height: 32px;
        background: rgba(0, 0, 0, 0.8);
        border: none;
        border-radius: 50%;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 10;
        transition: all 0.2s ease;
        backdrop-filter: blur(4px);
        padding: 0;
      }

      .fchub-stream-floating-close:hover {
        background: rgba(0, 0, 0, 0.95);
        transform: scale(1.1);
      }

      .fchub-stream-floating-close svg {
        color: white;
      }

      /* Drag handle bar */
      .fchub-stream-floating-drag-handle {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 32px;
        background: linear-gradient(to bottom, rgba(0, 0, 0, 0.7), rgba(0, 0, 0, 0));
        cursor: grab;
        z-index: 9;
        display: flex;
        align-items: center;
        justify-content: center;
      }

      .fchub-stream-floating-drag-handle::before {
        content: '';
        width: 32px;
        height: 4px;
        background: rgba(255, 255, 255, 0.4);
        border-radius: 2px;
        pointer-events: none;
      }

      .fchub-stream-floating-drag-handle:active {
        cursor: grabbing;
      }

      /* Resize handles - corners only, invisible */
      .fchub-stream-floating-resize {
        position: absolute;
        z-index: 11;
        width: 16px;
        height: 16px;
        background: transparent;
      }

      .fchub-stream-floating-resize-top-left {
        top: 0;
        left: 0;
        cursor: nwse-resize;
      }

      .fchub-stream-floating-resize-top-right {
        top: 0;
        right: 0;
        cursor: nesw-resize;
      }

      .fchub-stream-floating-resize-bottom-left {
        bottom: 0;
        left: 0;
        cursor: nesw-resize;
      }

      .fchub-stream-floating-resize-bottom-right {
        bottom: 0;
        right: 0;
        cursor: nwse-resize;
      }

      /* Mobile responsive */
      @media (max-width: 768px) {
        .fchub-stream-player-wrapper.fchub-stream-floating-mode {
          width: calc(100vw - 20px) !important;
          height: calc((100vw - 20px) * 9 / 16) !important; /* Maintain 16:9 */
          bottom: 10px;
          right: 10px;
          border-radius: 8px;
        }

        /* Disable resize handles on mobile - only drag and drop allowed */
        .fchub-stream-floating-resize {
          display: none !important;
        }
      }

      /* Small screens: smaller floating player */
      @media (max-height: 600px) {
        .fchub-stream-player-wrapper.fchub-stream-floating-mode {
          width: 300px !important;
          height: 169px !important; /* 300px * 9/16 */
          bottom: 10px;
        }
      }
    `

    document.head.appendChild(style)
  }

  return {
    init,
    closeFloatingPlayer
  }
}
