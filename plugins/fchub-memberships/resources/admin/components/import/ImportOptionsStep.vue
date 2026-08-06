<template>
  <el-card shadow="never" class="wizard-card">
    <p class="step-description">
      Configure how the import should handle existing members and other options.
    </p>

    <div class="option-section">
      <h4 class="option-title">Conflict Resolution</h4>
      <p class="option-help">
        How to handle members who already have an active grant for the mapped plan.
      </p>
      <el-radio-group v-model="options.conflict_mode" class="option-radio-group">
        <div class="option-radio-item">
          <el-radio value="skip">Skip existing members</el-radio>
          <p class="option-radio-help">
            Members who already have an active grant for the mapped plan will be skipped.
          </p>
        </div>
        <div class="option-radio-item">
          <el-radio value="extend">Extend expiry</el-radio>
          <p class="option-radio-help">
            If a member has an active grant, extend its expiry date to the imported expiry date.
          </p>
        </div>
        <div class="option-radio-item">
          <el-radio value="overwrite">Overwrite</el-radio>
          <p class="option-radio-help">
            Revoke existing grant and create a new one with imported data.
          </p>
        </div>
      </el-radio-group>
    </div>

    <div class="option-section">
      <h4 class="option-title">FluentCart Customers</h4>
      <div class="option-switch-row">
        <el-switch v-model="options.create_customers" />
        <span class="option-switch-label">
          Create customer records in FluentCart for imported members
        </span>
      </div>
      <p class="option-help">
        When enabled, FluentCart customer records will be created for members who don't have one yet.
      </p>
    </div>

    <div class="wizard-actions">
      <el-button @click="$emit('back')">Back</el-button>
      <el-button type="primary" @click="$emit('next')">Next</el-button>
    </div>
  </el-card>
</template>

<script setup>
defineProps({
  options: { type: Object, required: true },
})

defineEmits(['back', 'next'])
</script>

<style scoped>
.step-description {
  font-size: 14px;
  color: var(--fchub-text-secondary);
  margin: 0 0 20px;
}

.option-section {
  margin-bottom: 24px;
}

.option-title {
  font-size: 15px;
  font-weight: 600;
  color: var(--fchub-text-primary);
  margin: 0 0 4px;
}

.option-help {
  font-size: 13px;
  color: var(--fchub-text-secondary);
  margin: 0 0 12px;
}

.option-radio-group {
  display: flex;
  flex-direction: column;
  gap: 0;
}

.option-radio-item {
  padding: 8px 0;
}

.option-radio-help {
  font-size: 12px;
  color: var(--fchub-text-secondary);
  margin: 2px 0 0 24px;
}

.option-switch-row {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 8px;
}

.option-switch-label {
  font-size: 14px;
  color: var(--fchub-text-primary);
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
  .wizard-actions {
    flex-wrap: wrap;
  }
}
</style>
