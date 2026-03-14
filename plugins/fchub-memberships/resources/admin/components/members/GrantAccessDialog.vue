<template>
  <el-dialog :model-value="visible" title="Grant Access" width="500px" @close="$emit('close')">
    <el-form :model="form" label-position="top">
      <el-form-item label="User" required>
        <el-select
          :model-value="form.user_id"
          filterable
          remote
          :remote-method="searchUsers"
          :loading="searchingUsers"
          placeholder="Search WordPress users..."
          class="full-width"
          @update:model-value="$emit('update:userId', $event)"
        >
          <el-option
            v-for="user in userResults"
            :key="user.id"
            :label="`${user.display_name} (${user.email})`"
            :value="user.id"
          />
        </el-select>
      </el-form-item>
      <el-form-item label="Plan" required>
        <el-select
          :model-value="form.plan_id"
          placeholder="Select plan..."
          class="full-width"
          @update:model-value="$emit('update:planId', $event)"
        >
          <el-option
            v-for="plan in planOptions"
            :key="plan.id"
            :label="plan.title"
            :value="plan.id"
          />
        </el-select>
      </el-form-item>
      <el-form-item label="Expiry Date">
        <el-date-picker
          :model-value="form.expires_at"
          type="date"
          placeholder="Leave empty for plan default"
          :format="datePickerFormat"
          value-format="YYYY-MM-DD"
          class="full-width"
          @update:model-value="$emit('update:expiresAt', $event)"
        />
      </el-form-item>
      <el-form-item label="Reason">
        <el-input
          :model-value="form.reason"
          type="textarea"
          :rows="2"
          placeholder="Optional reason for granting access"
          @update:model-value="$emit('update:reason', $event)"
        />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="$emit('close')">Cancel</el-button>
      <el-button type="primary" @click="$emit('confirm')" :loading="loading" :disabled="!form.user_id || !form.plan_id">
        Grant Access
      </el-button>
    </template>
  </el-dialog>
</template>

<script setup>
defineProps({
  visible: {
    type: Boolean,
    default: false,
  },
  form: {
    type: Object,
    required: true,
  },
  loading: {
    type: Boolean,
    default: false,
  },
  searchingUsers: {
    type: Boolean,
    default: false,
  },
  userResults: {
    type: Array,
    default: () => [],
  },
  planOptions: {
    type: Array,
    default: () => [],
  },
  datePickerFormat: {
    type: String,
    required: true,
  },
  searchUsers: {
    type: Function,
    required: true,
  },
})

defineEmits(['close', 'confirm', 'update:userId', 'update:planId', 'update:expiresAt', 'update:reason'])
</script>

<style scoped>
.full-width {
  width: 100%;
}
</style>
