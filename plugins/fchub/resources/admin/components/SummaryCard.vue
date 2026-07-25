<script setup>
/**
 * One number and the sentence that stops it being ominous. A count on its own
 * is a dashboard demanding attention; a count with "everything is on its
 * latest release" underneath is information.
 */
defineProps({
  label: { type: String, required: true },
  value: { type: [Number, String], required: true },
  hint: { type: String, default: '' },
  tone: {
    type: String,
    default: 'blue',
    validator: (value) => ['blue', 'orange', 'pink', 'purple'].includes(value),
  },
})
</script>

<template>
  <div class="fchub-summary" :class="`fchub-summary--${tone}`">
    <p class="fchub-summary__label">{{ label }}</p>
    <p class="fchub-summary__value">{{ value }}</p>
    <p v-if="hint" class="fchub-summary__hint">{{ hint }}</p>
  </div>
</template>

<style scoped>
.fchub-summary {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 18px 20px;
  border: 1px solid var(--fchub-border-color);
  border-radius: var(--fchub-radius-stat);
  background: var(--fchub-card-bg);
}

.fchub-summary__label {
  margin: 0;
  font-size: 12px;
  font-weight: 500;
  color: var(--fchub-text-secondary);
}

.fchub-summary__value {
  margin: 0;
  font-size: 28px;
  font-weight: 600;
  line-height: 1.2;
}

.fchub-summary__hint {
  margin: 2px 0 0;
  font-size: 12px;
  line-height: 1.5;
  color: var(--fchub-text-secondary);
}

.fchub-summary--blue .fchub-summary__value {
  color: var(--fchub-stat-blue);
}

.fchub-summary--orange .fchub-summary__value {
  color: var(--fchub-stat-orange);
}

.fchub-summary--pink .fchub-summary__value {
  color: var(--fchub-stat-pink);
}

.fchub-summary--purple .fchub-summary__value {
  color: var(--fchub-stat-purple);
}
</style>
