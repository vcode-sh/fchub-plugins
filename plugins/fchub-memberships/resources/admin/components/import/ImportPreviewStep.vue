<template>
  <el-card shadow="never" class="wizard-card">
    <p class="step-description">Review your import configuration before starting.</p>

    <div class="stats-cards">
      <div class="stat-card">
        <div class="stat-value">{{ membersToImport }}</div>
        <div class="stat-label">Members to Import</div>
      </div>
      <div class="stat-card">
        <div class="stat-value">{{ plansToCreate }}</div>
        <div class="stat-label">Plans to Create</div>
      </div>
      <div class="stat-card">
        <div class="stat-value">{{ levelsSkipped }}</div>
        <div class="stat-label">Levels Skipped</div>
      </div>
    </div>

    <el-table :data="previewBreakdown" stripe class="breakdown-table">
      <el-table-column prop="level" label="Level" min-width="140" />
      <el-table-column label="Action" width="120">
        <template #default="{ row }">
          <el-tag
            :type="row.action === 'create' ? 'success' : row.action === 'map' ? 'primary' : 'info'"
            size="small"
          >
            {{ row.action }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="target_plan" label="Target Plan" min-width="160" />
      <el-table-column prop="member_count" label="Members" width="100" align="center" />
      <el-table-column label="Expiry" width="120">
        <template #default="{ row }">
          {{ row.expiry_type }}
        </template>
      </el-table-column>
    </el-table>

    <div v-if="membersWithoutUsers > 0" class="preview-warning">
      <el-alert
        :title="`${membersWithoutUsers} member(s) have no matching WordPress user account.`"
        type="warning"
        show-icon
        :closable="false"
        description="These members will be skipped unless their WordPress accounts are created first."
      />
    </div>

    <div class="wizard-actions">
      <el-button @click="$emit('back')">Back</el-button>
      <el-button type="primary" @click="$emit('start')">Start Import</el-button>
    </div>
  </el-card>
</template>

<script setup>
defineProps({
  membersToImport: { type: Number, required: true },
  plansToCreate: { type: Number, required: true },
  levelsSkipped: { type: Number, required: true },
  membersWithoutUsers: { type: Number, required: true },
  previewBreakdown: { type: Array, default: () => [] },
})

defineEmits(['back', 'start'])
</script>

<style scoped>
.step-description {
  font-size: 14px;
  color: var(--fchub-text-secondary);
  margin: 0 0 20px;
}

.stats-cards {
  display: flex;
  gap: 16px;
  margin-bottom: 20px;
}

.stat-card {
  flex: 1;
  padding: 16px;
  background: var(--el-fill-color-lighter, #fafafa);
  border: 1px solid var(--fchub-border-color);
  border-radius: 8px;
  text-align: center;
}

.stat-value {
  font-size: 28px;
  font-weight: 600;
  color: var(--fchub-text-primary);
  line-height: 1.2;
}

.stat-label {
  font-size: 13px;
  color: var(--fchub-text-secondary);
  margin-top: 4px;
}

.breakdown-table {
  margin-bottom: 16px;
}

.preview-warning {
  margin-top: 16px;
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
