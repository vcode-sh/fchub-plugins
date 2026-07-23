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

function functionSource(name) {
  const start = pageSource.indexOf(`function ${name}(`)
  if (start < 0) throw new Error(`Missing ${name}() in Settings.vue`)

  const bodyStart = pageSource.indexOf(') {', start) + 2
  let depth = 0
  for (let index = bodyStart; index < pageSource.length; index++) {
    if (pageSource[index] === '{') depth++
    if (pageSource[index] === '}') depth--
    if (depth === 0) return pageSource.slice(start, index + 1)
  }

  throw new Error(`Unclosed ${name}() in Settings.vue`)
}

function loadCommunitySettings(data) {
  const match = pageSource.match(/form\.value = \{[\s\S]*?\/\/ FluentCommunity\n([\s\S]*?)\/\/ Membership Rules/)
  if (!match) throw new Error('Missing FluentCommunity settings load block')
  return Function('data', `return ({${match[1]}})`)(data)
}

function validateCommunitySettings(formValue, planLoadError = '') {
  return Function(
    'form',
    'planOptionsError',
    'fcSpaces',
    'spaceSearchError',
    'fcBadges',
    'badgeSearchError',
    `${functionSource('validateSettings')}
     ${functionSource('invalidCommunityMapping')}
     ${functionSource('unavailableCommunityMapping')}
     ${functionSource('isHttpUrl')}
     return validateSettings()`,
  )(
    { value: formValue },
    { value: planLoadError },
    { value: [] },
    { value: '' },
    { value: [] },
    { value: '' },
  )
}

function buildSettingsPayload(formValue) {
  return Function('form', `${functionSource('buildPayload')}; return buildPayload()`)(
    { value: formValue },
  )
}

describe('FluentCommunity plan mappings', () => {
  it('opens contextual integration settings and focuses only known providers', () => {
    expect(pageSource).toContain("import { useRoute } from 'vue-router'")
    expect(pageSource).toContain("const allowedIntegrationProviders = new Set(['fluentcrm', 'fluent_community'])")
    expect(pageSource).toContain("route.query.category === 'integrations'")
    expect(pageSource).toContain(':focus-provider="focusedIntegrationProvider"')
    expect(componentSource).toContain('id="integration-fluentcrm"')
    expect(componentSource).toContain('id="integration-fluent-community"')
    expect(componentSource).toContain("'is-focused': focusProvider === 'fluentcrm'")
    expect(componentSource).toContain("'is-focused': focusProvider === 'fluent_community'")
  })

  it('renders one plan-led row with a labelled space control', () => {
    expect(componentSource).toContain('class="community-mapping-row"')
    expect(componentSource).toContain('{{ plan.label }}')
    expect(componentSource).toContain('`${plan.label}: Community space`')
    expect(componentSource).toContain('mappingStatus(plan.id).label')
    expect(componentSource).toContain('mappings configured')
  })

  it('surfaces supported resource loading failures', () => {
    expect(componentSource).toContain('spaceSearchError')
    expect(componentSource).toContain('role="alert"')
  })

  it('removes unsupported badge controls and leaves opaque preservation to the backend', () => {
    expect(componentSource).not.toContain('Member badge')
    expect(componentSource).not.toContain(':remote-method="searchFcBadges"')
    expect(componentSource).not.toContain('@visible-change="handleBadgeVisibility"')
    expect(componentSource).not.toContain('form.fc_badge_mappings')
    expect(componentSource).not.toContain('form.fc_remove_badge_on_revoke')
    expect(componentSource).not.toContain('Remove mapped badges when access ends')

    expect(pageSource).not.toContain('fc_badge_mappings')
    expect(pageSource).not.toContain('fc_remove_badge_on_revoke')
  })

  it('keeps unrelated Community settings savable without sending dead badge fields', () => {
    const loaded = loadCommunitySettings({
      fc_enabled: 'yes',
      fc_space_mappings: {},
    })
    const form = {
      ...loaded,
      restriction_mode: 'content_replace',
      default_restriction_message: 'Updated message',
      webhook_enabled: false,
    }

    expect(validateCommunitySettings(form, 'Membership plans failed to load')).toBeNull()
    expect(buildSettingsPayload(form)).toEqual(expect.objectContaining({
      restriction_message_no_access: 'Updated message',
      fc_enabled: 'yes',
    }))
    expect(buildSettingsPayload(form)).not.toHaveProperty('fc_badge_mappings')
    expect(buildSettingsPayload(form)).not.toHaveProperty('fc_remove_badge_on_revoke')
  })

  it('keeps mapping layout styles inside the owning component', () => {
    expect(componentSource).toContain('.community-mapping-grid')
    expect(componentSource).toContain('@media (max-width: 782px)')
    expect(componentSource).toContain('grid-template-columns: 1fr;')
    expect(componentSource).toContain('.community-plan-cell { display: grid;')
    expect(componentSource).toContain('white-space: normal;')
    expect(pageSource).not.toContain('.mapping-row {')
  })

  it('preloads supported saved resources and ignores opaque badge values', () => {
    expect(pageSource).toContain('createRemoteOptionsLoader')
    expect(pageSource).toContain('spaceSearchError')
    expect(pageSource).toContain('const include = mappedResourceIds(form.value.fc_space_mappings)')
    expect(pageSource).not.toContain('badgeOptionsLoader')
    expect(pageSource).not.toContain('searchFcBadges')
    expect(pageSource).not.toContain('badgeSearchError')
    expect(pageSource).not.toContain('fcBadges')
    expect(pageSource).not.toContain('loadingBadges')
    expect(pageSource).not.toContain('fc_badge_mappings')
    expect(pageSource).not.toContain('fc_remove_badge_on_revoke')
  })

  it('blocks malformed saved mapping IDs and identifies deleted resources', () => {
    expect(pageSource).toContain('invalidCommunityMapping')
    expect(pageSource).toContain('unavailableCommunityMapping')
    expect(componentSource).toContain('Selected space is unavailable')
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
