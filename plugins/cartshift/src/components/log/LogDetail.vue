<template>
  <div class="cartshift-log-detail">
    <div class="cartshift-log-detail-inner">
      <div class="cartshift-log-detail-grid">
        <div class="cartshift-log-detail-field">
          <span class="cartshift-log-detail-label">Status</span>
          <span :class="'cartshift-badge cartshift-badge-' + normalizedStatus">
            {{ statusLabel }}
          </span>
        </div>
        <div class="cartshift-log-detail-field">
          <span class="cartshift-log-detail-label">WC ID</span>
          <span class="cartshift-log-detail-value">{{ entry.wc_id || '-' }}</span>
        </div>
        <div class="cartshift-log-detail-field">
          <span class="cartshift-log-detail-label">Entity</span>
          <span class="cartshift-log-detail-value">{{ capitalize(entry.entity_type) }}</span>
        </div>
        <div class="cartshift-log-detail-field">
          <span class="cartshift-log-detail-label">Time</span>
          <span class="cartshift-log-detail-value">{{ entry.created_at }}</span>
        </div>
      </div>

      <div v-if="entry.message" class="cartshift-log-detail-message">
        <span class="cartshift-log-detail-label">Message</span>
        <p>{{ entry.message }}</p>
      </div>

      <div v-if="entry.details" class="cartshift-log-detail-json">
        <span class="cartshift-log-detail-label">Details</span>
        <pre>{{ formattedDetails }}</pre>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  entry: {
    type: Object,
    required: true,
  },
});

const normalizedStatus = computed(() => {
  const s = props.entry.status;
  if (s === 'dry-run') return 'dryrun';
  return s;
});

const statusLabel = computed(() => {
  return capitalize(props.entry.status);
});

const formattedDetails = computed(() => {
  if (!props.entry.details) return '';
  return JSON.stringify(props.entry.details, null, 2);
});

function capitalize(str) {
  if (!str) return '';
  return str.charAt(0).toUpperCase() + str.slice(1).replace(/[-_]/g, ' ');
}
</script>
