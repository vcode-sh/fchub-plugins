<template>
  <div class="fchub-history-entry">
    <div class="fchub-history-entry__info">
      <span class="fchub-history-entry__title">{{ entry.plan_title || 'Unknown Plan' }}</span>
      <span class="fchub-history-entry__date">{{ formatDate(entry.updated_at) }}</span>
    </div>
    <div class="fchub-history-entry__meta">
      <StatusBadge :status="entry.status" />
      <a
        v-if="entry.action"
        class="fchub-history-entry__action"
        :href="entry.action.url"
      >
        {{ entry.action.label }}
      </a>
    </div>
  </div>
</template>

<script setup>
import StatusBadge from './StatusBadge.vue'

defineProps({
  entry: { type: Object, required: true },
})

function formatDate(dateStr) {
  if (!dateStr) return ''
  const d = new Date(dateStr)
  return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
}
</script>

<style scoped>
.fchub-history-entry {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 12px 0;
  border-bottom: 1px solid var(--portal-border-light);
}

.fchub-history-entry:last-child {
  border-bottom: none;
}

.fchub-history-entry__info {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.fchub-history-entry__title {
  font-size: 14px;
  font-weight: 500;
  color: var(--portal-text-primary);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.fchub-history-entry__date {
  font-size: 12px;
  color: var(--portal-text-muted);
}

.fchub-history-entry__meta {
  display: flex;
  align-items: flex-end;
  flex-direction: column;
  gap: 8px;
  flex-shrink: 0;
}

.fchub-history-entry__action {
  color: var(--portal-accent-blue);
  font-size: 12px;
  font-weight: 600;
  text-decoration: none;
}

.fchub-history-entry__action:hover {
  text-decoration: underline;
}

@media (max-width: 480px) {
  .fchub-history-entry {
    align-items: stretch;
    flex-direction: column;
    gap: 8px;
  }

  .fchub-history-entry__title {
    white-space: normal;
  }

  .fchub-history-entry__meta {
    align-items: center;
    flex-direction: row;
    justify-content: space-between;
  }
}
</style>
