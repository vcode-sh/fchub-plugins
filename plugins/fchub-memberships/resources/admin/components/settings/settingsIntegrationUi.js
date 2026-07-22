export function createRemoteOptionsLoader(fetchOptions) {
  const cache = new Map()
  const pending = new Map()
  let requestSequence = 0

  return {
    async search(query = '', context = '') {
      const normalisedQuery = String(query ?? '').trim()
      const cacheKey = `${normalisedQuery}\u0000${String(context ?? '')}`
      const requestId = ++requestSequence

      if (cache.has(cacheKey)) {
        return {
          stale: false,
          cached: true,
          options: cache.get(cacheKey),
          error: null,
        }
      }

      let request = pending.get(cacheKey)
      if (!request) {
        request = Promise.resolve(fetchOptions(normalisedQuery, context))
        pending.set(cacheKey, request)
      }

      try {
        const options = await request
        if (pending.get(cacheKey) === request) pending.delete(cacheKey)
        if (requestId !== requestSequence) {
          return { stale: true, cached: false, options: [], error: null }
        }

        const safeOptions = Array.isArray(options) ? options : []
        cache.set(cacheKey, safeOptions)
        return { stale: false, cached: false, options: safeOptions, error: null }
      } catch (error) {
        if (pending.get(cacheKey) === request) pending.delete(cacheKey)
        if (requestId !== requestSequence) {
          return { stale: true, cached: false, options: [], error: null }
        }

        return { stale: false, cached: false, options: [], error }
      }
    },
    clear() {
      cache.clear()
      pending.clear()
      requestSequence++
    },
  }
}

export function mappedResourceIds(mappings = {}) {
  return [...new Set(Object.values(mappings)
    .map((value) => String(value ?? '').trim())
    .filter((value) => /^\d+$/.test(value) && Number(value) > 0))]
    .join(',')
}

export function mappingStatus(planId, spaceMappings = {}, badgeMappings = {}) {
  const hasSpace = Boolean(spaceMappings?.[planId])
  const hasBadge = Boolean(badgeMappings?.[planId])

  if (hasSpace && hasBadge) return { label: 'Space + badge', tone: 'complete' }
  if (hasSpace) return { label: 'Space only', tone: 'space' }
  if (hasBadge) return { label: 'Badge only', tone: 'badge' }
  return { label: 'Not mapped', tone: 'empty' }
}
