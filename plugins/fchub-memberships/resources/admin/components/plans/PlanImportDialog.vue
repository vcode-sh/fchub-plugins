<template>
  <el-dialog
    :model-value="modelValue"
    title="Import Plan"
    width="520px"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <el-tabs
      :model-value="mode"
      @update:model-value="emit('update:mode', $event)"
    >
      <el-tab-pane label="Paste JSON" name="paste">
        <el-form label-position="top">
          <el-form-item label="Plan JSON">
            <el-input
              :model-value="json"
              type="textarea"
              :rows="10"
              placeholder="Paste plan JSON data here..."
              @update:model-value="emit('update:json', $event)"
            />
          </el-form-item>
        </el-form>
      </el-tab-pane>
      <el-tab-pane label="Upload File" name="file">
        <div class="import-file-area">
          <input
            ref="fileInputRef"
            type="file"
            accept=".json"
            style="display: none"
            @change="emit('file-selected', $event)"
          />
          <el-button @click="fileInputRef?.click()">
            <el-icon><Upload /></el-icon>
            Select JSON File
          </el-button>
          <span v-if="fileName" class="import-file-name">{{ fileName }}</span>
        </div>
      </el-tab-pane>
    </el-tabs>
    <template #footer>
      <el-button @click="emit('update:modelValue', false)">Cancel</el-button>
      <el-button type="primary" :loading="importing" @click="emit('import')">
        Import
      </el-button>
    </template>
  </el-dialog>
</template>

<script setup>
import { ref } from 'vue'
import { Upload } from '@element-plus/icons-vue'

defineProps({
  fileName: {
    type: String,
    required: true,
  },
  importing: {
    type: Boolean,
    required: true,
  },
  json: {
    type: String,
    required: true,
  },
  mode: {
    type: String,
    required: true,
  },
  modelValue: {
    type: Boolean,
    required: true,
  },
})

const emit = defineEmits({
  'file-selected': (event) => Boolean(event?.target),
  import: () => true,
  'update:json': (value) => typeof value === 'string',
  'update:mode': (value) => typeof value === 'string',
  'update:modelValue': (value) => typeof value === 'boolean',
})

const fileInputRef = ref(null)
</script>

<style scoped>
.import-file-area {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 20px 0;
}

.import-file-name {
  font-size: 13px;
  color: var(--fchub-text-secondary);
}
</style>
