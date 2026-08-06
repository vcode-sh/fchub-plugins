import { nextTick, reactive, ref } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import { usePlanSlugPreview } from '@/composables/plans/usePlanSlugPreview.js'

function deferred() {
  let resolve
  let reject
  const promise = new Promise((resolvePromise, rejectPromise) => {
    resolve = resolvePromise
    reject = rejectPromise
  })
  return { promise, resolve, reject }
}

function setup(previewSlug = vi.fn()) {
  const scheduled = []
  const form = reactive({ title: '', slug: '' })
  const validateField = vi.fn().mockResolvedValue(undefined)
  const slug = usePlanSlugPreview({
    plansApi: { previewSlug },
    form,
    formRef: ref({ validateField }),
    isNew: () => true,
    planId: () => null,
    setTimer: (callback) => {
      scheduled.push(callback)
      return callback
    },
    clearTimer: (callback) => {
      const index = scheduled.indexOf(callback)
      if (index >= 0) scheduled.splice(index, 1)
    },
  })

  return { form, previewSlug, scheduled, validateField, slug }
}

describe('plan slug preview', () => {
  it('previews a title automatically after the debounce', async () => {
    const previewSlug = vi.fn().mockResolvedValue({ data: { slug: 'gold-plan', available: true } })
    const { form, scheduled, validateField, slug } = setup(previewSlug)

    form.title = 'Gold Plan'
    slug.onTitleInput('Gold Plan')
    expect(slug.slugPreviewLoading.value).toBe(true)
    expect(scheduled).toHaveLength(1)

    await scheduled.shift()()

    expect(previewSlug).toHaveBeenCalledWith({ title: 'Gold Plan', slug: null, exclude_id: null })
    expect(form.slug).toBe('gold-plan')
    expect(slug.slugAvailable.value).toBe(true)
    expect(validateField).toHaveBeenCalledWith('slug')
  })

  it('uses the custom value and returns to automatic mode when cleared', async () => {
    const previewSlug = vi.fn()
      .mockResolvedValueOnce({ data: { slug: 'custom-plan', available: true } })
      .mockResolvedValueOnce({ data: { slug: 'gold-plan', available: true } })
    const { form, scheduled, slug } = setup(previewSlug)
    form.title = 'Gold Plan'

    slug.onSlugInput('custom-plan')
    expect(slug.slugManuallyEdited.value).toBe(true)
    await scheduled.shift()()

    slug.onSlugInput('')
    expect(slug.slugManuallyEdited.value).toBe(false)
    await scheduled.shift()()

    expect(previewSlug.mock.calls).toEqual([
      [{ title: 'Gold Plan', slug: 'custom-plan', exclude_id: null }],
      [{ title: 'Gold Plan', slug: null, exclude_id: null }],
    ])
    expect(form.slug).toBe('gold-plan')
  })

  it('clears an empty form without calling the server', () => {
    const { form, previewSlug, scheduled, slug } = setup()
    form.slug = 'old'

    slug.onTitleInput('')

    expect(form.slug).toBe('')
    expect(slug.slugAvailable.value).toBe(false)
    expect(scheduled).toHaveLength(0)
    expect(previewSlug).not.toHaveBeenCalled()
  })

  it('exposes the server error and clears an unusable slug', async () => {
    const previewSlug = vi.fn().mockRejectedValue(new Error('No usable characters.'))
    const { form, scheduled, slug } = setup(previewSlug)
    form.title = '---'

    slug.onTitleInput('---')
    await scheduled.shift()()

    expect(form.slug).toBe('')
    expect(slug.slugAvailable.value).toBe(false)
    expect(slug.slugPreviewError.value).toBe('No usable characters.')
    expect(slug.slugPreviewLoading.value).toBe(false)
  })

  it('flushes a pending preview before validation', async () => {
    const previewSlug = vi.fn().mockResolvedValue({ data: { slug: 'gold', available: true } })
    const { form, scheduled, slug } = setup(previewSlug)
    form.title = 'Gold'
    slug.onTitleInput('Gold')

    await slug.flushSlugPreview()

    expect(scheduled).toHaveLength(0)
    expect(previewSlug).toHaveBeenCalledTimes(1)
    expect(form.slug).toBe('gold')
  })

  it('ignores a stale response that resolves after a newer request', async () => {
    const first = deferred()
    const second = deferred()
    const previewSlug = vi.fn()
      .mockReturnValueOnce(first.promise)
      .mockReturnValueOnce(second.promise)
    const { form, scheduled, slug } = setup(previewSlug)

    form.title = 'First'
    slug.onTitleInput('First')
    const firstRequest = scheduled.shift()()
    form.title = 'Second'
    slug.onTitleInput('Second')
    const secondRequest = scheduled.shift()()

    second.resolve({ data: { slug: 'second', available: true } })
    await secondRequest
    first.resolve({ data: { slug: 'first', available: true } })
    await firstRequest
    await nextTick()

    expect(form.slug).toBe('second')
    expect(slug.slugPreviewLoading.value).toBe(false)
  })

  it('marks a persisted slug as manually controlled', () => {
    const { slug } = setup()
    slug.markPersistedSlug()
    expect(slug.slugManuallyEdited.value).toBe(true)
  })
})
