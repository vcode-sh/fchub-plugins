<template>
  <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
    <div class="px-4 py-3 border-b border-gray-200 bg-gray-50">
      <h2 class="text-sm font-semibold text-gray-900">Current Setup</h2>
    </div>
    <div class="divide-y divide-gray-200">
      <StatusItem
        v-for="item in statusItems"
        :key="item.id"
        :icon="item.icon"
        :title="item.title"
        :description="item.description"
        :status="item.status"
        :status-type="item.statusType"
      />
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import { ShieldCheckIcon, VideoCameraIcon, CodeBracketIcon } from '@heroicons/vue/24/outline'
import StatusItem from './StatusItem.vue'
import streamApi from '../../services/streamApi'
import { logger } from '../../utils/logger'

const config = ref(null)
const loading = ref(true)

const providerStatus = computed(() => {
  if (!config.value) {
    return 'None (set it up first)'
  }

  const provider = config.value.provider
  const cloudflareEnabled = config.value.cloudflare?.enabled
  const bunnyEnabled = config.value.bunny?.enabled

  if (provider === 'cloudflare' && cloudflareEnabled) {
    return 'Cloudflare Stream'
  }

  if (provider === 'bunny' && bunnyEnabled) {
    return 'Bunny.net Stream'
  }

  return 'None (set it up first)'
})

const statusItems = computed(() => [
  {
    id: 'plugin-status',
    icon: ShieldCheckIcon,
    title: 'Plugin',
    description: 'Installed and running',
    status: 'Active',
    statusType: 'badge',
  },
  {
    id: 'video-provider',
    icon: VideoCameraIcon,
    title: 'Provider',
    description: 'Which streaming service you picked',
    status: providerStatus.value,
    statusType: 'text',
  },
  {
    id: 'version',
    icon: CodeBracketIcon,
    title: 'Version',
    description: 'Current release (updates when I release them)',
    status: 'version',
    statusType: 'component',
  },
])

onMounted(async () => {
  try {
    const configResponse = await streamApi.getConfig()

    if (configResponse.success) {
      config.value = configResponse.config
    }
  } catch (error) {
    logger.error('Failed to load status:', error)
  } finally {
    loading.value = false
  }
})
</script>
