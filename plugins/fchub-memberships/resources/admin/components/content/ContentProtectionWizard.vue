<template>
  <el-dialog
    :model-value="visible"
    title="Content Protection"
    width="880px"
    modal-class="content-protection-wizard-overlay"
    class="content-protection-wizard"
    :close-on-click-modal="false"
    :close-on-press-escape="false"
    :show-close="!saving"
    destroy-on-close
    @close="emit('close')"
  >
    <div class="cpw-shell">
      <ContentProtectionWizardProgress :step="step" @select-step="selectStep" />

      <main class="cpw-stage" aria-live="polite">
        <header class="cpw-task-header">
          <span class="cpw-task-eyebrow">{{ currentStepCopy.eyebrow }}</span>
          <h3>{{ currentStepCopy.title }}</h3>
          <p>{{ currentStepCopy.description }}</p>
        </header>

        <ContentProtectionWizardCategoryStep
          v-if="step === 0"
          :form="form"
          :category-cards="categoryCards"
          :category-types="categoryTypes"
          :category-selection-label="categorySelectionLabel"
          @select-category="emit('select-category', $event)"
          @type-change="emit('type-change')"
        />
        <ContentProtectionWizardResourceStep
          v-else-if="step === 1"
          :form="form"
          :category-types="categoryTypes"
          :category-selection-label="categorySelectionLabel"
          :special-pages="specialPages"
          @select-step="selectStep"
          @type-change="emit('type-change')"
          @comment-mode-change="emit('comment-mode-change')"
        />
        <ContentProtectionWizardAccessStep
          v-else-if="step === 2"
          :form="form"
          :plan-options-loading="planOptionsLoading"
          :plan-options="planOptions"
        />
        <ContentProtectionWizardReviewStep
          v-else
          :form="form"
          :plan-options-map="planOptionsMap"
          :resource-display-name="resourceDisplayName"
          @select-step="selectStep"
        />
      </main>
    </div>

    <template #footer>
      <div class="cpw-footer">
        <el-button :disabled="saving" @click="emit('close')">Cancel</el-button>
        <div class="cpw-footer-actions">
          <el-button v-if="step > 0" :disabled="saving" @click="emit('back')">Back</el-button>
          <el-button
            v-if="step < 3"
            type="primary"
            :disabled="!canAdvance || saving"
            @click="emit('next')"
          >
            {{ step === 2 ? 'Review rule' : 'Continue' }}
          </el-button>
          <el-button v-else type="primary" :loading="saving" :disabled="saving" @click="emit('submit')">
            Protect content
          </el-button>
        </div>
      </div>
    </template>
  </el-dialog>
</template>

<script setup>
import { computed } from 'vue'
import { stepCopy } from './contentProtectionWizardUi.js'
import ContentProtectionWizardProgress from './wizard/ContentProtectionWizardProgress.vue'
import ContentProtectionWizardCategoryStep from './wizard/ContentProtectionWizardCategoryStep.vue'
import ContentProtectionWizardResourceStep from './wizard/ContentProtectionWizardResourceStep.vue'
import ContentProtectionWizardAccessStep from './wizard/ContentProtectionWizardAccessStep.vue'
import ContentProtectionWizardReviewStep from './wizard/ContentProtectionWizardReviewStep.vue'

const props = defineProps({
  visible: Boolean,
  step: Number,
  form: { type: Object, required: true },
  categoryCards: { type: Array, default: () => [] },
  categoryTypes: { type: Array, default: () => [] },
  specialPages: { type: Array, default: () => [] },
  planOptionsLoading: Boolean,
  planOptions: { type: Array, default: () => [] },
  planOptionsMap: { type: Object, required: true },
  resourceDisplayName: { type: String, required: true },
  canAdvance: Boolean,
  saving: Boolean,
})

const emit = defineEmits([
  'close',
  'back',
  'next',
  'submit',
  'select-step',
  'select-category',
  'type-change',
  'comment-mode-change',
])

const currentStepCopy = computed(() => stepCopy(props.step, props.form))
const categorySelectionLabel = computed(() => {
  const type = props.categoryTypes.find(({ value }) => value === props.form.resource_type)
  return type ? `${props.form.categoryLabel} · ${type.label}` : props.form.categoryLabel
})
function selectStep(targetStep) {
  if (targetStep < props.step) {
    emit('select-step', targetStep)
  }
}
</script>

<style>
.content-protection-wizard-overlay .el-overlay-dialog {
  top: 16px;
  bottom: 16px;
  display: flex;
  align-items: stretch;
  justify-content: center;
  padding: 0 16px;
  box-sizing: border-box;
  overflow: hidden;
}

@media (min-width: 783px) {
  body.wp-admin .content-protection-wizard-overlay .el-overlay-dialog {
    top: 48px;
    left: 160px;
  }

  body.wp-admin.folded .content-protection-wizard-overlay .el-overlay-dialog {
    left: 36px;
  }
}

@media (min-width: 783px) and (max-width: 960px) {
  body.wp-admin.auto-fold .content-protection-wizard-overlay .el-overlay-dialog {
    left: 36px;
  }
}

.content-protection-wizard {
  width: min(880px, 100%) !important;
  height: auto;
  max-height: none;
  margin: 0 !important;
  display: flex;
  flex-direction: column;
  box-sizing: border-box;
  overflow: hidden;
  border-radius: 12px;
}

.content-protection-wizard .el-dialog__header {
  flex: 0 0 auto;
  margin: 0;
  padding: 20px 24px;
  border-bottom: 1px solid var(--fchub-border-color);
}

.content-protection-wizard .el-dialog__title {
  color: var(--fchub-text-primary);
  font-size: 18px;
  font-weight: 650;
}

.content-protection-wizard .el-dialog__headerbtn {
  top: 12px;
  right: 14px;
  width: 44px;
  height: 44px;
}

.content-protection-wizard .el-dialog__body {
  flex: 1 1 auto;
  min-height: 0;
  display: flex;
  padding: 0;
  overflow: hidden;
}

.content-protection-wizard .el-dialog__footer {
  flex: 0 0 auto;
  padding: 0;
  border-top: 1px solid var(--fchub-border-color);
}

.cpw-shell {
  flex: 1 1 auto;
  min-height: 0;
  height: auto;
  display: flex;
  flex-direction: column;
}

.cpw-stage {
  flex: 1 1 auto;
  min-height: 0;
  overflow-y: auto;
  padding: 26px 24px 30px;
  scrollbar-gutter: stable;
}

.cpw-task-header {
  margin-bottom: 20px;
}

.cpw-task-header h3 {
  margin: 5px 0 5px;
  color: var(--fchub-text-primary);
  font-size: 22px;
  font-weight: 700;
  line-height: 1.25;
}

.cpw-task-header p {
  max-width: 680px;
  margin: 0;
  color: var(--fchub-text-secondary);
  font-size: 14px;
  line-height: 1.55;
}

.cpw-progress-card:focus-visible,
.cpw-category-choice:focus-visible,
.cpw-context-strip button:focus-visible,
.cpw-review-heading button:focus-visible {
  outline: 3px solid color-mix(in srgb, var(--el-color-primary) 24%, transparent);
  outline-offset: 2px;
}

.cpw-footer {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 14px 24px;
  box-sizing: border-box;
  background: var(--fchub-card-bg);
}

.cpw-footer-actions {
  display: flex;
  align-items: center;
  gap: 8px;
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}

@media (max-width: 782px) {
  .content-protection-wizard-overlay .el-overlay-dialog {
    display: block;
    position: absolute;
    inset: 46px 0 0;
    height: calc(100dvh - 46px);
    padding: 0;
    overflow: hidden;
  }

  .content-protection-wizard {
    width: 100% !important;
    max-width: none;
    height: 100%;
    max-height: 100%;
    margin: 0 !important;
    border-radius: 0;
  }

  .content-protection-wizard .el-dialog__header {
    padding: 16px;
  }

  .content-protection-wizard .el-dialog__headerbtn {
    top: 7px;
    right: 6px;
  }

  .cpw-progress {
    overflow-x: auto;
    padding: 10px 16px;
    scroll-snap-type: x proximity;
    scrollbar-width: none;
  }

  .cpw-progress::-webkit-scrollbar {
    display: none;
  }

  .cpw-progress-list {
    display: flex;
    width: max-content;
    gap: 6px;
  }

  .cpw-progress-list li {
    width: clamp(72px, calc((100vw - 82px) / 4), 98px);
    flex: 0 0 clamp(72px, calc((100vw - 82px) / 4), 98px);
    scroll-snap-align: start;
  }

  .cpw-progress-card {
    height: 64px;
    min-height: 64px;
    grid-template-columns: 1fr;
    gap: 0;
    padding: 9px 10px;
  }

  .cpw-progress-marker,
  .cpw-progress-copy span {
    display: none;
  }

  .cpw-progress-copy strong {
    font-size: 12px;
    line-height: 1.2;
  }

  .cpw-stage {
    padding: 20px 16px 24px;
    scrollbar-gutter: auto;
  }

  .cpw-task-header h3 {
    font-size: 20px;
  }

  .cpw-category-choice {
    grid-template-columns: 18px 30px minmax(0, 1fr);
    gap: 10px;
    padding: 12px;
  }

  .cpw-category-arrow {
    display: none;
  }

  .cpw-category-icon {
    width: 30px;
    height: 30px;
    font-size: 21px;
  }

  .cpw-inline-types {
    align-items: flex-start;
    flex-direction: column;
    gap: 8px;
    margin: -2px 12px 12px 70px;
  }

  .cpw-current-selection {
    align-items: flex-start;
    flex-wrap: wrap;
  }

  .cpw-summary-next {
    width: 100%;
    margin: 0 0 0 30px;
  }

  .cpw-choice-group {
    grid-template-columns: 1fr;
  }

  .cpw-form-section {
    padding: 16px;
  }

  .cpw-review-group dl > div {
    grid-template-columns: 1fr;
    gap: 4px;
  }

  .cpw-footer {
    padding: 12px 16px;
  }

  .cpw-footer-actions {
    min-width: 0;
    flex: 1;
    justify-content: flex-end;
  }

  .cpw-footer-actions .el-button--primary {
    min-width: 112px;
  }
}

@media (max-width: 420px) {
  .cpw-footer > .el-button {
    padding-inline: 10px;
  }

  .cpw-footer-actions {
    gap: 6px;
  }

  .cpw-footer-actions .el-button {
    margin-left: 0;
    padding-inline: 11px;
  }
}
</style>
