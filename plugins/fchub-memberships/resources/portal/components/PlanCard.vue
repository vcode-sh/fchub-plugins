<template>
  <div class="fchub-plan-card">
    <!-- Header: title + badge -->
    <div class="fchub-plan-card__header">
      <div class="fchub-plan-card__header-left">
        <h3 class="fchub-plan-card__title">{{ plan.plan_title }}</h3>
        <p v-if="plan.description" class="fchub-plan-card__desc">{{ plan.description }}</p>
      </div>
      <StatusBadge :status="plan.status" :is-lifetime="plan.is_lifetime" />
    </div>

    <!-- Stats row -->
    <div class="fchub-plan-card__stats">
      <div class="fchub-plan-card__stat">
        <span class="fchub-plan-card__stat-label">{{ plan.is_lifetime ? 'Access' : 'Expires' }}</span>
        <span class="fchub-plan-card__stat-value">{{ plan.is_lifetime ? 'Lifetime' : formatDate(plan.expires_at) }}</span>
      </div>
      <span class="fchub-plan-card__stat-divider"></span>
      <div class="fchub-plan-card__stat">
        <span class="fchub-plan-card__stat-label">{{ plan.is_lifetime ? 'Duration' : 'Remaining' }}</span>
        <span class="fchub-plan-card__stat-value" :class="{ 'fchub-plan-card__stat-value--warn': !plan.is_lifetime && isExpiringSoon }">
          {{ plan.is_lifetime ? 'Forever' : daysLabel }}
        </span>
      </div>
      <template v-if="showProgress">
        <span class="fchub-plan-card__stat-divider"></span>
        <div class="fchub-plan-card__stat">
          <span class="fchub-plan-card__stat-label">Content</span>
          <span class="fchub-plan-card__stat-value">{{ plan.progress.unlocked }}/{{ plan.progress.total }} unlocked</span>
        </div>
      </template>
    </div>

    <!-- Progress bar -->
    <div v-if="showProgress" class="fchub-plan-card__progress">
      <DripProgress :progress="plan.progress" />
    </div>

    <!-- Content library -->
    <ContentLibrary
      v-if="plan.timeline && plan.timeline.length > 0"
      :items="plan.timeline"
      :plan-title="plan.plan_title"
    />
  </div>
</template>

<script setup>
import { computed } from 'vue'
import StatusBadge from './StatusBadge.vue'
import DripProgress from './DripProgress.vue'
import ContentLibrary from './ContentLibrary.vue'
import { useDaysRemaining } from '../composables/useDaysRemaining.js'

const props = defineProps({
  plan: { type: Object, required: true },
})

const { daysLeft, label: daysLabel, isExpiringSoon } = useDaysRemaining(
  computed(() => props.plan.is_lifetime ? null : props.plan.expires_at)
)

const showProgress = computed(() => {
  return props.plan.progress && props.plan.progress.total > 0
})

function formatDate(dateStr) {
  if (!dateStr) return 'No date'
  const d = new Date(dateStr)
  return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
}
</script>

<style scoped>
.fchub-plan-card {
  background: var(--portal-card-bg);
  border: 1px solid var(--portal-border);
  border-radius: var(--portal-radius);
  padding: 24px;
  transition: border-color 0.15s ease;
}

.fchub-plan-card:hover {
  border-color: #d1d5db;
}

/* Header */
.fchub-plan-card__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.fchub-plan-card__header-left {
  min-width: 0;
  flex: 1;
}

.fchub-plan-card__title {
  font-size: 16px;
  font-weight: 600;
  color: var(--portal-text-primary);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.fchub-plan-card__desc {
  margin-top: 4px;
  font-size: 13px;
  color: var(--portal-text-muted);
  line-height: 1.5;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

/* Stats row */
.fchub-plan-card__stats {
  display: flex;
  align-items: center;
  gap: 0;
  margin-top: 16px;
  padding: 12px 0;
  border-bottom: 1px solid var(--portal-border);
}

.fchub-plan-card__stat {
  display: flex;
  flex-direction: column;
  gap: 2px;
  flex: 1;
}

.fchub-plan-card__stat-divider {
  width: 1px;
  height: 28px;
  background: var(--portal-border);
  margin: 0 16px;
  flex-shrink: 0;
}

.fchub-plan-card__stat-label {
  font-size: 11px;
  font-weight: 500;
  color: var(--portal-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.fchub-plan-card__stat-value {
  font-size: 14px;
  font-weight: 600;
  color: var(--portal-text-primary);
}

.fchub-plan-card__stat-value--warn {
  color: var(--portal-badge-expired-text);
}

/* Progress */
.fchub-plan-card__progress {
  margin-top: 16px;
}

/* Content library spacing */
.fchub-plan-card :deep(.fchub-content-library) {
  margin-top: 16px;
}

@media (max-width: 480px) {
  .fchub-plan-card {
    padding: 16px;
  }

  .fchub-plan-card__stats {
    flex-direction: column;
    align-items: stretch;
    gap: 8px;
  }

  .fchub-plan-card__stat-divider {
    width: 100%;
    height: 1px;
    margin: 0;
  }
}
</style>
