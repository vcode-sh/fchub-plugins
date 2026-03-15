<template>
  <div>
    <PageHeader title="Migration Results" />

    <!-- Summary table -->
    <template v-if="state.progress?.entities">
      <h2>Summary</h2>
      <table class="widefat striped">
        <thead>
          <tr><th>Entity</th><th>Processed</th><th>Skipped</th><th>Errors</th><th>Status</th></tr>
        </thead>
        <tbody>
          <tr v-for="(e, entity) in state.progress.entities" :key="entity">
            <td><strong>{{ capitalize(entity) }}</strong></td>
            <td>{{ e.processed }}</td>
            <td>{{ e.skipped }}</td>
            <td>
              <span v-if="e.errors > 0" class="cartshift-fail">{{ e.errors }}</span>
              <template v-else>0</template>
            </td>
            <td>
              <span :class="'cartshift-badge cartshift-badge-' + e.status">
                {{ capitalize(e.status) }}
              </span>
            </td>
          </tr>
          <tr class="cartshift-total-row">
            <td><strong>Total</strong></td>
            <td><strong>{{ totals.processed }}</strong></td>
            <td><strong>{{ totals.skipped }}</strong></td>
            <td><strong>{{ totals.errors }}</strong></td>
            <td></td>
          </tr>
        </tbody>
      </table>
    </template>

    <!-- Log Viewer -->
    <h2>Migration Log</h2>
    <LogViewer :migration-id="state.progress?.migration_id" />

    <!-- Actions -->
    <h2>Actions</h2>
    <p>
      <button class="button" @click="newMigration">New Migration</button>
      <button class="button button-link-delete" @click="confirmRollback">Rollback Migration</button>
    </p>
  </div>
</template>

<script setup>
import { inject, computed } from 'vue';
import PageHeader from './PageHeader.vue';
import LogViewer from './log/LogViewer.vue';

const { state, actions } = inject('migration');

const totals = computed(() => {
  let processed = 0, skipped = 0, errors = 0;
  if (state.progress?.entities) {
    for (const entity in state.progress.entities) {
      const e = state.progress.entities[entity];
      processed += e.processed;
      skipped += e.skipped;
      errors += e.errors;
    }
  }
  return { processed, skipped, errors };
});

function capitalize(str) {
  if (!str) return '';
  return str.charAt(0).toUpperCase() + str.slice(1).replace(/_/g, ' ');
}

function newMigration() {
  actions.resetState();
}

async function confirmRollback() {
  if (!confirm('This will DELETE all FluentCart records created by the migration. This cannot be undone. Continue?')) {
    return;
  }

  try {
    const data = await actions.rollback();
    alert('Rollback complete. Deleted records: ' + JSON.stringify(data.stats || data));
  } catch {
    // Error is set in state by the composable.
  }
}
</script>
