import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useGrantAccessUserPicker } from '@/composables/members/useGrantAccessUserPicker.js'

function deferred() {
  let resolve
  let reject
  const promise = new Promise((resolvePromise, rejectPromise) => {
    resolve = resolvePromise
    reject = rejectPromise
  })

  return { promise, resolve, reject }
}

function createPicker(fetchUsers = vi.fn().mockResolvedValue({ data: [] })) {
  return {
    fetchUsers,
    picker: useGrantAccessUserPicker({ fetchUsers }),
  }
}

describe('grant access user picker', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
  })

  it('loads a bounded recent-user list before the administrator types', async () => {
    const recentUsers = [
      { id: 21, display_name: 'Alice Example', email: 'alice@example.com' },
    ]
    const { fetchUsers, picker } = createPicker(
      vi.fn().mockResolvedValue({ data: recentUsers }),
    )

    await picker.searchUsers('   ')

    expect(fetchUsers).toHaveBeenCalledWith({
      search: '',
      per_page: 10,
      users_only: true,
    })
    expect(picker.userResults.value).toEqual(recentUsers)
    expect(picker.userSearchError.value).toBe('')
  })

  it('reuses the recent list for one-character input', async () => {
    const { fetchUsers, picker } = createPicker(
      vi.fn().mockResolvedValue({ data: [{ id: 21, display_name: 'Alice Example' }] }),
    )

    await picker.searchUsers('')
    await picker.searchUsers('a')

    expect(fetchUsers).toHaveBeenCalledTimes(1)
  })

  it('caches repeated searches and deduplicates matching in-flight requests', async () => {
    const pending = deferred()
    const { fetchUsers, picker } = createPicker(vi.fn().mockReturnValue(pending.promise))

    const first = picker.searchUsers('alice')
    const duplicate = picker.searchUsers('alice')

    expect(fetchUsers).toHaveBeenCalledTimes(1)
    pending.resolve({ data: [{ id: 21, display_name: 'Alice Example' }] })
    await Promise.all([first, duplicate])
    await picker.searchUsers('alice')

    expect(fetchUsers).toHaveBeenCalledTimes(1)
    expect(picker.userResults.value).toHaveLength(1)
  })

  it('ignores a stale response that arrives after a newer query', async () => {
    const oldRequest = deferred()
    const newRequest = deferred()
    const { picker } = createPicker(
      vi.fn()
        .mockReturnValueOnce(oldRequest.promise)
        .mockReturnValueOnce(newRequest.promise),
    )

    const oldSearch = picker.searchUsers('alice')
    const newSearch = picker.searchUsers('bob')
    newRequest.resolve({ data: [{ id: 22, display_name: 'Bob Example' }] })
    await newSearch
    oldRequest.resolve({ data: [{ id: 21, display_name: 'Alice Example' }] })
    await oldSearch

    expect(picker.userResults.value).toEqual([{ id: 22, display_name: 'Bob Example' }])
  })

  it('exposes a recoverable search error without retaining stale options', async () => {
    const { picker } = createPicker(vi.fn().mockRejectedValue(new Error('Service unavailable')))

    await picker.searchUsers('alice')

    expect(picker.userResults.value).toEqual([])
    expect(picker.userSearchError.value).toBe('Service unavailable')
    expect(picker.searchingUsers.value).toBe(false)
  })

  it('resets session state when the dialog closes', async () => {
    const { fetchUsers, picker } = createPicker(
      vi.fn().mockResolvedValue({ data: [{ id: 21, display_name: 'Alice Example' }] }),
    )

    await picker.searchUsers('')
    picker.resetUserSearch()
    await picker.searchUsers('')

    expect(fetchUsers).toHaveBeenCalledTimes(2)
  })

  it('does not let a stale pre-reset request evict the current in-flight request', async () => {
    const staleRequest = deferred()
    const currentRequest = deferred()
    const fetchUsers = vi.fn()
      .mockReturnValueOnce(staleRequest.promise)
      .mockReturnValueOnce(currentRequest.promise)
      .mockResolvedValue({ data: [{ id: 99, display_name: 'Unexpected duplicate' }] })
    const { picker } = createPicker(fetchUsers)

    const staleSearch = picker.searchUsers('alice')
    picker.resetUserSearch()
    const currentSearch = picker.searchUsers('alice')

    staleRequest.resolve({ data: [{ id: 20, display_name: 'Stale user' }] })
    await staleSearch
    const duplicateSearch = picker.searchUsers('alice')

    expect(fetchUsers).toHaveBeenCalledTimes(2)
    currentRequest.resolve({ data: [{ id: 21, display_name: 'Alice Example' }] })
    await Promise.all([currentSearch, duplicateSearch])
    expect(picker.userResults.value).toEqual([{ id: 21, display_name: 'Alice Example' }])
  })
})
