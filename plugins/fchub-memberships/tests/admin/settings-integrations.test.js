import { readFileSync } from 'node:fs'
import path from 'node:path'
import { describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import { useSettingsForm } from '../../resources/admin/composables/settings/useSettingsForm.js'
import { useSettingsIntegrationOptions } from '../../resources/admin/composables/settings/useSettingsIntegrationOptions.js'

const componentSource = readFileSync(path.resolve(process.cwd(), 'resources/admin/components/settings/SettingsIntegrationsSection.vue'), 'utf8')

describe('FluentCommunity plan mappings', () => {
  it('renders one plan-led row with a labelled space control', () => {
    expect(componentSource).toContain('class="community-mapping-row"')
    expect(componentSource).toContain('`${plan.label}: Community space`')
    expect(componentSource).toContain('mappingStatus(plan.id).label')
  })

  it('keeps unrelated Community settings savable without dead badge fields', async () => {
    const form = ref({})
    const integrations = useSettingsIntegrationOptions({ api: { get: vi.fn() }, form })
    const boundary = useSettingsForm({
      form,
      settings: { get: vi.fn().mockResolvedValue({ fc_enabled: 'yes', fc_space_mappings: {}, restriction_message_no_access: 'Updated message' }), save: vi.fn() },
      validateIntegrations: integrations.validateCommunityMappings,
    })
    await boundary.loadSettings()

    expect(boundary.validateSettings()).toBeNull()
    expect(boundary.buildPayload()).toEqual(expect.objectContaining({ restriction_message_no_access: 'Updated message', fc_enabled: 'yes' }))
    expect(boundary.buildPayload()).not.toHaveProperty('fc_badge_mappings')
  })

  it('keeps saved mappings recoverable when plan data is unavailable', async () => {
    const form = ref({ fc_enabled: true, fc_space_mappings: { 7: '11' } })
    const boundary = useSettingsIntegrationOptions({ api: { get: vi.fn().mockRejectedValue(new Error('offline')) }, form })
    await boundary.loadPlanOptions()

    expect(boundary.planOptions.value).toEqual([{ id: 7, label: 'Saved plan #7', value: '7', status: 'unavailable' }])
    expect(boundary.validateCommunityMappings()).toBe('Retry loading membership plans, or clear the saved mapping rows before saving.')
    expect(componentSource).toContain('Retry plans')
  })

  it('retains invalid and unavailable mapping guards outside the page shell', () => {
    const form = ref({ fc_enabled: true, fc_space_mappings: { broken: '8' } })
    const boundary = useSettingsIntegrationOptions({ api: { get: vi.fn() }, form })
    expect(boundary.invalidCommunityMapping(form.value.fc_space_mappings)).toBe(true)
    expect(componentSource).toContain('Selected space is unavailable')
  })
})
