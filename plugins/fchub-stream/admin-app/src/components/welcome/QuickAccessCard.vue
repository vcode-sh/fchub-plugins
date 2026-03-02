<template>
  <div
    class="bg-white border border-gray-200 rounded-lg p-4 hover:shadow-md hover:border-primary-300 transition-all group"
  >
    <div class="flex items-start gap-3">
      <div
        class="w-10 h-10 rounded-lg bg-primary-50 flex items-center justify-center group-hover:bg-primary-100 transition-colors flex-shrink-0 mt-0.5"
      >
        <component :is="icon" class="w-5 h-5 text-primary-600" />
      </div>
      <div class="flex-1 min-w-0">
        <h3 class="text-sm font-semibold text-gray-900 mb-1">{{ title }}</h3>
        <p class="text-xs text-gray-500">{{ description }}</p>
      </div>
    </div>
  </div>
</template>

<script setup>
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
})
</script>
