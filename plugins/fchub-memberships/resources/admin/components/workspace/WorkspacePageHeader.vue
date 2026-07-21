<template>
  <header class="workspace-page-header">
    <div class="workspace-page-heading">
      <p
        v-if="eyebrow"
        class="workspace-page-eyebrow"
        :class="{ 'has-back': backTo }"
      >
        {{ eyebrow }}
      </p>
      <div class="workspace-page-title-row">
        <WorkspaceBackButton
          v-if="backTo"
          :to="backTo"
          :label="backLabel"
        />
        <div class="workspace-page-title-copy">
          <h1>{{ title }}</h1>
          <p v-if="description" class="workspace-page-description">{{ description }}</p>
        </div>
      </div>
    </div>
    <div v-if="$slots.actions" class="workspace-page-actions">
      <slot name="actions" />
    </div>
  </header>
</template>

<script setup>
import WorkspaceBackButton from './WorkspaceBackButton.vue'

defineProps({
  eyebrow: { type: String, default: '' },
  title: { type: String, required: true },
  description: { type: String, default: '' },
  backTo: { type: [String, Object], default: '' },
  backLabel: { type: String, default: 'Back' },
})
</script>

<style scoped>
.workspace-page-header {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 24px;
  margin-bottom: 24px;
}

.workspace-page-heading {
  min-width: 0;
}

.workspace-page-title-row {
  display: flex;
  align-items: flex-start;
  gap: 12px;
}

.workspace-page-title-copy {
  min-width: 0;
}

.workspace-page-eyebrow {
  margin: 0 0 6px;
  color: var(--el-color-primary);
  font-size: 11px;
  font-weight: 700;
  letter-spacing: .08em;
  text-transform: uppercase;
}

.workspace-page-eyebrow.has-back {
  margin-left: 52px;
}

h1 {
  margin: 0;
  color: var(--fchub-text-primary);
  font-size: clamp(24px, 3vw, 32px);
  line-height: 1.15;
  letter-spacing: -.025em;
}

.workspace-page-description {
  max-width: 680px;
  margin: 8px 0 0;
  color: var(--fchub-text-secondary);
  font-size: 14px;
  line-height: 1.55;
}

.workspace-page-actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 10px;
  flex-wrap: wrap;
}

@media (max-width: 782px) {
  .workspace-page-header {
    align-items: stretch;
    flex-direction: column;
    gap: 16px;
    margin-bottom: 20px;
  }

  .workspace-page-actions {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
  }

  .workspace-page-actions :deep(.el-button),
  .workspace-page-actions :deep(a) {
    width: 100%;
    margin: 0;
  }
}
</style>
