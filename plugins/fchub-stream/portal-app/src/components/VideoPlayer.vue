<template>
  <div class="video-player-wrapper">
    <div v-if="loading" class="loading-overlay">
      <div class="spinner"></div>
      <span>Loading video...</span>
    </div>

    <iframe
      v-show="!loading && !error"
      :src="playerUrl"
      :title="videoTitle || 'Video Player'"
      frameborder="0"
      allow="accelerometer; autoplay; encrypted-media; gyroscope"
      allowfullscreen
      class="video-player"
      @load="handleLoad"
      @error="handleError"
    />

    <div v-if="error" class="error-overlay">
      <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="10"></circle>
        <line x1="15" y1="9" x2="9" y2="15"></line>
        <line x1="9" y1="9" x2="15" y2="15"></line>
      </svg>
      <p>Failed to load video</p>
      <button @click="retry" class="retry-btn">Retry</button>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'

const props = defineProps({
  videoId: {
    type: String,
    required: true
  },
  provider: {
    type: String,
    required: true
  },
  playerUrl: {
    type: String,
    required: true
  },
  videoTitle: {
    type: String,
    default: ''
  }
})

const loading = ref(true)
const error = ref(false)

function handleLoad() {
  loading.value = false
  error.value = false
}

function handleError() {
  loading.value = false
  error.value = true
}

function retry() {
  loading.value = true
  error.value = false
  // Force iframe reload by changing src
  const iframe = document.querySelector('.video-player')
  if (iframe) {
    const src = iframe.src
    iframe.src = ''
    setTimeout(() => {
      iframe.src = src
    }, 100)
  }
}
</script>

<style scoped>
.video-player-wrapper {
  position: relative;
  width: 100%;
  aspect-ratio: 16 / 9;
  background: #000;
  border-radius: 8px;
  overflow: hidden;
}

.video-player {
  width: 100%;
  height: 100%;
  border: none;
}

.loading-overlay,
.error-overlay {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  background: rgba(0, 0, 0, 0.9);
  color: white;
}

.spinner {
  width: 40px;
  height: 40px;
  border: 4px solid rgba(255, 255, 255, 0.3);
  border-top-color: white;
  border-radius: 50%;
  animation: spin 1s linear infinite;
  margin-bottom: 0.75rem;
}

.loading-overlay span,
.error-overlay p {
  margin: 0.75rem 0;
  font-size: 0.875rem;
}

.retry-btn {
  margin-top: 0.5rem;
  padding: 0.5rem 1rem;
  background: #3b82f6;
  color: white;
  border: none;
  border-radius: 0.375rem;
  font-size: 0.875rem;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.2s;
}

.retry-btn:hover {
  background: #2563eb;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
