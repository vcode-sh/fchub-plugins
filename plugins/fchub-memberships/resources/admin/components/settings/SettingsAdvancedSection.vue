<template>
  <div class="fchub-settings-section">
    <div class="fchub-settings-section-title">Advanced</div>

    <div class="fchub-setting-row">
      <div class="fchub-setting-label">
        <h4>Debug Mode</h4>
        <p>Enable verbose logging for troubleshooting. Not recommended for production.</p>
      </div>
      <div class="fchub-setting-control">
        <el-switch v-model="form.debug_mode" />
      </div>
    </div>
  </div>
</template>

<script setup>
defineProps({
  form: { type: Object, required: true },
})
</script>
