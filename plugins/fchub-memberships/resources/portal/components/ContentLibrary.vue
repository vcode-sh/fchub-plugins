<template>
  <div class="fchub-content-library" v-if="items.length > 0">
    <button class="fchub-content-library__header" @click="expanded = !expanded">
      <span class="fchub-content-library__count">Content ({{ items.length }} {{ items.length === 1 ? 'item' : 'items' }})</span>
      <svg
        class="fchub-chevron"
        :class="{ 'fchub-chevron--open': expanded }"
        width="16"
        height="16"
        viewBox="0 0 16 16"
        fill="none"
      >
        <path
          d="M4 6L8 10L12 6"
          stroke="currentColor"
          stroke-width="1.5"
          stroke-linecap="round"
          stroke-linejoin="round"
        />
      </svg>
    </button>

    <Transition name="fchub-collapse">
      <div v-if="expanded" class="fchub-content-library__body">
        <div class="fchub-content-library__list">
          <ContentItem v-for="item in visibleItems" :key="item.rule_id" :item="item" />
        </div>
        <button
          v-if="items.length > collapsedCount"
          class="fchub-content-library__toggle"
          @click.stop="showAll = !showAll"
        >
          {{ showAll ? 'Show less' : `Show all ${items.length} items` }}
        </button>
      </div>
    </Transition>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import ContentItem from './ContentItem.vue'

const props = defineProps({
  items: { type: Array, required: true },
  planTitle: { type: String, default: '' },
})

const collapsedCount = 5
const expanded = ref(true)
const showAll = ref(false)

const visibleItems = computed(() => {
  if (showAll.value || props.items.length <= collapsedCount) return props.items
  return props.items.slice(0, collapsedCount)
})
</script>

<style scoped>
.fchub-content-library {
  border-top: 1px solid var(--portal-border);
  padding-top: 16px;
}

.fchub-content-library__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  padding: 0;
  margin-bottom: 8px;
  color: var(--portal-text-secondary);
  transition: color 0.15s ease;
  cursor: pointer;
}

.fchub-content-library__header:hover {
  color: var(--portal-text-primary);
}

.fchub-content-library__count {
  font-size: 13px;
  font-weight: 600;
}

.fchub-content-library__body {
  overflow: hidden;
}

.fchub-content-library__list {
  display: flex;
  flex-direction: column;
}

.fchub-content-library__toggle {
  display: inline-block;
  margin-top: 4px;
  padding: 4px 0;
  font-size: 13px;
  font-weight: 500;
  color: var(--portal-accent-blue);
  transition: opacity 0.15s ease;
}

.fchub-content-library__toggle:hover {
  opacity: 0.8;
}
</style>
