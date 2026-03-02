<template>
  <div v-if="videoData" class="fchub-stream-media-preview">
    <div class="preview-container">
      <img 
        :src="videoData.thumbnail_url" 
        :alt="`Video ${videoData.video_id}`"
        class="preview-thumbnail"
        @error="handleImageError"
      />
      <div class="preview-overlay">
        <svg class="play-icon" width="48" height="48" viewBox="0 0 24 24" fill="white">
          <path d="M8 5v14l11-7z"/>
        </svg>
      </div>
      <div v-if="videoData.status === 'pending'" class="encoding-badge">
        <svg class="spinner" width="16" height="16" viewBox="0 0 24 24">
          <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" opacity="0.25"/>
          <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" fill="none" stroke-linecap="round"/>
        </svg>
        <span>Encoding...</span>
      </div>
    </div>
    <button 
      class="remove-btn" 
      @click="handleRemove"
      type="button"
      title="Remove video"
    >
      Remove Media
    </button>
  </div>
</template>

<script setup>
import { ref } from 'vue'

const props = defineProps({
  videoData: {
    type: Object,
    required: true
  }
})

const emit = defineEmits(['remove'])

function handleRemove() {
  emit('remove')
}

function handleImageError(e) {
  // Fallback to placeholder if thumbnail fails to load
  e.target.src = 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="200" height="113"%3E%3Crect fill="%23ddd" width="200" height="113"/%3E%3Ctext fill="%23999" x="50%25" y="50%25" dominant-baseline="middle" text-anchor="middle"%3EVideo%3C/text%3E%3C/svg%3E'
}
</script>

<style scoped>
.fchub-stream-media-preview {
  margin: 16px 0;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  overflow: hidden;
  background: #fff;
}

.preview-container {
  position: relative;
  width: 100%;
  padding-bottom: 56.25%; /* 16:9 aspect ratio */
  background: #000;
  overflow: hidden;
}

/* Compact mode for dialogs/comments */
.fchub-stream-preview-compact .fchub-stream-media-preview {
  margin: 8px 0;
  max-width: 100%;
}

.fchub-stream-preview-compact .preview-container {
  padding-bottom: 0 !important; /* Remove aspect ratio padding */
  height: 120px; /* Fixed small height */
  max-height: 120px;
}

.fchub-stream-preview-compact .preview-thumbnail {
  position: relative; /* Not absolute in compact */
  width: 100%;
  height: 120px;
  object-fit: cover;
}

.fchub-stream-preview-compact .preview-overlay {
  height: 120px;
}

.fchub-stream-preview-compact .encoding-badge {
  font-size: 10px;
  padding: 3px 6px;
  top: 6px;
  right: 6px;
  gap: 4px;
}

.fchub-stream-preview-compact .encoding-badge svg {
  width: 12px;
  height: 12px;
}

.fchub-stream-preview-compact .play-icon {
  width: 32px;
  height: 32px;
}

.fchub-stream-preview-compact .remove-btn {
  padding: 8px;
  font-size: 13px;
}

.preview-thumbnail {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.preview-overlay {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(0, 0, 0, 0.3);
  pointer-events: none;
}

.play-icon {
  filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.3));
}

.encoding-badge {
  position: absolute;
  top: 12px;
  right: 12px;
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 6px 12px;
  background: rgba(0, 0, 0, 0.8);
  color: white;
  border-radius: 4px;
  font-size: 12px;
  font-weight: 500;
}

.spinner {
  animation: spin 1s linear infinite;
}

@keyframes spin {
  from {
    transform: rotate(0deg);
  }
  to {
    transform: rotate(360deg);
  }
}

.remove-btn {
  display: block;
  width: 100%;
  padding: 12px;
  background: #fff;
  border: none;
  border-top: 1px solid #e5e7eb;
  color: #ef4444;
  font-size: 14px;
  font-weight: 500;
  cursor: pointer;
  transition: background-color 0.2s;
}

.remove-btn:hover {
  background: #fef2f2;
}
</style>

<style>
/* Global style to hide fchub_stream shortcode in editor */
[contenteditable="true"]:has(+ .fchub-stream-preview-container) {
  /* When preview is shown, we want to visually hide the shortcode */
}

/* Alternative: Use ::after to overlay message over shortcode */
.crepe-editor:has(+ .fchub-stream-preview-container) {
  position: relative;
}
</style>

