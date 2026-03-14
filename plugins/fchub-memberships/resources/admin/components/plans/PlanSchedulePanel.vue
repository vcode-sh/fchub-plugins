<template>
  <template v-if="!isNew">
    <el-divider content-position="left">Schedule Status Change</el-divider>

    <div v-if="scheduledStatus" class="schedule-current">
      <el-tag type="warning" size="small">Scheduled</el-tag>
      <span>
        Status will change to <strong>{{ scheduledStatus }}</strong>
        on <strong>{{ formatDateTime(scheduledAt) }}</strong>
      </span>
      <el-button size="small" text type="danger" @click="$emit('clear')" :loading="loading">
        Clear
      </el-button>
    </div>

    <div class="schedule-form-row">
      <el-form-item label="New Status">
        <el-select
          :model-value="newStatus"
          placeholder="Select status..."
          style="width: 180px"
          clearable
          @update:model-value="$emit('update:newStatus', $event)"
        >
          <el-option label="Active" value="active" />
          <el-option label="Inactive" value="inactive" />
          <el-option label="Archived" value="archived" />
        </el-select>
      </el-form-item>
      <el-form-item label="Date & Time">
        <el-date-picker
          :model-value="newAt"
          type="datetime"
          placeholder="Select date and time"
          :format="dateTimePickerFormat"
          value-format="YYYY-MM-DD HH:mm:ss"
          style="width: 240px"
          @update:model-value="$emit('update:newAt', $event)"
        />
      </el-form-item>
      <el-button
        size="small"
        type="primary"
        plain
        style="margin-top: 30px"
        :disabled="!newStatus || !newAt"
        :loading="loading"
        @click="$emit('save')"
      >
        Set Schedule
      </el-button>
    </div>
    <div class="field-hint" style="margin-top: -8px">
      Schedule an automatic status change for this plan at a future date.
    </div>
  </template>
</template>

<script setup>
defineProps({
  isNew: {
    type: Boolean,
    default: false,
  },
  scheduledStatus: {
    type: String,
    default: null,
  },
  scheduledAt: {
    type: String,
    default: null,
  },
  newStatus: {
    type: String,
    default: '',
  },
  newAt: {
    type: String,
    default: '',
  },
  loading: {
    type: Boolean,
    default: false,
  },
  formatDateTime: {
    type: Function,
    required: true,
  },
  dateTimePickerFormat: {
    type: String,
    required: true,
  },
})

defineEmits(['update:newStatus', 'update:newAt', 'save', 'clear'])
</script>

<style scoped>
.schedule-current {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  background: var(--el-color-warning-light-9);
  border: 1px solid var(--el-color-warning-light-5);
  border-radius: 6px;
  margin-bottom: 16px;
  font-size: 13px;
}

.schedule-form-row {
  display: flex;
  align-items: flex-start;
  gap: 16px;
  flex-wrap: wrap;
}

.field-hint {
  font-size: 12px;
  color: var(--fchub-text-secondary);
  line-height: 1.4;
}
</style>
