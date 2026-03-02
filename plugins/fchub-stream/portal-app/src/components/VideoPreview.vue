<template>
  <div class="video-preview" :class="{ encoding: !isReady }">
    <div class="preview-image" @click="handleClick">
      <img v-if="thumbnailUrl" :src="thumbnailUrl" :alt="videoTitle" />
      <div v-else class="no-thumbnail">
        <svg width="64" height="64" viewBox="0 0 24 24" fill="currentColor">
          <path d="M2 6C2 4.89543 2.89543 4 4 4H7L9 2H15C16.1046 2 17 2.89543 17 4V6M2 6V16C2 17.1046 2.89543 18 4 18H16C17.1046 18 18 17.1046 18 16V8C18 6.89543 17.1046 6 16 6H2Z" />
          <circle cx="10" cy="12" r="3" />
        </svg>
      </div>

      <!-- Encoding overlay -->
      <div v-if="!isReady" class="encoding-overlay">
        <div class="spinner"></div>
        <span class="encoding-text">{{ statusText }}</span>
      </div>

      <!-- Play button -->
      <button v-else class="play-btn" @click="handleClick" aria-label="Play video">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="white">
          <polygon points="5 3 19 12 5 21 5 3"></polygon>
        </svg>
      </button>
    </div>

    <div v-if="videoTitle || duration" class="preview-info">
      <p v-if="videoTitle" class="video-title">{{ videoTitle }}</p>
      <p v-if="isReady && duration" class="video-duration">{{ formattedDuration }}</p>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { ENCODING_STATUS } from '../utils/constants'

const props = defineProps({
  videoId: {
    type: String,
    required: true
  },
  thumbnailUrl: {
    type: String,
    default: ''
  },
  videoTitle: {
    type: String,
    default: ''
  },
  encodingStatus: {
    type: String,
    default: ENCODING_STATUS.PENDING
  },
  duration: {
    type: Number,
    default: 0
  },
  playerUrl: {
    type: String,
    default: ''
  }
})

const emit = defineEmits(['play'])

const isReady = computed(() => {
  return props.encodingStatus === ENCODING_STATUS.READY
})

const statusText = computed(() => {
  const statusMap = {
    [ENCODING_STATUS.PENDING]: 'Processing...',
    [ENCODING_STATUS.PROCESSING]: 'Encoding...',
    [ENCODING_STATUS.READY]: 'Ready',
    [ENCODING_STATUS.FAILED]: 'Failed'
  }
  return statusMap[props.encodingStatus] || 'Processing...'
})

const formattedDuration = computed(() => {
  if (!props.duration) return ''

  const minutes = Math.floor(props.duration / 60)
  const seconds = props.duration % 60
  return `${minutes}:${seconds.toString().padStart(2, '0')}`
})

function handleClick() {
  if (isReady.value) {
    emit('play', {
      videoId: props.videoId,
      playerUrl: props.playerUrl
    })
  }
}
</script>

<style scoped>
.video-preview {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  max-width: 100%;
}

.preview-image {
  position: relative;
  width: 100%;
  aspect-ratio: 16 / 9;
  background: #f3f4f6;
  border-radius: 8px;
  overflow: hidden;
  cursor: pointer;
}

.preview-image img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.no-thumbnail {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
  color: #9ca3af;
}

.encoding-overlay {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  background: rgba(0, 0, 0, 0.7);
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

.encoding-text {
  font-size: 0.875rem;
  font-weight: 500;
}

.play-btn {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  display: flex;
  align-items: center;
  justify-content: center;
  width: 64px;
  height: 64px;
  background: rgba(0, 0, 0, 0.7);
  border: none;
  border-radius: 50%;
  cursor: pointer;
  transition: all 0.2s;
  opacity: 0;
}

.preview-image:hover .play-btn {
  opacity: 1;
}

.play-btn:hover {
  background: rgba(0, 0, 0, 0.9);
  transform: translate(-50%, -50%) scale(1.1);
}

.preview-info {
  padding: 0.5rem 0;
}

.video-title {
  margin: 0 0 0.25rem;
  font-weight: 500;
  color: #111827;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.video-duration {
  margin: 0;
  font-size: 0.875rem;
  color: #6b7280;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
