<template>
  <section class="cartshift-review-card" data-test="run-review">
    <header>
      <p class="cartshift-eyebrow">Your choices</p>
      <h3 ref="heading" tabindex="-1">Review what CartShift will do</h3>
      <p>Nothing is saved until you confirm every item.</p>
    </header>
    <div v-if="notice" class="cartshift-alert cartshift-alert--warning" data-test="review-changed" role="status">
      <span class="dashicons dashicons-update" aria-hidden="true"></span><p>{{ notice }}</p>
    </div>
    <fieldset class="cartshift-review-list">
      <legend class="screen-reader-text">Migration decisions</legend>
      <div v-for="item in run.review?.items || []" :key="item.review_id" class="cartshift-decision" data-test="review-decision">
        <input
          v-if="!item.choices?.length"
          type="checkbox"
          :checked="approvals[item.review_id] === true"
          :aria-label="`Approve ${item.title}`"
          @change="$emit('toggle', item.review_id, $event.target.checked)"
        />
        <span class="cartshift-decision-copy"><strong>{{ item.title }}</strong><small>{{ item.summary }}</small></span>
        <fieldset v-if="item.choices?.length" class="cartshift-product-choices">
          <legend class="screen-reader-text">Choose what CartShift should do with {{ item.title }}</legend>
          <label v-for="choice in item.choices" :key="choice.choice_id" data-test="product-choice">
            <input
              type="radio"
              :name="item.review_id"
              :value="choice.choice_id"
              :checked="approvals[item.review_id] === choice.choice_id"
              @change="$emit('toggle', item.review_id, choice.choice_id)"
            />
            <span><strong>{{ choice.label }}</strong><small>{{ choice.description }}</small></span>
          </label>
        </fieldset>
      </div>
    </fieldset>
    <ul v-if="run.review?.blockers?.length" class="cartshift-review-blockers">
      <li v-for="blocker in run.review.blockers" :key="blocker">{{ blocker }}</li>
    </ul>
    <div class="cartshift-review-actions">
      <button class="button" :class="{ 'button-primary': !run.mode_changed && !blocked }" data-test="accept-run-decisions" :disabled="busy || !canAccept" @click="$emit('accept')">
        {{ busy ? 'Saving…' : 'Confirm and continue' }}
      </button>
      <button class="button" :class="{ 'button-primary': run.mode_changed }" :disabled="busy" @click="$emit('cancel')">
        {{ run.mode_changed ? 'Cancel outdated review' : 'Cancel review' }}
      </button>
    </div>
  </section>
</template>

<script setup>
import { ref } from 'vue';

defineProps({
  run: { type: Object, required: true },
  busy: { type: Boolean, required: true },
  approvals: { type: Object, required: true },
  canAccept: { type: Boolean, required: true },
  blocked: { type: Boolean, required: true },
  notice: { type: String, default: null },
});
defineEmits(['toggle', 'accept', 'cancel']);

const heading = ref(null);
defineExpose({ focusHeading: () => heading.value?.focus() });
</script>
