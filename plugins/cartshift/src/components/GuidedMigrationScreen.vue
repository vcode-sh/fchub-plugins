<template>
  <div class="cartshift-guided">
    <PageHeader title="Migrate WooCommerce to FluentCart" />

    <p v-if="state.loading" class="description" role="status" aria-live="polite">Reading your shop…</p>
    <div v-if="!state.loading && state.error" class="notice notice-error inline" role="alert"><p>{{ state.error }}</p></div>

    <template v-if="!state.loading && data">
      <!-- This WordPress holds one end only. Not a fault, a different route. -->
      <section v-if="!data.guided_available" class="cartshift-block" data-test="cross-runtime">
        <p>{{ data.message }}</p>
      </section>

      <template v-else-if="!data.initialised">
        <section class="cartshift-block" data-test="uninitialised">
          <p>{{ data.message }}</p>
          <button class="button button-primary" :disabled="state.busy" @click="initialise">
            {{ state.busy ? 'Naming…' : 'Name this site' }}
          </button>
        </section>
      </template>

      <template v-else>
        <section v-if="!data.setup.complete" class="cartshift-block" data-test="setup">
          <h2>One-time setup</h2>
          <p class="description">
            CartShift reads these from <code>wp-config.php</code> or the environment and cannot set them
            itself. Copy the prepared lines, paste them into <code>wp-config.php</code>, then reload.
          </p>
          <div v-for="item in data.setup.missing" :key="item.constant" class="cartshift-requirement">
            <p><code>{{ item.constant }}</code></p>
            <p class="description">{{ item.purpose }}</p>
          </div>
          <button
            v-if="data.setup.can_copy_lines"
            class="button button-primary"
            data-test="setup-copy"
            :disabled="state.busy"
            @click="copySetupLines"
          >
            {{ state.setupCopied ? 'Setup lines copied' : 'Copy setup lines' }}
          </button>
        </section>

        <section class="cartshift-block">
          <h2>Your shop</h2>
          <p v-if="blockingChecks.length" data-test="preflight-blocked">
            <strong>This shop cannot migrate yet.</strong>
          </p>
          <ul v-if="blockingChecks.length" class="cartshift-blockers">
            <li v-for="check in blockingChecks" :key="check.label">
              <strong>{{ check.label }}</strong> — {{ check.message }}
            </li>
          </ul>
          <p v-else-if="!data.plan_blocked" data-test="preflight-ready">No blocking shop checks were found.</p>

          <details v-if="warningChecks.length" data-test="preflight-warnings">
            <summary>Things to review before migration</summary>
            <ul>
              <li v-for="check in warningChecks" :key="check.label">
                <strong>{{ check.label }}</strong> — {{ check.message }}
              </li>
            </ul>
          </details>

          <p v-if="!data.subscriptions.available" class="description" data-test="wcs-skipped">
            WooCommerce Subscriptions is not active. Subscription migration will be skipped. Products,
            variations, customers and orders are unaffected.
          </p>
          <p v-if="data.plan_blocked" class="notice notice-warning inline" data-test="plan-blocked">
            {{ data.plan_message }}
          </p>
        </section>

        <section v-if="data.plan.length || currentRun" class="cartshift-block">
          <h2>Migration readiness</h2>
          <p class="description">
            CartShift checks every supported shop record and prepares every decision for review. It stops before
            target records until a completed run can be rolled back safely.
          </p>
          <button
            v-if="canStart"
            class="button button-primary"
            data-test="start"
            :disabled="state.busy"
            @click="startRehearsal"
          >
            {{ state.busy ? 'Running…' : startLabel }}
          </button>

          <div v-if="currentRun" class="cartshift-run" data-test="run-progress">
            <p v-if="currentRun.phase !== 'unsafe_completion'">
              <strong>{{ currentRun.completed_steps }} of {{ currentRun.total_steps }} steps complete.</strong>
              <span v-if="currentRun.last_step"> Last step: {{ currentRun.last_step }}.</span>
            </p>
            <p v-if="currentRun.failure" class="notice notice-error inline" data-test="run-failure" role="alert">
              {{ currentRun.failure.message }}
            </p>
            <p v-if="currentRun.mode_changed" class="notice notice-warning inline" data-test="run-mode-changed" role="alert">
              {{ currentRun.mode_changed }}
            </p>
            <button
              v-if="currentRun.mode_changed && currentRun.phase !== 'awaiting_decisions'"
              class="button button-primary"
              data-test="stop-outdated-run"
              :disabled="state.busy"
              @click="startRehearsal"
            >
              {{ state.busy ? 'Stopping…' : 'Stop this outdated check' }}
            </button>
            <div v-if="currentRun.rollback" data-test="rollback-preview">
              <template v-if="currentRun.rollback.safe">
                <p>
                  Rollback will remove {{ currentRun.rollback.deletion_count }} target records created by
                  this failed run. It will not alter pre-existing target records.
                </p>
                <button
                  class="button"
                  data-test="rollback"
                  :disabled="state.busy"
                  @click="rollbackRun"
                >
                  {{ state.busy ? 'Rolling back…' : 'Roll back this failed run' }}
                </button>
              </template>
              <template v-else>
                <p><strong>Rollback is blocked because the target changed after this run.</strong></p>
              </template>
            </div>
            <section
              v-if="currentRun.migration_exceptions?.length"
              class="notice notice-warning inline"
              data-test="migration-exceptions"
            >
              <h3>Shared stock needs manual setup</h3>
              <p>
                CartShift can migrate these products safely, but FluentCart cannot use their shared WooCommerce
                stock total automatically. CartShift keeps affected variations unavailable so they cannot oversell.
              </p>
              <article v-for="(item, itemIndex) in currentRun.migration_exceptions" :key="itemIndex">
                <h4>{{ item.title }}</h4>
                <p>{{ item.message }}</p>
                <p v-if="item.target_state === 'confirmed'"><strong>CartShift verified this safe unavailable state in FluentCart.</strong></p>
                <p v-else-if="item.target_state === 'needs_review'"><strong>CartShift could not verify the saved safe state. Keep these variations unavailable and inspect them before selling.</strong></p>
                <p v-if="item.source_quantity_state === 'known'">
                  Original product-wide shared quantity: {{ item.source_quantity }}.
                </p>
                <p v-else-if="item.source_quantity_state === 'below_zero'">
                  WooCommerce reported this product's shared stock below zero. Count physical stock before enabling it.
                </p>
                <p v-else>WooCommerce did not provide a shared quantity. Count physical stock before enabling it.</p>
                <ul>
                  <li v-for="(variation, variationIndex) in item.variations" :key="variationIndex">
                    {{ variation.title }}<template v-if="variation.sku"> — SKU {{ variation.sku }}</template>
                  </li>
                </ul>
                <ul>
                  <li v-for="suggestion in item.suggestions" :key="suggestion">{{ suggestion }}</li>
                </ul>
              </article>
            </section>
          </div>

          <div
            v-if="currentRun?.phase === 'awaiting_decisions'"
            class="cartshift-review"
            data-test="run-review"
          >
            <h3 ref="reviewHeading" tabindex="-1">Review what CartShift will do</h3>
            <p class="description">
              These actions come from the current shop evidence. Nothing is recorded until you confirm every item.
            </p>
            <p v-if="state.reviewNotice" class="notice notice-warning inline" data-test="review-changed" role="status">
              {{ state.reviewNotice }}
            </p>
            <fieldset class="cartshift-review-list">
              <legend class="screen-reader-text">Migration decisions</legend>
              <label
                v-for="item in currentRun.review?.items || []"
                :key="item.review_id"
                class="cartshift-decision"
                data-test="review-decision"
              >
                <input v-model="reviewApprovals[item.review_id]" type="checkbox" />
                <span>
                  <strong>{{ item.title }}</strong>
                  <span class="description">{{ item.summary }}</span>
                </span>
              </label>
            </fieldset>
            <ul v-if="unresolvedReviewBlockers.length" class="cartshift-blockers">
              <li v-for="blocker in unresolvedReviewBlockers" :key="blocker">{{ blocker }}</li>
            </ul>
            <button
              class="button button-primary"
              data-test="accept-run-decisions"
              :disabled="state.busy || !canAcceptRun"
              @click="acceptRunDecisions"
            >
              {{ state.busy ? 'Recording…' : 'Confirm and continue' }}
            </button>
            <button class="button" :disabled="state.busy" @click="cancelRun">Cancel check</button>
          </div>
        </section>

        <section class="cartshift-block" data-test="cutover">
          <h2>The cutover</h2>
          <p class="description">{{ data.setup.cutover.message }}</p>
        </section>
      </template>
    </template>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, reactive, ref } from 'vue';
import { useApi } from '@/composables/useApi.js';
import PageHeader from './PageHeader.vue';

const { api } = useApi();

const state = reactive({
  loading: true,
  busy: false,
  error: null,
  data: null,
  run: null,
  setupCopied: false,
  reviewNotice: null,
});
const reviewApprovals = reactive({});
const reviewHeading = ref(null);

const data = computed(() => state.data);
const currentRun = computed(() => state.run ?? state.data?.run ?? null);

/**
 * Only checks that actually block. `wc_subscriptions` is severity `pass` whether
 * or not the add-on is installed, which is the whole product rule: an optional
 * dependency that is absent is not a failing check.
 */
const blockingChecks = computed(() =>
  Object.values(state.data?.preflight?.checks || {}).filter(
    (check) => String(check?.severity || '').toLowerCase() === 'fail'
  )
);

const warningChecks = computed(() =>
  Object.values(state.data?.preflight?.checks || {}).filter(
    (check) => String(check?.severity || '').toLowerCase() === 'warn'
  )
);

const unresolvedReviewBlockers = computed(() => currentRun.value?.review?.blockers || []);

function clearReviewApprovals() {
  Object.keys(reviewApprovals).forEach((key) => delete reviewApprovals[key]);
}

const canStart = computed(
  () =>
    state.data?.setup?.complete &&
    blockingChecks.value.length === 0 &&
    !state.data?.plan_blocked &&
    !currentRun.value?.mode_changed &&
    (!currentRun.value ||
      ['ready', 'running', 'cancelled', 'rolled_back'].includes(currentRun.value.phase) ||
      (currentRun.value.phase === 'failed' && currentRun.value.failure?.can_restart === true))
);

const startLabel = computed(() => {
  if (!currentRun.value) return 'Check my shop';
  if (['cancelled', 'rolled_back'].includes(currentRun.value.phase)) return 'Start a new check';
  if (currentRun.value.phase === 'failed') return 'Try the check again';
  return 'Continue the check';
});

const canAcceptRun = computed(() => {
  const items = currentRun.value?.review?.items || [];
  return (
    items.length > 0 &&
    unresolvedReviewBlockers.value.length === 0 &&
    blockingChecks.value.length === 0 &&
    !currentRun.value?.mode_changed &&
    items.every((item) => reviewApprovals[item.review_id] === true)
  );
});

async function refresh() {
  state.busy = true;
  state.error = null;
  try {
    state.data = await api('GET', 'migration/status');
    clearReviewApprovals();
    state.reviewNotice = null;
    state.run = state.data.run ?? null;
  } catch {
    state.error = 'Could not read your shop.';
  } finally {
    state.busy = false;
    state.loading = false;
  }
}

async function copySetupLines() {
  state.busy = true;
  state.error = null;
  try {
    const setup = await api('POST', 'migration/setup-lines');
    if (!navigator.clipboard?.writeText) throw new Error('Clipboard access is unavailable.');
    await navigator.clipboard.writeText(setup.lines);
    state.setupCopied = true;
  } catch {
    state.error = 'Could not copy the setup lines.';
  } finally {
    state.busy = false;
  }
}

async function startRehearsal() {
  state.busy = true;
  state.error = null;
  clearReviewApprovals();
  state.reviewNotice = null;
  try {
    await driveRehearsal();
  } catch {
    await refresh();
    if (!currentRun.value || currentRun.value.phase === 'running') {
      state.error = 'Could not run the readiness check.';
    }
  } finally {
    state.busy = false;
  }
}

async function acceptRunDecisions() {
  state.busy = true;
  state.error = null;
  const items = currentRun.value?.review?.items || [];
  try {
    const accepted = await api('POST', 'migration/decisions', {
      approved_reviews: items.map(({ review_id }) => review_id),
    });
    clearReviewApprovals();
    state.run = accepted.run;
    state.reviewNotice = accepted.review_changed
      ? 'The shop changed while you were reviewing. Nothing was recorded; review the current items again.'
      : null;
    if (accepted.review_changed) {
      await nextTick();
      reviewHeading.value?.focus();
    }
    if (state.run?.phase === 'running') {
      await driveRehearsal();
    }
  } catch {
    await refresh();
    if (currentRun.value?.phase === 'awaiting_decisions') {
      state.error = 'Could not record those decisions.';
    }
  } finally {
    state.busy = false;
  }
}

async function cancelRun() {
  state.busy = true;
  state.error = null;
  try {
    clearReviewApprovals();
    state.reviewNotice = null;
    state.run = await api('POST', 'migration/cancel');
  } catch {
    await refresh();
    if (currentRun.value?.phase === 'awaiting_decisions') {
      state.error = 'Could not cancel the readiness check.';
    }
  } finally {
    state.busy = false;
  }
}

async function rollbackRun() {
  state.busy = true;
  state.error = null;
  try {
    state.run = await api('POST', 'migration/rollback', {
      review_id: currentRun.value.rollback.review_id,
    });
  } catch {
    await refresh();
    if (currentRun.value?.phase !== 'rolled_back') {
      state.error = 'Could not roll back the failed rehearsal.';
    }
  } finally {
    state.busy = false;
  }
}

async function driveRehearsal() {
  for (let step = 0; step < 12; step += 1) {
    state.run = await api('POST', 'migration/start');
    if (state.run?.phase !== 'running') return;
  }
  throw new Error('The rehearsal did not reach a safe pause.');
}

async function initialise() {
  state.busy = true;
  try {
    await api('POST', 'migration/initialise');
    await refresh();
  } catch {
    state.error = 'Could not name this site.';
    state.busy = false;
  }
}

onMounted(() => refresh());
</script>

<style scoped>
.cartshift-guided { max-width: 1040px; }
.cartshift-block { margin-top: 28px; }
.cartshift-requirement { margin: 12px 0; }
.cartshift-blockers { padding-left: 18px; list-style: disc; }
.cartshift-run, .cartshift-review { margin-top: 18px; }
.cartshift-review-list { border: 0; margin: 0; padding: 0; }
.cartshift-decision { display: flex; gap: 10px; margin: 12px 0; align-items: flex-start; }
.cartshift-decision span { display: block; }
</style>
