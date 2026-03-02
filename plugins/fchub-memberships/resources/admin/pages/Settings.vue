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
      <!-- General Settings -->
      <div class="fchub-settings-section">
        <div class="fchub-settings-section-title">General Settings</div>

        <div class="fchub-setting-row">
          <div class="fchub-setting-label">
            <h4>Restriction Mode</h4>
            <p>How to handle access for non-members trying to view protected content.</p>
          </div>
          <div class="fchub-setting-control">
            <el-select v-model="form.restriction_mode" style="width: 100%">
              <el-option label="Replace Content with Message" value="content_replace" />
              <el-option label="Redirect to URL" value="redirect" />
              <el-option label="Show 403 Forbidden" value="403" />
            </el-select>
          </div>
        </div>

        <div class="fchub-setting-row">
          <div class="fchub-setting-label">
            <h4>Default Restriction Message</h4>
            <p>Message shown when content is restricted. Supports basic HTML.</p>
          </div>
          <div class="fchub-setting-control">
            <el-input
              v-model="form.default_restriction_message"
              type="textarea"
              :rows="3"
              placeholder="This content is restricted to members only."
            />
          </div>
        </div>

        <div class="fchub-setting-row">
          <div class="fchub-setting-label">
            <h4>Paused Membership Message</h4>
            <p>Message shown when a member's access is paused. Supports basic HTML.</p>
          </div>
          <div class="fchub-setting-control">
            <el-input
              v-model="form.restriction_message_paused"
              type="textarea"
              :rows="2"
              placeholder="Your membership is currently paused. Resume your membership to access this content."
            />
          </div>
        </div>

        <div class="fchub-setting-row" v-if="form.restriction_mode === 'redirect'">
          <div class="fchub-setting-label">
            <h4>Redirect URL</h4>
            <p>Non-members will be redirected to this URL when they try to access protected content.</p>
          </div>
          <div class="fchub-setting-control">
            <el-input
              v-model="form.redirect_url"
              placeholder="https://example.com/membership"
            />
          </div>
        </div>
      </div>

      <!-- Membership Rules -->
      <div class="fchub-settings-section">
        <div class="fchub-settings-section-title">Membership Rules</div>

        <div class="fchub-setting-row">
          <div class="fchub-setting-label">
            <h4>Membership Mode</h4>
            <p>Controls whether users can hold multiple membership plans simultaneously.</p>
          </div>
          <div class="fchub-setting-control">
            <el-select v-model="form.membership_mode" style="width: 100%">
              <el-option label="Allow Stacking (Multiple Plans)" value="stack" />
              <el-option label="Exclusive (One Plan Only)" value="exclusive" />
              <el-option label="Upgrade Only (Level-Based)" value="upgrade_only" />
            </el-select>
            <div class="setting-description" style="margin-top: 8px; font-size: 12px; color: var(--fchub-text-secondary); line-height: 1.5">
              <template v-if="form.membership_mode === 'stack'">
                Users can hold multiple plans at once. Access from all plans is cumulative.
              </template>
              <template v-else-if="form.membership_mode === 'exclusive'">
                Users can only have one active plan. Purchasing a new plan automatically revokes the previous one.
              </template>
              <template v-else-if="form.membership_mode === 'upgrade_only'">
                Users can only move to equal or higher-level plans. Lower-level plans are automatically revoked on upgrade.
              </template>
            </div>
            <el-alert
              v-if="form.membership_mode === 'upgrade_only'"
              type="info"
              :closable="false"
              show-icon
              style="margin-top: 8px"
            >
              Plans are compared by their Level field. Make sure all plans have appropriate levels configured in the Plan Editor.
            </el-alert>
          </div>
        </div>
      </div>

      <!-- Notification Settings -->
      <div class="fchub-settings-section">
        <div class="fchub-settings-section-title">Notifications</div>

        <div class="fchub-setting-row">
          <div class="fchub-setting-label">
            <h4>Email on Access Granted</h4>
            <p>Send an email when a user is granted membership access.</p>
          </div>
          <div class="fchub-setting-control">
            <el-switch v-model="form.email_access_granted" />
          </div>
        </div>

        <div class="fchub-setting-row">
          <div class="fchub-setting-label">
            <h4>Email on Access Expiring</h4>
            <p>Notify members before their access expires.</p>
          </div>
          <div class="fchub-setting-control">
            <div class="control-row">
              <el-switch v-model="form.email_access_expiring" />
              <div v-if="form.email_access_expiring" class="inline-number">
                <span class="inline-label">Warn</span>
                <el-input-number
                  v-model="form.email_expiring_days_before"
                  :min="1"
                  :max="90"
                  size="small"
                />
                <span class="inline-label">days before</span>
              </div>
            </div>
          </div>
        </div>

        <div class="fchub-setting-row">
          <div class="fchub-setting-label">
            <h4>Email on Access Revoked</h4>
            <p>Send an email when a user's membership access is revoked.</p>
          </div>
          <div class="fchub-setting-control">
            <el-switch v-model="form.email_access_revoked" />
          </div>
        </div>

        <div class="fchub-setting-row">
          <div class="fchub-setting-label">
            <h4>Email on Drip Content Unlocked</h4>
            <p>Notify members when new drip content becomes available to them.</p>
          </div>
          <div class="fchub-setting-control">
            <el-switch v-model="form.email_drip_unlocked" />
          </div>
        </div>
      </div>

      <!-- FluentCRM Settings -->
      <div class="fchub-settings-section">
        <div class="fchub-settings-section-title">FluentCRM Integration</div>

        <div class="fchub-setting-row">
          <div class="fchub-setting-label">
            <h4>Enable FluentCRM Sync</h4>
            <p>Automatically sync membership events (grant, revoke, pause, resume, expire) to FluentCRM tags, lists, and custom fields.</p>
          </div>
          <div class="fchub-setting-control">
            <el-switch v-model="form.fluentcrm_enabled" />
          </div>
        </div>

        <template v-if="form.fluentcrm_enabled">
          <div class="fchub-setting-row">
            <div class="fchub-setting-label">
              <h4>Tag Prefix</h4>
              <p>Prefix for auto-created tags. Tags will be named like "member:plan-slug".</p>
            </div>
            <div class="fchub-setting-control">
              <el-input
                v-model="form.fluentcrm_tag_prefix"
                placeholder="member:"
              />
            </div>
          </div>

          <div class="fchub-setting-row">
            <div class="fchub-setting-label">
              <h4>Default List</h4>
              <p>Add active members to this FluentCRM list automatically.</p>
            </div>
            <div class="fchub-setting-control">
              <el-select
                v-model="form.fluentcrm_default_list"
                style="width: 100%"
                placeholder="Select a list..."
                clearable
                filterable
                remote
                :remote-method="searchFluentcrmLists"
                :loading="loadingLists"
              >
                <el-option
                  v-for="list in fluentcrmLists"
                  :key="list.id"
                  :label="list.label"
                  :value="list.id"
                />
              </el-select>
            </div>
          </div>

          <div class="fchub-setting-row">
            <div class="fchub-setting-label">
              <h4>Auto-Create Tags</h4>
              <p>Automatically create FluentCRM tags from plan names when they don't exist.</p>
            </div>
            <div class="fchub-setting-control">
              <el-switch v-model="form.fluentcrm_auto_create_tags" />
            </div>
          </div>
        </template>
      </div>

      <!-- FluentCommunity Settings -->
      <div class="fchub-settings-section">
        <div class="fchub-settings-section-title">FluentCommunity Integration</div>

        <div class="fchub-setting-row">
          <div class="fchub-setting-label">
            <h4>Enable FluentCommunity Sync</h4>
            <p>Sync membership status to FluentCommunity spaces and badges when grants are created, revoked, or expire.</p>
          </div>
          <div class="fchub-setting-control">
            <el-switch v-model="form.fc_enabled" />
          </div>
        </div>

        <template v-if="form.fc_enabled">
          <div class="fchub-setting-row">
            <div class="fchub-setting-label">
              <h4>Plan to Space Mapping</h4>
              <p>Map membership plans to FluentCommunity spaces. Members will be added to the mapped space when granted access.</p>
            </div>
            <div class="fchub-setting-control">
              <div v-if="planOptions.length === 0" class="fchub-empty-note">
                No membership plans found. Create a plan first.
              </div>
              <div v-for="plan in planOptions" :key="'space-' + plan.id" class="mapping-row">
                <span class="mapping-plan-name">{{ plan.title }}</span>
                <el-select
                  v-model="form.fc_space_mappings[plan.id]"
                  style="width: 220px"
                  placeholder="Select space..."
                  clearable
                  filterable
                  remote
                  :remote-method="searchFcSpaces"
                  :loading="loadingSpaces"
                >
                  <el-option
                    v-for="space in fcSpaces"
                    :key="space.id"
                    :label="space.label"
                    :value="space.id"
                  />
                </el-select>
              </div>
            </div>
          </div>

          <div class="fchub-setting-row">
            <div class="fchub-setting-label">
              <h4>Plan to Badge Mapping</h4>
              <p>Assign a FluentCommunity badge when a member is granted a plan.</p>
            </div>
            <div class="fchub-setting-control">
              <div v-if="planOptions.length === 0" class="fchub-empty-note">
                No membership plans found. Create a plan first.
              </div>
              <div v-for="plan in planOptions" :key="'badge-' + plan.id" class="mapping-row">
                <span class="mapping-plan-name">{{ plan.title }}</span>
                <el-select
                  v-model="form.fc_badge_mappings[plan.id]"
                  style="width: 220px"
                  placeholder="Select badge..."
                  clearable
                  filterable
                  remote
                  :remote-method="searchFcBadges"
                  :loading="loadingBadges"
                >
                  <el-option
                    v-for="badge in fcBadges"
                    :key="badge.id"
                    :label="badge.label"
                    :value="badge.id"
                  />
                </el-select>
              </div>
            </div>
          </div>

          <div class="fchub-setting-row">
            <div class="fchub-setting-label">
              <h4>Remove Badge on Revoke</h4>
              <p>Remove the assigned badge when a member's access is revoked or expires.</p>
            </div>
            <div class="fchub-setting-control">
              <el-switch v-model="form.fc_remove_badge_on_revoke" />
            </div>
          </div>
        </template>
      </div>

      <!-- Webhooks -->
      <div class="fchub-settings-section">
        <div class="fchub-settings-section-title">Webhooks</div>

        <div class="fchub-setting-row">
          <div class="fchub-setting-label">
            <h4>Enable Webhooks</h4>
            <p>Send HTTP POST requests to external URLs when membership events occur (grant, revoke, pause, resume, expire).</p>
          </div>
          <div class="fchub-setting-control">
            <el-switch v-model="form.webhook_enabled" />
          </div>
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
              <el-input
                v-model="form.webhook_secret"
                readonly
                placeholder="No secret generated"
              >
                <template #append>
                  <el-button @click="copyWebhookSecret" :icon="CopyDocument">Copy</el-button>
                </template>
              </el-input>
              <el-button
                type="warning"
                size="small"
                plain
                style="margin-top: 8px"
                @click="regenerateWebhookSecret"
                :loading="regeneratingSecret"
              >
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
              <el-button
                @click="sendTestWebhook"
                :loading="testingWebhook"
              >
                Send Test
              </el-button>
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

      <!-- API Settings -->
      <div class="fchub-settings-section">
        <div class="fchub-settings-section-title">API</div>

        <div class="fchub-setting-row">
          <div class="fchub-setting-label">
            <h4>API Key</h4>
            <p>Use this key to authenticate external API requests to the memberships system.</p>
          </div>
          <div class="fchub-setting-control">
            <el-input
              v-model="form.api_key"
              readonly
              placeholder="No API key generated"
            >
              <template #append>
                <el-button @click="copyApiKey" :icon="CopyDocument">Copy</el-button>
              </template>
            </el-input>
            <el-button
              type="warning"
              size="small"
              plain
              style="margin-top: 8px"
              @click="regenerateApiKey"
              :loading="regenerating"
            >
              Regenerate API Key
            </el-button>
          </div>
        </div>
      </div>

      <!-- Advanced -->
      <div class="fchub-settings-section">
        <div class="fchub-settings-section-title">Advanced</div>

        <div class="fchub-setting-row">
          <div class="fchub-setting-label">
            <h4>Debug Mode</h4>
            <p>Enable verbose logging for troubleshooting. Not recommended for production.</p>
          </div>
          <div class="fchub-setting-control">
            <el-switch v-model="form.debug_mode" />
          </div>
        </div>
      </div>

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
import { CopyDocument, Check } from '@element-plus/icons-vue'
import api, { settings } from '@/api/index.js'

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
