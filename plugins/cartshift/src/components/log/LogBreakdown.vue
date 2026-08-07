<template>
  <section class="cartshift-log-breakdown" aria-labelledby="cartshift-breakdown-title">
    <div class="cartshift-log-breakdown-head">
      <h3 id="cartshift-breakdown-title" class="cartshift-log-breakdown-title">
        What went wrong, grouped
      </h3>
      <p class="cartshift-log-breakdown-lede">
        <template v-if="derived">
          Counted from the {{ formatNumber(covers) }}
          {{ covers === 1 ? 'entry' : 'entries' }} loaded so far, not the whole run.
          Load more entries for a fuller picture.
        </template>
        <template v-else>
          Every classified entry in this run, most common first.
        </template>
      </p>
    </div>

    <ul class="cartshift-log-breakdown-list">
      <li v-for="row in visibleRows" :key="row.code" class="cartshift-log-breakdown-item">
        <button
          type="button"
          :class="[
            'cartshift-log-breakdown-row',
            'cartshift-log-breakdown-row-' + (row.severity || 'plain'),
            { 'cartshift-log-breakdown-row-active': activeCode === row.code },
          ]"
          :aria-pressed="activeCode === row.code"
          @click="$emit('select', activeCode === row.code ? '' : row.code)"
        >
          <span class="cartshift-log-breakdown-count">{{ formatNumber(row.count) }}&times;</span>
          <span class="cartshift-log-breakdown-body">
            <span class="cartshift-log-breakdown-label">
              <span
                v-if="row.severity"
                :class="'cartshift-log-severity cartshift-log-severity-' + row.severity"
              >{{ severityLabel(row.severity) }}</span>
              {{ row.label }}
            </span>
            <span v-if="row.hint" class="cartshift-log-breakdown-hint">{{ row.hint }}</span>
            <code class="cartshift-log-breakdown-code">{{ row.code }}</code>
          </span>
          <span class="cartshift-log-breakdown-action">
            {{ activeCode === row.code ? 'Showing' : 'Show' }}
          </span>
        </button>

        <!--
          A hint is only half an answer. Where re-running the record can end
          differently, put the button next to the reason instead of making the
          admin read the advice and then go hunting for the control.
        -->
        <button
          v-if="retryEnabled && canRetry(row)"
          type="button"
          class="button cartshift-log-breakdown-retry"
          :aria-label="'Retry the ' + formatNumber(row.count) + ' records that failed with: ' + row.label"
          @click="$emit('retry', row)"
        >
          Retry these
        </button>
      </li>
    </ul>

    <div class="cartshift-log-breakdown-foot">
      <button
        v-if="rows.length > COLLAPSED_LIMIT"
        type="button"
        class="button"
        @click="expanded = !expanded"
      >
        {{ expanded ? 'Show top ' + COLLAPSED_LIMIT : 'Show all ' + rows.length + ' reasons' }}
      </button>
      <button
        v-if="activeCode"
        type="button"
        class="button"
        @click="$emit('select', '')"
      >
        Clear reason filter
      </button>
    </div>
  </section>
</template>

<script setup>
import { ref, computed } from 'vue';
import { isRetryableRow } from '@/composables/useLogViewer.js';

const COLLAPSED_LIMIT = 5;

const props = defineProps({
  rows: {
    type: Array,
    default: () => [],
  },
  // True when the counts came from loaded entries rather than the server.
  derived: {
    type: Boolean,
    default: false,
  },
  covers: {
    type: Number,
    default: 0,
  },
  activeCode: {
    type: String,
    default: '',
  },
  // Whether the screen this breakdown sits on can actually start a retry.
  retryEnabled: {
    type: Boolean,
    default: false,
  },
});

defineEmits(['select', 'retry']);

const expanded = ref(false);

function canRetry(row) {
  return isRetryableRow(row) && Number(row.count) > 0;
}

const visibleRows = computed(() => {
  return expanded.value ? props.rows : props.rows.slice(0, COLLAPSED_LIMIT);
});

function severityLabel(severity) {
  switch (severity) {
    case 'error': return 'Error';
    case 'warning': return 'Warning';
    case 'info': return 'FYI';
    default: return '';
  }
}

function formatNumber(value) {
  const n = Number(value);
  if (!Number.isFinite(n)) return '0';
  return n.toLocaleString();
}
</script>
