<template>
  <div>
    <PageHeader title="Migration in Progress" />

    <!-- Error state -->
    <template v-if="state.error">
      <div class="notice notice-error" role="alert">
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
      <p role="status" aria-live="polite">
        <span class="spinner is-active" style="float:none;"></span> Starting...
      </p>
    </template>

    <!-- Progress display -->
    <template v-else>
      <!-- An abandoned run: status says running, nothing is driving it. -->
      <div v-if="isInterrupted" class="notice notice-warning inline">
        <p>
          This migration is still marked as running, but nothing is working on it &mdash;
          the browser tab that started it was closed, the machine slept, or the request
          timed out. Nothing is lost: the run remembers exactly where it stopped.
        </p>
        <p>
          <strong>Resume</strong> picks up from that point.
          <strong>Cancel</strong> stops it and keeps whatever was migrated.
          <strong>Reset</strong> forgets the run so you can start again &mdash; it does
          <em>not</em> delete migrated records, that is what Rollback is for.
        </p>
      </div>

      <div v-else-if="state.background" class="notice notice-info inline">
        <p v-if="state.backgroundPending">
          <strong>Running in the background.</strong> Action Scheduler has batches queued,
          so you can close this tab and come back later &mdash; the migration carries on
          without you, and this screen picks up where it left off.
        </p>
        <p v-else>
          <strong>Running in the background.</strong> No batch is queued at this exact
          moment, which is normal between runs. Give it a few seconds. If the numbers
          below stay put, resume it here and this tab will drive it instead.
        </p>
      </div>

      <div v-if="state.stalled" class="notice notice-warning inline">
        <p>
          No progress for a while and no queued background batches. Action Scheduler may
          not be running. Resume it here to drive it from this tab, or reset the run.
        </p>
      </div>

      <div v-if="state.resetBlocked" class="notice notice-error inline" role="alert">
        <p>{{ state.resetBlocked }}</p>
        <p>
          <button class="button button-link-delete" @click="openReset(true)">
            Force Reset
          </button>
        </p>
      </div>

      <!-- A retry looks like any other run, so it has to say that it is one. -->
      <div v-if="state.progress.retry_of" class="notice notice-info inline">
        <p>
          <strong>This is a retry.</strong> It re-attempts records that failed in
          migration <code>{{ state.progress.retry_of }}</code>, and is a separate run with
          its own ID, log and rollback. The original is untouched either way.
        </p>
      </div>

      <!-- Screen readers get the numbers without having to re-read the table. -->
      <div class="cartshift-sr-only" role="status" aria-live="polite" aria-atomic="true">
        {{ liveSummary }}
      </div>

      <div class="cartshift-status-bar">
        <strong>Status:</strong>
        <span :class="'cartshift-badge cartshift-badge-' + state.progress.status">
          {{ capitalize(state.progress.status) }}
        </span>
        <span v-if="state.progress.dry_run" class="cartshift-badge cartshift-badge-dryrun">DRY RUN</span>
        <span v-if="state.progress.retry_of" class="cartshift-badge cartshift-badge-info">RETRY</span>
        <span v-if="state.background" class="cartshift-badge cartshift-badge-info">BACKGROUND</span>
        <span
          v-if="state.background && state.backgroundPending"
          class="cartshift-badge cartshift-badge-success"
        >
          SAFE TO CLOSE THIS TAB
        </span>
        <template v-if="state.progress.started_at"> | Started: {{ state.progress.started_at }}</template>
        <template v-if="state.progress.migration_id"> | ID: {{ state.progress.migration_id }}</template>
      </div>

      <table v-if="state.progress.entities" class="widefat striped cartshift-progress-table">
        <thead>
          <tr><th>Entity</th><th>Progress</th><th>Processed</th><th>Skipped</th><th>Errors</th></tr>
        </thead>
        <tbody>
          <tr v-for="(e, entity) in state.progress.entities" :key="entity">
            <td><strong>{{ capitalize(entity) }}</strong></td>
            <td>
              <div v-if="progressOf(e).mode === 'percent'" class="cartshift-progress-bar">
                <div
                  :class="'cartshift-progress-fill cartshift-progress-' + e.status"
                  :style="{ width: progressOf(e).percent + '%' }"
                ></div>
                <span class="cartshift-progress-text">{{ progressOf(e).percent }}%</span>
              </div>
              <span
                v-else
                :class="['cartshift-progress-note', 'cartshift-progress-note-' + progressOf(e).mode]"
                :title="progressOf(e).detail"
              >
                {{ progressOf(e).label }}
              </span>
            </td>
            <td>{{ formatNumber(e.processed) }} / {{ totalLabel(e) }}</td>
            <td>{{ formatNumber(e.skipped) }}</td>
            <td>
              <span v-if="e.errors > 0" class="cartshift-fail">{{ formatNumber(e.errors) }}</span>
              <template v-else>0</template>
            </td>
          </tr>
        </tbody>
      </table>

      <!-- Running: resume / cancel / reset -->
      <p v-if="state.progress.status === 'running'" style="margin-top:15px;">
        <button
          v-if="isInterrupted || state.stalled"
          class="button button-primary"
          :disabled="state.loading"
          @click="actions.resumeMigration()"
        >
          Resume Migration
        </button>
        <button class="button button-secondary" @click="cancelOpen = true">Cancel Migration</button>
        <button class="button button-link-delete" :disabled="state.loading" @click="openReset(false)">
          Reset Migration State
        </button>
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

    <ConfirmDialog
      :open="cancelOpen"
      title="Cancel this migration?"
      confirm-label="Cancel the migration"
      cancel-label="Keep going"
      @confirm="doCancel"
      @cancel="cancelOpen = false"
    >
      <p>
        Everything migrated so far stays in FluentCart. The run stops where it is and
        will not pick itself back up.
      </p>
      <p>
        Nothing is deleted by cancelling. If you want the migrated records gone, that is
        Rollback, on the results screen.
      </p>
    </ConfirmDialog>

    <ConfirmDialog
      :open="resetOpen"
      :title="resetForce ? 'Force-reset the migration state?' : 'Reset the migration state?'"
      :confirm-label="resetForce ? 'Force reset' : 'Reset state'"
      tone="danger"
      @confirm="doReset"
      @cancel="resetOpen = false"
    >
      <p v-if="resetForce">
        A batch may be running right this second. Forcing a reset clears the stored run
        anyway, which can leave a half-finished entity behind.
      </p>
      <p>
        <strong>Reset forgets the run. It does not delete anything.</strong>
        Every record already written to FluentCart, and every id-map entry pointing at
        it, stays exactly where it is. This only clears the stored state so a new run
        can start.
      </p>
      <p>
        Deleting the migrated records is Rollback's job, and it lives on the results
        screen.
      </p>
    </ConfirmDialog>
  </div>
</template>

<script setup>
import { inject, ref, computed } from 'vue';
import PageHeader from './PageHeader.vue';
import ConfirmDialog from './ConfirmDialog.vue';

const { state, actions } = inject('migration');

const cancelOpen = ref(false);
const resetOpen = ref(false);
const resetForce = ref(false);

const isInterrupted = computed(
  () => state.interrupted && state.progress?.status === 'running' && !state.migrating
);

/**
 * A one-line spoken summary. Deliberately coarse — percentages rounded to the
 * nearest five — so a polite live region does not narrate every batch.
 */
const liveSummary = computed(() => {
  const progress = state.progress;
  if (!progress) return '';

  const parts = [`Migration ${progress.status || 'unknown'}.`];
  const entities = progress.entities || {};

  for (const key of Object.keys(entities)) {
    const info = progressOf(entities[key]);
    const name = capitalize(key);

    if (info.mode === 'percent') {
      parts.push(`${name} ${Math.round(info.percent / 5) * 5} percent.`);
    } else {
      parts.push(`${name}: ${info.label}.`);
    }
  }

  return parts.join(' ');
});

function capitalize(str) {
  if (!str) return '';
  return str.charAt(0).toUpperCase() + str.slice(1).replace(/_/g, ' ');
}

/**
 * Zero is three different stories and they should not look alike: an entity with
 * nothing to migrate, an entity nobody has counted yet, and an entity whose
 * count could not be read at all. Returning a flat 0% for all three is how a
 * migration ends up looking broken when it is merely empty.
 */
function progressOf(e) {
  const status = e?.status || 'pending';
  const total = Number(e?.total);

  if (!Number.isFinite(total) || total < 0) {
    return {
      mode: 'unknown',
      percent: 0,
      label: 'Count unavailable',
      detail: 'The source count could not be read, so progress cannot be measured.',
    };
  }

  if (total === 0) {
    if (status === 'pending') {
      return {
        mode: 'uncounted',
        percent: 0,
        label: 'Not counted yet',
        detail: 'This entity has not started, so nothing has been counted.',
      };
    }

    return {
      mode: 'empty',
      percent: 0,
      label: 'Nothing to migrate',
      detail: 'WooCommerce has no records of this type. Not a failure.',
    };
  }

  if (status === 'completed') {
    return { mode: 'percent', percent: 100, label: '100%', detail: '' };
  }

  const done = Number(e?.processed || 0) + Number(e?.skipped || 0) + Number(e?.errors || 0);
  const percent = Math.max(0, Math.min(100, Math.round((done / total) * 100)));

  return { mode: 'percent', percent, label: `${percent}%`, detail: '' };
}

function totalLabel(e) {
  const total = Number(e?.total);

  if (!Number.isFinite(total) || total < 0) return '?';
  if (total === 0 && (e?.status || 'pending') === 'pending') return '?';

  return formatNumber(total);
}

function formatNumber(value) {
  const n = Number(value);
  if (!Number.isFinite(n)) return '0';
  return n.toLocaleString();
}

function openReset(force) {
  resetForce.value = !!force;
  resetOpen.value = true;
}

function doCancel() {
  cancelOpen.value = false;
  actions.cancelMigration();
}

function doReset() {
  resetOpen.value = false;
  actions.resetMigration(resetForce.value);
}

function goToResults() {
  actions.goToScreen('results');
}
</script>
