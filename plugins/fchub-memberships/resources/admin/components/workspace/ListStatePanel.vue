<template>
  <div :role="kind === 'error' ? 'alert' : 'status'" class="list-state" :class="`list-state--${kind}`">
    <el-icon class="list-state__icon">
      <WarningFilled v-if="kind === 'error'" />
      <Search v-else-if="kind === 'filtered'" />
      <Files v-else />
    </el-icon>
    <div class="list-state__copy">
      <strong>{{ title }}</strong>
      <p>{{ description }}</p>
    </div>
    <el-button v-if="actionLabel" :type="kind === 'empty' ? 'primary' : 'default'" @click="$emit('action')">
      <el-icon v-if="kind === 'error'"><RefreshRight /></el-icon>
      {{ actionLabel }}
    </el-button>
  </div>
</template>

<script setup>
import { Files, RefreshRight, Search, WarningFilled } from '@element-plus/icons-vue'

defineProps({
  kind: {
    type: String,
    default: 'empty',
  },
  title: {
    type: String,
    required: true,
  },
  description: {
    type: String,
    required: true,
  },
  actionLabel: {
    type: String,
    default: '',
  },
})

defineEmits(['action'])
</script>

<style scoped>
.list-state {
  display: flex;
  align-items: center;
  gap: 14px;
  min-height: 82px;
  margin-top: 14px;
  padding: 16px;
  border: 1px solid var(--fchub-border-color);
  border-radius: var(--fchub-radius-card);
  background: var(--fchub-page-bg);
}

.list-state--error {
  border-color: color-mix(in srgb, var(--el-color-danger) 34%, var(--fchub-border-color));
  background: color-mix(in srgb, var(--el-color-danger) 7%, var(--fchub-card-bg));
}

.list-state__icon {
  flex: 0 0 auto;
  color: var(--fchub-text-secondary);
  font-size: 24px;
}

.list-state--error .list-state__icon { color: var(--el-color-danger); }

.list-state__copy {
  flex: 1;
  min-width: 0;
}

.list-state__copy strong {
  color: var(--fchub-text-primary);
  font-size: 13px;
}

.list-state__copy p {
  margin: 3px 0 0;
  color: var(--fchub-text-secondary);
  font-size: 12px;
  line-height: 1.45;
}

@media (max-width: 480px) {
  .list-state {
    align-items: flex-start;
    flex-wrap: wrap;
  }

  .list-state .el-button { width: 100%; }
}
</style>
