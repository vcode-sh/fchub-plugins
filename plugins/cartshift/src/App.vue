<template>
  <div class="cartshift-page-wrap">
    <div class="cartshift-container">
      <PreflightScreen v-if="state.screen === 'preflight'" />
      <SelectScreen v-else-if="state.screen === 'select'" />
      <ProgressScreen v-else-if="state.screen === 'progress'" />
      <ResultsScreen v-else-if="state.screen === 'results'" />
    </div>
  </div>
</template>

<script setup>
import { provide, onMounted, onBeforeUnmount } from 'vue';
import { useMigration } from '@/composables/useMigration.js';
import { useTheme } from '@/composables/useTheme.js';
import PreflightScreen from '@/components/PreflightScreen.vue';
import SelectScreen from '@/components/SelectScreen.vue';
import ProgressScreen from '@/components/ProgressScreen.vue';
import ResultsScreen from '@/components/ResultsScreen.vue';

const { state, actions } = useMigration();
const theme = useTheme();

provide('migration', { state, actions });
provide('theme', theme);

onMounted(() => {
  actions.runPreflight();
});
</script>
