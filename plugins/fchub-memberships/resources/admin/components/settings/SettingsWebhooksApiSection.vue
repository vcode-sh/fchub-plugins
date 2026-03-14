<template>
  <div class="fchub-settings-section">
    <div class="fchub-settings-section-title">Webhooks</div>

    <div class="fchub-setting-row">
      <div class="fchub-setting-label">
        <h4>Enable Webhooks</h4>
        <p>Send HTTP POST requests to external URLs when membership events occur (grant, revoke, pause, resume, expire).</p>
      </div>
      <div class="fchub-setting-control"><el-switch v-model="form.webhook_enabled" /></div>
    </div>

    <template v-if="form.webhook_enabled">
      <div class="fchub-setting-row">
        <div class="fchub-setting-label">
          <h4>Webhook URLs</h4>
          <p>Enter one URL per line. Each URL will receive a POST request with JSON payload for every membership event.</p>
        </div>
        <div class="fchub-setting-control">
          <el-input
            v-model="form.webhook_urls"
            type="textarea"
            :rows="3"
            placeholder="https://example.com/webhook&#10;https://hooks.zapier.com/..."
          />
        </div>
      </div>

      <div class="fchub-setting-row">
        <div class="fchub-setting-label">
          <h4>Webhook Secret</h4>
          <p>Used to generate HMAC-SHA256 signatures in the X-FCHub-Signature header for verifying webhook authenticity.</p>
        </div>
        <div class="fchub-setting-control">
          <el-input v-model="form.webhook_secret" readonly placeholder="No secret generated">
            <template #append>
              <el-button @click="copyWebhookSecret" :icon="CopyDocument">Copy</el-button>
            </template>
          </el-input>
          <el-button type="warning" size="small" plain style="margin-top: 8px" @click="regenerateWebhookSecret" :loading="regeneratingSecret">
            Regenerate Secret
          </el-button>
        </div>
      </div>

      <div class="fchub-setting-row">
        <div class="fchub-setting-label">
          <h4>Test Webhook</h4>
          <p>Send a test payload to all configured webhook URLs to verify they are reachable.</p>
        </div>
        <div class="fchub-setting-control">
          <el-button @click="sendTestWebhook" :loading="testingWebhook">Send Test</el-button>
          <div v-if="testResults.length > 0" class="webhook-test-results">
            <div
              v-for="(result, idx) in testResults"
              :key="idx"
              class="webhook-test-result"
              :class="{ 'is-success': result.success, 'is-error': !result.success }"
            >
              <span>{{ result.url }}</span>
              <el-tag :type="result.success ? 'success' : 'danger'" size="small">
                {{ result.success ? 'OK' : (result.error || 'Failed') }}
              </el-tag>
            </div>
          </div>
        </div>
      </div>
    </template>
  </div>

  <div class="fchub-settings-section">
    <div class="fchub-settings-section-title">API</div>

    <div class="fchub-setting-row">
      <div class="fchub-setting-label">
        <h4>API Key</h4>
        <p>Use this key to authenticate external API requests to the memberships system.</p>
      </div>
      <div class="fchub-setting-control">
        <el-input v-model="form.api_key" readonly placeholder="No API key generated">
          <template #append>
            <el-button @click="copyApiKey" :icon="CopyDocument">Copy</el-button>
          </template>
        </el-input>
        <el-button type="warning" size="small" plain style="margin-top: 8px" @click="regenerateApiKey" :loading="regenerating">
          Regenerate API Key
        </el-button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { CopyDocument } from '@element-plus/icons-vue'

defineProps({
  form: { type: Object, required: true },
  regenerating: Boolean,
  regeneratingSecret: Boolean,
  testingWebhook: Boolean,
  testResults: { type: Array, default: () => [] },
  copyApiKey: { type: Function, required: true },
  regenerateApiKey: { type: Function, required: true },
  copyWebhookSecret: { type: Function, required: true },
  regenerateWebhookSecret: { type: Function, required: true },
  sendTestWebhook: { type: Function, required: true },
})
</script>
