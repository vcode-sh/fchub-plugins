import { computed, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'

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
  const resourceOptions = ref([])

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
    resourceOptions.value = []
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
    wizardForm.resource_id = ''
    resourceOptions.value = []

    const types = wizardCategoryTypes.value
    if (types.length === 1) {
      wizardForm.resource_type = types[0].value
      wizardForm.resource_type_label = types[0].label
      loadInitialResources()
    }
  }

  function onWizardTypeChange() {
    wizardForm.resource_id = ''
    resourceOptions.value = []
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
      resourceSearchLoading.value = true
      try {
        const res = await contentApi.searchResources({ type, query: '' })
        resourceOptions.value = res.data ?? res ?? []
      } catch {
        resourceOptions.value = []
      } finally {
        resourceSearchLoading.value = false
      }
    }

    if (type === 'comment') {
      wizardForm.commentMode = 'all'
      wizardForm.resource_id = '*'
    }
  }

  async function searchResources(query) {
    const type = wizardForm.resource_type === 'comment' ? 'post' : wizardForm.resource_type
    if (!query || !type) return
    resourceSearchLoading.value = true
    try {
      const res = await contentApi.searchResources({ type, query })
      resourceOptions.value = res.data ?? res ?? []
    } catch {
      resourceOptions.value = []
    } finally {
      resourceSearchLoading.value = false
    }
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

  const canAdvanceWizard = computed(() => {
    switch (wizardStep.value) {
      case 0:
        return !!wizardForm.categoryKey
      case 1:
        return !!wizardForm.resource_type && !!wizardForm.resource_id
      case 2:
        return wizardForm.plan_ids.length > 0
      default:
        return true
    }
  })

  async function submitProtect() {
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
