import { computed, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
  CONTENT_PROTECTION_STEPS,
  canAdvanceProtectionStep,
  categoryDescription,
  hasResourceSelection,
  stepCopy,
} from '@/components/content/contentProtectionWizardUi.js'
import { useContentProtectionWizard } from '@/composables/content/useContentProtectionWizard.js'

function deferred() {
  let resolve
  let reject
  const promise = new Promise((resolvePromise, rejectPromise) => {
    resolve = resolvePromise
    reject = rejectPromise
  })

  return { promise, resolve, reject }
}

function createWizard(contentApi = {}) {
  const api = {
    protect: vi.fn().mockResolvedValue({ data: {} }),
    searchResources: vi.fn().mockResolvedValue({ data: [] }),
    ...contentApi,
  }
  const fetchContent = vi.fn().mockResolvedValue(undefined)
  const resourceTypes = ref([
    { key: 'post', label: 'Posts', group: 'content' },
    { key: 'page', label: 'Pages', group: 'content' },
    { key: 'category', label: 'Categories', group: 'taxonomy' },
    { key: 'comment', label: 'Comments', group: 'advanced' },
  ])
  const planOptionsMap = computed(() => ({ 5: 'Gold Plan' }))

  return {
    api,
    fetchContent,
    wizard: useContentProtectionWizard({
      contentApi: api,
      fetchContent,
      resourceTypes,
      planOptionsMap,
      planOptionsLoading: ref(false),
    }),
  }
}

describe('content protection wizard UI policy', () => {
  it('defines four task-first steps', () => {
    expect(CONTENT_PROTECTION_STEPS.map(({ label }) => label)).toEqual([
      'Choose content',
      'Select resource',
      'Set access',
      'Review',
    ])
  })

  it('rejects blank resources and requires a selected type and plan', () => {
    expect(hasResourceSelection({ resource_type: 'post', resource_id: '   ' })).toBe(false)
    expect(canAdvanceProtectionStep(0, { categoryKey: 'posts_pages', resource_type: '' })).toBe(false)
    expect(canAdvanceProtectionStep(0, { categoryKey: 'posts_pages', resource_type: 'post' })).toBe(true)
    expect(canAdvanceProtectionStep(2, { plan_ids: [] })).toBe(false)
    expect(canAdvanceProtectionStep(2, { plan_ids: [5] })).toBe(true)
  })

  it('provides standalone category and step copy', () => {
    expect(categoryDescription('posts_pages')).toBe('Protect individual posts and pages.')
    expect(categoryDescription('unknown')).toBe('Choose this content type.')
    expect(stepCopy(1, { categoryLabel: 'Posts & Pages' })).toMatchObject({
      eyebrow: 'STEP 2 OF 4',
      title: 'Choose a specific resource',
    })
    expect(stepCopy(99, {})).toMatchObject({ title: 'Start with the content type' })
  })
})

describe('content protection wizard state', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
    vi.spyOn(ElMessage, 'success').mockImplementation(() => undefined)
    vi.spyOn(ElMessage, 'error').mockImplementation(() => undefined)
  })

  it('requires a subtype for a multi-type category and resets stale resources', () => {
    const { wizard } = createWizard()
    wizard.wizardForm.resource_type = 'page'
    wizard.wizardForm.resource_id = '91'
    wizard.resourceOptions.value = [{ id: '91', label: 'Old Page' }]

    wizard.selectWizardCategory({ key: 'posts_pages', label: 'Posts & Pages' })

    expect(wizard.wizardForm.resource_type).toBe('')
    expect(wizard.wizardForm.resource_id).toBe('')
    expect(wizard.resourceOptions.value).toEqual([])
    expect(wizard.canAdvanceWizard.value).toBe(false)

    wizard.wizardForm.resource_type = 'post'
    wizard.onWizardTypeChange()

    expect(wizard.wizardForm.resource_type_label).toBe('Posts')
    expect(wizard.canAdvanceWizard.value).toBe(true)
  })

  it('ignores a stale resource response after a newer search wins', async () => {
    vi.useFakeTimers()
    const first = deferred()
    const second = deferred()
    const { wizard } = createWizard({
      searchResources: vi.fn()
        .mockReturnValueOnce(first.promise)
        .mockReturnValueOnce(second.promise),
    })
    wizard.wizardForm.resource_type = 'post'

    const oldSearch = wizard.searchResources('old')
    await vi.advanceTimersByTimeAsync(250)
    const newSearch = wizard.searchResources('new')
    await vi.advanceTimersByTimeAsync(250)
    second.resolve({ data: [{ id: '2', label: 'New result' }] })
    await newSearch
    first.resolve({ data: [{ id: '1', label: 'Old result' }] })
    await oldSearch

    expect(wizard.resourceOptions.value).toEqual([{ id: '2', label: 'New result' }])
    expect(wizard.resourceSearchLoading.value).toBe(false)
    vi.useRealTimers()
  })

  it('loads browse results and clears old errors for a blank query', async () => {
    const browseResults = [{ id: '1', label: 'Recent result' }]
    const { api, wizard } = createWizard({
      searchResources: vi.fn().mockResolvedValue({ data: browseResults }),
    })
    wizard.wizardForm.resource_type = 'post'
    wizard.resourceOptions.value = [{ id: '1', label: 'Old result' }]
    wizard.resourceSearchError.value = 'Old failure'

    await wizard.searchResources('   ')

    expect(api.searchResources).toHaveBeenCalledWith({ type: 'post', query: '' })
    expect(wizard.resourceOptions.value).toEqual(browseResults)
    expect(wizard.resourceSearchError.value).toBe('')
  })

  it('debounces remote searches and reuses cached query results', async () => {
    vi.useFakeTimers()
    const results = [{ id: '2', label: 'Designing Forms' }]
    const { api, wizard } = createWizard({
      searchResources: vi.fn().mockResolvedValue({ data: results }),
    })
    wizard.wizardForm.resource_type = 'post'

    const firstSearch = wizard.searchResources('design')
    expect(api.searchResources).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(249)
    expect(api.searchResources).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(1)
    await firstSearch
    expect(api.searchResources).toHaveBeenCalledTimes(1)
    expect(api.searchResources).toHaveBeenCalledWith({ type: 'post', query: 'design' })

    await wizard.searchResources('design')
    expect(api.searchResources).toHaveBeenCalledTimes(1)
    expect(wizard.resourceOptions.value).toEqual(results)
    vi.useRealTimers()
  })

  it('keeps initial browse results for a one-character query', async () => {
    const browseResults = [{ id: '3', label: 'Business' }]
    const { api, wizard } = createWizard({
      searchResources: vi.fn().mockResolvedValue({ data: browseResults }),
    })
    wizard.wizardForm.resource_type = 'category'

    await wizard.searchResources('')
    await wizard.searchResources('b')

    expect(api.searchResources).toHaveBeenCalledTimes(1)
    expect(api.searchResources).toHaveBeenCalledWith({ type: 'category', query: '' })
    expect(wizard.resourceOptions.value).toEqual(browseResults)
  })

  it('preserves the selected resource while displaying new search results', async () => {
    vi.useFakeTimers()
    const { wizard } = createWizard({
      searchResources: vi.fn().mockResolvedValue({
        data: [{ id: '9', label: 'New result' }],
      }),
    })
    wizard.wizardForm.resource_type = 'post'
    wizard.wizardForm.resource_id = '5'
    wizard.resourceOptions.value = [{ id: '5', label: 'Selected result' }]

    const search = wizard.searchResources('new')
    await vi.advanceTimersByTimeAsync(250)
    await search

    expect(wizard.resourceOptions.value).toEqual([
      { id: '5', label: 'Selected result' },
      { id: '9', label: 'New result' },
    ])
    vi.useRealTimers()
  })

  it('shows a useful search error without retaining stale results', async () => {
    const { wizard } = createWizard({
      searchResources: vi.fn().mockRejectedValue(new Error('Search service unavailable')),
    })
    wizard.wizardForm.resource_type = 'post'
    wizard.resourceOptions.value = [{ id: '1', label: 'Old result' }]

    await wizard.searchResources('members')

    expect(wizard.resourceOptions.value).toEqual([])
    expect(wizard.resourceSearchError.value).toBe('Search service unavailable')
    expect(wizard.resourceSearchLoading.value).toBe(false)
  })

  it('submits once during rapid duplicate actions and preserves the payload', async () => {
    const mutation = deferred()
    const { api, fetchContent, wizard } = createWizard({
      protect: vi.fn().mockReturnValue(mutation.promise),
    })
    Object.assign(wizard.wizardForm, {
      resource_type: 'post',
      resource_id: '55',
      plan_ids: [5],
      show_teaser: 'yes',
      restriction_message: 'Members only',
      redirect_url: 'https://example.com/join',
    })

    const first = wizard.submitProtect()
    const second = wizard.submitProtect()

    expect(api.protect).toHaveBeenCalledTimes(1)
    expect(api.protect).toHaveBeenCalledWith({
      resource_type: 'post',
      resource_id: '55',
      plan_ids: [5],
      show_teaser: 'yes',
      restriction_message: 'Members only',
      redirect_url: 'https://example.com/join',
    })

    mutation.resolve({ data: {} })
    await Promise.all([first, second])

    expect(fetchContent).toHaveBeenCalledTimes(1)
    expect(wizard.wizardVisible.value).toBe(false)
    expect(wizard.protectLoading.value).toBe(false)
  })
})
