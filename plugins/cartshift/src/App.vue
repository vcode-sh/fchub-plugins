<template>
  <div class="cartshift-page-wrap">
    <div class="cartshift-container">
      <GuidedMigrationScreen />
    </div>
  </div>
</template>

<script setup>
import { provide } from 'vue';
import GuidedMigrationScreen from '@/components/GuidedMigrationScreen.vue';
import { useTheme } from '@/composables/useTheme.js';

const theme = useTheme();

provide('theme', theme);
</script>
