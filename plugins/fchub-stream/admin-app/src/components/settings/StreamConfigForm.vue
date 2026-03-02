<template>
  <div class="space-y-6">
    <div class="space-y-4">
      <!-- Account ID -->
      <div>
        <label for="account_id" class="block text-sm font-medium text-gray-700 mb-1">
          Cloudflare Account ID
          <span class="text-red-500">*</span>
        </label>
        <input
          id="account_id"
          v-model="formData.cloudflare.account_id"
          type="text"
          class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
          placeholder="a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6"
          :disabled="loading || saving"
        />
        <p class="mt-1 text-xs text-gray-500">
          Find this in Cloudflare Dashboard → Account Overview
        </p>
      </div>

      <!-- API Token -->
      <div>
        <label for="api_token" class="block text-sm font-medium text-gray-700 mb-1">
          Cloudflare API Token
          <span class="text-red-500">*</span>
        </label>
        <div class="relative">
          <input
            id="api_token"
            v-model="formData.cloudflare.api_token"
            :type="showToken ? 'text' : 'password'"
            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 pr-10"
            placeholder="Enter your API token"
            :disabled="loading || saving"
          />
          <button
            type="button"
            class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-500 hover:text-gray-700"
            @click="showToken = !showToken"
          >
            <span class="text-xs">{{ showToken ? 'Hide' : 'Show' }}</span>
          </button>
        </div>
        <p class="mt-1 text-xs text-gray-500">
          Create token in Cloudflare Dashboard → My Profile → API Tokens
          <br />
          Required permissions: Account.Cloudflare Stream:Edit, Account.Cloudflare Stream:Read
        </p>
        <p v-if="config?.cloudflare?.has_api_token" class="mt-1 text-xs text-green-600">
          ✓ API Token is configured
        </p>
      </div>

      <!-- Webhook Secret (Optional) -->
      <div>
        <label for="webhook_secret" class="block text-sm font-medium text-gray-700 mb-1">
          Webhook Secret
          <span class="text-gray-400 text-xs">(Optional)</span>
        </label>
        <input
          id="webhook_secret"
          v-model="formData.cloudflare.webhook_secret"
          type="password"
          class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
          placeholder="Enter webhook secret"
          :disabled="loading || saving"
        />
        <p class="mt-1 text-xs text-gray-500">
          Optional: Used for webhook verification from Cloudflare
        </p>
      </div>

      <!-- Enabled Toggle -->
      <div class="flex items-center">
        <input
          id="enabled"
          v-model="formData.cloudflare.enabled"
          type="checkbox"
          class="h-4 w-4 text-primary-600 focus:ring-primary-500 border-gray-300 rounded"
          :disabled="loading || saving"
        />
        <label for="enabled" class="ml-2 block text-sm text-gray-700">
          Enable Cloudflare Stream
        </label>
      </div>

      <!-- Test Result -->
      <ConnectionTestResult v-if="testResult" :test-result="testResult" />
    </div>

    <!-- Actions -->
    <div class="flex items-center gap-3 pt-4 border-t border-gray-200">
      <button
        type="button"
        class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 disabled:opacity-50 disabled:cursor-not-allowed"
        :disabled="loading || saving || !canTest"
        @click="handleTest"
      >
        Test Connection
      </button>

      <button
        type="button"
        class="px-4 py-2 text-sm font-medium text-white bg-primary-600 border border-transparent rounded-md shadow-sm hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 disabled:opacity-50 disabled:cursor-not-allowed"
        :disabled="loading || saving || !canSave"
        @click="handleSave"
      >
        <span v-if="saving">Saving...</span>
        <span v-else>Save Configuration</span>
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import ConnectionTestResult from './ConnectionTestResult.vue'

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
  testResult: {
    type: Object,
    default: null,
  },
})

const emit = defineEmits(['save', 'test'])

const showToken = ref(false)

const formData = ref({
  cloudflare: {
    account_id: '',
    api_token: '',
    webhook_secret: '',
    enabled: false,
  },
})

// Initialize form data from config
watch(
  () => props.config,
  newConfig => {
    if (newConfig?.cloudflare) {
      formData.value.cloudflare = {
        account_id: newConfig.cloudflare.account_id || '',
        api_token: '', // Never pre-fill token for security
        webhook_secret: '', // Never pre-fill secret
        enabled: newConfig.cloudflare.enabled || false,
      }
    }
  },
  { immediate: true }
)

const canSave = computed(() => {
  return (
    formData.value.cloudflare.account_id.trim() !== '' &&
    formData.value.cloudflare.api_token.trim() !== ''
  )
})

const canTest = computed(() => {
  return (
    formData.value.cloudflare.account_id.trim() !== '' &&
    formData.value.cloudflare.api_token.trim() !== ''
  )
})

function handleSave() {
  emit('save', formData.value, false)
}

function handleTest() {
  emit('test', formData.value.cloudflare.account_id, formData.value.cloudflare.api_token)
}
</script>
