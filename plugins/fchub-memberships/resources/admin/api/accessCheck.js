import { apiClient } from './client.js'

export const accessCheck = {
  check: (params) => apiClient.get('check-access', params),
}
