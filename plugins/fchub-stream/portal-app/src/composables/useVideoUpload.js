import { ref, computed } from 'vue'
import { uploadVideo } from '../services/uploadService'
import { validateFile, formatTime } from '../utils/fileValidation'
import { EVENTS, STATUS_TYPE } from '../utils/constants'
import { logger } from '../utils/logger'

// Shared state across all instances
const selectedFile = ref(null)
const uploading = ref(false)
const progress = ref(0)
const uploadSpeed = ref(0)
const timeRemaining = ref(null)
const status = ref(null)
const abortController = ref(null)
const uploadResult = ref(null)
const retryCount = ref(0) // Track retry attempts

/**
 * Composable for video upload functionality
 */
export function useVideoUpload() {
  const isUploading = computed(() => uploading.value)
  const canUpload = computed(() => selectedFile.value && !uploading.value)

  /**
   * Select a file for upload
   */
  function selectFile(file) {
    logger.log('[FCHub Stream] selectFile: Called with file:', {
      name: file?.name,
      type: file?.type,
      size: file?.size
    })
    logger.log('[FCHub Stream] Validating file:', file.name, file.type, file.size)
    const validation = validateFile(file)
    if (!validation.valid) {
      logger.error('[FCHub Stream] File validation failed:', validation.error)
      status.value = { type: STATUS_TYPE.ERROR, message: validation.error }
      
      return false
    }

    logger.log('[FCHub Stream] File validated successfully')
    selectedFile.value = file
    status.value = null
    uploadResult.value = null
    return true
  }

  /**
   * Upload the selected video file
   */
  async function upload(file = null) {
    const fileToUpload = file || selectedFile.value
    if (!fileToUpload) {
      throw new Error('No file selected')
    }

    // Increment retry count if previous upload failed.
    if (status.value?.type === STATUS_TYPE.ERROR) {
      retryCount.value++
    } else {
      retryCount.value = 0
    }

    uploading.value = true
    progress.value = 0
    status.value = null
    uploadResult.value = null
    abortController.value = new AbortController()

    const startTime = Date.now()
    let lastLoaded = 0
    let lastTime = startTime

    try {
      const result = await uploadVideo(fileToUpload, {
        onProgress: (loaded, total) => {
          progress.value = Math.round((loaded / total) * 100)

          // Calculate upload speed
          const currentTime = Date.now()
          const timeDiff = (currentTime - lastTime) / 1000 // seconds
          const loadedDiff = loaded - lastLoaded

          if (timeDiff > 0.5) { // Update every 500ms
            uploadSpeed.value = Math.round((loadedDiff / timeDiff) / 1024 / 1024 * 10) / 10 // MB/s
            lastLoaded = loaded
            lastTime = currentTime

            // Estimate time remaining
            if (uploadSpeed.value > 0) {
              const remainingBytes = total - loaded
              const remainingSeconds = (remainingBytes / 1024 / 1024) / uploadSpeed.value
              timeRemaining.value = formatTime(remainingSeconds)
            }
          }
        },
        signal: abortController.value.signal
      })

      uploadResult.value = result
      status.value = {
        type: STATUS_TYPE.SUCCESS,
        message: 'Video uploaded successfully!',
        data: result
      }

      // Emit upload complete event
      window.dispatchEvent(new CustomEvent(EVENTS.UPLOAD_COMPLETE, {
        detail: result
      }))

      return result
    } catch (error) {
      if (error.name === 'AbortError' || error.message === 'Upload cancelled') {
        status.value = {
          type: STATUS_TYPE.INFO,
          message: 'Upload cancelled'
        }

      } else {
        status.value = {
          type: STATUS_TYPE.ERROR,
          message: error.message || 'Upload failed'
        }

        // Emit upload error event
        window.dispatchEvent(new CustomEvent(EVENTS.UPLOAD_ERROR, {
          detail: { error: errorMessage }
        }))
      }
      throw error
    } finally {
      uploading.value = false
      progress.value = 0
      uploadSpeed.value = 0
      timeRemaining.value = null
    }
  }

  /**
   * Cancel the ongoing upload
   */
  function cancelUpload() {
    if (abortController.value) {
      abortController.value.abort()
      abortController.value = null
    }
  }

  /**
   * Remove the selected file
   */
  function removeFile() {
    selectedFile.value = null
    progress.value = 0
    status.value = null
    uploadResult.value = null
  }

  /**
   * Open the upload dialog
   */
  function openDialog() {
    window.dispatchEvent(new CustomEvent(EVENTS.OPEN_UPLOAD_DIALOG))
  }

  /**
   * Close the upload dialog
   */
  function closeDialog() {
    window.dispatchEvent(new CustomEvent(EVENTS.CLOSE_UPLOAD_DIALOG))
  }

  /**
   * Reset all state
   */
  function reset() {
    selectedFile.value = null
    uploading.value = false
    progress.value = 0
    uploadSpeed.value = 0
    timeRemaining.value = null
    status.value = null
    abortController.value = null
    uploadResult.value = null
    retryCount.value = 0
  }

  return {
    // State
    selectedFile,
    uploading: isUploading,
    progress,
    uploadSpeed,
    timeRemaining,
    status,
    canUpload,
    uploadResult,

    // Methods
    selectFile,
    upload,
    cancelUpload,
    removeFile,
    openDialog,
    closeDialog,
    reset
  }
}
