<template>
  <div class="fchub-settings-section webhook-settings-section">
    <div class="fchub-settings-section-title settings-section-heading">
      <span>Webhooks</span>
      <div class="settings-heading-actions">
        <button
          type="button"
          class="settings-guide-trigger"
          data-open-webhook-guide
          @click="guideTopic = 'webhooks'"
        >How it works</button>
        <span class="settings-status" :class="`is-${webhookStatus}`" data-webhook-status>{{ webhookStatusLabel }}</span>
      </div>
    </div>

    <div v-if="webhookError" class="settings-inline-error" data-webhook-error role="alert">
      {{ webhookError }}
    </div>

    <div class="fchub-setting-row">
      <div class="fchub-setting-label">
        <h4>Enable Webhooks</h4>
        <p>Send signed membership events after the saved destinations and secret have passed setup.</p>
      </div>
      <div class="fchub-setting-control settings-switch-control">
        <label class="settings-switch">
          <input
            v-model="form.webhook_enabled"
            type="checkbox"
            role="switch"
            aria-label="Enable webhooks"
            :disabled="!form.webhook_enabled && !webhookConfigurationValid"
          >
          <span aria-hidden="true" />
        </label>
        <small v-if="!form.webhook_enabled && !webhookConfigurationValid">Save valid destinations and generate a secret first.</small>
      </div>
    </div>

    <div class="fchub-setting-row">
      <div class="fchub-setting-label">
        <h4>Webhook URLs</h4>
        <p>Enter one HTTPS URL per line. These saved destinations remain visible while delivery is off.</p>
      </div>
      <div class="fchub-setting-control">
        <textarea
          v-model="form.webhook_urls"
          class="settings-textarea"
          aria-label="Webhook URLs"
          rows="3"
          placeholder="https://example.com/webhook"
        />
      </div>
    </div>

    <div class="fchub-setting-row">
      <div class="fchub-setting-label">
        <h4>Webhook Secret</h4>
        <p>The signing secret is shown once when generated. Stored secrets are never returned here.</p>
      </div>
      <div class="fchub-setting-control settings-credential-control">
        <span class="credential-state" data-webhook-secret-status>{{ webhookSecretLabel }}</span>
        <button
          v-if="webhookSecretConfigured"
          ref="webhookSecretActionButton"
          type="button"
          class="settings-button is-warning"
          data-regenerate-webhook-secret
          :disabled="busy.credentialMutation || busy.webhookSecret"
          @click="invokeCredentialAction('webhookSecret', actions.regenerateWebhookSecret, $event)"
        >{{ busy.webhookSecret ? 'Regenerating…' : 'Regenerate secret' }}</button>
        <button
          v-else
          ref="webhookSecretActionButton"
          type="button"
          class="settings-button"
          data-generate-webhook-secret
          :disabled="busy.credentialMutation || busy.webhookSecret"
          @click="invokeCredentialAction('webhookSecret', actions.generateWebhookSecret, $event)"
        >{{ busy.webhookSecret ? 'Generating…' : 'Generate secret' }}</button>
      </div>
    </div>

    <div class="fchub-setting-row">
      <div class="fchub-setting-label">
        <h4>Test Webhook</h4>
        <p>Send a real signed test event to every saved destination.</p>
      </div>
      <div class="fchub-setting-control">
        <button
          type="button"
          class="settings-button"
          data-test-webhook
          :disabled="busy.test || !webhookConfigurationValid"
          @click="actions.testWebhook"
        >{{ busy.test ? 'Sending…' : 'Send test' }}</button>
        <div v-if="testResults.length" class="webhook-test-results" aria-label="Webhook test results">
          <div
            v-for="(result, index) in testResults"
            :key="`${result.url || 'destination'}-${index}`"
            class="webhook-test-result"
            :class="result.success ? 'is-success' : 'is-error'"
          >
            <span>{{ result.url }}</span>
            <strong>{{ result.success ? 'OK' : (result.error || 'Failed') }}</strong>
          </div>
        </div>
      </div>
    </div>

    <WebhookDeliveryHistory
      class="settings-delivery-history"
      :deliveries="history.deliveries || []"
      :loading="Boolean(history.loading)"
      :error="history.error || ''"
      :retrying-id="history.retryingId ?? null"
      @refresh="emit('refresh-history')"
      @retry="emit('retry-delivery', $event)"
    />
  </div>

  <div class="fchub-settings-section api-settings-section">
    <div class="fchub-settings-section-title settings-section-heading">
      <span>Access API</span>
      <div class="settings-heading-actions">
        <button
          type="button"
          class="settings-guide-trigger"
          data-open-api-guide
          @click="guideTopic = 'api'"
        >How it works</button>
        <span class="settings-status" :class="accessApi.configured ? 'is-ready' : 'is-off'" data-access-api-status>
          {{ accessApi.configured ? 'Ready' : 'Not configured' }}
        </span>
      </div>
    </div>

    <div class="fchub-setting-row">
      <div class="fchub-setting-label">
        <h4>API Key</h4>
        <p>Authenticate external read-only access checks without exposing the stored credential.</p>
      </div>
      <div class="fchub-setting-control settings-credential-control">
        <template v-if="accessApi.configured">
          <dl class="credential-metadata">
            <div><dt>Prefix</dt><dd>{{ accessApi.prefix || 'Unavailable' }}</dd></div>
            <div><dt>Rotated</dt><dd>{{ accessApi.rotated_at || 'Unavailable' }}</dd></div>
          </dl>
          <div class="settings-action-row">
            <button
              ref="apiKeyActionButton"
              type="button"
              class="settings-button is-warning"
              data-regenerate-api-key
              :disabled="busy.credentialMutation || busy.apiKey"
              @click="invokeCredentialAction('apiKey', actions.regenerateApiKey, $event)"
            >{{ busy.apiKey ? 'Regenerating…' : 'Regenerate key' }}</button>
            <button
              type="button"
              class="settings-button is-danger"
              data-revoke-api-key
              :disabled="busy.credentialMutation || busy.revokeApiKey"
              @click="actions.revokeApiKey"
            >{{ busy.revokeApiKey ? 'Revoking…' : 'Revoke' }}</button>
          </div>
        </template>
        <button
          v-else
          ref="apiKeyActionButton"
          type="button"
          class="settings-button"
          data-generate-api-key
          :disabled="busy.credentialMutation || busy.apiKey"
          @click="invokeCredentialAction('apiKey', actions.generateApiKey, $event)"
        >{{ busy.apiKey ? 'Generating…' : 'Generate key' }}</button>
      </div>
    </div>
  </div>

  <SettingsConnectionGuideDialog
    v-if="guideTopic"
    :topic="guideTopic"
    :api-root="apiRoot"
    @close="guideTopic = ''"
  />

  <div
    v-if="oneTimeCredentials.apiKey"
    ref="apiKeyDialog"
    class="one-time-dialog-backdrop"
    data-one-time-api-key
    data-dialog-locked="true"
    role="dialog"
    aria-modal="true"
    aria-labelledby="one-time-api-key-title"
    tabindex="-1"
    @keydown="handleDialogKeydown('apiKey', $event)"
  >
    <div class="one-time-dialog">
      <span class="one-time-dialog-kicker">Shown once</span>
      <h3 id="one-time-api-key-title">Save the new API key</h3>
      <p>This key cannot be viewed again after you leave this dialog.</p>
      <code>{{ oneTimeCredentials.apiKey }}</code>
      <button ref="apiKeyCopyButton" type="button" class="settings-button" data-copy-one-time @click="copyOneTime('apiKey')">
        {{ apiKeyCopied ? 'Copied' : 'Copy key' }}
      </button>
      <p v-if="apiKeyCopyError" class="one-time-copy-error" role="alert">{{ apiKeyCopyError }}</p>
      <label class="one-time-acknowledgement">
        <input v-model="apiKeyAcknowledged" type="checkbox" :disabled="!apiKeyCopied">
        I have copied and safely stored this key.
      </label>
      <button
        type="button"
        class="settings-button is-primary"
        data-acknowledge
        :disabled="!apiKeyCopied || !apiKeyAcknowledged"
        @click="emit('acknowledge-api-key')"
      >Done</button>
    </div>
  </div>

  <div
    v-if="oneTimeCredentials.webhookSecret"
    ref="webhookSecretDialog"
    class="one-time-dialog-backdrop"
    data-one-time-webhook-secret
    data-dialog-locked="true"
    role="dialog"
    aria-modal="true"
    aria-labelledby="one-time-webhook-secret-title"
    tabindex="-1"
    @keydown="handleDialogKeydown('webhookSecret', $event)"
  >
    <div class="one-time-dialog">
      <span class="one-time-dialog-kicker">Shown once</span>
      <h3 id="one-time-webhook-secret-title">Save the new webhook secret</h3>
      <p>This secret cannot be viewed again after you leave this dialog.</p>
      <code>{{ oneTimeCredentials.webhookSecret }}</code>
      <button ref="webhookSecretCopyButton" type="button" class="settings-button" data-copy-one-time @click="copyOneTime('webhookSecret')">
        {{ webhookSecretCopied ? 'Copied' : 'Copy secret' }}
      </button>
      <p v-if="webhookSecretCopyError" class="one-time-copy-error" role="alert">{{ webhookSecretCopyError }}</p>
      <label class="one-time-acknowledgement">
        <input v-model="webhookSecretAcknowledged" type="checkbox" :disabled="!webhookSecretCopied">
        I have copied and safely stored this secret.
      </label>
      <button
        type="button"
        class="settings-button is-primary"
        data-acknowledge
        :disabled="!webhookSecretCopied || !webhookSecretAcknowledged"
        @click="emit('acknowledge-webhook-secret')"
      >Done</button>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import WebhookDeliveryHistory from './WebhookDeliveryHistory.vue'
import SettingsConnectionGuideDialog from './SettingsConnectionGuideDialog.vue'

const props = defineProps({
  form: { type: Object, required: true },
  accessApi: { type: Object, default: () => ({ configured: false, prefix: '', rotated_at: '' }) },
  webhookStatus: {
    type: String,
    default: 'off',
    validator: (value) => ['off', 'needs_setup', 'ready', 'degraded'].includes(value),
  },
  webhookSecretConfigured: { type: Boolean, default: false },
  webhookConfigurationValid: { type: Boolean, default: false },
  oneTimeCredentials: { type: Object, default: () => ({ apiKey: '', webhookSecret: '' }) },
  busy: { type: Object, default: () => ({}) },
  actions: { type: Object, required: true },
  testResults: { type: Array, default: () => [] },
  webhookError: { type: String, default: '' },
  history: { type: Object, default: () => ({}) },
})

const emit = defineEmits([
  'acknowledge-api-key',
  'acknowledge-webhook-secret',
  'refresh-history',
  'retry-delivery',
])

const apiKeyCopied = ref(false)
const apiKeyAcknowledged = ref(false)
const apiKeyCopyError = ref('')
const webhookSecretCopied = ref(false)
const webhookSecretAcknowledged = ref(false)
const webhookSecretCopyError = ref('')
const guideTopic = ref('')
const apiKeyDialog = ref(null)
const apiKeyCopyButton = ref(null)
const apiKeyActionButton = ref(null)
const apiKeyTrigger = ref(null)
const webhookSecretDialog = ref(null)
const webhookSecretCopyButton = ref(null)
const webhookSecretActionButton = ref(null)
const webhookSecretTrigger = ref(null)

const WEBHOOK_STATUS_LABELS = {
  off: 'Off',
  needs_setup: 'Needs setup',
  ready: 'Ready',
  degraded: 'Delivery failures',
}

const webhookStatusLabel = computed(() => WEBHOOK_STATUS_LABELS[props.webhookStatus])
const webhookSecretLabel = computed(() => (
  props.webhookSecretConfigured ? 'Configured (never reveal again)' : 'Not configured'
))
const apiRoot = computed(() => {
  const configured = window.fchubMembershipsAdmin?.rest_url || '/wp-json/fchub-memberships/v1/'
  return `${String(configured).replace(/\/+$/, '')}/`
})

watch(() => props.oneTimeCredentials.apiKey, (value, previousValue) => {
  apiKeyCopied.value = false
  apiKeyAcknowledged.value = false
  apiKeyCopyError.value = ''
  if (value) {
    nextTick(() => apiKeyCopyButton.value?.focus())
  } else if (previousValue) {
    restoreCredentialFocus('apiKey')
  }
}, { immediate: true })

watch(() => props.oneTimeCredentials.webhookSecret, (value, previousValue) => {
  webhookSecretCopied.value = false
  webhookSecretAcknowledged.value = false
  webhookSecretCopyError.value = ''
  if (value) {
    nextTick(() => webhookSecretCopyButton.value?.focus())
  } else if (previousValue) {
    restoreCredentialFocus('webhookSecret')
  }
}, { immediate: true })

function invokeCredentialAction(kind, action, event) {
  if (kind === 'apiKey') {
    apiKeyTrigger.value = event.currentTarget
  } else {
    webhookSecretTrigger.value = event.currentTarget
  }
  return action()
}

function restoreCredentialFocus(kind) {
  nextTick(() => {
    const trigger = kind === 'apiKey' ? apiKeyTrigger.value : webhookSecretTrigger.value
    const currentAction = kind === 'apiKey' ? apiKeyActionButton.value : webhookSecretActionButton.value
    const target = trigger?.isConnected ? trigger : currentAction
    target?.focus()
  })
}

function handleDialogKeydown(kind, event) {
  if (event.key === 'Escape') {
    event.preventDefault()
    event.stopPropagation()
    return
  }
  if (event.key !== 'Tab') return

  const dialog = kind === 'apiKey' ? apiKeyDialog.value : webhookSecretDialog.value
  if (!dialog) return

  const controls = [...dialog.querySelectorAll('button:not([disabled]), input:not([disabled])')]
  if (!controls.length) return

  const first = controls[0]
  const last = controls[controls.length - 1]
  const active = document.activeElement
  const wrapsBackward = event.shiftKey && (active === first || !dialog.contains(active))
  const wrapsForward = !event.shiftKey && (active === last || !dialog.contains(active))

  if (!wrapsBackward && !wrapsForward) return
  event.preventDefault()
  event.stopPropagation()
  const target = event.shiftKey ? last : first
  target.focus()
}

async function copyOneTime(kind) {
  const value = kind === 'apiKey'
    ? props.oneTimeCredentials.apiKey
    : props.oneTimeCredentials.webhookSecret

  try {
    await navigator.clipboard.writeText(value)
    if (kind === 'apiKey') {
      apiKeyCopied.value = true
      apiKeyCopyError.value = ''
    } else {
      webhookSecretCopied.value = true
      webhookSecretCopyError.value = ''
    }
  } catch {
    if (kind === 'apiKey') {
      apiKeyCopyError.value = 'Copy failed. Try again.'
    } else {
      webhookSecretCopyError.value = 'Copy failed. Try again.'
    }
  }
}
</script>

<style scoped>
.settings-section-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.settings-heading-actions { display: flex; align-items: center; justify-content: flex-end; gap: 8px; }
.settings-guide-trigger { min-height: 28px; padding: 4px 8px; border: 1px solid transparent; border-radius: 7px; color: var(--el-color-primary); background: transparent; font: inherit; font-size: 10px; font-weight: 700; cursor: pointer; }
.settings-guide-trigger:hover { border-color: color-mix(in srgb, var(--el-color-primary) 28%, var(--fchub-border-color)); background: color-mix(in srgb, var(--el-color-primary) 7%, var(--fchub-card-bg)); }
.settings-guide-trigger:focus-visible { outline: 2px solid var(--el-color-primary); outline-offset: 2px; }
.settings-status, .credential-state { display: inline-flex; align-items: center; width: fit-content; padding: 4px 9px; border-radius: 999px; color: var(--fchub-text-secondary); background: var(--fchub-page-bg); font-size: 11px; font-weight: 700; }
.settings-status.is-ready { color: #19733f; background: color-mix(in srgb, var(--el-color-success) 14%, var(--fchub-card-bg)); }
.settings-status.is-needs_setup { color: #785b18; background: color-mix(in srgb, var(--el-color-warning) 15%, var(--fchub-card-bg)); }
.settings-status.is-degraded { color: var(--el-color-danger); background: color-mix(in srgb, var(--el-color-danger) 10%, var(--fchub-card-bg)); }
.settings-inline-error { margin: 0 18px 4px; padding: 10px 12px; border: 1px solid color-mix(in srgb, var(--el-color-danger) 32%, transparent); border-radius: 8px; color: var(--el-color-danger); background: color-mix(in srgb, var(--el-color-danger) 6%, var(--fchub-card-bg)); font-size: 12px; }
.settings-switch-control, .settings-credential-control { display: grid; justify-items: start; gap: 9px; }
.settings-switch-control small { color: var(--fchub-text-secondary); font-size: 11px; line-height: 1.4; }
.settings-switch { display: inline-flex; cursor: pointer; }
.settings-switch input { position: absolute; width: 1px; height: 1px; opacity: 0; }
.settings-switch span { position: relative; width: 38px; height: 22px; border-radius: 999px; background: var(--fchub-border-color); transition: background .16s ease; }
.settings-switch span::after { content: ''; position: absolute; top: 3px; left: 3px; width: 16px; height: 16px; border-radius: 50%; background: #fff; box-shadow: 0 1px 3px rgb(0 0 0 / 20%); transition: transform .16s ease; }
.settings-switch input:checked + span { background: var(--el-color-primary); }
.settings-switch input:checked + span::after { transform: translateX(16px); }
.settings-switch input:focus-visible + span { outline: 2px solid var(--el-color-primary); outline-offset: 2px; }
.settings-switch input:disabled + span { cursor: not-allowed; opacity: .55; }
.settings-textarea { box-sizing: border-box; width: 100%; min-height: 82px; max-width: 100%; padding: 9px 11px; border: 1px solid var(--fchub-border-color); border-radius: 7px; color: var(--fchub-text-primary); background: var(--fchub-card-bg); font: inherit; font-size: 12px; line-height: 1.5; resize: vertical; }
.settings-textarea:focus { border-color: var(--el-color-primary); outline: 2px solid color-mix(in srgb, var(--el-color-primary) 18%, transparent); }
.settings-button { min-height: 32px; padding: 6px 11px; border: 1px solid var(--fchub-border-color); border-radius: 7px; color: var(--fchub-text-primary); background: var(--fchub-card-bg); font: inherit; font-size: 11px; font-weight: 650; cursor: pointer; }
.settings-button:hover:not(:disabled), .settings-button:focus-visible { border-color: var(--el-color-primary); color: var(--el-color-primary); }
.settings-button:focus-visible { outline: 2px solid var(--el-color-primary); outline-offset: 2px; }
.settings-button:disabled { cursor: wait; opacity: .55; }
.settings-button.is-warning { color: #8a4f12; }
.settings-button.is-danger { color: var(--el-color-danger); }
.settings-button.is-primary { border-color: var(--el-color-primary); color: #fff; background: var(--el-color-primary); }
.settings-action-row { display: flex; flex-wrap: wrap; gap: 8px; }
.credential-metadata { display: grid; gap: 6px; width: 100%; margin: 0; }
.credential-metadata div { display: grid; grid-template-columns: 54px minmax(0, 1fr); gap: 8px; }
.credential-metadata dt { color: var(--fchub-text-secondary); font-size: 11px; }
.credential-metadata dd { min-width: 0; margin: 0; color: var(--fchub-text-primary); font: 600 11px/1.4 ui-monospace, SFMono-Regular, Menlo, monospace; overflow-wrap: anywhere; }
.webhook-test-results { display: grid; gap: 6px; width: 100%; margin-top: 9px; }
.webhook-test-result { display: flex; justify-content: space-between; gap: 12px; min-width: 0; padding: 7px 9px; border-radius: 7px; background: var(--fchub-page-bg); font-size: 11px; }
.webhook-test-result span { min-width: 0; overflow-wrap: anywhere; }
.webhook-test-result.is-success strong { color: #19733f; }
.webhook-test-result.is-error strong { color: var(--el-color-danger); }
.settings-delivery-history { margin: 16px 18px 18px; }
.one-time-dialog-backdrop { position: fixed; z-index: 100100; inset: 0; display: grid; place-items: center; padding: 18px; background: rgb(15 23 42 / 55%); }
.one-time-dialog { display: grid; gap: 13px; width: min(100%, 520px); max-height: calc(100vh - 36px); padding: 22px; overflow-y: auto; border-radius: 12px; background: var(--fchub-card-bg, #fff); box-shadow: 0 24px 60px rgb(0 0 0 / 22%); }
.one-time-dialog-kicker { color: var(--el-color-primary); font-size: 10px; font-weight: 750; letter-spacing: .08em; text-transform: uppercase; }
.one-time-dialog h3, .one-time-dialog p { margin: 0; }
.one-time-dialog p { color: var(--fchub-text-secondary); font-size: 12px; line-height: 1.5; }
.one-time-dialog code { box-sizing: border-box; width: 100%; padding: 12px; border-radius: 8px; color: var(--fchub-text-primary); background: var(--fchub-page-bg, #f6f7f8); font-size: 12px; overflow-wrap: anywhere; user-select: all; }
.one-time-acknowledgement { display: flex; align-items: flex-start; gap: 8px; color: var(--fchub-text-primary); font-size: 12px; line-height: 1.45; }
.one-time-acknowledgement input { margin-top: 2px; }
.one-time-copy-error { color: var(--el-color-danger) !important; }

@media (max-width: 600px) {
  .settings-section-heading { align-items: flex-start; }
  .settings-delivery-history { margin: 14px; }
  .webhook-test-result { align-items: flex-start; flex-direction: column; }
  .one-time-dialog { padding: 18px; }
}
</style>
