import { ref } from 'vue'

function resolveValue(value) {
  return typeof value === 'function' ? value() : value
}

export function usePlanSlugPreview({
  plansApi,
  form,
  formRef,
  isNew,
  planId,
  setTimer = setTimeout,
  clearTimer = clearTimeout,
  delay = 250,
}) {
  const slugManuallyEdited = ref(false)
  const slugPreviewLoading = ref(false)
  const slugPreviewError = ref('')
  const slugAvailable = ref(true)
  let previewTimer = null
  let previewSequence = 0

  async function previewSlug(title, customSlug = null) {
    const sequence = ++previewSequence
    slugPreviewLoading.value = true

    try {
      const response = await plansApi.previewSlug({
        title,
        slug: customSlug,
        exclude_id: resolveValue(isNew) ? null : resolveValue(planId),
      })
      if (sequence !== previewSequence) return

      const preview = response.data ?? response
      form.slug = preview.slug || ''
      slugAvailable.value = preview.available !== false
      slugPreviewError.value = ''
      formRef.value?.validateField('slug').catch(() => {})
    } catch (error) {
      if (sequence !== previewSequence) return

      form.slug = ''
      slugAvailable.value = false
      slugPreviewError.value = error.message || 'WordPress could not generate a slug'
    } finally {
      if (sequence === previewSequence) slugPreviewLoading.value = false
    }
  }

  function scheduleSlugPreview(title, customSlug = null) {
    if (previewTimer !== null) clearTimer(previewTimer)
    slugPreviewError.value = ''

    if (!String(title || '').trim() && !String(customSlug || '').trim()) {
      previewTimer = null
      form.slug = ''
      slugAvailable.value = false
      return
    }

    slugPreviewLoading.value = true
    previewTimer = setTimer(() => {
      previewTimer = null
      return previewSlug(title, customSlug)
    }, delay)
  }

  function onTitleInput(value) {
    if (!slugManuallyEdited.value) {
      scheduleSlugPreview(value)
    }
  }

  function onSlugInput(value) {
    slugManuallyEdited.value = String(value || '').trim() !== ''
    scheduleSlugPreview(form.title, slugManuallyEdited.value ? value : null)
  }

  async function flushSlugPreview() {
    if (previewTimer === null) return

    clearTimer(previewTimer)
    previewTimer = null
    await previewSlug(form.title, slugManuallyEdited.value ? form.slug : null)
  }

  function markPersistedSlug() {
    slugManuallyEdited.value = true
  }

  return {
    slugManuallyEdited,
    slugPreviewLoading,
    slugPreviewError,
    slugAvailable,
    onTitleInput,
    onSlugInput,
    flushSlugPreview,
    markPersistedSlug,
  }
}
