<template>
  <div class="space-y-6">
    <WelcomeHero />
    <QuickAccessCards />
    <GettingStartedSection />
    <FeaturesGrid />
    <SystemStatus />
    <SupportSection />
  </div>
</template>

<script setup>
import WelcomeHero from './welcome/WelcomeHero.vue'
import QuickAccessCards from './welcome/QuickAccessCards.vue'
import GettingStartedSection from './welcome/GettingStartedSection.vue'
import FeaturesGrid from './welcome/FeaturesGrid.vue'
import SystemStatus from './welcome/SystemStatus.vue'
import SupportSection from './welcome/SupportSection.vue'
</script>
