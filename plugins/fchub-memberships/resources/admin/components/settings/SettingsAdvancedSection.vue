<template>
  <div class="fchub-settings-section">
    <div class="fchub-settings-section-title">Advanced</div>

    <div class="fchub-setting-row">
      <div class="fchub-setting-label">
        <h4>Debug Mode</h4>
        <p>Enable verbose logging for troubleshooting. Not recommended for production.</p>
      </div>
      <div class="fchub-setting-control">
        <el-switch v-model="form.debug_mode" aria-label="Debug mode" />
      </div>
    </div>

    <div class="fchub-setting-row">
      <div class="fchub-setting-label">
        <h4>Remove Data on Uninstall</h4>
        <p>Delete all Memberships data when the plugin is uninstalled.</p>
      </div>
      <div class="fchub-setting-control">
        <el-switch
          :model-value="form.uninstall_remove_data"
          aria-label="Remove plugin data on uninstall"
          @change="changeUninstallSetting"
        />
      </div>
    </div>
  </div>
</template>

<script setup>
import { ElMessageBox } from 'element-plus'

const props = defineProps({
  form: { type: Object, required: true },
})

async function changeUninstallSetting(enabled) {
  if (!enabled) {
    props.form.uninstall_remove_data = false
    return
  }

  try {
    await ElMessageBox.confirm(
      'Uninstalling the plugin will permanently delete all Memberships data. This cannot be undone.',
      'Delete Memberships data on uninstall?',
      {
        confirmButtonText: 'Enable data removal',
        cancelButtonText: 'Keep data',
        type: 'warning',
      },
    )
    props.form.uninstall_remove_data = true
  } catch {
    props.form.uninstall_remove_data = false
  }
}
</script>
