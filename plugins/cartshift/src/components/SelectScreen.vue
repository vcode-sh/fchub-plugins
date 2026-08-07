<template>
  <div>
    <PageHeader title="Select Entities to Migrate" />
    <p>Choose which WooCommerce data to migrate to FluentCart. Dependencies are respected automatically.</p>

    <table class="widefat striped">
      <thead>
        <tr><th></th><th>Entity</th><th>Count</th><th>Dependencies</th></tr>
      </thead>
      <tbody>
        <tr v-for="entity in entities" :key="entity.key">
          <td>
            <input
              type="checkbox"
              :checked="state.selectedEntities.indexOf(entity.key) !== -1"
              :disabled="entity.key === 'subscription' && !wcsActive"
              @change="toggleEntity(entity.key, $event)"
            />
          </td>
          <td><strong>{{ entity.label }}</strong></td>
          <td>{{ getCount(entity.key) }}</td>
          <td>
            <em v-if="entity.dep">{{ entity.dep }}</em>
            <template v-else>-</template>
          </td>
        </tr>
      </tbody>
    </table>

    <p v-if="!wcsActive" class="description">
      Subscription migration is disabled because WooCommerce Subscriptions is not active.
    </p>

    <div class="cartshift-option-box">
      <label>
        <input type="checkbox" v-model="state.dryRun" />
        <strong>Dry run</strong> &mdash; validate data mapping without writing to FluentCart. Shows what would be migrated.
      </label>
    </div>

    <div class="cartshift-option-box">
      <label>
        <input type="checkbox" v-model="state.useBackground" :disabled="!state.backgroundAvailable" />
        <strong>Run in the background</strong> &mdash; hand the batches to Action Scheduler so the
        migration survives this tab being closed. Progress updates every couple of seconds instead
        of live. Leave this off for a real-time run you intend to watch.
      </label>
      <p v-if="!state.backgroundAvailable" class="description">
        Unavailable: Action Scheduler was not found. It ships with both WooCommerce and FluentCart,
        so this normally means neither is loaded.
      </p>
    </div>

    <p style="margin-top:15px;">
      <button
        class="button button-primary button-hero"
        :disabled="state.migrating"
        @click="actions.startMigration()"
      >
        Start Migration
      </button>
      <button class="button" @click="actions.goToScreen('preflight')">Back</button>
    </p>
  </div>
</template>

<script setup>
import { inject, computed } from 'vue';
import { ENTITIES } from '@/composables/useMigration.js';
import PageHeader from './PageHeader.vue';

const { state, actions } = inject('migration');

const entities = ENTITIES;

const wcsActive = computed(() => {
  return state.preflight?.checks?.wc_subscriptions?.active;
});

function getCount(key) {
  if (!state.counts) return '?';
  return state.counts[key] ?? state.counts[key + 's'] ?? 0;
}

function toggleEntity(key, event) {
  const checked = event.target.checked;
  if (checked) {
    if (state.selectedEntities.indexOf(key) === -1) {
      state.selectedEntities.push(key);
    }
  } else {
    const idx = state.selectedEntities.indexOf(key);
    if (idx !== -1) {
      state.selectedEntities.splice(idx, 1);
    }
  }
}
</script>
