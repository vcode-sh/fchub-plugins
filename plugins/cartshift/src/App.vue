<template>
  <div class="cartshift-page-wrap">
    <div class="cartshift-container">
      <TransferSafetyScreen />
    </div>
  </div>
</template>

<script setup>
import { provide } from 'vue';
import TransferSafetyScreen from '@/components/TransferSafetyScreen.vue';
import { useTheme } from '@/composables/useTheme.js';

const theme = useTheme();

provide('theme', theme);
</script>
