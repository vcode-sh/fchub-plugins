<template>
  <div class="import-wizard-page">
    <WorkspacePageHeader
      eyebrow="Member migration"
      title="Import Members"
      description="Bring existing members across with a reviewable, reversible-feeling workflow. Nothing is imported before confirmation."
      back-to="/members"
      back-label="Back to members"
    />

    <nav class="import-progress-nav" aria-label="Import progress">
      <button
        v-for="(step, index) in importSteps"
        :key="step"
        type="button"
        class="import-progress-step"
        :class="{ active: currentStep === index, complete: currentStep > index }"
        :aria-current="currentStep === index ? 'step' : undefined"
        :disabled="index > currentStep"
        @click="index < currentStep && (currentStep = index)"
      >
        <span>{{ index + 1 }}</span><strong>{{ step }}</strong>
      </button>
    </nav>

    <ImportUploadStep
      v-if="currentStep === 0"
      v-model:is-drag-over="isDragOver"
      :parsing="parsing"
      :parse-error="parseError"
      :parse-result="parseResult"
      :preview-columns="previewColumns"
      @file-selected="onFileSelected"
      @file-drop="onFileDrop"
      @cancel="$router.push('/members')"
      @next="currentStep = 1"
    />
    <ImportMappingStep
      v-else-if="currentStep === 1"
      :levels="parseResult?.levels || []"
      :mappings="mappings"
      :existing-plans="existingPlans"
      :all-levels-mapped="canContinueFromMappings"
      @back="currentStep = 0"
      @next="currentStep = 2"
    />
    <ImportOptionsStep v-else-if="currentStep === 2" :options="options" @back="currentStep = 1" @next="currentStep = 3" />
    <ImportPreviewStep
      v-else-if="currentStep === 3"
      :members-to-import="membersToImport"
      :plans-to-create="plansToCreate"
      :levels-skipped="levelsSkipped"
      :members-without-users="membersWithoutUsers"
      :preview-breakdown="previewBreakdown"
      @back="currentStep = 2"
      @start="startImport"
    />
    <ImportProgressResultsStep
      v-else-if="currentStep === 4"
      :import-complete="importComplete"
      :import-progress="importProgress"
      :current-batch="currentBatch"
      :total-batches="totalBatches"
      :counters="counters"
      :import-results="importResults"
      :results-by-status="resultsByStatus"
      @download-report="downloadReport"
      @reset="resetWizard"
      @view-members="$router.push('/members')"
    />
  </div>
</template>

<script setup>
import { onMounted } from 'vue'
import WorkspacePageHeader from '@/components/workspace/WorkspacePageHeader.vue'
import ImportMappingStep from '@/components/import/ImportMappingStep.vue'
import ImportOptionsStep from '@/components/import/ImportOptionsStep.vue'
import ImportPreviewStep from '@/components/import/ImportPreviewStep.vue'
import ImportProgressResultsStep from '@/components/import/ImportProgressResultsStep.vue'
import ImportUploadStep from '@/components/import/ImportUploadStep.vue'
import { useMemberImportWizard } from '@/composables/import/useMemberImportWizard.js'

const importSteps = ['Upload', 'Map levels', 'Options', 'Preview', 'Import']
const {
  currentStep,
  isDragOver,
  parsing,
  parseError,
  parseResult,
  mappings,
  existingPlans,
  options,
  importComplete,
  currentBatch,
  totalBatches,
  importResults,
  counters,
  previewColumns,
  canContinueFromMappings,
  membersToImport,
  plansToCreate,
  levelsSkipped,
  membersWithoutUsers,
  previewBreakdown,
  importProgress,
  resultsByStatus,
  onFileSelected,
  onFileDrop,
  loadPlans,
  startImport,
  downloadReport,
  resetWizard,
} = useMemberImportWizard()

onMounted(loadPlans)
</script>

<style scoped>
.import-progress-nav {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 8px;
  margin-bottom: 24px;
}

.import-progress-step {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
  min-height: 48px;
  padding: 8px 10px;
  color: var(--fchub-text-secondary);
  cursor: pointer;
  background: var(--fchub-card-bg);
  border: 1px solid var(--fchub-border-color);
  border-radius: 10px;
}

.import-progress-step span {
  display: grid;
  flex: 0 0 24px;
  place-items: center;
  width: 24px;
  height: 24px;
  font-size: 11px;
  font-weight: 700;
  background: var(--el-fill-color-light);
  border-radius: 50%;
}

.import-progress-step strong {
  overflow: hidden;
  font-size: 12px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.import-progress-step.active {
  color: var(--fchub-text-primary);
  border-color: var(--el-color-primary);
  box-shadow: 0 0 0 2px var(--el-color-primary-light-8);
}

.import-progress-step.active span,
.import-progress-step.complete span {
  color: #fff;
  background: var(--el-color-primary);
}

.import-progress-step:disabled {
  cursor: default;
  opacity: .72;
}

.wizard-card {
  margin-bottom: 20px;
}

@media (max-width: 782px) {
  .import-progress-nav {
    gap: 5px;
  }

  .import-progress-step {
    justify-content: center;
    min-height: 42px;
    padding: 7px 4px;
  }

  .import-progress-step strong {
    display: none;
  }
}
</style>
