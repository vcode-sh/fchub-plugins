<template>
  <section class="cartshift-review-card" data-test="run-review">
    <header>
      <p class="cartshift-eyebrow">Your choices</p>
      <h3 ref="heading" tabindex="-1">Review what CartShift will do</h3>
      <p>Routine approvals are grouped. Choices that change the result stay separate.</p>
    </header>
    <div v-if="notice" class="cartshift-alert cartshift-alert--warning" data-test="review-changed" role="status">
      <span class="dashicons dashicons-update" aria-hidden="true"></span><p>{{ notice }}</p>
    </div>
    <div class="cartshift-review-summary" data-test="review-summary" aria-live="polite">
      <span><strong>{{ completedCount }} of {{ itemCount }} complete</strong><small>{{ remainingCopy }}</small></span>
      <span class="cartshift-review-summary-count">{{ Math.round((completedCount / itemCount) * 100) }}%</span>
      <span class="cartshift-review-summary-track" aria-hidden="true"><i :style="{ width: `${(completedCount / itemCount) * 100}%` }"></i></span>
    </div>
    <div class="cartshift-review-list" role="group" aria-label="Migration decisions">
      <section
        v-for="group in groups"
        :key="group.key"
        class="cartshift-review-group"
        :data-test="`review-group-${group.key}`"
      >
        <header class="cartshift-review-group-header">
          <span class="dashicons" :class="group.icon" aria-hidden="true"></span>
          <span><strong>{{ group.title }}</strong><small>{{ group.items.length }} {{ group.items.length === 1 ? 'item' : 'items' }}</small></span>
          <button
            v-if="group.routineItems.length > 1"
            type="button"
            class="button button-small"
            data-test="approve-group"
            :disabled="busy"
            @click="$emit('bulk-toggle', group.routineItems.map((item) => item.review_id), !group.allRoutineApproved)"
          >
            {{ group.allRoutineApproved ? 'Clear approvals' : `Approve all ${group.routineItems.length}` }}
          </button>
        </header>
        <details :open="group.items.length <= 5 || group.hasChoices">
          <summary>
            <span>{{ group.items.length > 5 ? 'Show individual items' : 'Review items' }}</span>
            <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
          </summary>
          <div class="cartshift-review-group-items">
            <div v-for="item in group.items" :key="item.review_id" class="cartshift-decision" data-test="review-decision">
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
          </div>
        </details>
      </section>
    </div>
    <ul v-if="run.review?.blockers?.length" class="cartshift-review-blockers">
      <li v-for="blocker in run.review.blockers" :key="blocker">{{ blocker }}</li>
    </ul>
    <div class="cartshift-review-actions">
      <button class="button" :class="{ 'button-primary': !run.mode_changed && !blocked }" data-test="accept-run-decisions" :disabled="busy || !canAccept" @click="$emit('accept')">
        {{ busy ? 'Saving…' : `Confirm ${itemCount} ${itemCount === 1 ? 'decision' : 'decisions'}` }}
      </button>
      <button class="button" :class="{ 'button-primary': run.mode_changed }" :disabled="busy" @click="$emit('cancel')">
        {{ run.mode_changed ? 'Cancel outdated review' : 'Cancel review' }}
      </button>
    </div>
  </section>
</template>

<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
  run: { type: Object, required: true },
  busy: { type: Boolean, required: true },
  approvals: { type: Object, required: true },
  canAccept: { type: Boolean, required: true },
  blocked: { type: Boolean, required: true },
  notice: { type: String, default: null },
});
defineEmits(['toggle', 'bulk-toggle', 'accept', 'cancel']);

const groupPresentation = {
  products: { title: 'Products', icon: 'dashicons-products' },
  customers: { title: 'Customers', icon: 'dashicons-admin-users' },
  orders: { title: 'Orders', icon: 'dashicons-cart' },
  subscriptions: { title: 'Subscriptions', icon: 'dashicons-update' },
  other: { title: 'Other decisions', icon: 'dashicons-yes-alt' },
};
const itemCount = computed(() => props.run.review?.items?.length || 0);
const completedCount = computed(() => (props.run.review?.items || []).filter(isComplete).length);
const remainingCopy = computed(() => {
  const remaining = itemCount.value - completedCount.value;
  return remaining === 0 ? 'Ready to continue' : `${remaining} ${remaining === 1 ? 'decision' : 'decisions'} left`;
});
const groups = computed(() => {
  const grouped = new Map();
  (props.run.review?.items || []).forEach((item) => {
    const key = groupPresentation[item.group] ? item.group : legacyGroup(item.kind);
    if (!grouped.has(key)) grouped.set(key, []);
    grouped.get(key).push(item);
  });

  return Object.keys(groupPresentation).flatMap((key) => {
    const items = grouped.get(key);
    if (!items?.length) return [];
    const routineItems = items.filter((item) => !item.choices?.length);
    return [{
      key,
      ...groupPresentation[key],
      items,
      routineItems,
      hasChoices: items.some((item) => item.choices?.length),
      allRoutineApproved: routineItems.length > 0 && routineItems.every((item) => props.approvals[item.review_id] === true),
    }];
  });
});

function isComplete(item) {
  return item.choices?.length
    ? typeof props.approvals[item.review_id] === 'string'
    : props.approvals[item.review_id] === true;
}

function legacyGroup(kind) {
  if (kind?.startsWith('product_')) return 'products';
  if (kind?.startsWith('customer_')) return 'customers';
  if (kind === 'record_collision') return 'orders';
  return 'other';
}

const heading = ref(null);
defineExpose({ focusHeading: () => heading.value?.focus() });
</script>
