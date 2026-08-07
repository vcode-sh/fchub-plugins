<template>
  <div>
    <PageHeader title="Select Entities to Migrate" />
    <p>Choose which WooCommerce data to migrate to FluentCart. Dependencies are respected automatically.</p>

    <div class="cartshift-select-layout">
      <div class="cartshift-select-scope">
        <!-- Surfaces both the 422 scope-too-large refusal (Task 10) and the
             empty-selection guard — startMigration() flips the screen back
             to 'select' and sets state.error, but leaves nothing on this
             screen to say so unless it is rendered here. -->
        <div v-if="state.error" class="notice notice-error inline" role="alert">
          <p>{{ state.error }}</p>
        </div>

        <fieldset class="cartshift-scope-modes">
          <legend>How much do you want to migrate?</legend>

          <label class="cartshift-scope-mode-option">
            <input
              type="radio"
              name="cartshift-scope-mode"
              value="everything"
              :checked="state.scope.mode === 'everything'"
              @change="actions.setScopeMode('everything')"
            />
            <strong>Everything</strong>
          </label>

          <label class="cartshift-scope-mode-option">
            <input
              type="radio"
              name="cartshift-scope-mode"
              value="since"
              :checked="state.scope.mode === 'since'"
              @change="actions.setScopeMode('since')"
            />
            <strong>Everything from a date</strong>
          </label>

          <div v-if="state.scope.mode === 'since'" class="cartshift-scope-mode-detail">
            <label>
              Migrate orders, and everything they touch, from this date onward.
              <input type="date" class="cartshift-scope-since" v-model="state.scope.since" />
            </label>
          </div>

          <label class="cartshift-scope-mode-option">
            <input
              type="radio"
              name="cartshift-scope-mode"
              value="explicit"
              :checked="state.scope.mode === 'explicit'"
              @change="actions.setScopeMode('explicit')"
            />
            <strong>Let me choose</strong>
          </label>

          <div v-if="state.scope.mode === 'explicit'" class="cartshift-scope-mode-detail">
            <ScopePicker v-model="state.scope.products" kind="product" />
            <ScopePicker v-model="state.scope.customers" kind="customer" />

            <div v-if="showUpwardOffer" class="cartshift-option-box cartshift-upward-offer-box">
              <label>
                <input
                  type="checkbox"
                  class="cartshift-upward-offer"
                  v-model="state.scope.includeOrdersForProducts"
                />
                {{ upwardOfferText }}
              </label>
            </div>
          </div>
        </fieldset>

        <table class="widefat striped">
          <thead>
            <tr><th></th><th>Entity</th><th>Count</th><th>Dependencies</th></tr>
          </thead>
          <tbody>
            <tr v-for="entity in entities" :key="entity.key">
              <td>
                <input
                  type="checkbox"
                  :checked="state.selectedEntities.indexOf(entity.key) !== -1"
                  :disabled="entity.key === 'subscription' && !wcsActive"
                  @change="toggleEntity(entity.key, $event)"
                />
              </td>
              <td><strong>{{ entity.label }}</strong></td>
              <td>{{ getCount(entity.key) }}</td>
              <td>
                <em v-if="entity.dep">{{ entity.dep }}</em>
                <template v-else>-</template>
              </td>
            </tr>
          </tbody>
        </table>

        <p v-if="!wcsActive" class="description">
          Subscription migration is disabled because WooCommerce Subscriptions is not active.
        </p>

        <div class="cartshift-option-box">
          <label>
            <input type="checkbox" class="cartshift-dry-run" v-model="state.dryRun" />
            <strong>Dry run</strong> &mdash; validate data mapping without writing to FluentCart. Shows what would be migrated.
          </label>
        </div>

        <div class="cartshift-option-box">
          <label>
            <input
              type="checkbox"
              class="cartshift-background"
              v-model="state.useBackground"
              :disabled="!state.backgroundAvailable"
            />
            <strong>Run in the background</strong> &mdash; hand the batches to Action Scheduler so the
            migration survives this tab being closed. Progress updates every couple of seconds instead
            of live. Leave this off for a real-time run you intend to watch.
          </label>
          <p v-if="!state.backgroundAvailable" class="description">
            Unavailable: Action Scheduler was not found. It ships with both WooCommerce and FluentCart,
            so this normally means neither is loaded.
          </p>
        </div>

        <p style="margin-top:15px;">
          <button
            class="button button-primary button-hero"
            :disabled="state.migrating"
            @click="actions.startMigration()"
          >
            Start migration
          </button>
          <button class="button" @click="actions.goToScreen('preflight')">Back</button>
        </p>
      </div>

      <div class="cartshift-select-receipt">
        <MigrationReceipt
          :preview="state.preview"
          :counts="state.counts"
          :loading="state.previewLoading"
          @apply-remedy="actions.applyRemedy($event)"
        />
      </div>
    </div>
  </div>
</template>

<script setup>
import { inject, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { ENTITIES } from '@/composables/useMigration.js';
import PageHeader from './PageHeader.vue';
import ScopePicker from './ScopePicker.vue';
import MigrationReceipt from './MigrationReceipt.vue';

const DEBOUNCE_MS = 300;

const { state, actions } = inject('migration');

const entities = ENTITIES;

const wcsActive = computed(() => {
  return state.preflight?.checks?.wc_subscriptions?.active;
});

function getCount(key) {
  if (!state.counts) return '?';
  return state.counts[key] ?? state.counts[key + 's'] ?? 0;
}

function toggleEntity(key, event) {
  const checked = event.target.checked;
  if (checked) {
    if (state.selectedEntities.indexOf(key) === -1) {
      state.selectedEntities.push(key);
    }
  } else {
    const idx = state.selectedEntities.indexOf(key);
    if (idx !== -1) {
      state.selectedEntities.splice(idx, 1);
    }
  }
}

// The upward offer only makes sense once the owner has picked at least one
// product, and only once a preview has actually come back — before that the
// closure numbers it quotes do not exist yet.
const showUpwardOffer = computed(() => {
  return state.scope.mode === 'explicit' && state.scope.products.length > 0 && !!state.preview;
});

/**
 * Deliberately qualitative, no numbers. `closure.products`/`closure.customers`
 * describe what including-orders-for-products *would* add, but the resolver
 * only computes that once `includeOrdersForProducts` is true
 * (ScopeResolver::seedOrderPredicate()) — before the tick, the closure the
 * preview just reported is the closure of the picks alone, so it is 0/0 here
 * regardless of what ticking the box would actually pull in. Quoting those
 * numbers on the offer itself would present a zero, or someone else's
 * customer-driven closure, as the answer to a question that has not been
 * asked yet. The real, verified numbers appear in the receipt's own closure
 * note once the box is ticked and a fresh preview comes back.
 */
const upwardOfferText = computed(() => {
  const picked = state.scope.products.length;
  const pickedWord = picked === 1 ? 'product' : 'products';

  return (
    `You picked ${picked.toLocaleString()} ${pickedWord}. Orders have to come complete, so this ` +
    `also brings in the other products and buyers on those orders — the summary will show ` +
    `exactly how many.`
  );
});

// Every scope change — mode, date, picked items, the upward offer — asks the
// server again, debounced so editing the picker does not fire a query per
// keystroke. This is the one place scope state is read to trigger it; it is
// still setScopeMode()/v-model writes that mutate state.scope itself.
//
// The entity ticks are watched too: ScopePreview::build() keys `counts` off
// exactly the `entity_types` list refreshPreview() sends, so unticking
// "Coupons" without asking again would leave the receipt showing coupons
// forever.
let debounceTimer = null;

function scheduleRefresh() {
  if (debounceTimer !== null) {
    clearTimeout(debounceTimer);
  }
  debounceTimer = setTimeout(() => {
    debounceTimer = null;
    actions.refreshPreview();
  }, DEBOUNCE_MS);
}

watch([() => state.scope, () => state.selectedEntities], scheduleRefresh, { deep: true });

// The receipt is the primary feedback surface, but with no preview yet it
// shows nothing past the whole-shop counts — no consequences, no "Nothing
// left behind", no remedies (MigrationReceipt.vue gates all of that on
// `preview`). Ask once on arrival so the default "Everything" door is not a
// blank read.
onMounted(() => {
  actions.refreshPreview();
});

onBeforeUnmount(() => {
  if (debounceTimer !== null) {
    clearTimeout(debounceTimer);
    debounceTimer = null;
  }
});
</script>
