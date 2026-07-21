<template>
  <div class="member-profile-page" v-loading="loading">
    <a class="profile-back-link" @click.prevent="$router.push('/members')">
      <el-icon><ArrowLeft /></el-icon>
      Back to members
    </a>

    <template v-if="member">
      <section class="profile-hero" aria-labelledby="member-profile-title">
        <div class="profile-identity-row">
          <span class="profile-avatar" aria-hidden="true">{{ memberInitials }}</span>
          <div class="profile-identity">
            <span class="profile-eyebrow">MEMBER WORKSPACE</span>
            <h1 id="member-profile-title">{{ member.display_name }}</h1>
            <div class="profile-meta">
              <span><el-icon><Message /></el-icon>{{ member.email || member.user_email }}</span>
              <span><el-icon><Calendar /></el-icon>Registered {{ formatDate(member.registered_at) }}</span>
            </div>
          </div>
          <div class="profile-actions">
            <el-button type="primary" class="profile-primary-action" @click="grantDialogVisible = true">
              <el-icon><Plus /></el-icon>
              Grant access
            </el-button>
            <el-popconfirm
              title="Revoke every current grant for this member?"
              confirm-button-text="Revoke all"
              confirm-button-type="danger"
              :disabled="!accessState.canRevokeAll"
              @confirm="handleRevokeAll"
            >
              <template #reference>
                <el-button
                  type="danger"
                  plain
                  :loading="revokingAll"
                  :disabled="!accessState.canRevokeAll"
                >
                  <el-icon><CircleClose /></el-icon>
                  Revoke all
                </el-button>
              </template>
            </el-popconfirm>
          </div>
        </div>

        <div class="profile-summary" role="region" aria-label="Membership summary">
          <article class="profile-stat">
            <span class="profile-stat-icon is-primary"><el-icon><Key /></el-icon></span>
            <span><strong>{{ profileSummary.activeCount }}</strong><small>Active access</small></span>
          </article>
          <article class="profile-stat">
            <span class="profile-stat-icon is-neutral"><el-icon><Document /></el-icon></span>
            <span><strong>{{ profileSummary.historyCount }}</strong><small>Grant history</small></span>
          </article>
          <article class="profile-stat">
            <span class="profile-stat-icon is-success"><el-icon><List /></el-icon></span>
            <span><strong>{{ profileSummary.activityCount }}</strong><small>Activity</small></span>
          </article>
        </div>
      </section>

      <div class="profile-workspace">
        <main class="profile-main-column">
          <section class="profile-panel access-panel" role="region" aria-label="Current access">
            <header class="profile-panel-header">
              <div>
                <span class="profile-section-eyebrow">ACCESS</span>
                <h2>Current access</h2>
                <p>{{ accessState.description }}</p>
              </div>
              <el-tag :type="accessState.hasAccess ? 'success' : 'info'" effect="light">
                {{ accessState.title }}
              </el-tag>
            </header>

            <div v-if="activeGrants.length" class="access-grant-list">
              <article v-for="grant in activeGrants" :key="grant.id" class="access-grant-card">
                <div class="grant-card-heading">
                  <button class="grant-plan-button" type="button" @click="openDripDrawer(grant)">
                    {{ grant.plan_title }}
                  </button>
                  <div class="grant-card-status">
                    <el-tag :type="statusTagType(grant.status)" size="small">{{ normaliseSourceLabel(grant.status) }}</el-tag>
                    <el-tag
                      v-if="grant.trial_ends_at && new Date(grant.trial_ends_at) > new Date()"
                      type="info"
                      size="small"
                    >
                      Trial
                    </el-tag>
                  </div>
                </div>

                <dl class="grant-facts">
                  <div><dt>Granted</dt><dd>{{ formatDate(grant.created_at) }}</dd></div>
                  <div><dt>Access ends</dt><dd>{{ grant.expires_at ? formatDate(grant.expires_at) : 'Lifetime' }}</dd></div>
                  <div><dt>Source</dt><dd>{{ normaliseSourceLabel(grant.source_type) }}</dd></div>
                </dl>

                <div class="grant-card-actions">
                  <el-button v-if="grant.status === 'active'" size="small" plain @click="handlePause(grant)">Pause</el-button>
                  <el-button v-if="grant.status === 'paused'" size="small" type="success" plain @click="handleResume(grant)">Resume</el-button>
                  <el-button size="small" @click="openExtendDialog(grant)">Extend</el-button>
                  <el-popconfirm
                    title="Revoke this grant?"
                    confirm-button-text="Revoke"
                    confirm-button-type="danger"
                    @confirm="handleRevoke(grant)"
                  >
                    <template #reference>
                      <el-button size="small" type="danger" plain>Revoke</el-button>
                    </template>
                  </el-popconfirm>
                </div>
              </article>
            </div>

            <div v-else class="access-empty-state">
              <span class="access-empty-icon" aria-hidden="true"><el-icon><Key /></el-icon></span>
              <div>
                <h3>No active access</h3>
                <p>This member cannot open plan-protected content yet.</p>
              </div>
              <el-button type="primary" plain @click="grantDialogVisible = true">Grant access</el-button>
            </div>
          </section>

          <section v-if="timeline.length" class="profile-panel drip-panel" aria-labelledby="drip-heading">
            <header class="profile-panel-header">
              <div>
                <span class="profile-section-eyebrow">DELIVERY</span>
                <h2 id="drip-heading">Drip schedule</h2>
                <p>Upcoming and unlocked content for current plans.</p>
              </div>
            </header>
            <div v-for="planTimeline in timeline" :key="planTimeline.plan_id" class="drip-plan-group">
              <h3 class="drip-plan-title">{{ planTimeline.plan_title }}</h3>
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
                    <el-tag :type="dripItemType(item.status)" size="small">{{ item.status }}</el-tag>
                  </div>
                </el-timeline-item>
              </el-timeline>
            </div>
          </section>

          <section class="profile-panel history-panel" aria-labelledby="history-heading">
            <header class="profile-panel-header">
              <div>
                <span class="profile-section-eyebrow">AUDIT TRAIL</span>
                <h2 id="history-heading">Grant history</h2>
                <p>Every access record, including expired and revoked grants.</p>
              </div>
              <span class="profile-panel-count">{{ allGrants.length }} total</span>
            </header>

            <div v-if="allGrants.length" class="grant-history-list">
              <article v-for="grant in allGrants" :key="`history-${grant.id}-${grant.status}`" class="history-row">
                <div class="history-plan">
                  <strong>{{ grant.plan_title }}</strong>
                  <el-tag :type="statusTagType(grant.status)" size="small">{{ normaliseSourceLabel(grant.status) }}</el-tag>
                </div>
                <dl class="history-facts">
                  <div><dt>Granted</dt><dd>{{ formatDate(grant.created_at) }}</dd></div>
                  <div><dt>Access ended</dt><dd>{{ grant.revoked_at ? formatDate(grant.revoked_at) : (grant.expires_at ? formatDate(grant.expires_at) : 'Lifetime') }}</dd></div>
                  <div><dt>Source</dt><dd>{{ normaliseSourceLabel(grant.source_type) }}</dd></div>
                </dl>
              </article>
            </div>

            <div v-else class="compact-empty-state">
              <el-icon><Document /></el-icon>
              <span>No grant history yet.</span>
            </div>
          </section>
        </main>

        <aside class="profile-side-column">
          <section class="profile-panel activity-panel" role="region" aria-label="Activity timeline">
            <header class="profile-panel-header">
              <div>
                <span class="profile-section-eyebrow">RECENT EVENTS</span>
                <h2>Activity</h2>
                <p>Membership changes and automated events.</p>
              </div>
            </header>

            <div v-loading="activityLoading">
              <ol v-if="activityEvents.length" class="activity-feed">
                <li v-for="(event, idx) in activityEvents" :key="`${event.type}-${event.date}-${idx}`" class="activity-feed-item">
                  <span class="activity-dot" :class="`is-${activityEventColor(event.type)}`" aria-hidden="true"></span>
                  <div class="activity-event">
                    <div class="activity-event-topline">
                      <el-tag :type="activityEventColor(event.type)" size="small">{{ activityEventLabel(event.type) }}</el-tag>
                      <time>{{ formatDate(event.date) }}</time>
                    </div>
                    <p class="activity-description">{{ event.description }}</p>
                    <div
                      v-if="event.metadata && (event.metadata.context || event.metadata.plan_title || event.metadata.source_type)"
                      class="activity-details"
                    >
                      <span v-if="event.metadata.plan_title">{{ event.metadata.plan_title }}</span>
                      <span v-if="event.metadata.source_type">{{ normaliseSourceLabel(event.metadata.source_type) }}</span>
                      <span v-if="event.metadata.context">{{ event.metadata.context }}</span>
                    </div>
                  </div>
                </li>
              </ol>

              <div v-else-if="!activityLoading" class="compact-empty-state">
                <el-icon><List /></el-icon>
                <span>No activity recorded.</span>
              </div>

              <div v-if="activityTotal > activityEvents.length" class="activity-load-more">
                <el-button @click="loadMoreActivity" :loading="activityLoadingMore" size="small">
                  Load more ({{ activityEvents.length }} of {{ activityTotal }})
                </el-button>
              </div>
            </div>
          </section>
        </aside>
      </div>
    </template>

    <GrantAccessDialog
      :visible="grantDialogVisible"
      :form="grantForm"
      :loading="granting"
      :fixed-user="member"
      :selected-user="member"
      :plan-options="planOptions"
      :date-picker-format="wpDatePickerFormat"
      @close="grantDialogVisible = false; resetGrantForm()"
      @confirm="handleGrant"
      @update:plan-id="grantForm.plan_id = $event"
      @update:expires-at="grantForm.expires_at = $event"
      @update:reason="grantForm.reason = $event"
    />

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
import { ArrowLeft, Calendar, CircleClose, Document, Key, List, Message, Plus } from '@element-plus/icons-vue'
import { useRoute } from 'vue-router'
import { ElMessage } from 'element-plus'
import { members as membersApi, plans } from '@/api/index.js'
import { formatWpDate, wpDatePickerFormat } from '@/utils/wpDate.js'
import GrantAccessDialog from '@/components/members/GrantAccessDialog.vue'
import {
  buildMemberProfileSummary,
  getMemberAccessState,
  getMemberInitials,
  normaliseSourceLabel,
} from './memberProfileUi.js'

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
  user_id: parseInt(userId.value),
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
const memberInitials = computed(() => getMemberInitials(member.value || {}))
const profileSummary = computed(() => buildMemberProfileSummary(allGrants.value, activityTotal.value))
const accessState = computed(() => getMemberAccessState(activeGrants.value))

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
    user_id: parseInt(userId.value),
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
.member-profile-page {
  width: 100%;
  max-width: 1240px;
  margin: 0 auto;
  box-sizing: border-box;
}

.profile-back-link {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  margin-bottom: 14px;
  color: var(--fchub-text-secondary);
  font-size: 12px;
  font-weight: 550;
  text-decoration: none;
  cursor: pointer;
}

.profile-back-link:hover,
.profile-back-link:focus-visible {
  color: var(--el-color-primary);
}

.profile-hero,
.profile-panel {
  border: 1px solid var(--fchub-border-color);
  background: var(--fchub-card-bg);
  box-shadow: 0 1px 2px rgba(16, 24, 40, 0.03);
}

.profile-hero {
  overflow: hidden;
  margin-bottom: 16px;
  border-radius: 12px;
  background:
    linear-gradient(135deg, color-mix(in srgb, var(--fchub-card-bg) 96%, var(--el-color-primary) 4%), var(--fchub-card-bg));
}

.profile-identity-row {
  display: grid;
  grid-template-columns: 56px minmax(0, 1fr) auto;
  align-items: center;
  gap: 16px;
  padding: 22px 24px;
}

.profile-avatar {
  display: grid;
  width: 56px;
  height: 56px;
  place-items: center;
  border-radius: 15px;
  color: #fff;
  background: var(--el-color-primary);
  box-shadow: 0 6px 18px color-mix(in srgb, var(--el-color-primary) 25%, transparent);
  font-size: 16px;
  font-weight: 780;
  letter-spacing: 0.04em;
}

.profile-identity {
  min-width: 0;
}

.profile-eyebrow,
.profile-section-eyebrow {
  color: var(--el-color-primary);
  font-size: 10px;
  font-weight: 800;
  letter-spacing: 0.09em;
}

.profile-identity h1 {
  margin: 3px 0 7px;
  color: var(--fchub-text-primary);
  font-size: 23px;
  font-weight: 730;
  line-height: 1.2;
}

.profile-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 18px;
  color: var(--fchub-text-secondary);
  font-size: 12px;
}

.profile-meta span {
  min-width: 0;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  overflow-wrap: anywhere;
}

.profile-actions {
  display: flex;
  align-items: center;
  gap: 8px;
}

.profile-summary {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  border-top: 1px solid var(--fchub-border-color);
  background: color-mix(in srgb, var(--fchub-card-bg) 97%, var(--el-color-primary) 3%);
}

.profile-stat {
  min-width: 0;
  display: flex;
  align-items: center;
  gap: 11px;
  padding: 14px 24px;
}

.profile-stat + .profile-stat {
  border-left: 1px solid var(--fchub-border-color);
}

.profile-stat-icon {
  display: grid;
  width: 34px;
  height: 34px;
  flex: 0 0 auto;
  place-items: center;
  border-radius: 9px;
}

.profile-stat-icon.is-primary {
  color: var(--el-color-primary);
  background: color-mix(in srgb, var(--el-color-primary) 10%, var(--fchub-card-bg));
}

.profile-stat-icon.is-neutral {
  color: var(--fchub-text-secondary);
  background: color-mix(in srgb, var(--fchub-text-secondary) 9%, var(--fchub-card-bg));
}

.profile-stat-icon.is-success {
  color: var(--el-color-success);
  background: color-mix(in srgb, var(--el-color-success) 10%, var(--fchub-card-bg));
}

.profile-stat > span:last-child {
  min-width: 0;
  display: grid;
  gap: 1px;
}

.profile-stat strong {
  color: var(--fchub-text-primary);
  font-size: 18px;
  line-height: 1.1;
}

.profile-stat small {
  color: var(--fchub-text-secondary);
  font-size: 11px;
}

.profile-workspace {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(300px, 350px);
  align-items: start;
  gap: 16px;
}

.profile-main-column,
.profile-side-column {
  min-width: 0;
  display: grid;
  gap: 16px;
}

.profile-panel {
  min-width: 0;
  padding: 20px;
  border-radius: 12px;
  box-sizing: border-box;
}

.profile-panel-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 17px;
}

.profile-panel-header > div {
  min-width: 0;
}

.profile-panel-header h2 {
  margin: 3px 0 4px;
  color: var(--fchub-text-primary);
  font-size: 17px;
  font-weight: 700;
  line-height: 1.25;
}

.profile-panel-header p {
  margin: 0;
  color: var(--fchub-text-secondary);
  font-size: 12px;
  line-height: 1.5;
}

.profile-panel-count {
  flex: 0 0 auto;
  color: var(--fchub-text-secondary);
  font-size: 11px;
  font-weight: 650;
}

.access-grant-list {
  display: grid;
  gap: 10px;
}

.access-grant-card {
  padding: 16px;
  border: 1px solid var(--fchub-border-color);
  border-radius: 10px;
  background: color-mix(in srgb, var(--fchub-card-bg) 98%, var(--el-color-primary) 2%);
}

.grant-card-heading,
.grant-card-status,
.grant-card-actions {
  display: flex;
  align-items: center;
}

.grant-card-heading {
  justify-content: space-between;
  gap: 12px;
}

.grant-card-status,
.grant-card-actions {
  flex-wrap: wrap;
  gap: 6px;
}

.grant-plan-button {
  min-width: 0;
  margin: 0;
  padding: 0;
  border: 0;
  color: var(--fchub-text-primary);
  background: transparent;
  font: inherit;
  font-size: 15px;
  font-weight: 700;
  text-align: left;
  cursor: pointer;
}

.grant-plan-button:hover,
.grant-plan-button:focus-visible {
  color: var(--el-color-primary);
}

.grant-facts,
.history-facts {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
  margin: 14px 0;
}

.grant-facts div,
.history-facts div {
  min-width: 0;
}

.grant-facts dt,
.history-facts dt,
.grant-facts dd,
.history-facts dd {
  margin: 0;
}

.grant-facts dt,
.history-facts dt {
  margin-bottom: 3px;
  color: var(--fchub-text-secondary);
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.grant-facts dd,
.history-facts dd {
  color: var(--fchub-text-primary);
  font-size: 12px;
  line-height: 1.4;
  overflow-wrap: anywhere;
}

.grant-card-actions {
  justify-content: flex-end;
  padding-top: 12px;
  border-top: 1px solid var(--fchub-border-color);
}

.grant-card-actions :deep(.el-button + .el-button) {
  margin-left: 0;
}

.access-empty-state {
  display: grid;
  grid-template-columns: 44px minmax(0, 1fr) auto;
  align-items: center;
  gap: 14px;
  padding: 18px;
  border: 1px dashed color-mix(in srgb, var(--el-color-primary) 25%, var(--fchub-border-color));
  border-radius: 10px;
  background: color-mix(in srgb, var(--fchub-card-bg) 96%, var(--el-color-primary) 4%);
}

.access-empty-icon {
  display: grid;
  width: 44px;
  height: 44px;
  place-items: center;
  border-radius: 12px;
  color: var(--el-color-primary);
  background: color-mix(in srgb, var(--el-color-primary) 10%, var(--fchub-card-bg));
  font-size: 20px;
}

.access-empty-state h3 {
  margin: 0 0 3px;
  color: var(--fchub-text-primary);
  font-size: 14px;
}

.access-empty-state p {
  margin: 0;
  color: var(--fchub-text-secondary);
  font-size: 12px;
  line-height: 1.45;
}

.grant-history-list {
  overflow: hidden;
  border: 1px solid var(--fchub-border-color);
  border-radius: 10px;
}

.history-row {
  display: grid;
  grid-template-columns: minmax(150px, 0.8fr) minmax(0, 1.2fr);
  align-items: center;
  gap: 18px;
  padding: 14px 16px;
}

.history-row + .history-row {
  border-top: 1px solid var(--fchub-border-color);
}

.history-plan {
  min-width: 0;
  display: flex;
  align-items: center;
  gap: 8px;
}

.history-plan strong {
  min-width: 0;
  color: var(--fchub-text-primary);
  font-size: 13px;
  overflow-wrap: anywhere;
}

.history-facts {
  margin: 0;
}

.activity-feed {
  margin: 0;
  padding: 0;
  list-style: none;
}

.activity-feed-item {
  position: relative;
  display: grid;
  grid-template-columns: 12px minmax(0, 1fr);
  gap: 10px;
  padding-bottom: 18px;
}

.activity-feed-item:not(:last-child)::before {
  content: '';
  position: absolute;
  top: 13px;
  bottom: 0;
  left: 5px;
  width: 1px;
  background: var(--fchub-border-color);
}

.activity-dot {
  position: relative;
  z-index: 1;
  width: 10px;
  height: 10px;
  margin-top: 4px;
  border: 2px solid var(--fchub-card-bg);
  border-radius: 50%;
  background: var(--el-color-info);
  box-shadow: 0 0 0 1px var(--fchub-border-color);
}

.activity-dot.is-success { background: var(--el-color-success); }
.activity-dot.is-warning { background: var(--el-color-warning); }
.activity-dot.is-danger { background: var(--el-color-danger); }

.activity-event {
  min-width: 0;
}

.activity-event-topline {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.activity-event-topline time {
  flex: 0 0 auto;
  color: var(--fchub-text-secondary);
  font-size: 10px;
}

.activity-description {
  margin: 7px 0 0;
  color: var(--fchub-text-primary);
  font-size: 12px;
  font-weight: 550;
  line-height: 1.45;
  overflow-wrap: anywhere;
}

.activity-details {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 9px;
  margin-top: 5px;
  color: var(--fchub-text-secondary);
  font-size: 10px;
  line-height: 1.4;
}

.compact-empty-state {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  min-height: 72px;
  padding: 12px;
  border: 1px dashed var(--fchub-border-color);
  border-radius: 9px;
  color: var(--fchub-text-secondary);
  font-size: 12px;
  box-sizing: border-box;
}

.activity-load-more {
  padding-top: 12px;
  text-align: center;
}

.drip-plan-group {
  margin-bottom: 22px;
}

.drip-plan-group:last-child {
  margin-bottom: 0;
}

.drip-plan-title {
  margin: 0 0 12px;
  color: var(--fchub-text-primary);
  font-size: 14px;
  font-weight: 700;
}

.drip-item,
.drip-detail-header,
.drip-detail-notification {
  display: flex;
  align-items: center;
  gap: 8px;
}

.drip-item {
  justify-content: space-between;
}

.drip-item-title,
.drip-detail-title {
  color: var(--fchub-text-primary);
  font-size: 13px;
}

.drip-detail-item {
  display: grid;
  gap: 6px;
}

.drip-detail-title {
  font-weight: 600;
}

.drip-detail-meta {
  color: var(--fchub-text-secondary);
  font-size: 11px;
}

.full-width {
  width: 100%;
}

@media (max-width: 1100px) {
  .profile-workspace {
    grid-template-columns: 1fr;
  }

  .profile-side-column {
    grid-row: auto;
  }
}

@media (max-width: 782px) {
  .member-profile-page {
    max-width: none;
  }

  .profile-identity-row {
    grid-template-columns: 48px minmax(0, 1fr);
    align-items: start;
    gap: 12px;
    padding: 18px 16px;
  }

  .profile-avatar {
    width: 48px;
    height: 48px;
    border-radius: 13px;
    font-size: 14px;
  }

  .profile-identity h1 {
    font-size: 20px;
  }

  .profile-meta {
    display: grid;
    gap: 5px;
  }

  .profile-actions {
    grid-column: 1 / -1;
    width: 100%;
  }

  .profile-actions :deep(.el-button),
  .profile-actions :deep(.el-popconfirm) {
    min-width: 0;
    flex: 1 1 0;
  }

  .profile-actions :deep(.el-button + .el-button) {
    margin-left: 0;
  }

  .profile-stat {
    justify-content: center;
    padding: 12px 8px;
  }

  .profile-stat-icon {
    display: none;
  }

  .profile-stat > span:last-child {
    justify-items: center;
    text-align: center;
  }

  .profile-panel {
    padding: 18px 16px;
  }

  .profile-panel-header {
    gap: 10px;
  }

  .access-empty-state {
    grid-template-columns: 40px minmax(0, 1fr);
    padding: 16px;
  }

  .access-empty-icon {
    width: 40px;
    height: 40px;
  }

  .access-empty-state .el-button {
    grid-column: 1 / -1;
    width: 100%;
    margin-left: 0;
  }

  .history-row {
    grid-template-columns: 1fr;
    gap: 12px;
  }
}

@media (max-width: 480px) {
  .profile-actions {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .profile-actions :deep(.el-button) {
    width: 100%;
    padding-inline: 10px;
  }

  .profile-panel-header {
    flex-direction: column;
  }

  .profile-panel-header > .el-tag,
  .profile-panel-count {
    align-self: flex-start;
  }

  .grant-card-heading {
    align-items: flex-start;
  }

  .grant-facts,
  .history-facts {
    gap: 8px;
  }

  .grant-card-actions {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }

  .grant-card-actions :deep(.el-button) {
    width: 100%;
    margin-left: 0;
    padding-inline: 7px;
  }
}
</style>
