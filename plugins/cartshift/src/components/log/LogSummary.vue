<template>
  <div class="cartshift-log-summary">
    <div class="cartshift-log-summary-cards">
      <button
        type="button"
        :class="['cartshift-log-stat-card', { 'cartshift-log-stat-card-active': activeFilter === '' }]"
        :aria-pressed="activeFilter === ''"
        @click="$emit('filter', '')"
      >
        <span class="cartshift-log-stat-icon cartshift-log-stat-icon-total" aria-hidden="true">&#9632;</span>
        <span class="cartshift-log-stat-count">{{ count('total') }}</span>
        <span class="cartshift-log-stat-label">Total</span>
      </button>

      <button
        type="button"
        :class="['cartshift-log-stat-card', { 'cartshift-log-stat-card-active': activeFilter === 'success' }]"
        :aria-pressed="activeFilter === 'success'"
        @click="$emit('filter', 'success')"
      >
        <span class="cartshift-log-stat-icon cartshift-log-stat-icon-success" aria-hidden="true">&#10003;</span>
        <span class="cartshift-log-stat-count">{{ count('success') }}</span>
        <span class="cartshift-log-stat-label">Processed</span>
      </button>

      <button
        type="button"
        :class="['cartshift-log-stat-card', { 'cartshift-log-stat-card-active': activeFilter === 'skipped' }]"
        :aria-pressed="activeFilter === 'skipped'"
        @click="$emit('filter', 'skipped')"
      >
        <span class="cartshift-log-stat-icon cartshift-log-stat-icon-skipped" aria-hidden="true">&#8677;</span>
        <span class="cartshift-log-stat-count">{{ count('skipped') }}</span>
        <span class="cartshift-log-stat-label">Skipped</span>
      </button>

      <!--
        Migrated, but not intact. A warning row is neither a skip (nothing was
        written) nor an error (nothing survived) — it is a record that came
        across with something missing, and it needs its own card or it stays
        invisible.
      -->
      <button
        type="button"
        :class="['cartshift-log-stat-card', { 'cartshift-log-stat-card-active': activeFilter === 'warning' }]"
        :aria-pressed="activeFilter === 'warning'"
        @click="$emit('filter', 'warning')"
      >
        <span class="cartshift-log-stat-icon cartshift-log-stat-icon-warning" aria-hidden="true">&#9888;</span>
        <span class="cartshift-log-stat-count">{{ count('warning') }}</span>
        <span class="cartshift-log-stat-label">Warnings</span>
      </button>

      <button
        type="button"
        :class="['cartshift-log-stat-card', { 'cartshift-log-stat-card-active': activeFilter === 'error' }]"
        :aria-pressed="activeFilter === 'error'"
        @click="$emit('filter', 'error')"
      >
        <span class="cartshift-log-stat-icon cartshift-log-stat-icon-error" aria-hidden="true">&#10007;</span>
        <span class="cartshift-log-stat-count">{{ count('error') }}</span>
        <span class="cartshift-log-stat-label">Errors</span>
      </button>

      <button
        v-if="stats['dry-run'] > 0"
        type="button"
        :class="['cartshift-log-stat-card', { 'cartshift-log-stat-card-active': activeFilter === 'dry-run' }]"
        :aria-pressed="activeFilter === 'dry-run'"
        @click="$emit('filter', 'dry-run')"
      >
        <span class="cartshift-log-stat-icon cartshift-log-stat-icon-dryrun" aria-hidden="true">&#9675;</span>
        <span class="cartshift-log-stat-count">{{ count('dry-run') }}</span>
        <span class="cartshift-log-stat-label">Dry-run</span>
      </button>
    </div>

    <div v-if="stats.total > 0" class="cartshift-log-summary-bar" role="img" :aria-label="barLabel">
      <div
        v-if="stats.success > 0"
        class="cartshift-log-summary-bar-segment cartshift-log-summary-bar-success"
        :style="{ width: getPercent('success') + '%' }"
      ></div>
      <div
        v-if="stats.skipped > 0"
        class="cartshift-log-summary-bar-segment cartshift-log-summary-bar-skipped"
        :style="{ width: getPercent('skipped') + '%' }"
      ></div>
      <div
        v-if="stats.warning > 0"
        class="cartshift-log-summary-bar-segment cartshift-log-summary-bar-warning"
        :style="{ width: getPercent('warning') + '%' }"
      ></div>
      <div
        v-if="stats.error > 0"
        class="cartshift-log-summary-bar-segment cartshift-log-summary-bar-error"
        :style="{ width: getPercent('error') + '%' }"
      ></div>
      <div
        v-if="stats['dry-run'] > 0"
        class="cartshift-log-summary-bar-segment cartshift-log-summary-bar-dryrun"
        :style="{ width: getPercent('dry-run') + '%' }"
      ></div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  stats: {
    type: Object,
    required: true,
  },
  activeFilter: {
    type: String,
    default: '',
  },
});

defineEmits(['filter']);

const barLabel = computed(() => {
  const parts = [];
  if (props.stats.success) parts.push(`${props.stats.success} migrated`);
  if (props.stats.skipped) parts.push(`${props.stats.skipped} skipped`);
  if (props.stats.warning) parts.push(`${props.stats.warning} migrated with warnings`);
  if (props.stats.error) parts.push(`${props.stats.error} errored`);
  if (props.stats['dry-run']) parts.push(`${props.stats['dry-run']} dry run`);
  return parts.length ? 'Log breakdown: ' + parts.join(', ') + '.' : 'No log entries.';
});

function count(status) {
  const value = Number(props.stats?.[status]);
  return Number.isFinite(value) ? value : 0;
}

function getPercent(status) {
  const total = count('total');
  if (!total) return 0;
  return Math.round((count(status) / total) * 100);
}
</script>
