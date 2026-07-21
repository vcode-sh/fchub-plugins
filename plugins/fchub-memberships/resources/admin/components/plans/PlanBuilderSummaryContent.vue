<template>
  <div class="summary-content">
    <div class="summary-heading">
      <p>Plan at a glance</p>
      <h2 :id="headingId">{{ summary.title }}</h2>
      <span class="summary-status" :class="`is-${summary.status.toLowerCase().replaceAll(' ', '-')}`">
        {{ summary.status }}
      </span>
    </div>

    <dl>
      <div>
        <dt>Access duration</dt>
        <dd>{{ summary.duration }}</dd>
      </div>
      <div>
        <dt>Content access</dt>
        <dd>{{ summary.contentAccess }}</dd>
      </div>
      <div>
        <dt>Trial</dt>
        <dd>{{ summary.trial }}</dd>
      </div>
    </dl>
  </div>
</template>

<script setup>
defineProps({
  summary: {
    type: Object,
    required: true,
  },
  headingId: {
    type: String,
    required: true,
  },
})
</script>

<style scoped>
.summary-content {
  padding: 20px;
}

.summary-heading {
  padding-bottom: 18px;
  border-bottom: 1px solid var(--fchub-border-color);
}

.summary-heading p {
  margin: 0 0 7px;
  color: var(--fchub-text-secondary);
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.summary-heading h2 {
  overflow-wrap: anywhere;
  margin: 0 0 12px;
  color: var(--fchub-text-primary);
  font-size: 17px;
  line-height: 1.35;
}

.summary-status {
  display: inline-flex;
  padding: 4px 8px;
  border-radius: 999px;
  color: var(--fchub-text-secondary);
  background: var(--el-fill-color-light);
  font-size: 11px;
  font-weight: 700;
}

.summary-status.is-active {
  color: var(--el-color-success-dark-2, #3b8b54);
  background: color-mix(in srgb, var(--el-color-success) 11%, var(--fchub-card-bg));
}

dl {
  display: grid;
  gap: 0;
  margin: 6px 0 0;
}

dl div {
  display: grid;
  gap: 3px;
  padding: 13px 0;
  border-bottom: 1px solid var(--fchub-border-color);
}

dl div:last-child {
  border-bottom: 0;
  padding-bottom: 0;
}

dt {
  color: var(--fchub-text-secondary);
  font-size: 11px;
}

dd {
  overflow-wrap: anywhere;
  margin: 0;
  color: var(--fchub-text-primary);
  font-size: 13px;
  font-weight: 600;
  line-height: 1.4;
}
</style>
