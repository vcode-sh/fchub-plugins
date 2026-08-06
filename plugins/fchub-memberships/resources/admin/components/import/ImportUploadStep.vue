<template>
  <el-card shadow="never" class="wizard-card">
    <div
      class="upload-zone"
      :class="{ 'drag-over': isDragOver }"
      @dragover.prevent="$emit('update:isDragOver', true)"
      @dragleave.prevent="$emit('update:isDragOver', false)"
      @drop.prevent="$emit('file-drop', $event)"
      @click="fileInput?.click()"
    >
      <input
        ref="fileInput"
        type="file"
        accept=".csv"
        style="display: none"
        @change="$emit('file-selected', $event)"
      >
      <el-icon class="upload-icon"><UploadFilled /></el-icon>
      <p class="upload-text">Drag and drop a CSV file here, or click to select</p>
      <p class="upload-hint">Supported formats: WishList Member, Generic CSV</p>
    </div>

    <div v-if="parsing" class="parse-loading">
      <el-icon class="is-loading"><Loading /></el-icon>
      <span>Parsing CSV file...</span>
    </div>

    <div v-if="parseError" class="parse-error">
      <el-alert :title="parseError" type="error" show-icon :closable="false" />
    </div>

    <template v-if="parseResult">
      <div class="parse-summary">
        <el-tag type="info" size="large" class="format-badge">
          {{ parseResult.format }}
        </el-tag>
      </div>

      <div class="stats-cards">
        <div class="stat-card">
          <div class="stat-value">{{ parseResult.total_members }}</div>
          <div class="stat-label">Total Members</div>
        </div>
        <div class="stat-card">
          <div class="stat-value">{{ parseResult.unique_emails }}</div>
          <div class="stat-label">Unique Emails</div>
        </div>
        <div class="stat-card">
          <div class="stat-value">{{ parseResult.levels?.length || 0 }}</div>
          <div class="stat-label">Levels Found</div>
        </div>
      </div>

      <div v-if="parseResult.warnings?.length" class="parse-warnings">
        <el-alert
          v-for="(warning, index) in parseResult.warnings"
          :key="index"
          :title="warning"
          type="warning"
          show-icon
          :closable="false"
          class="warning-item"
        />
      </div>

      <el-table :data="parseResult.preview" stripe class="preview-table">
        <el-table-column
          v-for="column in previewColumns"
          :key="column"
          :prop="column"
          :label="column"
          min-width="140"
        />
      </el-table>
      <div v-if="parseResult.total_members > 10" class="preview-note">
        Showing first 10 of {{ parseResult.total_members }} rows
      </div>
    </template>

    <div class="wizard-actions">
      <el-button @click="$emit('cancel')">Cancel</el-button>
      <el-button type="primary" :disabled="!parseResult" @click="$emit('next')">
        Next
      </el-button>
    </div>
  </el-card>
</template>

<script setup>
import { ref } from 'vue'
import { Loading, UploadFilled } from '@element-plus/icons-vue'

defineProps({
  isDragOver: Boolean,
  parsing: Boolean,
  parseError: { type: String, default: '' },
  parseResult: { type: Object, default: null },
  previewColumns: { type: Array, default: () => [] },
})

defineEmits(['cancel', 'file-drop', 'file-selected', 'next', 'update:isDragOver'])

const fileInput = ref(null)
</script>

<style scoped>
.upload-zone {
  padding: 48px 24px;
  text-align: center;
  cursor: pointer;
  border: 2px dashed var(--fchub-border-color);
  border-radius: 8px;
  transition: border-color .2s, background-color .2s;
}

.upload-zone:hover,
.upload-zone.drag-over {
  background-color: var(--el-color-primary-light-9);
  border-color: var(--el-color-primary);
}

.upload-icon {
  margin-bottom: 12px;
  font-size: 48px;
  color: var(--fchub-text-secondary);
}

.upload-text {
  margin: 0 0 6px;
  font-size: 15px;
  font-weight: 500;
  color: var(--fchub-text-primary);
}

.upload-hint {
  margin: 0;
  font-size: 13px;
  color: var(--fchub-text-secondary);
}

.parse-loading {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 24px;
  font-size: 14px;
  color: var(--fchub-text-secondary);
}

.parse-error {
  margin-top: 16px;
}

.parse-summary {
  margin: 20px 0 16px;
}

.format-badge {
  font-weight: 500;
}

.stats-cards {
  display: flex;
  gap: 16px;
  margin-bottom: 20px;
}

.stat-card {
  flex: 1;
  padding: 16px;
  text-align: center;
  background: var(--el-fill-color-lighter, #fafafa);
  border: 1px solid var(--fchub-border-color);
  border-radius: 8px;
}

.stat-value {
  font-size: 28px;
  font-weight: 600;
  line-height: 1.2;
  color: var(--fchub-text-primary);
}

.stat-label {
  margin-top: 4px;
  font-size: 13px;
  color: var(--fchub-text-secondary);
}

.parse-warnings {
  margin-bottom: 16px;
}

.warning-item {
  margin-bottom: 8px;
}

.preview-table {
  margin-top: 16px;
}

.preview-note {
  margin-top: 8px;
  font-size: 12px;
  color: var(--fchub-text-secondary);
  text-align: center;
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
  .stats-cards {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .wizard-actions {
    flex-wrap: wrap;
  }
}
</style>
