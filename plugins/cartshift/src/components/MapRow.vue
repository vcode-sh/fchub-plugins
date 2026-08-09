<template>
  <tr class="cartshift-map-row" :class="{ 'is-decided': !!row.decision }">
    <td class="cartshift-map-woo">
      <strong>{{ row.name }}</strong>
      <span class="description">
        {{ row.wc_type }} &middot; {{ row.variations }} variation(s) &middot; {{ row.order_count }} orders
      </span>
    </td>

    <td class="cartshift-map-fc">
      <select
        v-if="row.candidates.length"
        :value="row.suggested"
        @change="$emit('suggest', Number($event.target.value))"
      >
        <option v-for="candidate in row.candidates" :key="candidate.id" :value="candidate.id">
          {{ candidate.label }}
        </option>
      </select>
      <span v-else class="description">Will be created</span>

      <!-- The matcher scores a product it does not recognise as "no candidate",
           which is honest and, on its own, a dead end: the two Lapka
           subscription products both scored none, and both have to be mapped.
           So the whole catalogue is one button away, always. -->
      <button type="button" class="button-link" data-action="search" @click="$emit('search')">
        Search the FluentCart catalogue
      </button>

      <!-- The one place CartShift writes into a product the owner built by
           hand, so it is said out loud before they press anything. -->
      <span v-if="row.variant" class="description cartshift-map-variants">
        {{ row.variant.matched }}/{{ row.variant.total }} variants matched<template
          v-if="row.variant.adds"
        >
          &middot; adds {{ row.variant.adds }}</template
        >
      </span>

      <!-- CartShift will not write the Woo product's files into a product the
           owner built by hand — which of their variants would each file belong
           to? — so the customer-facing consequence is said here, where the
           owner is looking at the row and the alternatives are one dropdown
           away. Promotion warns again at run time, by which point the fix is
           manual. -->
      <span v-if="row.downloads_lost" class="description cartshift-map-downloads">
        Linked product has no files &mdash; migrated orders will show none
      </span>

      <!-- A subscription's variation is a billing contract, not a size. Every
           target variation is listed, compatible or not, because "this one is
           refused and here is why" is the only version of this screen an owner
           can act on. -->
      <div v-if="isSubscription" class="cartshift-map-contracts">
        <div v-for="source in row.variant.sources" :key="source.id" class="cartshift-map-source">
          <span class="description">
            {{ source.name }} bills
            {{ source.interval || 'on an interval FluentCart cannot express' }}
          </span>

          <label
            v-for="option in source.options"
            :key="option.id"
            class="cartshift-map-variation"
            :class="{ 'is-refused': !option.compatible }"
            :data-variation="option.id"
          >
            <input
              type="radio"
              :name="`variation-${row.wc_id}-${source.id}`"
              :disabled="!option.compatible"
              :checked="source.selected === option.id"
              @change="$emit('choose-variation', source.id, option.id)"
            />
            <strong>{{ option.name }}</strong>
            <span class="description">
              {{ option.payment_type }}
              <template v-if="option.repeat_interval"> &middot; {{ option.repeat_interval }}</template>
              &middot; {{ option.price }}
              <template v-if="option.trial_days"> &middot; {{ option.trial_days }}-day trial</template>
              &middot;
              <template v-if="option.times">{{ option.times }} payments then it ends</template>
              <template v-else>runs until cancelled</template>
            </span>
            <span v-for="code in option.errors" :key="code" class="cartshift-map-refused">
              {{ reasonCopy(code) }}
            </span>
            <span v-for="code in option.warnings" :key="code" class="description">
              {{ reasonCopy(code) }}
            </span>
          </label>
        </div>

        <!-- Off unless the owner says so, and asked for per row rather than
             once for the screen: it is permission for these exact products to
             land on one variation, not a global preference. -->
        <label class="cartshift-map-shared" data-shared-target>
          <input
            type="checkbox"
            :checked="!!row.allow_shared_target"
            @change="$emit('share-target', $event.target.checked)"
          />
          Another product may already use this variation
        </label>
      </div>

      <p v-for="error in blockingErrors" :key="error.code" class="cartshift-map-refused">
        {{ error.message }}
      </p>
    </td>

    <td class="cartshift-map-actions">
      <span v-if="row.decision" class="cartshift-map-decided">{{ row.decision.decision }}</span>
      <template v-else>
        <button
          v-if="row.suggested && !blockingErrors.length"
          type="button"
          class="button"
          data-action="link"
          @click="$emit('decide', 'link')"
        >
          Link
        </button>
        <button
          v-if="!blocksCreation"
          type="button"
          class="button"
          data-action="create"
          @click="$emit('decide', 'create')"
        >
          Create
        </button>
        <button type="button" class="button" data-action="skip" @click="$emit('decide', 'skip')">
          Skip
        </button>
      </template>
    </td>
  </tr>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  row: { type: Object, required: true },
});

defineEmits(['decide', 'suggest', 'search', 'choose-variation', 'share-target']);

const isSubscription = computed(() => (props.row.variant?.sources || []).length > 0);

// A blocked source variation is not a thing to save around. Section 7.4: an
// orphan subscription variation stops the mapping until the owner creates or
// picks a compatible FluentCart variation — CartShift will not invent one, and
// it certainly will not invent it as a one-time purchase.
const blockingErrors = computed(() => props.row.variant?.errors || []);

// AND "CREATE" IS NOT THE WAY ROUND IT.
//
// The Create route runs `ProductMigrator` and `VariationMapper`, which read the
// cadence leniently — `week/2` becomes weekly, `month/2` and `month/12` become
// monthly — so an operator answering "CartShift cannot express this contract"
// with Create would get a FluentCart product quietly claiming a different one.
// The run refuses it anyway (`MigrationOrchestratorFactory::
// unrepresentableSubscriptionProductIds()`), which means the button's only
// possible outcome was a product silently dropped from the migration.
const blocksCreation = computed(
  () => isSubscription.value && blockingErrors.value.length > 0,
);

// The codes are the contract; this is the copy. Kept here rather than on the
// server so the wire stays machine-readable for the CLI and the receipts.
const REASONS = {
  target_variation_contract_mismatch: 'Wrong billing interval for this product.',
  // Emitted when another variation has already taken it — of this product, or
  // of another one in the same decision set. A dimmed option with no reason on
  // it is the one case where this screen refuses something and explains
  // nothing, which is worse than refusing nothing at all.
  target_variation_contract_collision: 'Already used by another variation.',
  unsupported_billing_cadence: 'FluentCart cannot express this billing interval exactly.',
  target_variation_missing: 'No compatible variation on this product.',
  target_price_differs_from_source:
    'List price differs. Existing subscribers keep their own amount — the source contract is preserved.',
};

function reasonCopy(code) {
  return REASONS[code] || code;
}
</script>
