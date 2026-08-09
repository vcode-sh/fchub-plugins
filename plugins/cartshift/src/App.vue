<template>
  <div class="cartshift-page-wrap">
    <div class="cartshift-container">
      <PreflightScreen v-if="state.screen === 'preflight'" />
      <SelectScreen v-else-if="state.screen === 'select'" />
      <MapScreen v-else-if="state.screen === 'map'" />
      <!-- Not a step in the wizard. It is reached from Select and returns
           there, and it never starts a run — which is why it sits beside the
           run screens rather than between them. -->
      <SubscriptionAuditScreen v-else-if="state.screen === 'subscription-audit'" />
      <ProgressScreen v-else-if="state.screen === 'progress'" />
      <ResultsScreen v-else-if="state.screen === 'results'" />
    </div>
  </div>
</template>

<script setup>
import { provide, onMounted, onBeforeUnmount, watch } from 'vue';
import { useMigration } from '@/composables/useMigration.js';
import { useTheme } from '@/composables/useTheme.js';
import PreflightScreen from '@/components/PreflightScreen.vue';
import SelectScreen from '@/components/SelectScreen.vue';
import MapScreen from '@/components/MapScreen.vue';
import SubscriptionAuditScreen from '@/components/SubscriptionAuditScreen.vue';
import ProgressScreen from '@/components/ProgressScreen.vue';
import ResultsScreen from '@/components/ResultsScreen.vue';

const { state, actions } = useMigration();
const theme = useTheme();

provide('migration', { state, actions });
provide('theme', theme);

/**
 * Guard the tab while the browser is the thing driving the migration. Closing
 * it mid-run leaves the server stuck on 'running' until someone resets it.
 * Background runs need no guard — surviving a closed tab is the whole point.
 */
function beforeUnloadHandler(event) {
  event.preventDefault();
  event.returnValue = '';
  return '';
}

function setUnloadGuard(active) {
  if (active) {
    window.addEventListener('beforeunload', beforeUnloadHandler);
  } else {
    window.removeEventListener('beforeunload', beforeUnloadHandler);
  }
}

watch(
  () => state.migrating && !state.background,
  (active) => setUnloadGuard(active)
);

onMounted(() => {
  actions.bootstrap();
});

onBeforeUnmount(() => {
  setUnloadGuard(false);
});
</script>
