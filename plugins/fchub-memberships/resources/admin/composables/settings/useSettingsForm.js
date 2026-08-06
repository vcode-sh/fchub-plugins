import { computed, ref } from 'vue'

const EMAIL_NOTIFICATIONS = [
  ['access_granted', 'email_access_granted'],
  ['access_expiring', 'email_access_expiring'],
  ['access_revoked', 'email_access_revoked'],
  ['membership_paused', 'email_membership_paused'],
  ['membership_resumed', 'email_membership_resumed'],
  ['trial_expiring', 'email_trial_expiring'],
  ['trial_converted', 'email_trial_converted'],
  ['drip_content_unlocked', 'email_drip_unlocked'],
]

function defaultEmailTheme() {
  return {
    logo_url: '',
    primary_color: '#2563eb',
    background_color: '#f3f4f6',
    content_color: '#374151',
    content_width: 600,
    font_family: 'system',
    footer_text: '',
  }
}

function defaultForm() {
  return {
    restriction_mode: 'content_replace',
    default_restriction_message: '',
    restriction_message_paused: '',
    redirect_url: '',
    email_access_granted: true,
    email_access_expiring: true,
    email_expiring_days_before: 7,
    trial_expiry_notice_days: 3,
    email_access_revoked: true,
    email_drip_unlocked: true,
    email_membership_paused: true,
    email_membership_resumed: true,
    email_trial_expiring: true,
    email_trial_converted: true,
    email_templates: {},
    email_theme: defaultEmailTheme(),
    email_delivery: {},
    hide_protected_in_archive: false,
    uninstall_remove_data: false,
    debug_mode: false,
    webhook_enabled: false,
    webhook_urls: '',
    fluentcrm_enabled: false,
    fluentcrm_tag_prefix: 'member:',
    fluentcrm_default_list: '',
    fluentcrm_auto_create_tags: true,
    fc_enabled: false,
    fc_space_mappings: {},
    membership_mode: 'stack',
  }
}

function clone(value) {
  return JSON.parse(JSON.stringify(value))
}

function isHttpUrl(value) {
  try {
    const url = new URL(String(value || '').trim())
    return url.protocol === 'http:' || url.protocol === 'https:'
  } catch {
    return false
  }
}

export function useSettingsForm({
  form = ref(defaultForm()),
  settings,
  beforeHydrate,
  afterHydrate,
  onSettingsData,
  onSaveResponse,
  validateIntegrations,
  notify,
}) {
  const loading = ref(false)
  const saving = ref(false)
  const savedSnapshot = ref('')
  const savedFormSnapshot = ref(null)
  const loadError = ref('')
  const validationMessage = ref('')
  const settingsReady = ref(false)

  const isDirty = computed(() => savedSnapshot.value !== '' && JSON.stringify(buildPayload()) !== savedSnapshot.value)

  function buildPayload() {
    const value = form.value
    return {
      default_protection_mode: value.restriction_mode,
      restriction_message_no_access: value.default_restriction_message,
      restriction_message_paused: value.restriction_message_paused,
      default_redirect_url: value.redirect_url,
      expiry_warning_days: value.email_expiring_days_before,
      trial_expiry_notice_days: value.trial_expiry_notice_days,
      email_access_granted: value.email_access_granted ? 'yes' : 'no',
      email_access_expiring: value.email_access_expiring ? 'yes' : 'no',
      email_access_revoked: value.email_access_revoked ? 'yes' : 'no',
      email_drip_unlocked: value.email_drip_unlocked ? 'yes' : 'no',
      email_membership_paused: value.email_membership_paused ? 'yes' : 'no',
      email_membership_resumed: value.email_membership_resumed ? 'yes' : 'no',
      email_trial_expiring: value.email_trial_expiring ? 'yes' : 'no',
      email_trial_converted: value.email_trial_converted ? 'yes' : 'no',
      email_templates: value.email_templates,
      email_theme: value.email_theme,
      email_delivery: value.email_delivery,
      hide_protected_in_archive: value.hide_protected_in_archive ? 'yes' : 'no',
      uninstall_remove_data: value.uninstall_remove_data ? 'yes' : 'no',
      debug_mode: value.debug_mode ? 'yes' : 'no',
      webhook_enabled: value.webhook_enabled ? 'yes' : 'no',
      webhook_urls: value.webhook_urls,
      fluentcrm_enabled: value.fluentcrm_enabled ? 'yes' : 'no',
      fluentcrm_tag_prefix: value.fluentcrm_tag_prefix,
      fluentcrm_default_list: value.fluentcrm_default_list,
      fluentcrm_auto_create_tags: value.fluentcrm_auto_create_tags ? 'yes' : 'no',
      fc_enabled: value.fc_enabled ? 'yes' : 'no',
      fc_space_mappings: value.fc_space_mappings,
      membership_mode: value.membership_mode,
    }
  }

  function hydrate(data) {
    const emailDelivery = { ...(data.email_delivery ?? {}) }
    EMAIL_NOTIFICATIONS.forEach(([key, settingKey]) => {
      if (!emailDelivery[key]) emailDelivery[key] = data[settingKey] === 'no' ? 'off' : 'built_in'
    })
    Object.assign(form.value, {
      restriction_mode: data.default_protection_mode ?? 'content_replace',
      default_restriction_message: data.restriction_message_no_access ?? '',
      restriction_message_paused: data.restriction_message_paused ?? '',
      redirect_url: data.default_redirect_url ?? '',
      email_access_granted: data.email_access_granted === 'yes',
      email_access_expiring: data.email_access_expiring === 'yes',
      email_expiring_days_before: data.expiry_warning_days ?? 7,
      trial_expiry_notice_days: data.trial_expiry_notice_days ?? 3,
      email_access_revoked: data.email_access_revoked === 'yes',
      email_drip_unlocked: data.email_drip_unlocked === 'yes',
      email_membership_paused: data.email_membership_paused === 'yes',
      email_membership_resumed: data.email_membership_resumed === 'yes',
      email_trial_expiring: data.email_trial_expiring === 'yes',
      email_trial_converted: data.email_trial_converted === 'yes',
      email_templates: data.email_templates ?? {},
      email_theme: { ...defaultEmailTheme(), ...(data.email_theme ?? {}) },
      email_delivery: emailDelivery,
      hide_protected_in_archive: data.hide_protected_in_archive === 'yes',
      uninstall_remove_data: data.uninstall_remove_data === 'yes',
      debug_mode: data.debug_mode === 'yes',
      webhook_enabled: data.webhook_enabled === 'yes',
      webhook_urls: data.webhook_urls ?? '',
      fluentcrm_enabled: data.fluentcrm_enabled === 'yes',
      fluentcrm_tag_prefix: data.fluentcrm_tag_prefix ?? 'member:',
      fluentcrm_default_list: data.fluentcrm_default_list ?? '',
      fluentcrm_auto_create_tags: data.fluentcrm_auto_create_tags !== 'no',
      fc_enabled: data.fc_enabled === 'yes',
      fc_space_mappings: data.fc_space_mappings ?? {},
      membership_mode: data.membership_mode ?? 'stack',
    })
  }

  async function loadSettings() {
    loading.value = true
    loadError.value = ''
    validationMessage.value = ''
    settingsReady.value = false
    try {
      const response = await settings.get()
      const data = response.data ?? response
      onSettingsData?.(data)
      await beforeHydrate?.(data)
      hydrate(data)
      savedSnapshot.value = JSON.stringify(buildPayload())
      savedFormSnapshot.value = clone(form.value)
      settingsReady.value = true
      await afterHydrate?.(data)
    } catch (error) {
      loadError.value = error.message || 'The settings service did not return a usable response.'
    } finally {
      loading.value = false
    }
  }

  function validateSettings() {
    if (form.value.restriction_mode === 'redirect' && !isHttpUrl(form.value.redirect_url)) {
      return { category: 'general', message: 'Enter a valid HTTP or HTTPS redirect URL.' }
    }
    if (form.value.webhook_enabled) {
      const urls = String(form.value.webhook_urls || '').split('\n').map((url) => url.trim()).filter(Boolean)
      if (urls.length === 0 || urls.some((url) => !isHttpUrl(url))) {
        return { category: 'webhooks', message: 'Enter a valid HTTP or HTTPS URL on each line.' }
      }
    }
    const integrationMessage = validateIntegrations?.()
    return integrationMessage ? { category: 'integrations', message: integrationMessage } : null
  }

  async function saveSettings({ onValidationError } = {}) {
    const validation = validateSettings()
    if (validation) {
      validationMessage.value = validation.message
      onValidationError?.(validation)
      return false
    }

    saving.value = true
    validationMessage.value = ''
    try {
      const response = await settings.save(buildPayload())
      const data = response.data ?? response
      onSaveResponse?.(data)
      savedSnapshot.value = JSON.stringify(buildPayload())
      savedFormSnapshot.value = clone(form.value)
      notify?.success('Settings saved successfully.')
      return true
    } catch (error) {
      notify?.error(error)
      return false
    } finally {
      saving.value = false
    }
  }

  function discardChanges() {
    if (!savedFormSnapshot.value) return
    Object.assign(form.value, clone(savedFormSnapshot.value))
    validationMessage.value = ''
    notify?.info('Unsaved changes discarded.')
  }

  return {
    form,
    loading,
    saving,
    loadError,
    validationMessage,
    settingsReady,
    isDirty,
    buildPayload,
    hydrate,
    loadSettings,
    saveSettings,
    discardChanges,
    validateSettings,
    clearValidation: () => { validationMessage.value = '' },
  }
}
