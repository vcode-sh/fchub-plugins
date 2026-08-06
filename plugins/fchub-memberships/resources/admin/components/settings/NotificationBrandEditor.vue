<template>
  <template v-if="editingBrand">
    <div class="notification-editor-header">
      <button type="button" class="notification-editor-back" @click="$emit('cancel')">
        <el-icon><ArrowLeft /></el-icon>
        All notifications
      </button>
      <div class="notification-editor-title">
        <span>Shared design</span>
        <h2>Brand template</h2>
      </div>
      <div class="notification-editor-actions">
        <el-button :loading="savingBrand" type="primary" @click="$emit('save')">
          <el-icon><Check /></el-icon>
          Save template
        </el-button>
      </div>
    </div>

    <div class="notification-editor-workspace notification-editor-workspace--brand">
      <EmailBrandTemplateEditor
        :model-value="draftTheme"
        class="notification-composer"
        :variables="globalVariables"
        aria-label="Global email brand template"
        @update:model-value="$emit('update:draft-theme', $event)"
        @choose-logo="$emit('choose-logo')"
      />
      <EmailPreviewPanel
        :html="previewHtml"
        :subject="previewSubject"
        :error="previewError"
        :loading="previewing"
        :device="previewDevice"
        hide-test
        @update:device="$emit('update:device', $event)"
      />
    </div>
  </template>
</template>

<script setup>
import { ArrowLeft, Check } from '@element-plus/icons-vue'
import EmailBrandTemplateEditor from './EmailBrandTemplateEditor.vue'
import EmailPreviewPanel from './EmailPreviewPanel.vue'

defineProps({
  draftTheme: { type: Object, default: null },
  editingBrand: { type: Boolean, default: false },
  globalVariables: { type: Object, required: true },
  previewDevice: { type: String, required: true },
  previewError: { type: String, default: '' },
  previewHtml: { type: String, default: '' },
  previewing: { type: Boolean, default: false },
  previewSubject: { type: String, default: '' },
  savingBrand: { type: Boolean, default: false },
})

defineEmits(['cancel', 'choose-logo', 'save', 'update:device', 'update:draft-theme'])
</script>

<style scoped>
.notification-editor-header {
  display: grid;
  grid-template-columns: minmax(120px, 1fr) minmax(180px, auto) minmax(260px, 1fr);
  align-items: center;
  gap: 16px;
  margin: -4px 0 18px;
  padding-bottom: 16px;
  border-bottom: 1px solid var(--fchub-border-color);
}
.notification-editor-back {
  display: inline-flex;
  align-items: center;
  justify-self: start;
  gap: 5px;
  padding: 0;
  border: 0;
  background: transparent;
  color: var(--el-color-primary);
  font-size: 11px;
  font-weight: 650;
  cursor: pointer;
}
.notification-editor-title { text-align: center; }
.notification-editor-title span {
  color: var(--el-color-primary);
  font-size: 9px;
  font-weight: 750;
  text-transform: uppercase;
  letter-spacing: .08em;
}
.notification-editor-title h2 {
  margin: 3px 0 0;
  color: var(--fchub-text-primary);
  font-size: 18px;
  line-height: 1.2;
}
.notification-editor-actions { display: flex; justify-content: flex-end; gap: 7px; }
.notification-editor-workspace {
  display: grid;
  grid-template-columns: minmax(0, 1.08fr) minmax(330px, .92fr);
  gap: 16px;
  align-items: start;
}
.notification-editor-workspace--brand {
  grid-template-columns: minmax(0, 1fr) minmax(390px, .82fr);
}
.notification-composer { min-width: 0; }

@media (max-width: 1180px) {
  .notification-editor-workspace { grid-template-columns: 1fr; }
}
@media (max-width: 782px) {
  .notification-editor-header { grid-template-columns: 1fr auto; gap: 10px; }
  .notification-editor-title {
    grid-column: 1 / -1;
    grid-row: 1;
    text-align: left;
  }
  .notification-editor-back { grid-column: 1; grid-row: 2; }
  .notification-editor-actions {
    grid-column: 2;
    grid-row: 2;
    flex-wrap: wrap;
  }
}
@media (max-width: 480px) {
  .notification-editor-actions .el-button { flex: 1; margin: 0; }
}
</style>
