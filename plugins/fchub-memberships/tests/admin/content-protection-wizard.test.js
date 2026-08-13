import { computed, ref } from 'vue'
import { mount } from '@vue/test-utils'
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
import ContentProtectionWizard from '@/components/content/ContentProtectionWizard.vue'
import ContentProtectionWizardProgress from '@/components/content/wizard/ContentProtectionWizardProgress.vue'
import ContentProtectionWizardCategoryStep from '@/components/content/wizard/ContentProtectionWizardCategoryStep.vue'
import ContentProtectionWizardResourceStep from '@/components/content/wizard/ContentProtectionWizardResourceStep.vue'
import ContentProtectionWizardAccessStep from '@/components/content/wizard/ContentProtectionWizardAccessStep.vue'
import ContentProtectionWizardReviewStep from '@/components/content/wizard/ContentProtectionWizardReviewStep.vue'

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
  it('composes the guided journey from isolated step components', () => {
    const mountWizard = (step) => mount(ContentProtectionWizard, {
      props: {
        visible: true,
        step,
        form: {
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
        },
        categoryCards: [],
        categoryTypes: [],
        resourceLoading: false,
        resourceError: '',
        resourceOptions: [],
        planOptionsLoading: false,
        planOptions: [],
        planOptionsMap: {},
        resourceDisplayName: '',
        canAdvance: false,
        saving: false,
        searchResources: () => undefined,
      },
      global: {
        stubs: {
          ElDialog: { template: '<section><slot /><slot name="footer" /></section>' },
        },
      },
    })

    const category = mountWizard(0)
    const resource = mountWizard(1)
    const access = mountWizard(2)
    const review = mountWizard(3)

    expect(category.findComponent(ContentProtectionWizardProgress).exists()).toBe(true)
    expect(category.findComponent(ContentProtectionWizardCategoryStep).exists()).toBe(true)
    expect(resource.findComponent(ContentProtectionWizardResourceStep).exists()).toBe(true)
    expect(access.findComponent(ContentProtectionWizardAccessStep).exists()).toBe(true)
    expect(review.findComponent(ContentProtectionWizardReviewStep).exists()).toBe(true)
  })

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

  it('requires a subtype for a multi-type category and drops the stale selection', () => {
    const { wizard } = createWizard()
    wizard.wizardForm.resource_type = 'page'
    wizard.wizardForm.resource_id = '91'
    wizard.wizardForm.resource_label = 'Old Page'

    wizard.selectWizardCategory({ key: 'posts_pages', label: 'Posts & Pages' })

    expect(wizard.wizardForm.resource_type).toBe('')
    expect(wizard.wizardForm.resource_id).toBe('')
    expect(wizard.wizardForm.resource_label).toBeNull()
    expect(wizard.canAdvanceWizard.value).toBe(false)

    wizard.wizardForm.resource_type = 'post'
    wizard.onWizardTypeChange()

    expect(wizard.wizardForm.resource_type_label).toBe('Posts')
    expect(wizard.canAdvanceWizard.value).toBe(true)
  })

  it('names the chosen resource on review instead of echoing its id', () => {
    const { wizard } = createWizard()
    wizard.wizardForm.resource_type = 'post'
    wizard.wizardForm.resource_id = '91'

    expect(wizard.wizardResourceDisplayName.value).toBe('91')

    wizard.wizardForm.resource_label = 'Getting Started'
    expect(wizard.wizardResourceDisplayName.value).toBe('Getting Started')
  })

  it('reads a URL pattern straight from the field it was typed into', () => {
    const { wizard } = createWizard()
    wizard.wizardForm.resource_type = 'url_pattern'
    wizard.wizardForm.resource_id = '/members/*'

    expect(wizard.wizardResourceDisplayName.value).toBe('/members/*')
  })

  it('labels the comment wildcard and clears it when scoping to one post', () => {
    const { wizard } = createWizard()
    wizard.wizardForm.resource_type = 'comment'
    wizard.wizardForm.commentMode = 'all'
    wizard.onCommentModeChange()

    expect(wizard.wizardForm.resource_id).toBe('*')
    expect(wizard.wizardResourceDisplayName.value).toBe('All Protected Content Comments')

    wizard.wizardForm.commentMode = 'specific'
    wizard.onCommentModeChange()

    expect(wizard.wizardForm.resource_id).toBe('')
    expect(wizard.wizardForm.resource_label).toBeNull()
  })

  it('loads the fixed special-page list once the type is chosen', async () => {
    const searchResources = vi.fn().mockResolvedValue({
      data: [{ id: 'blog', label: 'Blog / Posts Page' }],
    })
    const { wizard } = createWizard({ searchResources })
    wizard.wizardForm.resource_type = 'special_page'

    wizard.onWizardTypeChange()
    await Promise.resolve()

    expect(searchResources).toHaveBeenCalledWith({ type: 'special_page', query: '' })
    expect(wizard.specialPages.value).toEqual([{ id: 'blog', label: 'Blog / Posts Page' }])
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
