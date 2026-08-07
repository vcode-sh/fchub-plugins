<template>
  <section
    v-if="hasSomethingToRetry"
    class="cartshift-retry-zone"
    aria-labelledby="cartshift-retry-title"
  >
    <h3 id="cartshift-retry-title" class="cartshift-retry-zone-title">
      <span aria-hidden="true">&#8635;</span> Recommended
    </h3>

    <div class="cartshift-action-row">
      <div class="cartshift-action-copy">
        <strong>Retry the records that did not make it</strong>
        <span>
          Re-runs {{ formatNumber(retryCount) }}
          {{ retryCount === 1 ? 'record' : 'records' }} from this run &mdash;
          {{ statusPhrase }} &mdash; and nothing else. Records that migrated cleanly are
          left alone and are not duplicated.
        </span>
        <span>
          This starts a <strong>new migration run</strong> with its own ID, its own log
          and its own rollback. Migration
          <code v-if="migrationId">{{ migrationId }}</code>
          <template v-else>this run</template>
          is not modified.
        </span>

        <div v-if="supported" class="cartshift-retry-options">
          <label v-if="warningCount > 0" class="cartshift-retry-option">
            <input type="checkbox" v-model="includeWarnings" />
            <span>
              Include the {{ formatNumber(warningCount) }} warning
              {{ warningCount === 1 ? 'row' : 'rows' }} as well as the errors
            </span>
          </label>
          <label class="cartshift-retry-option">
            <input type="checkbox" v-model="dryRun" />
            <span>Dry run &mdash; work out what would happen, write nothing</span>
          </label>
        </div>

        <span v-if="!supported" class="cartshift-retry-unavailable">
          {{ unavailableReason }}
        </span>
      </div>

      <button
        class="button button-primary"
        :disabled="!supported || retryCount === 0 || state.retrying || state.loading"
        :title="!supported ? unavailableReason : undefined"
        @click="openDialog(null)"
      >
        <template v-if="state.retrying">
          <span class="spinner is-active" style="float:none;margin:0 5px 0 0;"></span>
          Starting...
        </template>
        <template v-else>
          Retry {{ formatNumber(retryCount) }}
          {{ retryCount === 1 ? 'Record' : 'Records' }}
        </template>
      </button>
    </div>

    <ConfirmDialog
      :open="dialogOpen"
      :title="dryRun ? 'Dry-run the failed records again?' : 'Retry the failed records?'"
      :confirm-label="dryRun ? 'Start the dry run' : 'Start the retry'"
      cancel-label="Not now"
      @confirm="confirmRetry"
      @cancel="closeDialog"
    >
      <p>
        CartShift will start a new migration run that re-attempts
        <strong>{{ formatNumber(retryCount) }}</strong>
        {{ retryCount === 1 ? 'record' : 'records' }} &mdash; {{ statusPhrase }}.
      </p>
      <p v-if="scope">
        Scoped to one reason: <strong>{{ scope.label }}</strong>
        (<code>{{ scope.code }}</code>). CartShift asks the server to limit the retry to
        that reason. If this build cannot narrow by reason, every matching record in the
        run is retried instead &mdash; more work, same outcome.
      </p>
      <p>
        The original run is left exactly as it is. The retry gets its own ID and its own
        log, and can be rolled back on its own.
      </p>
      <p v-if="dryRun">
        Nothing will be written. A dry run tells you whether the retry would work; it does
        not migrate anything.
      </p>
      <p v-else>
        Records that already migrated are not touched and not duplicated. Anything that
        fails again will fail for the same reason unless the cause has been fixed.
      </p>
    </ConfirmDialog>
  </section>
</template>

<script setup>
import { ref, computed, inject, watch, onMounted } from 'vue';
import { useApi } from '@/composables/useApi.js';
import ConfirmDialog from './ConfirmDialog.vue';

const props = defineProps({
  migrationId: {
    type: String,
    default: null,
  },
  // Fallback error count, from the progress entity totals, used until the log
  // stats arrive and whenever they cannot be read at all.
  fallbackErrors: {
    type: Number,
    default: 0,
  },
});

// Tells the screen above whether this panel is actually showing, so it can
// demote its own primary button rather than competing with one it cannot see.
const emit = defineEmits(['availability']);

const { state, actions } = inject('migration');
const { api } = useApi();

const errorCount = ref(0);
const warningCount = ref(0);
const statsLoaded = ref(false);

const includeWarnings = ref(false);
const dryRun = ref(false);
const dialogOpen = ref(false);

// When the retry was launched from one breakdown reason rather than the panel.
const scope = ref(null);

const supported = computed(() => state.retrySupport !== 'no');

/**
 * Is there anything here worth re-running?
 *
 * The entity totals count errors and nothing else, so a run whose only casualty
 * was a batch of warning rows — a subscription that came across without its
 * product, say — looks spotless from the progress table. The log stats know
 * better, which is why this waits for them before deciding there is nothing to do.
 */
const hasSomethingToRetry = computed(() => {
  if (!props.migrationId) return false;

  return statsLoaded.value
    ? errorCount.value > 0 || warningCount.value > 0
    : props.fallbackErrors > 0;
});

const unavailableReason = computed(
  () =>
    state.retryUnavailable ||
    'Retry is not available on this install. Fix the causes below and run a fresh migration.'
);

const retryCount = computed(() => {
  if (scope.value) return scope.value.count;

  const errors = statsLoaded.value ? errorCount.value : props.fallbackErrors;

  return errors + (includeWarnings.value ? warningCount.value : 0);
});

const statusPhrase = computed(() => {
  if (scope.value) {
    return `everything classified as ${scope.value.label}`;
  }

  return includeWarnings.value
    ? 'everything logged as an error or a warning'
    : 'everything logged as an error';
});

const statuses = computed(() =>
  scope.value ? scope.value.statuses : includeWarnings.value ? ['error', 'warning'] : ['error']
);

/**
 * Read the failure counts off the log stats. The progress table counts errors
 * per entity but knows nothing about warnings, which live only as log rows.
 */
async function loadCounts() {
  if (!props.migrationId) return;

  try {
    const data = await api(
      'GET',
      `log/stats?migration_id=${encodeURIComponent(props.migrationId)}`
    );

    errorCount.value = Number(data?.error) || 0;
    warningCount.value = Number(data?.warning) || 0;
    statsLoaded.value = true;

    // Nothing errored but something warned: including warnings is the only
    // retry on offer, so it starts ticked rather than leaving a dead button.
    includeWarnings.value = errorCount.value === 0 && warningCount.value > 0;
  } catch {
    // Stats are best-effort; the entity totals stand in for the error count and
    // warnings simply go unoffered.
    statsLoaded.value = false;
  }
}

function openDialog(nextScope) {
  scope.value = nextScope;
  dialogOpen.value = true;
}

/** Backing out drops the scope too, or the panel keeps quoting a count nobody asked for. */
function closeDialog() {
  dialogOpen.value = false;
  scope.value = null;
}

/**
 * Open the retry dialog pre-scoped to one breakdown reason. Called by the
 * results screen when a row in the grouped breakdown asks to be retried.
 */
function retryReason(row) {
  if (!row || !row.code) return;

  openDialog({
    code: row.code,
    label: row.label || row.code,
    count: Number(row.count) || 0,
    // A warning-severity reason writes warning rows, so retrying it has to ask
    // for those as well as errors, or the retry matches nothing.
    statuses: row.severity === 'warning' || row.severity === 'info'
      ? ['error', 'warning']
      : ['error'],
  });
}

async function confirmRetry() {
  dialogOpen.value = false;

  await actions.startRetry({
    statuses: statuses.value,
    dryRun: dryRun.value,
    codes: scope.value ? [scope.value.code] : undefined,
  });

  scope.value = null;
}

onMounted(() => {
  actions.probeRetrySupport();
  loadCounts();
});

watch(
  () => props.migrationId,
  () => {
    statsLoaded.value = false;
    loadCounts();
  }
);

watch(hasSomethingToRetry, (value) => emit('availability', value), { immediate: true });

function formatNumber(value) {
  const n = Number(value);
  if (!Number.isFinite(n)) return '0';
  return n.toLocaleString();
}

defineExpose({ retryReason });
</script>
