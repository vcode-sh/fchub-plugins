import { ref } from 'vue'
import { createRemoteOptionsLoader, mappedResourceIds } from '@/components/settings/settingsIntegrationUi.js'

export function useSettingsIntegrationOptions({ api, form }) {
  const loadingLists = ref(false)
  const fluentcrmLists = ref([])
  const loadingSpaces = ref(false)
  const fcSpaces = ref([])
  const spaceSearchError = ref('')
  const planOptions = ref([])
  const planOptionsError = ref('')

  const spaceOptionsLoader = createRemoteOptionsLoader(async (query, include) => {
    const response = await api.get('admin/fc-spaces', { search: query, include })
    return response.data ?? response ?? []
  })

  async function loadPlanOptions(source = form.value) {
    planOptionsError.value = ''
    const mappingKeys = Object.keys(source.fc_space_mappings ?? {})
      .filter((id) => Boolean(source.fc_space_mappings?.[id]))
    const validPlanIds = mappingKeys.filter((id) => /^[1-9]\d*$/.test(id))
    const invalidRows = mappingKeys
      .filter((id) => !validPlanIds.includes(id))
      .map((id) => ({
        id,
        label: `Invalid saved plan reference “${id}”`,
        value: id,
        status: 'invalid',
      }))

    try {
      const plansRes = await api.get('admin/plans/options', { include: validPlanIds.join(',') })
      const plans = plansRes.data ?? plansRes
      const availablePlans = Array.isArray(plans) ? plans : []
      const returnedPlanIds = new Set(availablePlans.map((plan) => String(plan.id)))
      planOptions.value = [
        ...availablePlans,
        ...validPlanIds
          .filter((id) => !returnedPlanIds.has(id))
          .map((id) => ({ id: Number(id), label: `Unavailable plan #${id}`, value: id, status: 'missing' })),
        ...invalidRows,
      ]
    } catch {
      planOptionsError.value = 'Membership plans could not be loaded. Retry, or clear the saved mappings shown below.'
      planOptions.value = [
        ...validPlanIds.map((id) => ({ id: Number(id), label: `Saved plan #${id}`, value: id, status: 'unavailable' })),
        ...invalidRows,
      ]
    }
  }

  async function searchFluentcrmLists(query) {
    loadingLists.value = true
    try {
      const response = await api.get('admin/fluentcrm-lists', { search: query })
      fluentcrmLists.value = response.data ?? response ?? []
    } catch {
      fluentcrmLists.value = []
    } finally {
      loadingLists.value = false
    }
  }

  async function searchFcSpaces(query) {
    const include = mappedResourceIds(form.value.fc_space_mappings)
    loadingSpaces.value = true
    spaceSearchError.value = ''
    const result = await spaceOptionsLoader.search(query, include)
    if (result.stale) return

    if (result.error) {
      fcSpaces.value = []
      spaceSearchError.value = 'Spaces could not be loaded. Try opening the selector again.'
    } else {
      fcSpaces.value = result.options
    }
    loadingSpaces.value = false
  }

  async function loadSavedOptions() {
    if (form.value.fluentcrm_default_list) searchFluentcrmLists('')
    if (mappedResourceIds(form.value.fc_space_mappings)) await searchFcSpaces('')
  }

  function invalidCommunityMapping(mappings = {}) {
    return Object.entries(mappings).some(([planId, resourceId]) => Boolean(resourceId) && (
      !/^[1-9]\d*$/.test(String(planId))
      || !/^\d+$/.test(String(resourceId))
      || Number(planId) <= 0
      || Number(resourceId) <= 0
    ))
  }

  function unavailableCommunityMapping(mappings = {}, options = [], loadError = '') {
    const selectedIds = Object.values(mappings).map((value) => String(value ?? '')).filter(Boolean)
    if (selectedIds.length === 0) return false
    if (loadError) return true

    const availableIds = new Set(options.map((option) => String(option.id)))
    return selectedIds.some((id) => !availableIds.has(id))
  }

  function validateCommunityMappings() {
    const mappings = form.value.fc_space_mappings ?? {}
    const hasMappings = Object.values(mappings).some(Boolean)
    if (!form.value.fc_enabled || !hasMappings) return null
    if (planOptionsError.value) {
      return 'Retry loading membership plans, or clear the saved mapping rows before saving.'
    }
    if (invalidCommunityMapping(mappings)) return 'Review unavailable FluentCommunity mappings before saving.'
    if (unavailableCommunityMapping(mappings, fcSpaces.value, spaceSearchError.value)) {
      return 'Clear or replace FluentCommunity resources that are no longer available.'
    }
    return null
  }

  return {
    loadingLists,
    fluentcrmLists,
    loadingSpaces,
    fcSpaces,
    spaceSearchError,
    planOptions,
    planOptionsError,
    loadPlanOptions,
    searchFluentcrmLists,
    searchFcSpaces,
    loadSavedOptions,
    invalidCommunityMapping,
    unavailableCommunityMapping,
    validateCommunityMappings,
  }
}
