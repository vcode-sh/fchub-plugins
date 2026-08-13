import { computed, ref, unref } from 'vue'
import { ElMessage } from 'element-plus'
import { members, plans } from '@/api/index.js'
import { toExpiryTimestamp } from '@/utils/wpDate.js'
import {
  buildExtensionPresets,
  buildMemberVerdict,
  getMemberInitials,
  isCurrentMembership,
} from '@/pages/Members/memberProfileUi.js'

function profileUserId(userId) {
  return parseInt(unref(userId))
}

export function useMemberProfile(userId, {
  membersApi = members,
  plansApi = plans,
  notify = ElMessage,
} = {}) {
  const loading = ref(false)
  const member = ref(null)
  const memberships = ref([])
  const planOptions = ref([])
  const revokingAll = ref(false)
  const grantDialogVisible = ref(false)
  const granting = ref(false)
  const grantForm = ref(emptyGrantForm(userId))
  const extendDialogVisible = ref(false)
  const extending = ref(false)
  const extendDate = ref('')
  const extendingMembership = ref(null)
  const expandedKeys = ref([])
  const dripByKey = ref({})
  const providerStateByKey = ref({})
  const providerCheckPending = ref('')

  const currentMemberships = computed(() => memberships.value.filter(isCurrentMembership))
  const memberInitials = computed(() => getMemberInitials(member.value || {}))
  const verdict = computed(() => buildMemberVerdict(memberships.value))
  const canRevokeAll = computed(() => currentMemberships.value.length > 0)
  const extendPresets = computed(() => (
    extendingMembership.value ? buildExtensionPresets(extendingMembership.value) : []
  ))

  async function fetchMember() {
    loading.value = true
    try {
      const response = await membersApi.get(unref(userId))
      const data = response.data ?? response
      member.value = data.user || data
      memberships.value = data.memberships || []
    } catch (error) {
      notify.error(error.message || 'Failed to load member data')
    } finally {
      loading.value = false
    }
  }

  async function fetchPlanOptions() {
    try {
      const response = await plansApi.options()
      const options = response.data || response || []
      planOptions.value = options.map((option) => ({
        id: option.id ?? option.value,
        title: option.label ?? option.title,
      }))
    } catch {
      // Preserve the page's non-blocking plan-option fallback.
    }
  }

  async function mutate(operation, successMessage, failureMessage) {
    try {
      await operation()
      notify.success(successMessage)
      await fetchMember()
      return true
    } catch (error) {
      notify.error(error.message || failureMessage)
      return false
    }
  }

  function handleRevoke(membership) {
    return mutate(
      () => membersApi.revoke({ user_id: profileUserId(userId), plan_id: membership.plan_id }),
      'Membership revoked',
      'Failed to revoke membership',
    )
  }

  async function handleRevokeAll() {
    revokingAll.value = true
    try {
      const planIds = [...new Set(currentMemberships.value.map((item) => item.plan_id).filter(Boolean))]
      await Promise.all(planIds.map((planId) => (
        membersApi.revoke({ user_id: profileUserId(userId), plan_id: planId })
      )))
      notify.success('All current memberships revoked')
      await fetchMember()
    } catch (error) {
      notify.error(error.message || 'Failed to revoke memberships')
    } finally {
      revokingAll.value = false
    }
  }

  function resetGrantForm() {
    grantForm.value = emptyGrantForm(userId)
  }

  async function handleGrant() {
    granting.value = true
    const payload = { user_id: profileUserId(userId), plan_id: grantForm.value.plan_id }
    if (grantForm.value.expires_at) payload.expires_at = toExpiryTimestamp(grantForm.value.expires_at)
    if (grantForm.value.reason) payload.reason = grantForm.value.reason

    const granted = await mutate(
      () => membersApi.grant(payload),
      'Access granted successfully',
      'Failed to grant access',
    )
    if (granted) {
      grantDialogVisible.value = false
      resetGrantForm()
    }
    granting.value = false
  }

  function openExtendDialog(membership) {
    extendingMembership.value = membership
    extendDate.value = membership.expires_at ? membership.expires_at.slice(0, 10) : ''
    extendDialogVisible.value = true
  }

  async function handleExtend() {
    if (!extendingMembership.value) return
    extending.value = true

    const extended = await mutate(
      () => membersApi.extend({
        user_id: profileUserId(userId),
        plan_id: extendingMembership.value.plan_id,
        expires_at: toExpiryTimestamp(extendDate.value),
      }),
      'Membership extended successfully',
      'Failed to extend membership',
    )
    if (extended) {
      extendDialogVisible.value = false
      extendingMembership.value = null
      extendDate.value = ''
    }
    extending.value = false
  }

  function handlePause(membership) {
    return mutate(
      () => membersApi.pause({ grant_id: membership.grant_ids[0] }),
      'Membership paused',
      'Failed to pause membership',
    )
  }

  function handleResume(membership) {
    return mutate(
      () => membersApi.resume({ grant_id: membership.grant_ids[0] }),
      'Membership resumed',
      'Failed to resume membership',
    )
  }

  /**
   * Drip and provider state are loaded when a card is opened, never on page
   * load: provider classification calls the providers themselves.
   */
  async function toggleExpanded(membership) {
    const key = membership.key
    if (expandedKeys.value.includes(key)) {
      expandedKeys.value = expandedKeys.value.filter((item) => item !== key)
      return
    }

    expandedKeys.value = [...expandedKeys.value, key]
    if (!membership.plan_id || dripByKey.value[key]) return

    try {
      const response = await membersApi.dripTimeline(unref(userId), { plan_id: membership.plan_id })
      const data = response.data ?? response
      dripByKey.value = {
        ...dripByKey.value,
        [key]: Array.isArray(data) ? data : (data.items ?? data.timeline ?? []),
      }
    } catch {
      dripByKey.value = { ...dripByKey.value, [key]: [] }
    }
  }

  async function checkProviders(membership) {
    providerCheckPending.value = membership.key
    try {
      const response = await membersApi.providerState(unref(userId))
      const data = response.data ?? response
      providerStateByKey.value = { ...providerStateByKey.value, [membership.key]: data }
    } catch (error) {
      notify.error(error.message || 'Failed to read provider state')
    } finally {
      providerCheckPending.value = ''
    }
  }

  return {
    loading,
    member,
    memberships,
    currentMemberships,
    planOptions,
    revokingAll,
    canRevokeAll,
    grantDialogVisible,
    granting,
    grantForm,
    extendDialogVisible,
    extending,
    extendDate,
    extendPresets,
    expandedKeys,
    dripByKey,
    providerStateByKey,
    providerCheckPending,
    memberInitials,
    verdict,
    fetchMember,
    fetchPlanOptions,
    handleRevoke,
    handleRevokeAll,
    resetGrantForm,
    handleGrant,
    openExtendDialog,
    handleExtend,
    handlePause,
    handleResume,
    toggleExpanded,
    checkProviders,
  }
}

function emptyGrantForm(userId) {
  return {
    user_id: profileUserId(userId),
    plan_id: '',
    expires_at: '',
    reason: '',
  }
}
