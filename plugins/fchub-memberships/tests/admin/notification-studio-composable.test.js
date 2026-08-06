import { nextTick, reactive } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { useNotificationStudio } from '../../resources/admin/composables/settings/useNotificationStudio.js'

const notification = {
  key: 'access_granted',
  group: 'access',
  label: 'Access granted',
  setting_key: 'email_access_granted',
  delivery: 'built_in',
  template: { subject: 'Welcome', preheader: 'Ready', blocks: [{ id: 'copy', type: 'rich_text', content: '<p>Welcome</p>' }] },
  default_template: { subject: 'Default', preheader: '', blocks: [{ id: 'copy', type: 'rich_text', content: '<p>Default</p>' }] },
  variables: {},
  theme_override: null,
}

function createStudio({ api = {}, standalone = true } = {}) {
  const form = reactive({ email_templates: {}, email_delivery: {}, email_theme: {} })
  const messages = { success: vi.fn(), error: vi.fn(), info: vi.fn(), warning: vi.fn() }
  const studio = useNotificationStudio({
    form,
    standalone,
    api: {
      emailNotifications: vi.fn().mockResolvedValue({
        data: {
          notifications: [structuredClone(notification)],
          brand_template: { primary_color: '#2563eb' },
          fluentcrm_available: false,
        },
      }),
      previewEmail: vi.fn().mockResolvedValue({
        data: { html: '<p>Preview</p>', subject: 'Preview subject' },
      }),
      saveEmail: vi.fn().mockResolvedValue({}),
      testEmail: vi.fn().mockResolvedValue({}),
      saveEmailBrandTemplate: vi.fn().mockResolvedValue({
        data: { brand_template: { primary_color: '#7c3aed' } },
      }),
      ...api,
    },
    messages,
  })
  return { form, messages, studio }
}

afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

describe('notification studio composable', () => {
  it('hydrates the standalone draft form from the direct notification catalogue response', async () => {
    const { form, studio } = createStudio()

    await studio.loadCatalog()

    expect(form.email_delivery).toEqual({ access_granted: 'built_in' })
    expect(form.email_templates.access_granted).toEqual(notification.template)
    expect(form.email_theme).toEqual({ primary_color: '#2563eb' })
    expect(form.email_access_granted).toBe(true)
  })

  it('rolls back an optimistic standalone delivery change when the direct save rejects', async () => {
    const saveEmail = vi.fn().mockRejectedValue(new Error('Direct save failed'))
    const { form, messages, studio } = createStudio({ api: { saveEmail } })
    await studio.loadCatalog()

    await studio.setDelivery(studio.notifications.value[0], 'off')

    expect(form.email_delivery.access_granted).toBe('built_in')
    expect(form.email_access_granted).toBe(true)
    expect(messages.error).toHaveBeenCalledWith('Direct save failed')
  })

  it('waits 350 ms before rendering a changed email preview', async () => {
    vi.useFakeTimers()
    const previewEmail = vi.fn().mockResolvedValue({ data: { html: '<p>Preview</p>', subject: 'Preview subject' } })
    const { studio } = createStudio({ api: { previewEmail } })
    await studio.loadCatalog()
    studio.editing.value = notification
    studio.draft.value = { key: notification.key, delivery: 'built_in', template: structuredClone(notification.template) }
    studio.schedulePreview()

    await vi.advanceTimersByTimeAsync(349)
    expect(previewEmail).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(1)
    await nextTick()
    expect(previewEmail).toHaveBeenCalledWith(expect.objectContaining({ key: 'access_granted', template: notification.template }))
  })

  it('saves the edited email through the direct endpoint and applies the saved draft locally', async () => {
    vi.useFakeTimers()
    const saveEmail = vi.fn().mockResolvedValue({})
    const { form, studio } = createStudio({ api: { saveEmail } })
    await studio.loadCatalog()
    studio.openEditor(studio.notifications.value[0])
    studio.draft.value.delivery = 'off'
    studio.draft.value.template.subject = 'A deliberate subject'
    studio.useGlobalTemplate.value = false
    studio.draftTheme.value = { primary_color: '#db2777' }

    await studio.applyEditor()

    expect(saveEmail).toHaveBeenCalledWith('access_granted', {
      key: 'access_granted',
      delivery: 'off',
      template: {
        subject: 'A deliberate subject',
        preheader: 'Ready',
        blocks: [{ id: 'copy', type: 'rich_text', content: '<p>Welcome</p>' }],
      },
      theme_override: { primary_color: '#db2777' },
    })
    expect(form.email_templates.access_granted.subject).toBe('A deliberate subject')
    expect(form.email_delivery.access_granted).toBe('off')
    expect(studio.editing.value).toBeNull()
    studio.dispose()
  })

  it('saves the exact shared brand payload and hydrates the form from the response', async () => {
    vi.useFakeTimers()
    const saveEmailBrandTemplate = vi.fn().mockResolvedValue({
      data: { brand_template: { primary_color: '#7c3aed', content_width: 640 } },
    })
    const { form, studio } = createStudio({ api: { saveEmailBrandTemplate } })
    await studio.loadCatalog()
    studio.openBrandEditor()
    studio.draftTheme.value = { primary_color: '#7c3aed', content_width: 640 }

    await studio.saveBrandEditor()

    expect(saveEmailBrandTemplate).toHaveBeenCalledWith({
      theme: { primary_color: '#7c3aed', content_width: 640 },
    })
    expect(form.email_theme).toEqual({ primary_color: '#7c3aed', content_width: 640 })
    expect(studio.editingBrand.value).toBe(false)
    studio.dispose()
  })

  it('sends a trimmed test address with the current draft and brand contract', async () => {
    vi.useFakeTimers()
    const testEmail = vi.fn().mockResolvedValue({})
    const { messages, studio } = createStudio({ api: { testEmail } })
    await studio.loadCatalog()
    studio.openEditor(studio.notifications.value[0])

    await studio.sendTest('  owner@example.com  ')

    expect(testEmail).toHaveBeenCalledWith({
      key: 'access_granted',
      template: notification.template,
      theme: { primary_color: '#2563eb' },
      theme_override: null,
      to: 'owner@example.com',
    })
    expect(messages.success).toHaveBeenCalledWith('Test email sent to owner@example.com.')
    expect(studio.testing.value).toBe(false)
    studio.dispose()
  })

  it('keeps pasted URLs available when the WordPress Media Library is unavailable', () => {
    vi.stubGlobal('wp', undefined)
    const { messages, studio } = createStudio()
    const block = { url: 'https://example.com/pasted.jpg', alt: '' }

    studio.openMediaLibrary('logo_url')
    studio.openMediaLibraryForBlock(block)

    expect(block).toEqual({ url: 'https://example.com/pasted.jpg', alt: '' })
    expect(messages.error).toHaveBeenCalledTimes(2)
    expect(messages.error).toHaveBeenCalledWith(
      'The WordPress Media Library is unavailable. Paste an image URL instead.',
    )
  })

  it('keeps preview failures out of the catalogue error domain', async () => {
    vi.useFakeTimers()
    const previewEmail = vi.fn().mockRejectedValue(new Error('Preview service offline'))
    const { studio } = createStudio({ api: { previewEmail } })
    await studio.loadCatalog()
    studio.editing.value = notification
    studio.draft.value = {
      key: notification.key,
      delivery: 'built_in',
      template: structuredClone(notification.template),
    }

    studio.schedulePreview()
    await vi.advanceTimersByTimeAsync(350)

    expect(studio.previewError.value).toBe('Preview service offline')
    expect(studio.loadError.value).toBe('')
    expect(studio.previewing.value).toBe(false)
  })

  it('cancels a pending preview when the studio is disposed', async () => {
    vi.useFakeTimers()
    const previewEmail = vi.fn().mockResolvedValue({ data: { html: '', subject: '' } })
    const { studio } = createStudio({ api: { previewEmail } })
    studio.editing.value = notification
    studio.draft.value = {
      key: notification.key,
      delivery: 'built_in',
      template: structuredClone(notification.template),
    }

    studio.schedulePreview()
    studio.dispose()
    await vi.advanceTimersByTimeAsync(350)

    expect(previewEmail).not.toHaveBeenCalled()
  })
})
