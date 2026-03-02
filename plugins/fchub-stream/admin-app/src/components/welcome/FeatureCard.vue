<template>
  <div class="bg-white border border-gray-200 rounded-lg p-4">
    <div class="flex items-start gap-3">
      <div
        :class="iconBgClass"
        class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0"
      >
        <component :is="icon" class="w-5 h-5" :class="iconTextClass" />
      </div>
      <div>
        <h3 class="text-sm font-semibold text-gray-900 mb-1">{{ title }}</h3>
        <p class="text-xs text-gray-600">{{ description }}</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  icon: {
    type: Object,
    required: true,
  },
  title: {
    type: String,
    required: true,
  },
  description: {
    type: String,
    required: true,
  },
  isSpecial: {
    type: Boolean,
    default: false,
  },
})

const iconBgClass = computed(() => (props.isSpecial ? 'bg-primary-50' : 'bg-primary-50'))
const iconTextClass = computed(() => (props.isSpecial ? 'text-primary-600' : 'text-primary-600'))
</script>
