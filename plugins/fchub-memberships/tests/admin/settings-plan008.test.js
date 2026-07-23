import { describe, expect, it, vi } from 'vitest'
import { readFileSync } from 'node:fs'
import path from 'node:path'

const pageSource = readFileSync(
  path.resolve(process.cwd(), 'resources/admin/pages/Settings.vue'),
  'utf8',
)

const generalSource = readFileSync(
  path.resolve(process.cwd(), 'resources/admin/components/settings/SettingsGeneralSection.vue'),
  'utf8',
)

const advancedSource = readFileSync(
  path.resolve(process.cwd(), 'resources/admin/components/settings/SettingsAdvancedSection.vue'),
  'utf8',
)

function functionSource(source, name) {
  const start = source.indexOf(`function ${name}(`)
  if (start < 0) throw new Error(`Missing ${name}()`)

  const bodyStart = source.indexOf(') {', start) + 2
  let depth = 0
  for (let index = bodyStart; index < source.length; index++) {
    if (source[index] === '{') depth++
    if (source[index] === '}') depth--
    if (depth === 0) return source.slice(start, index + 1)
  }

  throw new Error(`Unclosed ${name}() in Settings.vue`)
}

function buildSettingsPayload(formValue) {
  return Function('form', `${functionSource(pageSource, 'buildPayload')}; return buildPayload()`)(
    { value: formValue },
  )
}

function loadSettingsForm(data) {
  const match = pageSource.match(/form\.value = (\{[\s\S]*?\n    \})\n    savedSnapshot/)
  if (!match) throw new Error('Missing settings form load block')

  return Function(
    'data',
    'emailDelivery',
    'defaultEmailTheme',
    `return (${match[1]})`,
  )(data, {}, () => ({}))
}

function changeUninstallSetting(formValue, enabled, confirm) {
  const props = { form: formValue }
  const ElMessageBox = { confirm }
  const handlerSource = functionSource(advancedSource, 'changeUninstallSetting')
    .replace(/^function /, 'async function ')
  return Function(
    'props',
    'ElMessageBox',
    `${handlerSource}; return changeUninstallSetting`,
  )(props, ElMessageBox)(enabled)
}

describe('Plan 008 settings controls', () => {
  it('loads and serialises every active setting without dead badge fields', () => {
    const loaded = loadSettingsForm({
      expiry_warning_days: 11,
      trial_expiry_notice_days: 4,
      hide_protected_in_archive: 'yes',
      uninstall_remove_data: 'yes',
    })

    expect(loaded).toEqual(expect.objectContaining({
      email_expiring_days_before: 11,
      trial_expiry_notice_days: 4,
      hide_protected_in_archive: true,
      uninstall_remove_data: true,
    }))

    const payload = buildSettingsPayload(loaded)
    expect(payload).toEqual(expect.objectContaining({
      expiry_warning_days: 11,
      trial_expiry_notice_days: 4,
      hide_protected_in_archive: 'yes',
      uninstall_remove_data: 'yes',
    }))
    expect(pageSource).not.toContain('fc_badge_mappings')
    expect(pageSource).not.toContain('fc_remove_badge_on_revoke')
  })

  it('renders clear timing and archive controls outside Notification Studio', () => {
    expect(generalSource).toContain('Access Expiry Notice')
    expect(generalSource).toContain('Trial Expiry Notice')
    expect(generalSource).toContain('Hide Protected Content from Archives')
    expect(generalSource).toContain('aria-label="Access expiry notice days"')
    expect(generalSource).toContain('aria-label="Trial expiry notice days"')
    expect(generalSource).toContain('aria-label="Hide protected content from archives"')
  })

  it('keeps uninstall data removal off when confirmation is cancelled', async () => {
    const confirm = vi.fn().mockRejectedValueOnce(new Error('cancelled'))
    const form = { debug_mode: false, uninstall_remove_data: false }

    await changeUninstallSetting(form, true, confirm)

    expect(confirm).toHaveBeenCalledOnce()
    expect(form.uninstall_remove_data).toBe(false)
  })

  it('enables uninstall data removal only after explicit confirmation', async () => {
    const confirm = vi.fn().mockResolvedValueOnce('confirm')
    const form = { debug_mode: false, uninstall_remove_data: false }

    await changeUninstallSetting(form, true, confirm)

    expect(form.uninstall_remove_data).toBe(true)
    expect(confirm).toHaveBeenCalledWith(
      expect.stringContaining('permanently delete'),
      expect.any(String),
      expect.objectContaining({ type: 'warning' }),
    )
  })
})
