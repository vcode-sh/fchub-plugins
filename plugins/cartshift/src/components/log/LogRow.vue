<template>
  <tbody class="cartshift-log-row-group">
    <tr
      :class="['cartshift-log-row', rowClass]"
      @click="expanded = !expanded"
    >
      <td class="cartshift-log-row-icon">
        <span
          :class="'cartshift-log-status-icon cartshift-log-status-icon-' + normalizedStatus"
          :title="statusLabel"
        >
          <span aria-hidden="true">{{ statusIcon }}</span>
          <span class="cartshift-sr-only">{{ statusLabel }}</span>
        </span>
      </td>
      <td class="cartshift-log-row-time">
        <span :title="entry.created_at || ''">{{ relativeTime }}</span>
      </td>
      <td>
        <span class="cartshift-log-entity-badge">{{ capitalize(entry.entity_type) }}</span>
      </td>
      <td class="cartshift-log-row-message">
        <span v-if="code" class="cartshift-log-row-code" :title="'Reason code: ' + code">
          {{ codeLabel }}
        </span>
        {{ truncatedMessage }}
      </td>
      <td class="cartshift-log-row-chevron">
        <button
          type="button"
          class="cartshift-log-chevron-btn"
          :aria-expanded="expanded ? 'true' : 'false'"
          :aria-label="(expanded ? 'Hide' : 'Show') + ' details for ' + capitalize(entry.entity_type) + ' ' + (entry.wc_id || 'entry')"
          @click.stop="expanded = !expanded"
        >
          <span :class="['cartshift-log-chevron', { 'cartshift-log-chevron-open': expanded }]" aria-hidden="true">
            &#9660;
          </span>
        </button>
      </td>
    </tr>
    <tr v-if="expanded" class="cartshift-log-detail-row">
      <td colspan="5">
        <Transition name="cartshift-slide">
          <LogDetail :entry="entry" :descriptor="descriptor" />
        </Transition>
      </td>
    </tr>
  </tbody>
</template>

<script setup>
import { ref, computed } from 'vue';
import { extractCode } from '@/composables/useLogViewer.js';
import LogDetail from './LogDetail.vue';

const props = defineProps({
  entry: {
    type: Object,
    required: true,
  },
  // Translated descriptor for this row's code, when the stats endpoint knows it.
  descriptor: {
    type: Object,
    default: null,
  },
});

const expanded = ref(false);

const normalizedStatus = computed(() => {
  const s = props.entry.status;
  if (s === 'dry-run') return 'dryrun';
  return s;
});

const code = computed(() => extractCode(props.entry));

// Prefer the server's translated label; the raw slug is only a fallback.
const codeLabel = computed(() => props.descriptor?.label || code.value);

const statusLabel = computed(() => {
  switch (props.entry.status) {
    case 'success': return 'Migrated';
    case 'error': return 'Error';
    case 'warning': return 'Migrated with a warning';
    case 'skipped': return 'Skipped';
    case 'dry-run': return 'Dry run';
    default: return capitalize(props.entry.status) || 'Logged';
  }
});

const statusIcon = computed(() => {
  switch (props.entry.status) {
    case 'success': return '\u2713';
    case 'error': return '\u2717';
    // Warning keeps the triangle; skipped moves to an arrow-to-bar, because two
    // different outcomes wearing the same glyph is how warnings stayed invisible.
    case 'warning': return '\u26A0';
    case 'skipped': return '\u21E5';
    case 'dry-run': return '\u25CB';
    default: return '\u2022';
  }
});

const rowClass = computed(() => {
  switch (props.entry.status) {
    case 'error': return 'cartshift-log-row-error';
    case 'warning': return 'cartshift-log-row-warning';
    case 'skipped': return 'cartshift-log-row-skipped';
    case 'dry-run': return 'cartshift-log-row-dryrun';
    default: return '';
  }
});

const relativeTime = computed(() => {
  if (!props.entry.created_at) return '-';
  return formatRelative(props.entry.created_at);
});

const truncatedMessage = computed(() => {
  const msg = props.entry.message || '';
  if (msg.length <= 80) return msg;
  return msg.substring(0, 77) + '...';
});

function capitalize(str) {
  if (!str) return '';
  return str.charAt(0).toUpperCase() + str.slice(1).replace(/[-_]/g, ' ');
}

function formatRelative(dateStr) {
  const date = new Date(dateStr + 'Z');
  const now = new Date();
  const diffMs = now - date;
  const diffSec = Math.floor(diffMs / 1000);

  if (diffSec < 60) return 'just now';
  if (diffSec < 3600) return Math.floor(diffSec / 60) + 'm ago';
  if (diffSec < 86400) return Math.floor(diffSec / 3600) + 'h ago';

  const diffDays = Math.floor(diffSec / 86400);
  if (diffDays === 1) return '1 day ago';
  if (diffDays < 30) return diffDays + ' days ago';

  return date.toLocaleDateString();
}
</script>
