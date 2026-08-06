<template>
  <section class="builder-panel review-panel" aria-labelledby="review-step-heading">
    <div class="builder-panel-heading">
      <span class="builder-panel-step">Step 3 of 3</span>
      <h2 id="review-step-heading">Review the member experience</h2>
      <p>One last check before this plan is created.</p>
    </div>

    <div class="review-hero">
      <span>Your membership plan</span>
      <h3>{{ summary.title }}</h3>
      <p>{{ description.trim() || 'No member-facing description has been added.' }}</p>
    </div>

    <dl class="review-list">
      <div><dt>Availability</dt><dd>{{ summary.status }}</dd></div>
      <div><dt>Access duration</dt><dd>{{ summary.duration }}</dd></div>
      <div><dt>Content access</dt><dd>{{ summary.contentAccess }}</dd></div>
      <div><dt>Trial</dt><dd>{{ summary.trial }}</dd></div>
    </dl>

    <el-alert
      v-if="ruleCount === 0"
      title="This plan does not unlock protected content yet."
      description="That is valid. You can add content access now or edit the plan later."
      type="warning"
      :closable="false"
      show-icon
    />
    <el-alert
      v-if="status !== 'active'"
      :title="status === 'archived' ? 'This plan will be archived.' : 'This plan will stay inactive.'"
      description="Members will not see it as an available active plan until its status changes."
      type="info"
      :closable="false"
      show-icon
    />
  </section>
</template>

<script setup>
defineProps({
  summary: {
    type: Object,
    required: true,
  },
  description: {
    type: String,
    default: '',
  },
  ruleCount: {
    type: Number,
    required: true,
  },
  status: {
    type: String,
    required: true,
  },
})
</script>

<style scoped>
.builder-panel {
  overflow: hidden;
  border: 1px solid var(--fchub-border-color);
  border-radius: 12px;
  background: var(--fchub-card-bg);
  box-shadow: 0 10px 30px rgb(38 55 95 / 5%);
}

.builder-panel-heading {
  padding: 26px 28px 22px;
  border-bottom: 1px solid var(--fchub-border-color);
}

.builder-panel-step {
  display: block;
  margin-bottom: 7px;
  color: var(--el-color-primary);
  font-size: 10px;
  font-weight: 800;
  letter-spacing: 0.09em;
  text-transform: uppercase;
}

.builder-panel-heading h2 {
  margin: 0;
  color: var(--fchub-text-primary);
  font-size: 21px;
  line-height: 1.3;
  letter-spacing: -0.015em;
}

.builder-panel-heading p {
  margin: 6px 0 0;
  color: var(--fchub-text-secondary);
  font-size: 13px;
  line-height: 1.55;
}

.review-panel {
  padding-bottom: 24px;
}

.review-hero {
  margin: 24px 28px 16px;
  padding: 22px;
  border-radius: 10px;
  color: var(--fchub-text-primary);
  background: linear-gradient(135deg, color-mix(in srgb, var(--el-color-primary) 8%, var(--fchub-card-bg)), var(--fchub-card-bg));
}

.review-hero span {
  color: var(--el-color-primary);
  font-size: 10px;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.review-hero h3 {
  overflow-wrap: anywhere;
  margin: 7px 0 4px;
  font-size: 21px;
  line-height: 1.35;
}

.review-hero p {
  margin: 0;
  color: var(--fchub-text-secondary);
  font-size: 13px;
  line-height: 1.55;
}

.review-list {
  margin: 0 28px 18px;
  border: 1px solid var(--fchub-border-color);
  border-radius: 10px;
}

.review-list div {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
  padding: 13px 15px;
  border-bottom: 1px solid var(--fchub-border-color);
}

.review-list div:last-child {
  border-bottom: 0;
}

.review-list dt {
  color: var(--fchub-text-secondary);
  font-size: 12px;
}

.review-list dd {
  margin: 0;
  color: var(--fchub-text-primary);
  font-size: 13px;
  font-weight: 600;
  text-align: right;
}

.review-panel :deep(.el-alert) {
  width: auto;
  margin: 12px 28px 0;
}

@media (max-width: 782px) {
  .builder-panel-heading {
    padding-left: 18px;
    padding-right: 18px;
  }

  .builder-panel-heading h2 {
    font-size: 19px;
  }

  .review-hero,
  .review-list,
  .review-panel :deep(.el-alert) {
    margin-left: 16px;
    margin-right: 16px;
  }

  .review-list div {
    align-items: flex-start;
  }
}
</style>
