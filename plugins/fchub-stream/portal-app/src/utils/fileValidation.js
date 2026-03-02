import { logger } from './logger'

/**
 * Get upload settings from window object
 */
function getUploadSettings() {
  const settings = window.fchubStreamSettings || 
                   window.fcom_portal_general?.fchubStreamSettings ||
                   window.fluentComAdmin?.fchubStreamSettings ||
                   {}
  return {
    maxFileSize: (settings.upload?.max_file_size || 500) * 1024 * 1024, // MB to bytes
    allowedFormats: settings.upload?.allowed_formats || ['mp4', 'mov', 'webm', 'avi'],
    allowedMimeTypes: settings.upload?.allowed_mime_types || [
      'video/mp4',
      'video/quicktime',
      'video/webm',
      'video/x-msvideo'
    ]
  }
}

/**
 * Validate video file before upload
 *
 * @param {File} file - File to validate
 * @returns {Object} Validation result { valid: boolean, error?: string }
 */
export function validateFile(file) {
  logger.log('[FCHub Stream] Validation: Starting validation for file:', {
    name: file?.name,
    type: file?.type,
    size: file?.size
  })
  
  if (!file) {
    logger.error('[FCHub Stream] Validation: No file provided')
    return {
      valid: false,
      error: 'No file selected'
    }
  }

  const settings = getUploadSettings()
  logger.log('[FCHub Stream] Validation: Settings:', {
    maxFileSize: settings.maxFileSize,
    allowedFormats: settings.allowedFormats,
    allowedMimeTypes: settings.allowedMimeTypes
  })

  // Check file size
  if (file.size > settings.maxFileSize) {
    const maxSizeMB = Math.round(settings.maxFileSize / 1024 / 1024)
    logger.error('[FCHub Stream] Validation: File too large:', file.size, 'max:', settings.maxFileSize)
    return {
      valid: false,
      error: `File size exceeds maximum allowed size (${maxSizeMB}MB)`
    }
  }

  // Check file extension first (more reliable than MIME type)
  const extension = file.name.split('.').pop().toLowerCase()
  const hasValidExtension = settings.allowedFormats.includes(extension)
  logger.log('[FCHub Stream] Validation: Extension check:', {
    extension,
    hasValidExtension,
    allowedFormats: settings.allowedFormats
  })
  
  // Check MIME type (may be empty or incorrect for some files)
  const hasValidMimeType = !file.type || settings.allowedMimeTypes.includes(file.type)
  logger.log('[FCHub Stream] Validation: MIME type check:', {
    mimeType: file.type,
    hasValidMimeType,
    allowedMimeTypes: settings.allowedMimeTypes
  })
  
  // Accept file if either extension OR MIME type is valid
  // This handles cases where browser reports incorrect MIME type
  if (!hasValidExtension && !hasValidMimeType) {
    logger.error('[FCHub Stream] Validation: File rejected - neither extension nor MIME type valid')
    return {
      valid: false,
      error: `File type not allowed. Allowed formats: ${settings.allowedFormats.join(', ')}`
    }
  }

  logger.log('[FCHub Stream] Validation: File validated successfully')
  return {
    valid: true
  }
}

/**
 * Format file size for display
 *
 * @param {number} bytes - File size in bytes
 * @returns {string} Formatted file size
 */
export function formatFileSize(bytes) {
  if (bytes === 0) return '0 Bytes'

  const k = 1024
  const sizes = ['Bytes', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))

  return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i]
}

/**
 * Format time duration
 *
 * @param {number} seconds - Time in seconds
 * @returns {string} Formatted time
 */
export function formatTime(seconds) {
  if (seconds < 60) return `${Math.round(seconds)}s`
  if (seconds < 3600) return `${Math.round(seconds / 60)}m`
  return `${Math.round(seconds / 3600)}h`
}
