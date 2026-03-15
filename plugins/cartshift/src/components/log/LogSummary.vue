<template>
  <div class="cartshift-log-summary">
    <div class="cartshift-log-summary-cards">
      <button
        :class="['cartshift-log-stat-card', { 'cartshift-log-stat-card-active': activeFilter === '' }]"
        @click="$emit('filter', '')"
      >
        <span class="cartshift-log-stat-icon cartshift-log-stat-icon-total">&#9632;</span>
        <span class="cartshift-log-stat-count">{{ stats.total }}</span>
        <span class="cartshift-log-stat-label">Total</span>
      </button>

      <button
        :class="['cartshift-log-stat-card', { 'cartshift-log-stat-card-active': activeFilter === 'success' }]"
        @click="$emit('filter', 'success')"
      >
        <span class="cartshift-log-stat-icon cartshift-log-stat-icon-success">&#10003;</span>
        <span class="cartshift-log-stat-count">{{ stats.success }}</span>
        <span class="cartshift-log-stat-label">Processed</span>
      </button>

      <button
        :class="['cartshift-log-stat-card', { 'cartshift-log-stat-card-active': activeFilter === 'skipped' }]"
        @click="$emit('filter', 'skipped')"
      >
        <span class="cartshift-log-stat-icon cartshift-log-stat-icon-skipped">&#9888;</span>
        <span class="cartshift-log-stat-count">{{ stats.skipped }}</span>
        <span class="cartshift-log-stat-label">Skipped</span>
      </button>

      <button
        :class="['cartshift-log-stat-card', { 'cartshift-log-stat-card-active': activeFilter === 'error' }]"
        @click="$emit('filter', 'error')"
      >
        <span class="cartshift-log-stat-icon cartshift-log-stat-icon-error">&#10007;</span>
        <span class="cartshift-log-stat-count">{{ stats.error }}</span>
        <span class="cartshift-log-stat-label">Errors</span>
      </button>

      <button
        v-if="stats['dry-run'] > 0"
        :class="['cartshift-log-stat-card', { 'cartshift-log-stat-card-active': activeFilter === 'dry-run' }]"
        @click="$emit('filter', 'dry-run')"
      >
        <span class="cartshift-log-stat-icon cartshift-log-stat-icon-dryrun">&#9675;</span>
        <span class="cartshift-log-stat-count">{{ stats['dry-run'] }}</span>
        <span class="cartshift-log-stat-label">Dry-run</span>
      </button>
    </div>

    <div v-if="stats.total > 0" class="cartshift-log-summary-bar">
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

function getPercent(status) {
  if (!props.stats.total) return 0;
  return Math.round((props.stats[status] / props.stats.total) * 100);
}
</script>
