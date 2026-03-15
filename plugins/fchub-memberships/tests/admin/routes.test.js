import { describe, expect, it } from 'vitest'
import router from '@/router/index.js'
import { routeExpectations } from './support.js'

describe('admin routes', () => {
  it.each(routeExpectations)('resolves %s to a concrete route record', ({ path }) => {
    const resolved = router.resolve(path)
    expect(resolved.matched.length).toBeGreaterThan(0)
    expect(resolved.href).toContain('#')
  })
})
