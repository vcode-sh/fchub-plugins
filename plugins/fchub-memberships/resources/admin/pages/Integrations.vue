<template>
  <div class="integrations-page">
    <WorkspacePageHeader
      eyebrow="Operations"
      title="Integrations"
      description="Review provider availability, capabilities, and membership access operations."
    >
      <template #actions>
        <router-link to="/settings?category=integrations" class="integrations-action">
          Configure integrations
        </router-link>
      </template>
    </WorkspacePageHeader>

    <ProviderIssuePanel
      v-if="selectedProvider"
      :provider="selectedProvider"
      class="integrations-issue-panel"
    />
    <ProviderHealthCards />
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import ProviderHealthCards from '@/components/dashboard/ProviderHealthCards.vue'
import ProviderIssuePanel from '@/components/integrations/ProviderIssuePanel.vue'
import WorkspacePageHeader from '@/components/workspace/WorkspacePageHeader.vue'

const route = useRoute()
const allowedProviders = new Set(['fluentcrm', 'fluent_community'])
const selectedProvider = computed(() => (
  allowedProviders.has(route.query.provider) ? route.query.provider : ''
))
</script>

<style scoped>
.integrations-page {
  min-width: 0;
}

.integrations-issue-panel {
  margin-bottom: 14px;
}

.integrations-action {
  display: inline-flex;
  align-items: center;
  min-height: 34px;
  padding: 0 13px;
  border: 1px solid var(--fchub-border-color);
  border-radius: 8px;
  color: var(--fchub-text-primary);
  background: var(--fchub-card-bg);
  font-size: 12px;
  font-weight: 650;
  text-decoration: none;
}

.integrations-action:hover,
.integrations-action:focus-visible {
  border-color: var(--el-color-primary);
  color: var(--el-color-primary);
}
</style>
