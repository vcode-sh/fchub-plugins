<template>
  <div>
    <PageHeader title="Migration Results" />

    <!-- Outcome, before any table. A run with 4,000 errors should not look like a clean one. -->
    <div :class="['notice', 'inline', 'cartshift-outcome', outcome.noticeClass]">
      <p>
        <strong>{{ outcome.headline }}</strong>
      </p>
      <p>{{ outcome.detail }}</p>
      <p v-if="outcome.nextStep">{{ outcome.nextStep }}</p>
    </div>

    <!--
      A retry is its own run and reads like one, which is confusing without the
      lineage stated somewhere the eye lands.
    -->
    <p v-if="retryOf" class="cartshift-retry-lineage">
      <span class="cartshift-badge cartshift-badge-info">RETRY</span>
      This run re-attempted records that failed in migration
      <code>{{ retryOf }}</code>. That run still exists, with its own log and its own
      rollback &mdash; this one did not modify it.
    </p>

    <!-- Summary table -->
    <template v-if="state.progress?.entities">
      <h2>Summary</h2>
      <table class="widefat striped cartshift-table-results">
        <thead>
          <tr><th>Entity</th><th>Processed</th><th>Skipped</th><th>Errors</th><th>Status</th></tr>
        </thead>
        <tbody>
          <tr v-for="(e, entity) in state.progress.entities" :key="entity">
            <td><strong>{{ capitalize(entity) }}</strong></td>
            <td>{{ formatNumber(e.processed) }}</td>
            <td>
              <span v-if="e.skipped > 0" class="cartshift-warn-text">{{ formatNumber(e.skipped) }}</span>
              <template v-else>0</template>
            </td>
            <td>
              <span v-if="e.errors > 0" class="cartshift-fail">{{ formatNumber(e.errors) }}</span>
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
            <td><strong>{{ formatNumber(totals.processed) }}</strong></td>
            <td><strong>{{ formatNumber(totals.skipped) }}</strong></td>
            <td><strong>{{ formatNumber(totals.errors) }}</strong></td>
            <td></td>
          </tr>
        </tbody>
      </table>
    </template>

    <!-- Log Viewer -->
    <h2>Migration Log</h2>
    <LogViewer
      :migration-id="state.progress?.migration_id"
      :retry-enabled="retryOffered"
      @retry="onBreakdownRetry"
    />

    <div v-if="state.error" class="notice notice-error inline" role="alert">
      <p>{{ state.error }}</p>
    </div>

    <!--
      Four actions, three consequences: retry and new migration create things,
      reset forgets things, rollback deletes things. They are ranked and fenced
      accordingly — one primary button on the screen, and the only one that
      destroys data behind its own border.
    -->
    <h2>What next</h2>

    <RetryPanel
      v-if="canAttemptRetry"
      ref="retryPanel"
      :migration-id="state.progress?.migration_id"
      :fallback-errors="totals.errors"
      @availability="retryShowing = $event"
    />

    <div class="cartshift-action-panel">
      <div class="cartshift-action-row">
        <div class="cartshift-action-copy">
          <strong>Run another migration</strong>
          <span>
            Back to the start. Nothing is touched &mdash; this run's results and log stay
            where they are.
          </span>
          <span v-if="retryShowing">
            This migrates everything again from scratch. To pick up only what failed, use
            the retry above.
          </span>
        </div>
        <button
          :class="['button', retryShowing ? '' : 'button-primary']"
          @click="newMigration"
        >
          New Migration
        </button>
      </div>

      <div class="cartshift-action-row">
        <div class="cartshift-action-copy">
          <strong>Clear the stored run</strong>
          <span>
            Forgets that this migration happened, so a fresh one can start. Every record
            it created stays in FluentCart, and so does every id-map entry pointing at
            them. Nothing is deleted.
          </span>
        </div>
        <button class="button" :disabled="state.loading" @click="resetOpen = true">
          Reset Migration State
        </button>
      </div>
    </div>

    <div class="cartshift-danger-zone">
      <h3 class="cartshift-danger-zone-title">
        <span aria-hidden="true">&#9888;</span> Destructive
      </h3>
      <div class="cartshift-action-row">
        <div class="cartshift-action-copy">
          <strong>Roll back this migration</strong>
          <span>
            Permanently deletes every FluentCart record this migration created. There is
            no undo and no backup is taken. If you only want to run the migration again,
            use <em>Reset Migration State</em> above instead &mdash; that keeps the data.
          </span>
          <span v-if="retryOf">
            This is the retry only. Records created by migration
            <code>{{ retryOf }}</code> are not part of it and stay where they are.
          </span>
        </div>
        <button class="button button-link-delete" :disabled="state.loading" @click="openRollback">
          Roll Back Migration
        </button>
      </div>
    </div>

    <ConfirmDialog
      :open="resetOpen"
      title="Reset the migration state?"
      confirm-label="Reset state"
      @confirm="doReset"
      @cancel="resetOpen = false"
    >
      <p>
        <strong>This deletes nothing.</strong> It clears the stored run so a new migration
        can start. Records already written to FluentCart, and their id-map entries, are
        untouched.
      </p>
    </ConfirmDialog>

    <ConfirmDialog
      :open="rollbackOpen"
      title="Delete everything this migration created?"
      confirm-label="Delete the migrated records"
      cancel-label="Leave it alone"
      tone="danger"
      :confirm-disabled="!rollbackAcknowledged"
      @confirm="doRollback"
      @cancel="closeRollback"
    >
      <p>
        Rollback permanently deletes the FluentCart records created by
        <template v-if="state.progress?.migration_id">
          migration <code>{{ state.progress.migration_id }}</code>
        </template>
        <template v-else>this migration</template>
        &mdash; on current figures, around
        <strong>{{ totals.processed }}</strong> records.
      </p>
      <p>
        There is no undo, and CartShift does not take a backup first. If you have edited
        any of these records in FluentCart since the migration, those edits go with them.
      </p>
      <p>
        Wanting a clean slate for another run is <em>not</em> a reason to do this &mdash;
        Reset Migration State does that without deleting anything.
      </p>
      <label class="cartshift-modal-ack">
        <input type="checkbox" v-model="rollbackAcknowledged" />
        <span>I understand these records will be permanently deleted.</span>
      </label>
    </ConfirmDialog>
  </div>
</template>

<script setup>
import { inject, ref, computed, onMounted } from 'vue';
import PageHeader from './PageHeader.vue';
import ConfirmDialog from './ConfirmDialog.vue';
import RetryPanel from './RetryPanel.vue';
import LogViewer from './log/LogViewer.vue';

const { state, actions } = inject('migration');

const resetOpen = ref(false);
const rollbackOpen = ref(false);
const rollbackAcknowledged = ref(false);
const retryPanel = ref(null);

/** The run this one re-attempted, when it is itself a retry. */
const retryOf = computed(() => state.progress?.retry_of || null);

/**
 * Whether a retry is worth mounting the panel for at all. A cancelled run is
 * deliberately excluded: the answer there is resume or a fresh run, not
 * re-attempting whichever handful of records happened to fail before it stopped.
 * The panel itself decides whether it has anything to offer once the log stats
 * land, and says so through @availability.
 */
const canAttemptRetry = computed(() => {
  if (!state.progress?.migration_id) return false;

  return state.progress.status !== 'cancelled';
});

/** Set by the panel once it knows there are failed records to re-run. */
const retryShowing = ref(false);

/** Whether the breakdown should offer per-reason retry buttons. */
const retryOffered = computed(() => retryShowing.value && state.retrySupport !== 'no');

function onBreakdownRetry(row) {
  retryPanel.value?.retryReason(row);
}

onMounted(() => {
  // Ask once, early, so the panel knows whether it can offer the control rather
  // than finding out by failing.
  actions.probeRetrySupport();
});

const totals = computed(() => {
  let processed = 0, skipped = 0, errors = 0;

  if (state.progress?.entities) {
    for (const entity in state.progress.entities) {
      const e = state.progress.entities[entity];
      processed += Number(e.processed) || 0;
      skipped += Number(e.skipped) || 0;
      errors += Number(e.errors) || 0;
    }
  }

  return { processed, skipped, errors };
});

/**
 * A migration that limped home with 4,000 errors used to look identical to one
 * that sailed through, bar a number in a cell. State the outcome in words, at
 * the top, before anyone has to read a table.
 */
const outcome = computed(() => {
  const status = state.progress?.status || 'completed';
  const dryRun = !!state.progress?.dry_run;
  const { processed, skipped, errors } = totals.value;

  // A retry is a migration of a shortlist, so "3 records migrated" reads like a
  // disaster unless the headline says what was being attempted.
  const noun = retryOf.value ? 'Retry' : 'Migration';

  if (status === 'cancelled') {
    return {
      noticeClass: 'notice-warning',
      headline: `${noun} cancelled`,
      detail: `Stopped part-way through. ${processed} records were migrated before it stopped, and they are still there.`,
      nextStep: 'Reset the state to start again, or roll back to remove what was created.',
    };
  }

  if (status === 'failed') {
    return {
      noticeClass: 'notice-error',
      headline: `${noun} failed`,
      detail: `The run stopped on an error. ${processed} records were migrated, ${errors} failed.`,
      nextStep: 'The log below has the reasons. Fix the cause, then retry the failed records.',
    };
  }

  if (dryRun) {
    return {
      noticeClass: 'notice-info',
      headline: 'Dry run finished — nothing was written to FluentCart',
      detail: `${processed} records would have been migrated, ${skipped} skipped, ${errors} would have failed. `
        + 'CartShift wrote simulation rows to its own ID-map table so the run could resolve references; '
        + 'those are cleared with the run and no FluentCart records were created.',
      nextStep: errors > 0
        ? 'Those failures will happen for real unless you deal with them first. The log below says why.'
        : 'Run it for real when you are ready.',
    };
  }

  if (errors > 0) {
    return {
      noticeClass: 'notice-error',
      headline: `${noun} finished with ${errors} ${errors === 1 ? 'error' : 'errors'}`,
      // Not "failed outright" any more. The count is now the number of error
      // rows in the log rather than the number of records that threw, and those
      // are not the same thing: a record can arrive and still lose a column to a
      // write the database refused. Telling someone three orders failed when all
      // three are sitting in FluentCart sends them looking for the wrong problem.
      detail: `${processed} records migrated, ${skipped} skipped, ${errors} failed or arrived incomplete.`,
      nextStep:
        'The grouped breakdown below explains what went wrong and how often. Once the cause is fixed, retry just those records — you do not have to run the whole thing again.',
    };
  }

  if (skipped > 0) {
    return {
      noticeClass: 'notice-warning',
      headline: `${noun} finished, with skips`,
      detail: `${processed} records migrated and ${skipped} skipped. Nothing errored.`,
      nextStep: 'Skips are usually deliberate, but worth a look — the breakdown below groups them by reason.',
    };
  }

  return {
    noticeClass: 'notice-success',
    headline: `${noun} finished cleanly`,
    detail: `${processed} records migrated. Nothing skipped, nothing failed.`,
    nextStep: retryOf.value
      ? 'Everything this retry attempted came across. The run it came from is unchanged.'
      : '',
  };
});

function capitalize(str) {
  if (!str) return '';
  return str.charAt(0).toUpperCase() + str.slice(1).replace(/_/g, ' ');
}

function formatNumber(value) {
  const n = Number(value);
  if (!Number.isFinite(n)) return '0';
  return n.toLocaleString();
}

function newMigration() {
  actions.resetState();
}

function doReset() {
  resetOpen.value = false;
  actions.resetMigration(false);
}

function openRollback() {
  rollbackAcknowledged.value = false;
  rollbackOpen.value = true;
}

function closeRollback() {
  rollbackOpen.value = false;
  rollbackAcknowledged.value = false;
}

/** Turn whatever the rollback endpoint reports into one readable sentence. */
function describeRollback(data) {
  const stats = data?.stats || data;

  if (!stats || typeof stats !== 'object') {
    return 'The migrated records have been deleted.';
  }

  const parts = [];

  for (const key of Object.keys(stats)) {
    const value = Number(stats[key]);
    if (Number.isFinite(value)) {
      parts.push(`${value} ${key.replace(/_/g, ' ')}`);
    }
  }

  return parts.length > 0
    ? `Deleted ${parts.join(', ')}.`
    : 'The migrated records have been deleted.';
}

async function doRollback() {
  rollbackOpen.value = false;
  rollbackAcknowledged.value = false;

  try {
    const data = await actions.rollback();

    // rollback() resets the app back to the preflight screen, so the outcome has
    // to be handed to the message that screen already renders.
    state.resetMessage = 'Rollback complete. ' + describeRollback(data);
  } catch {
    // The composable puts the failure in state.error.
  }
}
</script>
