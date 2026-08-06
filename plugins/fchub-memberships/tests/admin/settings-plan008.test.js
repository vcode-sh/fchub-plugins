import { describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import { useSettingsForm } from '../../resources/admin/composables/settings/useSettingsForm.js'

describe('Plan 008 settings controls', () => {
  it('loads and serialises every active setting without dead badge fields', async () => {
    const form = ref({})
    const boundary = useSettingsForm({
      form,
      settings: {
        get: vi.fn().mockResolvedValue({
          expiry_warning_days: 11, trial_expiry_notice_days: 4,
          hide_protected_in_archive: 'yes', uninstall_remove_data: 'yes',
        }),
        save: vi.fn(),
      },
    })
    await boundary.loadSettings()

    expect(form.value).toEqual(expect.objectContaining({
      email_expiring_days_before: 11, trial_expiry_notice_days: 4,
      hide_protected_in_archive: true, uninstall_remove_data: true,
    }))
    expect(boundary.buildPayload()).toEqual(expect.objectContaining({
      expiry_warning_days: 11, trial_expiry_notice_days: 4,
      hide_protected_in_archive: 'yes', uninstall_remove_data: 'yes',
    }))
    expect(boundary.buildPayload()).not.toHaveProperty('fc_badge_mappings')
    expect(boundary.buildPayload()).not.toHaveProperty('fc_remove_badge_on_revoke')
  })
})
