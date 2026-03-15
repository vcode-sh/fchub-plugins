<template>
  <div>
    <LoadingState v-if="loading" />

    <template v-else-if="error">
      <div class="fchub-error">
        <div class="fchub-error__icon">
          <svg width="32" height="32" viewBox="0 0 32 32" fill="none">
            <circle cx="16" cy="16" r="12" stroke="currentColor" stroke-width="1.5" />
            <path d="M16 11V18" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
            <circle cx="16" cy="22" r="1" fill="currentColor" />
          </svg>
        </div>
        <p class="fchub-error__message">{{ error }}</p>
        <button class="fchub-error__retry" @click="refresh">Try again</button>
      </div>
    </template>

    <template v-else-if="!hasPlans && !hasHistory">
      <EmptyState />
    </template>

    <template v-else>
      <section v-if="hasPlans" class="fchub-plans-section">
        <div class="fchub-plans-section__list">
          <PlanCard v-for="plan in plans" :key="plan.plan_id" :plan="plan" />
        </div>
      </section>

      <MembershipHistory v-if="hasHistory" :entries="history" />
    </template>
  </div>
</template>

<script setup>
import { useMyAccess } from '../composables/useMyAccess.js'
import LoadingState from './LoadingState.vue'
import EmptyState from './EmptyState.vue'
import PlanCard from './PlanCard.vue'
import MembershipHistory from './MembershipHistory.vue'

const { plans, history, loading, error, refresh, hasPlans, hasHistory } = useMyAccess()
</script>

<style scoped>
.fchub-plans-section__list {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.fchub-error {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  padding: 56px 24px;
}

.fchub-error__icon {
  color: var(--portal-badge-expired-text);
  opacity: 0.6;
  margin-bottom: 12px;
}

.fchub-error__message {
  font-size: 14px;
  color: var(--portal-text-secondary);
  margin-bottom: 16px;
  max-width: 300px;
  line-height: 1.5;
}

.fchub-error__retry {
  padding: 8px 20px;
  font-size: 14px;
  font-weight: 500;
  color: var(--portal-accent-blue);
  border: 1px solid var(--portal-border);
  border-radius: var(--portal-radius-sm);
  transition: background 0.15s ease;
}

.fchub-error__retry:hover {
  background: var(--portal-hover-bg);
}
</style>
