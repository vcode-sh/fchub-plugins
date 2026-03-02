<template>
  <div class="upload-progress">
    <div class="progress-bar">
      <div class="progress-fill" :style="{ width: progress + '%' }"></div>
    </div>
    <div class="progress-info">
      <span class="progress-percentage">{{ progress }}%</span>
      <span v-if="uploadSpeed > 0" class="progress-speed">{{ uploadSpeed }} MB/s</span>
      <span v-if="timeRemaining" class="progress-time">{{ timeRemaining }} remaining</span>
    </div>
  </div>
</template>

<script setup>
import { defineProps } from 'vue'

defineProps({
  progress: {
    type: Number,
    required: true,
    default: 0
  },
  uploadSpeed: {
    type: Number,
    default: 0
  },
  timeRemaining: {
    type: String,
    default: null
  }
})
</script>

<style scoped>
.upload-progress {
  margin-top: 1rem;
}

.progress-bar {
  height: 8px;
  background: #e5e7eb;
  border-radius: 4px;
  overflow: hidden;
}

.progress-fill {
  height: 100%;
  background: linear-gradient(90deg, #3b82f6, #60a5fa);
  transition: width 0.3s ease;
  border-radius: 4px;
}

.progress-info {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: 0.5rem;
  font-size: 0.875rem;
  color: #6b7280;
}

.progress-percentage {
  font-weight: 600;
  color: #3b82f6;
}

.progress-speed,
.progress-time {
  font-size: 0.75rem;
}
</style>
