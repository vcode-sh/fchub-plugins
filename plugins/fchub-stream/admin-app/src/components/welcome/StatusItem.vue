<template>
  <div class="px-4 py-3 flex items-center justify-between hover:bg-gray-50 transition-colors">
    <div class="flex items-center gap-3">
      <component :is="icon" class="w-5 h-5 text-gray-400" />
      <div>
        <p class="text-sm font-medium text-gray-900">{{ title }}</p>
        <p class="text-xs text-gray-500">{{ description }}</p>
      </div>
    </div>
    <div v-if="statusType === 'badge'" class="flex items-center gap-2">
      <span
        class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-primary-100 text-primary-800"
      >
        {{ status }}
      </span>
      <div class="w-2 h-2 rounded-full bg-primary-500 animate-pulse" />
    </div>
    <span v-else-if="statusType === 'text'" class="text-sm font-semibold text-gray-500">
      {{ status }}
    </span>
    <VersionBadge v-else-if="statusType === 'component'" />
  </div>
</template>

<script setup>
import VersionBadge from '../VersionBadge.vue'

defineProps({
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
  status: {
    type: String,
    default: '',
  },
  statusType: {
    type: String,
    required: true,
    validator: value => ['badge', 'text', 'component'].includes(value),
  },
})
</script>
