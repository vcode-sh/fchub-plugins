import { describe, expect, it, vi } from 'vitest'
import path from 'node:path'
import { pathToFileURL } from 'node:url'

async function loadHelpers() {
  try {
    const file = path.resolve(process.cwd(), 'resources/admin/components/settings/settingsIntegrationUi.js')
    return await import(/* @vite-ignore */ pathToFileURL(file).href)
  } catch {
    return null
  }
}

describe('settings integration helpers', () => {
  it('keeps only the latest remote-search response', async () => {
    const helpers = await loadHelpers()
    expect(helpers).not.toBeNull()

    const resolvers = new Map()
    const fetchOptions = vi.fn((query) => new Promise((resolve) => resolvers.set(query, resolve)))
    const loader = helpers.createRemoteOptionsLoader(fetchOptions)

    const first = loader.search('s')
    const second = loader.search('start')
    resolvers.get('start')([{ id: '31', label: 'Start Here' }])
    expect(await second).toMatchObject({ stale: false, options: [{ id: '31', label: 'Start Here' }] })

    resolvers.get('s')([{ id: '99', label: 'Stale Space' }])
    expect(await first).toMatchObject({ stale: true })
  })

  it('caches identical searches and normalises mapped IDs', async () => {
    const helpers = await loadHelpers()
    expect(helpers).not.toBeNull()

    const fetchOptions = vi.fn().mockResolvedValue([{ id: '31', label: 'Start Here' }])
    const loader = helpers.createRemoteOptionsLoader(fetchOptions)

    await loader.search('', '31')
    await loader.search('', '31')

    expect(fetchOptions).toHaveBeenCalledTimes(1)
    expect(helpers.mappedResourceIds({ 5: '31', 8: '31', 12: '', 14: 'nope' })).toBe('31')
  })

  it('shares an in-flight request for an identical search', async () => {
    const helpers = await loadHelpers()
    expect(helpers).not.toBeNull()

    let resolveOptions
    const fetchOptions = vi.fn(() => new Promise((resolve) => { resolveOptions = resolve }))
    const loader = helpers.createRemoteOptionsLoader(fetchOptions)

    const first = loader.search('', '31')
    const second = loader.search('', '31')
    expect(fetchOptions).toHaveBeenCalledTimes(1)

    resolveOptions([{ id: '31', label: 'Start Here' }])
    expect(await first).toMatchObject({ stale: true })
    expect(await second).toMatchObject({ stale: false, options: [{ id: '31', label: 'Start Here' }] })
  })

  it('describes each plan mapping without implying both resources are required', async () => {
    const helpers = await loadHelpers()
    expect(helpers).not.toBeNull()

    expect(helpers.mappingStatus(5, { 5: '31' }, {})).toEqual({ label: 'Space only', tone: 'space' })
    expect(helpers.mappingStatus(5, {}, { 5: '7' })).toEqual({ label: 'Badge only', tone: 'badge' })
    expect(helpers.mappingStatus(5, { 5: '31' }, { 5: '7' })).toEqual({ label: 'Space + badge', tone: 'complete' })
    expect(helpers.mappingStatus(5, {}, {})).toEqual({ label: 'Not mapped', tone: 'empty' })
  })
})
