<template>
  <div>
    <h2 class="text-base font-semibold text-gray-900 mb-4">What You Need</h2>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
      <QuickAccessCard
        v-for="card in cards"
        :key="card.id"
        :icon="card.icon"
        :title="card.title"
        :description="card.description"
      />
    </div>
  </div>
</template>

<script setup>
import { CogIcon, FilmIcon, ChartBarIcon } from '@heroicons/vue/24/outline'
import QuickAccessCard from './QuickAccessCard.vue'

const cards = [
  {
    id: 'settings',
    icon: CogIcon,
    title: 'Settings',
    description: 'Add provider credentials. Set limits. Make it work.',
  },
  {
    id: 'videos',
    icon: FilmIcon,
    title: 'Videos',
    description: 'Check what got uploaded. Provider dashboard shows the rest.',
  },
  {
    id: 'analytics',
    icon: ChartBarIcon,
    title: 'Analytics',
    description: 'Coming eventually. Maybe. Depends on coffee levels.',
  },
]
</script>
