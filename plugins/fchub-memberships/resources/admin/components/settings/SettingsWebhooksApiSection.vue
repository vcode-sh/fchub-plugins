<template>
  <div class="fchub-settings-section webhook-settings-section">
    <div class="endpoint-section-header">
      <div>
        <span>Webhook endpoints</span>
        <h3>Send membership events where they need to go</h3>
        <p>Every endpoint has its own secret, test, activation state and delivery controls.</p>
      </div>
      <div class="endpoint-header-actions">
        <button type="button" class="text-button is-guide" data-open-webhook-guide @click="guideTopic = 'webhooks'">
          How it works
        </button>
        <button type="button" class="section-button is-primary" data-add-webhook-endpoint @click="showCreate = true">
          Add endpoint
        </button>
      </div>
    </div>

    <div v-if="webhookError" class="settings-inline-error" data-webhook-error role="alert">{{ webhookError }}</div>

    <form v-if="showCreate" class="endpoint-create-card" data-endpoint-create-form @submit.prevent="createEndpoint">
      <div class="endpoint-create-heading">
        <div><span>New endpoint</span><strong>Start with the receiver details</strong></div>
        <button type="button" class="text-button" @click="resetCreate">Cancel</button>
      </div>
      <label>
        <span>Name</span>
        <input v-model.trim="newEndpoint.name" type="text" required aria-label="Endpoint name" placeholder="CRM receiver">
      </label>
      <label>
        <span>HTTPS URL</span>
        <input v-model.trim="newEndpoint.url" type="url" required aria-label="Endpoint URL" placeholder="https://example.com/webhook">
      </label>
      <div class="endpoint-create-footer">
        <p>Next: generate its secret, send one test, then activate it.</p>
        <button type="submit" class="section-button is-primary" :disabled="endpointBusy.create">
          {{ endpointBusy.create ? 'Adding…' : 'Add endpoint' }}
        </button>
      </div>
    </form>

    <div v-if="endpoints.length" class="endpoint-list">
      <WebhookEndpointCard
        v-for="endpoint in endpoints"
        :key="endpoint.id"
        :endpoint="endpoint"
        :busy="endpointBusy[endpoint.id] || {}"
        @rotate-secret="actions.rotateEndpointSecret"
        @test="actions.testEndpoint"
        @activate="actions.activateEndpoint"
        @pause="actions.pauseEndpoint"
        @delete="actions.deleteEndpoint"
      />
    </div>
    <div v-else class="endpoint-empty">
      <strong>No webhook endpoints yet</strong>
      <span>Add the public HTTPS URL supplied by the system receiving membership events.</span>
    </div>

    <WebhookDeliveryHistory
      class="settings-delivery-history"
      :deliveries="history.deliveries || []"
      :loading="Boolean(history.loading)"
      :error="history.error || ''"
      :retrying-id="history.retryingId ?? null"
      @refresh="emit('refresh-history')"
      @retry="emit('retry-delivery', $event)"
      @cancel="emit('cancel-delivery', $event)"
    />
  </div>

  <div class="fchub-settings-section api-settings-section">
    <div class="endpoint-section-header">
      <div>
        <span>Access API</span>
        <h3>Read-only membership checks</h3>
        <p>Use a separate API key for external access checks. It is not a webhook secret.</p>
      </div>
      <div class="endpoint-header-actions">
        <button type="button" class="text-button is-guide" data-open-api-guide @click="guideTopic = 'api'">
          How it works
        </button>
        <span class="endpoint-summary" data-access-api-status>{{ accessApi.configured ? 'Ready' : 'Not configured' }}</span>
      </div>
    </div>
    <div class="api-key-card">
      <template v-if="accessApi.configured">
        <dl>
          <div><dt>Prefix</dt><dd>{{ accessApi.prefix || 'Unavailable' }}</dd></div>
          <div><dt>Rotated</dt><dd>{{ accessApi.rotated_at || 'Unavailable' }}</dd></div>
        </dl>
        <div class="api-actions">
          <button type="button" class="section-button" data-regenerate-api-key :disabled="busy.credentialMutation" @click="actions.regenerateApiKey">Regenerate key</button>
          <button type="button" class="section-button is-danger" data-revoke-api-key :disabled="busy.credentialMutation" @click="actions.revokeApiKey">Revoke</button>
        </div>
      </template>
      <button v-else type="button" class="section-button" data-generate-api-key :disabled="busy.credentialMutation" @click="actions.generateApiKey">Generate API key</button>
    </div>
  </div>

  <SettingsConnectionGuideDialog
    v-if="guideTopic"
    :topic="guideTopic"
    :api-root="apiRoot"
    @close="guideTopic = ''"
  />

  <WebhookSecretDialog
    v-if="oneTimeEndpointSecret.secret"
    :secret="oneTimeEndpointSecret.secret"
    :endpoint-name="oneTimeEndpointSecret.name"
    @close="emit('close-endpoint-secret')"
  />

  <div
    v-if="oneTimeCredentials.apiKey"
    class="api-key-dialog"
    role="dialog"
    aria-modal="true"
    aria-labelledby="one-time-api-key-title"
  >
    <div>
      <span>Shown once</span>
      <h3 id="one-time-api-key-title">Save the new API key</h3>
      <code>{{ oneTimeCredentials.apiKey }}</code>
      <button type="button" class="section-button is-primary" @click="emit('acknowledge-api-key')">Close</button>
    </div>
  </div>
</template>

<script setup>
import { reactive, ref } from 'vue'
import SettingsConnectionGuideDialog from './SettingsConnectionGuideDialog.vue'
import WebhookDeliveryHistory from './WebhookDeliveryHistory.vue'
import WebhookEndpointCard from './WebhookEndpointCard.vue'
import WebhookSecretDialog from './WebhookSecretDialog.vue'

const props = defineProps({
  accessApi: { type: Object, default: () => ({ configured: false }) },
  endpoints: { type: Array, default: () => [] },
  endpointBusy: { type: Object, default: () => ({}) },
  oneTimeEndpointSecret: { type: Object, default: () => ({ secret: '', name: '' }) },
  oneTimeCredentials: { type: Object, default: () => ({ apiKey: '' }) },
  busy: { type: Object, default: () => ({}) },
  actions: { type: Object, required: true },
  webhookError: { type: String, default: '' },
  history: { type: Object, default: () => ({}) },
})
const emit = defineEmits([
  'acknowledge-api-key',
  'close-endpoint-secret',
  'refresh-history',
  'retry-delivery',
  'cancel-delivery',
])

const showCreate = ref(false)
const guideTopic = ref('')
const newEndpoint = reactive({ name: '', url: '' })
const apiRoot = `${String(window.fchubMembershipsAdmin?.rest_url || '/wp-json/fchub-memberships/v1/').replace(/\/+$/, '')}/`

async function createEndpoint() {
  const created = await props.actions.createEndpoint({ ...newEndpoint })
  if (created !== false) resetCreate()
}

function resetCreate() {
  newEndpoint.name = ''
  newEndpoint.url = ''
  showCreate.value = false
}
</script>

<style scoped>
.webhook-settings-section, .api-settings-section { display: grid; gap: 16px; }
.endpoint-section-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 18px; padding-bottom: 16px; border-bottom: 1px solid var(--fchub-border-color); }
.endpoint-section-header > div { min-width: 0; }
.endpoint-header-actions { display: flex; align-items: center; justify-content: flex-end; gap: 10px; flex: none; }
.endpoint-section-header span { color: var(--el-color-primary); font-size: 10px; font-weight: 750; letter-spacing: .08em; text-transform: uppercase; }
.endpoint-section-header h3 { margin: 4px 0 5px; color: var(--fchub-text-primary); font-size: 17px; }
.endpoint-section-header p { margin: 0; color: var(--fchub-text-secondary); font-size: 12px; line-height: 1.5; }
.endpoint-summary { flex: none; padding: 5px 9px; border-radius: 999px; background: var(--fchub-page-bg); }
.section-button { min-height: 34px; padding: 6px 11px; border: 1px solid var(--fchub-border-color); border-radius: 7px; color: var(--fchub-text-primary); background: var(--fchub-card-bg); font: inherit; font-size: 11px; font-weight: 650; cursor: pointer; }
.section-button.is-primary { border-color: var(--el-color-primary); color: #fff; background: var(--el-color-primary); }
.section-button.is-danger { color: var(--el-color-danger); }
.section-button:disabled { cursor: not-allowed; opacity: .5; }
.settings-inline-error { padding: 11px 12px; border: 1px solid color-mix(in srgb, var(--el-color-danger) 32%, transparent); border-radius: 8px; color: var(--el-color-danger); background: color-mix(in srgb, var(--el-color-danger) 6%, var(--fchub-card-bg)); font-size: 12px; }
.endpoint-create-card { display: grid; grid-template-columns: minmax(0, .7fr) minmax(0, 1.3fr); gap: 14px; padding: 16px; border: 1px solid color-mix(in srgb, var(--el-color-primary) 38%, var(--fchub-border-color)); border-radius: 12px; background: color-mix(in srgb, var(--el-color-primary) 4%, var(--fchub-card-bg)); }
.endpoint-create-heading, .endpoint-create-footer { grid-column: 1 / -1; display: flex; align-items: center; justify-content: space-between; gap: 16px; }
.endpoint-create-heading div { display: grid; gap: 3px; }
.endpoint-create-heading span { color: var(--el-color-primary); font-size: 9px; font-weight: 750; text-transform: uppercase; letter-spacing: .08em; }
.endpoint-create-heading strong { color: var(--fchub-text-primary); font-size: 13px; }
.text-button { border: 0; color: var(--fchub-text-secondary); background: transparent; cursor: pointer; }
.text-button.is-guide { color: var(--el-color-primary); font-size: 11px; font-weight: 650; }
.endpoint-create-card label { display: grid; gap: 6px; color: var(--fchub-text-primary); font-size: 11px; font-weight: 650; }
.endpoint-create-card input { box-sizing: border-box; width: 100%; min-height: 40px; padding: 8px 10px; border: 1px solid #9aa5b5; border-radius: 8px; color: var(--fchub-text-primary); background: var(--fchub-card-bg); font: inherit; font-size: 12px; }
.endpoint-create-card input:focus { border-color: var(--el-color-primary); outline: 3px solid color-mix(in srgb, var(--el-color-primary) 16%, transparent); }
.endpoint-create-footer p { margin: 0; color: var(--fchub-text-secondary); font-size: 11px; }
.endpoint-list { display: grid; gap: 12px; }
.endpoint-empty { display: grid; gap: 5px; padding: 24px; border: 1px dashed #9aa5b5; border-radius: 12px; text-align: center; }
.endpoint-empty strong { color: var(--fchub-text-primary); }
.endpoint-empty span { color: var(--fchub-text-secondary); font-size: 12px; }
.settings-delivery-history { margin-top: 2px; }
.api-key-card { display: flex; align-items: flex-end; justify-content: space-between; gap: 18px; padding: 16px; border: 1px solid var(--fchub-border-color); border-radius: 12px; }
.api-key-card dl { display: flex; gap: 24px; margin: 0; }
.api-key-card dl div { display: grid; gap: 3px; }
.api-key-card dt { color: var(--fchub-text-secondary); font-size: 10px; }
.api-key-card dd { margin: 0; color: var(--fchub-text-primary); font: 600 11px/1.4 ui-monospace, monospace; }
.api-actions { display: flex; gap: 8px; }
.api-key-dialog { position: fixed; inset: 0; z-index: 100100; display: grid; place-items: center; padding: 18px; background: rgb(15 23 42 / 58%); }
.api-key-dialog > div { display: grid; gap: 13px; width: min(100%, 520px); padding: 22px; border-radius: 12px; background: var(--fchub-card-bg); }
.api-key-dialog h3 { margin: 0; }
.api-key-dialog code { padding: 12px; border: 1px solid var(--fchub-border-color); border-radius: 8px; overflow-wrap: anywhere; user-select: all; }
@media (max-width: 700px) { .endpoint-section-header, .api-key-card { flex-direction: column; align-items: stretch; } .endpoint-create-card { grid-template-columns: 1fr; } .api-key-card dl { flex-direction: column; gap: 10px; } }
</style>
