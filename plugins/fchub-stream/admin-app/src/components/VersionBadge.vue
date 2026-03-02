<template>
  <span>v{{ version }}</span>
</template>

<script setup>
import { computed } from 'vue'

const version = computed(() => {
  const v = window.fchubStream?.version || '0.0.1'
  return v
})
</script>
