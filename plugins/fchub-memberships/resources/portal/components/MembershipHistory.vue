<template>
  <div class="fchub-history">
    <button class="fchub-history__toggle" @click="expanded = !expanded">
      <span class="fchub-history__heading">Past Memberships</span>
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
      <div v-if="expanded" class="fchub-history__body">
        <HistoryEntry v-for="entry in entries" :key="entryKey(entry)" :entry="entry" />
      </div>
    </Transition>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import HistoryEntry from './HistoryEntry.vue'

defineProps({
  entries: { type: Array, required: true },
})

const expanded = ref(false)

function entryKey(entry) {
  return `${entry.id || ''}-${entry.plan_id || ''}-${entry.resource_id || ''}-${entry.updated_at || ''}`
}
</script>

<style scoped>
.fchub-history {
  margin-top: 24px;
  background: var(--portal-card-bg);
  border: 1px solid var(--portal-border);
  border-radius: var(--portal-radius);
  overflow: hidden;
}

.fchub-history__toggle {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  padding: 16px 24px;
  color: var(--portal-text-secondary);
  transition: color 0.15s ease, background 0.15s ease;
  cursor: pointer;
}

.fchub-history__toggle:hover {
  color: var(--portal-text-primary);
  background: var(--portal-hover-bg);
}

.fchub-history__heading {
  font-size: 14px;
  font-weight: 600;
  color: inherit;
}

.fchub-history__body {
  padding: 0 24px 16px;
  overflow: hidden;
}
</style>
