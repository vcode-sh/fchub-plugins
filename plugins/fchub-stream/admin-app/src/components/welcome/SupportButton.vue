<template>
  <component
    :is="href ? 'a' : 'button'"
    :href="href"
    :target="external ? '_blank' : undefined"
    :rel="external ? 'noopener noreferrer' : undefined"
    class="inline-flex items-center gap-1 px-3 py-1.5 bg-white border border-primary-300 rounded-md text-xs font-medium text-primary-700 hover:bg-primary-50 transition-colors"
  >
    <component :is="icon" class="w-3.5 h-3.5" />
    {{ label }}
  </component>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  icon: {
    type: Object,
    required: true,
  },
  label: {
    type: String,
    required: true,
  },
  href: {
    type: String,
    default: null,
  },
  external: {
    type: Boolean,
    default: false,
  },
})

// Auto-detect external links if external prop not provided
const external = computed(() => {
  if (props.external !== undefined) {
    return props.external
  }
  // Only mark as external if href starts with http and is not the same domain
  if (props.href?.startsWith('http')) {
    try {
      const hrefUrl = new URL(props.href)
      const currentUrl = window.location
      return hrefUrl.hostname !== currentUrl.hostname
    } catch {
      return false
    }
  }
  return false
})
</script>
