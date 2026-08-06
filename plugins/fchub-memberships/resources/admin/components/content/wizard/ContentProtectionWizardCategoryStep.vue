<template>
  <section class="cpw-step cpw-category-step" aria-labelledby="cpw-category-heading">
    <h4 id="cpw-category-heading" class="sr-only">Content types</h4>
    <div class="cpw-category-list">
      <div
        v-for="card in categoryCards"
        :key="card.key"
        class="cpw-category-item"
        :class="{ 'is-selected': form.categoryKey === card.key }"
      >
        <button
          type="button"
          class="cpw-category-choice"
          :aria-pressed="form.categoryKey === card.key"
          :aria-expanded="form.categoryKey === card.key && categoryTypes.length > 1"
          :aria-controls="form.categoryKey === card.key && categoryTypes.length > 1 ? `cpw-types-${card.key}` : undefined"
          @click="emit('select-category', card)"
        >
          <span class="cpw-selection-dot" aria-hidden="true">
            <el-icon v-if="form.categoryKey === card.key"><Check /></el-icon>
          </span>
          <span class="cpw-category-icon" aria-hidden="true">
            <el-icon><component :is="card.icon" /></el-icon>
          </span>
          <span class="cpw-category-copy">
            <strong>{{ card.label }}</strong>
            <small>{{ categoryDescription(card.key) }}</small>
          </span>
          <el-icon class="cpw-category-arrow" aria-hidden="true"><ArrowRight /></el-icon>
        </button>

        <div
          v-if="form.categoryKey === card.key && categoryTypes.length > 1"
          :id="`cpw-types-${card.key}`"
          class="cpw-inline-types"
        >
          <span class="cpw-inline-label">Choose a type</span>
          <el-radio-group v-model="form.resource_type" @change="emit('type-change')">
            <el-radio v-for="type in categoryTypes" :key="type.value" :value="type.value">
              {{ type.label }}
            </el-radio>
          </el-radio-group>
        </div>
      </div>
    </div>

    <div v-if="form.categoryKey" class="cpw-current-selection">
      <span class="cpw-summary-icon" aria-hidden="true">
        <el-icon><DocumentChecked /></el-icon>
      </span>
      <span>
        <small>Current selection</small>
        <strong>{{ categorySelectionLabel }}</strong>
      </span>
      <span class="cpw-summary-next">Next: choose a specific resource</span>
    </div>
  </section>
</template>

<script setup>
import { ArrowRight, Check, DocumentChecked } from '@element-plus/icons-vue'
import { categoryDescription } from '../contentProtectionWizardUi.js'

defineProps({
  form: { type: Object, required: true },
  categoryCards: { type: Array, default: () => [] },
  categoryTypes: { type: Array, default: () => [] },
  categorySelectionLabel: { type: String, required: true },
})

const emit = defineEmits(['select-category', 'type-change'])
</script>

<style>
.cpw-category-list {
  overflow: hidden;
  border: 1px solid var(--fchub-border-color);
  border-radius: 10px;
  background: var(--fchub-card-bg);
}

.cpw-category-item + .cpw-category-item {
  border-top: 1px solid var(--fchub-border-color);
}

.cpw-category-item.is-selected {
  background: color-mix(in srgb, var(--el-color-primary) 5%, var(--fchub-card-bg));
}

.cpw-category-choice {
  width: 100%;
  min-height: 64px;
  display: grid;
  grid-template-columns: 20px 36px minmax(0, 1fr) 20px;
  align-items: center;
  gap: 12px;
  padding: 11px 16px;
  border: 0;
  color: var(--fchub-text-primary);
  background: transparent;
  text-align: left;
  cursor: pointer;
}

.cpw-category-choice:hover {
  background: color-mix(in srgb, var(--el-color-primary) 4%, transparent);
}

.cpw-selection-dot {
  width: 18px;
  height: 18px;
  display: grid;
  place-items: center;
  border: 1.5px solid var(--fchub-border-color);
  border-radius: 50%;
  background: var(--fchub-card-bg);
}

.cpw-category-item.is-selected .cpw-selection-dot {
  color: #fff;
  border-color: var(--el-color-primary);
  background: var(--el-color-primary);
  font-size: 12px;
}

.cpw-category-icon,
.cpw-summary-icon,
.cpw-section-icon {
  display: grid;
  place-items: center;
  color: var(--fchub-text-secondary);
}

.cpw-category-icon {
  width: 36px;
  height: 36px;
  font-size: 24px;
}

.cpw-category-item.is-selected .cpw-category-icon {
  color: var(--el-color-primary);
}

.cpw-category-copy {
  min-width: 0;
  display: grid;
  gap: 2px;
}

.cpw-category-copy strong {
  font-size: 14px;
  line-height: 1.35;
}

.cpw-category-copy small {
  color: var(--fchub-text-secondary);
  font-size: 12px;
  line-height: 1.4;
}

.cpw-category-arrow {
  color: var(--fchub-text-secondary);
}

.cpw-inline-types {
  display: flex;
  align-items: center;
  gap: 16px;
  margin: -2px 16px 12px 84px;
  padding: 10px 14px;
  border: 1px solid color-mix(in srgb, var(--el-color-primary) 18%, var(--fchub-border-color));
  border-radius: 8px;
  background: color-mix(in srgb, var(--el-color-primary) 4%, var(--fchub-card-bg));
}

.cpw-inline-label {
  color: var(--fchub-text-secondary);
  font-size: 12px;
  font-weight: 600;
}

.cpw-current-selection,
.cpw-context-strip {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-top: 18px;
  padding: 13px 15px;
  border: 1px solid var(--fchub-border-color);
  border-radius: 9px;
  background: color-mix(in srgb, var(--fchub-card-bg) 94%, var(--el-color-primary) 6%);
}

.cpw-current-selection > span:nth-child(2),
.cpw-context-strip > span {
  min-width: 0;
  display: grid;
  gap: 2px;
}

.cpw-current-selection small,
.cpw-context-strip small {
  color: var(--fchub-text-secondary);
  font-size: 11px;
}

.cpw-current-selection strong,
.cpw-context-strip strong {
  color: var(--fchub-text-primary);
  font-size: 13px;
}

.cpw-summary-next {
  margin-left: auto;
  color: var(--fchub-text-secondary);
  font-size: 12px;
}
</style>
