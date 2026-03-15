<template>
  <span class="fchub-status-badge" :class="badgeClass">{{ badgeLabel }}</span>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  status: { type: String, required: true },
  isLifetime: { type: Boolean, default: false },
})

const badgeClass = computed(() => {
  if (props.status === 'active' && props.isLifetime) return 'fchub-status-badge--lifetime'
  return `fchub-status-badge--${props.status}`
})

const badgeLabel = computed(() => {
  if (props.status === 'active' && props.isLifetime) return 'Lifetime'
  const labels = {
    active: 'Active',
    paused: 'Paused',
    expired: 'Expired',
    revoked: 'Revoked',
  }
  return labels[props.status] || props.status
})
</script>

<style scoped>
.fchub-status-badge {
  display: inline-block;
  padding: 4px 8px;
  font-size: 12px;
  font-weight: 500;
  line-height: 1;
  border-radius: var(--portal-radius-sm);
  white-space: nowrap;
}

.fchub-status-badge--active {
  background: var(--portal-badge-active-bg);
  color: var(--portal-badge-active-text);
}

.fchub-status-badge--lifetime {
  background: var(--portal-badge-lifetime-bg);
  color: var(--portal-badge-lifetime-text);
}

.fchub-status-badge--paused {
  background: var(--portal-badge-paused-bg);
  color: var(--portal-badge-paused-text);
}

.fchub-status-badge--expired {
  background: var(--portal-badge-expired-bg);
  color: var(--portal-badge-expired-text);
}

.fchub-status-badge--revoked {
  background: var(--portal-badge-revoked-bg);
  color: var(--portal-badge-revoked-text);
}
</style>
