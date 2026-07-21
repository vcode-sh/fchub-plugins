import { ref } from 'vue'

const MIN_USER_QUERY_LENGTH = 2

function normaliseUsers(response) {
  const users = response?.data ?? response ?? []
  return Array.isArray(users) ? users : []
}

export function useGrantAccessUserPicker({ fetchUsers }) {
  const searchingUsers = ref(false)
  const userResults = ref([])
  const userSearchError = ref('')
  const resultCache = new Map()
  const pendingRequests = new Map()
  let activeRequestId = 0
  let searchSession = 0

  function queryKey(query) {
    return `${searchSession}:${query.toLocaleLowerCase()}`
  }

  function requestUsers(query) {
    const key = queryKey(query)
    const pending = pendingRequests.get(key)

    if (pending) {
      return pending
    }

    let request
    request = Promise.resolve(fetchUsers({
      search: query,
      per_page: 10,
      users_only: true,
    })).finally(() => {
      if (pendingRequests.get(key) === request) {
        pendingRequests.delete(key)
      }
    })

    pendingRequests.set(key, request)
    return request
  }

  async function searchUsers(query = '') {
    const normalisedQuery = String(query ?? '').trim()
    const effectiveQuery = normalisedQuery.length < MIN_USER_QUERY_LENGTH ? '' : normalisedQuery
    const key = queryKey(effectiveQuery)
    const requestId = ++activeRequestId

    userSearchError.value = ''

    if (resultCache.has(key)) {
      userResults.value = resultCache.get(key)
      searchingUsers.value = false
      return
    }

    searchingUsers.value = true

    try {
      const response = await requestUsers(effectiveQuery)
      const users = normaliseUsers(response)
      resultCache.set(key, users)

      if (requestId === activeRequestId) {
        userResults.value = users
      }
    } catch (error) {
      if (requestId === activeRequestId) {
        userResults.value = []
        userSearchError.value = error?.message || 'User search is temporarily unavailable.'
      }
    } finally {
      if (requestId === activeRequestId) {
        searchingUsers.value = false
      }
    }
  }

  function resetUserSearch() {
    activeRequestId += 1
    searchSession += 1
    resultCache.clear()
    pendingRequests.clear()
    searchingUsers.value = false
    userResults.value = []
    userSearchError.value = ''
  }

  return {
    searchingUsers,
    userResults,
    userSearchError,
    searchUsers,
    resetUserSearch,
  }
}
