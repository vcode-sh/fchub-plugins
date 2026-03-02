<template>
  <div class="space-y-2">
    <div
      v-if="testResult.status === 'success'"
      class="flex items-center gap-2 p-3 bg-green-50 border border-green-200 rounded-md"
    >
      <svg
class="w-5 h-5 text-green-600"
fill="none" stroke="currentColor"
viewBox="0 0 24 24">
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-width="2"
          d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"
        />
      </svg>
      <span class="text-sm font-medium text-green-800">{{ testResult.message }}</span>
    </div>

    <div
      v-else-if="testResult.status === 'error'"
      class="flex items-center gap-2 p-3 bg-red-50 border border-red-200 rounded-md"
    >
      <svg
class="w-5 h-5 text-red-600"
fill="none" stroke="currentColor"
viewBox="0 0 24 24">
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-width="2"
          d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
        />
      </svg>
      <span class="text-sm font-medium text-red-800">{{ testResult.message }}</span>
    </div>

    <div
      v-else-if="testResult.status === 'pending'"
      class="flex items-center gap-2 p-3 bg-blue-50 border border-blue-200 rounded-md"
    >
      <svg class="animate-spin h-5 w-5 text-blue-600" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
        <path
          class="opacity-75"
          fill="currentColor"
          d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
        />
      </svg>
      <span class="text-sm font-medium text-blue-800">Testing connection...</span>
    </div>
  </div>
</template>

<script setup>
defineProps({
  testResult: {
    type: Object,
    required: true,
  },
})
</script>
