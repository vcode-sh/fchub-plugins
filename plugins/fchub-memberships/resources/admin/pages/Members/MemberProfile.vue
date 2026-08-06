<template>
  <div class="member-profile-page" v-loading="loading">
    <WorkspaceBackButton class="profile-back-button" to="/members" label="Back to members" />

    <template v-if="member">
      <MemberProfileHero
        :member="member"
        :initials="memberInitials"
        :summary="profileSummary"
        :access-state="accessState"
        :revoking-all="revokingAll"
        :format-date="formatDate"
        @grant="grantDialogVisible = true"
        @revoke-all="handleRevokeAll"
      />

      <div class="profile-workspace">
        <main class="profile-main-column">
          <MemberProfileAccessPanel
            :active-grants="activeGrants"
            :access-state="accessState"
            :format-date="formatDate"
            :status-tag-type="statusTagType"
            :normalise-source-label="normaliseSourceLabel"
            @grant="grantDialogVisible = true"
            @pause="handlePause"
            @resume="handleResume"
            @extend="openExtendDialog"
            @revoke="handleRevoke"
            @open-drip="openDripDrawer"
          />
          <MemberProfileDripSchedule :timeline="timeline" :format-date="formatDate" :drip-item-type="dripItemType" />
          <MemberProfileGrantHistory
            :grants="allGrants"
            :format-date="formatDate"
            :status-tag-type="statusTagType"
            :normalise-source-label="normaliseSourceLabel"
          />
        </main>

        <aside class="profile-side-column">
          <MemberProfileActivityPanel
            :events="activityEvents"
            :total="activityTotal"
            :loading="activityLoading"
            :loading-more="activityLoadingMore"
            :format-date="formatDate"
            :event-color="activityEventColor"
            :event-label="activityEventLabel"
            :normalise-source-label="normaliseSourceLabel"
            @load-more="loadMoreActivity"
          />
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

    <MemberProfileExtendDialog
      :visible="extendDialogVisible"
      :date="extendDate"
      :loading="extending"
      :date-picker-format="wpDatePickerFormat"
      @update:visible="extendDialogVisible = $event"
      @update:date="extendDate = $event"
      @confirm="handleExtend"
    />

    <MemberProfileDripTimelineDrawer
      :visible="dripDrawerVisible"
      :plan="dripDrawerPlan"
      :loading="dripDrawerLoading"
      :items="dripDrawerData"
      :detail-type="dripDetailType"
      :detail-timestamp="dripDetailTimestamp"
      @update:visible="dripDrawerVisible = $event"
    />
  </div>
</template>

<script setup>
import { computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { formatWpDate, wpDatePickerFormat } from '@/utils/wpDate.js'
import GrantAccessDialog from '@/components/members/GrantAccessDialog.vue'
import MemberProfileAccessPanel from '@/components/members/profile/MemberProfileAccessPanel.vue'
import MemberProfileActivityPanel from '@/components/members/profile/MemberProfileActivityPanel.vue'
import MemberProfileDripSchedule from '@/components/members/profile/MemberProfileDripSchedule.vue'
import MemberProfileDripTimelineDrawer from '@/components/members/profile/MemberProfileDripTimelineDrawer.vue'
import MemberProfileExtendDialog from '@/components/members/profile/MemberProfileExtendDialog.vue'
import MemberProfileGrantHistory from '@/components/members/profile/MemberProfileGrantHistory.vue'
import MemberProfileHero from '@/components/members/profile/MemberProfileHero.vue'
import WorkspaceBackButton from '@/components/workspace/WorkspaceBackButton.vue'
import { useMemberActivity } from '@/composables/members/useMemberActivity.js'
import { useMemberProfile } from '@/composables/members/useMemberProfile.js'
import { buildMemberProfileSummary, normaliseSourceLabel } from './memberProfileUi.js'

const route = useRoute()
const userId = computed(() => route.params.id)
const profile = useMemberProfile(userId)
const activity = useMemberActivity(userId)
const profileSummary = computed(() => buildMemberProfileSummary(profile.allGrants.value, activity.total.value))

const {
  loading, member, allGrants, timeline, planOptions, revokingAll, grantDialogVisible, granting, grantForm,
  extendDialogVisible, extending, extendDate, dripDrawerVisible, dripDrawerLoading, dripDrawerPlan, dripDrawerData,
  activeGrants, memberInitials, accessState, fetchMember, fetchPlanOptions, handleRevoke, handleRevokeAll,
  resetGrantForm, handleGrant, openExtendDialog, handleExtend, openDripDrawer, handlePause, handleResume,
} = profile
const {
  loading: activityLoading, loadingMore: activityLoadingMore, events: activityEvents, total: activityTotal,
  fetchActivity, loadMoreActivity,
} = activity

function statusTagType(status) {
  return { active: 'success', paused: 'warning', expired: 'warning', revoked: 'danger' }[status] || 'info'
}

function dripItemType(status) {
  return { unlocked: 'success', upcoming: 'warning', locked: 'info' }[status] || 'info'
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

function activityEventColor(type) {
  return {
    grant_created: 'success', grant_renewed: 'success', grant_revoked: 'danger', grant_paused: 'warning',
    grant_expired: 'warning', trial_started: 'info', drip_sent: 'success', drip_scheduled: 'info',
    drip_failed: 'danger', audit_created: 'success', audit_renewed: 'success', audit_revoked: 'danger',
    audit_paused: 'warning', audit_resumed: 'success', audit_updated: 'info',
  }[type] || 'info'
}

function activityEventLabel(type) {
  return {
    grant_created: 'Granted', grant_renewed: 'Renewed', grant_revoked: 'Revoked', grant_paused: 'Paused',
    grant_expired: 'Expired', trial_started: 'Trial', drip_sent: 'Drip Sent', drip_scheduled: 'Drip Scheduled',
    drip_failed: 'Drip Failed', audit_created: 'Created', audit_renewed: 'Renewed', audit_revoked: 'Revoked',
    audit_paused: 'Paused', audit_resumed: 'Resumed', audit_updated: 'Updated',
  }[type] || type.replace(/_/g, ' ').replace(/\b\w/g, (character) => character.toUpperCase())
}

function formatDate(value) {
  return formatWpDate(value)
}

onMounted(() => {
  fetchMember()
  fetchPlanOptions()
  fetchActivity()
})
</script>

<style scoped>
.member-profile-page { width: 100%; max-width: 1240px; margin: 0 auto; box-sizing: border-box; }
.profile-back-button { margin-bottom: 14px; }
.profile-workspace { display: grid; grid-template-columns: minmax(0, 1fr) minmax(300px, 350px); align-items: start; gap: 16px; }
.profile-main-column, .profile-side-column { min-width: 0; display: grid; gap: 16px; }
@media (max-width: 1100px) { .profile-workspace { grid-template-columns: 1fr; }.profile-side-column { grid-row: auto; } }
@media (max-width: 782px) { .member-profile-page { max-width: none; } }
</style>
