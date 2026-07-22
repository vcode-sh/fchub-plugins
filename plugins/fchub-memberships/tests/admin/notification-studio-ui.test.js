import { describe, expect, it } from 'vitest'
import {
  addBlock,
  activeDeliveryCount,
  brandTemplatePayload,
  deliveryOptions,
  groupNotifications,
  moveBlock,
  newBlock,
  notificationEditorPayload,
} from '../../resources/admin/components/settings/notificationStudioUi.js'

describe('notification studio UI model', () => {
  const notifications = [
    { key: 'access_granted', group: 'access', label: 'Access granted' },
    { key: 'access_expiring', group: 'access', label: 'Access expiring' },
    { key: 'access_revoked', group: 'access', label: 'Access revoked' },
    { key: 'membership_paused', group: 'lifecycle', label: 'Membership paused' },
    { key: 'membership_resumed', group: 'lifecycle', label: 'Membership resumed' },
    { key: 'trial_expiring', group: 'trial', label: 'Trial expiring' },
    { key: 'trial_converted', group: 'trial', label: 'Trial converted' },
    { key: 'drip_content_unlocked', group: 'content', label: 'Drip content unlocked' },
  ]

  it('groups all eight native notifications without FluentCRM', () => {
    const groups = groupNotifications(notifications)

    expect(groups.map(({ key }) => key)).toEqual(['access', 'lifecycle', 'trial', 'content'])
    expect(groups.flatMap(({ items }) => items)).toHaveLength(8)
    expect(deliveryOptions(false)).toEqual([
      { label: 'Built-in email', value: 'built_in' },
      { label: 'Off', value: 'off' },
    ])
  })

  it('only offers FluentCRM as an optional advanced owner when available', () => {
    expect(deliveryOptions(true)).toContainEqual({ label: 'FluentCRM automation', value: 'fluentcrm' })
    expect(deliveryOptions(false).some(({ value }) => value === 'fluentcrm')).toBe(false)
  })

  it('counts every enabled native or optional delivery owner', () => {
    expect(activeDeliveryCount({
      access_granted: 'built_in',
      access_expiring: 'fluentcrm',
      access_revoked: 'off',
    }, ['access_granted', 'access_expiring', 'access_revoked'])).toBe(2)
  })

  it('creates, adds and keyboard-reorders email blocks predictably', () => {
    const blocks = addBlock([], newBlock('heading', 'one'))
    const withButton = addBlock(blocks, newBlock('button', 'two'))
    const moved = moveBlock(withButton, 1, -1)

    expect(moved.map(({ id }) => id)).toEqual(['two', 'one'])
    expect(moved[0]).toMatchObject({ type: 'button', label: 'Continue', url: '#' })
    expect(moveBlock(moved, 0, -1)).toEqual(moved)
  })

  it('builds an independent save payload with template, theme and delivery owner', () => {
    const payload = notificationEditorPayload(
      { key: 'access_granted', delivery: 'built_in', template: { subject: 'Hello', preheader: '', blocks: [] } },
      { primary_color: '#2563eb' },
    )

    expect(payload).toEqual({
      key: 'access_granted',
      delivery: 'built_in',
      template: { subject: 'Hello', preheader: '', blocks: [] },
      theme: { primary_color: '#2563eb' },
      theme_override: null,
    })
  })

  it('saves one global brand shell while allowing explicit per-email overrides', () => {
    expect(brandTemplatePayload({ header_style: 'logo', content_width: 620 })).toEqual({
      theme: { header_style: 'logo', content_width: 620 },
    })

    expect(notificationEditorPayload(
      { key: 'access_granted', delivery: 'built_in', template: { subject: 'Hello', blocks: [] }, theme_override: null },
      { primary_color: '#2563eb' },
    )).toMatchObject({ theme_override: null })
  })
})
