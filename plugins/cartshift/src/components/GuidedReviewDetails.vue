<template>
  <details :open="open" @toggle="$emit('toggle', $event.target.open)">
    <summary>
      <span>{{ label }}</span>
      <span class="cartshift-review-detail-count">{{ items.length }}</span>
      <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
    </summary>
    <div v-if="open" class="cartshift-review-group-items">
      <slot />
      <button
        v-if="items.length > limit"
        type="button"
        class="button button-small cartshift-review-show-more"
        :data-test="`show-more-${sectionKey}`"
        @click="$emit('show-more')"
      >
        Show {{ Math.min(20, items.length - limit) }} more
      </button>
    </div>
  </details>
</template>

<script setup>
defineProps({
  sectionKey: { type: String, required: true },
  label: { type: String, required: true },
  items: { type: Array, required: true },
  open: { type: Boolean, required: true },
  limit: { type: Number, required: true },
});
defineEmits(['toggle', 'show-more']);
</script>
