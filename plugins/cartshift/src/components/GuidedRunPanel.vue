<template>
  <section class="cartshift-run-card" data-test="run-progress">
    <header class="cartshift-run-header">
      <div>
        <p class="cartshift-eyebrow">Saved progress</p>
        <h2>{{ runTitle }}</h2>
        <p>
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

    <section v-if="run.renewal_pause" class="cartshift-rollback-card" data-test="renewal-pause">
      <span class="dashicons dashicons-controls-pause" aria-hidden="true"></span>
      <div>
        <h3>{{ run.renewal_pause.title }}</h3>
        <p>{{ run.renewal_pause.message }}</p>
        <button
          class="button button-primary"
          data-test="confirm-renewals-paused"
          :disabled="busy"
          @click="$emit('confirm-renewals')"
        >
          {{ busy ? 'Continuing…' : run.renewal_pause.action }}
        </button>
      </div>
    </section>

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
      <header><span class="dashicons dashicons-archive" aria-hidden="true"></span><div><h3>Migration follow-up</h3><p>Review anything that still needs your attention after migration.</p></div></header>
      <article v-for="(item, itemIndex) in run.migration_exceptions" :key="itemIndex">
        <h4>{{ item.title }}</h4>
        <p>{{ item.message }}</p>
        <template v-if="item.type === 'shared_stock'">
          <p><strong>Shared stock needs manual setup. Affected variations stay inactive and unavailable, preventing accidental overselling.</strong></p>
          <p v-if="item.target_state === 'confirmed'"><strong>CartShift verified the safe unavailable state in FluentCart.</strong></p>
          <p v-else-if="item.target_state === 'needs_review'"><strong>Keep these variations unavailable and inspect them before selling.</strong></p>
          <p v-if="item.source_quantity_state === 'known'">Original product-wide shared quantity: {{ item.source_quantity }}.</p>
          <p v-else-if="item.source_quantity_state === 'below_zero'">WooCommerce reported stock below zero. Count physical stock before enabling it.</p>
          <p v-else>WooCommerce did not provide a shared quantity. Count physical stock before enabling it.</p>
          <ul><li v-for="(variation, variationIndex) in item.variations" :key="variationIndex">{{ variation.title }}<template v-if="variation.sku"> — SKU {{ variation.sku }}</template></li></ul>
          <ul class="cartshift-suggestions"><li v-for="suggestion in item.suggestions" :key="suggestion">{{ suggestion }}</li></ul>
        </template>
        <template v-else-if="item.type === 'sku_change'">
          <p><strong>All variations were kept. Only their FluentCart SKUs changed.</strong></p>
          <ul>
            <li v-for="(variation, variationIndex) in item.variations" :key="variationIndex">
              {{ variation.title }} — {{ variation.source_sku }} → {{ variation.target_sku }}
            </li>
          </ul>
          <ul class="cartshift-suggestions"><li v-for="suggestion in item.suggestions" :key="suggestion">{{ suggestion }}</li></ul>
        </template>
        <template v-else-if="item.type === 'fulfilment_summary'">
          <p><strong>{{ item.order_count }} {{ item.order_count === 1 ? 'order reviewed' : 'orders reviewed' }} together.</strong></p>
          <ul>
            <li>{{ item.delivered_count }} marked delivered from completed WooCommerce orders</li>
            <li>{{ item.unshipped_count }} kept unshipped because they were not completed</li>
            <li v-if="item.mixed_count">{{ item.mixed_count }} mixed physical and digital {{ item.mixed_count === 1 ? 'order keeps' : 'orders keep' }} digital items fulfilled</li>
          </ul>
        </template>
        <template v-else-if="item.type === 'historical_line_summary'">
          <p v-if="item.line_count === 1"><strong>1 order item kept with its name, price and product link.</strong></p>
          <p v-else><strong>{{ item.line_count }} order items kept with their names, prices and product links.</strong></p>
          <p>No replacement variation was guessed. The historical order remains accurate even though its old variation is gone.</p>
        </template>
      </article>
    </section>
  </section>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({ run: { type: Object, required: true }, busy: { type: Boolean, required: true } });
defineEmits(['continue', 'confirm-renewals', 'rollback']);

const progress = computed(() => Math.min(100, Math.round((props.run.completed_steps / props.run.total_steps) * 100) || 0));
const runTitle = computed(() => {
  if (props.run.phase === 'awaiting_decisions') return 'Waiting for your review';
  if (props.run.phase === 'awaiting_renewal_pause') return 'Ready to move subscription renewals';
  if (props.run.phase === 'completed') return 'Migration complete';
  if (props.run.phase === 'failed') return 'Migration stopped safely';
  if (props.run.phase === 'rolled_back') return 'Rollback complete';
  if (props.run.phase === 'cancelled') return 'Review cancelled';
  return 'Moving your store';
});
</script>
