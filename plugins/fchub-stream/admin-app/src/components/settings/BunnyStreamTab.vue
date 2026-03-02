<template>
  <div class="space-y-6">
    <!-- Header with Status -->
    <div class="bg-white border border-gray-200 rounded-lg shadow-sm p-6">
      <div class="flex items-start justify-between mb-4">
        <div>
          <h3 class="text-lg font-semibold text-gray-900 mb-1">Bunny.net Stream</h3>
          <p class="text-sm text-gray-600">
            Upload videos. Provider processes. Works.
          </p>
        </div>
        <ProviderStatus
          :enabled="config?.bunny?.enabled || false"
          :configured="config?.bunny?.has_api_key || false"
          provider="bunny"
        />
      </div>

      <!-- Configuration Form -->
      <div class="space-y-4">
        <!-- Library ID -->
        <div>
          <label for="bunny_library_id" class="block text-sm font-medium text-gray-700 mb-1">
            Library ID <span class="text-red-500">*</span>
          </label>
          <input
            id="bunny_library_id"
            v-model="formData.library_id"
            type="text"
            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500 disabled:bg-gray-50"
            placeholder="12345"
            :disabled="saving"
          />
          <p class="mt-1 text-xs text-gray-500">
            Bunny Dashboard → Stream → Your Library → Library ID (numeric)
          </p>
        </div>

        <!-- API Key -->
        <div>
          <label for="bunny_api_key" class="block text-sm font-medium text-gray-700 mb-1">
            API Key <span class="text-red-500">*</span>
            <span v-if="hasExistingKey" class="text-gray-500 text-xs font-normal">
              (leave empty to keep existing)
            </span>
          </label>
          <div class="relative">
            <input
              id="bunny_api_key"
              v-model="formData.api_key"
              :type="showKey ? 'text' : 'password'"
              class="w-full px-3 py-2 pr-16 border border-gray-300 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500 disabled:bg-gray-50"
              :placeholder="hasExistingKey ? 'New key or leave empty' : 'Your API key'"
              :disabled="saving"
            />
            <button
              type="button"
              @click="showKey = !showKey"
              class="absolute right-2 top-1/2 -translate-y-1/2 px-2 py-1 text-xs text-gray-600 hover:text-gray-900"
            >
              {{ showKey ? 'Hide' : 'Show' }}
            </button>
          </div>
          <p class="mt-1 text-xs text-gray-500">
            Dashboard → Stream → Your Library → API Key
          </p>
        </div>

        <!-- Collection ID (Optional) -->
        <div>
          <label for="bunny_collection_id" class="block text-sm font-medium text-gray-700 mb-1">
            Collection ID <span class="text-gray-500 text-xs">(optional)</span>
          </label>
          <input
            id="bunny_collection_id"
            v-model="formData.collection_id"
            type="text"
            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500 disabled:bg-gray-50"
            placeholder="abc123-def456-ghi789"
            :disabled="saving"
          />
          <p class="mt-1 text-xs text-gray-500">
            Leave empty to upload to root. Or specify Collection GUID for organization.
          </p>
        </div>

        <!-- Info Box -->
        <div class="p-3 bg-gray-50 rounded-md border border-gray-200">
          <div class="flex items-start gap-2">
            <svg class="w-5 h-5 text-gray-400 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <div class="flex-1">
              <span class="text-sm font-medium text-gray-900">
                Bunny.net doesn't use webhooks
              </span>
              <p class="text-xs text-gray-600 mt-0.5">
                Video status updates via direct API polling. Works fine.
              </p>
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
          <strong>How it works:</strong> Enter Library ID + API Key → Click "Save & Test" → If credentials work, provider enables automatically. That's it.
        </p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import ProviderStatus from './ProviderStatus.vue'

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

const emit = defineEmits(['save', 'test', 'remove', 'toggle-enabled'])

// Form data
const formData = ref({
  library_id: '',
  api_key: '',
  collection_id: '',
  enabled: false,
})

const showKey = ref(false)

// Computed properties
const hasExistingKey = computed(() => props.config?.bunny?.has_api_key || false)
const isConfigured = computed(() => hasExistingKey.value)
const isEnabled = computed(() => props.config?.bunny?.enabled || false)

const canSave = computed(() => {
  const hasLibraryId = formData.value.library_id.trim().length > 0
  const hasNewKey = formData.value.api_key.trim().length > 0

  // If already configured, allow save without new key
  if (hasExistingKey.value) {
    return hasLibraryId
  }

  // If not configured, require both fields
  return hasLibraryId && hasNewKey
})

// Load existing config into form
watch(() => props.config?.bunny, (bunny) => {
  if (bunny) {
    formData.value.library_id = bunny.library_id || ''
    formData.value.collection_id = bunny.collection_id || ''
    formData.value.enabled = bunny.enabled || false
    // Don't load api_key - it's encrypted
  }
}, { immediate: true, deep: true })

// Handlers
const handleSaveAndTest = async () => {
  const configData = {
    bunny: {
      library_id: formData.value.library_id.trim(),
      api_key: formData.value.api_key.trim() || null, // null = keep existing
      collection_id: formData.value.collection_id.trim() || '',
      enabled: formData.value.enabled,
    },
  }

  // Save with test = true
  emit('save', configData, true)

  // Clear key field after save (don't show saved key)
  formData.value.api_key = ''
}

const handleToggleEnabled = () => {
  emit('toggle-enabled')
}

const handleRemove = () => {
  if (confirm('Remove Bunny.net Stream config? Videos won\'t upload if this is your active provider.')) {
    emit('remove')
  }
}
</script>
