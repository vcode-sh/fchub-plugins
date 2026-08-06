import { computed, ref, watch } from 'vue'
import {
  addBlock,
  deliveryOptions,
  groupNotifications,
  moveBlock,
  newBlock,
} from '@/components/settings/notificationStudioUi.js'

function clone(value) {
  return JSON.parse(JSON.stringify(value))
}

export function useNotificationStudio({ form, standalone, api, messages }) {
  const loading = ref(false)
  const loadError = ref('')
  const notifications = ref([])
  const fluentcrmAvailable = ref(false)
  const catalogReady = ref(false)
  const catalogTheme = ref(null)
  const brandTemplate = ref({})
  const editing = ref(null)
  const editingBrand = ref(false)
  const draft = ref(null)
  const draftTheme = ref(null)
  const useGlobalTemplate = ref(true)
  const previewHtml = ref('')
  const previewSubject = ref('')
  const previewError = ref('')
  const previewing = ref(false)
  const previewDevice = ref('desktop')
  const testing = ref(false)
  const savingBrand = ref(false)
  const savingEmail = ref(false)
  let previewTimer = null
  let disposed = false

  const notificationGroups = computed(() => groupNotifications(notifications.value))
  const availableDeliveryOptions = computed(() => deliveryOptions(fluentcrmAvailable.value))
  const activeCount = computed(() => notifications.value.filter((notification) => currentDelivery(notification) !== 'off').length)
  const richVariables = computed(() => Object.entries(editing.value?.variables ?? {})
    .filter(([, config]) => config.type === 'rich')
    .map(([value, config]) => ({ value, label: config.label })))
  const globalVariables = computed(() => ({
    '{site_name}': { label: 'Site name', type: 'text' },
  }))

  function currentDelivery(notification) {
    const saved = form.email_delivery?.[notification.key]
    if (saved === 'fluentcrm' && !fluentcrmAvailable.value) return 'built_in'
    if (saved) return saved
    return form[notification.setting_key] === false ? 'off' : notification.delivery
  }

  function templateFor(notification) {
    const saved = form.email_templates?.[notification.key]
    return saved && typeof saved === 'object' ? saved : notification.template
  }

  function setDeliveryLocal(notification, delivery) {
    form.email_delivery = { ...(form.email_delivery ?? {}), [notification.key]: delivery }
    form[notification.setting_key] = delivery === 'built_in'
  }

  async function setDelivery(notification, delivery) {
    const previous = currentDelivery(notification)
    setDeliveryLocal(notification, delivery)
    if (!standalone) return

    try {
      await api.saveEmail(notification.key, {
        key: notification.key,
        delivery,
        template: templateFor(notification),
        theme_override: notification.theme_override ?? null,
      })
      notification.delivery = delivery
      messages.success(`${notification.label} delivery updated.`)
    } catch (error) {
      setDeliveryLocal(notification, previous)
      messages.error(error.message || 'Delivery could not be updated.')
    }
  }

  async function loadCatalog() {
    loading.value = true
    loadError.value = ''
    try {
      const response = await api.emailNotifications()
      const data = response.data ?? response
      notifications.value = data.notifications ?? []
      catalogTheme.value = data.theme ?? null
      brandTemplate.value = clone(data.brand_template ?? data.theme ?? {})
      fluentcrmAvailable.value = Boolean(data.fluentcrm_available)
      if (standalone) {
        form.email_theme = clone(brandTemplate.value)
        form.email_templates = {}
        form.email_delivery = {}
        notifications.value.forEach((notification) => {
          form.email_templates[notification.key] = clone(notification.template)
          form.email_delivery[notification.key] = notification.delivery
          form[notification.setting_key] = notification.delivery === 'built_in'
        })
      }
      catalogReady.value = true
    } catch (error) {
      loadError.value = error.message || 'Email notifications could not be loaded.'
    } finally {
      loading.value = false
    }
  }

  function openEditor(notification) {
    const group = notificationGroups.value.find(({ key }) => key === notification.group)
    editing.value = { ...notification, groupLabel: group?.label ?? 'Notification' }
    draft.value = {
      key: notification.key,
      delivery: currentDelivery(notification),
      template: clone(templateFor(notification)),
    }
    useGlobalTemplate.value = !notification.theme_override
    draftTheme.value = clone(notification.theme_override ?? brandTemplate.value ?? catalogTheme.value ?? {})
    previewDevice.value = 'desktop'
    schedulePreview(true)
  }

  function cancelEditor() {
    editing.value = null
    draft.value = null
    draftTheme.value = null
    previewHtml.value = ''
    previewError.value = ''
  }

  async function applyEditor() {
    savingEmail.value = true
    try {
      const payload = {
        key: editing.value.key,
        delivery: draft.value.delivery,
        template: draft.value.template,
        theme_override: useGlobalTemplate.value ? null : draftTheme.value,
      }
      await api.saveEmail(editing.value.key, payload)
      form.email_templates = { ...(form.email_templates ?? {}), [editing.value.key]: clone(draft.value.template) }
      setDeliveryLocal(editing.value, draft.value.delivery)
      const notification = notifications.value.find(({ key }) => key === editing.value.key)
      if (notification) {
        notification.template = clone(draft.value.template)
        notification.delivery = draft.value.delivery
        notification.theme_override = useGlobalTemplate.value ? null : clone(draftTheme.value)
      }
      cancelEditor()
      messages.success('Email saved and ready for delivery.')
    } catch (error) {
      messages.error(error.message || 'The email could not be saved.')
    } finally {
      savingEmail.value = false
    }
  }

  function resetDraft() {
    draft.value.template = clone(editing.value.default_template ?? editing.value.template)
    messages.info('Default content restored in this draft.')
  }

  function appendBlock(type) {
    draft.value.template.blocks = addBlock(draft.value.template.blocks, newBlock(type))
  }

  function reorderBlock(index, direction) {
    draft.value.template.blocks = moveBlock(draft.value.template.blocks, index, direction)
  }

  function deleteBlock(index) {
    if (draft.value.template.blocks.length === 1) return
    draft.value.template.blocks.splice(index, 1)
  }

  function insertFieldVariable(field, variable) {
    draft.value.template[field] = `${draft.value.template[field] ?? ''}${variable}`
  }

  function schedulePreview(immediate = false) {
    if (disposed) return
    clearTimeout(previewTimer)
    previewTimer = setTimeout(renderPreview, immediate ? 0 : 350)
  }

  async function renderPreview() {
    if (!draft.value && !editingBrand.value) return
    previewing.value = true
    previewError.value = ''
    try {
      const previewNotification = editingBrand.value ? notifications.value[0] : editing.value
      const response = await api.previewEmail({
        key: previewNotification?.key ?? 'access_granted',
        template: editingBrand.value ? previewNotification?.template : draft.value.template,
        theme: editingBrand.value ? draftTheme.value : brandTemplate.value,
        theme_override: editingBrand.value || useGlobalTemplate.value ? null : draftTheme.value,
      })
      const data = response.data ?? response
      previewHtml.value = data.html ?? ''
      previewSubject.value = data.subject ?? ''
    } catch (error) {
      previewError.value = error.message || 'The delivery preview could not be rendered.'
    } finally {
      previewing.value = false
    }
  }

  async function sendTest(address) {
    const testAddress = String(address ?? '').trim()
    if (!testAddress) {
      messages.warning('Enter an email address for the test.')
      return
    }
    testing.value = true
    try {
      await api.testEmail({
        key: draft.value.key,
        template: draft.value.template,
        theme: brandTemplate.value,
        theme_override: useGlobalTemplate.value ? null : draftTheme.value,
        to: testAddress,
      })
      messages.success(`Test email sent to ${testAddress}.`)
    } catch (error) {
      messages.error(error.message || 'The test email could not be sent.')
    } finally {
      testing.value = false
    }
  }

  function openBrandEditor() {
    editingBrand.value = true
    draftTheme.value = clone(brandTemplate.value)
    previewDevice.value = 'desktop'
    schedulePreview(true)
  }

  function cancelBrandEditor() {
    editingBrand.value = false
    draftTheme.value = null
    previewHtml.value = ''
    previewError.value = ''
  }

  async function saveBrandEditor() {
    savingBrand.value = true
    try {
      const response = await api.saveEmailBrandTemplate({ theme: draftTheme.value })
      const data = response.data ?? response
      brandTemplate.value = clone(data.brand_template ?? draftTheme.value)
      form.email_theme = clone(brandTemplate.value)
      cancelBrandEditor()
      messages.success('Global email brand template saved.')
    } catch (error) {
      messages.error(error.message || 'The brand template could not be saved.')
    } finally {
      savingBrand.value = false
    }
  }

  function selectMedia(onSelect) {
    if (!window.wp?.media) {
      messages.error('The WordPress Media Library is unavailable. Paste an image URL instead.')
      return
    }
    const frame = window.wp.media({
      title: 'Choose email image',
      button: { text: 'Use this image' },
      library: { type: 'image' },
      multiple: false,
    })
    frame.on('select', () => onSelect(frame.state().get('selection').first()?.toJSON()))
    frame.open()
  }

  function openMediaLibrary(field) {
    selectMedia((attachment) => {
      if (attachment?.url) draftTheme.value[field] = attachment.url
    })
  }

  function openMediaLibraryForBlock(block) {
    selectMedia((attachment) => {
      if (attachment?.url) {
        block.url = attachment.url
        if (!block.alt && attachment.alt) block.alt = attachment.alt
      }
    })
  }

  watch([draft, draftTheme, useGlobalTemplate], () => {
    if (editing.value || editingBrand.value) schedulePreview()
  }, { deep: true })

  function dispose() {
    disposed = true
    clearTimeout(previewTimer)
  }

  return {
    activeCount,
    applyEditor,
    appendBlock,
    availableDeliveryOptions,
    brandTemplate,
    cancelBrandEditor,
    cancelEditor,
    catalogReady,
    currentDelivery,
    deleteBlock,
    dispose,
    draft,
    draftTheme,
    editing,
    editingBrand,
    fluentcrmAvailable,
    globalVariables,
    insertFieldVariable,
    loadCatalog,
    loadError,
    loading,
    notificationGroups,
    notifications,
    openBrandEditor,
    openEditor,
    openMediaLibrary,
    openMediaLibraryForBlock,
    previewDevice,
    previewError,
    previewHtml,
    previewing,
    previewSubject,
    reorderBlock,
    resetDraft,
    richVariables,
    saveBrandEditor,
    savingBrand,
    savingEmail,
    schedulePreview,
    sendTest,
    setDelivery,
    templateFor,
    testing,
    useGlobalTemplate,
  }
}
