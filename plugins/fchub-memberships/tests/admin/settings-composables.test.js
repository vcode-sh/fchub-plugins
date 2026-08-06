import { describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import { useSettingsForm } from '../../resources/admin/composables/settings/useSettingsForm.js'
import { useSettingsIntegrationOptions } from '../../resources/admin/composables/settings/useSettingsIntegrationOptions.js'
import { useWebhookSettingsOperations } from '../../resources/admin/composables/settings/useWebhookSettingsOperations.js'

describe('Settings composable boundaries', () => {
  it('hydrates and discards settings without replacing the shared form object', async () => {
    const form = ref({ restriction_mode: 'content_replace' })
    const original = form.value
    const settings = {
      get: vi.fn().mockResolvedValue({
        default_protection_mode: 'redirect',
        default_redirect_url: 'https://example.test/members',
        email_access_granted: 'yes',
        email_access_expiring: 'yes',
        email_access_revoked: 'yes',
        email_drip_unlocked: 'yes',
        email_membership_paused: 'yes',
        email_membership_resumed: 'yes',
        email_trial_expiring: 'yes',
        email_trial_converted: 'yes',
      }),
      save: vi.fn(),
    }
    const boundary = useSettingsForm({ form, settings })

    await boundary.loadSettings()

    expect(form.value).toBe(original)
    expect(boundary.buildPayload()).toMatchObject({
      default_protection_mode: 'redirect',
      default_redirect_url: 'https://example.test/members',
    })

    form.value.redirect_url = 'https://example.test/changed'
    expect(boundary.isDirty.value).toBe(true)
    boundary.discardChanges()
    expect(form.value).toBe(original)
    expect(form.value.redirect_url).toBe('https://example.test/members')
  })

  it('keeps saved Community mappings recoverable when plan options are unavailable', async () => {
    const form = ref({ fc_space_mappings: { 7: '11' } })
    const api = { get: vi.fn().mockRejectedValue(new Error('offline')) }
    const boundary = useSettingsIntegrationOptions({ api, form })

    await boundary.loadPlanOptions()

    expect(boundary.planOptionsError.value).toContain('Membership plans could not be loaded')
    expect(boundary.planOptions.value).toEqual([
      { id: 7, label: 'Saved plan #7', value: '7', status: 'unavailable' },
    ])
  })

  it('drops a late API-key response once the Webhooks category is no longer active', async () => {
    const active = ref(true)
    let resolveKey
    const settings = {
      generateApiKey: vi.fn(() => new Promise((resolve) => { resolveKey = resolve })),
    }
    const boundary = useWebhookSettingsOperations({
      settings,
      isActive: () => active.value,
      confirm: vi.fn(),
    })

    const pending = boundary.generateApiKey()
    active.value = false
    boundary.invalidateCredentialRequests()
    resolveKey({ api_key: 'late-key', access_api: { configured: true, prefix: 'late' } })
    await pending

    expect(boundary.oneTimeCredentials.value.apiKey).toBe('')
    expect(boundary.apiKeyBusy.value).toBe(false)
  })
})
