<template>
  <div class="min-h-screen bg-gray-50 p-4 sm:p-6">
    <div class="max-w-7xl mx-auto">
      <!-- Compact Header -->
      <header class="bg-white border border-gray-200 rounded-lg shadow-sm mb-4">
        <div class="px-4 sm:px-6">
          <div class="flex justify-between items-center py-3">
            <!-- Logo & Title -->
            <div class="flex items-center gap-2.5">
              <div
                class="w-8 h-8 bg-gradient-to-br from-primary-500 to-primary-600 rounded-lg flex items-center justify-center flex-shrink-0"
              >
                <VideoCameraIcon class="w-5 h-5 text-onprimary" />
              </div>
              <div class="hidden sm:block">
                <h1 class="text-base font-bold text-gray-900 leading-none">{{ pageTitle }}</h1>
                <p class="text-[10px] text-gray-500 mt-0.5">{{ pageSubtitle }}</p>
              </div>
              <h1 class="sm:hidden text-base font-bold text-gray-900">{{ pageTitleShort }}</h1>
            </div>

            <!-- Version Badge -->
            <div class="flex items-center gap-2">
              <span class="text-[10px] px-2 py-1 bg-gray-100 text-gray-600 rounded font-medium">
                <VersionBadge />
              </span>
            </div>
          </div>
        </div>
      </header>

      <!-- Content -->
      <main>
        <slot />
      </main>

      <!-- Footer -->
      <footer class="bg-white border border-gray-200 rounded-lg shadow-sm mt-8">
        <div class="px-4 sm:px-6 py-3">
          <div
            class="flex flex-col sm:flex-row items-center justify-between gap-2 text-[10px] text-gray-500"
          >
            <p>
              FCHub Stream Plugin
              <span class="hidden sm:inline">
                • Version
                <VersionBadge />
              </span>
            </p>
            <div class="flex items-center gap-3">
              <a
                href="https://github.com/vcode-sh/fchub-plugins"
                target="_blank"
                rel="noopener noreferrer"
                class="text-primary-600 hover:text-primary-700 transition-colors"
              >
                Documentation
              </a>
              <span>•</span>
              <a
                href="https://github.com/vcode-sh/fchub-plugins/issues"
                target="_blank"
                rel="noopener noreferrer"
                class="text-primary-600 hover:text-primary-700 transition-colors"
              >
                Support
              </a>
              <span>•</span>
              <a
                href="https://github.com/vcode-sh/fchub-plugins"
                target="_blank"
                rel="noopener noreferrer"
                class="text-primary-600 hover:text-primary-700 transition-colors"
              >
                GitHub
              </a>
            </div>
          </div>
        </div>
      </footer>
    </div>

    <!-- Toast Notifications -->
    <Toast :toasts="toasts" @remove="removeToast" />
  </div>
</template>

<script setup>
import { VideoCameraIcon } from '@heroicons/vue/24/outline'
import VersionBadge from './VersionBadge.vue'
import Toast from './ui/Toast.vue'
import { useNotifications } from '../composables/useNotifications'

const { toasts, removeToast } = useNotifications()

defineProps({
  version: {
    type: String,
    required: true,
  },
  pageTitle: {
    type: String,
    required: true,
  },
  pageSubtitle: {
    type: String,
    required: true,
  },
  pageTitleShort: {
    type: String,
    required: true,
  },
})
</script>
