<template>
  <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
    <div class="px-4 py-3 border-b border-gray-200 bg-gray-50">
      <h2 class="text-sm font-semibold text-gray-900 flex items-center gap-2">
        <RocketLaunchIcon class="w-4 h-4 text-primary-600" />
        How to Make It Work
      </h2>
    </div>
    <div class="divide-y divide-gray-200">
      <GettingStartedStep
        v-for="step in steps"
        :key="step.number"
        :number="step.number"
        :title="step.title"
        :description="step.description"
      />
    </div>
  </div>
</template>

<script setup>
import { RocketLaunchIcon } from '@heroicons/vue/24/outline'
import GettingStartedStep from './GettingStartedStep.vue'

const steps = [
  {
    number: 1,
    title: 'Pick a Provider',
    description: 'Cloudflare Stream or Bunny.net. Both work. Pick one.',
  },
  {
    number: 2,
    title: 'Add API Credentials',
    description: 'Getting API keys is tedious. Here\'s the settings page. You\'ll survive.',
  },
  {
    number: 3,
    title: 'Set Upload Limits',
    description: 'Max file size, allowed formats. Basic stuff. Prevents chaos.',
  },
  {
    number: 4,
    title: 'Videos Upload Directly',
    description: 'Users upload. Provider processes. No media library involvement. That\'s it.',
  },
]
</script>
