<template>
  <button
    :class="['fchub-stream-upload-btn', 'fcom_action_btn', { disabled: isDisabled, uploading: uploading }]"
    :disabled="isDisabled"
    :title="tooltip"
    @click.stop="handleClick"
  >
    <svg
      class="fcom_icon"
      width="20"
      height="20"
      viewBox="0 0 24 24"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
    >
      <!-- Simple video camera icon - matches FluentCommunity style -->
      <path
        d="M23 7l-7 5 7 5V7z"
        fill="currentColor"
      />
      <path
        d="M14 5H4a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2z"
        fill="currentColor"
      />
    </svg>
    <span v-if="uploading" class="upload-indicator"></span>
  </button>
</template>

<script setup>
import { computed } from 'vue'
import { useVideoUpload } from '../composables/useVideoUpload'
import { usePortalIntegration } from '../composables/usePortalIntegration'

const props = defineProps({
  existingVideoData: {
    type: Object,
    default: null
  }
})

const { uploading, openDialog } = useVideoUpload()
const { isVideoUploadEnabled } = usePortalIntegration()

const isDisabled = computed(() => {
  return !isVideoUploadEnabled() || uploading.value
})

const hasExistingVideo = computed(() => {
  return !!props.existingVideoData
})

const tooltip = computed(() => {
  if (!isVideoUploadEnabled()) return 'Video upload is not enabled'
  if (uploading.value) return 'Uploading video...'
  if (hasExistingVideo.value) return 'Replace Video'
  return 'Upload Video'
})

function handleClick() {
  if (!isDisabled.value) {
    // If we have existing video, this is a replace operation
    if (hasExistingVideo.value) {
      // The new video will replace the old one when uploaded
      // Backend will handle removal of old video if needed
    }
    openDialog()
  }
}
</script>

<style scoped>
.fchub-stream-upload-btn {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0.5rem;
  background: transparent;
  border: none;
  border-radius: 0.375rem;
  cursor: pointer;
  transition: all 0.2s ease;
  color: #6b7280;
}

.fchub-stream-upload-btn:hover:not(.disabled) {
  background: #f3f4f6;
  color: #3b82f6;
}

.fchub-stream-upload-btn.disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.fchub-stream-upload-btn.uploading {
  opacity: 0.7;
}

.fcom_icon {
  width: 20px;
  height: 20px;
}

.upload-indicator {
  position: absolute;
  top: 4px;
  right: 4px;
  width: 8px;
  height: 8px;
  background: #3b82f6;
  border-radius: 50%;
  animation: pulse 1.5s ease-in-out infinite;
}

@keyframes pulse {
  0%, 100% {
    opacity: 1;
  }
  50% {
    opacity: 0.5;
  }
}
</style>
