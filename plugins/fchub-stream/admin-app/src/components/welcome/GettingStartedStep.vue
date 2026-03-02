<template>
  <div class="px-4 py-2.5 flex items-center gap-3 hover:bg-gray-50 transition-colors">
    <div class="w-5 h-5 rounded-full bg-primary-100 flex items-center justify-center flex-shrink-0">
      <span class="text-xs font-bold text-primary-600">{{ number }}</span>
    </div>
    <div class="flex-1 min-w-0">
      <p class="text-sm font-medium text-gray-900">{{ title }}</p>
      <p class="text-xs text-gray-500">{{ description }}</p>
    </div>
  </div>
</template>

<script setup>
defineProps({
  number: {
    type: Number,
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
})
</script>
