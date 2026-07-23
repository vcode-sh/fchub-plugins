<template>
  <section class="provider-health panel" aria-label="Provider health">
    <div class="section-heading">
      <div>
        <p class="section-eyebrow">Integrations</p>
        <h2>Provider health</h2>
      </div>
    </div>

    <p v-if="loading" class="provider-health__state" role="status">Loading provider health…</p>

    <div v-else-if="errorMessage" class="provider-health__state" role="status">
      <span>{{ errorMessage }}</span>
      <button type="button" @click="loadProviders">Try again</button>
    </div>

    <p v-else-if="!items.length" class="provider-health__state">
      No provider information is available.
    </p>

    <div v-else class="provider-health__grid">
      <article v-for="provider in items" :key="provider.value" class="provider-card">
        <header class="provider-card__header">
          <div>
            <h3>{{ provider.label }}</h3>
            <span v-if="provider.version">Version {{ provider.version }}</span>
          </div>
          <span class="provider-card__status" :class="`is-${statusClass(provider.status)}`">
            {{ statusLabel(provider.status) }}
          </span>
        </header>

        <p class="provider-card__reason">{{ statusDescription(provider.status) }}</p>

        <ul v-if="capabilities(provider).length" class="provider-card__capabilities">
          <li v-for="capability in capabilities(provider)" :key="capability.key">
            <span>{{ capabilityLabel(capability.key) }}</span>
            <strong>{{ capabilityStatus(capability.status) }}</strong>
          </li>
        </ul>

        <dl class="provider-card__operations">
          <div>
            <dt>Pending</dt>
            <dd>{{ count(provider.pending_operations) }} pending</dd>
          </div>
          <div>
            <dt>Failed</dt>
            <dd>{{ count(provider.failed_operations) }} failed</dd>
          </div>
        </dl>

        <p class="provider-card__last-success">
          <span>Last successful check</span>
          <strong>{{ lastSuccess(provider.last_successful_reconciliation) }}</strong>
        </p>

        <router-link
          v-if="safeRepairUrl(provider.repair_url)"
          :to="safeRepairUrl(provider.repair_url)"
          class="provider-card__link"
        >
          Review provider
        </router-link>
      </article>
    </div>
  </section>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { providers } from '@/api/providers.js'
import { formatWpDateTime } from '@/utils/wpDate.js'

const items = ref([])
const loading = ref(true)
const errorMessage = ref('')

const statusLabels = {
  healthy: 'Healthy',
  degraded: 'Needs attention',
  inactive: 'Inactive',
  disabled: 'Disabled',
  incompatible: 'Incompatible',
  unverified: 'Not yet verified',
}

const statusDescriptions = {
  healthy: 'The provider is available and no operation failures are reported.',
  degraded: 'Some provider operations need attention.',
  inactive: 'The provider is not active.',
  disabled: 'The provider is available but disabled.',
  incompatible: 'The installed provider is not compatible.',
  unverified: 'Runtime certification has not been completed.',
}

const capabilityLabels = {
  content: 'WordPress content',
  spaces: 'Spaces',
  courses: 'Courses',
  groups: 'Groups',
  lifecycle_sync: 'Lifecycle sync',
  profile_verification_read: 'Profile verification',
  badges: 'Badges',
  points: 'Points',
  leaderboard_levels: 'Levels',
}

async function loadProviders() {
  loading.value = true
  errorMessage.value = ''

  try {
    const response = await providers.list()
    if (!Array.isArray(response?.data)) {
      throw new Error('Invalid provider response')
    }
    items.value = response.data
  } catch {
    items.value = []
    errorMessage.value = 'Provider health could not be loaded.'
  } finally {
    loading.value = false
  }
}

function statusClass(status) {
  return Object.hasOwn(statusLabels, status) ? status : 'unknown'
}

function statusLabel(status) {
  return statusLabels[status] || 'Unavailable'
}

function statusDescription(status) {
  return statusDescriptions[status] || 'Provider status is unavailable.'
}

function capabilities(provider) {
  if (!provider.capabilities || typeof provider.capabilities !== 'object') {
    return []
  }

  return Object.entries(provider.capabilities).map(([key, value]) => ({
    key,
    status: typeof value === 'string' ? value : value?.status,
  }))
}

function capabilityLabel(key) {
  return capabilityLabels[key] || 'Capability'
}

function capabilityStatus(status) {
  return status === 'available' ? 'Available' : statusLabel(status)
}

function count(value) {
  return Math.max(0, Number(value) || 0)
}

function lastSuccess(value) {
  return value ? formatWpDateTime(value, 'Not recorded') : 'Not recorded'
}

function safeRepairUrl(value) {
  return value === '/settings' ? value : ''
}

onMounted(loadProviders)
</script>

<style scoped>
.provider-health {
  min-width: 0;
  padding: 18px;
  border: 1px solid var(--fchub-border-color);
  border-radius: 12px;
  background: var(--fchub-card-bg);
}

.section-heading {
  margin-bottom: 14px;
}

.section-eyebrow {
  margin: 0 0 4px;
  color: var(--fchub-text-secondary);
  font-size: 10px;
  font-weight: 700;
  letter-spacing: .08em;
  text-transform: uppercase;
}

h2,
h3 {
  margin: 0;
  color: var(--fchub-text-primary);
}

h2 {
  font-size: 17px;
}

h3 {
  font-size: 13px;
}

.provider-health__state {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin: 0;
  padding: 12px;
  border-radius: 8px;
  background: var(--fchub-page-bg);
  color: var(--fchub-text-secondary);
  font-size: 12px;
}

.provider-health__state button,
.provider-card__link {
  color: var(--el-color-primary);
  font: inherit;
  font-weight: 600;
  text-decoration: none;
}

.provider-health__grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
}

.provider-card {
  min-width: 0;
  padding: 13px;
  border: 1px solid var(--fchub-border-color);
  border-radius: 9px;
}

.provider-card__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
}

.provider-card__header > div {
  min-width: 0;
}

.provider-card__header span,
.provider-card__reason,
.provider-card__last-success span {
  color: var(--fchub-text-secondary);
  font-size: 10px;
}

.provider-card__status {
  flex: 0 0 auto;
  padding: 3px 7px;
  border-radius: 999px;
  background: var(--fchub-page-bg);
  color: var(--fchub-text-secondary);
  font-size: 10px;
  font-weight: 700;
}

.provider-card__status.is-healthy {
  background: color-mix(in srgb, var(--el-color-success) 14%, var(--fchub-card-bg));
  color: color-mix(in srgb, var(--el-color-success) 78%, var(--fchub-text-primary));
}

.provider-card__status.is-degraded,
.provider-card__status.is-incompatible {
  background: color-mix(in srgb, var(--el-color-warning) 14%, var(--fchub-card-bg));
  color: color-mix(in srgb, var(--el-color-warning) 72%, var(--fchub-text-primary));
}

.provider-card__reason {
  min-height: 2.8em;
  margin: 10px 0;
  line-height: 1.4;
}

.provider-card__capabilities {
  display: grid;
  gap: 5px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.provider-card__capabilities li {
  display: flex;
  justify-content: space-between;
  gap: 8px;
  color: var(--fchub-text-secondary);
  font-size: 10px;
}

.provider-card__capabilities strong {
  color: var(--fchub-text-primary);
}

.provider-card__operations {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
  margin: 12px 0;
}

.provider-card__operations div {
  padding: 7px;
  border-radius: 7px;
  background: var(--fchub-page-bg);
}

.provider-card__operations dt {
  color: var(--fchub-text-secondary);
  font-size: 9px;
  text-transform: uppercase;
}

.provider-card__operations dd {
  margin: 2px 0 0;
  color: var(--fchub-text-primary);
  font-size: 11px;
  font-weight: 600;
}

.provider-card__last-success {
  display: flex;
  flex-direction: column;
  gap: 2px;
  margin: 0 0 10px;
}

.provider-card__last-success strong {
  font-size: 11px;
}

.provider-card__link {
  display: inline-flex;
  font-size: 11px;
}

@media (max-width: 1100px) {
  .provider-health__grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 640px) {
  .provider-health {
    padding: 14px;
  }

  .provider-health__grid {
    grid-template-columns: 1fr;
  }
}
</style>
