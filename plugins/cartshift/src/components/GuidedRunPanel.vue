<template>
  <section class="cartshift-run-card" data-test="run-progress">
    <header class="cartshift-run-header">
      <div>
        <p class="cartshift-eyebrow">Saved progress</p>
        <h2>{{ runTitle }}</h2>
        <p v-if="run.phase !== 'unsafe_completion'">
          {{ run.completed_steps }} of {{ run.total_steps }} checks complete<template v-if="run.last_step"> · {{ run.last_step }}</template>
        </p>
      </div>
      <span class="cartshift-progress-value">{{ progress }}%</span>
    </header>
    <div class="cartshift-progress-track" aria-hidden="true"><span :style="{ width: `${progress}%` }"></span></div>

    <div v-if="run.failure" class="cartshift-alert cartshift-alert--danger" data-test="run-failure" role="alert">
      <span class="dashicons dashicons-warning" aria-hidden="true"></span><p>{{ run.failure.message }}</p>
    </div>
    <div v-if="run.mode_changed" class="cartshift-alert cartshift-alert--warning" data-test="run-mode-changed" role="alert">
      <span class="dashicons dashicons-update" aria-hidden="true"></span><p>{{ run.mode_changed }}</p>
    </div>
    <button
      v-if="run.mode_changed && run.phase !== 'awaiting_decisions'"
      class="button button-primary"
      data-test="stop-outdated-run"
      :disabled="busy"
      @click="$emit('continue')"
    >
      {{ busy ? 'Stopping…' : 'Stop this outdated check' }}
    </button>

    <div v-if="run.rollback" class="cartshift-rollback-card" data-test="rollback-preview">
      <template v-if="run.rollback.safe">
        <span class="dashicons dashicons-undo" aria-hidden="true"></span>
        <div>
          <h3>Safe rollback available</h3>
          <p>CartShift can remove {{ run.rollback.deletion_count }} {{ run.rollback.deletion_count === 1 ? 'record' : 'records' }} created by this run. Existing FluentCart data will stay untouched.</p>
          <button class="button" data-test="rollback" :disabled="busy" @click="$emit('rollback')">
            {{ busy ? 'Rolling back…' : 'Roll back this run' }}
          </button>
        </div>
      </template>
      <template v-else>
        <span class="dashicons dashicons-lock" aria-hidden="true"></span>
        <div><h3>Rollback needs attention</h3><p>FluentCart changed after this run, so CartShift will not remove anything automatically.</p></div>
      </template>
    </div>

    <section v-if="run.migration_exceptions?.length" class="cartshift-stock-report" data-test="migration-exceptions">
      <header><span class="dashicons dashicons-archive" aria-hidden="true"></span><div><h3>Shared stock needs manual setup</h3><p>Affected variations stay unavailable, preventing accidental overselling.</p></div></header>
      <article v-for="(item, itemIndex) in run.migration_exceptions" :key="itemIndex">
        <h4>{{ item.title }}</h4>
        <p>{{ item.message }}</p>
        <p v-if="item.target_state === 'confirmed'"><strong>CartShift verified the safe unavailable state in FluentCart.</strong></p>
        <p v-else-if="item.target_state === 'needs_review'"><strong>Keep these variations unavailable and inspect them before selling.</strong></p>
        <p v-if="item.source_quantity_state === 'known'">Original product-wide shared quantity: {{ item.source_quantity }}.</p>
        <p v-else-if="item.source_quantity_state === 'below_zero'">WooCommerce reported stock below zero. Count physical stock before enabling it.</p>
        <p v-else>WooCommerce did not provide a shared quantity. Count physical stock before enabling it.</p>
        <ul><li v-for="(variation, variationIndex) in item.variations" :key="variationIndex">{{ variation.title }}<template v-if="variation.sku"> — SKU {{ variation.sku }}</template></li></ul>
        <ul class="cartshift-suggestions"><li v-for="suggestion in item.suggestions" :key="suggestion">{{ suggestion }}</li></ul>
      </article>
    </section>
  </section>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({ run: { type: Object, required: true }, busy: { type: Boolean, required: true } });
defineEmits(['continue', 'rollback']);

const progress = computed(() => Math.min(100, Math.round((props.run.completed_steps / props.run.total_steps) * 100) || 0));
const runTitle = computed(() => {
  if (props.run.phase === 'awaiting_decisions') return 'Waiting for your review';
  if (props.run.phase === 'failed' || props.run.phase === 'unsafe_completion') return 'The review stopped safely';
  if (props.run.phase === 'rolled_back') return 'Rollback complete';
  if (props.run.phase === 'cancelled') return 'Review cancelled';
  return 'Reviewing your store';
});
</script>
