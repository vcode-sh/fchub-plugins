<template>
  <section class="provider-issue-panel" aria-live="polite">
    <header class="provider-issue-panel__header">
      <div>
        <p class="provider-issue-panel__eyebrow">Issue review</p>
        <h2>{{ providerTitle }} issues</h2>
      </div>
      <p>Read-only provider details from the latest available checks.</p>
    </header>

    <p v-if="loading" class="provider-issue-panel__state" role="status">
      Checking provider issues…
    </p>

    <div v-else-if="loadFailed" class="provider-issue-panel__state" role="alert">
      <span>Provider issue details could not be loaded.</span>
      <button type="button" @click="loadIssues">Try again</button>
    </div>

    <template v-else-if="provider === 'fluentcrm'">
      <p class="provider-issue-panel__guidance">{{ crmAction }}</p>
      <dl class="provider-issue-metrics">
        <div v-for="metric in crmMetrics" :key="metric.label" class="provider-issue-metric">
          <dt>{{ metric.label }}</dt>
          <dd class="provider-issue-metric__value">{{ metric.value }}</dd>
        </div>
      </dl>
    </template>

    <template v-else-if="provider === 'fluent_community'">
      <p v-if="!communityIssues.length" class="provider-issue-panel__state">
        No FluentCommunity access issues were found in the checked resources.
      </p>
      <ul v-else class="provider-issue-list">
        <li
          v-for="issue in communityIssues"
          :key="issue.key"
          class="provider-issue-item"
        >
          <strong>{{ issue.classification }}</strong>
          <span>{{ issue.member }} · {{ issue.resource }}</span>
        </li>
      </ul>
      <p v-if="hasHiddenCommunityIssues" class="provider-issue-panel__more">
        This panel shows the first five issues from this page.
      </p>
      <p v-if="hasMoreCommunityResources" class="provider-issue-panel__more">
        More resources remain to be checked.
      </p>
    </template>

    <p v-else class="provider-issue-panel__state">
      Issue details are not available for this provider.
    </p>
  </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { providers } from '@/api/providers.js'

const props = defineProps({
  provider: {
    type: String,
    required: true,
    validator: (value) => ['fluentcrm', 'fluent_community'].includes(value),
  },
})

const loading = ref(true)
const loadFailed = ref(false)
const crmHealth = ref({})
const communityIssues = ref([])
const hasHiddenCommunityIssues = ref(false)
const hasMoreCommunityResources = ref(false)
let requestSerial = 0
const communityPageLimit = 3
const communityIssueLimit = 5

const providerTitle = computed(() => ({
  fluentcrm: 'FluentCRM',
  fluent_community: 'FluentCommunity',
})[props.provider] || 'Provider')

const knownCrmActions = new Set([
  'Install and activate FluentCRM.',
  'Enable FluentCRM lifecycle sync.',
  'Update FluentCRM to a compatible version.',
  'Repair CRM projection storage.',
  'Run a dry reconciliation and resolve failures.',
  'No action required.',
])

const classificationLabels = {
  local_only: 'Local access only',
  provider_uncertified: 'Provider not certified',
  provider_unknown: 'Provider state unknown',
  provider_unavailable: 'Provider unavailable',
  operation_pending: 'Operation pending',
  operation_processing: 'Operation in progress',
  operation_stale: 'Operation stalled',
  operation_retryable_failed: 'Operation retry required',
  operation_terminal_failed: 'Operation failed',
  internal_active_provider_absent: 'Access missing in provider',
  internal_paused_provider_present: 'Paused access still present',
  internal_ended_provider_present: 'Ended access still present',
  unknown_ownership: 'Ownership needs review',
}

const resourceLabels = {
  fc_space: 'Space',
  fc_course: 'Course',
  fc_badge: 'Badge',
}

const crmAction = computed(() => {
  const action = typeof crmHealth.value.action === 'string' ? crmHealth.value.action : ''
  return knownCrmActions.has(action)
    ? action
    : 'Review the integration configuration and retry this check.'
})

const crmMetrics = computed(() => [
  { label: 'Pending projections', value: safeCount(crmHealth.value.pending_projections) },
  { label: 'Failed projections', value: safeCount(crmHealth.value.failed_projections) },
  { label: 'Failed reconciliations', value: safeCount(crmHealth.value.failed_reconciliations) },
  { label: 'Detected drift', value: safeCount(crmHealth.value.drift) },
])

function safeCount(value) {
  const count = Number(value)
  return Number.isFinite(count) ? Math.max(0, Math.trunc(count)) : 0
}

function safeMember(value) {
  const userId = Number(value)
  return Number.isInteger(userId) && userId > 0 ? `Member #${userId}` : 'Member unavailable'
}

function safeResource(type, value) {
  const label = resourceLabels[type]
  const resourceId = typeof value === 'number' ? String(value) : String(value || '')
  if (!label) {
    return 'Resource unavailable'
  }

  if (type === 'fc_space' || type === 'fc_course') {
    return /^[1-9]\d*$/.test(resourceId)
      ? `${label} #${resourceId}`
      : 'Resource unavailable'
  }

  return /^[a-z0-9][a-z0-9_-]{0,190}$/i.test(resourceId)
    ? `${label} ${resourceId}`
    : 'Resource unavailable'
}

function normaliseCommunityIssue(item, index) {
  const classification = typeof item?.classification === 'string' ? item.classification : ''

  return {
    key: `${index}:${safeCount(item?.cursor_id)}`,
    classification: classificationLabels[classification] || 'Issue needs review',
    member: safeMember(item?.user_id),
    resource: safeResource(item?.resource_type, item?.resource_id),
  }
}

async function loadIssues() {
  const currentRequest = ++requestSerial
  loading.value = true
  loadFailed.value = false
  crmHealth.value = {}
  communityIssues.value = []
  hasHiddenCommunityIssues.value = false
  hasMoreCommunityResources.value = false

  try {
    if (props.provider === 'fluentcrm') {
      const response = await providers.fluentCrmHealth()
      if (!response?.data || typeof response.data !== 'object' || Array.isArray(response.data)) {
        throw new Error('Invalid provider health response')
      }
      if (currentRequest === requestSerial) {
        crmHealth.value = response.data
      }
    } else if (props.provider === 'fluent_community') {
      let cursor = null
      let pageCount = 0
      const issues = []

      while (pageCount < communityPageLimit && issues.length <= communityIssueLimit) {
        const response = await providers.reconciliationPage({ limit: 100, cursor })
        if (!response?.data || typeof response.data !== 'object' || !Array.isArray(response.data.items)) {
          throw new Error('Invalid provider reconciliation response')
        }
        if (currentRequest !== requestSerial) return

        issues.push(...response.data.items.filter((item) => (
          item?.provider === 'fluent_community' && item?.classification !== 'healthy'
        )))
        cursor = typeof response.data.next_cursor === 'string' && response.data.next_cursor !== ''
          ? response.data.next_cursor
          : null
        pageCount += 1
        if (!cursor) break
      }

      if (currentRequest === requestSerial) {
        communityIssues.value = issues.slice(0, communityIssueLimit).map(normaliseCommunityIssue)
        hasHiddenCommunityIssues.value = issues.length > communityIssueLimit
        hasMoreCommunityResources.value = cursor !== null
      }
    }
  } catch {
    if (currentRequest === requestSerial) {
      loadFailed.value = true
    }
  } finally {
    if (currentRequest === requestSerial) {
      loading.value = false
    }
  }
}

watch(() => props.provider, loadIssues, { immediate: true })
</script>

<style scoped>
.provider-issue-panel {
  min-width: 0;
  padding: 16px 18px;
  border: 1px solid var(--fchub-border-color);
  border-radius: 12px;
  background: var(--fchub-card-bg);
}

.provider-issue-panel__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 18px;
  margin-bottom: 14px;
}

.provider-issue-panel__header h2,
.provider-issue-panel__header p {
  margin: 0;
}

.provider-issue-panel__header h2 {
  color: var(--fchub-text-primary);
  font-size: 15px;
  line-height: 1.3;
}

.provider-issue-panel__header > p {
  max-width: 420px;
  color: var(--fchub-text-secondary);
  font-size: 12px;
  line-height: 1.45;
  text-align: right;
}

.provider-issue-panel__eyebrow {
  margin-bottom: 3px !important;
  color: var(--fchub-text-secondary);
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.provider-issue-panel__state {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin: 0;
  color: var(--fchub-text-secondary);
  font-size: 12px;
}

.provider-issue-panel__state button {
  min-height: 30px;
  padding: 0 10px;
  border: 1px solid var(--fchub-border-color);
  border-radius: 7px;
  color: var(--fchub-text-primary);
  background: var(--fchub-card-bg);
  cursor: pointer;
}

.provider-issue-panel__guidance {
  margin: 0 0 12px;
  color: var(--fchub-text-secondary);
  font-size: 12px;
  line-height: 1.5;
}

.provider-issue-metrics {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 8px;
  margin: 0;
}

.provider-issue-metric {
  min-width: 0;
  padding: 10px 11px;
  border-radius: 8px;
  background: var(--fchub-page-bg);
}

.provider-issue-metric dt {
  overflow-wrap: anywhere;
  color: var(--fchub-text-secondary);
  font-size: 10px;
  font-weight: 650;
  line-height: 1.35;
}

.provider-issue-metric dd {
  margin: 4px 0 0;
  color: var(--fchub-text-primary);
  font-size: 14px;
  font-weight: 700;
}

.provider-issue-list {
  display: grid;
  gap: 7px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.provider-issue-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
  min-width: 0;
  padding: 9px 11px;
  border-radius: 8px;
  background: var(--fchub-page-bg);
}

.provider-issue-item strong {
  color: var(--fchub-text-primary);
  font-size: 12px;
}

.provider-issue-item span {
  min-width: 0;
  overflow-wrap: anywhere;
  color: var(--fchub-text-secondary);
  font-size: 11px;
  text-align: right;
}

.provider-issue-panel__more {
  margin: 10px 0 0;
  color: var(--fchub-text-secondary);
  font-size: 11px;
}

@media (max-width: 782px) {
  .provider-issue-panel__header,
  .provider-issue-item {
    align-items: flex-start;
    flex-direction: column;
    gap: 6px;
  }

  .provider-issue-panel__header > p,
  .provider-issue-item span {
    text-align: left;
  }

  .provider-issue-metrics {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
