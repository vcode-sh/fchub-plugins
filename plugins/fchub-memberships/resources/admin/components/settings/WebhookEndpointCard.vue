<template>
  <article class="webhook-endpoint-card" :class="`is-${endpoint.status}`" data-webhook-endpoint>
    <header>
      <div>
        <span class="endpoint-eyebrow">Webhook endpoint</span>
        <h4 data-endpoint-name>{{ endpoint.name }}</h4>
        <code data-endpoint-url>{{ endpoint.url }}</code>
      </div>
      <span class="endpoint-status" :class="`is-${endpoint.status}`" data-endpoint-status>
        {{ statusLabel }}
      </span>
    </header>

    <div class="endpoint-facts">
      <div data-endpoint-secret>
        <strong>{{ secretLabel }}</strong>
        <span v-if="endpoint.requires_rotation">Rotate now to give this endpoint its own secret.</span>
        <span v-else>The secret belongs only to this endpoint.</span>
      </div>
      <div>
        <strong>{{ testLabel }}</strong>
        <span>Tests are one-shot and never enter the retry queue.</span>
      </div>
    </div>

    <div class="endpoint-actions">
      <button
        v-if="!endpoint.secret_configured || endpoint.requires_rotation"
        type="button"
        class="endpoint-button is-primary"
        data-rotate-endpoint-secret
        :disabled="isBusy"
        @click="emit('rotate-secret', endpoint.id)"
      >{{ endpoint.secret_configured ? 'Rotate secret' : 'Generate secret' }}</button>
      <button
        v-else
        type="button"
        class="endpoint-button"
        data-rotate-endpoint-secret
        :disabled="isBusy"
        @click="emit('rotate-secret', endpoint.id)"
      >Rotate secret</button>
      <button
        type="button"
        class="endpoint-button"
        data-test-endpoint
        :disabled="isBusy || !endpoint.secret_configured"
        @click="emit('test', endpoint.id)"
      >{{ busy.test ? 'Testing…' : 'Test endpoint' }}</button>
      <button
        v-if="endpoint.status !== 'active'"
        type="button"
        class="endpoint-button is-primary"
        data-activate-endpoint
        :disabled="isBusy || endpoint.last_test_status !== 'succeeded'"
        @click="emit('activate', endpoint.id)"
      >Activate</button>
      <button
        v-else
        type="button"
        class="endpoint-button"
        data-pause-endpoint
        :disabled="isBusy"
        @click="emit('pause', endpoint.id)"
      >Pause</button>
      <button
        type="button"
        class="endpoint-button is-danger"
        data-delete-endpoint
        :disabled="isBusy"
        @click="emit('delete', endpoint.id)"
      >Delete</button>
    </div>
  </article>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  endpoint: { type: Object, required: true },
  busy: { type: Object, default: () => ({}) },
})
const emit = defineEmits(['rotate-secret', 'test', 'activate', 'pause', 'delete'])

const isBusy = computed(() => Object.values(props.busy).some(Boolean))
const statusLabel = computed(() => ({
  active: 'Active',
  paused: 'Paused',
  draft: 'Setup required',
}[props.endpoint.status] || 'Setup required'))
const secretLabel = computed(() => {
  if (props.endpoint.requires_rotation) return 'Shared legacy secret detected'
  return props.endpoint.secret_configured ? 'Independent secret configured' : 'Secret required'
})
const testLabel = computed(() => ({
  succeeded: 'Last test passed',
  failed: 'Last test failed',
}[props.endpoint.last_test_status] || 'Not tested yet'))
</script>

<style scoped>
.webhook-endpoint-card { display: grid; gap: 16px; padding: 18px; border: 1px solid var(--fchub-border-color); border-radius: 12px; background: var(--fchub-card-bg); box-shadow: 0 1px 2px rgb(15 23 42 / 4%); }
.webhook-endpoint-card.is-active { border-color: color-mix(in srgb, var(--el-color-success) 38%, var(--fchub-border-color)); }
.webhook-endpoint-card > header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; }
.webhook-endpoint-card h4 { margin: 3px 0 6px; color: var(--fchub-text-primary); font-size: 15px; }
.webhook-endpoint-card code { display: block; max-width: 100%; color: var(--fchub-text-secondary); overflow-wrap: anywhere; font-size: 11px; }
.endpoint-eyebrow { color: var(--el-color-primary); font-size: 9px; font-weight: 750; letter-spacing: .08em; text-transform: uppercase; }
.endpoint-status { flex: none; padding: 4px 8px; border-radius: 999px; color: var(--fchub-text-secondary); background: var(--fchub-page-bg); font-size: 10px; font-weight: 700; }
.endpoint-status.is-active { color: #19733f; background: color-mix(in srgb, var(--el-color-success) 14%, var(--fchub-card-bg)); }
.endpoint-facts { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
.endpoint-facts > div { display: grid; gap: 3px; padding: 11px; border: 1px solid color-mix(in srgb, var(--fchub-border-color) 76%, transparent); border-radius: 9px; background: color-mix(in srgb, var(--fchub-page-bg) 48%, var(--fchub-card-bg)); }
.endpoint-facts strong { color: var(--fchub-text-primary); font-size: 11px; }
.endpoint-facts span { color: var(--fchub-text-secondary); font-size: 10px; line-height: 1.4; }
.endpoint-actions { display: flex; flex-wrap: wrap; gap: 8px; }
.endpoint-button { min-height: 32px; padding: 6px 10px; border: 1px solid var(--fchub-border-color); border-radius: 7px; color: var(--fchub-text-primary); background: var(--fchub-card-bg); font: inherit; font-size: 11px; font-weight: 650; cursor: pointer; }
.endpoint-button:hover:not(:disabled) { border-color: var(--el-color-primary); color: var(--el-color-primary); }
.endpoint-button.is-primary { border-color: var(--el-color-primary); color: #fff; background: var(--el-color-primary); }
.endpoint-button.is-primary:hover:not(:disabled) { color: #fff; }
.endpoint-button.is-danger { color: var(--el-color-danger); }
.endpoint-button:disabled { cursor: not-allowed; opacity: .5; }
@media (max-width: 600px) { .endpoint-facts { grid-template-columns: 1fr; } }
</style>
