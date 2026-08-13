<template>
  <section class="cartshift-review-card" data-test="run-review">
    <header>
      <p class="cartshift-eyebrow">Your choices</p>
      <h3 ref="heading" tabindex="-1">Review the migration plan</h3>
      <p>Approve the safe plan in batches. CartShift asks separately only when your choice changes the result.</p>
    </header>

    <div v-if="notice" class="cartshift-alert cartshift-alert--warning" data-test="review-changed" role="status">
      <span class="dashicons dashicons-update" aria-hidden="true"></span><p>{{ notice }}</p>
    </div>

    <section v-if="run.review?.source_scope" class="cartshift-scope-summary" data-test="source-scope-summary">
      <span class="dashicons dashicons-filter" aria-hidden="true"></span>
      <div>
        <h4>What CartShift will leave alone</h4>
        <ul>
          <li v-if="run.review.source_scope.omitted_subscriptions">
            {{ run.review.source_scope.omitted_subscriptions }} ended subscriptions stay in WooCommerce.
          </li>
          <li v-if="run.review.source_scope.omitted_wordpress_accounts">
            {{ run.review.source_scope.omitted_wordpress_accounts }} unrelated WordPress accounts stay untouched.
          </li>
          <li v-if="run.review.source_scope.guest_order_profiles">
            {{ run.review.source_scope.guest_order_profiles }} guest checkouts move only with their orders
            ({{ run.review.source_scope.unique_guest_emails }} guest email addresses). No WordPress accounts are created for guests.
          </li>
          <li v-if="run.review.source_scope.unlinked_order_profiles">
            {{ run.review.source_scope.unlinked_order_profiles }} orders move without a customer profile because WooCommerce has no usable customer email.
          </li>
        </ul>
      </div>
    </section>

    <div class="cartshift-review-summary" data-test="review-summary" aria-live="polite">
      <span><strong>{{ completedStepCount }} of {{ reviewStepCount }} steps complete</strong><small>{{ remainingCopy }}</small></span>
      <span class="cartshift-review-summary-count">{{ progress }}%</span>
      <span class="cartshift-review-summary-track" aria-hidden="true"><i :style="{ width: `${progress}%` }"></i></span>
    </div>

    <div class="cartshift-review-sections">
      <section v-if="safeItems.length" class="cartshift-review-section cartshift-review-section--safe" data-test="review-safe-plan">
        <div class="cartshift-review-section-heading">
          <span class="cartshift-review-section-icon"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span></span>
          <div>
            <p class="cartshift-eyebrow">Safe plan</p>
            <h4>Ready to move</h4>
            <p>{{ itemCount(safeItems) }} can follow CartShift's safe defaults. Nothing existing in FluentCart will be overwritten.</p>
          </div>
          <button
            type="button"
            class="button"
            data-test="approve-safe-plan"
            :disabled="busy"
            @click="toggleBatch(safeItems)"
          >
            {{ allComplete(safeItems) ? 'Clear selection' : `Approve ${safeItems.length} ready ${safeItems.length === 1 ? 'item' : 'items'}` }}
          </button>
        </div>
        <ReviewDetails
          section-key="safe_plan"
          label="Review included items"
          :items="safeItems"
          :open="detailsOpen.safe_plan"
          :limit="visibleLimits.safe_plan"
          @toggle="detailsOpen.safe_plan = $event"
          @show-more="visibleLimits.safe_plan += pageSize"
        >
          <ReviewItem
            v-for="item in safeItems.slice(0, visibleLimits.safe_plan)"
            :key="item.review_id"
            :item="item"
            :approval="approvals[item.review_id]"
            @toggle="emit('toggle', item.review_id, $event)"
          />
        </ReviewDetails>
      </section>

      <section v-if="choiceItems.length" class="cartshift-review-section cartshift-review-section--choice" data-test="review-choices">
        <div class="cartshift-review-section-heading cartshift-review-section-heading--simple">
          <span class="cartshift-review-section-icon"><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span></span>
          <div>
            <p class="cartshift-eyebrow">Your decision</p>
            <h4>Needs your choice</h4>
            <p>{{ choiceItems.length }} {{ choiceItems.length === 1 ? 'match changes' : 'matches change' }} what CartShift will do.</p>
          </div>
        </div>

        <article v-if="suggestedCustomerItems.length" class="cartshift-review-recommendations">
          <div>
            <strong>Suggested customer matches</strong>
            <p>{{ suggestedCustomerItems.length }} customers have one clear existing FluentCart match. You can accept the suggestions together or inspect them first.</p>
          </div>
          <button
            type="button"
            class="button"
            data-test="apply-suggested-customer-matches"
            :disabled="busy"
            @click="toggleBatch(suggestedCustomerItems)"
          >
            {{ allComplete(suggestedCustomerItems) ? 'Clear suggestions' : `Use ${suggestedCustomerItems.length} suggested ${suggestedCustomerItems.length === 1 ? 'match' : 'matches'}` }}
          </button>
          <ReviewDetails
            section-key="customer_matches"
            label="Review customer matches"
            :items="suggestedCustomerItems"
            :open="detailsOpen.customer_matches"
            :limit="visibleLimits.customer_matches"
            @toggle="detailsOpen.customer_matches = $event"
            @show-more="visibleLimits.customer_matches += pageSize"
          >
            <ReviewItem
              v-for="item in suggestedCustomerItems.slice(0, visibleLimits.customer_matches)"
              :key="item.review_id"
              :item="item"
              :approval="approvals[item.review_id]"
              @toggle="emit('toggle', item.review_id, $event)"
            />
          </ReviewDetails>
        </article>

        <div v-if="explicitChoiceItems.length" class="cartshift-review-choice-list">
          <div
            v-for="item in explicitChoiceItems"
            :key="item.review_id"
            :data-test="`explicit-choice-${item.review_id}`"
          >
            <ReviewItem
              :item="item"
              :approval="approvals[item.review_id]"
              @toggle="emit('toggle', item.review_id, $event)"
            />
          </div>
        </div>
      </section>

      <section v-if="staysItems.length" class="cartshift-review-section cartshift-review-section--stays" data-test="review-stays-behind">
        <div class="cartshift-review-section-heading">
          <span class="cartshift-review-section-icon"><span class="dashicons dashicons-lock" aria-hidden="true"></span></span>
          <div>
            <p class="cartshift-eyebrow">Duplicate protection</p>
            <h4>Stays in WooCommerce</h4>
            <p>{{ staysItems.length }} {{ staysItems.length === 1 ? 'item already has' : 'items already have' }} an existing or incompatible destination. CartShift will leave the WooCommerce copy in place and will not change FluentCart.</p>
            <small v-if="staysItems.some((item) => item.target_story)">Identity conflicts are marked “Already in FluentCart” in the details.</small>
          </div>
          <button
            type="button"
            class="button"
            data-test="acknowledge-stays-behind"
            :disabled="busy"
            @click="toggleBatch(staysItems)"
          >
            {{ allComplete(staysItems) ? 'Clear selection' : `Acknowledge ${staysItems.length} ${staysItems.length === 1 ? 'item' : 'items'}` }}
          </button>
        </div>
        <ReviewDetails
          section-key="stays_behind"
          label="Review items staying behind"
          :items="staysDisplayItems"
          :open="detailsOpen.stays_behind"
          :limit="visibleLimits.stays_behind"
          @toggle="detailsOpen.stays_behind = $event"
          @show-more="visibleLimits.stays_behind += pageSize"
        >
          <template v-for="item in staysDisplayItems.slice(0, visibleLimits.stays_behind)" :key="item.review_id">
            <article v-if="item.aggregate" class="cartshift-review-aggregate" data-test="review-aggregate">
              <span class="dashicons dashicons-list-view" aria-hidden="true"></span>
              <div><strong>{{ item.title }}</strong><p>{{ item.summary }}</p></div>
            </article>
            <ReviewItem
              v-else
              :item="item"
              :approval="approvals[item.review_id]"
              @toggle="emit('toggle', item.review_id, $event)"
            />
          </template>
        </ReviewDetails>
      </section>
    </div>

    <ul v-if="run.review?.blockers?.length" class="cartshift-review-blockers">
      <li v-for="blocker in run.review.blockers" :key="blocker">{{ blocker }}</li>
    </ul>
    <div class="cartshift-review-actions">
      <button class="button" :class="{ 'button-primary': !run.mode_changed && !blocked }" data-test="accept-run-decisions" :disabled="busy || !canAccept" @click="emit('accept')">
        {{ busy ? 'Saving…' : 'Confirm review' }}
      </button>
      <button class="button" :class="{ 'button-primary': run.mode_changed }" data-test="cancel-review" :disabled="busy" @click="emit('cancel')">
        {{ run.mode_changed ? 'Cancel outdated review' : 'Cancel review' }}
      </button>
    </div>
  </section>
</template>

<script setup>
import { computed, reactive, ref } from 'vue';
import ReviewDetails from './GuidedReviewDetails.vue';
import ReviewItem from './GuidedReviewItem.vue';

const props = defineProps({
  run: { type: Object, required: true },
  busy: { type: Boolean, required: true },
  approvals: { type: Object, required: true },
  canAccept: { type: Boolean, required: true },
  blocked: { type: Boolean, required: true },
  notice: { type: String, default: null },
});
const emit = defineEmits(['toggle', 'accept', 'cancel']);

const pageSize = 20;
const items = computed(() => props.run.review?.items || []);
const safeItems = computed(() => items.value.filter((item) => sectionFor(item) === 'safe_plan'));
const choiceItems = computed(() => items.value.filter((item) => sectionFor(item) === 'choices'));
const staysItems = computed(() => items.value.filter((item) => sectionFor(item) === 'stays_behind'));
const staysDisplayItems = computed(() => aggregateTechnicalItems(staysItems.value));
const suggestedCustomerItems = computed(() => choiceItems.value.filter(
  (item) => item.kind === 'customer_match' && typeof item.recommended_choice_id === 'string'
));
const explicitChoiceItems = computed(() => choiceItems.value.filter(
  (item) => !suggestedCustomerItems.value.includes(item)
));

const detailsOpen = reactive({
  safe_plan: safeItems.value.length <= 5,
  customer_matches: suggestedCustomerItems.value.length <= 5,
  stays_behind: staysItems.value.length <= 5,
});
const visibleLimits = reactive({ safe_plan: pageSize, customer_matches: pageSize, stays_behind: pageSize });

const reviewStepCount = computed(() =>
  (safeItems.value.length ? 1 : 0)
  + (suggestedCustomerItems.value.length ? 1 : 0)
  + explicitChoiceItems.value.length
  + (staysItems.value.length ? 1 : 0)
);
const completedStepCount = computed(() =>
  (safeItems.value.length && allComplete(safeItems.value) ? 1 : 0)
  + (suggestedCustomerItems.value.length && allComplete(suggestedCustomerItems.value) ? 1 : 0)
  + explicitChoiceItems.value.filter(isComplete).length
  + (staysItems.value.length && allComplete(staysItems.value) ? 1 : 0)
);
const remainingCopy = computed(() => {
  const remaining = reviewStepCount.value - completedStepCount.value;
  return remaining === 0 ? 'Ready to continue' : `${remaining} ${remaining === 1 ? 'step' : 'steps'} left`;
});
const progress = computed(() => reviewStepCount.value === 0
  ? 0
  : Math.round((completedStepCount.value / reviewStepCount.value) * 100));

function sectionFor(item) {
  if (['safe_plan', 'choices', 'stays_behind'].includes(item.section)) return item.section;
  if (item.kind === 'record_collision') return 'stays_behind';
  return item.choices?.length ? 'choices' : 'safe_plan';
}

function answerFor(item) {
  if (!item.choices?.length) return true;
  return typeof item.recommended_choice_id === 'string'
    ? item.recommended_choice_id
    : item.choices.length === 1 ? item.choices[0].choice_id : null;
}

function isComplete(item) {
  return item.choices?.length
    ? item.choices.some((choice) => choice.choice_id === props.approvals[item.review_id])
    : props.approvals[item.review_id] === true;
}

function allComplete(batch) {
  return batch.length > 0 && batch.every(isComplete);
}

function toggleBatch(batch) {
  const clear = allComplete(batch);
  batch.forEach((item) => emit('toggle', item.review_id, clear ? null : answerFor(item)));
}

function itemCount(batch) {
  return `${batch.length} ${batch.length === 1 ? 'item' : 'items'}`;
}

function aggregateTechnicalItems(batch) {
  const visible = [];
  const aggregates = new Map();
  batch.forEach((item) => {
    const technicalTitle = /^(Order|Product|Customer|Subscription)\s+\d/i.test(item.title || '');
    if (item.story || item.target_story || !technicalTitle) {
      visible.push(item);
      return;
    }
    const key = `${item.group || 'other'}|${item.summary}`;
    if (!aggregates.has(key)) aggregates.set(key, { count: 0, group: item.group || 'items', summary: item.summary });
    aggregates.get(key).count += 1;
  });
  aggregates.forEach((aggregate, key) => {
    const singular = { orders: 'order', products: 'product', customers: 'customer', subscriptions: 'subscription' }[aggregate.group] || 'item';
    visible.push({
      aggregate: true,
      review_id: `aggregate-${key}`,
      title: `${aggregate.count} ${aggregate.count === 1 ? singular : aggregate.group}`,
      summary: aggregate.summary,
    });
  });
  return visible;
}

const heading = ref(null);
defineExpose({ focusHeading: () => heading.value?.focus() });
</script>
