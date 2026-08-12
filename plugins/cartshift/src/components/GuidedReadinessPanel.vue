<template>
  <section class="cartshift-migration-card">
    <ol class="cartshift-journey" aria-label="Migration journey">
      <li :class="{ active: journeyStep === 1, complete: journeyStep > 1 }">
        <span class="cartshift-step-number">1</span>
        <span class="cartshift-step-copy"><strong>Check</strong><small>Store readiness</small></span>
      </li>
      <li :class="{ active: journeyStep === 2, complete: journeyStep > 2 }">
        <span class="cartshift-step-number">2</span>
        <span class="cartshift-step-copy"><strong>Review</strong><small>Your choices</small></span>
      </li>
      <li :class="{ active: journeyStep === 3 }">
        <span class="cartshift-step-number">3</span>
        <span class="cartshift-step-copy"><strong>Move</strong><small>Safe migration</small></span>
      </li>
    </ol>

    <div class="cartshift-readiness-grid">
      <div class="cartshift-readiness-main">
        <header class="cartshift-readiness-hero" :class="`is-${tone}`" data-test="readiness-hero">
          <span class="cartshift-status-icon dashicons" :class="statusIcon" aria-hidden="true"></span>
          <div>
            <p class="cartshift-eyebrow">Store check</p>
            <h1>{{ heading }}</h1>
            <p>{{ introduction }}</p>
            <button
              v-if="showRefreshAction"
              class="button button-primary button-hero"
              data-test="check-again"
              :disabled="busy"
              @click="$emit('refresh')"
            >
              {{ busy ? 'Checking…' : 'Check again' }}
            </button>
            <button
              v-else-if="needsSetup"
              class="button button-primary button-hero"
              data-test="check-store"
              :disabled="busy"
              @click="$emit('initialise')"
            >
              {{ busy ? 'Preparing…' : 'Check my store' }}
            </button>
            <button
              v-else-if="canStart"
              class="button button-primary button-hero"
              data-test="start"
              :disabled="busy"
              @click="$emit('start')"
            >
              {{ busy ? 'Reviewing…' : startLabel }}
            </button>
          </div>
        </header>

        <section v-if="isBlocked" class="cartshift-attention-card" data-test="blocking-checks">
          <div class="cartshift-section-heading">
            <span class="dashicons dashicons-warning" aria-hidden="true"></span>
            <div><h2>Resolve before continuing</h2><p>CartShift will wait. Nothing in FluentCart will be overwritten.</p></div>
          </div>
          <div v-if="blockingChecks.length" data-test="preflight-blocked">
            <article v-for="check in blockingChecks" :key="check.label" class="cartshift-check-row">
              <span class="dashicons dashicons-dismiss" aria-hidden="true"></span>
              <div><h3>{{ check.label }}</h3><p>{{ check.message }}</p></div>
            </article>
          </div>
          <article v-if="data.plan_blocked" class="cartshift-check-row" data-test="plan-blocked">
            <span class="dashicons dashicons-lock" aria-hidden="true"></span>
            <div><h3>Migration route</h3><p>{{ data.plan_message }}</p></div>
          </article>
        </section>

        <p v-else-if="!needsSetup" class="cartshift-ready-note" data-test="preflight-ready">
          <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
          No blocking issues found. CartShift can prepare the detailed review.
        </p>
      </div>

      <aside class="cartshift-readiness-side">
        <section class="cartshift-side-card">
          <p class="cartshift-eyebrow">Included in the check</p>
          <ul class="cartshift-included-list">
            <li><span class="dashicons dashicons-yes" aria-hidden="true"></span>Products and variations</li>
            <li><span class="dashicons dashicons-yes" aria-hidden="true"></span>Customers and orders</li>
            <li v-if="data.subscriptions?.available && data.plan_blocked" class="cartshift-included-warning">
              <span class="dashicons dashicons-warning" aria-hidden="true"></span>Subscriptions need a supported migration route
            </li>
            <li v-else-if="data.subscriptions?.available">
              <span class="dashicons dashicons-yes" aria-hidden="true"></span>Subscriptions
            </li>
            <li v-else data-test="wcs-skipped">
              <span class="dashicons dashicons-minus" aria-hidden="true"></span>Subscriptions are not active, so they are skipped
            </li>
          </ul>
        </section>

        <details v-if="warningChecks.length" class="cartshift-disclosure" data-test="preflight-warnings">
          <summary>
            <span><strong>Worth knowing</strong><small>{{ warningChecks.length }} non-blocking {{ warningChecks.length === 1 ? 'note' : 'notes' }}</small></span>
            <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
          </summary>
          <article v-for="check in warningChecks" :key="check.label" class="cartshift-warning-row">
            <span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
            <div><h3>{{ check.label }}</h3><p>{{ check.message }}</p></div>
          </article>
        </details>
      </aside>
    </div>

    <details class="cartshift-process" data-test="cutover">
      <summary>
        <span class="dashicons dashicons-editor-help" aria-hidden="true"></span>
        <span><strong>How the move works</strong><small>Review, verify, then activate FluentCart</small></span>
        <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
      </summary>
      <div>
        <p>CartShift checks your store first and asks only about matches it cannot decide safely.</p>
        <p>{{ data.setup?.cutover?.message }}</p>
      </div>
    </details>
  </section>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  data: { type: Object, required: true },
  currentRun: { type: Object, default: null },
  blockingChecks: { type: Array, required: true },
  warningChecks: { type: Array, required: true },
  busy: { type: Boolean, required: true },
  canStart: { type: Boolean, required: true },
  startLabel: { type: String, required: true },
});

defineEmits(['initialise', 'refresh', 'start']);

const needsSetup = computed(() => !props.data.initialised || !props.data.setup?.complete);
const isBlocked = computed(() => props.blockingChecks.length > 0 || props.data.plan_blocked);
const showRefreshAction = computed(() => isBlocked.value && !props.currentRun?.mode_changed);
const journeyStep = computed(() => {
  if (props.currentRun?.phase === 'awaiting_decisions') return 2;
  if (props.currentRun?.phase === 'completed') return 3;
  return 1;
});

const tone = computed(() => {
  if (isBlocked.value) return 'attention';
  if (needsSetup.value) return 'welcome';
  if (props.currentRun) return 'progress';
  return 'ready';
});

const heading = computed(() => {
  if (isBlocked.value) return 'Your store needs attention';
  if (needsSetup.value) return 'Ready to check your store?';
  if (props.currentRun?.phase === 'completed') return 'Migration complete';
  if (props.currentRun?.phase === 'awaiting_decisions') return 'Your review is ready';
  if (props.currentRun) return 'Migration in progress';
  return 'Ready for review';
});

const introduction = computed(() => {
  if (isBlocked.value) return 'Resolve the items below before CartShift continues. Nothing will be overwritten.';
  if (needsSetup.value) return 'CartShift will securely prepare itself and check what can move to FluentCart.';
  if (props.currentRun?.phase === 'completed') return 'Your selected records are now in FluentCart. Review any notes below.';
  if (props.currentRun?.phase === 'awaiting_decisions') return 'Check the choices below. CartShift will not decide these for you.';
  if (props.currentRun) return 'CartShift has saved your progress. Continue from the current step below.';
  return 'CartShift found no blockers. Next, review exactly how your store will move.';
});

const statusIcon = computed(() => ({
  welcome: 'dashicons-store',
  attention: 'dashicons-warning',
  progress: 'dashicons-update',
  ready: 'dashicons-yes-alt',
})[tone.value]);
</script>
