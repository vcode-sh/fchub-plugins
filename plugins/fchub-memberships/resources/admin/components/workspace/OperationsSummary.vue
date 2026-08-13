<template>
  <section class="operations-summary" :aria-label="label">
    <article v-for="item in items" :key="item.label" class="operations-summary__item" :data-tone="item.tone || 'neutral'">
      <div class="operations-summary__value">{{ item.value ?? 0 }}</div>
      <div class="operations-summary__copy">
        <strong>{{ item.label }}</strong>
        <span>{{ item.support }}</span>
      </div>
    </article>
  </section>
</template>

<script setup>
defineProps({
  label: {
    type: String,
    required: true,
  },
  items: {
    type: Array,
    default: () => [],
  },
})
</script>

<style scoped>
.operations-summary {
  display: grid;
  /* Fits four tiles as before, and absorbs a fifth when a workspace has one. */
  grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
  gap: 10px;
  margin-bottom: 16px;
}

.operations-summary__item {
  min-width: 0;
  padding: 14px 16px;
  border: 1px solid var(--fchub-border-color);
  border-radius: var(--fchub-radius-card);
  background: var(--fchub-card-bg);
  box-shadow: 0 1px 2px color-mix(in srgb, var(--fchub-text-primary) 5%, transparent);
}

.operations-summary__item[data-tone='warning'] {
  border-color: color-mix(in srgb, var(--el-color-warning) 30%, var(--fchub-border-color));
}

.operations-summary__item[data-tone='danger'] {
  border-color: color-mix(in srgb, var(--el-color-danger) 24%, var(--fchub-border-color));
}

.operations-summary__item[data-tone='success'] {
  border-color: color-mix(in srgb, var(--el-color-success) 26%, var(--fchub-border-color));
}

.operations-summary__value {
  color: var(--fchub-text-primary);
  font-size: 24px;
  font-weight: 700;
  line-height: 1;
  letter-spacing: -0.03em;
}

.operations-summary__copy {
  display: grid;
  gap: 3px;
  margin-top: 9px;
}

.operations-summary__copy strong {
  color: var(--fchub-text-primary);
  font-size: 12px;
  font-weight: 650;
}

.operations-summary__copy span {
  color: var(--fchub-text-secondary);
  font-size: 11px;
  line-height: 1.35;
}

@media (max-width: 900px) {
  .operations-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

@media (max-width: 480px) {
  .operations-summary { gap: 8px; }
  .operations-summary__item { padding: 12px; }
  .operations-summary__value { font-size: 21px; }
}
</style>
