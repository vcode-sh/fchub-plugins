<template>
  <div class="fchub-content-item" :class="itemClass">
    <span class="fchub-content-item__dot" :class="dotClass"></span>
    <div class="fchub-content-item__body">
      <div class="fchub-content-item__title-row">
        <component
          :is="isUnlocked && item.url ? 'a' : 'span'"
          class="fchub-content-item__title"
          :href="isUnlocked && item.url ? item.url : undefined"
          :target="isUnlocked && item.url ? '_blank' : undefined"
          :rel="isUnlocked && item.url ? 'noopener' : undefined"
        >
          {{ item.label || item.resource_title || 'Untitled' }}
        </component>
        <span v-if="item.resource_type" class="fchub-content-item__type">{{ formatType(item.resource_type) }}</span>
      </div>
      <span v-if="isUpcoming && item.available_at" class="fchub-content-item__countdown">
        <template v-if="isWithinWeek">{{ countdownLabel }}</template>
        <template v-else>Available: {{ formatDate(item.available_at) }}</template>
      </span>
    </div>
    <span v-if="isLocked" class="fchub-content-item__lock">
      <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
        <rect x="3" y="6.5" width="8" height="5" rx="1" stroke="currentColor" stroke-width="1.2" />
        <path d="M5 6.5V4.5C5 3.39543 5.89543 2.5 7 2.5C8.10457 2.5 9 3.39543 9 4.5V6.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" />
      </svg>
    </span>
    <span v-else-if="isUnlocked" class="fchub-content-item__check">
      <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
        <path d="M11 4.5L5.75 9.75L3 7" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
      </svg>
    </span>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useCountdown } from '../composables/useCountdown.js'

const props = defineProps({
  item: { type: Object, required: true },
})

const isUnlocked = computed(() => props.item.status === 'unlocked')
const isUpcoming = computed(() => props.item.status === 'upcoming')
const isLocked = computed(() => props.item.status === 'locked')

const itemClass = computed(() => `fchub-content-item--${props.item.status}`)
const dotClass = computed(() => `fchub-content-item__dot--${props.item.status}`)

const hasCountdown = props.item.status === 'upcoming' && props.item.available_at
const countdown = hasCountdown ? useCountdown(props.item.available_at) : null
const countdownLabel = computed(() => countdown ? countdown.label.value : '')

const isWithinWeek = computed(() => {
  if (!props.item.available_at) return false
  const diff = new Date(props.item.available_at).getTime() - Date.now()
  return diff > 0 && diff <= 7 * 86400000
})

function formatDate(dateStr) {
  const d = new Date(dateStr)
  return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
}

function formatType(type) {
  const labels = {
    post: 'Post',
    page: 'Page',
    category: 'Category',
    tag: 'Tag',
    course: 'Course',
    space: 'Space',
  }
  return labels[type] || type
}
</script>

<style scoped>
.fchub-content-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 0;
  border-bottom: 1px solid var(--portal-border-light);
  transition: background 0.15s ease;
}

.fchub-content-item:last-child {
  border-bottom: none;
}

.fchub-content-item:hover {
  background: var(--portal-hover-bg);
}

.fchub-content-item__dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
}

.fchub-content-item__dot--unlocked {
  background: var(--portal-accent-green);
}

.fchub-content-item__dot--upcoming {
  background: var(--portal-badge-paused-text);
}

.fchub-content-item__dot--locked {
  background: #d1d5db;
}

.fchub-content-item__body {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
  flex: 1;
}

.fchub-content-item__title-row {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}

.fchub-content-item__title {
  font-size: 14px;
  font-weight: 500;
  color: var(--portal-text-body);
  line-height: 1.4;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  min-width: 0;
}

a.fchub-content-item__title {
  cursor: pointer;
  text-decoration: none;
}

a.fchub-content-item__title:hover {
  color: var(--portal-accent-blue);
}

.fchub-content-item--upcoming .fchub-content-item__title,
.fchub-content-item--locked .fchub-content-item__title {
  color: var(--portal-text-muted);
}

.fchub-content-item__type {
  font-size: 12px;
  font-weight: 500;
  color: var(--portal-text-muted);
  padding: 2px 6px;
  background: var(--portal-hover-bg);
  border-radius: var(--portal-radius-sm);
  white-space: nowrap;
  flex-shrink: 0;
}

.fchub-content-item__countdown {
  font-size: 12px;
  font-weight: 500;
  color: var(--portal-badge-paused-text);
}

.fchub-content-item__lock {
  color: var(--portal-text-muted);
  opacity: 0.5;
  flex-shrink: 0;
}

.fchub-content-item__check {
  color: var(--portal-accent-green);
  flex-shrink: 0;
}

@media (max-width: 480px) {
  .fchub-content-item__type {
    display: none;
  }
}
</style>
