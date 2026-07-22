<template>
  <div class="fchub-settings-section notification-studio-summary-section">
    <div class="notification-studio-summary">
      <div class="notification-studio-summary-icon"><el-icon><Message /></el-icon></div>
      <div>
        <div class="fchub-settings-section-title">Email Studio</div>
        <p>Design all eight membership emails, manage the shared brand template, preview the exact output, and send tests.</p>
      </div>
      <el-button type="primary" @click="router.push('/settings/email-studio')">
        Open Email Studio
        <el-icon><ArrowRight /></el-icon>
      </el-button>
    </div>
    <div class="notification-studio-summary-grid">
      <article><el-icon><Brush /></el-icon><div><strong>One brand template</strong><span>Header, footer, colours, spacing, type, and canvas shared by default.</span></div></article>
      <article><el-icon><EditPen /></el-icon><div><strong>Per-email control</strong><span>Override the shared template only when a message genuinely needs it.</span></div></article>
      <article><el-icon><View /></el-icon><div><strong>Delivery preview</strong><span>Server-rendered desktop and mobile previews plus test delivery.</span></div></article>
    </div>
  </div>
</template>

<script setup>
import { useRouter } from 'vue-router'
import { ArrowRight, Brush, EditPen, Message, View } from '@element-plus/icons-vue'
const router = useRouter()
</script>

<style scoped>
.notification-studio-summary { display: grid; grid-template-columns: 44px minmax(0, 1fr) auto; align-items: center; gap: 14px; padding: 18px; border: 1px solid #cfe1ff; border-radius: 13px; background: linear-gradient(135deg, #f8fbff, #f3f7ff); }
.notification-studio-summary-icon { display: grid; width: 44px; height: 44px; place-items: center; border-radius: 12px; background: #e8f2ff; color: var(--el-color-primary); font-size: 20px; }
.notification-studio-summary p { margin: 5px 0 0; color: var(--fchub-text-secondary); font-size: 11px; line-height: 1.5; }
.notification-studio-summary-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; margin-top: 12px; }
.notification-studio-summary-grid article { display: flex; gap: 10px; padding: 14px; border: 1px solid var(--fchub-border-color); border-radius: 11px; background: #fff; }
.notification-studio-summary-grid article > .el-icon { flex: 0 0 auto; margin-top: 2px; color: var(--el-color-primary); font-size: 17px; }
.notification-studio-summary-grid article div { display: grid; gap: 4px; }
.notification-studio-summary-grid strong { font-size: 11px; }
.notification-studio-summary-grid span { color: var(--fchub-text-secondary); font-size: 10px; line-height: 1.45; }

@media (max-width: 900px) {
  .notification-studio-summary-grid { grid-template-columns: 1fr; }
}

@media (max-width: 600px) {
  .notification-studio-summary { grid-template-columns: 40px minmax(0, 1fr); }
  .notification-studio-summary .el-button { grid-column: 1 / -1; width: 100%; }
}
</style>
