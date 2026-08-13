<template>
  <div class="cartshift-review-story">
    <div class="cartshift-review-story-title">
      <span class="dashicons" :class="icon" aria-hidden="true"></span>
      <div>
        <strong>{{ item.title }}</strong>
        <small v-if="story?.kind === 'customer' && story.email">{{ story.email }}</small>
      </div>
    </div>

    <div v-if="story" class="cartshift-review-story-facts">
      <template v-if="story.kind === 'order'">
        <span v-if="story.created_utc">{{ formatDate(story.created_utc) }}</span>
        <span v-if="story.status">{{ humanise(story.status) }}</span>
        <strong v-if="story.currency">{{ formatMoney(story.gross_total, story.currency) }}</strong>
      </template>
      <template v-else-if="story.kind === 'product'">
        <span v-if="story.sku">SKU {{ story.sku }}</span>
        <span v-if="story.product_type">{{ humanise(story.product_type) }}</span>
        <span>{{ relationCopy(story.dependent_orders, 'order') }}</span>
      </template>
      <template v-else-if="story.kind === 'customer'">
        <span>{{ humanise(story.classification) }}</span>
        <span>{{ relationCopy(story.dependent_orders, 'order') }}</span>
        <span v-if="story.dependent_subscriptions">{{ relationCopy(story.dependent_subscriptions, 'subscription') }}</span>
      </template>
      <template v-else-if="story.kind === 'subscription'">
        <span v-if="story.status">{{ humanise(story.status) }}</span>
        <strong v-if="story.currency">{{ formatMoney(story.recurring_total, story.currency) }}</strong>
        <span v-if="story.next_payment_utc">Next payment {{ formatDate(story.next_payment_utc) }}</span>
      </template>
    </div>

    <ul v-if="storyItems.length" class="cartshift-review-story-products">
      <li v-for="(product, index) in storyItems" :key="`${product.name}-${index}`">
        <span>{{ product.name }}</span><small v-if="product.quantity">× {{ product.quantity }}</small>
      </li>
    </ul>
    <p class="cartshift-review-story-summary">{{ item.summary }}</p>

    <div v-if="item.target_story" class="cartshift-review-target-story">
      <span><span class="dashicons dashicons-saved" aria-hidden="true"></span>Already in FluentCart</span>
      <strong>{{ item.target_story.customer_name || item.title }}</strong>
      <small>
        <template v-if="item.target_story.created_utc">{{ formatDate(item.target_story.created_utc) }}</template>
        <template v-if="item.target_story.currency"> · {{ formatMoney(item.target_story.gross_total, item.target_story.currency) }}</template>
      </small>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({ item: { type: Object, required: true } });
const story = computed(() => props.item.story || null);
const icon = computed(() => ({
  order: 'dashicons-cart',
  customer: 'dashicons-admin-users',
  product: 'dashicons-products',
  subscription: 'dashicons-update',
}[story.value?.kind] || 'dashicons-yes-alt'));
const storyItems = computed(() => {
  if (!story.value) return [];
  if (Array.isArray(story.value.items)) return story.value.items.filter((item) => item?.name);
  if (Array.isArray(story.value.purchases)) return story.value.purchases.map((name) => ({ name, quantity: 0 }));
  if (story.value.item_name) return [{ name: story.value.item_name, quantity: story.value.quantity }];
  return [];
});

function formatDate(value) {
  const date = new Date(String(value).replace(' ', 'T') + (String(value).includes('Z') ? '' : 'Z'));
  if (Number.isNaN(date.getTime())) return value;
  return new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }).format(date);
}

function formatMoney(amount, currency) {
  try {
    return new Intl.NumberFormat('en-GB', { style: 'currency', currency }).format(Number(amount || 0) / 100);
  } catch {
    return `${currency} ${(Number(amount || 0) / 100).toFixed(2)}`;
  }
}

function humanise(value) {
  return String(value || '').replaceAll('_', ' ').replaceAll('-', ' ').replace(/^./, (letter) => letter.toUpperCase());
}

function relationCopy(count, label) {
  const total = Number(count || 0);
  return `${total} related ${label}${total === 1 ? '' : 's'}`;
}
</script>
