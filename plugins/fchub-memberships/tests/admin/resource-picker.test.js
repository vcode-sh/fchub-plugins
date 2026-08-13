import { nextTick } from 'vue'
import { mount } from '@vue/test-utils'
import { ElSelect } from 'element-plus'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import ResourcePicker from '@/components/content/ResourcePicker.vue'
import { content } from '@/api/index.js'

vi.mock('@/api/index.js', () => ({
  content: { searchResources: vi.fn() },
}))

const ROWS = [
  { id: '7', label: 'Checkout', status: 'draft', type_label: 'Pages' },
  { id: '8', label: 'Pricing', status: 'publish', type_label: 'Pages' },
]

function mountPicker(props = {}) {
  return mount(ResourcePicker, {
    props: {
      resourceType: 'page',
      typeLabel: 'Pages',
      modelValue: '',
      label: null,
      'onUpdate:modelValue': (value) => wrapper.setProps({ modelValue: value }),
      'onUpdate:label': (value) => wrapper.setProps({ label: value }),
      ...props,
    },
    attachTo: document.body,
  })
}

let wrapper

async function openDropdown() {
  wrapper.findComponent(ElSelect).vm.$emit('visible-change', true)
  await nextTick()
  await nextTick()
}

describe('resource picker', () => {
  beforeEach(() => {
    content.searchResources.mockReset().mockResolvedValue({ data: ROWS })
  })

  afterEach(() => {
    wrapper?.unmount()
    document.body.innerHTML = ''
  })

  it('records the title alongside the id so nothing downstream sees a bare number', async () => {
    wrapper = mountPicker()
    await openDropdown()

    wrapper.findComponent(ElSelect).vm.$emit('update:modelValue', '7')
    await nextTick()

    expect(wrapper.props('modelValue')).toBe('7')
    expect(wrapper.props('label')).toBe('Checkout')
  })

  it('clears both halves together', async () => {
    wrapper = mountPicker({ modelValue: '7', label: 'Checkout' })
    await openDropdown()

    wrapper.findComponent(ElSelect).vm.$emit('update:modelValue', '')
    await nextTick()

    expect(wrapper.props('modelValue')).toBe('')
    expect(wrapper.props('label')).toBeNull()
  })

  it('badges only the statuses that explain themselves', async () => {
    wrapper = mountPicker()
    await openDropdown()

    const rows = document.querySelectorAll('.resource-picker-popper .el-select-dropdown__item')
    expect(rows).toHaveLength(2)
    expect(rows[0].textContent).toContain('Pages · Draft')
    expect(rows[1].textContent).toContain('Pages')
    expect(rows[1].textContent).not.toContain('·')
  })

  it('offers the all-of-type sentinel and names it when chosen', async () => {
    wrapper = mountPicker({ allowAll: true })
    await openDropdown()

    wrapper.findComponent(ElSelect).vm.$emit('update:modelValue', '0')
    await nextTick()

    expect(wrapper.props('label')).toBe('All of this type')
    expect(wrapper.findComponent(ElSelect).props('clearable')).toBe(false)
  })

  it('shows a saved selection without waiting for a search', () => {
    wrapper = mountPicker({ modelValue: '7', label: 'Checkout' })

    expect(content.searchResources).not.toHaveBeenCalled()
    expect(wrapper.findComponent(ElSelect).vm.selectedLabel).toBe('Checkout')
  })
})
