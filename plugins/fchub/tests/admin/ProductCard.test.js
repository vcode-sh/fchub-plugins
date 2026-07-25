import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import ProductCard from '../../resources/admin/components/ProductCard.vue'

function product(overrides = {}) {
  return {
    slug: 'fchub-memberships',
    name: 'Memberships',
    description: 'A complete membership system for FluentCart.',
    version: '1.4.0',
    requires_wp: '6.7',
    requires_php: '8.3',
    dependencies: ['fluentcart'],
    docs_url: 'https://fchub.co/docs/fchub-memberships',
    release_url: 'https://github.com/vcode-sh/fchub-plugins/releases/tag/fchub-memberships/v1.4.0',
    lifecycle: 'active',
    update: 'current',
    compatibility: 'compatible',
    compatibility_reason: null,
    health: 'unknown',
    health_message: null,
    installed_version: '1.4.0',
    admin_url: 'https://example.com/wp-admin/admin.php?page=fchub-memberships',
    actions: ['deactivate'],
    ...overrides,
  }
}

const HEALTHY = product()

const INACTIVE = product({
  lifecycle: 'inactive',
  admin_url: null,
  actions: ['activate'],
})

const UPDATABLE = product({
  installed_version: '1.3.0',
  update: 'available',
  actions: ['update', 'deactivate'],
})

const INCOMPATIBLE = product({
  lifecycle: 'not_installed',
  update: 'unknown',
  installed_version: null,
  admin_url: null,
  compatibility: 'blocked',
  compatibility_reason: { requirement: 'php', required: '8.3', current: '8.1.2' },
  actions: [],
})

const NOT_INSTALLED = product({
  lifecycle: 'not_installed',
  update: 'unknown',
  installed_version: null,
  admin_url: null,
  actions: ['install', 'install-and-activate'],
})

let wrapper

function render(props) {
  wrapper = mount(ProductCard, {
    props: { pending: null, ...props },
    attachTo: document.body,
  })

  return wrapper
}

afterEach(() => {
  wrapper?.unmount()
  wrapper = null
})

describe('a product card, whatever state the product is in', () => {
  it('offers exactly one primary action', () => {
    for (const fixture of [HEALTHY, INACTIVE, UPDATABLE, INCOMPATIBLE, NOT_INSTALLED]) {
      const card = mount(ProductCard, { props: { product: fixture, pending: null } })

      expect(card.findAll('[data-primary="true"]')).toHaveLength(1)

      card.unmount()
    }
  })

  it('keeps documentation and release notes as secondary links', () => {
    render({ product: HEALTHY })

    const docs = wrapper.get('[data-link="docs"]')
    const notes = wrapper.get('[data-link="release"]')

    expect(docs.attributes('href')).toBe(HEALTHY.docs_url)
    expect(notes.attributes('href')).toBe(HEALTHY.release_url)
    expect(docs.attributes('data-primary')).toBeUndefined()
    expect(notes.attributes('data-primary')).toBeUndefined()
  })

  it('escapes whatever the catalogue calls a product rather than trusting it', () => {
    render({
      product: product({
        name: '<img src=x onerror="alert(1)">Memberships',
        description: '<script>alert(2)</script>Access rules.',
      }),
    })

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.find('script').exists()).toBe(false)
    expect(wrapper.text()).toContain('<img src=x onerror="alert(1)">Memberships')
  })
})

describe('a healthy, active product', () => {
  it('leads with opening the product, not with fiddling with it', () => {
    render({ product: HEALTHY })

    const primary = wrapper.get('[data-primary="true"]')

    expect(primary.attributes('href')).toBe(HEALTHY.admin_url)
    expect(primary.text()).toBe('Open settings')
    expect(wrapper.text()).toContain('Active')
  })

  it('still offers switching it off, quietly', () => {
    render({ product: HEALTHY })

    const off = wrapper.get('[data-action="deactivate"]')

    expect(off.attributes('data-primary')).toBeUndefined()

    off.trigger('click')

    expect(wrapper.emitted('action')[0]).toEqual([
      { slug: 'fchub-memberships', action: 'deactivate' },
    ])
  })
})

describe('an installed but switched-off product', () => {
  it('leads with switching it on', async () => {
    render({ product: INACTIVE })

    const primary = wrapper.get('[data-primary="true"]')

    expect(primary.attributes('data-action')).toBe('activate')
    expect(primary.text()).toBe('Switch on')

    await primary.trigger('click')

    expect(wrapper.emitted('action')[0]).toEqual([
      { slug: 'fchub-memberships', action: 'activate' },
    ])
  })
})

describe('a product with an update waiting', () => {
  it('leads with the update and says which version it is going to', async () => {
    render({ product: UPDATABLE })

    const primary = wrapper.get('[data-primary="true"]')

    expect(primary.attributes('data-action')).toBe('update')
    expect(primary.text()).toBe('Update to 1.4.0')
    expect(wrapper.text()).toContain('1.3.0 installed')

    await primary.trigger('click')

    expect(wrapper.emitted('action')[0]).toEqual([{ slug: 'fchub-memberships', action: 'update' }])
  })

  it('shows the action running without losing the button', async () => {
    render({ product: UPDATABLE })
    await wrapper.setProps({ pending: 'update' })

    const primary = wrapper.get('[data-primary="true"]')

    expect(primary.attributes('aria-disabled')).toBe('true')
    expect(primary.text()).toBe('Updating…')

    await primary.trigger('click')

    expect(wrapper.emitted('action')).toBeUndefined()
  })
})

describe('a product that is not installed', () => {
  it('leads with install and activate, and keeps install-only quieter', async () => {
    render({ product: NOT_INSTALLED })

    const primary = wrapper.get('[data-primary="true"]')

    expect(primary.attributes('data-action')).toBe('install-and-activate')
    expect(primary.text()).toBe('Install and activate')

    const installOnly = wrapper.get('[data-action="install"]')

    expect(installOnly.attributes('data-primary')).toBeUndefined()
    expect(installOnly.text()).toBe('Install only')

    await installOnly.trigger('click')

    expect(wrapper.emitted('action')[0]).toEqual([
      { slug: 'fchub-memberships', action: 'install' },
    ])
  })

  it('names the release on offer', () => {
    render({ product: NOT_INSTALLED })

    expect(wrapper.text()).toContain('Latest release 1.4.0')
  })
})

describe('a product this site cannot run', () => {
  it('disables the action and says exactly what is missing', () => {
    render({ product: INCOMPATIBLE })

    const primary = wrapper.get('[data-primary="true"]')

    expect(primary.attributes('aria-disabled')).toBe('true')
    expect(wrapper.text()).toContain('Memberships needs PHP 8.3. This site runs 8.1.2.')
  })

  it('keeps the disabled action reachable by keyboard and explains itself there', () => {
    render({ product: INCOMPATIBLE })

    const primary = wrapper.get('[data-primary="true"]')

    // A native `disabled` button drops out of the tab order, taking the
    // explanation with it. aria-disabled keeps it reachable and announced.
    expect(primary.attributes('disabled')).toBeUndefined()
    expect(primary.element.tabIndex).toBe(0)

    const describedBy = primary.attributes('aria-describedby')

    expect(describedBy).toBeTruthy()

    const reason = wrapper.get(`#${describedBy}`)

    expect(reason.text()).toBe('Memberships needs PHP 8.3. This site runs 8.1.2.')
    expect(reason.isVisible()).toBe(true)
  })

  it('does nothing at all when pressed', async () => {
    render({ product: INCOMPATIBLE })

    await wrapper.get('[data-primary="true"]').trigger('click')

    expect(wrapper.emitted('action')).toBeUndefined()
  })

  it('never renders the raw reason object', () => {
    for (const fixture of [
      INCOMPATIBLE,
      product({
        compatibility: 'unknown',
        compatibility_reason: { requirement: 'dependency', required: 'fluentcart', current: null },
      }),
      product({ compatibility: 'unknown', compatibility_reason: null }),
    ]) {
      const card = mount(ProductCard, { props: { product: fixture, pending: null } })
      const html = card.html()

      expect(card.text()).not.toContain('[object Object]')
      expect(card.text()).not.toContain('undefined')
      // The object's own key names would appear if anything ever stringified
      // it, however politely.
      expect(html).not.toContain('[object Object]')
      expect(html).not.toContain('compatibility_reason')
      expect(html).not.toContain('"requirement"')
      expect(html).not.toContain('requirement:')

      card.unmount()
    }
  })

  it('admits when it cannot check, instead of guessing', () => {
    render({
      product: product({
        compatibility: 'unknown',
        compatibility_reason: { requirement: 'dependency', required: 'fluentcart', current: null },
      }),
    })

    expect(wrapper.text()).toContain('Memberships needs FluentCart, which FCHub cannot check here.')
    expect(wrapper.text()).toContain('Cannot be checked')
  })
})

describe('how loudly the note is painted', () => {
  /** The note that carries `reason`, whichever of the three it is today. */
  function note() {
    return wrapper.findAll('.fchub-card__note').at(-1)
  }

  it('reserves the critical fill for a product this site genuinely cannot run', () => {
    render({ product: INCOMPATIBLE })

    expect(note().classes()).toContain('fchub-card__note--blocked')
  })

  it('leaves an unconfirmed requirement at the same amber its badge wears', () => {
    render({
      product: product({
        compatibility: 'unknown',
        compatibility_reason: { requirement: 'dependency', required: 'fluentcart', current: null },
        actions: [],
      }),
    })

    // The badge says "Cannot be checked" in amber. A pink note under an amber
    // badge is the card disagreeing with itself about how bad this is.
    expect(wrapper.text()).toContain('Cannot be checked')
    expect(note().classes()).not.toContain('fchub-card__note--blocked')
    expect(note().classes()).not.toContain('fchub-card__note--permission')
  })

  it('does not paint a permissions fact as though something broke', () => {
    render({
      product: product({
        lifecycle: 'not_installed',
        update: 'unknown',
        installed_version: null,
        admin_url: null,
        actions: [],
      }),
    })

    expect(wrapper.text()).toContain('Your account cannot make that change on this site.')
    expect(note().classes()).toContain('fchub-card__note--permission')
    expect(note().classes()).not.toContain('fchub-card__note--blocked')
  })
})

describe('product health', () => {
  it('stays silent when the product has not published any', () => {
    render({ product: HEALTHY })

    expect(wrapper.find('[data-health]').exists()).toBe(false)
  })

  it('passes on the product’s own sentence when it asks for attention', () => {
    render({
      product: product({ health: 'attention', health_message: 'Two plans have no content rules.' }),
    })

    expect(wrapper.get('[data-health]').text()).toBe('Two plans have no content rules.')
    expect(wrapper.text()).toContain('Needs attention')
  })
})

describe('focus after an action', () => {
  it('returns to the button that started it', async () => {
    render({ product: UPDATABLE })

    const primary = wrapper.get('[data-primary="true"]')

    primary.element.focus()
    await primary.trigger('click')

    await wrapper.setProps({ pending: 'update' })
    // A failed update leaves the product exactly as it was, so the button the
    // customer pressed is still there — and is where they should land.
    await wrapper.setProps({ pending: null })
    await nextTick()

    expect(document.activeElement).toBe(wrapper.get('[data-action="update"]').element)
  })

  it('falls back to the card heading when the action removed its own button', async () => {
    render({ product: INACTIVE })

    const primary = wrapper.get('[data-primary="true"]')

    primary.element.focus()
    await primary.trigger('click')

    await wrapper.setProps({ pending: 'activate' })
    await wrapper.setProps({ pending: null, product: HEALTHY })
    await nextTick()

    expect(wrapper.find('[data-action="activate"]').exists()).toBe(false)
    expect(document.activeElement).toBe(wrapper.get('[data-card-heading]').element)
  })

  it('leaves focus alone when nothing was pressed here', async () => {
    render({ product: UPDATABLE })

    document.body.focus()

    await wrapper.setProps({ pending: 'update' })
    await wrapper.setProps({ pending: null })
    await nextTick()

    expect(document.activeElement).toBe(document.body)
  })
})
