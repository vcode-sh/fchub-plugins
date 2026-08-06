import { computed, ref, unref } from 'vue'
import { ElMessage } from 'element-plus'
import { members, plans } from '@/api/index.js'
import {
  getMemberAccessState,
  getMemberInitials,
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
  const allGrants = ref([])
  const timeline = ref([])
  const planOptions = ref([])
  const revokingAll = ref(false)
  const grantDialogVisible = ref(false)
  const granting = ref(false)
  const grantForm = ref({
    user_id: profileUserId(userId),
    plan_id: '',
    expires_at: '',
    reason: '',
  })
  const extendDialogVisible = ref(false)
  const extending = ref(false)
  const extendDate = ref('')
  const extendingGrant = ref(null)
  const dripDrawerVisible = ref(false)
  const dripDrawerLoading = ref(false)
  const dripDrawerPlan = ref(null)
  const dripDrawerData = ref([])

  const activeGrants = computed(() => (
    allGrants.value.filter((grant) => grant.status === 'active' || grant.status === 'paused')
  ))
  const memberInitials = computed(() => getMemberInitials(member.value || {}))
  const accessState = computed(() => getMemberAccessState(activeGrants.value))

  async function fetchMember() {
    loading.value = true
    try {
      const response = await membersApi.get(unref(userId))
      const data = response.data ?? response
      member.value = data.user || data
      const planGroups = data.plans || []
      const grants = []
      planGroups.forEach((planGroup) => {
        const planGrants = planGroup.grants || []
        planGrants.forEach((grant) => {
          grants.push({ ...grant, plan_title: planGroup.plan_title || '' })
        })
      })
      allGrants.value = (data.history || []).map((grant) => ({
        ...grant,
        plan_title: grant.plan_title || grants.find((item) => item.id === grant.id)?.plan_title || '',
      }))
      timeline.value = planGroups.filter((planGroup) => planGroup.progress).map((planGroup) => ({
        plan_id: planGroup.plan_id,
        plan_title: planGroup.plan_title,
        items: planGroup.progress?.items || [],
      }))
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

  async function handleRevoke(grant) {
    try {
      await membersApi.revoke({ user_id: profileUserId(userId), plan_id: grant.plan_id })
      notify.success('Grant revoked')
      fetchMember()
    } catch (error) {
      notify.error(error.message || 'Failed to revoke grant')
    }
  }

  async function handleRevokeAll() {
    revokingAll.value = true
    try {
      const planIds = [...new Set(activeGrants.value.map((grant) => grant.plan_id).filter(Boolean))]
      await Promise.all(planIds.map((planId) => (
        membersApi.revoke({ user_id: profileUserId(userId), plan_id: planId })
      )))
      notify.success('All active grants revoked')
      fetchMember()
    } catch (error) {
      notify.error(error.message || 'Failed to revoke grants')
    } finally {
      revokingAll.value = false
    }
  }

  function resetGrantForm() {
    grantForm.value = {
      user_id: profileUserId(userId),
      plan_id: '',
      expires_at: '',
      reason: '',
    }
  }

  async function handleGrant() {
    granting.value = true
    try {
      const payload = { user_id: profileUserId(userId), plan_id: grantForm.value.plan_id }
      if (grantForm.value.expires_at) payload.expires_at = grantForm.value.expires_at
      if (grantForm.value.reason) payload.reason = grantForm.value.reason
      await membersApi.grant(payload)
      notify.success('Access granted successfully')
      grantDialogVisible.value = false
      resetGrantForm()
      fetchMember()
    } catch (error) {
      notify.error(error.message || 'Failed to grant access')
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
        user_id: profileUserId(userId),
        plan_id: extendingGrant.value.plan_id,
        expires_at: extendDate.value,
      })
      notify.success('Grant extended successfully')
      extendDialogVisible.value = false
      extendingGrant.value = null
      extendDate.value = ''
      fetchMember()
    } catch (error) {
      notify.error(error.message || 'Failed to extend grant')
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
      const response = await membersApi.dripTimeline(unref(userId), { plan_id: grant.plan_id })
      const data = response.data ?? response
      dripDrawerData.value = Array.isArray(data) ? data : (data.items ?? data.timeline ?? [])
    } catch {
      dripDrawerData.value = []
    } finally {
      dripDrawerLoading.value = false
    }
  }

  async function handlePause(grant) {
    try {
      await membersApi.pause({ grant_id: grant.id })
      notify.success('Membership paused')
      fetchMember()
    } catch (error) {
      notify.error(error.message || 'Failed to pause')
    }
  }

  async function handleResume(grant) {
    try {
      await membersApi.resume({ grant_id: grant.id })
      notify.success('Membership resumed')
      fetchMember()
    } catch (error) {
      notify.error(error.message || 'Failed to resume')
    }
  }

  return {
    loading,
    member,
    allGrants,
    timeline,
    planOptions,
    revokingAll,
    grantDialogVisible,
    granting,
    grantForm,
    extendDialogVisible,
    extending,
    extendDate,
    dripDrawerVisible,
    dripDrawerLoading,
    dripDrawerPlan,
    dripDrawerData,
    activeGrants,
    memberInitials,
    accessState,
    fetchMember,
    fetchPlanOptions,
    handleRevoke,
    handleRevokeAll,
    resetGrantForm,
    handleGrant,
    openExtendDialog,
    handleExtend,
    openDripDrawer,
    handlePause,
    handleResume,
  }
}
