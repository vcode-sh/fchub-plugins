<template>
  <aside class="notification-preview-panel" aria-label="Email preview">
    <header>
      <div>
        <span>Live delivery preview</span>
        <strong>{{ subject }}</strong>
      </div>
      <div class="preview-device-switch" role="group" aria-label="Preview size">
        <el-tooltip content="Desktop preview" placement="bottom">
          <button type="button" :class="{ 'is-active': device === 'desktop' }" aria-label="Desktop preview" @click="$emit('update:device', 'desktop')"><el-icon><Monitor /></el-icon></button>
        </el-tooltip>
        <el-tooltip content="Mobile preview" placement="bottom">
          <button type="button" :class="{ 'is-active': device === 'mobile' }" aria-label="Mobile preview" @click="$emit('update:device', 'mobile')"><el-icon><Cellphone /></el-icon></button>
        </el-tooltip>
      </div>
    </header>
    <div v-if="!hideTest" class="preview-test-row">
      <el-input v-model="testAddress" size="small" placeholder="Send test to email…" @keyup.enter="$emit('send-test', testAddress)" />
      <el-button size="small" :loading="testing" @click="$emit('send-test', testAddress)"><el-icon><Promotion /></el-icon>Send test</el-button>
    </div>
    <el-alert v-if="error" type="error" :title="error" show-icon :closable="false" />
    <div class="email-preview-stage" :class="`is-${device}`" v-loading="loading">
      <iframe v-if="html" title="Rendered email preview" sandbox="" :srcdoc="html" />
      <div v-else class="email-preview-empty"><el-icon><View /></el-icon><span>Preparing the delivery preview…</span></div>
    </div>
    <p class="preview-footnote"><el-icon><InfoFilled /></el-icon>This is the same sanitised, server-rendered HTML used for delivery. Email clients may still apply their own rendering rules.</p>
  </aside>
</template>

<script setup>
import { ref } from 'vue'
import { Cellphone, InfoFilled, Monitor, Promotion, View } from '@element-plus/icons-vue'

defineProps({
  html: { type: String, default: '' },
  subject: { type: String, default: '' },
  error: { type: String, default: '' },
  loading: { type: Boolean, default: false },
  testing: { type: Boolean, default: false },
  device: { type: String, default: 'desktop' },
  hideTest: { type: Boolean, default: false },
})
defineEmits(['update:device', 'send-test'])
const testAddress = ref('')
</script>

<style scoped>
.notification-preview-panel { position: sticky; top: 84px; overflow: hidden; min-width: 0; border: 1px solid var(--fchub-border-color); border-radius: 14px; background: #eef2f7; box-shadow: 0 12px 34px rgba(15, 23, 42, .07); }
.notification-preview-panel > header { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 13px 14px; border-bottom: 1px solid var(--fchub-border-color); background: #fff; }
.notification-preview-panel > header > div:first-child { display: grid; min-width: 0; gap: 2px; }
.notification-preview-panel > header span { color: var(--el-color-primary); font-size: 9px; font-weight: 750; text-transform: uppercase; letter-spacing: .06em; }
.notification-preview-panel > header strong { overflow: hidden; max-width: 300px; color: var(--fchub-text-primary); font-size: 11px; text-overflow: ellipsis; white-space: nowrap; }
.preview-device-switch { display: flex; gap: 2px; padding: 3px; border-radius: 8px; background: #eef2f7; }
.preview-device-switch button { display: grid; width: 34px; height: 32px; padding: 0; place-items: center; border: 0; border-radius: 6px; background: transparent; color: #64748b; cursor: pointer; }
.preview-device-switch button.is-active { background: #fff; color: var(--el-color-primary); box-shadow: 0 1px 4px rgba(15, 23, 42, .12); }
.preview-test-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 6px; padding: 10px 12px; border-bottom: 1px solid var(--fchub-border-color); background: #fff; }
.email-preview-stage { min-height: 620px; padding: 16px; transition: padding .2s ease; }
.email-preview-stage iframe { display: block; width: 100%; height: 690px; margin: 0 auto; border: 0; border-radius: 10px; background: #fff; box-shadow: 0 10px 30px rgba(15, 23, 42, .12); transition: width .2s ease; }
.email-preview-stage.is-mobile iframe { width: 360px; max-width: 100%; }
.email-preview-empty { display: grid; min-height: 560px; place-content: center; justify-items: center; gap: 8px; color: #94a3b8; font-size: 11px; }
.email-preview-empty .el-icon { font-size: 24px; }
.preview-footnote { display: flex; align-items: flex-start; gap: 6px; margin: 0; padding: 11px 13px; border-top: 1px solid var(--fchub-border-color); background: #fff; color: var(--fchub-text-tertiary); font-size: 9.5px; line-height: 1.4; }
.preview-footnote .el-icon { flex: 0 0 auto; margin-top: 1px; color: var(--el-color-primary); }

@media (max-width: 1180px) {
  .notification-preview-panel { position: static; }
  .email-preview-stage iframe { height: 640px; }
}

@media (max-width: 782px) {
  .email-preview-stage { min-height: 520px; padding: 8px; }
  .email-preview-stage iframe { height: 560px; }
  .preview-test-row { grid-template-columns: 1fr; }
}
</style>
