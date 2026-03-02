<template>
  <div class="space-y-6">
    <!-- Header with Status -->
    <div class="bg-white border border-gray-200 rounded-lg shadow-sm p-6">
      <div class="flex items-start justify-between mb-4">
        <div>
          <h3 class="text-lg font-semibold text-gray-900 mb-1">Cloudflare Stream</h3>
          <p class="text-sm text-gray-600">
            Direct uploads. Auto-encoding. Works.
          </p>
        </div>
        <ProviderStatus
          :enabled="config?.cloudflare?.enabled || false"
          :configured="config?.cloudflare?.has_api_token || false"
          provider="cloudflare"
        />
      </div>

      <!-- Configuration Form -->
      <div class="space-y-4">
        <!-- Account ID -->
        <div>
          <label for="cloudflare_account_id" class="block text-sm font-medium text-gray-700 mb-1">
            Account ID <span class="text-red-500">*</span>
          </label>
          <input
            id="cloudflare_account_id"
            v-model="formData.account_id"
            type="text"
            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500 disabled:bg-gray-50"
            placeholder="a1b2c3d4e5f6g7h8i9j0"
            :disabled="saving"
          />
          <p class="mt-1 text-xs text-gray-500">
            Cloudflare Dashboard → Account Overview. 32 characters.
          </p>
        </div>

        <!-- API Token -->
        <div>
          <label for="cloudflare_api_token" class="block text-sm font-medium text-gray-700 mb-1">
            API Token <span class="text-red-500">*</span>
            <span v-if="hasExistingToken" class="text-gray-500 text-xs font-normal">
              (leave empty to keep existing)
            </span>
          </label>
          <div class="relative">
            <input
              id="cloudflare_api_token"
              v-model="formData.api_token"
              :type="showToken ? 'text' : 'password'"
              class="w-full px-3 py-2 pr-16 border border-gray-300 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500 disabled:bg-gray-50"
              :placeholder="hasExistingToken ? 'New token or leave empty' : 'Your API token'"
              :disabled="saving"
            />
            <button
              type="button"
              @click="showToken = !showToken"
              class="absolute right-2 top-1/2 -translate-y-1/2 px-2 py-1 text-xs text-gray-600 hover:text-gray-900"
            >
              {{ showToken ? 'Hide' : 'Show' }}
            </button>
          </div>
          <p class="mt-1 text-xs text-gray-500">
            Dashboard → My Profile → API Tokens → Create Token
            <br>
            Permissions: Cloudflare Stream:Edit + Cloudflare Stream:Read
          </p>
        </div>

        <!-- Customer Subdomain -->
        <div>
          <label for="cloudflare_subdomain" class="block text-sm font-medium text-gray-700 mb-1">
            Customer Subdomain <span class="text-red-500">*</span>
          </label>
          <input
            id="cloudflare_subdomain"
            v-model="formData.customer_subdomain"
            type="text"
            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500 disabled:bg-gray-50"
            placeholder="customer-abc123def456"
            :disabled="saving"
          />
          <p class="mt-1 text-xs text-gray-500">
            From embed code: <code class="bg-gray-100 px-1 rounded">https://<strong>customer-abc123</strong>.cloudflarestream.com</code>
          </p>
        </div>

        <!-- Webhook Status -->
        <div class="p-3 bg-gray-50 rounded-md border border-gray-200">
          <div class="flex items-start gap-2">
            <svg v-if="hasWebhook" class="w-5 h-5 text-green-600 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
            </svg>
            <svg v-else class="w-5 h-5 text-gray-400 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <div class="flex-1">
              <div class="flex items-center justify-between gap-2">
                <div>
                  <span class="text-sm font-medium text-gray-900">
                    Webhook {{ hasWebhook ? 'Active' : 'Not Configured' }}
                  </span>
                  <p class="text-xs text-gray-600 mt-0.5">
                    {{ hasWebhook ? 'Receives encoding status updates automatically.' : 'Activate webhook to receive encoding status updates from Cloudflare.' }}
                  </p>
                </div>
                <button
                  v-if="!hasWebhook && isConfigured && isEnabled"
                  type="button"
                  @click="handleActivateWebhook"
                  :disabled="activatingWebhook || saving"
                  class="px-3 py-1.5 text-xs font-medium text-white bg-primary-600 rounded-md hover:bg-primary-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                >
                  {{ activatingWebhook ? 'Activating...' : 'Activate Webhook' }}
                </button>
              </div>
            </div>
          </div>
        </div>

      </div>

      <!-- Actions -->
      <div class="flex items-center gap-3 pt-6 border-t border-gray-200 mt-6">
        <button
          type="button"
          @click="handleSaveAndTest"
          :disabled="!canSave || saving"
          class="px-4 py-2 text-sm font-medium text-white bg-primary-600 rounded-md hover:bg-primary-700 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          {{ saving ? 'Saving...' : 'Save & Test' }}
        </button>

        <button
          v-if="isConfigured"
          type="button"
          @click="handleToggleEnabled"
          :disabled="saving"
          class="px-4 py-2 text-sm font-medium rounded-md border disabled:opacity-50 disabled:cursor-not-allowed"
          :class="isEnabled
            ? 'text-yellow-700 bg-yellow-50 border-yellow-300 hover:bg-yellow-100'
            : 'text-green-700 bg-green-50 border-green-300 hover:bg-green-100'
          "
        >
          {{ isEnabled ? 'Disable' : 'Enable' }}
        </button>

        <button
          v-if="isConfigured"
          type="button"
          @click="handleRemove"
          :disabled="saving"
          class="ml-auto px-4 py-2 text-sm font-medium text-red-700 bg-white border border-red-300 rounded-md hover:bg-red-50 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          Remove Configuration
        </button>
      </div>

      <!-- Help Text -->
      <div class="mt-4 p-3 bg-primary-50 border border-primary-200 rounded-md">
        <p class="text-xs text-primary-800">
          <strong>How it works:</strong> Enter credentials → Click "Save & Test" → If test passes, provider enables automatically → Webhook configures itself. No manual steps. No confusion.
        </p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import ProviderStatus from './ProviderStatus.vue'
import streamApi from '../../services/streamApi'
import { useNotifications } from '../../composables/useNotifications'

const props = defineProps({
  config: {
    type: Object,
    required: true,
  },
  loading: {
    type: Boolean,
    default: false,
  },
  saving: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['save', 'test', 'remove', 'toggle-enabled', 'webhook-activated'])

const { success, error } = useNotifications()
const activatingWebhook = ref(false)

// Form data
const formData = ref({
  account_id: '',
  api_token: '',
  customer_subdomain: '',
  enabled: false,
})

const showToken = ref(false)

// Computed properties
const hasExistingToken = computed(() => props.config?.cloudflare?.has_api_token || false)
const hasWebhook = computed(() => props.config?.cloudflare?.has_webhook_secret || false)
const isConfigured = computed(() => hasExistingToken.value)
const isEnabled = computed(() => props.config?.cloudflare?.enabled || false)

const canSave = computed(() => {
  const hasAccountId = formData.value.account_id.trim().length > 0
  const hasSubdomain = formData.value.customer_subdomain.trim().length > 0
  const hasNewToken = formData.value.api_token.trim().length > 0

  // If already configured, allow save without new token
  if (hasExistingToken.value) {
    return hasAccountId && hasSubdomain
  }

  // If not configured, require all fields
  return hasAccountId && hasSubdomain && hasNewToken
})

// Load existing config into form
watch(() => props.config?.cloudflare, (cloudflare) => {
  if (cloudflare) {
    formData.value.account_id = cloudflare.account_id || ''
    formData.value.customer_subdomain = cloudflare.customer_subdomain || ''
    formData.value.enabled = cloudflare.enabled || false
    // Don't load api_token - it's encrypted
  }
}, { immediate: true, deep: true })

// Handlers
const handleSaveAndTest = async () => {
  const configData = {
    cloudflare: {
      account_id: formData.value.account_id.trim(),
      api_token: formData.value.api_token.trim() || null, // null = keep existing
      customer_subdomain: formData.value.customer_subdomain.trim(),
      enabled: formData.value.enabled,
    },
  }

  // Save with test = true
  emit('save', configData, true)

  // Clear token field after save (don't show saved token)
  formData.value.api_token = ''
}

const handleToggleEnabled = () => {
  emit('toggle-enabled')
}

const handleRemove = () => {
  if (confirm('Remove Cloudflare Stream config? Videos won\'t upload if this is your active provider.')) {
    emit('remove')
  }
}

const handleActivateWebhook = async () => {
  try {
    activatingWebhook.value = true
    const response = await streamApi.activateCloudflareWebhook()
    
    if (response.success) {
      success('Webhook activated', response.message || 'Webhook has been activated successfully.')
      emit('webhook-activated')
    } else {
      throw new Error(response.message || 'Failed to activate webhook')
    }
  } catch (err) {
    const errorMessage = err.response?.data?.message || err.message || 'Failed to activate webhook'
    error('Webhook activation failed', errorMessage)
  } finally {
    activatingWebhook.value = false
  }
}
</script>
