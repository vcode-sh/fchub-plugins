<template>
  <div class="settings-page" v-loading="loading">
    <div class="page-header">
      <h2 class="fchub-page-title">Settings</h2>
      <el-button type="primary" @click="saveSettings" :loading="saving">
        <el-icon><Check /></el-icon>
        Save
      </el-button>
    </div>

    <div class="fchub-settings-body">
      <SettingsGeneralSection :form="form" />
      <SettingsNotificationsSection :form="form" />
      <SettingsIntegrationsSection
        :form="form"
        :plan-options="planOptions"
        :loading-lists="loadingLists"
        :fluentcrm-lists="fluentcrmLists"
        :loading-spaces="loadingSpaces"
        :fc-spaces="fcSpaces"
        :loading-badges="loadingBadges"
        :fc-badges="fcBadges"
        :search-fluentcrm-lists="searchFluentcrmLists"
        :search-fc-spaces="searchFcSpaces"
        :search-fc-badges="searchFcBadges"
      />
      <SettingsWebhooksApiSection
        :form="form"
        :regenerating="regenerating"
        :regenerating-secret="regeneratingSecret"
        :testing-webhook="testingWebhook"
        :test-results="testResults"
        :copy-api-key="copyApiKey"
        :regenerate-api-key="regenerateApiKey"
        :copy-webhook-secret="copyWebhookSecret"
        :regenerate-webhook-secret="regenerateWebhookSecret"
        :send-test-webhook="sendTestWebhook"
      />
      <SettingsAdvancedSection :form="form" />

      <!-- Footer Save -->
      <div class="fchub-settings-footer">
        <el-button type="primary" @click="saveSettings" :loading="saving">
          <el-icon><Check /></el-icon>
          Save Settings
        </el-button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Check } from '@element-plus/icons-vue'
import api, { settings } from '@/api/index.js'
import SettingsGeneralSection from '@/components/settings/SettingsGeneralSection.vue'
import SettingsNotificationsSection from '@/components/settings/SettingsNotificationsSection.vue'
import SettingsIntegrationsSection from '@/components/settings/SettingsIntegrationsSection.vue'
import SettingsWebhooksApiSection from '@/components/settings/SettingsWebhooksApiSection.vue'
import SettingsAdvancedSection from '@/components/settings/SettingsAdvancedSection.vue'

const loading = ref(false)
const saving = ref(false)
const regenerating = ref(false)
const regeneratingSecret = ref(false)
const testingWebhook = ref(false)
const testResults = ref([])

// FluentCRM remote search state
const loadingLists = ref(false)
const fluentcrmLists = ref([])

// FluentCommunity remote search state
const loadingSpaces = ref(false)
const fcSpaces = ref([])
const loadingBadges = ref(false)
const fcBadges = ref([])

// Plan options for mappings
const planOptions = ref([])

const form = ref({
  restriction_mode: 'content_replace',
  default_restriction_message: '',
  restriction_message_paused: '',
  redirect_url: '',
  email_access_granted: true,
  email_access_expiring: true,
  email_expiring_days_before: 7,
  email_access_revoked: true,
  email_drip_unlocked: true,
  api_key: '',
  debug_mode: false,
  // Webhooks
  webhook_enabled: false,
  webhook_urls: '',
  webhook_secret: '',
  // FluentCRM
  fluentcrm_enabled: false,
  fluentcrm_tag_prefix: 'member:',
  fluentcrm_default_list: '',
  fluentcrm_auto_create_tags: true,
  // FluentCommunity
  fc_enabled: false,
  fc_space_mappings: {},
  fc_badge_mappings: {},
  fc_remove_badge_on_revoke: false,
  // Membership Rules
  membership_mode: 'stack',
})

async function loadSettings() {
  loading.value = true
  try {
    const [settingsRes, plansRes] = await Promise.all([
      settings.get(),
      api.get('admin/plans/options'),
    ])

    const data = settingsRes.data ?? settingsRes
    const plans = plansRes.data ?? plansRes

    planOptions.value = Array.isArray(plans) ? plans : []

    form.value = {
      restriction_mode: data.default_protection_mode ?? 'content_replace',
      default_restriction_message: data.restriction_message_no_access ?? '',
      restriction_message_paused: data.restriction_message_paused ?? '',
      redirect_url: data.default_redirect_url ?? '',
      email_access_granted: data.email_access_granted === 'yes',
      email_access_expiring: data.email_access_expiring === 'yes',
      email_expiring_days_before: data.expiry_warning_days ?? 7,
      email_access_revoked: data.email_access_revoked === 'yes',
      email_drip_unlocked: data.email_drip_unlocked === 'yes',
      api_key: data.api_key ?? '',
      debug_mode: data.debug_mode === 'yes',
      // Webhooks
      webhook_enabled: data.webhook_enabled === 'yes',
      webhook_urls: data.webhook_urls ?? '',
      webhook_secret: data.webhook_secret ?? '',
      // FluentCRM
      fluentcrm_enabled: data.fluentcrm_enabled === 'yes',
      fluentcrm_tag_prefix: data.fluentcrm_tag_prefix ?? 'member:',
      fluentcrm_default_list: data.fluentcrm_default_list ?? '',
      fluentcrm_auto_create_tags: data.fluentcrm_auto_create_tags !== 'no',
      // FluentCommunity
      fc_enabled: data.fc_enabled === 'yes',
      fc_space_mappings: data.fc_space_mappings ?? {},
      fc_badge_mappings: data.fc_badge_mappings ?? {},
      fc_remove_badge_on_revoke: data.fc_remove_badge_on_revoke === 'yes',
      // Membership Rules
      membership_mode: data.membership_mode ?? 'stack',
    }

    // Pre-load FluentCRM lists if a default is set
    if (form.value.fluentcrm_default_list) {
      searchFluentcrmLists('')
    }
  } catch (err) {
    ElMessage.error('Failed to load settings: ' + (err.message || 'Unknown error'))
  } finally {
    loading.value = false
  }
}

function buildPayload() {
  const f = form.value
  return {
    default_protection_mode: f.restriction_mode,
    restriction_message_no_access: f.default_restriction_message,
    restriction_message_paused: f.restriction_message_paused,
    default_redirect_url: f.redirect_url,
    expiry_warning_days: f.email_expiring_days_before,
    email_access_granted: f.email_access_granted ? 'yes' : 'no',
    email_access_expiring: f.email_access_expiring ? 'yes' : 'no',
    email_access_revoked: f.email_access_revoked ? 'yes' : 'no',
    email_drip_unlocked: f.email_drip_unlocked ? 'yes' : 'no',
    debug_mode: f.debug_mode ? 'yes' : 'no',
    // Webhooks
    webhook_enabled: f.webhook_enabled ? 'yes' : 'no',
    webhook_urls: f.webhook_urls,
    // FluentCRM
    fluentcrm_enabled: f.fluentcrm_enabled ? 'yes' : 'no',
    fluentcrm_tag_prefix: f.fluentcrm_tag_prefix,
    fluentcrm_default_list: f.fluentcrm_default_list,
    fluentcrm_auto_create_tags: f.fluentcrm_auto_create_tags ? 'yes' : 'no',
    // FluentCommunity
    fc_enabled: f.fc_enabled ? 'yes' : 'no',
    fc_space_mappings: f.fc_space_mappings,
    fc_badge_mappings: f.fc_badge_mappings,
    fc_remove_badge_on_revoke: f.fc_remove_badge_on_revoke ? 'yes' : 'no',
    // Membership Rules
    membership_mode: f.membership_mode,
  }
}

async function saveSettings() {
  saving.value = true
  try {
    await settings.save(buildPayload())
    ElMessage.success('Settings saved successfully.')
  } catch (err) {
    ElMessage.error('Failed to save settings: ' + (err.message || 'Unknown error'))
  } finally {
    saving.value = false
  }
}

async function copyApiKey() {
  if (!form.value.api_key) {
    ElMessage.warning('No API key to copy.')
    return
  }
  try {
    await navigator.clipboard.writeText(form.value.api_key)
    ElMessage.success('API key copied to clipboard.')
  } catch {
    ElMessage.error('Failed to copy API key.')
  }
}

async function regenerateApiKey() {
  try {
    await ElMessageBox.confirm(
      'This will invalidate the current API key. Any external integrations using the old key will stop working. Continue?',
      'Regenerate API Key',
      {
        confirmButtonText: 'Regenerate',
        cancelButtonText: 'Cancel',
        type: 'warning',
      },
    )
  } catch {
    return
  }

  regenerating.value = true
  try {
    const response = await settings.generateApiKey()
    const data = response.data ?? response
    form.value.api_key = data.api_key ?? form.value.api_key
    ElMessage.success('API key regenerated successfully.')
  } catch (err) {
    ElMessage.error('Failed to regenerate API key: ' + (err.message || 'Unknown error'))
  } finally {
    regenerating.value = false
  }
}

async function copyWebhookSecret() {
  if (!form.value.webhook_secret) {
    ElMessage.warning('No webhook secret to copy.')
    return
  }
  try {
    await navigator.clipboard.writeText(form.value.webhook_secret)
    ElMessage.success('Webhook secret copied to clipboard.')
  } catch {
    ElMessage.error('Failed to copy webhook secret.')
  }
}

async function regenerateWebhookSecret() {
  try {
    await ElMessageBox.confirm(
      'This will invalidate the current webhook secret. External services verifying signatures with the old secret will fail. Continue?',
      'Regenerate Webhook Secret',
      {
        confirmButtonText: 'Regenerate',
        cancelButtonText: 'Cancel',
        type: 'warning',
      },
    )
  } catch {
    return
  }

  regeneratingSecret.value = true
  try {
    const response = await settings.regenerateWebhookSecret()
    const data = response.data ?? response
    form.value.webhook_secret = data.webhook_secret ?? form.value.webhook_secret
    ElMessage.success('Webhook secret regenerated.')
  } catch (err) {
    ElMessage.error('Failed to regenerate webhook secret: ' + (err.message || 'Unknown error'))
  } finally {
    regeneratingSecret.value = false
  }
}

async function sendTestWebhook() {
  testingWebhook.value = true
  testResults.value = []
  try {
    const response = await settings.testWebhook()
    const data = response.data ?? response
    testResults.value = data.results ?? []
    if (data.success) {
      const allOk = testResults.value.every(r => r.success)
      if (allOk) {
        ElMessage.success('All webhook URLs responded successfully.')
      } else {
        ElMessage.warning('Some webhook URLs failed. Check results below.')
      }
    } else {
      ElMessage.error(data.message || 'Failed to send test webhook.')
    }
  } catch (err) {
    ElMessage.error('Failed to send test webhook: ' + (err.message || 'Unknown error'))
  } finally {
    testingWebhook.value = false
  }
}

async function searchFluentcrmLists(query) {
  loadingLists.value = true
  try {
    const res = await api.get('admin/fluentcrm-lists', { search: query })
    fluentcrmLists.value = res.data ?? res ?? []
  } catch {
    fluentcrmLists.value = []
  } finally {
    loadingLists.value = false
  }
}

async function searchFcSpaces(query) {
  loadingSpaces.value = true
  try {
    const res = await api.get('admin/fc-spaces', { search: query })
    fcSpaces.value = res.data ?? res ?? []
  } catch {
    fcSpaces.value = []
  } finally {
    loadingSpaces.value = false
  }
}

async function searchFcBadges(query) {
  loadingBadges.value = true
  try {
    const res = await api.get('admin/fc-badges', { search: query })
    fcBadges.value = res.data ?? res ?? []
  } catch {
    fcBadges.value = []
  } finally {
    loadingBadges.value = false
  }
}

onMounted(() => {
  loadSettings()
})
</script>

<style scoped>
.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
}

/* Inline controls */
.control-row {
  display: flex;
  align-items: center;
  gap: 16px;
}

.inline-number {
  display: flex;
  align-items: center;
  gap: 8px;
}

.inline-label {
  font-size: 13px;
  color: var(--fchub-text-secondary);
  white-space: nowrap;
}

/* Mapping rows */
.mapping-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 8px 0;
  border-bottom: 1px solid var(--el-border-color-lighter);
}

.mapping-row:last-child {
  border-bottom: none;
}

.mapping-plan-name {
  font-size: 13px;
  font-weight: 500;
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.fchub-empty-note {
  font-size: 13px;
  color: var(--fchub-text-secondary);
  padding: 8px 0;
}

/* Webhook test results */
.webhook-test-results {
  margin-top: 12px;
}

.webhook-test-result {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 6px 0;
  font-size: 13px;
}

.webhook-test-result span {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  min-width: 0;
  flex: 1;
}
</style>
