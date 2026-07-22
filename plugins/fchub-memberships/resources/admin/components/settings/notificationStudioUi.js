const GROUPS = [
  { key: 'access', label: 'Access' },
  { key: 'lifecycle', label: 'Membership lifecycle' },
  { key: 'trial', label: 'Trials' },
  { key: 'content', label: 'Content' },
]

export function groupNotifications(notifications = []) {
  return GROUPS
    .map((group) => ({
      ...group,
      items: notifications.filter(({ group: itemGroup }) => itemGroup === group.key),
    }))
    .filter(({ items }) => items.length)
}

export function deliveryOptions(fluentcrmAvailable) {
  const options = [{ label: 'Built-in email', value: 'built_in' }]
  if (fluentcrmAvailable) {
    options.push({ label: 'FluentCRM automation', value: 'fluentcrm' })
  }
  options.push({ label: 'Off', value: 'off' })
  return options
}

export function activeDeliveryCount(deliveries = {}, notificationKeys = []) {
  return notificationKeys.filter((key) => (deliveries[key] ?? 'built_in') !== 'off').length
}

export function newBlock(type, id = `${type}-${Date.now()}-${Math.random().toString(16).slice(2)}`) {
  const defaults = {
    rich_text: { content: '<p>Write your message here.</p>' },
    heading: { content: 'A clear heading', align: 'left' },
    button: { label: 'Continue', url: '#', align: 'left' },
    image: { url: '', alt: '', link_url: '' },
    divider: {},
    spacer: { height: 24 },
    dynamic: { variable: '' },
  }
  return { id, type, ...(defaults[type] ?? {}) }
}

export function addBlock(blocks, block, afterIndex = blocks.length - 1) {
  const next = [...blocks]
  next.splice(Math.max(0, afterIndex + 1), 0, block)
  return next
}

export function moveBlock(blocks, index, direction) {
  const target = index + direction
  if (index < 0 || index >= blocks.length || target < 0 || target >= blocks.length) {
    return blocks
  }
  const next = [...blocks]
  const [block] = next.splice(index, 1)
  next.splice(target, 0, block)
  return next
}

export function notificationEditorPayload(notification, theme) {
  return {
    key: notification.key,
    delivery: notification.delivery,
    template: notification.template,
    theme,
    theme_override: notification.theme_override ?? null,
  }
}

export function brandTemplatePayload(theme) {
  return { theme }
}
