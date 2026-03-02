<template>
  <div class="bg-gradient-to-br from-primary-600 to-primary-700 rounded-xl shadow-sm p-8">
    <div class="flex items-start justify-between">
      <div class="flex-1">
        <h1 class="text-2xl font-bold mb-2 text-onprimary">Direct video uploads for FluentCommunity</h1>
        <p class="text-onprimarylight text-base max-w-2xl leading-relaxed">
          Cloudflare Stream or Bunny.net. No YouTube detours. No WordPress media library gymnastics. Cloudflare Stream or Bunny.net. Direct uploads.
        </p>

      </div>
      <div class="hidden lg:block">
        <div
          class="w-20 h-20 bg-white/10 backdrop-blur-sm rounded-2xl flex items-center justify-center"
        >
          <VideoCameraIcon class="w-12 h-12 text-onprimary" />
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { VideoCameraIcon } from '@heroicons/vue/24/outline'
</script>
