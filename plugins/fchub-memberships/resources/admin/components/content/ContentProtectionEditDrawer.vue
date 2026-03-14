<template>
  <el-drawer
    :model-value="visible"
    title="Edit Protection Rule"
    direction="rtl"
    size="420px"
    :close-on-click-modal="false"
    @close="$emit('close')"
  >
    <div v-if="form" class="edit-drawer-body">
      <div class="edit-resource-header">
        <el-tag size="small" :type="typeTagColor(form.resource_type_group)">
          {{ form.resource_type_label || form.resource_type }}
        </el-tag>
        <h4 class="edit-resource-title">{{ form.resource_title }}</h4>
      </div>

      <el-form label-position="top">
        <el-form-item label="Plans">
          <el-select v-model="form.plan_ids" multiple placeholder="Select plans" style="width: 100%">
            <el-option v-for="plan in planOptions" :key="plan.id" :label="plan.title" :value="plan.id" />
          </el-select>
        </el-form-item>

        <el-form-item label="Show Teaser">
          <el-select v-model="form.show_teaser" style="width: 200px">
            <el-option label="No" value="no" />
            <el-option label="Yes" value="yes" />
          </el-select>
        </el-form-item>

        <el-form-item label="Restriction Message">
          <el-input v-model="form.restriction_message" type="textarea" :rows="3" placeholder="Custom message (leave empty for default)" />
        </el-form-item>

        <el-form-item label="Redirect URL">
          <el-input v-model="form.redirect_url" placeholder="https://example.com/upgrade" />
        </el-form-item>
      </el-form>
    </div>

    <template #footer>
      <el-button @click="$emit('close')">Cancel</el-button>
      <el-button type="primary" :loading="saving" @click="$emit('save')">Save Changes</el-button>
    </template>
  </el-drawer>
</template>

<script setup>
defineProps({
  visible: Boolean,
  form: { type: Object, default: null },
  planOptions: { type: Array, default: () => [] },
  saving: Boolean,
  typeTagColor: { type: Function, required: true },
})

defineEmits(['close', 'save'])
</script>
