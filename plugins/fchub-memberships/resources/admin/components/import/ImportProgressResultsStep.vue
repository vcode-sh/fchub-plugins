<template>
  <el-card shadow="never" class="wizard-card">
    <template v-if="!importComplete">
      <div class="import-progress">
        <el-progress :percentage="importProgress" :stroke-width="20" :text-inside="true" />
        <p class="progress-label">
          Processing batch {{ currentBatch }} of {{ totalBatches }}...
        </p>
      </div>

      <div class="import-counters">
        <div class="counter counter-success">
          <span class="counter-value">{{ counters.imported }}</span>
          <span class="counter-label">Imported</span>
        </div>
        <div class="counter counter-warning">
          <span class="counter-value">{{ counters.skipped }}</span>
          <span class="counter-label">Skipped</span>
        </div>
        <div class="counter counter-info">
          <span class="counter-value">{{ counters.extended }}</span>
          <span class="counter-label">Extended</span>
        </div>
        <div class="counter counter-danger">
          <span class="counter-value">{{ counters.failed }}</span>
          <span class="counter-label">Failed</span>
        </div>
      </div>
    </template>

    <template v-if="importComplete">
      <el-result
        icon="success"
        title="Import Complete"
        :sub-title="`${counters.imported} members imported, ${counters.skipped} skipped, ${counters.extended} extended, ${counters.failed} failed.`"
      />

      <div v-if="importResults.length > 0" class="results-detail">
        <el-collapse>
          <el-collapse-item
            v-if="resultsByStatus.imported.length > 0"
            title="Imported"
            name="imported"
          >
            <ImportResultTable :rows="resultsByStatus.imported" detail-label="Details" />
          </el-collapse-item>
          <el-collapse-item
            v-if="resultsByStatus.skipped.length > 0"
            title="Skipped"
            name="skipped"
          >
            <ImportResultTable :rows="resultsByStatus.skipped" detail-label="Reason" />
          </el-collapse-item>
          <el-collapse-item
            v-if="resultsByStatus.extended.length > 0"
            title="Extended"
            name="extended"
          >
            <ImportResultTable :rows="resultsByStatus.extended" detail-label="Details" />
          </el-collapse-item>
          <el-collapse-item
            v-if="resultsByStatus.failed.length > 0"
            title="Failed"
            name="failed"
          >
            <ImportResultTable :rows="resultsByStatus.failed" detail-label="Error" />
          </el-collapse-item>
        </el-collapse>
      </div>

      <div class="wizard-actions">
        <el-button @click="$emit('download-report')">
          <el-icon><Download /></el-icon>
          Download Report
        </el-button>
        <el-button @click="$emit('reset')">Import Again</el-button>
        <el-button type="primary" @click="$emit('view-members')">View Members</el-button>
      </div>
    </template>
  </el-card>
</template>

<script setup>
import { Download } from '@element-plus/icons-vue'
import ImportResultTable from './ImportResultTable.vue'

defineProps({
  importComplete: Boolean,
  importProgress: { type: Number, required: true },
  currentBatch: { type: Number, required: true },
  totalBatches: { type: Number, required: true },
  counters: { type: Object, required: true },
  importResults: { type: Array, default: () => [] },
  resultsByStatus: { type: Object, required: true },
})

defineEmits(['download-report', 'reset', 'view-members'])
</script>

<style scoped>
.import-progress {
  padding: 24px 0;
  text-align: center;
}

.progress-label {
  font-size: 14px;
  color: var(--fchub-text-secondary);
  margin-top: 12px;
}

.import-counters {
  display: flex;
  gap: 16px;
  margin-bottom: 24px;
}

.counter {
  flex: 1;
  padding: 12px;
  border: 1px solid var(--fchub-border-color);
  border-radius: 8px;
  text-align: center;
}

.counter-success {
  background: var(--el-color-success-light-9);
}

.counter-warning {
  background: var(--el-color-warning-light-9);
}

.counter-info {
  background: var(--el-color-primary-light-9);
}

.counter-danger {
  background: var(--el-color-danger-light-9);
}

.counter-value {
  display: block;
  font-size: 24px;
  font-weight: 600;
  color: var(--fchub-text-primary);
}

.counter-label {
  display: block;
  font-size: 12px;
  color: var(--fchub-text-secondary);
  margin-top: 2px;
}

.results-detail {
  margin: 20px 0;
}

.wizard-actions {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  margin-top: 24px;
  padding-top: 16px;
  border-top: 1px solid var(--fchub-border-color);
}

@media (max-width: 782px) {
  .import-counters {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .wizard-actions {
    flex-wrap: wrap;
  }
}
</style>
