<template>
  <div class="inline-flex items-center">
    <button
      type="button"
      @click="showTooltip = !showTooltip"
      @mouseenter="showTooltip = true"
      @mouseleave="showTooltip = false"
      class="ml-1.5 text-gray-400 hover:text-gray-600 transition-colors focus:outline-none"
    >
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
      </svg>
    </button>

    <!-- Tooltip content -->
    <Transition name="tooltip">
      <div
        v-if="showTooltip"
        class="absolute z-10 px-3 py-2 text-xs bg-gray-900 text-white rounded-md shadow-lg max-w-xs mt-1"
        :class="positionClass"
        style="margin-left: -8rem;"
      >
        <slot />
        <!-- Arrow -->
        <div class="absolute w-2 h-2 bg-gray-900 transform rotate-45 -top-1 left-32"></div>
      </div>
    </Transition>
  </div>
</template>

<script setup>
import { ref } from 'vue'

defineProps({
  position: {
    type: String,
    default: 'bottom',
    validator: (value) => ['top', 'bottom', 'left', 'right'].includes(value),
  },
})

const showTooltip = ref(false)

const positionClass = ''
</script>

<style scoped>
.tooltip-enter-active,
.tooltip-leave-active {
  transition: all 0.2s ease;
}

.tooltip-enter-from,
.tooltip-leave-to {
  opacity: 0;
  transform: translateY(-4px);
}
</style>
