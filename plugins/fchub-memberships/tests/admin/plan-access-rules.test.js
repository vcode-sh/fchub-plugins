import { reactive } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import { usePlanAccessRules } from '@/composables/plans/usePlanAccessRules.js'

function setup({ contentApi = {}, initialRules = [] } = {}) {
  const form = reactive({ rules: initialRules })
  const messages = {
    info: vi.fn(),
    success: vi.fn(),
  }
  const access = usePlanAccessRules({
    contentApi: {
      resourceTypes: vi.fn().mockResolvedValue([]),
      spaceGroups: vi.fn().mockResolvedValue([]),
      ...contentApi,
    },
    rules: () => form.rules,
    messageApi: messages,
  })

  return { access, form, messages }
}

function validate(rule, value) {
  return new Promise((resolve) => {
    rule.validator({}, value, (error) => resolve(error))
  })
}

describe('plan access rules', () => {
  it('adds, updates and removes an editable rule', () => {
    const { access, form } = setup()

    access.addRule()
    expect(form.rules).toEqual([{
      resource_type: 'post',
      resource_id: '0',
      resource_label: null,
      drip_type: 'immediate',
      drip_delay_days: null,
      drip_date: null,
    }])

    form.rules[0].drip_type = 'delayed'
    access.onDripTypeChange(form.rules[0])
    expect(form.rules[0]).toMatchObject({ drip_delay_days: 1, drip_date: null })

    access.removeRule(0)
    expect(form.rules).toEqual([])
  })

  it('does not mutate a rule set containing a read-only legacy rule', () => {
    const locked = { resource_type: 'sfwd-lessons', resource_id: '5', read_only: true, drip_type: 'immediate' }
    const { access, form } = setup({ initialRules: [locked] })

    access.addRule()
    access.removeRule(0)
    locked.drip_type = 'delayed'
    access.onDripTypeChange(locked)

    expect(access.hasReadOnlyRules.value).toBe(true)
    expect(form.rules).toEqual([{ ...locked, drip_delay_days: undefined }])
  })

  it('normalises provider capabilities and preserves the approved group order', async () => {
    const resourceTypes = vi.fn().mockResolvedValue({
      data: [
        { key: 'category', label: 'Categories', group: 'taxonomy', allow_all: true },
        { key: 'fc_space', label: 'Spaces', group: 'content', source: 'FluentCommunity', searchable: false, allow_all: false, identifier: 'positive_int' },
      ],
      groups: { content: 'Provider content' },
    })
    const { access } = setup({ contentApi: { resourceTypes } })

    await access.loadResourceTypes()

    expect(access.resourceTypeGroups.value).toEqual([
      {
        key: 'content',
        label: 'Provider content',
        types: [{
          value: 'fc_space',
          label: 'Spaces',
          source: 'FluentCommunity',
          searchable: false,
          allow_all: false,
          identifier: 'positive_int',
          displayLabel: 'Spaces (FluentCommunity)',
        }],
      },
      {
        key: 'taxonomy',
        label: 'Taxonomy',
        types: [{
          value: 'category',
          label: 'Categories',
          source: '',
          searchable: true,
          allow_all: true,
          identifier: 'positive_int',
          displayLabel: 'Categories',
        }],
      },
    ])
    expect(access.hasFcSpaceResourceType.value).toBe(true)
  })

  it('uses safe core resource capabilities when discovery fails', async () => {
    const { access } = setup({
      contentApi: { resourceTypes: vi.fn().mockRejectedValue(new Error('offline')) },
    })

    await access.loadResourceTypes()

    expect(access.resourceTypeGroups.value.map(({ key }) => key)).toEqual(['content', 'taxonomy'])
    expect(access.getTypeConfig('post')).toMatchObject({ value: 'post', label: 'Posts' })
  })

  it('validates provider identifiers according to discovered capabilities', async () => {
    const { access } = setup()
    access.resourceTypeGroups.value = [{
      key: 'content',
      label: 'Content',
      types: [
        { value: 'course', allow_all: false, identifier: 'positive_int' },
        { value: 'collection', allow_all: false, identifier: 'slug' },
        { value: 'post', allow_all: true, identifier: 'positive_int' },
      ],
    }]

    expect(access.resourceIdRules({ resource_type: 'post' })).toEqual([])
    expect(await validate(access.resourceIdRules({ resource_type: 'course' })[0], '7')).toBeUndefined()
    expect(await validate(access.resourceIdRules({ resource_type: 'course' })[0], 'slug')).toBeInstanceOf(Error)
    expect(await validate(access.resourceIdRules({ resource_type: 'collection' })[0], 'gold-plan')).toBeUndefined()
    expect(await validate(access.resourceIdRules({ resource_type: 'collection' })[0], '123')).toBeInstanceOf(Error)
  })

  it('resets resource values according to the selected capability', () => {
    const { access } = setup()
    access.resourceTypeGroups.value = [{
      key: 'advanced',
      label: 'Advanced',
      types: [
        { value: 'post', allow_all: true },
        { value: 'course', allow_all: false },
        { value: 'url_pattern', allow_all: false },
      ],
    }]
    const rule = { resource_type: 'post', resource_id: '7', resource_label: 'Old' }

    access.onResourceTypeChange(rule)
    expect(rule).toMatchObject({ resource_id: '0', resource_label: null })
    rule.resource_type = 'course'
    access.onResourceTypeChange(rule)
    expect(rule.resource_id).toBe('')
    rule.resource_type = 'url_pattern'
    access.onResourceTypeChange(rule)
    expect(rule.resource_id).toBe('')
  })

  it('loads Space Groups and appends only new spaces', async () => {
    const spaceGroups = vi.fn().mockResolvedValue({ data: [{
      id: 9,
      label: 'Community',
      spaces: [{ id: 2, label: 'Start' }, { id: 3, label: 'News' }],
    }] })
    const { access, form, messages } = setup({
      contentApi: { spaceGroups },
      initialRules: [{ resource_type: 'fc_space', resource_id: '2', resource_label: 'Start' }],
    })

    await access.loadSpaceGroups()
    access.addSelectedSpaceGroup('9')

    expect(spaceGroups).toHaveBeenCalledWith({ search: '' })
    expect(form.rules.at(-1)).toMatchObject({ resource_type: 'fc_space', resource_id: '3', resource_label: 'News' })
    expect(messages.success).toHaveBeenCalledWith('Added 1 Space from Community')
  })

  it('leaves surviving rules holding their own titles when an earlier rule goes', () => {
    const { access, form } = setup({
      initialRules: [
        { resource_type: 'post', resource_id: '7', resource_label: 'Checkout', drip_type: 'immediate' },
        { resource_type: 'post', resource_id: '8', resource_label: 'Pricing', drip_type: 'immediate' },
      ],
    })

    access.removeRule(0)

    expect(form.rules).toHaveLength(1)
    expect(form.rules[0]).toMatchObject({ resource_id: '8', resource_label: 'Pricing' })
    expect(access.ruleSummary(form.rules[0])).toContain('Pricing')
  })

  it('summarises a rule by the title the member picked, not its id', () => {
    const { access } = setup()
    access.resourceTypeGroups.value = [{
      key: 'content',
      label: 'Content',
      types: [{ value: 'post', label: 'Posts', displayLabel: 'Posts', allow_all: true }],
    }]

    expect(access.ruleSummary({
      resource_type: 'post',
      resource_id: '7',
      resource_label: 'Checkout',
      drip_type: 'delayed',
      drip_delay_days: 3,
    })).toBe('Posts · Checkout · after 3 days')

    expect(access.ruleSummary({
      resource_type: 'post',
      resource_id: '0',
      resource_label: null,
      drip_type: 'immediate',
    })).toBe('Posts · all of this type · immediately')
  })
})
