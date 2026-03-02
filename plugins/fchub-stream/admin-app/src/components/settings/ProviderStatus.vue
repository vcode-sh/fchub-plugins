<template>
  <div class="flex items-center gap-2">
    <!-- Status Badge -->
    <span
      class="px-2.5 py-1 text-xs font-medium rounded-md"
      :class="statusClass"
    >
      {{ statusText }}
    </span>

    <!-- Provider Info -->
    <span v-if="showInfo" class="text-sm text-gray-600">
      {{ infoText }}
    </span>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  enabled: {
    type: Boolean,
    default: false,
  },
  configured: {
    type: Boolean,
    default: false,
  },
  provider: {
    type: String,
    required: true, // 'cloudflare' or 'bunny'
  },
  showInfo: {
    type: Boolean,
    default: true,
  },
})

const statusClass = computed(() => {
  if (!props.configured) {
    return 'bg-gray-100 text-gray-700'
  }
  if (props.enabled) {
    return 'bg-green-100 text-green-800'
  }
  return 'bg-yellow-100 text-yellow-800'
})

const statusText = computed(() => {
  if (!props.configured) {
    return 'Not Configured'
  }
  if (props.enabled) {
    return 'Active'
  }
  return 'Disabled'
})

const infoText = computed(() => {
  if (!props.configured) {
    return 'Add credentials to get started'
  }
  if (props.enabled) {
    return 'Videos upload to this provider'
  }
  return 'Configured but not active'
})
</script>
