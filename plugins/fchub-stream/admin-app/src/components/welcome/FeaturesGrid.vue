<template>
  <div>
    <h2 class="text-base font-semibold text-gray-900 mb-4">What It Does</h2>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <FeatureCard
        v-for="feature in features"
        :key="feature.id"
        :icon="feature.icon"
        :title="feature.title"
        :description="feature.description"
        :is-special="feature.isSpecial"
      />
    </div>
  </div>
</template>

<script setup>
import { CheckCircleIcon, WrenchScrewdriverIcon } from '@heroicons/vue/24/outline'
import FeatureCard from './FeatureCard.vue'

const features = [
  {
    id: 'direct-upload',
    icon: CheckCircleIcon,
    title: 'Direct Uploads',
    description:
      'Videos upload straight to Cloudflare or Bunny. No media library gymnastics. No manual conversion. Works.',
    isSpecial: false,
  },
  {
    id: 'provider-choice',
    icon: CheckCircleIcon,
    title: 'Two Providers',
    description:
      'Cloudflare Stream or Bunny.net. Pick one. Both work. Two providers is basically an ecosystem, right?',
    isSpecial: false,
  },
  {
    id: 'community-integration',
    icon: CheckCircleIcon,
    title: 'FluentCommunity Integration',
    description:
      'Videos show in feeds. No extra setup needed. Built it that way because I value my sanity.',
    isSpecial: false,
  },
  {
    id: 'upload-management',
    icon: CheckCircleIcon,
    title: 'Upload Control',
    description:
      'Set file size limits. Choose allowed formats. Basic stuff that should be standard but isn\'t.',
    isSpecial: false,
  },
  {
    id: 'automatic-processing',
    icon: CheckCircleIcon,
    title: 'Automatic Processing',
    description:
      'Providers handle encoding. You upload, they process. That\'s their job. This plugin just connects the dots.',
    isSpecial: false,
  },
  {
    id: 'no-media-library',
    icon: WrenchScrewdriverIcon,
    title: 'No Media Library Pain',
    description:
      'Built this because WordPress media library owes me therapy sessions. Zero media library involvement. That\'s the feature.',
    isSpecial: true,
  },
]
</script>
