<template>
  <div class="member-profile-page" v-loading="loading">
    <WorkspaceBackButton class="profile-back-button" to="/members" label="Back to members" />

    <template v-if="member">
      <MemberProfileHero
        :member="member"
        :initials="memberInitials"
        :verdict="verdict"
        :can-revoke-all="canRevokeAll"
        :revoking-all="revokingAll"
        :format-date="formatDate"
        @grant="grantDialogVisible = true"
        @revoke-all="handleRevokeAll"
      />

      <div class="profile-workspace">
        <main class="profile-main-column">
          <MemberProfileAccessPanel
            :memberships="memberships"
            :expanded-keys="expandedKeys"
            :drip-by-key="dripByKey"
            :provider-state-by-key="providerStateByKey"
            :provider-check-pending="providerCheckPending"
            :format-date="formatDate"
            @grant="grantDialogVisible = true"
            @toggle="toggleExpanded"
            @pause="handlePause"
            @resume="handleResume"
            @extend="openExtendDialog"
            @revoke="handleRevoke"
            @check-providers="checkProviders"
          />
          <MemberProfileTimeline
            :events="activityEvents"
            :total="activityTotal"
            :filter="timelineFilter"
            :loading="activityLoading"
            :loading-more="activityLoadingMore"
            :format-date="formatDate"
            @load-more="loadMoreActivity"
            @update:filter="timelineFilter = $event"
          />
        </main>

        <aside class="profile-side-column">
          <MemberAccessCheckPanel
            :options="accessCheck.options.value"
            :selected="accessCheck.selected.value"
            :result="accessCheck.result.value"
            :empty-text="accessCheck.emptyText.value"
            :searching="accessCheck.searching.value"
            :checking="accessCheck.checking.value"
            :search="accessCheck.search"
            :browse="accessCheck.browse"
            @check="accessCheck.check"
            @update:selected="accessCheck.selected.value = $event"
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
      :presets="extendPresets"
      :loading="extending"
      :date-picker-format="wpDatePickerFormat"
      @update:visible="extendDialogVisible = $event"
      @update:date="extendDate = $event"
      @confirm="handleExtend"
    />
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { formatWpDate, wpDatePickerFormat } from '@/utils/wpDate.js'
import GrantAccessDialog from '@/components/members/GrantAccessDialog.vue'
import MemberAccessCheckPanel from '@/components/members/profile/MemberAccessCheckPanel.vue'
import MemberProfileAccessPanel from '@/components/members/profile/MemberProfileAccessPanel.vue'
import MemberProfileExtendDialog from '@/components/members/profile/MemberProfileExtendDialog.vue'
import MemberProfileHero from '@/components/members/profile/MemberProfileHero.vue'
import MemberProfileTimeline from '@/components/members/profile/MemberProfileTimeline.vue'
import WorkspaceBackButton from '@/components/workspace/WorkspaceBackButton.vue'
import { useMemberAccessCheck } from '@/composables/members/useMemberAccessCheck.js'
import { useMemberActivity } from '@/composables/members/useMemberActivity.js'
import { useMemberProfile } from '@/composables/members/useMemberProfile.js'

const route = useRoute()
const userId = computed(() => route.params.id)
const timelineFilter = ref('')
const accessCheck = useMemberAccessCheck(userId)

const {
  loading, member, memberships, planOptions, revokingAll, canRevokeAll, grantDialogVisible,
  granting, grantForm, extendDialogVisible, extending, extendDate, extendPresets, expandedKeys,
  dripByKey, providerStateByKey, providerCheckPending, memberInitials, verdict, fetchMember,
  fetchPlanOptions, handleRevoke, handleRevokeAll, resetGrantForm, handleGrant, openExtendDialog,
  handleExtend, handlePause, handleResume, toggleExpanded, checkProviders,
} = useMemberProfile(userId)

const {
  loading: activityLoading, loadingMore: activityLoadingMore, events: activityEvents,
  total: activityTotal, fetchActivity, loadMoreActivity,
} = useMemberActivity(userId)

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
