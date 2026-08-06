<template>
  <template v-if="!editing && !editingBrand">
    <div class="notification-studio-intro">
      <div>
        <div class="fchub-settings-section-title">Member email studio</div>
        <p>Control every transactional message, its delivery owner, content, and brand styling.</p>
      </div>
      <el-tag v-if="catalogReady" effect="plain" round>
        {{ activeCount }} of {{ notifications.length }} active
      </el-tag>
    </div>

    <el-alert
      v-if="loadError"
      type="error"
      :title="loadError"
      show-icon
      :closable="false"
    >
      <template #default>
        <el-button size="small" @click="$emit('retry')">Try again</el-button>
      </template>
    </el-alert>

    <el-skeleton v-else-if="loading" :rows="6" animated />

    <template v-else>
      <div v-if="fluentcrmAvailable" class="notification-advanced-option">
        <el-icon><Connection /></el-icon>
        <div>
          <strong>Advanced automation is available</strong>
          <span>
            FluentCRM can own selected events when you need sequences or conditions.
            Built-in email remains the default.
          </span>
        </div>
      </div>

      <button type="button" class="brand-template-card" @click="$emit('open-brand')">
        <span class="brand-template-card-icon"><el-icon><Brush /></el-icon></span>
        <span>
          <strong>Global brand template</strong>
          <small>Header, footer, canvas, typography, spacing, and colour defaults.</small>
        </span>
        <span class="brand-template-swatches">
          <i :style="{ background: brandTemplate.header_background }" />
          <i :style="{ background: brandTemplate.primary_color }" />
          <i :style="{ background: brandTemplate.background_color }" />
        </span>
        <el-icon><ArrowRight /></el-icon>
      </button>

      <section
        v-for="group in notificationGroups"
        :key="group.key"
        class="notification-group"
      >
        <header>
          <h3>{{ group.label }}</h3>
          <span>
            {{ group.items.length }} {{ group.items.length === 1 ? 'message' : 'messages' }}
          </span>
        </header>

        <div class="notification-card-grid">
          <article
            v-for="notification in group.items"
            :key="notification.key"
            class="notification-card"
          >
            <div class="notification-card-main">
              <div class="notification-card-icon"><el-icon><Message /></el-icon></div>
              <div class="notification-card-copy">
                <div class="notification-card-title-row">
                  <h4>{{ notification.label }}</h4>
                  <span
                    class="delivery-dot"
                    :class="`is-${currentDelivery(notification)}`"
                  />
                </div>
                <p>{{ notification.description }}</p>
                <strong class="notification-subject-preview">
                  {{ templateFor(notification).subject }}
                </strong>
              </div>
            </div>

            <div class="notification-card-actions">
              <el-select
                :model-value="currentDelivery(notification)"
                size="small"
                :aria-label="`${notification.label} delivery`"
                @change="$emit('set-delivery', notification, $event)"
              >
                <el-option
                  v-for="option in availableDeliveryOptions"
                  :key="option.value"
                  :label="option.label"
                  :value="option.value"
                />
              </el-select>
              <el-tooltip content="Edit email" placement="top">
                <el-button
                  size="small"
                  circle
                  :aria-label="`Edit ${notification.label}`"
                  @click="$emit('edit', notification)"
                >
                  <el-icon><EditPen /></el-icon>
                </el-button>
              </el-tooltip>
            </div>
          </article>
        </div>
      </section>
    </template>
  </template>
</template>

<script setup>
import { ArrowRight, Brush, Connection, EditPen, Message } from '@element-plus/icons-vue'

defineProps({
  activeCount: { type: Number, required: true },
  availableDeliveryOptions: { type: Array, required: true },
  brandTemplate: { type: Object, required: true },
  catalogReady: { type: Boolean, default: false },
  currentDelivery: { type: Function, required: true },
  editing: { type: Object, default: null },
  editingBrand: { type: Boolean, default: false },
  fluentcrmAvailable: { type: Boolean, default: false },
  loadError: { type: String, default: '' },
  loading: { type: Boolean, default: false },
  notificationGroups: { type: Array, required: true },
  notifications: { type: Array, required: true },
  templateFor: { type: Function, required: true },
})

defineEmits(['edit', 'open-brand', 'retry', 'set-delivery'])
</script>

<style scoped>
.notification-studio-intro {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 20px;
  margin-bottom: 18px;
}
.notification-studio-intro p {
  margin: 5px 0 0;
  color: var(--fchub-text-secondary);
  font-size: 12px;
  line-height: 1.5;
}
.notification-advanced-option {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  margin-bottom: 20px;
  padding: 13px 15px;
  border: 1px solid #ddd6fe;
  border-radius: 10px;
  background: #faf8ff;
  color: #5b21b6;
}
.notification-advanced-option > .el-icon {
  flex: 0 0 auto;
  margin-top: 2px;
  font-size: 18px;
}
.notification-advanced-option div { display: grid; gap: 3px; }
.notification-advanced-option strong { color: #4c1d95; font-size: 12px; }
.notification-advanced-option span { color: #6d5a8f; font-size: 11px; line-height: 1.45; }
.brand-template-card {
  display: grid;
  grid-template-columns: 42px minmax(0, 1fr) auto 18px;
  align-items: center;
  gap: 12px;
  width: 100%;
  margin: 0 0 22px;
  padding: 14px 16px;
  border: 1px solid #bfd8ff;
  border-radius: 12px;
  background: linear-gradient(135deg, #f8fbff, #f2f7ff);
  color: var(--fchub-text-primary);
  text-align: left;
  cursor: pointer;
  transition: border-color .16s ease, box-shadow .16s ease, transform .16s ease;
}
.brand-template-card:hover {
  border-color: var(--el-color-primary);
  box-shadow: 0 9px 24px rgba(37, 99, 235, .09);
  transform: translateY(-1px);
}
.brand-template-card-icon {
  display: grid;
  width: 42px;
  height: 42px;
  place-items: center;
  border-radius: 11px;
  background: #fff;
  color: var(--el-color-primary);
  box-shadow: 0 3px 10px rgba(37, 99, 235, .1);
  font-size: 18px;
}
.brand-template-card > span:nth-child(2) { display: grid; gap: 4px; }
.brand-template-card strong { font-size: 12px; }
.brand-template-card small {
  color: var(--fchub-text-secondary);
  font-size: 10.5px;
  line-height: 1.4;
}
.brand-template-swatches { display: flex; gap: 5px; }
.brand-template-swatches i {
  display: block;
  width: 22px;
  height: 22px;
  border: 2px solid #fff;
  border-radius: 50%;
  box-shadow: 0 0 0 1px rgba(15, 23, 42, .12);
}
.notification-group + .notification-group { margin-top: 22px; }
.notification-group > header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 9px;
}
.notification-group > header h3 {
  margin: 0;
  color: var(--fchub-text-primary);
  font-size: 13px;
  font-weight: 700;
}
.notification-group > header span {
  color: var(--fchub-text-tertiary);
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: .05em;
}
.notification-card-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}
.notification-card {
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  min-width: 0;
  padding: 15px;
  border: 1px solid var(--fchub-border-color);
  border-radius: 11px;
  background: var(--fchub-card-bg);
  transition: border-color .16s ease, box-shadow .16s ease, transform .16s ease;
}
.notification-card:hover {
  border-color: color-mix(in srgb, var(--el-color-primary) 28%, var(--fchub-border-color));
  box-shadow: 0 8px 22px rgba(15, 23, 42, .055);
  transform: translateY(-1px);
}
.notification-card-main { display: flex; align-items: flex-start; gap: 11px; min-width: 0; }
.notification-card-icon {
  display: grid;
  flex: 0 0 auto;
  width: 34px;
  height: 34px;
  place-items: center;
  border-radius: 9px;
  background: #eef6ff;
  color: var(--el-color-primary);
}
.notification-card-copy { min-width: 0; }
.notification-card-title-row { display: flex; align-items: center; gap: 7px; }
.notification-card h4 {
  margin: 0;
  color: var(--fchub-text-primary);
  font-size: 12px;
  font-weight: 700;
}
.notification-card p {
  min-height: 32px;
  margin: 4px 0 8px;
  color: var(--fchub-text-secondary);
  font-size: 10.5px;
  line-height: 1.45;
}
.notification-subject-preview {
  display: block;
  overflow: hidden;
  color: var(--fchub-text-secondary);
  font-size: 10.5px;
  font-weight: 550;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.delivery-dot { width: 7px; height: 7px; border-radius: 50%; background: #94a3b8; }
.delivery-dot.is-built_in { background: #22c55e; }
.delivery-dot.is-fluentcrm { background: #8b5cf6; }
.notification-card-actions {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 8px;
  margin-top: 14px;
}
.notification-card-actions .el-select { width: 100%; }

@media (max-width: 1180px) {
  .notification-card-grid { grid-template-columns: 1fr; }
}
@media (max-width: 782px) {
  .notification-card-actions { grid-template-columns: 1fr; }
  .brand-template-card { grid-template-columns: 40px minmax(0, 1fr) 18px; }
  .brand-template-swatches { display: none; }
}
@media (max-width: 480px) {
  .notification-card-main { gap: 8px; }
  .notification-card { padding: 12px; }
}
</style>
