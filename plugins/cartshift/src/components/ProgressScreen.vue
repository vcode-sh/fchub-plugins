<template>
  <div>
    <PageHeader title="Migration in Progress" />

    <!-- Error state -->
    <template v-if="state.error">
      <div class="notice notice-error">
        <p>{{ state.error }}</p>
      </div>
      <p>
        <button v-if="state.batchError" class="button button-primary" @click="actions.retryBatch()">
          Retry Batch
        </button>
        <button class="button" @click="actions.backFromError()">Back to Start</button>
      </p>
    </template>

    <!-- Loading state -->
    <template v-else-if="!state.progress">
      <p><span class="spinner is-active" style="float:none;"></span> Starting...</p>
    </template>

    <!-- Progress display -->
    <template v-else>
      <div class="cartshift-status-bar">
        <strong>Status:</strong>
        <span :class="'cartshift-badge cartshift-badge-' + state.progress.status">
          {{ capitalize(state.progress.status) }}
        </span>
        <span v-if="state.progress.dry_run" class="cartshift-badge cartshift-badge-dryrun">DRY RUN</span>
        <template v-if="state.progress.started_at"> | Started: {{ state.progress.started_at }}</template>
      </div>

      <table v-if="state.progress.entities" class="widefat striped cartshift-progress-table">
        <thead>
          <tr><th>Entity</th><th>Progress</th><th>Processed</th><th>Skipped</th><th>Errors</th></tr>
        </thead>
        <tbody>
          <tr v-for="(e, entity) in state.progress.entities" :key="entity">
            <td><strong>{{ capitalize(entity) }}</strong></td>
            <td>
              <div class="cartshift-progress-bar">
                <div
                  :class="'cartshift-progress-fill cartshift-progress-' + e.status"
                  :style="{ width: getPercent(e) + '%' }"
                ></div>
                <span class="cartshift-progress-text">{{ getPercent(e) }}%</span>
              </div>
            </td>
            <td>{{ e.processed }} / {{ e.total }}</td>
            <td>{{ e.skipped }}</td>
            <td>
              <span v-if="e.errors > 0" class="cartshift-fail">{{ e.errors }}</span>
              <template v-else>0</template>
            </td>
          </tr>
        </tbody>
      </table>

      <!-- Running: cancel button -->
      <p v-if="state.progress.status === 'running'" style="margin-top:15px;">
        <button class="button button-secondary" @click="confirmCancel">Cancel Migration</button>
      </p>

      <!-- Completed: finalize + results -->
      <template v-else>
        <p style="margin-top:15px;">
          <button
            v-if="!state.finalized && !state.progress.dry_run"
            class="button button-primary"
            :disabled="state.finalizing"
            @click="actions.finalize()"
          >
            <template v-if="state.finalizing">
              <span class="spinner is-active" style="float:none;margin:0 5px 0 0;"></span> Finalizing...
            </template>
            <template v-else>Finalize Migration</template>
          </button>

          <template v-if="state.finalized && state.finalizeStats">
            <div class="notice notice-success inline" style="margin:10px 0;">
              <p>
                Finalization complete &mdash; {{ state.finalizeStats.customers_updated }} customer stats recalculated, caches cleared.
              </p>
            </div>
          </template>

          <button
            :class="['button', state.finalized ? 'button-primary' : '']"
            @click="goToResults"
          >
            View Results &amp; Log
          </button>
        </p>
      </template>
    </template>
  </div>
</template>

<script setup>
import { inject } from 'vue';
import PageHeader from './PageHeader.vue';

const { state, actions } = inject('migration');

function capitalize(str) {
  if (!str) return '';
  return str.charAt(0).toUpperCase() + str.slice(1).replace(/_/g, ' ');
}

function getPercent(e) {
  if (e.status === 'completed') return 100;
  if (e.total <= 0) return 0;
  return Math.round(((e.processed + e.skipped + e.errors) / e.total) * 100);
}

function confirmCancel() {
  if (confirm('Are you sure you want to cancel the migration?')) {
    actions.cancelMigration();
  }
}

function goToResults() {
  actions.goToScreen('results');
}
</script>
