<template>
  <el-dialog :model-value="visible" title="Bulk Revoke Plan" width="450px" @close="$emit('close')">
    <p class="bulk-dialog-info">Revoke a plan from {{ selectedCount }} selected members.</p>
    <el-form label-position="top">
      <el-form-item label="Plan" required>
        <el-select :model-value="planId" placeholder="Select plan..." class="full-width" @update:model-value="$emit('update:planId', $event)">
          <el-option v-for="plan in planOptions" :key="plan.id" :label="plan.title" :value="plan.id" />
        </el-select>
      </el-form-item>
      <el-form-item label="Reason">
        <el-input :model-value="reason" type="textarea" :rows="2" placeholder="Optional reason for revoking" @update:model-value="$emit('update:reason', $event)" />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="$emit('close')">Cancel</el-button>
      <el-button type="danger" @click="$emit('confirm')" :loading="loading" :disabled="!planId">
        Revoke from {{ selectedCount }} Members
      </el-button>
    </template>
  </el-dialog>
</template>

<script setup>
defineProps({
  visible: Boolean,
  selectedCount: Number,
  planId: [String, Number],
  reason: {
    type: String,
    default: '',
  },
  planOptions: {
    type: Array,
    default: () => [],
  },
  loading: Boolean,
})

defineEmits(['close', 'confirm', 'update:planId', 'update:reason'])
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
