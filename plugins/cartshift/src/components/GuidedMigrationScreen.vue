<template>
  <div class="cartshift-guided">
    <PageHeader title="CartShift" subtitle="WooCommerce to FluentCart" guided />

    <div v-if="state.loading" class="cartshift-loading" role="status" aria-live="polite">
      <span class="cartshift-spinner" aria-hidden="true"></span>
      <span>Checking your store…</span>
    </div>
    <div v-if="!state.loading && state.error" class="cartshift-alert cartshift-alert--danger cartshift-page-alert" role="alert">
      <span class="dashicons dashicons-warning" aria-hidden="true"></span>
      <p>{{ state.error }}</p>
    </div>

    <template v-if="!state.loading && data">
      <section v-if="!data.guided_available" class="cartshift-empty-state" data-test="cross-runtime">
        <span class="dashicons dashicons-randomize" aria-hidden="true"></span>
        <h2>This store needs a different migration route</h2>
        <p>{{ data.message }}</p>
      </section>

      <template v-else>
        <GuidedReadinessPanel
          :data="data"
          :current-run="currentRun"
          :blocking-checks="blockingChecks"
          :warning-checks="warningChecks"
          :busy="state.busy"
          :can-start="canStart"
          :start-label="startLabel"
          @initialise="initialise"
          @refresh="refresh"
          @start="startRehearsal"
        />

        <GuidedRunPanel
          v-if="currentRun"
          :run="currentRun"
          :busy="state.busy"
          @continue="startRehearsal"
          @rollback="rollbackRun"
        />

        <GuidedDecisionReview
          v-if="currentRun?.phase === 'awaiting_decisions'"
          ref="decisionReview"
          :run="currentRun"
          :busy="state.busy"
          :approvals="reviewApprovals"
          :can-accept="canAcceptRun"
          :blocked="blockingChecks.length > 0 || data.plan_blocked"
          :notice="state.reviewNotice"
          @toggle="toggleReview"
          @accept="acceptRunDecisions"
          @cancel="cancelRun"
        />
      </template>
    </template>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, reactive, ref } from 'vue';
import { useApi } from '@/composables/useApi.js';
import GuidedDecisionReview from './GuidedDecisionReview.vue';
import GuidedReadinessPanel from './GuidedReadinessPanel.vue';
import GuidedRunPanel from './GuidedRunPanel.vue';
import PageHeader from './PageHeader.vue';

const { api } = useApi();
const state = reactive({ loading: true, busy: false, error: null, data: null, run: null, reviewNotice: null });
const reviewApprovals = reactive({});
const decisionReview = ref(null);

const data = computed(() => state.data);
const currentRun = computed(() => state.run ?? state.data?.run ?? null);
const blockingChecks = computed(() => checksWithSeverity('fail'));
const warningChecks = computed(() => checksWithSeverity('warn'));
const unresolvedReviewBlockers = computed(() => currentRun.value?.review?.blockers || []);

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
  if (!currentRun.value) return 'Review my store';
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

function checksWithSeverity(severity) {
  return Object.values(state.data?.preflight?.checks || {}).filter(
    (check) => String(check?.severity || '').toLowerCase() === severity
  );
}

function clearReviewApprovals() {
  Object.keys(reviewApprovals).forEach((key) => delete reviewApprovals[key]);
}

function toggleReview(reviewId, checked) {
  reviewApprovals[reviewId] = checked;
}

async function refresh() {
  state.busy = true;
  state.error = null;
  try {
    state.data = await api('GET', 'migration/status');
    clearReviewApprovals();
    state.reviewNotice = null;
    state.run = state.data.run ?? null;
  } catch {
    state.error = 'CartShift could not check your store. Please try again.';
  } finally {
    state.busy = false;
    state.loading = false;
  }
}

async function initialise() {
  state.busy = true;
  state.error = null;
  try {
    await api('POST', 'migration/initialise');
    await refresh();
  } catch {
    state.error = 'CartShift could not prepare the private migration workspace. Please try again.';
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
      state.error = 'CartShift could not continue the store review.';
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
      ? 'Your store changed while you were reviewing. Nothing was recorded; please check the current items again.'
      : null;
    if (accepted.review_changed) {
      await nextTick();
      decisionReview.value?.focusHeading();
    }
    if (state.run?.phase === 'running') await driveRehearsal();
  } catch {
    await refresh();
    if (currentRun.value?.phase === 'awaiting_decisions') state.error = 'CartShift could not save those choices.';
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
    if (currentRun.value?.phase === 'awaiting_decisions') state.error = 'CartShift could not cancel this review.';
  } finally {
    state.busy = false;
  }
}

async function rollbackRun() {
  state.busy = true;
  state.error = null;
  try {
    state.run = await api('POST', 'migration/rollback', { review_id: currentRun.value.rollback.review_id });
  } catch {
    await refresh();
    if (currentRun.value?.phase !== 'rolled_back') state.error = 'CartShift could not roll back this run.';
  } finally {
    state.busy = false;
  }
}

async function driveRehearsal() {
  for (let step = 0; step < 12; step += 1) {
    state.run = await api('POST', 'migration/start');
    if (state.run?.phase !== 'running') return;
  }
  throw new Error('The readiness review did not reach a safe pause.');
}

onMounted(refresh);
</script>
