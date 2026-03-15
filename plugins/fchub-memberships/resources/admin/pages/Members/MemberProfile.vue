<template>
  <div class="member-profile-page" v-loading="loading">
    <!-- Back link -->
    <a class="back-link" @click.prevent="$router.push('/members')">
      <el-icon><ArrowLeft /></el-icon>
      Back to Members
    </a>

    <!-- Member Header -->
    <el-card shadow="never" class="member-header" v-if="member">
      <div class="header-content">
        <div class="member-info">
          <h2>{{ member.display_name }}</h2>
          <div class="member-meta">
            <span class="member-email">
              <el-icon><Message /></el-icon>
              {{ member.email || member.user_email }}
            </span>
            <span class="member-registered">
              <el-icon><Calendar /></el-icon>
              Registered {{ formatDate(member.registered_at) }}
            </span>
          </div>
        </div>
        <div class="header-actions">
          <el-button type="primary" @click="grantDialogVisible = true">
            <el-icon><Plus /></el-icon>
            Grant Access
          </el-button>
          <el-popconfirm
            title="Are you sure you want to revoke all active grants for this user?"
            confirm-button-text="Revoke All"
            confirm-button-type="danger"
            @confirm="handleRevokeAll"
          >
            <template #reference>
              <el-button type="danger" plain :loading="revokingAll">
                <el-icon><CircleClose /></el-icon>
                Revoke All
              </el-button>
            </template>
          </el-popconfirm>
        </div>
      </div>
    </el-card>

    <!-- Active Grants -->
    <el-card shadow="never" class="section-card" v-if="member">
      <template #header>
        <div class="section-title">
          <el-icon><Key /></el-icon>
          Active Grants
        </div>
      </template>
      <el-table :data="activeGrants">
        <el-table-column prop="plan_title" label="Plan" min-width="180">
          <template #default="{ row }">
            <el-tag size="small" class="clickable-plan-tag" @click="openDripDrawer(row)">
              {{ row.plan_title }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="Status" width="140">
          <template #default="{ row }">
            <el-tag :type="statusTagType(row.status)" size="small">
              {{ row.status }}
            </el-tag>
            <el-tag v-if="row.trial_ends_at && new Date(row.trial_ends_at) > new Date()" type="info" size="small" style="margin-left: 4px">
              Trial
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="Granted" width="130">
          <template #default="{ row }">
            {{ formatDate(row.created_at) }}
          </template>
        </el-table-column>
        <el-table-column label="Expires" width="130">
          <template #default="{ row }">
            {{ row.expires_at ? formatDate(row.expires_at) : 'Lifetime' }}
          </template>
        </el-table-column>
        <el-table-column prop="source_type" label="Source" width="100">
          <template #default="{ row }">
            <span class="source-label">{{ row.source_type || '-' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="Actions" width="240">
          <template #default="{ row }">
            <div class="action-buttons">
              <el-button
                v-if="row.status === 'active'"
                size="small"
                type="warning"
                plain
                @click="handlePause(row)"
              >
                Pause
              </el-button>
              <el-button
                v-if="row.status === 'paused'"
                size="small"
                type="success"
                plain
                @click="handleResume(row)"
              >
                Resume
              </el-button>
              <el-button
                size="small"
                @click="openExtendDialog(row)"
              >
                Extend
              </el-button>
              <el-popconfirm
                title="Revoke this grant?"
                confirm-button-text="Revoke"
                confirm-button-type="danger"
                @confirm="handleRevoke(row)"
              >
                <template #reference>
                  <el-button size="small" type="danger" plain>
                    Revoke
                  </el-button>
                </template>
              </el-popconfirm>
            </div>
          </template>
        </el-table-column>
      </el-table>
      <el-empty v-if="activeGrants.length === 0" description="No active grants" />
    </el-card>

    <!-- Drip Timeline -->
    <el-card shadow="never" class="section-card" v-if="member && timeline.length > 0">
      <template #header>
        <div class="section-title">
          <el-icon><Timer /></el-icon>
          Drip Timeline
        </div>
      </template>
      <div v-for="planTimeline in timeline" :key="planTimeline.plan_id" class="drip-plan-group">
        <h4 class="drip-plan-title">{{ planTimeline.plan_title }}</h4>
        <el-timeline>
          <el-timeline-item
            v-for="item in planTimeline.items"
            :key="item.id"
            :type="dripItemType(item.status)"
            :hollow="item.status === 'locked'"
            :timestamp="item.status === 'unlocked' ? formatDate(item.unlocked_at) : (item.status === 'upcoming' ? `Unlocks ${formatDate(item.unlock_date)}` : 'Locked')"
          >
            <div class="drip-item">
              <span class="drip-item-title">{{ item.title }}</span>
              <el-tag
                :type="dripItemType(item.status)"
                size="small"
                class="drip-status-tag"
              >
                {{ item.status }}
              </el-tag>
            </div>
          </el-timeline-item>
        </el-timeline>
      </div>
    </el-card>

    <!-- Grant History -->
    <el-card shadow="never" class="section-card" v-if="member">
      <template #header>
        <div class="section-title">
          <el-icon><Document /></el-icon>
          Grant History
        </div>
      </template>
      <el-table :data="allGrants">
        <el-table-column prop="plan_title" label="Plan" min-width="160">
          <template #default="{ row }">
            <el-tag size="small">{{ row.plan_title }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="Status" width="110">
          <template #default="{ row }">
            <el-tag :type="statusTagType(row.status)" size="small">
              {{ row.status }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="Granted" width="130">
          <template #default="{ row }">
            {{ formatDate(row.created_at) }}
          </template>
        </el-table-column>
        <el-table-column label="Expires" width="130">
          <template #default="{ row }">
            {{ row.expires_at ? formatDate(row.expires_at) : 'Lifetime' }}
          </template>
        </el-table-column>
        <el-table-column prop="source_type" label="Source" width="100" />
        <el-table-column label="Revoked" width="140">
          <template #default="{ row }">
            {{ row.revoked_at ? formatDate(row.revoked_at) : '-' }}
          </template>
        </el-table-column>
      </el-table>
      <el-empty v-if="allGrants.length === 0" description="No grant history" />
    </el-card>

    <!-- Activity Timeline -->
    <el-card shadow="never" class="section-card" v-if="member">
      <template #header>
        <div class="section-title">
          <el-icon><List /></el-icon>
          Activity Timeline
        </div>
      </template>
      <div v-loading="activityLoading">
        <el-timeline v-if="activityEvents.length > 0">
          <el-timeline-item
            v-for="(event, idx) in activityEvents"
            :key="idx"
            :type="activityEventColor(event.type)"
            :hollow="event.type.startsWith('drip_scheduled')"
            :timestamp="formatDate(event.date)"
            placement="top"
          >
            <div class="activity-event">
              <div class="activity-event-header">
                <el-tag :type="activityEventColor(event.type)" size="small">
                  {{ activityEventLabel(event.type) }}
                </el-tag>
                <span class="activity-description">{{ event.description }}</span>
              </div>
              <div
                v-if="event.metadata && (event.metadata.context || event.metadata.plan_title || event.metadata.source_type)"
                class="activity-details"
              >
                <span v-if="event.metadata.plan_title" class="activity-detail-item">
                  Plan: {{ event.metadata.plan_title }}
                </span>
                <span v-if="event.metadata.source_type" class="activity-detail-item">
                  Source: {{ event.metadata.source_type }}
                </span>
                <span v-if="event.metadata.context" class="activity-detail-item">
                  {{ event.metadata.context }}
                </span>
              </div>
            </div>
          </el-timeline-item>
        </el-timeline>
        <el-empty v-if="!activityLoading && activityEvents.length === 0" description="No activity recorded" :image-size="40" />
        <div v-if="activityTotal > activityEvents.length" class="activity-load-more">
          <el-button @click="loadMoreActivity" :loading="activityLoadingMore" size="small">
            Load More ({{ activityEvents.length }} of {{ activityTotal }})
          </el-button>
        </div>
      </div>
    </el-card>

    <!-- Grant Access Dialog -->
    <el-dialog
      v-model="grantDialogVisible"
      title="Grant Access"
      width="500px"
      @close="resetGrantForm"
    >
      <el-form :model="grantForm" label-position="top">
        <el-form-item label="Plan" required>
          <el-select
            v-model="grantForm.plan_id"
            placeholder="Select plan..."
            class="full-width"
          >
            <el-option
              v-for="plan in planOptions"
              :key="plan.id"
              :label="plan.title"
              :value="plan.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="Expiry Date">
          <el-date-picker
            v-model="grantForm.expires_at"
            type="date"
            placeholder="Leave empty for plan default"
            :format="wpDatePickerFormat"
            value-format="YYYY-MM-DD"
            class="full-width"
          />
        </el-form-item>
        <el-form-item label="Reason">
          <el-input
            v-model="grantForm.reason"
            type="textarea"
            :rows="2"
            placeholder="Optional reason for granting access"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="grantDialogVisible = false">Cancel</el-button>
        <el-button
          type="primary"
          @click="handleGrant"
          :loading="granting"
          :disabled="!grantForm.plan_id"
        >
          Grant Access
        </el-button>
      </template>
    </el-dialog>

    <!-- Extend Dialog -->
    <el-dialog
      v-model="extendDialogVisible"
      title="Extend Grant"
      width="400px"
    >
      <el-form label-position="top">
        <el-form-item label="New Expiry Date" required>
          <el-date-picker
            v-model="extendDate"
            type="date"
            placeholder="Select new expiry date"
            :format="wpDatePickerFormat"
            value-format="YYYY-MM-DD"
            class="full-width"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="extendDialogVisible = false">Cancel</el-button>
        <el-button
          type="primary"
          @click="handleExtend"
          :loading="extending"
          :disabled="!extendDate"
        >
          Extend
        </el-button>
      </template>
    </el-dialog>

    <!-- Drip Timeline Drawer -->
    <el-drawer
      v-model="dripDrawerVisible"
      :title="`Drip Timeline — ${dripDrawerPlan?.plan_title || ''}`"
      direction="rtl"
      size="520px"
    >
      <div v-loading="dripDrawerLoading">
        <template v-if="dripDrawerData.length > 0">
          <el-timeline>
            <el-timeline-item
              v-for="item in dripDrawerData"
              :key="item.rule_id || item.id"
              :type="dripDetailType(item)"
              :hollow="item.status === 'locked'"
              :timestamp="dripDetailTimestamp(item)"
            >
              <div class="drip-detail-item">
                <div class="drip-detail-header">
                  <span class="drip-detail-title">{{ item.resource_title || item.title }}</span>
                  <el-tag :type="dripDetailType(item)" size="small">
                    {{ item.status === 'unlocked' ? 'Unlocked' : item.status === 'scheduled' ? 'Upcoming' : 'Locked' }}
                  </el-tag>
                </div>
                <div class="drip-detail-meta">
                  <span v-if="item.resource_type">{{ item.resource_type }}</span>
                  <span v-if="item.days_offset"> · {{ item.days_offset }} day{{ item.days_offset !== 1 ? 's' : '' }} delay</span>
                </div>
                <div v-if="item.notification_scheduled != null" class="drip-detail-notification">
                  <el-tag size="small" :type="item.notification_scheduled ? 'success' : 'info'">
                    Notification: {{ item.notification_scheduled ? 'Scheduled' : 'Pending' }}
                  </el-tag>
                </div>
              </div>
            </el-timeline-item>
          </el-timeline>
        </template>
        <el-empty v-else-if="!dripDrawerLoading" description="No drip schedule for this plan" :image-size="60" />
      </div>
    </el-drawer>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { ArrowLeft, Calendar, CircleClose, Document, Key, List, Message, Plus, Timer } from '@element-plus/icons-vue'
import { useRoute } from 'vue-router'
import { ElMessage } from 'element-plus'
import { members as membersApi, plans } from '@/api/index.js'
import { formatWpDate, wpDatePickerFormat } from '@/utils/wpDate.js'

const route = useRoute()
const userId = computed(() => route.params.id)

// Member data
const loading = ref(false)
const member = ref(null)
const allGrants = ref([])
const timeline = ref([])

// Plan options
const planOptions = ref([])

// Revoke all
const revokingAll = ref(false)

// Grant dialog
const grantDialogVisible = ref(false)
const granting = ref(false)
const grantForm = ref({
  plan_id: '',
  expires_at: '',
  reason: '',
})

// Activity timeline
const activityLoading = ref(false)
const activityLoadingMore = ref(false)
const activityEvents = ref([])
const activityTotal = ref(0)
const activityPage = ref(1)

// Extend dialog
const extendDialogVisible = ref(false)
const extending = ref(false)
const extendDate = ref('')
const extendingGrant = ref(null)

// Drip drawer
const dripDrawerVisible = ref(false)
const dripDrawerLoading = ref(false)
const dripDrawerPlan = ref(null)
const dripDrawerData = ref([])

// Computed
const activeGrants = computed(() =>
  allGrants.value.filter((g) => g.status === 'active' || g.status === 'paused')
)

async function fetchMember() {
  loading.value = true
  try {
    const response = await membersApi.get(userId.value)
    const data = response.data ?? response
    member.value = data.user || data
    // Flatten grants from plans structure
    const planGroups = data.plans || []
    const grants = []
    planGroups.forEach((pg) => {
      (pg.grants || []).forEach((g) => {
        grants.push({ ...g, plan_title: pg.plan_title || '' })
      })
    })
    allGrants.value = (data.history || []).map((grant) => ({
      ...grant,
      plan_title: grant.plan_title || grants.find((item) => item.id === grant.id)?.plan_title || '',
    }))
    timeline.value = planGroups.filter((pg) => pg.progress).map((pg) => ({
      plan_id: pg.plan_id,
      plan_title: pg.plan_title,
      items: pg.progress?.items || [],
    }))
  } catch (err) {
    ElMessage.error(err.message || 'Failed to load member data')
  } finally {
    loading.value = false
  }
}

async function fetchPlanOptions() {
  try {
    const response = await plans.options()
    const opts = response.data || response || []
    planOptions.value = opts.map((o) => ({ id: o.id ?? o.value, title: o.label ?? o.title }))
  } catch {
    // Silently fail
  }
}

async function handleRevoke(grant) {
  try {
    await membersApi.revoke({ user_id: parseInt(userId.value), plan_id: grant.plan_id })
    ElMessage.success('Grant revoked')
    fetchMember()
  } catch (err) {
    ElMessage.error(err.message || 'Failed to revoke grant')
  }
}

async function handleRevokeAll() {
  revokingAll.value = true
  try {
    const planIds = [...new Set(activeGrants.value.map(g => g.plan_id).filter(Boolean))]
    const revokePromises = planIds.map((planId) =>
      membersApi.revoke({ user_id: parseInt(userId.value), plan_id: planId })
    )
    await Promise.all(revokePromises)
    ElMessage.success('All active grants revoked')
    fetchMember()
  } catch (err) {
    ElMessage.error(err.message || 'Failed to revoke grants')
  } finally {
    revokingAll.value = false
  }
}

async function handleGrant() {
  granting.value = true
  try {
    const payload = {
      user_id: parseInt(userId.value),
      plan_id: grantForm.value.plan_id,
    }
    if (grantForm.value.expires_at) payload.expires_at = grantForm.value.expires_at
    if (grantForm.value.reason) payload.reason = grantForm.value.reason

    await membersApi.grant(payload)
    ElMessage.success('Access granted successfully')
    grantDialogVisible.value = false
    resetGrantForm()
    fetchMember()
  } catch (err) {
    ElMessage.error(err.message || 'Failed to grant access')
  } finally {
    granting.value = false
  }
}

function openExtendDialog(grant) {
  extendingGrant.value = grant
  extendDate.value = grant.expires_at || ''
  extendDialogVisible.value = true
}

async function handleExtend() {
  if (!extendingGrant.value) return
  extending.value = true
  try {
    await membersApi.extend({
      user_id: parseInt(userId.value),
      plan_id: extendingGrant.value.plan_id,
      expires_at: extendDate.value,
    })
    ElMessage.success('Grant extended successfully')
    extendDialogVisible.value = false
    extendingGrant.value = null
    extendDate.value = ''
    fetchMember()
  } catch (err) {
    ElMessage.error(err.message || 'Failed to extend grant')
  } finally {
    extending.value = false
  }
}

async function openDripDrawer(grant) {
  dripDrawerPlan.value = grant
  dripDrawerVisible.value = true
  dripDrawerLoading.value = true
  dripDrawerData.value = []
  try {
    const response = await membersApi.dripTimeline(userId.value, { plan_id: grant.plan_id })
    const data = response.data ?? response
    dripDrawerData.value = Array.isArray(data) ? data : (data.items ?? data.timeline ?? [])
  } catch {
    dripDrawerData.value = []
  } finally {
    dripDrawerLoading.value = false
  }
}

function dripDetailType(item) {
  if (item.status === 'unlocked') return 'success'
  if (item.status === 'scheduled') return 'warning'
  return 'info'
}

function dripDetailTimestamp(item) {
  if (item.status === 'unlocked' && (item.unlocked_at || item.unlock_date)) return `Unlocked ${formatDate(item.unlocked_at || item.unlock_date)}`
  if (item.unlock_date) return `Unlocks ${formatDate(item.unlock_date)}`
  return 'Locked'
}

function resetGrantForm() {
  grantForm.value = {
    plan_id: '',
    expires_at: '',
    reason: '',
  }
}

function statusTagType(status) {
  const map = {
    active: 'success',
    paused: 'warning',
    expired: 'warning',
    revoked: 'danger',
  }
  return map[status] || 'info'
}

function dripItemType(status) {
  const map = {
    unlocked: 'success',
    upcoming: 'warning',
    locked: 'info',
  }
  return map[status] || 'info'
}

async function fetchActivity() {
  activityLoading.value = true
  activityPage.value = 1
  try {
    const response = await membersApi.activity(userId.value, { page: 1, per_page: 50 })
    const data = response.data ?? response
    activityEvents.value = Array.isArray(data) ? data : (data.data ?? data ?? [])
    activityTotal.value = response.total ?? data.total ?? activityEvents.value.length
  } catch {
    activityEvents.value = []
    activityTotal.value = 0
  } finally {
    activityLoading.value = false
  }
}

async function loadMoreActivity() {
  activityLoadingMore.value = true
  activityPage.value++
  try {
    const response = await membersApi.activity(userId.value, { page: activityPage.value, per_page: 50 })
    const data = response.data ?? response
    const newEvents = Array.isArray(data) ? data : (data.data ?? data ?? [])
    activityEvents.value = [...activityEvents.value, ...newEvents]
  } catch {
    // keep existing
  } finally {
    activityLoadingMore.value = false
  }
}

function activityEventColor(type) {
  const map = {
    grant_created: 'success',
    grant_renewed: 'success',
    grant_revoked: 'danger',
    grant_paused: 'warning',
    grant_expired: 'warning',
    trial_started: 'info',
    drip_sent: 'success',
    drip_scheduled: 'info',
    drip_failed: 'danger',
    audit_created: 'success',
    audit_renewed: 'success',
    audit_revoked: 'danger',
    audit_paused: 'warning',
    audit_resumed: 'success',
    audit_updated: 'info',
  }
  return map[type] || 'info'
}

function activityEventLabel(type) {
  const map = {
    grant_created: 'Granted',
    grant_renewed: 'Renewed',
    grant_revoked: 'Revoked',
    grant_paused: 'Paused',
    grant_expired: 'Expired',
    trial_started: 'Trial',
    drip_sent: 'Drip Sent',
    drip_scheduled: 'Drip Scheduled',
    drip_failed: 'Drip Failed',
    audit_created: 'Created',
    audit_renewed: 'Renewed',
    audit_revoked: 'Revoked',
    audit_paused: 'Paused',
    audit_resumed: 'Resumed',
    audit_updated: 'Updated',
  }
  return map[type] || type.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
}

async function handlePause(grant) {
  try {
    await membersApi.pause({ grant_id: grant.id })
    ElMessage.success('Membership paused')
    fetchMember()
  } catch (err) {
    ElMessage.error(err.message || 'Failed to pause')
  }
}

async function handleResume(grant) {
  try {
    await membersApi.resume({ grant_id: grant.id })
    ElMessage.success('Membership resumed')
    fetchMember()
  } catch (err) {
    ElMessage.error(err.message || 'Failed to resume')
  }
}

function formatDate(dateStr) {
  return formatWpDate(dateStr)
}

onMounted(() => {
  fetchMember()
  fetchPlanOptions()
  fetchActivity()
})
</script>

<style scoped>
.back-link {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 13px;
  color: var(--fchub-text-secondary);
  text-decoration: none;
  margin-bottom: 16px;
  cursor: pointer;
}

.back-link:hover {
  color: var(--el-color-primary);
}

.member-header {
  margin-bottom: 20px;
}

.header-content {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
}

.member-info h2 {
  margin: 0 0 8px 0;
  font-size: 20px;
  font-weight: 700;
  color: var(--fchub-text-primary);
}

.member-meta {
  display: flex;
  gap: 20px;
  color: var(--fchub-text-secondary);
  font-size: 14px;
}

.member-meta span {
  display: flex;
  align-items: center;
  gap: 4px;
}

.header-actions {
  display: flex;
  gap: 10px;
  flex-shrink: 0;
}

.section-card {
  margin-bottom: 20px;
}

.section-title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 16px;
  font-weight: 600;
  color: var(--fchub-text-primary);
}

.action-buttons {
  display: flex;
  gap: 6px;
}

/* Drip Timeline */
.drip-plan-group {
  margin-bottom: 24px;
}

.drip-plan-group:last-child {
  margin-bottom: 0;
}

.drip-plan-title {
  margin: 0 0 12px 0;
  font-size: 15px;
  font-weight: 600;
  color: var(--fchub-text-primary);
}

.drip-item {
  display: flex;
  align-items: center;
  gap: 10px;
}

.drip-item-title {
  font-size: 14px;
  color: var(--fchub-text-primary);
}

.drip-status-tag {
  flex-shrink: 0;
}

.full-width {
  width: 100%;
}

.activity-event {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.activity-event-header {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
}

.activity-description {
  color: var(--fchub-text-primary);
}

.activity-details {
  display: flex;
  gap: 12px;
  font-size: 12px;
  color: var(--fchub-text-secondary);
  margin-top: 2px;
}

.activity-detail-item {
  display: inline-block;
}

.activity-load-more {
  text-align: center;
  padding: 16px 0;
}

.cancellation-info {
  font-size: 12px;
  color: var(--el-color-danger);
}

.effective-date {
  color: var(--fchub-text-secondary);
}

.clickable-plan-tag {
  cursor: pointer;
  transition: opacity 0.2s;
}

.clickable-plan-tag:hover {
  opacity: 0.8;
}

.drip-detail-item {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.drip-detail-header {
  display: flex;
  align-items: center;
  gap: 8px;
}

.drip-detail-title {
  font-size: 14px;
  font-weight: 500;
  color: var(--fchub-text-primary);
}

.drip-detail-meta {
  font-size: 12px;
  color: var(--fchub-text-secondary);
}

.drip-detail-notification {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 2px;
}
</style>
