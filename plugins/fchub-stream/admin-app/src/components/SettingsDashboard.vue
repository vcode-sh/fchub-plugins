<template>
  <div class="space-y-6">
    <!-- Provider Status Bar -->
    <div v-if="config" class="bg-white border border-gray-200 rounded-lg shadow-sm p-4">
      <!-- No providers enabled -->
      <div v-if="!config.cloudflare?.enabled && !config.bunny?.enabled" class="flex items-center gap-3">
        <svg class="w-5 h-5 text-primary-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
        </svg>
        <div class="flex-1">
          <p class="text-sm font-medium text-gray-900">No provider configured</p>
          <p class="text-xs text-gray-600 mt-0.5">Pick a tab below. Add credentials. Enable. Upload videos.</p>
        </div>
      </div>

      <!-- One provider enabled -->
      <div v-else-if="config.cloudflare?.enabled !== config.bunny?.enabled" class="flex items-center gap-3">
        <svg class="w-5 h-5 text-green-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
        </svg>
        <div class="flex-1">
          <p class="text-sm font-medium text-gray-900">
            Active provider: <span class="text-primary-600">{{ config.provider === 'cloudflare' ? 'Cloudflare Stream' : 'Bunny.net Stream' }}</span>
          </p>
          <p class="text-xs text-gray-600 mt-0.5">Working. Uploads go here.</p>
        </div>
      </div>

      <!-- Both providers enabled -->
      <div v-else class="flex items-center justify-between gap-3">
        <div class="flex items-center gap-3">
          <svg class="w-5 h-5 text-green-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
          </svg>
          <div>
            <p class="text-sm font-medium text-gray-900">
              Active: <span class="text-primary-600">{{ config.provider === 'cloudflare' ? 'Cloudflare Stream' : 'Bunny.net Stream' }}</span>
            </p>
            <p class="text-xs text-gray-600 mt-0.5">Both configured. Switch anytime.</p>
          </div>
        </div>
        <button
          @click="handleProviderChange(config.provider === 'cloudflare' ? 'bunny' : 'cloudflare')"
          :disabled="saving"
          class="px-3 py-1.5 text-xs font-medium text-primary-700 bg-primary-50 border border-primary-200 rounded-md hover:bg-primary-100 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          Switch to {{ config.provider === 'cloudflare' ? 'Bunny' : 'Cloudflare' }}
        </button>
      </div>
    </div>

    <SettingsTabs :tabs="tabs" :default-tab="activeTab" @tab-change="handleTabChange">
      <!-- Cloudflare Stream Tab -->
      <template #tab-cloudflare="{ tab }">
        <CloudflareStreamTab
          v-if="config"
          :config="config"
          :loading="loading"
          :saving="saving"
          @save="handleSave"
          @test="handleTest"
          @remove="handleRemove"
          @toggle-enabled="handleToggleEnabled"
          @webhook-activated="handleWebhookActivated"
        />
        <div v-else class="flex items-center justify-center py-12">
          <div class="text-center">
            <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-primary-600 mx-auto"></div>
            <p class="mt-2 text-sm text-gray-500">Loading configuration...</p>
          </div>
        </div>
      </template>

      <!-- Bunny.net Stream Tab -->
      <template #tab-bunny="{ tab }">
        <BunnyStreamTab
          v-if="config"
          :config="config"
          :loading="loading"
          :saving="saving"
          @save="handleSaveBunny"
          @test="handleTestBunny"
          @remove="handleRemoveBunny"
          @toggle-enabled="handleToggleEnabledBunny"
        />
        <div v-else class="flex items-center justify-center py-12">
          <div class="text-center">
            <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-primary-600 mx-auto"></div>
            <p class="mt-2 text-sm text-gray-500">Loading configuration...</p>
          </div>
        </div>
      </template>

      <!-- Upload Settings Tab -->
      <template #tab-upload-settings="{ tab }">
        <UploadSettingsTab
          v-if="uploadSettings"
          :settings="uploadSettings"
          :saving="saving"
          :save-result="uploadSettingsSaveResult"
          @save="handleSaveUploadSettings"
          @reset="handleResetUploadSettings"
        />
        <div v-else class="flex items-center justify-center py-12">
          <div class="text-center">
            <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-primary-600 mx-auto"></div>
            <p class="mt-2 text-sm text-gray-500">Loading upload settings...</p>
          </div>
        </div>
      </template>

    </SettingsTabs>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { CogIcon } from '@heroicons/vue/24/outline'
import streamApi from '../services/streamApi'
import { logger } from '../utils/logger'
import { useNotifications } from '../composables/useNotifications'
import SettingsTabs from './settings/SettingsTabs.vue'
import CloudflareStreamTab from './settings/CloudflareStreamTab.vue'
import BunnyStreamTab from './settings/BunnyStreamTab.vue'
import UploadSettingsTab from './settings/UploadSettingsTab.vue'

const { success, error, warning } = useNotifications()

const config = ref(null)
const uploadSettings = ref(null)
const loading = ref(true)
const saving = ref(false)
const testResult = ref(null)
const activeTab = ref('cloudflare')
const uploadSettingsSaveResult = ref(null)

// Provider and upload tabs
const tabs = computed(() => [
  {
    id: 'cloudflare',
    label: 'Cloudflare Stream',
    index: 0,
  },
  {
    id: 'bunny',
    label: 'Bunny.net Stream',
    index: 1,
  },
  {
    id: 'upload-settings',
    label: 'Upload Settings',
    index: 2,
    icon: CogIcon,
  },
])

onMounted(async () => {
  await loadConfig()
  await loadUploadSettings()
})

async function loadConfig() {
  try {
    loading.value = true
    const response = await streamApi.getConfig()
    if (response.success) {
      config.value = response.config
    }
  } catch (error) {
      logger.error('Failed to load config:', error)
  } finally {
    loading.value = false
  }
}

async function loadUploadSettings() {
  try {
    // Load upload settings
    const uploadResponse = await streamApi.getUploadSettings()
    // Load comment video settings
    const commentResponse = await streamApi.getCommentVideoSettings()

    if (uploadResponse.success) {
      uploadSettings.value = {
        ...uploadResponse.data,
        // Merge comment video enabled flag
        enable_comment_video: commentResponse.success ? (commentResponse.data?.enabled ?? true) : true,
      }
    }
  } catch (error) {
      logger.error('Failed to load upload settings:', error)
  }
}

async function handleSave(configData, testConnection) {
  try {
    saving.value = true
    testResult.value = null

    // Ensure data is properly formatted
    const plainData = {
      cloudflare: configData.cloudflare || {},
      test_connection: testConnection || false,
    }

    const response = await streamApi.saveCloudflareConfig(plainData, testConnection)

    if (response.success) {
      // Reload config to get updated data
      await loadConfig()

      if (response.test_result) {
        testResult.value = response.test_result
      }

      success('Configuration saved', response.message)
      return { success: true, message: response.message }
    } else {
      throw new Error(response.message || 'Failed to save configuration')
    }
  } catch (err) {
      logger.error('[FCHub Stream] Save error:', err)
      logger.error('[FCHub Stream] Error response:', err.response?.data)

    const errorMessage = err.response?.data?.message || err.message || 'Failed to save configuration'

    // Show error to user
    error('Save failed', errorMessage)

    return {
      success: false,
      message: errorMessage,
    }
  } finally {
    saving.value = false
  }
}

async function handleTest(accountId, apiToken) {
  try {
    loading.value = true
    testResult.value = null

    // If apiToken is null, use saved token from config
    let tokenToUse = apiToken
    if (!tokenToUse && config.value?.cloudflare?.has_api_token) {
      // Token not provided but we have saved token - test will use saved one
      tokenToUse = null // Backend will use saved token
    }

    const response = await streamApi.testCloudflareConnection(accountId, tokenToUse)

    if (response.success) {
      testResult.value = {
        status: response.status,
        message: response.message,
      }

      return {
        success: response.status === 'success',
        message: response.message,
      }
    } else {
      throw new Error(response.message || 'Connection test failed')
    }
  } catch (error) {
    const errorMessage = error.response?.data?.message || error.message || 'Connection test failed'
    testResult.value = {
      status: 'error',
      message: errorMessage,
    }

    return {
      success: false,
      message: errorMessage,
    }
  } finally {
    loading.value = false
  }
}

async function handleRemove() {
  try {
    if (!confirm('Remove Cloudflare config? Credentials gone. Videos stay on Cloudflare.')) {
      return
    }

    saving.value = true
    const response = await streamApi.removeCloudflareConfig()

    if (response.success) {
      await loadConfig()
      testResult.value = null
      success('Configuration removed', response.message)
      return { success: true, message: response.message }
    } else {
      throw new Error(response.message || 'Failed to remove configuration')
    }
  } catch (err) {
    const errorMessage = err.response?.data?.message || err.message || 'Failed to remove configuration'
    error('Remove failed', errorMessage)
    return {
      success: false,
      message: errorMessage,
    }
  } finally {
    saving.value = false
  }
}

async function handleToggleEnabled() {
  try {
    saving.value = true

    // Toggle enabled status
    const newEnabled = !config.value?.cloudflare?.enabled

    const response = await streamApi.updateCloudflareEnabled(newEnabled)

    if (response.success) {
      // Reload config to get updated data
      await loadConfig()
      success('Cloudflare status updated', response.message)
      return { success: true, message: response.message }
    } else {
      throw new Error(response.message || 'Failed to update status')
    }
  } catch (err) {
    const errorMessage = err.response?.data?.message || err.message || 'Failed to update status'
    error('Update failed', errorMessage)
    return {
      success: false,
      message: errorMessage,
    }
  } finally {
    saving.value = false
  }
}

async function handleSaveBunny(configData, testConnection) {
  try {
    saving.value = true
    testResult.value = null

    // Ensure data is properly formatted
    const plainData = {
      bunny: configData.bunny || {},
      test_connection: testConnection || false,
    }

    const response = await streamApi.saveBunnyConfig(plainData, testConnection)

    if (response.success) {
      // Reload config to get updated data
      await loadConfig()

      if (response.test_result) {
        testResult.value = response.test_result
      }

      success('Bunny.net configuration saved', response.message)
      return { success: true, message: response.message }
    } else {
      throw new Error(response.message || 'Failed to save configuration')
    }
  } catch (err) {
      logger.error('[FCHub Stream] Save error:', err)
      logger.error('[FCHub Stream] Error response:', err.response?.data)

    const errorMessage = err.response?.data?.message || err.message || 'Failed to save configuration'

    // Show error to user
    error('Save failed', errorMessage)

    return {
      success: false,
      message: errorMessage,
    }
  } finally {
    saving.value = false
  }
}

async function handleTestBunny(libraryId, apiKey) {
  try {
    loading.value = true
    testResult.value = null

    // If apiKey is null, use saved key from config
    let keyToUse = apiKey
    if (!keyToUse && config.value?.bunny?.has_api_key) {
      // Key not provided but we have saved key - test will use saved one
      keyToUse = null // Backend will use saved key
    }

    const response = await streamApi.testBunnyConnection(libraryId, keyToUse)

    if (response.success) {
      testResult.value = {
        status: response.status,
        message: response.message,
      }

      return {
        success: response.status === 'success',
        message: response.message,
      }
    } else {
      throw new Error(response.message || 'Connection test failed')
    }
  } catch (error) {
    const errorMessage = error.response?.data?.message || error.message || 'Connection test failed'
    testResult.value = {
      status: 'error',
      message: errorMessage,
    }

    return {
      success: false,
      message: errorMessage,
    }
  } finally {
    loading.value = false
  }
}

async function handleRemoveBunny() {
  try {
    if (!confirm('Remove Bunny.net config? Credentials gone. Videos stay on Bunny.')) {
      return
    }

    saving.value = true
    const response = await streamApi.removeBunnyConfig()

    if (response.success) {
      await loadConfig()
      testResult.value = null
      success('Configuration removed', response.message)
      return { success: true, message: response.message }
    } else {
      throw new Error(response.message || 'Failed to remove configuration')
    }
  } catch (err) {
    const errorMessage = err.response?.data?.message || err.message || 'Failed to remove configuration'
    error('Remove failed', errorMessage)
    return {
      success: false,
      message: errorMessage,
    }
  } finally {
    saving.value = false
  }
}

async function handleToggleEnabledBunny() {
  try {
    saving.value = true

    // Toggle enabled status
    const newEnabled = !config.value?.bunny?.enabled

    const response = await streamApi.updateBunnyEnabled(newEnabled)

    if (response.success) {
      // Reload config to get updated data
      await loadConfig()
      success('Bunny.net status updated', response.message)
      return { success: true, message: response.message }
    } else {
      throw new Error(response.message || 'Failed to update status')
    }
  } catch (err) {
    const errorMessage = err.response?.data?.message || err.message || 'Failed to update status'
    error('Update failed', errorMessage)
    return {
      success: false,
      message: errorMessage,
    }
  } finally {
    saving.value = false
  }
}

function handleTabChange(tabId) {
  activeTab.value = tabId
  // Reset test result when switching tabs
  testResult.value = null
}

async function handleProviderChange(provider) {
  if (provider === config.value?.provider) {
    return // Already selected
  }

  // Check if provider is enabled
  if (provider === 'cloudflare' && !config.value?.cloudflare?.enabled) {
    warning('Provider disabled', 'Enable Cloudflare Stream first before selecting it.')
    return
  }

  if (provider === 'bunny' && !config.value?.bunny?.enabled) {
    warning('Provider disabled', 'Enable Bunny.net Stream first before selecting it.')
    return
  }

  try {
    saving.value = true
    const response = await streamApi.updateProvider(provider)

    if (response.success) {
      await loadConfig()
      success('Provider updated', response.message)
      return { success: true, message: response.message }
    } else {
      throw new Error(response.message || 'Failed to update provider')
    }
  } catch (err) {
    const errorMessage = err.response?.data?.message || err.message || 'Failed to update provider'
    error('Update failed', errorMessage)
    return {
      success: false,
      message: errorMessage,
    }
  } finally {
    saving.value = false
  }
}

async function handleWebhookActivated() {
  // Reload config to show updated webhook status
  await loadConfig()
}

async function handleSaveUploadSettings(settings) {
  // Clear previous result
  uploadSettingsSaveResult.value = null

  try {
    saving.value = true

    // Extract comment video enabled flag
    const commentVideoEnabled = settings.enable_comment_video ?? true
    const uploadSettingsData = { ...settings }
    delete uploadSettingsData.enable_comment_video

    // Save upload settings
    const uploadResponse = await streamApi.saveUploadSettings(uploadSettingsData)

    // Save comment video enabled flag separately
    const commentResponse = await streamApi.saveCommentVideoSettings({ enabled: commentVideoEnabled })

    if (uploadResponse.success && commentResponse.success) {
      await loadUploadSettings()

      // Set success result
      uploadSettingsSaveResult.value = {
        success: true,
        message: 'Settings saved successfully'
      }

      // Clear result after 3 seconds
      setTimeout(() => {
        uploadSettingsSaveResult.value = null
      }, 3000)

      return { success: true, message: 'Settings saved successfully' }
    } else {
      throw new Error(uploadResponse.message || commentResponse.message || 'Failed to save settings')
    }
  } catch (error) {
    const errorMessage = error.response?.data?.message || error.message || 'Failed to save settings'

    // Set error result - component will display it
    uploadSettingsSaveResult.value = {
      success: false,
      message: errorMessage
    }

    // Clear error after 5 seconds
    setTimeout(() => {
      uploadSettingsSaveResult.value = null
    }, 5000)

    return {
      success: false,
      message: errorMessage,
    }
  } finally {
    saving.value = false
  }
}

async function handleResetUploadSettings() {
  try {
    saving.value = true

    // Reset both upload settings and comment video settings
    const uploadResponse = await streamApi.resetUploadSettings()
    const commentResponse = await streamApi.resetCommentVideoSettings()

    if (uploadResponse.success && commentResponse.success) {
      await loadUploadSettings()
      success('Settings reset', 'Upload settings reset to defaults')
      return { success: true, message: 'Settings reset to defaults' }
    } else {
      throw new Error(uploadResponse.message || commentResponse.message || 'Failed to reset settings')
    }
  } catch (err) {
    const errorMessage = err.response?.data?.message || err.message || 'Failed to reset settings'
    error('Reset failed', errorMessage)
    return {
      success: false,
      message: errorMessage,
    }
  } finally {
    saving.value = false
  }
}
</script>
