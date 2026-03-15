<template>
  <div class="fchub-drip-progress">
    <div class="fchub-drip-progress__header">
      <span class="fchub-drip-progress__label">
        {{ progress.unlocked }} of {{ progress.total }} items unlocked
      </span>
      <span class="fchub-drip-progress__pct">{{ percentage }}%</span>
    </div>
    <div class="fchub-drip-progress__track">
      <div
        class="fchub-drip-progress__fill fchub-progress-fill"
        :style="{ width: fillWidth }"
      ></div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, nextTick } from 'vue'

const props = defineProps({
  progress: { type: Object, required: true },
})

const mounted = ref(false)

onMounted(() => {
  nextTick(() => {
    mounted.value = true
  })
})

const percentage = computed(() => {
  if (props.progress.total === 0) return 0
  return Math.round((props.progress.unlocked / props.progress.total) * 100)
})

const fillWidth = computed(() => {
  if (!mounted.value) return '0%'
  return `${percentage.value}%`
})
</script>

<style scoped>
.fchub-drip-progress__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 6px;
}

.fchub-drip-progress__label {
  font-size: 12px;
  font-weight: 500;
  color: var(--portal-text-muted);
}

.fchub-drip-progress__pct {
  font-size: 12px;
  font-weight: 500;
  color: var(--portal-text-muted);
}

.fchub-drip-progress__track {
  width: 100%;
  height: 4px;
  background: var(--portal-border);
  border-radius: 2px;
  overflow: hidden;
}

.fchub-drip-progress__fill {
  height: 100%;
  background: #16a34a;
  border-radius: 2px;
  min-width: 0;
}
</style>
