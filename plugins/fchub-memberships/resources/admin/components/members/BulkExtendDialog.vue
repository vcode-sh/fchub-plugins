<template>
  <el-dialog :model-value="visible" title="Bulk Extend Expiry" width="450px" @close="$emit('close')">
    <p class="bulk-dialog-info">Extend expiry for {{ selectedCount }} selected members.</p>
    <el-form label-position="top">
      <el-form-item label="Plan" required>
        <el-select :model-value="planId" placeholder="Select plan..." class="full-width" @update:model-value="$emit('update:planId', $event)">
          <el-option v-for="plan in planOptions" :key="plan.id" :label="plan.title" :value="plan.id" />
        </el-select>
      </el-form-item>
      <el-form-item label="New Expiry Date" required>
        <el-date-picker
          :model-value="expiresAt"
          type="date"
          placeholder="Select new expiry date"
          :format="datePickerFormat"
          value-format="YYYY-MM-DD"
          class="full-width"
          @update:model-value="$emit('update:expiresAt', $event)"
        />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="$emit('close')">Cancel</el-button>
      <el-button type="primary" @click="$emit('confirm')" :loading="loading" :disabled="!planId || !expiresAt">
        Extend {{ selectedCount }} Members
      </el-button>
    </template>
  </el-dialog>
</template>

<script setup>
defineProps({
  visible: Boolean,
  selectedCount: Number,
  planId: [String, Number],
  expiresAt: {
    type: String,
    default: '',
  },
  planOptions: {
    type: Array,
    default: () => [],
  },
  loading: Boolean,
  datePickerFormat: {
    type: String,
    required: true,
  },
})

defineEmits(['close', 'confirm', 'update:planId', 'update:expiresAt'])
</script>

<style scoped>
.bulk-dialog-info {
  font-size: 14px;
  color: var(--fchub-text-secondary);
  margin-bottom: 16px;
}

.full-width {
  width: 100%;
}
</style>
