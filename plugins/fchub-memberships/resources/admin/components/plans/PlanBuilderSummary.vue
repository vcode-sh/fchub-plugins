<template>
  <aside class="plan-summary" aria-label="Plan at a glance">
    <button
      type="button"
      class="plan-summary-mobile-toggle"
      :aria-expanded="String(mobileOpen)"
      :aria-controls="`${idPrefix}-mobile-content`"
      @click="$emit('toggle-mobile')"
    >
      <span>
        <small>Plan at a glance</small>
        <strong>{{ summary.title }}</strong>
      </span>
      <el-icon class="summary-toggle-icon" :class="{ 'is-open': mobileOpen }" aria-hidden="true">
        <ArrowDown />
      </el-icon>
    </button>

    <div class="plan-summary-desktop-content">
      <SummaryContent :summary="summary" :heading-id="`${idPrefix}-heading`" />
    </div>

    <div
      v-show="mobileOpen"
      :id="`${idPrefix}-mobile-content`"
      class="plan-summary-mobile-content"
    >
      <SummaryContent :summary="summary" :heading-id="`${idPrefix}-mobile-heading`" />
    </div>
  </aside>
</template>

<script setup>
import { ArrowDown } from '@element-plus/icons-vue'
import SummaryContent from './PlanBuilderSummaryContent.vue'

defineProps({
  summary: {
    type: Object,
    required: true,
  },
  mobileOpen: {
    type: Boolean,
    default: false,
  },
  idPrefix: {
    type: String,
    default: 'plan-summary',
  },
})

defineEmits(['toggle-mobile'])
</script>

<style scoped>
.plan-summary {
  overflow: hidden;
  border: 1px solid var(--fchub-border-color);
  border-radius: 12px;
  background: var(--fchub-card-bg);
  box-shadow: 0 8px 24px rgb(38 55 95 / 5%);
}

.plan-summary-mobile-toggle,
.plan-summary-mobile-content {
  display: none;
}

@media (max-width: 782px) {
  .plan-summary-desktop-content {
    display: none;
  }

  .plan-summary-mobile-toggle {
    width: 100%;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    padding: 14px 16px;
    border: 0;
    color: var(--fchub-text-primary);
    background: var(--fchub-card-bg);
    text-align: left;
    cursor: pointer;
  }

  .plan-summary-mobile-toggle span:first-child {
    min-width: 0;
    display: grid;
    gap: 2px;
  }

  .plan-summary-mobile-toggle small {
    color: var(--fchub-text-secondary);
    font-size: 11px;
  }

  .plan-summary-mobile-toggle strong {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 13px;
  }

  .plan-summary-mobile-toggle:focus-visible {
    outline: 3px solid color-mix(in srgb, var(--el-color-primary) 25%, transparent);
    outline-offset: -3px;
  }

  .summary-toggle-icon {
    flex: 0 0 auto;
    transition: transform 160ms ease;
  }

  .summary-toggle-icon.is-open {
    transform: rotate(180deg);
  }

  .plan-summary-mobile-content {
    display: block;
    border-top: 1px solid var(--fchub-border-color);
  }
}
</style>
