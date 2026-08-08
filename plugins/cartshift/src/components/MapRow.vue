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
    </td>

    <td class="cartshift-map-actions">
      <span v-if="row.decision" class="cartshift-map-decided">{{ row.decision.decision }}</span>
      <template v-else>
        <button v-if="row.suggested" type="button" class="button" data-action="link" @click="$emit('decide', 'link')">
          Link
        </button>
        <button type="button" class="button" data-action="create" @click="$emit('decide', 'create')">
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
defineProps({
  row: { type: Object, required: true },
});

defineEmits(['decide', 'suggest']);
</script>
