<template>
  <router-link
    class="workspace-back-button"
    :to="to"
    :aria-label="label"
    :title="label"
  >
    <ArrowLeft aria-hidden="true" />
  </router-link>
</template>

<script setup>
import { ArrowLeft } from '@element-plus/icons-vue'

defineProps({
  to: { type: [String, Object], required: true },
  label: { type: String, required: true },
})
</script>

<style scoped>
.workspace-back-button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  flex: 0 0 40px;
  border: 1px solid transparent;
  border-radius: 8px;
  color: var(--fchub-text-secondary);
  text-decoration: none;
  transition: color .15s ease, background .15s ease, border-color .15s ease;
}

.workspace-back-button :deep(svg) {
  width: 17px;
  height: 17px;
}

.workspace-back-button:hover {
  border-color: var(--fchub-border-color);
  background: var(--fchub-card-bg);
  color: var(--el-color-primary);
}

.workspace-back-button:focus-visible {
  outline: 2px solid var(--el-color-primary);
  outline-offset: 2px;
}
</style>
