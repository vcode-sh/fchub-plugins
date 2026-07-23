<template>
  <div class="settings-page" v-loading="loading">
    <WorkspacePageHeader
      eyebrow="Plugin configuration"
      title="Settings"
      description="Control access, communication, and connections from one reliable workspace."
    />

    <ListStatePanel
      v-if="loadError"
      kind="error"
      title="Settings are unavailable"
      :description="loadError"
      action-label="Try again"
      @action="loadSettings"
    />

    <template v-else-if="settingsReady">
      <section class="settings-overview" aria-label="Settings overview">
        <article class="settings-overview-card settings-overview-card--blue">
          <el-icon><Lock /></el-icon>
          <div><span>Content protection</span><strong>{{ restrictionModeLabel }}</strong></div>
        </article>
        <article class="settings-overview-card settings-overview-card--green">
          <el-icon><Message /></el-icon>
          <div><span>Email notifications</span><strong>{{ enabledEmailCount }} of 8 active</strong></div>
        </article>
        <article class="settings-overview-card settings-overview-card--purple">
          <el-icon><Connection /></el-icon>
          <div><span>Connected services</span><strong>{{ enabledConnectionCount }} of 3 active</strong></div>
        </article>
      </section>

      <div class="settings-mobile-category">
        <label for="settings-category-select">Settings category</label>
        <el-select
          id="settings-category-select"
          v-model="settingsTabModel"
          aria-label="Settings category"
          style="width: 100%"
        >
          <el-option v-for="category in settingsCategories" :key="category.id" :label="category.label" :value="category.id" />
        </el-select>
      </div>

      <div class="settings-console">
        <aside class="settings-sidebar">
          <div class="settings-sidebar-heading">
            <span>Configuration</span>
            <strong>Choose one area</strong>
          </div>
          <nav class="settings-category-nav" aria-label="Settings categories">
            <button
              v-for="category in settingsCategories"
              :key="category.id"
              type="button"
              class="settings-category-button"
              :class="{ 'is-active': activeSettingsTab === category.id }"
              :aria-current="activeSettingsTab === category.id ? 'page' : undefined"
              @click="selectCategory(category.id)"
            >
              <el-icon><component :is="category.icon" /></el-icon>
              <span class="settings-category-copy">
                <strong>{{ category.label }}</strong>
                <small>{{ category.summary }}</small>
              </span>
              <el-icon class="settings-category-arrow"><ArrowRight /></el-icon>
            </button>
          </nav>
          <div class="settings-sidebar-note">
            <el-icon><InfoFilled /></el-icon>
            <span>Changes affect every membership plan unless a plan overrides them.</span>
          </div>
        </aside>

        <section class="settings-panel" aria-labelledby="settings-panel-title">
          <header class="settings-panel-header">
            <div>
              <p>Settings area</p>
              <h2 id="settings-panel-title">{{ activeCategory.label }}</h2>
              <span>{{ activeCategory.description }}</span>
            </div>
            <el-tag effect="plain" round>{{ activeCategory.summary }}</el-tag>
          </header>

          <div v-if="validationMessage" class="settings-validation" role="alert">
            <el-icon><WarningFilled /></el-icon>
            <span>{{ validationMessage }}</span>
          </div>

          <SettingsGeneralSection v-if="activeSettingsTab === 'general'" :form="form" />
          <SettingsNotificationsSummary v-else-if="activeSettingsTab === 'notifications'" />
          <SettingsIntegrationsSection
            v-else-if="activeSettingsTab === 'integrations'"
            :form="form"
            :plan-options="planOptions"
            :plan-options-error="planOptionsError"
            :loading-lists="loadingLists"
            :fluentcrm-lists="fluentcrmLists"
            :loading-spaces="loadingSpaces"
            :fc-spaces="fcSpaces"
            :space-search-error="spaceSearchError"
            :search-fluentcrm-lists="searchFluentcrmLists"
            :search-fc-spaces="searchFcSpaces"
            :reload-plan-options="loadPlanOptions"
          />
          <SettingsWebhooksApiSection
            v-else-if="activeSettingsTab === 'webhooks'"
            :form="form"
            :access-api="accessApi"
            :webhook-status="webhookStatus"
            :webhook-secret-configured="webhookSecretConfigured"
            :webhook-configuration-valid="webhookConfigurationValid"
            :one-time-credentials="oneTimeCredentials"
            :busy="webhookBusy"
            :actions="webhookActions"
            :test-results="testResults"
            :webhook-error="webhookError"
            :history="webhookHistory"
            @acknowledge-api-key="clearOneTimeApiKey"
            @acknowledge-webhook-secret="clearOneTimeWebhookSecret"
            @refresh-history="refreshWebhookOperationalState"
            @retry-delivery="retryWebhookDelivery"
          />
          <SettingsAdvancedSection v-else :form="form" />
        </section>
      </div>

      <Transition name="save-bar">
        <div v-if="isDirty" class="settings-save-bar" role="region" aria-label="Unsaved settings">
          <div class="settings-save-copy">
            <span class="settings-save-dot" />
            <div><strong>{{ saving ? 'Saving changes…' : 'Unsaved changes' }}</strong><span>Review and save, or restore the last saved configuration.</span></div>
          </div>
          <div class="settings-save-actions">
            <el-button :disabled="saving" @click="discardChanges">Discard</el-button>
            <el-button type="primary" :loading="saving" @click="saveSettings">
              <el-icon><Check /></el-icon>
              Save
            </el-button>
          </div>
        </div>
      </Transition>
    </template>
  </div>
</template>

<script setup>
import { computed, markRaw, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  ArrowRight,
  Bell,
  Check,
  Connection,
  InfoFilled,
  Link,
  Lock,
  Message,
  Setting,
  Tools,
  WarningFilled,
} from '@element-plus/icons-vue'
import api, { settings } from '@/api/index.js'
import SettingsGeneralSection from '@/components/settings/SettingsGeneralSection.vue'
import SettingsNotificationsSummary from '@/components/settings/SettingsNotificationsSummary.vue'
import SettingsIntegrationsSection from '@/components/settings/SettingsIntegrationsSection.vue'
import SettingsWebhooksApiSection from '@/components/settings/SettingsWebhooksApiSection.vue'
import SettingsAdvancedSection from '@/components/settings/SettingsAdvancedSection.vue'
import { createRemoteOptionsLoader, mappedResourceIds } from '@/components/settings/settingsIntegrationUi.js'
import { activeDeliveryCount } from '@/components/settings/notificationStudioUi.js'
import WorkspacePageHeader from '@/components/workspace/WorkspacePageHeader.vue'
import ListStatePanel from '@/components/workspace/ListStatePanel.vue'

const loading = ref(false)
const saving = ref(false)
const apiKeyBusy = ref(false)
const webhookSecretBusy = ref(false)
const revokeApiKeyBusy = ref(false)
const testingWebhook = ref(false)
const testResults = ref([])
const accessApi = ref({ configured: false, prefix: null, rotated_at: null })
const webhookStatus = ref('off')
const webhookSecretConfigured = ref(false)
const webhookDestinationsConfigured = ref(false)
const webhookError = ref('')
const oneTimeCredentials = ref({ apiKey: '', webhookSecret: '' })
const webhookDeliveries = ref([])
const webhookHistoryLoading = ref(false)
const webhookHistoryError = ref('')
const retryingDeliveryId = ref(null)
let webhookRequestVersion = 0
let credentialRequestGeneration = 0
const activeSettingsTab = ref('general')
const savedSnapshot = ref('')
const savedFormSnapshot = ref(null)
const loadError = ref('')
const validationMessage = ref('')
const settingsReady = ref(false)

// FluentCRM remote search state
const loadingLists = ref(false)
const fluentcrmLists = ref([])

// FluentCommunity remote search state
const loadingSpaces = ref(false)
const fcSpaces = ref([])
const spaceSearchError = ref('')

// Plan options for mappings
const planOptions = ref([])
const planOptionsError = ref('')

const emailNotifications = [
  ['access_granted', 'email_access_granted'],
  ['access_expiring', 'email_access_expiring'],
  ['access_revoked', 'email_access_revoked'],
  ['membership_paused', 'email_membership_paused'],
  ['membership_resumed', 'email_membership_resumed'],
  ['trial_expiring', 'email_trial_expiring'],
  ['trial_converted', 'email_trial_converted'],
  ['drip_content_unlocked', 'email_drip_unlocked'],
]

const defaultEmailTheme = () => ({
  logo_url: '',
  primary_color: '#2563eb',
  background_color: '#f3f4f6',
  content_color: '#374151',
  content_width: 600,
  font_family: 'system',
  footer_text: '',
})

const form = ref({
  restriction_mode: 'content_replace',
  default_restriction_message: '',
  restriction_message_paused: '',
  redirect_url: '',
  email_access_granted: true,
  email_access_expiring: true,
  email_expiring_days_before: 7,
  trial_expiry_notice_days: 3,
  email_access_revoked: true,
  email_drip_unlocked: true,
  email_membership_paused: true,
  email_membership_resumed: true,
  email_trial_expiring: true,
  email_trial_converted: true,
  email_templates: {},
  email_theme: defaultEmailTheme(),
  email_delivery: {},
  hide_protected_in_archive: false,
  uninstall_remove_data: false,
  debug_mode: false,
  // Webhooks
  webhook_enabled: false,
  webhook_urls: '',
  // FluentCRM
  fluentcrm_enabled: false,
  fluentcrm_tag_prefix: 'member:',
  fluentcrm_default_list: '',
  fluentcrm_auto_create_tags: true,
  // FluentCommunity
  fc_enabled: false,
  fc_space_mappings: {},
  // Membership Rules
  membership_mode: 'stack',
})

const spaceOptionsLoader = createRemoteOptionsLoader(async (query, include) => {
  const response = await api.get('admin/fc-spaces', { search: query, include })
  return response.data ?? response ?? []
})

const isDirty = computed(() => savedSnapshot.value !== '' && JSON.stringify(buildPayload()) !== savedSnapshot.value)

const credentialMutationBusy = computed(() => (
  apiKeyBusy.value || webhookSecretBusy.value || revokeApiKeyBusy.value
))

const webhookBusy = computed(() => ({
  credentialMutation: credentialMutationBusy.value,
  apiKey: apiKeyBusy.value,
  webhookSecret: webhookSecretBusy.value,
  revokeApiKey: revokeApiKeyBusy.value,
  test: testingWebhook.value,
}))

const webhookHistory = computed(() => ({
  deliveries: webhookDeliveries.value,
  loading: webhookHistoryLoading.value,
  error: webhookHistoryError.value,
  retryingId: retryingDeliveryId.value,
}))

const webhookConfigurationValid = computed(() => {
  const savedUrls = savedFormSnapshot.value?.webhook_urls ?? ''
  return webhookSecretConfigured.value
    && webhookDestinationsConfigured.value
    && form.value.webhook_urls === savedUrls
})

const hasPendingCredentialAcknowledgement = computed(() => Boolean(
  oneTimeCredentials.value.apiKey || oneTimeCredentials.value.webhookSecret
))

const settingsTabModel = computed({
  get: () => activeSettingsTab.value,
  set: (category) => selectCategory(category),
})

const enabledEmailCount = computed(() => activeDeliveryCount(
  form.value.email_delivery,
  emailNotifications.map(([key]) => key),
))

const enabledConnectionCount = computed(() => [
  form.value.fluentcrm_enabled,
  form.value.fc_enabled,
  form.value.webhook_enabled,
].filter(Boolean).length)

const restrictionModeLabel = computed(() => ({
  content_replace: 'Protected message',
  redirect: 'Redirect visitors',
  403: '403 response',
}[form.value.restriction_mode] ?? 'Not configured'))

const settingsCategories = computed(() => [
  {
    id: 'general',
    label: 'General',
    description: 'Set the default protection experience and how plans work together.',
    summary: restrictionModeLabel.value,
    icon: markRaw(Setting),
  },
  {
    id: 'notifications',
    label: 'Notifications',
    description: 'Design, preview, test, and route every membership email.',
    summary: `${enabledEmailCount.value} of 8 active`,
    icon: markRaw(Bell),
  },
  {
    id: 'integrations',
    label: 'Integrations',
    description: 'Connect the tools that should follow membership changes.',
    summary: `${Number(form.value.fluentcrm_enabled) + Number(form.value.fc_enabled)} of 2 connected`,
    icon: markRaw(Connection),
  },
  {
    id: 'webhooks',
    label: 'Webhooks & API',
    description: 'Deliver membership events and manage credentials for external systems.',
    summary: form.value.webhook_enabled
      ? webhookStatusLabel(webhookStatus.value)
      : (accessApi.value.configured ? 'API ready' : 'Not connected'),
    icon: markRaw(Link),
  },
  {
    id: 'advanced',
    label: 'Advanced',
    description: 'Use troubleshooting controls without cluttering everyday settings.',
    summary: form.value.debug_mode ? 'Debug logging on' : 'Production safe',
    icon: markRaw(Tools),
  },
])

const activeCategory = computed(() => settingsCategories.value.find(({ id }) => id === activeSettingsTab.value) ?? settingsCategories.value[0])

async function loadSettings() {
  invalidateWebhookRequests()
  invalidateCredentialRequests()
  clearOneTimeCredentials()
  loading.value = true
  loadError.value = ''
  validationMessage.value = ''
  settingsReady.value = false
  try {
    const settingsRes = await settings.get()
    const data = settingsRes.data ?? settingsRes
    accessApi.value = normaliseAccessApi(data.access_api)
    webhookSecretConfigured.value = data.webhook_secret_configured === true
    webhookDestinationsConfigured.value = data.webhook_destinations_configured === true
    webhookStatus.value = normaliseWebhookStatus(data.webhook_status)
    await loadPlanOptions(data)

    const emailDelivery = { ...(data.email_delivery ?? {}) }
    emailNotifications.forEach(([key, settingKey]) => {
      if (!emailDelivery[key]) {
        emailDelivery[key] = data[settingKey] === 'no' ? 'off' : 'built_in'
      }
    })

    form.value = {
      restriction_mode: data.default_protection_mode ?? 'content_replace',
      default_restriction_message: data.restriction_message_no_access ?? '',
      restriction_message_paused: data.restriction_message_paused ?? '',
      redirect_url: data.default_redirect_url ?? '',
      email_access_granted: data.email_access_granted === 'yes',
      email_access_expiring: data.email_access_expiring === 'yes',
      email_expiring_days_before: data.expiry_warning_days ?? 7,
      trial_expiry_notice_days: data.trial_expiry_notice_days ?? 3,
      email_access_revoked: data.email_access_revoked === 'yes',
      email_drip_unlocked: data.email_drip_unlocked === 'yes',
      email_membership_paused: data.email_membership_paused === 'yes',
      email_membership_resumed: data.email_membership_resumed === 'yes',
      email_trial_expiring: data.email_trial_expiring === 'yes',
      email_trial_converted: data.email_trial_converted === 'yes',
      email_templates: data.email_templates ?? {},
      email_theme: { ...defaultEmailTheme(), ...(data.email_theme ?? {}) },
      email_delivery: emailDelivery,
      hide_protected_in_archive: data.hide_protected_in_archive === 'yes',
      uninstall_remove_data: data.uninstall_remove_data === 'yes',
      debug_mode: data.debug_mode === 'yes',
      // Webhooks
      webhook_enabled: data.webhook_enabled === 'yes',
      webhook_urls: data.webhook_urls ?? '',
      // FluentCRM
      fluentcrm_enabled: data.fluentcrm_enabled === 'yes',
      fluentcrm_tag_prefix: data.fluentcrm_tag_prefix ?? 'member:',
      fluentcrm_default_list: data.fluentcrm_default_list ?? '',
      fluentcrm_auto_create_tags: data.fluentcrm_auto_create_tags !== 'no',
      // FluentCommunity
      fc_enabled: data.fc_enabled === 'yes',
      fc_space_mappings: data.fc_space_mappings ?? {},
      // Membership Rules
      membership_mode: data.membership_mode ?? 'stack',
    }
    savedSnapshot.value = JSON.stringify(buildPayload())
    savedFormSnapshot.value = cloneForm(form.value)
    settingsReady.value = true

    if (activeSettingsTab.value === 'webhooks') {
      refreshWebhookOperationalState()
    }

    // Pre-load FluentCRM lists if a default is set
    if (form.value.fluentcrm_default_list) {
      searchFluentcrmLists('')
    }

    if (mappedResourceIds(form.value.fc_space_mappings)) {
      await searchFcSpaces('')
    }
  } catch (err) {
    loadError.value = err.message || 'The settings service did not return a usable response.'
  } finally {
    loading.value = false
  }
}

function cloneForm(value) {
  return JSON.parse(JSON.stringify(value))
}

async function loadPlanOptions(source = form.value) {
  planOptionsError.value = ''
  const mappingKeys = Object.keys(source.fc_space_mappings ?? {})
    .filter((id) => Boolean(source.fc_space_mappings?.[id]))
  const validPlanIds = mappingKeys.filter((id) => /^[1-9]\d*$/.test(id))
  const invalidRows = mappingKeys
    .filter((id) => !validPlanIds.includes(id))
    .map((id) => ({
      id,
      label: `Invalid saved plan reference “${id}”`,
      value: id,
      status: 'invalid',
    }))

  try {
    const plansRes = await api.get('admin/plans/options', { include: validPlanIds.join(',') })
    const plans = plansRes.data ?? plansRes
    const availablePlans = Array.isArray(plans) ? plans : []
    const returnedPlanIds = new Set(availablePlans.map((plan) => String(plan.id)))
    planOptions.value = [
      ...availablePlans,
      ...validPlanIds
        .filter((id) => !returnedPlanIds.has(id))
        .map((id) => ({ id: Number(id), label: `Unavailable plan #${id}`, value: id, status: 'missing' })),
      ...invalidRows,
    ]
  } catch {
    planOptionsError.value = 'Membership plans could not be loaded. Retry, or clear the saved mappings shown below.'
    planOptions.value = [
      ...validPlanIds.map((id) => ({ id: Number(id), label: `Saved plan #${id}`, value: id, status: 'unavailable' })),
      ...invalidRows,
    ]
  }
}

function selectCategory(category) {
  if (category !== 'webhooks' && hasPendingCredentialAcknowledgement.value) return
  activeSettingsTab.value = category
  validationMessage.value = ''
}

function buildPayload() {
  const f = form.value
  return {
    default_protection_mode: f.restriction_mode,
    restriction_message_no_access: f.default_restriction_message,
    restriction_message_paused: f.restriction_message_paused,
    default_redirect_url: f.redirect_url,
    expiry_warning_days: f.email_expiring_days_before,
    trial_expiry_notice_days: f.trial_expiry_notice_days,
    email_access_granted: f.email_access_granted ? 'yes' : 'no',
    email_access_expiring: f.email_access_expiring ? 'yes' : 'no',
    email_access_revoked: f.email_access_revoked ? 'yes' : 'no',
    email_drip_unlocked: f.email_drip_unlocked ? 'yes' : 'no',
    email_membership_paused: f.email_membership_paused ? 'yes' : 'no',
    email_membership_resumed: f.email_membership_resumed ? 'yes' : 'no',
    email_trial_expiring: f.email_trial_expiring ? 'yes' : 'no',
    email_trial_converted: f.email_trial_converted ? 'yes' : 'no',
    email_templates: f.email_templates,
    email_theme: f.email_theme,
    email_delivery: f.email_delivery,
    hide_protected_in_archive: f.hide_protected_in_archive ? 'yes' : 'no',
    uninstall_remove_data: f.uninstall_remove_data ? 'yes' : 'no',
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
    // Membership Rules
    membership_mode: f.membership_mode,
  }
}

async function saveSettings() {
  const validation = validateSettings()
  if (validation) {
    activeSettingsTab.value = validation.category
    validationMessage.value = validation.message
    return
  }

  saving.value = true
  validationMessage.value = ''
  try {
    const response = await settings.save(buildPayload())
    const data = response.data ?? response
    if (data.access_api) accessApi.value = normaliseAccessApi(data.access_api)
    if (typeof data.webhook_secret_configured === 'boolean') {
      webhookSecretConfigured.value = data.webhook_secret_configured
    }
    if (typeof data.webhook_destinations_configured === 'boolean') {
      webhookDestinationsConfigured.value = data.webhook_destinations_configured
    }
    if (data.webhook_status) webhookStatus.value = normaliseWebhookStatus(data.webhook_status)
    savedSnapshot.value = JSON.stringify(buildPayload())
    savedFormSnapshot.value = cloneForm(form.value)
    webhookError.value = ''
    ElMessage.success('Settings saved successfully.')
  } catch (err) {
    if (activeSettingsTab.value === 'webhooks') {
      webhookError.value = err.message || 'Webhook settings could not be saved.'
    } else {
      ElMessage.error('Failed to save settings: ' + (err.message || 'Unknown error'))
    }
  } finally {
    saving.value = false
  }
}

function discardChanges() {
  if (!savedFormSnapshot.value) return
  form.value = cloneForm(savedFormSnapshot.value)
  validationMessage.value = ''
  testResults.value = []
  ElMessage.info('Unsaved changes discarded.')
}

function validateSettings() {
  if (form.value.restriction_mode === 'redirect' && !isHttpUrl(form.value.redirect_url)) {
    return {
      category: 'general',
      message: 'Enter a valid HTTP or HTTPS redirect URL.',
    }
  }

  if (form.value.webhook_enabled) {
    const urls = String(form.value.webhook_urls || '').split('\n').map((url) => url.trim()).filter(Boolean)
    if (urls.length === 0 || urls.some((url) => !isHttpUrl(url))) {
      return {
        category: 'webhooks',
        message: 'Enter a valid HTTP or HTTPS URL on each line.',
      }
    }
  }

  const hasCommunityMappings = Object.values(form.value.fc_space_mappings ?? {}).some(Boolean)

  if (form.value.fc_enabled && planOptionsError.value && hasCommunityMappings) {
    return {
      category: 'integrations',
      message: 'Retry loading membership plans, or clear the saved mapping rows before saving.',
    }
  }

  if (form.value.fc_enabled && invalidCommunityMapping(form.value.fc_space_mappings)) {
    return {
      category: 'integrations',
      message: 'Review unavailable FluentCommunity mappings before saving.',
    }
  }

  if (form.value.fc_enabled && unavailableCommunityMapping(
    form.value.fc_space_mappings,
    fcSpaces.value,
    spaceSearchError.value,
  )) {
    return {
      category: 'integrations',
      message: 'Clear or replace FluentCommunity resources that are no longer available.',
    }
  }

  return null
}

function invalidCommunityMapping(mappings = {}) {
  return Object.entries(mappings).some(([planId, resourceId]) => Boolean(resourceId) && (
    !/^[1-9]\d*$/.test(String(planId))
    || !/^\d+$/.test(String(resourceId))
    || Number(planId) <= 0
    || Number(resourceId) <= 0
  ))
}

function unavailableCommunityMapping(mappings = {}, options = [], loadError = '') {
  const selectedIds = Object.values(mappings).map((value) => String(value ?? '')).filter(Boolean)
  if (selectedIds.length === 0) return false
  if (loadError) return true

  const availableIds = new Set(options.map((option) => String(option.id)))
  return selectedIds.some((id) => !availableIds.has(id))
}

function isHttpUrl(value) {
  try {
    const url = new URL(String(value || '').trim())
    return url.protocol === 'http:' || url.protocol === 'https:'
  } catch {
    return false
  }
}

async function generateApiKey() {
  if (credentialMutationBusy.value) return
  await issueApiKey()
}

async function regenerateApiKey() {
  if (credentialMutationBusy.value) return
  const confirmed = await confirmDestructiveAction(
    'This invalidates the current API key immediately. Continue?',
    'Regenerate API key',
    'Regenerate',
  )
  if (!confirmed) return
  await issueApiKey()
}

async function issueApiKey() {
  if (activeSettingsTab.value !== 'webhooks' || credentialMutationBusy.value) return
  const generation = credentialRequestGeneration
  apiKeyBusy.value = true
  webhookError.value = ''
  try {
    const response = await settings.generateApiKey()
    if (!credentialRequestIsCurrent(generation)) return
    const data = response.data ?? response
    if (typeof data.api_key !== 'string' || data.api_key === '') {
      throw new Error('The settings service did not return the new API key.')
    }
    accessApi.value = normaliseAccessApi(data.access_api)
    oneTimeCredentials.value = { apiKey: data.api_key, webhookSecret: '' }
  } catch (err) {
    if (!credentialRequestIsCurrent(generation)) return
    webhookError.value = err.message || 'The API key could not be generated.'
  } finally {
    if (credentialRequestIsCurrent(generation)) apiKeyBusy.value = false
  }
}

async function revokeApiKey() {
  if (credentialMutationBusy.value) return
  const confirmed = await confirmDestructiveAction(
    'This immediately rejects external requests using the current key. Continue?',
    'Revoke API key',
    'Revoke',
  )
  if (!confirmed) return
  if (activeSettingsTab.value !== 'webhooks' || credentialMutationBusy.value) return

  const generation = credentialRequestGeneration
  revokeApiKeyBusy.value = true
  webhookError.value = ''
  clearOneTimeApiKey()
  try {
    const response = await settings.revokeApiKey()
    if (!credentialRequestIsCurrent(generation)) return
    const data = response.data ?? response
    accessApi.value = normaliseAccessApi(data.access_api)
  } catch (err) {
    if (!credentialRequestIsCurrent(generation)) return
    webhookError.value = err.message || 'The API key could not be revoked.'
  } finally {
    if (credentialRequestIsCurrent(generation)) revokeApiKeyBusy.value = false
  }
}

async function generateWebhookSecret() {
  if (credentialMutationBusy.value) return
  await issueWebhookSecret()
}

async function regenerateWebhookSecret() {
  if (credentialMutationBusy.value) return
  const confirmed = await confirmDestructiveAction(
    'This invalidates the current signing secret immediately. Continue?',
    'Regenerate webhook secret',
    'Regenerate',
  )
  if (!confirmed) return
  await issueWebhookSecret()
}

async function issueWebhookSecret() {
  if (activeSettingsTab.value !== 'webhooks' || credentialMutationBusy.value) return
  const generation = credentialRequestGeneration
  webhookSecretBusy.value = true
  webhookError.value = ''
  try {
    const response = await settings.regenerateWebhookSecret()
    if (!credentialRequestIsCurrent(generation)) return
    const data = response.data ?? response
    if (typeof data.webhook_secret !== 'string' || data.webhook_secret === '') {
      throw new Error('The settings service did not return the new webhook secret.')
    }
    webhookSecretConfigured.value = true
    oneTimeCredentials.value = { apiKey: '', webhookSecret: data.webhook_secret }
  } catch (err) {
    if (!credentialRequestIsCurrent(generation)) return
    webhookError.value = err.message || 'The webhook secret could not be generated.'
  } finally {
    if (credentialRequestIsCurrent(generation)) webhookSecretBusy.value = false
  }
}

async function sendTestWebhook() {
  testingWebhook.value = true
  webhookError.value = ''
  testResults.value = []
  try {
    const response = await settings.testWebhook()
    const data = response.data ?? response
    testResults.value = normaliseTestResults(data.results)
    await refreshWebhookOperationalState()
  } catch (err) {
    webhookError.value = err.message || 'The test webhook could not be sent.'
  } finally {
    testingWebhook.value = false
  }
}

async function refreshWebhookOperationalState() {
  if (activeSettingsTab.value !== 'webhooks') return

  const requestVersion = ++webhookRequestVersion
  webhookHistoryLoading.value = true
  webhookHistoryError.value = ''
  webhookError.value = ''

  const [healthResult, historyResult] = await Promise.allSettled([
    settings.getWebhookHealth(),
    settings.listWebhookDeliveries({ page: 1, per_page: 20, status: '' }),
  ])
  if (requestVersion !== webhookRequestVersion || activeSettingsTab.value !== 'webhooks') return

  if (healthResult.status === 'fulfilled') {
    const health = healthResult.value.data ?? healthResult.value
    webhookStatus.value = normaliseWebhookStatus(health.status)
  } else {
    webhookError.value = errorMessage(healthResult.reason, 'Webhook health could not be loaded.')
  }

  if (historyResult.status === 'fulfilled') {
    const history = historyResult.value.data ?? historyResult.value
    webhookDeliveries.value = Array.isArray(history.deliveries) ? history.deliveries.slice(0, 20) : []
  } else {
    webhookHistoryError.value = errorMessage(historyResult.reason, 'Delivery history could not be loaded.')
  }
  webhookHistoryLoading.value = false
}

async function retryWebhookDelivery(deliveryId) {
  retryingDeliveryId.value = deliveryId
  webhookHistoryError.value = ''
  try {
    await settings.retryWebhookDelivery(deliveryId)
    await refreshWebhookOperationalState()
  } catch (err) {
    webhookHistoryError.value = errorMessage(err, 'The delivery could not be retried.')
  } finally {
    retryingDeliveryId.value = null
  }
}

const webhookActions = {
  generateApiKey,
  regenerateApiKey,
  revokeApiKey,
  generateWebhookSecret,
  regenerateWebhookSecret,
  testWebhook: sendTestWebhook,
}

function normaliseAccessApi(value) {
  return {
    configured: value?.configured === true,
    prefix: value?.configured === true ? (value.prefix ?? null) : null,
    rotated_at: value?.configured === true ? (value.rotated_at ?? null) : null,
  }
}

function normaliseWebhookStatus(value) {
  return ['off', 'needs_setup', 'ready', 'degraded'].includes(value) ? value : 'off'
}

function webhookStatusLabel(value) {
  return {
    off: 'Webhooks off',
    needs_setup: 'Needs setup',
    ready: 'Webhooks ready',
    degraded: 'Delivery failures',
  }[value] ?? 'Webhooks off'
}

function normaliseTestResults(results) {
  if (!Array.isArray(results)) return []
  const labels = {
    pending: 'Pending',
    processing: 'Pending',
    retrying: 'Retrying',
    succeeded: 'Delivered',
    failed: 'Failed',
  }

  return results.map((result) => ({
    ...result,
    url: result.destination_url ?? '',
    success: result.status === 'succeeded',
    error: result.status === 'succeeded' ? '' : (labels[result.status] ?? 'Pending'),
  }))
}

async function confirmDestructiveAction(message, title, confirmButtonText) {
  try {
    await ElMessageBox.confirm(message, title, {
      confirmButtonText,
      cancelButtonText: 'Cancel',
      type: 'warning',
    })
    return true
  } catch {
    return false
  }
}

function clearOneTimeApiKey() {
  oneTimeCredentials.value = { ...oneTimeCredentials.value, apiKey: '' }
}

function clearOneTimeWebhookSecret() {
  oneTimeCredentials.value = { ...oneTimeCredentials.value, webhookSecret: '' }
}

function clearOneTimeCredentials() {
  oneTimeCredentials.value = { apiKey: '', webhookSecret: '' }
}

function invalidateWebhookRequests() {
  webhookRequestVersion += 1
  webhookHistoryLoading.value = false
  retryingDeliveryId.value = null
}

function invalidateCredentialRequests() {
  credentialRequestGeneration += 1
  apiKeyBusy.value = false
  webhookSecretBusy.value = false
  revokeApiKeyBusy.value = false
}

function credentialRequestIsCurrent(generation) {
  return generation === credentialRequestGeneration && activeSettingsTab.value === 'webhooks'
}

function errorMessage(error, fallback) {
  return error?.message || fallback
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
  const include = mappedResourceIds(form.value.fc_space_mappings)
  loadingSpaces.value = true
  spaceSearchError.value = ''
  const result = await spaceOptionsLoader.search(query, include)
  if (result.stale) return

  if (result.error) {
    fcSpaces.value = []
    spaceSearchError.value = 'Spaces could not be loaded. Try opening the selector again.'
  } else {
    fcSpaces.value = result.options
  }
  loadingSpaces.value = false
}

watch(activeSettingsTab, (category, previousCategory) => {
  if (previousCategory === 'webhooks' && category !== 'webhooks' && hasPendingCredentialAcknowledgement.value) {
    activeSettingsTab.value = 'webhooks'
    return
  }

  validationMessage.value = ''
  if (previousCategory === 'webhooks' || category !== 'webhooks') {
    clearOneTimeCredentials()
    invalidateWebhookRequests()
    invalidateCredentialRequests()
  }
  if (category === 'webhooks' && settingsReady.value) {
    refreshWebhookOperationalState()
  }
})

onMounted(() => {
  loadSettings()
})

onBeforeUnmount(() => {
  clearOneTimeCredentials()
  invalidateWebhookRequests()
  invalidateCredentialRequests()
})
</script>

<style scoped>
.settings-overview { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
.settings-overview-card { display: flex; align-items: center; gap: 13px; min-width: 0; padding: 15px 16px; border: 1px solid var(--fchub-border-color); border-radius: 12px; background: var(--fchub-card-bg); }
.settings-overview-card > .el-icon { flex: 0 0 auto; width: 36px; height: 36px; border-radius: 10px; font-size: 18px; }
.settings-overview-card--blue > .el-icon { color: var(--el-color-primary); background: color-mix(in srgb, var(--el-color-primary) 12%, var(--fchub-card-bg)); }
.settings-overview-card--green > .el-icon { color: var(--el-color-success); background: color-mix(in srgb, var(--el-color-success) 12%, var(--fchub-card-bg)); }
.settings-overview-card--purple > .el-icon { color: #8b5cf6; background: color-mix(in srgb, #8b5cf6 12%, var(--fchub-card-bg)); }
.settings-overview-card div { min-width: 0; }
.settings-overview-card span, .settings-overview-card strong { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.settings-overview-card span { color: var(--fchub-text-secondary); font-size: 11px; font-weight: 650; letter-spacing: .025em; }
.settings-overview-card strong { margin-top: 3px; color: var(--fchub-text-primary); font-size: 13px; }

.settings-console { display: grid; grid-template-columns: 264px minmax(0, 1fr); align-items: start; overflow: hidden; border: 1px solid var(--fchub-border-color); border-radius: 14px; background: var(--fchub-card-bg); }
.settings-sidebar { align-self: stretch; padding: 18px 12px; border-right: 1px solid var(--fchub-border-color); background: color-mix(in srgb, var(--fchub-page-bg) 58%, var(--fchub-card-bg)); }
.settings-sidebar-heading { padding: 0 10px 14px; }
.settings-sidebar-heading span { display: block; color: var(--el-color-primary); font-size: 10px; font-weight: 750; letter-spacing: .08em; text-transform: uppercase; }
.settings-sidebar-heading strong { display: block; margin-top: 4px; color: var(--fchub-text-primary); font-size: 13px; }
.settings-category-nav { display: grid; gap: 5px; }
.settings-category-button { display: grid; grid-template-columns: 30px minmax(0, 1fr) 16px; align-items: center; gap: 9px; width: 100%; min-height: 58px; padding: 9px 10px; border: 1px solid transparent; border-radius: 10px; color: var(--fchub-text-secondary); background: transparent; font: inherit; text-align: left; cursor: pointer; transition: border-color .18s ease, background .18s ease, color .18s ease; }
.settings-category-button:hover { border-color: var(--fchub-border-color); background: var(--fchub-card-bg); color: var(--fchub-text-primary); }
.settings-category-button:focus-visible { outline: 2px solid var(--el-color-primary); outline-offset: 1px; }
.settings-category-button.is-active { border-color: color-mix(in srgb, var(--el-color-primary) 32%, var(--fchub-border-color)); color: var(--fchub-text-primary); background: color-mix(in srgb, var(--el-color-primary) 8%, var(--fchub-card-bg)); box-shadow: inset 3px 0 0 var(--el-color-primary); }
.settings-category-button > .el-icon:first-child { width: 30px; height: 30px; border-radius: 8px; background: var(--fchub-card-bg); font-size: 15px; }
.settings-category-button.is-active > .el-icon:first-child { color: var(--el-color-primary); background: color-mix(in srgb, var(--el-color-primary) 12%, var(--fchub-card-bg)); }
.settings-category-copy { min-width: 0; }
.settings-category-copy strong, .settings-category-copy small { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.settings-category-copy strong { font-size: 13px; font-weight: 650; }
.settings-category-copy small { margin-top: 3px; color: var(--fchub-text-secondary); font-size: 10px; }
.settings-category-arrow { font-size: 12px; opacity: .55; }
.settings-sidebar-note { display: flex; align-items: flex-start; gap: 8px; margin: 16px 8px 0; padding: 12px; border-top: 1px solid var(--fchub-border-color); color: var(--fchub-text-secondary); font-size: 11px; line-height: 1.45; }
.settings-sidebar-note .el-icon { flex: 0 0 auto; margin-top: 1px; color: var(--el-color-primary); }

.settings-panel { min-width: 0; }
.settings-panel-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 20px; padding: 24px 28px 20px; border-bottom: 1px solid var(--fchub-border-color); }
.settings-panel-header > div { min-width: 0; }
.settings-panel-header p { margin: 0 0 5px; color: var(--el-color-primary); font-size: 10px; font-weight: 750; letter-spacing: .08em; text-transform: uppercase; }
.settings-panel-header h2 { margin: 0; color: var(--fchub-text-primary); font-size: 20px; line-height: 1.2; }
.settings-panel-header span { display: block; max-width: 620px; margin-top: 7px; color: var(--fchub-text-secondary); font-size: 13px; line-height: 1.5; }
.settings-panel-header .el-tag { flex: 0 0 auto; }
.settings-validation { display: flex; align-items: center; gap: 9px; margin: 16px 28px 0; padding: 11px 13px; border: 1px solid color-mix(in srgb, var(--el-color-danger) 38%, var(--fchub-border-color)); border-radius: 9px; color: var(--el-color-danger); background: color-mix(in srgb, var(--el-color-danger) 7%, var(--fchub-card-bg)); font-size: 12px; }
.settings-panel :deep(.fchub-settings-section) { padding: 22px 28px 10px; }
.settings-panel :deep(.fchub-settings-section + .fchub-settings-section) { border-top: 8px solid var(--fchub-page-bg); }
.settings-panel :deep(.fchub-setting-row) { display: grid; grid-template-columns: minmax(190px, 240px) minmax(0, 1fr); gap: 28px; padding: 18px 0; }
.settings-panel :deep(.fchub-setting-label) { padding-top: 1px; }
.settings-panel :deep(.fchub-setting-label h4) { font-weight: 650; }
.settings-panel :deep(.fchub-setting-control) { max-width: 680px; }

.settings-save-bar { position: sticky; bottom: 14px; z-index: 8; display: flex; align-items: center; justify-content: space-between; gap: 20px; width: min(760px, calc(100% - 32px)); margin: 16px 16px 0 auto; padding: 13px 14px; border: 1px solid color-mix(in srgb, var(--el-color-primary) 30%, var(--fchub-border-color)); border-radius: 12px; background: var(--fchub-card-bg); box-shadow: 0 14px 38px rgba(15, 23, 42, .17); }
.settings-save-copy { display: flex; align-items: center; gap: 10px; min-width: 0; }
.settings-save-dot { flex: 0 0 auto; width: 9px; height: 9px; border-radius: 999px; background: var(--el-color-warning); box-shadow: 0 0 0 4px color-mix(in srgb, var(--el-color-warning) 16%, transparent); }
.settings-save-copy strong, .settings-save-copy span { display: block; }
.settings-save-copy strong { color: var(--fchub-text-primary); font-size: 13px; }
.settings-save-copy div > span { margin-top: 2px; color: var(--fchub-text-secondary); font-size: 11px; }
.settings-save-actions { display: flex; align-items: center; gap: 8px; flex: 0 0 auto; }
.settings-save-actions .el-button { margin: 0; }
.save-bar-enter-active, .save-bar-leave-active { transition: opacity .18s ease, transform .18s ease; }
.save-bar-enter-from, .save-bar-leave-to { opacity: 0; transform: translateY(8px); }
.settings-mobile-category { display: none; margin-bottom: 12px; }
.settings-mobile-category label { display: block; margin-bottom: 6px; color: var(--fchub-text-primary); font-size: 12px; font-weight: 650; }

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

@media (max-width: 980px) {
  .settings-console { grid-template-columns: 226px minmax(0, 1fr); }
  .settings-panel :deep(.fchub-setting-row) { grid-template-columns: 1fr; gap: 11px; }
  .settings-save-bar { width: calc(100% - 258px); }
}

@media (max-width: 782px) {
  .settings-overview { grid-template-columns: 1fr; gap: 8px; }
  .settings-overview-card { padding: 12px 13px; }
  .settings-mobile-category { display: block; }
  .settings-console { display: block; overflow: visible; }
  .settings-sidebar { display: none; }
  .settings-panel-header { align-items: flex-start; padding: 20px 18px 16px; }
  .settings-panel-header .el-tag { display: none; }
  .settings-panel :deep(.fchub-settings-section) { padding: 18px 18px 8px; }
  .settings-panel :deep(.fchub-setting-row) { padding: 16px 0; }
  .settings-panel :deep(.fchub-setting-control) { max-width: 100%; }
  .settings-validation { margin: 14px 18px 0; }
  .settings-save-bar { align-items: stretch; flex-direction: column; gap: 12px; width: auto; bottom: 8px; margin: 12px 0 0; }
  .settings-save-actions { display: grid; grid-template-columns: 1fr 1fr; }
  .settings-save-actions .el-button { width: 100%; }
}
</style>
