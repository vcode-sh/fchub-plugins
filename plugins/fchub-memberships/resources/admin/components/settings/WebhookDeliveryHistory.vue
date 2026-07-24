<template>
  <section class="webhook-delivery-history" aria-labelledby="webhook-delivery-history-title">
    <header class="webhook-delivery-history-header">
      <div>
        <span>Recent activity</span>
        <h4 id="webhook-delivery-history-title">Latest webhook deliveries</h4>
        <p>The 20 most recent delivery attempts.</p>
      </div>
      <button
        type="button"
        class="webhook-delivery-refresh"
        data-refresh-history
        :disabled="loading"
        @click="emit('refresh')"
      >Refresh</button>
    </header>

    <div v-if="loading" class="webhook-delivery-state" role="status" aria-live="polite">
      Loading delivery history…
    </div>
    <div v-else-if="error" class="webhook-delivery-state is-error" role="alert">
      <strong>Delivery history unavailable</strong>
      <span>{{ error }}</span>
    </div>
    <div v-else-if="recentDeliveries.length === 0" class="webhook-delivery-state" role="status">
      <strong>No webhook deliveries yet</strong>
      <span>New delivery attempts will appear here.</span>
    </div>

    <div v-else class="webhook-delivery-list" role="list" aria-label="Latest webhook deliveries">
      <article
        v-for="delivery in recentDeliveries"
        :key="delivery.id"
        class="webhook-delivery-row"
        data-delivery-row
        role="listitem"
      >
        <div class="webhook-delivery-main">
          <div class="webhook-delivery-event">
            <strong>{{ eventLabel(delivery.event_type) }}</strong>
            <code>{{ delivery.event_id }}</code>
          </div>
          <div class="webhook-delivery-meta">
            <span class="webhook-delivery-destination">{{ destinationHost(delivery.destination_url) }}</span>
            <span>Attempt {{ Number(delivery.attempt_count) || 0 }}</span>
            <span v-if="hasResponseCode(delivery)">HTTP {{ delivery.response_code }}</span>
            <time :datetime="deliveryTimestamp(delivery)">{{ relativeTimestamp(delivery) }}</time>
          </div>
        </div>

        <div class="webhook-delivery-side">
          <span
            class="webhook-delivery-status"
            :class="`is-${statusTone(delivery.status)}`"
            :aria-label="`Delivery status: ${statusLabel(delivery.status)}`"
          >{{ statusLabel(delivery.status) }}</span>
          <button
            v-if="['pending', 'retrying'].includes(delivery.status)"
            type="button"
            class="webhook-delivery-retry"
            data-cancel-delivery
            :aria-label="`Stop retrying ${eventLabel(delivery.event_type)} delivery to ${destinationHost(delivery.destination_url)}`"
            @click="emit('cancel', delivery.id)"
          >Stop retrying</button>
          <button
            v-if="delivery.status === 'failed'"
            type="button"
            class="webhook-delivery-retry"
            data-retry-delivery
            :disabled="isRetrying(delivery.id)"
            :aria-label="`Retry ${eventLabel(delivery.event_type)} delivery to ${destinationHost(delivery.destination_url)}`"
            @click="emit('retry', delivery.id)"
          >{{ isRetrying(delivery.id) ? 'Retrying…' : 'Retry' }}</button>
        </div>
      </article>
    </div>
  </section>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  deliveries: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
  error: { type: String, default: '' },
  retryingId: { type: [Number, String], default: null },
})

const emit = defineEmits(['refresh', 'retry', 'cancel'])

const recentDeliveries = computed(() => props.deliveries.slice(0, 20))

const STATUS_LABELS = {
  pending: 'Pending',
  processing: 'Pending',
  retrying: 'Retrying',
  succeeded: 'Delivered',
  failed: 'Failed',
  cancelled: 'Stopped',
}

function eventLabel(value) {
  const label = String(value || 'Webhook event').replaceAll('_', ' ')
  return label.charAt(0).toUpperCase() + label.slice(1)
}

function destinationHost(value) {
  try {
    return new URL(String(value)).hostname || 'Unknown destination'
  } catch {
    return 'Unknown destination'
  }
}

function statusLabel(status) {
  return STATUS_LABELS[status] || 'Pending'
}

function statusTone(status) {
  if (status === 'succeeded') return 'delivered'
  if (status === 'failed') return 'failed'
  if (status === 'retrying') return 'retrying'
  return 'pending'
}

function hasResponseCode(delivery) {
  return delivery.response_code !== null && delivery.response_code !== undefined
}

function deliveryTimestamp(delivery) {
  return delivery.delivered_at
    || delivery.last_attempt_at
    || delivery.updated_at
    || delivery.created_at
    || ''
}

function parseTimestamp(value) {
  const raw = String(value || '')
  const normalized = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(raw)
    ? `${raw.replace(' ', 'T')}Z`
    : raw
  const timestamp = new Date(normalized)
  return Number.isNaN(timestamp.getTime()) ? null : timestamp
}

function relativeTimestamp(delivery) {
  const timestamp = parseTimestamp(deliveryTimestamp(delivery))
  if (!timestamp) return 'Time unavailable'

  const seconds = Math.max(0, Math.floor((Date.now() - timestamp.getTime()) / 1000))
  if (seconds < 60) return 'Just now'
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`
  if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`
  return `${Math.floor(seconds / 86400)}d ago`
}

function isRetrying(deliveryId) {
  return props.retryingId !== null && String(props.retryingId) === String(deliveryId)
}
</script>

<style scoped>
.webhook-delivery-history { min-width: 0; max-width: 100%; overflow: hidden; border: 1px solid var(--fchub-border-color); border-radius: 12px; background: var(--fchub-card-bg); }
.webhook-delivery-history-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; padding: 16px 18px; border-bottom: 1px solid var(--fchub-border-color); background: color-mix(in srgb, var(--fchub-page-bg) 46%, var(--fchub-card-bg)); }
.webhook-delivery-history-header > div { min-width: 0; }
.webhook-delivery-history-header span { display: block; margin-bottom: 3px; color: var(--el-color-primary); font-size: 10px; font-weight: 750; letter-spacing: .08em; text-transform: uppercase; }
.webhook-delivery-history-header h4 { margin: 0; color: var(--fchub-text-primary); font-size: 14px; line-height: 1.35; }
.webhook-delivery-history-header p { margin: 4px 0 0; color: var(--fchub-text-secondary); font-size: 11px; line-height: 1.4; }
.webhook-delivery-refresh, .webhook-delivery-retry { flex: 0 0 auto; min-height: 32px; padding: 5px 10px; border: 1px solid var(--fchub-border-color); border-radius: 7px; color: var(--fchub-text-primary); background: var(--fchub-card-bg); font: inherit; font-size: 11px; font-weight: 650; cursor: pointer; }
.webhook-delivery-refresh:hover:not(:disabled), .webhook-delivery-retry:hover:not(:disabled) { border-color: var(--el-color-primary); color: var(--el-color-primary); }
.webhook-delivery-refresh:focus-visible, .webhook-delivery-retry:focus-visible { outline: 2px solid var(--el-color-primary); outline-offset: 2px; }
.webhook-delivery-refresh:disabled, .webhook-delivery-retry:disabled { cursor: wait; opacity: .55; }
.webhook-delivery-state { display: grid; gap: 4px; justify-items: start; padding: 22px 18px; color: var(--fchub-text-secondary); font-size: 12px; }
.webhook-delivery-state strong { color: var(--fchub-text-primary); }
.webhook-delivery-state.is-error { color: var(--el-color-danger); background: color-mix(in srgb, var(--el-color-danger) 6%, var(--fchub-card-bg)); }
.webhook-delivery-list { min-width: 0; }
.webhook-delivery-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 14px; align-items: center; min-width: 0; padding: 12px 18px; border-bottom: 1px solid var(--fchub-border-color); }
.webhook-delivery-row:last-child { border-bottom: 0; }
.webhook-delivery-main { display: grid; gap: 7px; min-width: 0; }
.webhook-delivery-event { display: flex; align-items: baseline; flex-wrap: wrap; gap: 5px 9px; min-width: 0; }
.webhook-delivery-event strong { color: var(--fchub-text-primary); font-size: 12px; line-height: 1.35; }
.webhook-delivery-event code { min-width: 0; color: var(--fchub-text-secondary); font-size: 10px; overflow-wrap: anywhere; white-space: normal; }
.webhook-delivery-meta { display: flex; flex-wrap: wrap; gap: 4px 12px; min-width: 0; color: var(--fchub-text-secondary); font-size: 10px; line-height: 1.4; }
.webhook-delivery-destination { min-width: 0; color: var(--fchub-text-primary); font-weight: 620; overflow-wrap: anywhere; }
.webhook-delivery-side { display: flex; align-items: center; justify-content: flex-end; gap: 8px; }
.webhook-delivery-status { padding: 3px 7px; border-radius: 999px; font-size: 10px; font-weight: 700; white-space: nowrap; }
.webhook-delivery-status.is-pending { color: #785b18; background: color-mix(in srgb, var(--el-color-warning) 14%, var(--fchub-card-bg)); }
.webhook-delivery-status.is-retrying { color: #8a4f12; background: color-mix(in srgb, var(--el-color-warning) 20%, var(--fchub-card-bg)); }
.webhook-delivery-status.is-delivered { color: #19733f; background: color-mix(in srgb, var(--el-color-success) 14%, var(--fchub-card-bg)); }
.webhook-delivery-status.is-failed { color: var(--el-color-danger); background: color-mix(in srgb, var(--el-color-danger) 10%, var(--fchub-card-bg)); }

@media (max-width: 600px) {
  .webhook-delivery-history-header { align-items: stretch; flex-direction: column; padding: 14px; }
  .webhook-delivery-refresh { align-self: flex-start; }
  .webhook-delivery-row { grid-template-columns: minmax(0, 1fr); gap: 10px; padding: 13px 14px; }
  .webhook-delivery-side { justify-content: space-between; }
}
</style>
