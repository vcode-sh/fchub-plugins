import { computed, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { canAdvanceProtectionStep } from '@/components/content/contentProtectionWizardUi.js'

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
  const specialPages = ref([])

  const wizardForm = reactive({
    categoryKey: '',
    categoryLabel: '',
    resource_type: '',
    resource_type_label: '',
    resource_id: '',
    resource_label: null,
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
    wizardForm.resource_label = null
    wizardForm.plan_ids = []
    wizardForm.show_teaser = 'no'
    wizardForm.restriction_message = ''
    wizardForm.redirect_url = ''
    wizardForm.commentMode = 'all'
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
    clearResourceSelection()

    const types = wizardCategoryTypes.value
    if (types.length === 1) {
      wizardForm.resource_type = types[0].value
      wizardForm.resource_type_label = types[0].label
      loadInitialResources()
    }
  }

  function onWizardTypeChange() {
    clearResourceSelection()
    const typeObj = wizardCategoryTypes.value.find(t => t.value === wizardForm.resource_type)
    wizardForm.resource_type_label = typeObj ? typeObj.label : wizardForm.resource_type
    loadInitialResources()
  }

  function onCommentModeChange() {
    clearResourceSelection()
    if (wizardForm.commentMode === 'all') {
      wizardForm.resource_id = '*'
      wizardForm.resource_label = 'All Protected Content Comments'
    }
  }

  function clearResourceSelection() {
    wizardForm.resource_id = ''
    wizardForm.resource_label = null
  }

  // Special pages are a fixed six, so they load once instead of being searched.
  async function loadInitialResources() {
    if (wizardForm.resource_type === 'special_page') {
      const response = await contentApi.searchResources({ type: 'special_page', query: '' })
      specialPages.value = response.data ?? response ?? []
    }

    if (wizardForm.resource_type === 'comment') {
      wizardForm.commentMode = 'all'
      onCommentModeChange()
    }
  }

  const wizardResourceDisplayName = computed(() => {
    if (wizardForm.resource_type === 'url_pattern') {
      return wizardForm.resource_id || '(not set)'
    }
    return wizardForm.resource_label || wizardForm.resource_id || '(not set)'
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
    specialPages,
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
    submitProtect,
    resetWizard,
  }
}
