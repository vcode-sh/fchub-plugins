<template>
  <div class="bg-primary-50 border border-primary-200 rounded-lg p-4">
    <div class="flex items-start gap-3">
      <InformationCircleIcon class="w-5 h-5 text-primary-600 flex-shrink-0 mt-0.5" />
      <div class="flex-1">
        <h3 class="text-sm font-semibold text-primary-900 mb-1">Stuck?</h3>
        <p class="text-xs text-primary-800 mb-3">
          There's documentation. It exists. Provider dashboards are confusing, but here's where to find credentials.
        </p>
        <div class="flex flex-wrap gap-2">
          <SupportButton
            v-for="button in buttons"
            :key="button.id"
            :icon="button.icon"
            :label="button.label"
            :href="button.href"
            :external="button.external"
          />
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import {
  InformationCircleIcon,
  BookOpenIcon,
  CogIcon,
  VideoCameraIcon,
} from '@heroicons/vue/24/outline'
import SupportButton from './SupportButton.vue'

// Build settings URL using WordPress admin URL
const getSettingsUrl = () => {
  // Try to get admin URL from WordPress if available
  if (window.fchubStream?.ajaxUrl) {
    // Extract admin URL from ajaxUrl (admin-ajax.php is in admin directory)
    // ajaxUrl format: https://domain.com/wp-admin/admin-ajax.php
    // We need: https://domain.com/wp-admin/admin.php?page=fchub-stream-settings
    const adminUrl = window.fchubStream.ajaxUrl.replace('/admin-ajax.php', '')
    return `${adminUrl}/admin.php?page=fchub-stream-settings`
  }
  // Fallback: construct relative URL
  return 'admin.php?page=fchub-stream-settings'
}

const buttons = [
  {
    id: 'documentation',
    icon: BookOpenIcon,
    label: 'Documentation',
    href: 'https://github.com/vcode-sh/fchub-plugins',
    external: true, // Explicitly mark as external
  },
  {
    id: 'settings',
    icon: CogIcon,
    label: 'Settings',
    href: getSettingsUrl(),
    external: false, // Internal link - same window
  },
  {
    id: 'upload',
    icon: VideoCameraIcon,
    label: 'Upload Videos',
  },
]
</script>
