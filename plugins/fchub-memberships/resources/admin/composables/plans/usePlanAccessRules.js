import { computed, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { appendCommunitySpaceRules } from '@/pages/Plans/planEditorUi.js'
import { hasReadOnlyPlanRules } from '@/utils/planRulePayload.js'

const SPECIAL_PAGE_OPTIONS = Object.freeze([
  { id: 'blog', label: 'Blog / Posts Page' },
  { id: 'front_page', label: 'Front Page' },
  { id: 'search', label: 'Search Results' },
  { id: '404', label: '404 Page' },
  { id: 'author', label: 'Author Archives' },
  { id: 'date', label: 'Date Archives' },
])

function capitalize(value) {
  if (!value) return ''
  return value.charAt(0).toUpperCase() + value.slice(1)
}

export function usePlanAccessRules({
  contentApi,
  rules,
  messageApi = ElMessage,
}) {
  const resourceTypeGroups = ref([])
  const spaceGroups = ref([])
  const spaceGroupsLoading = ref(false)
  const selectedSpaceGroupId = ref('')

  const currentRules = () => rules() || []
  const hasReadOnlyRules = computed(() => hasReadOnlyPlanRules(currentRules()))
  const hasFcSpaceResourceType = computed(() => Boolean(getTypeConfig('fc_space')))

  function getTypeConfig(resourceType) {
    for (const group of resourceTypeGroups.value) {
      const found = group.types.find((type) => type.value === resourceType)
      if (found) return found
    }
    return null
  }

  function createEmptyRule() {
    return {
      resource_type: 'post',
      resource_id: '0',
      resource_label: null,
      drip_type: 'immediate',
      drip_delay_days: null,
      drip_date: null,
    }
  }

  function addRule() {
    if (hasReadOnlyRules.value) return
    currentRules().push(createEmptyRule())
  }

  function removeRule(index) {
    if (hasReadOnlyRules.value) return
    currentRules().splice(index, 1)
  }

  function onDripTypeChange(rule) {
    if (hasReadOnlyRules.value) return

    if (rule.drip_type === 'immediate') {
      rule.drip_delay_days = null
      rule.drip_date = null
    } else if (rule.drip_type === 'delayed') {
      rule.drip_date = null
      if (!rule.drip_delay_days) rule.drip_delay_days = 1
    } else if (rule.drip_type === 'fixed_date') {
      rule.drip_delay_days = null
    }
  }

  function addSelectedSpaceGroup(groupId) {
    if (!groupId || hasReadOnlyRules.value) return

    const group = spaceGroups.value.find((item) => String(item.id) === String(groupId))
    selectedSpaceGroupId.value = ''
    if (!group) return

    const planRules = currentRules()
    const previousRuleCount = planRules.length
    const result = appendCommunitySpaceRules(planRules, group.spaces)
    if (result.added.length === 0) {
      messageApi.info(`All Spaces from ${group.label} are already in this plan`)
      return
    }

    planRules.push(...result.rules.slice(previousRuleCount))
    messageApi.success(`Added ${result.added.length} Space${result.added.length === 1 ? '' : 's'} from ${group.label}`)
  }

  function resourceIdRules(rule) {
    if (hasReadOnlyRules.value || getTypeConfig(rule.resource_type)?.allow_all !== false) {
      return []
    }

    return [{
      trigger: ['blur', 'change'],
      validator: (_rule, value, callback) => {
        const identifier = getTypeConfig(rule.resource_type)?.identifier || 'positive_int'
        const resourceId = String(value ?? '')

        if (identifier === 'slug' && resourceId !== '' && /\D/.test(resourceId)) {
          callback()
          return
        }

        if (identifier === 'positive_int' && /^[1-9]\d*$/.test(resourceId)) {
          callback()
          return
        }

        callback(new Error('Choose a valid provider resource'))
      },
    }]
  }

  function ruleSummary(rule) {
    const type = getTypeConfig(rule.resource_type)?.displayLabel
      || capitalize(rule.resource_type)
      || 'Resource'
    const scope = !rule.resource_id || String(rule.resource_id) === '0'
      ? 'all of this type'
      : rule.resource_label || 'selected resource'
    const drip = rule.drip_type === 'delayed'
      ? `after ${rule.drip_delay_days || 1} day${Number(rule.drip_delay_days || 1) === 1 ? '' : 's'}`
      : rule.drip_type === 'fixed_date'
        ? 'on a fixed date'
        : 'immediately'

    return `${type} · ${scope} · ${drip}`
  }

  function onResourceTypeChange(rule) {
    if (hasReadOnlyRules.value) return

    rule.resource_id = rule.resource_type === 'url_pattern'
      ? ''
      : (getTypeConfig(rule.resource_type)?.allow_all ? '0' : '')
    rule.resource_label = null
  }

  async function loadResourceTypes() {
    try {
      const response = await contentApi.resourceTypes()
      const data = response.data ?? response
      const types = Array.isArray(data) ? data : (data.data ?? data)
      const groups = response.groups ?? data.groups ?? {}
      const groupMap = {}
      const groupOrder = ['content', 'taxonomy', 'navigation', 'advanced']
      const defaultLabels = {
        content: 'Content',
        taxonomy: 'Taxonomy',
        navigation: 'Navigation',
        advanced: 'Advanced',
      }

      for (const type of types) {
        const groupKey = type.group || 'content'
        if (!groupMap[groupKey]) {
          groupMap[groupKey] = {
            key: groupKey,
            label: groups[groupKey] || defaultLabels[groupKey] || capitalize(groupKey),
            types: [],
          }
        }
        const source = type.source || ''
        groupMap[groupKey].types.push({
          value: type.key || type.value,
          label: type.label,
          source,
          searchable: type.searchable !== false,
          allow_all: type.allow_all === true,
          identifier: type.identifier || 'positive_int',
          displayLabel: source ? `${type.label} (${source})` : type.label,
        })
      }

      resourceTypeGroups.value = groupOrder
        .filter((groupKey) => groupMap[groupKey])
        .map((groupKey) => groupMap[groupKey])
    } catch {
      resourceTypeGroups.value = [
        {
          key: 'content',
          label: 'Content',
          types: [
            { value: 'post', label: 'Posts' },
            { value: 'page', label: 'Pages' },
          ],
        },
        {
          key: 'taxonomy',
          label: 'Taxonomy',
          types: [
            { value: 'category', label: 'Categories' },
            { value: 'post_tag', label: 'Tags' },
          ],
        },
      ]
    }
  }

  async function loadSpaceGroups() {
    spaceGroupsLoading.value = true
    try {
      const response = await contentApi.spaceGroups({ search: '' })
      const data = response.data ?? response
      spaceGroups.value = (Array.isArray(data) ? data : []).map((group) => ({
        id: String(group.id),
        label: group.label || `Group #${group.id}`,
        spaces: Array.isArray(group.spaces)
          ? group.spaces.map((space) => ({
            id: String(space.id),
            label: space.label || `Space #${space.id}`,
          }))
          : [],
      }))
    } catch {
      spaceGroups.value = []
    } finally {
      spaceGroupsLoading.value = false
    }
  }

  function isPastDate(date) {
    const today = new Date()
    today.setHours(0, 0, 0, 0)
    return date.getTime() < today.getTime()
  }

  return {
    specialPageOptions: SPECIAL_PAGE_OPTIONS,
    resourceTypeGroups,
    spaceGroups,
    spaceGroupsLoading,
    selectedSpaceGroupId,
    hasReadOnlyRules,
    hasFcSpaceResourceType,
    getTypeConfig,
    addRule,
    removeRule,
    onDripTypeChange,
    addSelectedSpaceGroup,
    resourceIdRules,
    ruleSummary,
    onResourceTypeChange,
    loadResourceTypes,
    loadSpaceGroups,
    isPastDate,
  }
}
