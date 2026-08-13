<template>
  <article class="cartshift-decision" data-test="review-decision">
    <input
      v-if="!item.choices?.length"
      type="checkbox"
      :checked="approval === true"
      :aria-label="`Approve ${item.title}`"
      @change="$emit('toggle', $event.target.checked)"
    />
    <GuidedReviewStory :item="item" />
    <fieldset v-if="item.choices?.length" class="cartshift-product-choices">
      <legend class="screen-reader-text">Choose what CartShift should do with {{ item.title }}</legend>
      <label v-for="choice in item.choices" :key="choice.choice_id" data-test="product-choice">
        <input
          type="radio"
          :name="item.review_id"
          :value="choice.choice_id"
          :checked="approval === choice.choice_id"
          @change="$emit('toggle', choice.choice_id)"
        />
        <span><strong>{{ choice.label }}</strong><small>{{ choice.description }}</small></span>
      </label>
    </fieldset>
  </article>
</template>

<script setup>
import GuidedReviewStory from './GuidedReviewStory.vue';

defineProps({
  item: { type: Object, required: true },
  approval: { type: [Boolean, String], default: null },
});
defineEmits(['toggle']);
</script>
