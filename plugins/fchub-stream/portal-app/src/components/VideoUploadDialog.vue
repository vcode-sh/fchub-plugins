<template>
  <Teleport to="body">
    <Transition name="modal">
      <div v-if="isOpen" class="fchub-stream-upload-dialog" @click.self="handleClose" @click.stop>
        <div class="dialog-content" @click.stop>
          <header class="dialog-header">
            <h2>Upload Video</h2>
            <button class="close-btn" @click="handleClose" aria-label="Close">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
              </svg>
            </button>
          </header>

          <div class="dialog-body">
            <!-- Drag & Drop Area -->
            <div
              v-if="!selectedFile"
              class="drop-zone"
              :class="{ 'drag-over': isDragOver }"
              @drop.prevent="handleDrop"
              @dragover.prevent="isDragOver = true"
              @dragleave="isDragOver = false"
              @click.stop="selectFile"
            >
              <svg class="drop-icon" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                <polyline points="17 8 12 3 7 8"></polyline>
                <line x1="12" y1="3" x2="12" y2="15"></line>
              </svg>
              <p class="drop-text">Drag video here</p>
              <p class="drop-hint">or click to browse</p>
              <div class="file-hints">
                <span>Max size: {{ maxFileSizeMB }}MB</span>
                <span>Formats: {{ allowedFormats.join(', ') }}</span>
              </div>
            </div>

            <!-- File Info -->
            <div v-if="selectedFile && !uploading" class="file-info">
              <div class="file-preview">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="currentColor">
                  <path d="M2 6C2 4.89543 2.89543 4 4 4H7L9 2H15C16.1046 2 17 2.89543 17 4V6M2 6V16C2 17.1046 2.89543 18 4 18H16C17.1046 18 18 17.1046 18 16V8C18 6.89543 17.1046 6 16 6H2Z" />
                  <circle cx="10" cy="12" r="3" />
                </svg>
              </div>
              <div class="file-details">
                <p class="file-name">{{ selectedFile.name }}</p>
                <p class="file-size">{{ formatFileSize(selectedFile.size) }}</p>
              </div>
              <button class="remove-btn" @click="removeFile" aria-label="Remove file">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <line x1="18" y1="6" x2="6" y2="18"></line>
                  <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
              </button>
            </div>

            <!-- Upload Progress -->
            <VideoUploadProgress
              v-if="uploading"
              :progress="progress"
              :upload-speed="uploadSpeed"
              :time-remaining="timeRemaining"
            />

            <!-- Status Display -->
            <div v-if="status" class="status-display" :class="status.type">
              <div class="status-icon">
                <svg v-if="status.type === 'success'" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
                <svg v-else-if="status.type === 'error'" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <circle cx="12" cy="12" r="10"></circle>
                  <line x1="15" y1="9" x2="9" y2="15"></line>
                  <line x1="9" y1="9" x2="15" y2="15"></line>
                </svg>
                <svg v-else width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <circle cx="12" cy="12" r="10"></circle>
                  <line x1="12" y1="8" x2="12" y2="12"></line>
                  <line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>
              </div>
              <p class="status-message">{{ status.message }}</p>
              <button v-if="status.type === 'error'" class="btn-retry" @click="retryUpload">
                Retry
              </button>
            </div>
          </div>

          <footer class="dialog-footer">
            <button class="btn-secondary" @click="handleClose">
              {{ uploading ? 'Cancel' : 'Close' }}
            </button>
            <button
              v-if="selectedFile && !uploading && !status"
              class="btn-primary"
              @click="startUpload"
              :disabled="!canUpload"
            >
              Upload Video
            </button>
          </footer>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useVideoUpload } from '../composables/useVideoUpload'
import { usePortalIntegration } from '../composables/usePortalIntegration'
import { formatFileSize } from '../utils/fileValidation'
import { EVENTS } from '../utils/constants'
import VideoUploadProgress from './VideoUploadProgress.vue'
import { logger } from '../utils/logger'

const {
  selectedFile,
  uploading,
  progress,
  uploadSpeed,
  timeRemaining,
  status,
  canUpload,
  selectFile: selectFileFromComposable,
  upload,
  removeFile: removeFileFromComposable,
  cancelUpload
} = useVideoUpload()

const { setMediaInPortalApp, insertShortcodeIntoEditor } = usePortalIntegration()

const isOpen = ref(false)
const isDragOver = ref(false)

const settings = window.fchubStreamSettings || {}
const maxFileSizeMB = computed(() => settings.upload?.max_file_size || 500)
const allowedFormats = computed(() => settings.upload?.allowed_formats || ['mp4', 'mov', 'webm', 'avi'])

function handleOpenDialog() {
  isOpen.value = true
}

function handleCloseDialog() {
  isOpen.value = false
}

function handleClose() {
  if (uploading.value) {
    if (confirm('Upload in progress. Cancel upload?')) {
      cancelUpload()
      isOpen.value = false
    }
  } else {
    isOpen.value = false
  }
}

function selectFile() {
  logger.log('[FCHub Stream] File picker: Creating input element')
  const input = document.createElement('input')
  input.type = 'file'
  // Try multiple accept formats - some browsers need specific MIME types
  // Using both video/* and specific extensions for maximum compatibility
  input.setAttribute('accept', 'video/*,.mp4,.mov,.webm,.avi')
  logger.log('[FCHub Stream] File picker: Accept attribute set to:', input.getAttribute('accept'))
  
  // Ensure input stays in DOM until user interacts
  input.style.position = 'absolute'
  input.style.left = '-9999px'
  input.style.opacity = '0'
  input.style.pointerEvents = 'none'
  document.body.appendChild(input)
  logger.log('[FCHub Stream] File picker: Input added to DOM')
  
  // Use a promise to ensure cleanup happens properly
  const cleanup = () => {
    setTimeout(() => {
      if (input.parentNode) {
        logger.log('[FCHub Stream] File picker: Cleaning up input element')
        document.body.removeChild(input)
      }
    }, 100)
  }
  
  input.onchange = (e) => {
    logger.log('[FCHub Stream] File picker: onChange triggered, files:', e.target.files?.length || 0)
    const file = e.target.files[0]
    if (file) {
      logger.log('[FCHub Stream] File picker: File selected:', {
        name: file.name,
        type: file.type,
        size: file.size,
        lastModified: file.lastModified
      })
      selectFileFromComposable(file)
    } else {
      logger.warn('[FCHub Stream] File picker: No file selected')
    }
    cleanup()
  }
  
  // Handle cancel - cleanup after a delay
  const cancelTimeout = setTimeout(() => {
    if (input.parentNode && (!input.files || input.files.length === 0)) {
      logger.log('[FCHub Stream] File picker: Timeout - no file selected, cleaning up')
      document.body.removeChild(input)
    }
  }, 1000)
  
  // Clear timeout if file is selected
  input.addEventListener('change', () => {
    clearTimeout(cancelTimeout)
  })
  
  // Also handle focusout for cleanup
  input.addEventListener('focusout', () => {
    setTimeout(() => {
      if (input.parentNode && (!input.files || input.files.length === 0)) {
        logger.log('[FCHub Stream] File picker: Focusout - cleaning up')
        document.body.removeChild(input)
      }
    }, 500)
  })
  
  // Trigger file picker
  logger.log('[FCHub Stream] File picker: Triggering click()')
  input.click()
  logger.log('[FCHub Stream] File picker: Click triggered')
}

function handleDrop(event) {
  logger.log('[FCHub Stream] Drag & drop: Drop event triggered')
  isDragOver.value = false
  const files = event.dataTransfer.files
  logger.log('[FCHub Stream] Drag & drop: Files dropped:', files.length)
  
  if (files.length > 0) {
    const file = files[0]
    logger.log('[FCHub Stream] Drag & drop: Processing file:', {
      name: file.name,
      type: file.type,
      size: file.size,
      lastModified: file.lastModified
    })
    selectFileFromComposable(file)
  } else {
    logger.warn('[FCHub Stream] Drag & drop: No files in dataTransfer')
  }
}

async function startUpload() {
  if (!selectedFile.value) return

  try {
    const result = await upload(selectedFile.value)

    logger.log('[FCHub Stream] Upload successful, result:', result)

    // Emit event with video data - UI will show preview
    if (result.video_id) {
      window.dispatchEvent(new CustomEvent('fchub-stream-video-added', {
        detail: {
          video_id: result.video_id,
          provider: result.provider,
          thumbnail_url: result.thumbnail_url,
          status: result.status,
          customer_subdomain: result.customer_subdomain || '',
          shortcode: `[fchub_stream:${result.video_id}]`
        }
      }))
      logger.log('[FCHub Stream] Video added event dispatched')
    }

    // Auto-close after delay
    setTimeout(() => {
      isOpen.value = false
      removeFile()
    }, 1500)
  } catch (error) {
    // Error handled by composable
  }
}

function retryUpload() {
  if (selectedFile.value) {
    startUpload()
  }
}

function removeFile() {
  removeFileFromComposable()
}

// Event listeners
onMounted(() => {
  window.addEventListener(EVENTS.OPEN_UPLOAD_DIALOG, handleOpenDialog)
  window.addEventListener(EVENTS.CLOSE_UPLOAD_DIALOG, handleCloseDialog)
})

onUnmounted(() => {
  window.removeEventListener(EVENTS.OPEN_UPLOAD_DIALOG, handleOpenDialog)
  window.removeEventListener(EVENTS.CLOSE_UPLOAD_DIALOG, handleCloseDialog)
})
</script>

<style scoped>
/* Modal backdrop */
.fchub-stream-upload-dialog {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 10000;
  backdrop-filter: blur(4px);
}

/* Dialog content */
.dialog-content {
  background: var(--el-bg-color, white);
  border-radius: 12px;
  width: 90%;
  max-width: 600px;
  max-height: 90vh;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
}

/* Header */
.dialog-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1.5rem;
  border-bottom: 1px solid var(--el-border-color, #e5e7eb);
}

.dialog-header h2 {
  margin: 0;
  font-size: 1.25rem;
  font-weight: 600;
  color: var(--el-text-color-primary, #111827);
}

.close-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 2rem;
  height: 2rem;
  background: transparent;
  border: none;
  border-radius: 0.375rem;
  cursor: pointer;
  color: var(--el-text-color-secondary, #6b7280);
  transition: all 0.2s;
}

.close-btn:hover {
  background: var(--el-fill-color-light, #f3f4f6);
  color: var(--el-text-color-primary, #111827);
}

/* Body */
.dialog-body {
  flex: 1;
  overflow-y: auto;
  padding: 1.5rem;
}

/* Drag & drop area */
.drop-zone {
  border: 2px dashed var(--el-border-color-light, #cbd5e1);
  border-radius: 8px;
  padding: 3rem 2rem;
  text-align: center;
  transition: all 0.2s;
  cursor: pointer;
}

.drop-zone:hover {
  border-color: var(--el-border-color-dark, #94a3b8);
  background: var(--el-fill-color-lighter, #f8fafc);
}

.drop-zone.drag-over {
  border-color: #3b82f6;
  background: #eff6ff;
}

.drop-icon {
  margin: 0 auto 1rem;
  color: var(--el-text-color-secondary, #94a3b8);
}

.drop-text {
  margin: 0 0 0.5rem;
  font-size: 1.125rem;
  font-weight: 500;
  color: var(--el-text-color-regular, #374151);
}

.drop-hint {
  margin: 0 0 1.5rem;
  font-size: 0.875rem;
  color: var(--el-text-color-secondary, #6b7280);
}

.file-hints {
  display: flex;
  justify-content: center;
  gap: 1rem;
  font-size: 0.75rem;
  color: var(--el-text-color-placeholder, #9ca3af);
}

/* File info */
.file-info {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 1rem;
  background: var(--el-fill-color-lighter, #f9fafb);
  border-radius: 8px;
}

.file-preview {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 64px;
  height: 64px;
  background: var(--el-fill-color, #e5e7eb);
  border-radius: 8px;
  color: var(--el-text-color-secondary, #6b7280);
  flex-shrink: 0;
}

.file-details {
  flex: 1;
  min-width: 0;
}

.file-name {
  margin: 0 0 0.25rem;
  font-weight: 500;
  color: var(--el-text-color-primary, #111827);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.file-size {
  margin: 0;
  font-size: 0.875rem;
  color: var(--el-text-color-secondary, #6b7280);
}

.remove-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 2rem;
  height: 2rem;
  background: transparent;
  border: none;
  border-radius: 0.375rem;
  cursor: pointer;
  color: var(--el-text-color-secondary, #6b7280);
  transition: all 0.2s;
  flex-shrink: 0;
}

.remove-btn:hover {
  background: var(--el-fill-color, #e5e7eb);
  color: #ef4444;
}

/* Status display */
.status-display {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.75rem;
  padding: 1.5rem;
  border-radius: 8px;
  margin-top: 1rem;
}

.status-display.success {
  background: #f0fdf4;
  color: #166534;
}

.status-display.error {
  background: #fef2f2;
  color: #991b1b;
}

.status-display.info {
  background: #eff6ff;
  color: #1e40af;
}

.status-icon svg {
  width: 48px;
  height: 48px;
}

.status-message {
  margin: 0;
  font-size: 1rem;
  font-weight: 500;
  text-align: center;
}

.btn-retry {
  margin-top: 0.5rem;
  padding: 0.5rem 1rem;
  background: #ef4444;
  color: white;
  border: none;
  border-radius: 0.375rem;
  font-size: 0.875rem;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.2s;
}

.btn-retry:hover {
  background: #dc2626;
}

/* Footer */
.dialog-footer {
  display: flex;
  justify-content: flex-end;
  gap: 0.75rem;
  padding: 1.5rem;
  border-top: 1px solid var(--el-border-color, #e5e7eb);
}

.btn-secondary,
.btn-primary {
  padding: 0.5rem 1rem;
  border: none;
  border-radius: 0.375rem;
  font-size: 0.875rem;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.2s;
}

.btn-secondary {
  background: transparent;
  color: var(--el-text-color-regular, #374151);
}

.btn-secondary:hover {
  background: var(--el-fill-color-light, #f3f4f6);
}

.btn-primary {
  background: var(--el-color-primary, #3b82f6);
  color: white;
}

.btn-primary:hover:not(:disabled) {
  background: var(--el-color-primary-dark-2, #2563eb);
}

.btn-primary:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

/* Animations */
.modal-enter-active,
.modal-leave-active {
  transition: opacity 0.2s;
}

.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}

.modal-enter-active .dialog-content,
.modal-leave-active .dialog-content {
  transition: transform 0.2s;
}

.modal-enter-from .dialog-content,
.modal-leave-to .dialog-content {
  transform: scale(0.95);
}
</style>
