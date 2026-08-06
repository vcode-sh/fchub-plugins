<template>
  <el-card shadow="never" class="wizard-card">
    <p class="step-description">
      Map each detected membership level to an existing plan or create a new one.
    </p>

    <div
      v-for="(level, index) in levels"
      :key="index"
      class="level-card"
      :class="{ 'level-skipped': mappings[level.name]?.action === 'skip' }"
    >
      <div class="level-header">
        <span class="level-name">{{ level.name }}</span>
        <el-tag size="small" type="info">{{ level.count }} members</el-tag>
      </div>

      <el-radio-group v-model="mappings[level.name].action" class="level-actions">
        <el-radio value="create">Create New Plan</el-radio>
        <el-radio value="map">Map to Existing Plan</el-radio>
        <el-radio value="skip">Skip this Level</el-radio>
      </el-radio-group>

      <div v-if="mappings[level.name].action === 'create'" class="mapping-details">
        <el-form label-position="top" class="mapping-form">
          <el-form-item label="Plan Title">
            <el-input v-model="mappings[level.name].title" placeholder="Plan title" />
          </el-form-item>
          <el-form-item label="Duration Type">
            <el-select v-model="mappings[level.name].duration_type" style="width: 100%">
              <el-option label="Lifetime (never expires)" value="lifetime" />
              <el-option label="Fixed Duration (days)" value="fixed_days" />
            </el-select>
          </el-form-item>
          <el-form-item
            v-if="mappings[level.name].duration_type === 'fixed_days'"
            label="Duration (days)"
          >
            <el-input-number
              v-model="mappings[level.name].duration_days"
              :min="1"
              :max="36500"
              controls-position="right"
            />
          </el-form-item>
        </el-form>
      </div>

      <div v-if="mappings[level.name].action === 'map'" class="mapping-details">
        <el-select
          v-model="mappings[level.name].plan_id"
          placeholder="Select existing plan..."
          filterable
          style="width: 100%"
        >
          <el-option
            v-for="plan in existingPlans"
            :key="plan.id"
            :label="plan.title"
            :value="plan.id"
          />
        </el-select>
      </div>
    </div>

    <div class="wizard-actions">
      <el-button @click="$emit('back')">Back</el-button>
      <el-button type="primary" :disabled="!allLevelsMapped" @click="$emit('next')">
        Next
      </el-button>
    </div>
  </el-card>
</template>

<script setup>
defineProps({
  levels: { type: Array, default: () => [] },
  mappings: { type: Object, required: true },
  existingPlans: { type: Array, default: () => [] },
  allLevelsMapped: Boolean,
})

defineEmits(['back', 'next'])
</script>

<style scoped>
.step-description {
  font-size: 14px;
  color: var(--fchub-text-secondary);
  margin: 0 0 20px;
}

.level-card {
  padding: 16px;
  margin-bottom: 16px;
  background: var(--el-fill-color-lighter, #fafafa);
  border: 1px solid var(--fchub-border-color);
  border-radius: 8px;
  transition: opacity .2s;
}

.level-card.level-skipped {
  opacity: .6;
}

.level-header {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 12px;
}

.level-name {
  font-weight: 600;
  font-size: 15px;
  color: var(--fchub-text-primary);
}

.level-actions {
  display: flex;
  gap: 16px;
  margin-bottom: 12px;
}

.mapping-details {
  margin-top: 12px;
  padding: 12px 16px;
  background: var(--fchub-card-bg);
  border: 1px solid var(--fchub-border-color);
  border-radius: 6px;
}

.mapping-form {
  display: flex;
  gap: 16px;
  flex-wrap: wrap;
}

.mapping-form .el-form-item {
  margin-bottom: 0;
  min-width: 180px;
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
