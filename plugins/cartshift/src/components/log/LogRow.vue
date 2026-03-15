<template>
  <tbody class="cartshift-log-row-group">
    <tr
      :class="['cartshift-log-row', rowClass]"
      @click="expanded = !expanded"
    >
      <td class="cartshift-log-row-icon">
        <span :class="'cartshift-log-status-icon cartshift-log-status-icon-' + normalizedStatus">
          {{ statusIcon }}
        </span>
      </td>
      <td class="cartshift-log-row-time">{{ relativeTime }}</td>
      <td>
        <span class="cartshift-log-entity-badge">{{ capitalize(entry.entity_type) }}</span>
      </td>
      <td class="cartshift-log-row-message">
        {{ truncatedMessage }}
      </td>
      <td class="cartshift-log-row-chevron">
        <span :class="['cartshift-log-chevron', { 'cartshift-log-chevron-open': expanded }]">
          &#9660;
        </span>
      </td>
    </tr>
    <tr v-if="expanded" class="cartshift-log-detail-row">
      <td colspan="5">
        <Transition name="cartshift-slide">
          <LogDetail :entry="entry" />
        </Transition>
      </td>
    </tr>
  </tbody>
</template>

<script setup>
import { ref, computed } from 'vue';
import LogDetail from './LogDetail.vue';

const props = defineProps({
  entry: {
    type: Object,
    required: true,
  },
});

const expanded = ref(false);

const normalizedStatus = computed(() => {
  const s = props.entry.status;
  if (s === 'dry-run') return 'dryrun';
  return s;
});

const statusIcon = computed(() => {
  switch (props.entry.status) {
    case 'success': return '\u2713';
    case 'error': return '\u2717';
    case 'skipped': return '\u26A0';
    case 'dry-run': return '\u25CB';
    default: return '\u2022';
  }
});

const rowClass = computed(() => {
  switch (props.entry.status) {
    case 'error': return 'cartshift-log-row-error';
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
