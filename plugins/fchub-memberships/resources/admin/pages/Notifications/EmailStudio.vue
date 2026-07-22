<template>
  <div class="email-studio-page">
    <WorkspacePageHeader
      eyebrow="Member communications"
      title="Email Studio"
      description="Create dependable membership emails with one shared brand template, precise per-message controls, and exact delivery previews."
      back-to="/settings"
      back-label="Back to Settings"
    />

    <SettingsNotificationsSection :form="studioForm" standalone />
  </div>
</template>

<script setup>
import { reactive } from 'vue'
import SettingsNotificationsSection from '@/components/settings/SettingsNotificationsSection.vue'
import WorkspacePageHeader from '@/components/workspace/WorkspacePageHeader.vue'

const studioForm = reactive({
  email_templates: {},
  email_delivery: {},
  email_theme: {},
})
</script>

<style scoped>
.email-studio-page { min-width: 0; }

@media (max-width: 782px) {
  .email-studio-page :deep(.workspace-page-description) { max-width: 32rem; }
}
</style>
