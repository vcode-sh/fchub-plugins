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
          <div>
            <span>Content protection</span>
            <strong>{{ restrictionModeLabel }}</strong>
          </div>
        </article>
        <article class="settings-overview-card settings-overview-card--green">
          <el-icon><Message /></el-icon>
          <div>
            <span>Email notifications</span>
            <strong>{{ enabledEmailCount }} of 8 active</strong>
          </div>
        </article>
        <article class="settings-overview-card settings-overview-card--purple">
          <el-icon><Connection /></el-icon>
          <div>
            <span>Connected services</span>
            <strong>{{ enabledConnectionCount }} of 3 active</strong>
          </div>
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
          <el-option
            v-for="category in settingsCategories"
            :key="category.id"
            :label="category.label"
            :value="category.id"
          />
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
            :focus-provider="focusedIntegrationProvider"
          />
          <SettingsWebhooksApiSection
            v-else-if="activeSettingsTab === 'webhooks'"
            :access-api="accessApi"
            :endpoints="webhookEndpoints"
            :endpoint-busy="endpointBusy"
            :one-time-endpoint-secret="oneTimeEndpointSecret"
            :one-time-credentials="oneTimeCredentials"
            :busy="webhookBusy"
            :actions="webhookActions"
            :webhook-error="webhookError"
            :history="webhookHistory"
            @acknowledge-api-key="clearOneTimeApiKey"
            @close-endpoint-secret="clearOneTimeEndpointSecret"
            @refresh-history="refreshWebhookOperationalState"
            @retry-delivery="retryWebhookDelivery"
            @cancel-delivery="cancelWebhookDelivery"
          />
          <SettingsAdvancedSection v-else :form="form" />
        </section>
      </div>

      <Transition name="save-bar">
        <div
          v-if="isDirty"
          class="settings-save-bar"
          role="region"
          aria-label="Unsaved settings"
        >
          <div class="settings-save-copy">
            <span class="settings-save-dot" />
            <div>
              <strong>{{ saving ? 'Saving changes…' : 'Unsaved changes' }}</strong>
              <span>Review and save, or restore the last saved configuration.</span>
            </div>
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
import { useRoute } from 'vue-router'
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
import { activeDeliveryCount } from '@/components/settings/notificationStudioUi.js'
import WorkspacePageHeader from '@/components/workspace/WorkspacePageHeader.vue'
import ListStatePanel from '@/components/workspace/ListStatePanel.vue'
import { useSettingsForm } from '@/composables/settings/useSettingsForm.js'
import { useSettingsIntegrationOptions } from '@/composables/settings/useSettingsIntegrationOptions.js'
import { useWebhookSettingsOperations } from '@/composables/settings/useWebhookSettingsOperations.js'

const route = useRoute()
const form = ref({})
const activeSettingsTab = ref(route.query.category === 'integrations' ? 'integrations' : 'general')
const allowedIntegrationProviders = new Set(['fluentcrm', 'fluent_community'])
const integrations = useSettingsIntegrationOptions({ api, form })

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

const webhooks = useWebhookSettingsOperations({
  settings,
  isActive: () => activeSettingsTab.value === 'webhooks',
  confirm: confirmDestructiveAction,
})

const formState = useSettingsForm({
  form,
  settings,
  beforeHydrate: (data) => integrations.loadPlanOptions(data),
  onSettingsData: webhooks.hydrate,
  afterHydrate: async () => {
    if (activeSettingsTab.value === 'webhooks') webhooks.refreshWebhookOperationalState()
    await integrations.loadSavedOptions()
  },
  onSaveResponse: webhooks.applySaveResponse,
  validateIntegrations: integrations.validateCommunityMappings,
  notify: {
    success: ElMessage.success,
    info: ElMessage.info,
    error: (error) => {
      if (activeSettingsTab.value === 'webhooks') {
        webhooks.webhookError.value = error.message || 'Webhook settings could not be saved.'
      } else {
        ElMessage.error('Failed to save settings: ' + (error.message || 'Unknown error'))
      }
    },
  },
})

const { loading, saving, loadError, validationMessage, settingsReady, isDirty } = formState
const {
  loadingLists,
  fluentcrmLists,
  loadingSpaces,
  fcSpaces,
  spaceSearchError,
  planOptions,
  planOptionsError,
} = integrations
const {
  accessApi,
  webhookEndpoints,
  endpointBusy,
  oneTimeEndpointSecret,
  oneTimeCredentials,
  webhookBusy,
  webhookHistory,
  webhookError,
  hasPendingCredentialAcknowledgement,
} = webhooks

const notificationKeys = [
  'access_granted',
  'access_expiring',
  'access_revoked',
  'membership_paused',
  'membership_resumed',
  'trial_expiring',
  'trial_converted',
  'drip_content_unlocked',
]
const settingsTabModel = computed({
  get: () => activeSettingsTab.value,
  set: selectCategory,
})
const focusedIntegrationProvider = computed(() => (
  activeSettingsTab.value === 'integrations'
  && route.query.category === 'integrations'
  && allowedIntegrationProviders.has(route.query.provider)
    ? route.query.provider
    : ''
))
const enabledEmailCount = computed(() => (
  activeDeliveryCount(form.value.email_delivery, notificationKeys)
))
const enabledConnectionCount = computed(() => [
  form.value.fluentcrm_enabled,
  form.value.fc_enabled,
  webhookEndpoints.value.some((endpoint) => endpoint.status === 'active'),
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
    summary: webhookEndpoints.value.some((endpoint) => endpoint.status === 'active')
      ? `${webhookEndpoints.value.filter((endpoint) => endpoint.status === 'active').length} active`
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
const activeCategory = computed(() => (
  settingsCategories.value.find(({ id }) => id === activeSettingsTab.value)
  ?? settingsCategories.value[0]
))

function selectCategory(category) {
  if (category !== 'webhooks' && hasPendingCredentialAcknowledgement.value) return
  activeSettingsTab.value = category
  formState.clearValidation()
}

async function loadSettings() {
  webhooks.invalidateWebhookRequests()
  webhooks.invalidateCredentialRequests()
  webhooks.clearOneTimeCredentials()
  await formState.loadSettings()
}

async function saveSettings() {
  await formState.saveSettings({
    onValidationError: ({ category }) => {
      activeSettingsTab.value = category
    },
  })
}

function discardChanges() {
  formState.discardChanges()
  webhooks.testResults.value = []
}

watch(activeSettingsTab, (category, previousCategory) => {
  if (
    previousCategory === 'webhooks'
    && category !== 'webhooks'
    && hasPendingCredentialAcknowledgement.value
  ) {
    activeSettingsTab.value = 'webhooks'
    return
  }
  formState.clearValidation()
  if (previousCategory === 'webhooks' || category !== 'webhooks') {
    webhooks.clearOneTimeCredentials()
    webhooks.invalidateWebhookRequests()
    webhooks.invalidateCredentialRequests()
  }
  if (category === 'webhooks' && settingsReady.value) {
    webhooks.refreshWebhookOperationalState()
  }
})
watch(() => route.query.category, (category) => {
  if (category === 'integrations') selectCategory('integrations')
})
onMounted(loadSettings)
onBeforeUnmount(() => {
  webhooks.clearOneTimeCredentials()
  webhooks.invalidateWebhookRequests()
  webhooks.invalidateCredentialRequests()
})

const { searchFluentcrmLists, searchFcSpaces, loadPlanOptions } = integrations
const {
  clearOneTimeApiKey,
  clearOneTimeEndpointSecret,
  refreshWebhookOperationalState,
  retryWebhookDelivery,
  cancelWebhookDelivery,
} = webhooks
const webhookActions = webhooks.actions
</script>

<style scoped src="./Settings.css"></style>
