<script setup>
/**
 * A small, wordy label. Every badge says what it means in words, because a
 * customer who cannot distinguish the amber one from the green one still
 * deserves to know whether their shop is taking money.
 */
defineProps({
  label: { type: String, required: true },
  tone: {
    type: String,
    default: 'neutral',
    validator: (value) => ['neutral', 'positive', 'info', 'warning', 'critical'].includes(value),
  },
})
</script>

<template>
  <span class="fchub-badge" :class="`fchub-badge--${tone}`">
    <span class="fchub-badge__dot" aria-hidden="true" />
    {{ label }}
  </span>
</template>

<style scoped>
.fchub-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 3px 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 500;
  line-height: 1.5;
  white-space: nowrap;
}

.fchub-badge__dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: currentColor;
  opacity: 0.7;
  flex-shrink: 0;
}

.fchub-badge--neutral {
  color: var(--fchub-text-secondary);
  background: var(--fchub-neutral-bg);
}

.fchub-badge--positive {
  color: var(--fchub-positive);
  background: var(--fchub-positive-bg);
}

.fchub-badge--info {
  color: var(--fchub-stat-blue);
  background: var(--fchub-stat-blue-bg);
}

.fchub-badge--warning {
  color: var(--fchub-stat-orange);
  background: var(--fchub-stat-orange-bg);
}

.fchub-badge--critical {
  color: var(--fchub-stat-pink);
  background: var(--fchub-stat-pink-bg);
}
</style>
