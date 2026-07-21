import { computed, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { canAdvanceProtectionStep } from '@/components/content/contentProtectionWizardUi.js'

const RESOURCE_SEARCH_DELAY = 250
const MIN_RESOURCE_QUERY_LENGTH = 2

export function useContentProtectionWizard({
  contentApi,
  fetchContent,
  resourceTypes,
  planOptionsMap,
  planOptionsLoading,
}) {
  const wizardVisible = ref(false)
  const wizardStep = ref(0)
  const protectLoading = ref(false)
  const resourceSearchLoading = ref(false)
  const resourceSearchError = ref('')
  const resourceOptions = ref([])
  const resourceResultCache = new Map()
  const resourcePendingRequests = new Map()
  let resourceRequestId = 0
  let resourceSessionId = 0

  const wizardForm = reactive({
    categoryKey: '',
    categoryLabel: '',
    resource_type: '',
    resource_type_label: '',
    resource_id: '',
    plan_ids: [],
    show_teaser: 'no',
    restriction_message: '',
    redirect_url: '',
    commentMode: 'all',
  })

  const wizardCategoryTypes = computed(() => {
    if (!wizardForm.categoryKey) return []

    const key = wizardForm.categoryKey
    const allTypes = resourceTypes.value

    switch (key) {
      case 'posts_pages':
        return allTypes.filter(t => ['post', 'page'].includes(t.key)).map(t => ({ value: t.key, label: t.label }))
      case 'taxonomies':
        return allTypes.filter(t => t.group === 'taxonomy').map(t => ({ value: t.key, label: t.label }))
      case 'cpt':
        return allTypes.filter(t => t.group === 'content' && !['post', 'page'].includes(t.key)).map(t => ({ value: t.key, label: t.label }))
      case 'menu':
        return allTypes.filter(t => t.key === 'menu_item').map(t => ({ value: t.key, label: t.label }))
      case 'url':
        return allTypes.filter(t => t.key === 'url_pattern').map(t => ({ value: t.key, label: t.label }))
      case 'special':
        return allTypes.filter(t => t.key === 'special_page').map(t => ({ value: t.key, label: t.label }))
      case 'comments':
        return allTypes.filter(t => t.key === 'comment').map(t => ({ value: t.key, label: t.label }))
      default:
        return []
    }
  })

  function resetWizard() {
    wizardStep.value = 0
    wizardForm.categoryKey = ''
    wizardForm.categoryLabel = ''
    wizardForm.resource_type = ''
    wizardForm.resource_type_label = ''
    wizardForm.resource_id = ''
    wizardForm.plan_ids = []
    wizardForm.show_teaser = 'no'
    wizardForm.restriction_message = ''
    wizardForm.redirect_url = ''
    wizardForm.commentMode = 'all'
    resourceRequestId += 1
    resourceSessionId += 1
    resourceResultCache.clear()
    resourcePendingRequests.clear()
    resourceOptions.value = []
    resourceSearchError.value = ''
    resourceSearchLoading.value = false
  }

  function openProtectWizard(categoryKey, categoryCards) {
    resetWizard()
    wizardVisible.value = true
    if (categoryKey) {
      selectWizardCategory(categoryCards.find(c => c.key === categoryKey))
    }
  }

  function selectWizardCategory(card) {
    if (!card) return
    wizardForm.categoryKey = card.key
    wizardForm.categoryLabel = card.label
    wizardForm.resource_type = ''
    wizardForm.resource_type_label = ''
    wizardForm.resource_id = ''
    resourceRequestId += 1
    resourceOptions.value = []
    resourceSearchError.value = ''
    resourceSearchLoading.value = false

    const types = wizardCategoryTypes.value
    if (types.length === 1) {
      wizardForm.resource_type = types[0].value
      wizardForm.resource_type_label = types[0].label
      loadInitialResources()
    }
  }

  function onWizardTypeChange() {
    wizardForm.resource_id = ''
    resourceRequestId += 1
    resourceOptions.value = []
    resourceSearchError.value = ''
    resourceSearchLoading.value = false
    const typeObj = wizardCategoryTypes.value.find(t => t.value === wizardForm.resource_type)
    wizardForm.resource_type_label = typeObj ? typeObj.label : wizardForm.resource_type
    loadInitialResources()
  }

  function onCommentModeChange() {
    wizardForm.resource_id = wizardForm.commentMode === 'all' ? '*' : ''
  }

  async function loadInitialResources() {
    const type = wizardForm.resource_type
    if (!type) return

    if (type === 'special_page' || type === 'menu_item') {
      await searchResources('')
    }

    if (type === 'comment') {
      wizardForm.commentMode = 'all'
      wizardForm.resource_id = '*'
    }
  }

  function mergeSelectedResource(options) {
    const selected = resourceOptions.value.find(
      option => String(option.id) === String(wizardForm.resource_id),
    )

    if (!selected || options.some(option => String(option.id) === String(selected.id))) {
      return options
    }

    return [selected, ...options]
  }

  function cacheKey(type, query) {
    return `${type}:${query.toLowerCase()}`
  }

  async function fetchResourceOptions(type, query, requestId, sessionId) {
    const key = cacheKey(type, query)

    if (resourceResultCache.has(key)) {
      if (requestId === resourceRequestId) {
        resourceOptions.value = mergeSelectedResource(resourceResultCache.get(key))
        resourceSearchLoading.value = false
      }
      return
    }

    let request = resourcePendingRequests.get(key)
    if (!request) {
      request = contentApi.searchResources({ type, query })
        .then((res) => {
          const options = res.data ?? res ?? []
          if (sessionId === resourceSessionId) {
            resourceResultCache.set(key, options)
          }
          return options
        })
      resourcePendingRequests.set(key, request)
      const clearPendingRequest = () => {
        if (resourcePendingRequests.get(key) === request) {
          resourcePendingRequests.delete(key)
        }
      }
      void request.then(clearPendingRequest, clearPendingRequest)
    }

    try {
      const options = await request
      if (requestId === resourceRequestId) {
        resourceOptions.value = mergeSelectedResource(options)
      }
    } catch (error) {
      if (requestId === resourceRequestId) {
        resourceOptions.value = mergeSelectedResource([])
        resourceSearchError.value = error.message || 'Content search failed. Try again.'
      }
    } finally {
      if (requestId === resourceRequestId) {
        resourceSearchLoading.value = false
      }
    }
  }

  async function searchResources(query) {
    const type = wizardForm.resource_type === 'comment' ? 'post' : wizardForm.resource_type
    const normalizedQuery = String(query || '').trim()
    if (!type) {
      resourceRequestId += 1
      resourceOptions.value = []
      resourceSearchError.value = ''
      resourceSearchLoading.value = false
      return
    }

    const requestId = ++resourceRequestId
    const sessionId = resourceSessionId
    const effectiveQuery = normalizedQuery.length < MIN_RESOURCE_QUERY_LENGTH ? '' : normalizedQuery
    const key = cacheKey(type, effectiveQuery)
    resourceSearchLoading.value = true
    resourceSearchError.value = ''

    if (normalizedQuery.length >= MIN_RESOURCE_QUERY_LENGTH && !resourceResultCache.has(key)) {
      await new Promise(resolve => setTimeout(resolve, RESOURCE_SEARCH_DELAY))
      if (requestId !== resourceRequestId) return
    }

    await fetchResourceOptions(type, effectiveQuery, requestId, sessionId)
  }

  const wizardResourceDisplayName = computed(() => {
    if (wizardForm.resource_type === 'url_pattern') {
      return wizardForm.resource_id || '(not set)'
    }
    if (wizardForm.resource_type === 'comment' && wizardForm.resource_id === '*') {
      return 'All Protected Content Comments'
    }
    const opt = resourceOptions.value.find(o => String(o.id) === String(wizardForm.resource_id))
    return opt ? (opt.label || opt.title) : wizardForm.resource_id || '(not set)'
  })

  const canAdvanceWizard = computed(() => canAdvanceProtectionStep(wizardStep.value, wizardForm))

  async function submitProtect() {
    if (protectLoading.value) return

    protectLoading.value = true
    try {
      await contentApi.protect({
        resource_type: wizardForm.resource_type,
        resource_id: wizardForm.resource_id,
        plan_ids: wizardForm.plan_ids,
        show_teaser: wizardForm.show_teaser,
        restriction_message: wizardForm.restriction_message,
        redirect_url: wizardForm.redirect_url,
      })
      ElMessage.success('Content protected successfully')
      wizardVisible.value = false
      resetWizard()
      await fetchContent()
    } catch (err) {
      ElMessage.error(err.message || 'Failed to protect content')
    } finally {
      protectLoading.value = false
    }
  }

  return {
    wizardVisible,
    wizardStep,
    protectLoading,
    resourceSearchLoading,
    resourceSearchError,
    resourceOptions,
    wizardForm,
    wizardCategoryTypes,
    wizardResourceDisplayName,
    canAdvanceWizard,
    planOptionsMap,
    planOptionsLoading,
    openProtectWizard,
    selectWizardCategory,
    onWizardTypeChange,
    onCommentModeChange,
    searchResources,
    submitProtect,
    resetWizard,
  }
}
