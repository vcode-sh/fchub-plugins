import { readFileSync } from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const componentSource = readFileSync(
  path.resolve(process.cwd(), 'resources/admin/components/settings/SettingsIntegrationsSection.vue'),
  'utf8',
)

const pageSource = readFileSync(
  path.resolve(process.cwd(), 'resources/admin/pages/Settings.vue'),
  'utf8',
)

describe('FluentCommunity plan mappings', () => {
  it('renders one plan-led row with labelled space and badge controls', () => {
    expect(componentSource).toContain('class="community-mapping-row"')
    expect(componentSource).toContain('{{ plan.label }}')
    expect(componentSource).toContain('`${plan.label}: Community space`')
    expect(componentSource).toContain('`${plan.label}: Member badge`')
    expect(componentSource).toContain('mappingStatus(plan.id).label')
    expect(componentSource).toContain('mappings configured')
  })

  it('surfaces resource loading failures and contextualises badge removal', () => {
    expect(componentSource).toContain('spaceSearchError')
    expect(componentSource).toContain('badgeSearchError')
    expect(componentSource).toContain('role="alert"')
    expect(componentSource).toContain('aria-label="Remove mapped badges when access ends"')
    expect(componentSource).toContain(':disabled="!hasBadgeMappings"')
  })

  it('keeps mapping layout styles inside the owning component', () => {
    expect(componentSource).toContain('.community-mapping-grid')
    expect(componentSource).toContain('@media (max-width: 782px)')
    expect(componentSource).toContain('grid-template-columns: 1fr;')
    expect(componentSource).toContain('.community-plan-cell { display: grid;')
    expect(componentSource).toContain('white-space: normal;')
    expect(pageSource).not.toContain('.mapping-row {')
  })

  it('preloads saved resources and guards remote-search failures', () => {
    expect(pageSource).toContain('createRemoteOptionsLoader')
    expect(pageSource).toContain('spaceSearchError')
    expect(pageSource).toContain('badgeSearchError')
    expect(pageSource).toContain('const include = mappedResourceIds(form.value.fc_space_mappings)')
    expect(pageSource).toContain('const include = mappedResourceIds(form.value.fc_badge_mappings)')
  })

  it('blocks malformed saved mapping IDs and identifies deleted resources', () => {
    expect(pageSource).toContain('invalidCommunityMapping')
    expect(pageSource).toContain('unavailableCommunityMapping')
    expect(componentSource).toContain('Selected space is unavailable')
    expect(componentSource).toContain('Selected badge is unavailable')
    expect(componentSource).toContain('isMissingOption')
  })

  it('keeps saved mappings recoverable when plan data is invalid or unavailable', () => {
    expect(pageSource).toContain('planOptionsError')
    expect(pageSource).toContain('async function loadPlanOptions')
    expect(pageSource).toContain('Saved plan #')
    expect(pageSource).toContain("status: 'invalid'")
    expect(pageSource).toContain('!/^[1-9]\\d*$/.test(String(planId))')
    expect(componentSource).toContain('Retry plans')
    expect(componentSource).toContain("plan.status === 'invalid' ? 'Cleanup required'")
    expect(componentSource).toContain('v-for="(plan, rowIndex) in planOptions"')
  })
})
