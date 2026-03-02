<template>
  <div class="space-y-6">
    <!-- Header -->
    <div>
      <h2 class="text-xl font-semibold text-gray-900">Upload Settings</h2>
      <p class="mt-1 text-sm text-gray-500">Video upload limits, allowed formats, and other settings. No media library gymnastics.</p>
    </div>

    <!-- Max File Size -->
    <div class="bg-white border border-gray-200 rounded-lg p-6">
      <div class="flex items-center justify-between mb-4">
        <div>
          <h3 class="text-lg font-medium text-gray-900">Maximum File Size</h3>
          <p class="text-sm text-gray-500 mt-1">How big can videos be? Set it here. 1MB to 10GB. Pick your pain threshold.</p>
        </div>
        <div class="text-right">
          <span class="text-2xl font-bold text-primary-600">{{ localSettings.max_file_size_mb }}</span>
          <span class="text-sm text-gray-500 ml-1">MB</span>
        </div>
      </div>

      <div class="mt-6">
        <input
          v-model.number="localSettings.max_file_size_mb"
          type="range"
          min="1"
          max="10000"
          step="1"
          class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer slider"
          :disabled="saving"
          @input="() => {}"
        />
        <div class="flex justify-between text-xs text-gray-500 mt-2">
          <span>1 MB</span>
          <span>500 MB</span>
          <span>1 GB</span>
          <span>5 GB</span>
          <span>10 GB</span>
        </div>
      </div>

      <div class="mt-4 flex gap-2">
        <button
          v-for="preset in fileSizePresets"
          :key="preset.value"
          @click="localSettings.max_file_size_mb = preset.value"
          class="px-3 py-1 text-xs font-medium rounded-md border transition-colors"
          :class="
            localSettings.max_file_size_mb === preset.value
              ? 'bg-primary-50 border-primary-500 text-primary-700'
              : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50'
          "
          :disabled="saving"
        >
          {{ preset.label }}
        </button>
      </div>
    </div>

    <!-- Allowed Formats -->
    <div class="bg-white border border-gray-200 rounded-lg p-6">
      <div class="mb-4">
        <h3 class="text-lg font-medium text-gray-900">Allowed Formats</h3>
        <p class="text-sm text-gray-500 mt-1">Which formats work? Check the boxes. Uncheck the ones that don't. Simple.</p>
      </div>

      <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
        <label
          v-for="format in videoFormats"
          :key="format.value"
          class="flex items-center gap-3 p-4 border rounded-lg cursor-pointer transition-colors"
          :class="
            localSettings.allowed_formats.includes(format.value)
              ? 'border-primary-500 bg-primary-50'
              : 'border-gray-300 hover:border-gray-400'
          "
        >
          <input
            v-model="localSettings.allowed_formats"
            type="checkbox"
            :value="format.value"
            class="w-4 h-4 text-primary-600 rounded focus:ring-primary-500"
            :disabled="saving"
          />
          <div class="flex-1">
            <div class="font-medium text-gray-900">{{ format.label }}</div>
            <div class="text-xs text-gray-500">{{ format.mime }}</div>
          </div>
        </label>
      </div>

      <p v-if="localSettings.allowed_formats.length === 0" class="mt-4 text-sm text-yellow-600">
        ⚠️ Pick at least one format. Zero formats = zero uploads. Math checks out.
      </p>
    </div>

    <!-- Additional Settings -->
    <div class="bg-white border border-gray-200 rounded-lg p-6">
      <h3 class="text-lg font-medium text-gray-900 mb-4">Additional Settings</h3>

      <div class="space-y-4">
        <!-- Enable Upload from Portal -->
        <div class="flex items-center justify-between p-4 border rounded-lg bg-gray-50 hover:bg-gray-100 transition-colors">
          <div class="flex-1 pr-4">
            <div class="font-medium text-gray-900">Enable Upload from Portal</div>
            <div class="text-sm text-gray-500 mt-1">Let users upload videos from FluentCommunity Portal. Direct uploads. No media library.</div>
          </div>
          <label class="relative inline-flex items-center cursor-pointer flex-shrink-0">
            <input
              v-model="localSettings.enable_upload_from_portal"
              type="checkbox"
              class="sr-only peer"
              :disabled="saving"
            />
            <div class="w-14 h-7 bg-gray-300 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-primary-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-6 after:w-6 after:transition-all peer-checked:bg-primary-600 shadow-sm"></div>
          </label>
        </div>

        <!-- Enable Video in Comments -->
        <div class="flex items-center justify-between p-4 border rounded-lg bg-gray-50 hover:bg-gray-100 transition-colors">
          <div class="flex-1 pr-4">
            <div class="font-medium text-gray-900">Enable Video in Comments</div>
            <div class="text-sm text-gray-500 mt-1">Videos in comments? Sure. Uses same limits as posts. No special treatment.</div>
          </div>
          <label class="relative inline-flex items-center cursor-pointer flex-shrink-0">
            <input
              v-model="localSettings.enable_comment_video"
              type="checkbox"
              class="sr-only peer"
              :disabled="saving"
            />
            <div class="w-14 h-7 bg-gray-300 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-primary-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-6 after:w-6 after:transition-all peer-checked:bg-primary-600 shadow-sm"></div>
          </label>
        </div>

        <!-- Auto Publish -->
        <div class="flex items-center justify-between p-4 border rounded-lg bg-gray-50 hover:bg-gray-100 transition-colors">
          <div class="flex-1 pr-4">
            <div class="font-medium text-gray-900">Auto Publish</div>
            <div class="text-sm text-gray-500 mt-1">Publish videos automatically when encoding finishes. Or don't. Your call.</div>
          </div>
          <label class="relative inline-flex items-center cursor-pointer flex-shrink-0">
            <input
              v-model="localSettings.auto_publish"
              type="checkbox"
              class="sr-only peer"
              :disabled="saving"
            />
            <div class="w-14 h-7 bg-gray-300 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-primary-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-6 after:w-6 after:transition-all peer-checked:bg-primary-600 shadow-sm"></div>
          </label>
        </div>

        <!-- Polling Interval -->
        <div class="p-4 border rounded-lg">
          <div class="flex items-center justify-between mb-2">
            <div>
              <div class="font-medium text-gray-900">Status Polling Interval</div>
              <div class="text-sm text-gray-500 mt-1">How often to check if videos are done encoding. 10-300 seconds. Lower = more checks = more API calls.</div>
            </div>
            <div class="text-right">
              <span class="text-xl font-bold text-primary-600">{{ localSettings.polling_interval }}</span>
              <span class="text-sm text-gray-500 ml-1">sec</span>
            </div>
          </div>
          <input
            v-model.number="localSettings.polling_interval"
            type="range"
            min="10"
            max="300"
            step="10"
            class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer slider mt-4"
            :disabled="saving"
            @input="() => {}"
          />
          <div class="flex justify-between text-xs text-gray-500 mt-2">
            <span>10 sec</span>
            <span>60 sec</span>
            <span>120 sec</span>
            <span>300 sec</span>
          </div>
        </div>

        <!-- Max Duration -->
        <div class="p-4 border rounded-lg">
          <div class="flex items-center justify-between mb-2">
            <div>
              <div class="font-medium text-gray-900">Maximum Video Duration</div>
              <div class="text-sm text-gray-500 mt-1">Max video length in seconds. 0 = unlimited. 21600 = 6 hours. Pick your limit.</div>
              <div class="text-xs text-yellow-600 mt-1 italic">
                ⚠️ Honest moment: Duration validation isn't implemented yet. Setting saves but doesn't enforce. Coming soon. Maybe.
              </div>
            </div>
            <div class="text-right">
              <span class="text-xl font-bold text-primary-600">
                {{ localSettings.max_duration_seconds === 0 ? '∞' : formatDuration(localSettings.max_duration_seconds) }}
              </span>
            </div>
          </div>
          <input
            v-model.number="localSettings.max_duration_seconds"
            type="range"
            min="0"
            max="21600"
            step="15"
            class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer slider mt-4"
            :disabled="saving"
            @input="() => {}"
          />
          <div class="flex justify-between text-xs text-gray-500 mt-2">
            <span>Unlimited</span>
            <span>5 min</span>
            <span>30 min</span>
            <span>2 hours</span>
            <span>6 hours</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Actions -->
    <div class="flex items-center justify-between pt-4 border-t border-gray-200">
      <button
        @click="handleReset"
        class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 transition-colors"
        :disabled="saving"
      >
        Reset to Defaults
      </button>
      <button
        @click="handleSave"
        class="px-6 py-2 text-sm font-medium text-white bg-primary-600 rounded-md hover:bg-primary-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
        :disabled="saving || !hasChanges"
      >
        {{ saving ? 'Saving...' : 'Save' }}
      </button>
    </div>

    <!-- Error Message -->
    <div v-if="saveError" class="p-4 bg-red-50 border border-red-200 rounded-lg">
      <div class="flex items-center gap-2">
        <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
        </svg>
        <span class="text-sm font-medium text-red-800">{{ saveError }}</span>
      </div>
    </div>

    <!-- Success Message -->
    <div v-if="saveSuccess" class="p-4 bg-green-50 border border-green-200 rounded-lg">
      <div class="flex items-center gap-2">
        <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
        </svg>
        <span class="text-sm font-medium text-green-800">Settings saved. They work now. Probably.</span>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import streamApi from '../../services/streamApi'

const props = defineProps({
  settings: {
    type: Object,
    default: () => ({}),
  },
  saving: {
    type: Boolean,
    default: false,
  },
  saveResult: {
    type: Object,
    default: null,
  },
})

const emit = defineEmits(['save', 'reset'])

const localSettings = ref({
  max_file_size_mb: 500,
  allowed_formats: ['mp4', 'mov', 'webm', 'avi'],
  max_duration_seconds: 0,
  auto_publish: true,
  polling_interval: 30,
  enable_upload_from_portal: true,
  enable_comment_video: true,
})

const saveSuccess = ref(false)
const saveError = ref(null)

const fileSizePresets = [
  { label: '100 MB', value: 100 },
  { label: '500 MB', value: 500 },
  { label: '1 GB', value: 1024 },
  { label: '2 GB', value: 2048 },
  { label: '5 GB', value: 5120 },
]

const videoFormats = [
  { value: 'mp4', label: 'MP4', mime: 'video/mp4' },
  { value: 'mov', label: 'MOV', mime: 'video/quicktime' },
  { value: 'webm', label: 'WebM', mime: 'video/webm' },
  { value: 'avi', label: 'AVI', mime: 'video/x-msvideo' },
  { value: 'mkv', label: 'MKV', mime: 'video/x-matroska' },
  { value: 'm4v', label: 'M4V', mime: 'video/x-m4v' },
]

const hasChanges = computed(() => {
  if (!props.settings) return false

  return (
    localSettings.value.max_file_size_mb !== (props.settings.max_file_size_mb ?? 500) ||
    JSON.stringify(localSettings.value.allowed_formats.sort()) !== JSON.stringify((props.settings.allowed_formats ?? []).sort()) ||
    localSettings.value.max_duration_seconds !== (props.settings.max_duration_seconds ?? 0) ||
    localSettings.value.auto_publish !== (props.settings.auto_publish ?? true) ||
    localSettings.value.polling_interval !== (props.settings.polling_interval ?? 30) ||
    localSettings.value.enable_upload_from_portal !== (props.settings.enable_upload_from_portal ?? true) ||
    localSettings.value.enable_comment_video !== (props.settings.enable_comment_video ?? true)
  )
})

function formatDuration(seconds) {
  if (seconds === 0) return 'Unlimited'
  if (seconds < 60) return `${seconds}s`
  if (seconds < 3600) return `${Math.round(seconds / 60)}m`
  return `${Math.round(seconds / 3600)}h`
}

watch(
  () => props.settings,
  (newSettings) => {
    if (newSettings) {
      localSettings.value = {
        max_file_size_mb: newSettings.max_file_size_mb ?? 500,
        allowed_formats: [...(newSettings.allowed_formats ?? ['mp4', 'mov', 'webm', 'avi'])],
        max_duration_seconds: newSettings.max_duration_seconds ?? 0,
        auto_publish: newSettings.auto_publish ?? true,
        polling_interval: newSettings.polling_interval ?? 30,
        enable_upload_from_portal: newSettings.enable_upload_from_portal ?? true,
        enable_comment_video: newSettings.enable_comment_video ?? true,
      }
    }
  },
  { immediate: true, deep: true }
)

async function handleSave() {
  if (localSettings.value.allowed_formats.length === 0) {
    alert('Pick at least one format. Zero formats = zero uploads.')
    return
  }

  // Clear previous states
  saveSuccess.value = false
  saveError.value = null
  
  // Emit save event - parent will handle async operation
  emit('save', { ...localSettings.value })
  
  // Don't show success immediately - wait for parent to confirm via saveResult prop
}

// Watch saveResult prop to show success/error
watch(
  () => props.saveResult,
  (result) => {
    if (!result) {
      saveSuccess.value = false
      saveError.value = null
      return
    }
    
    if (result.success) {
      saveSuccess.value = true
      saveError.value = null
      setTimeout(() => {
        saveSuccess.value = false
      }, 3000)
    } else {
      saveSuccess.value = false
      saveError.value = result.message || 'Failed to save settings'
    }
  },
  { immediate: true }
)

async function handleReset() {
  if (!confirm('Reset all upload settings to defaults? This undoes your changes. Sure?')) {
    return
  }

  emit('reset')
}
</script>

<style scoped>
.slider::-webkit-slider-thumb {
  appearance: none;
  width: 20px;
  height: 20px;
  border-radius: 50%;
  background: #3b82f6;
  cursor: pointer;
}

.slider::-moz-range-thumb {
  width: 20px;
  height: 20px;
  border-radius: 50%;
  background: #3b82f6;
  cursor: pointer;
  border: none;
}
</style>

