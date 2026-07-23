import { apiClient } from './client.js'

export const providers = {
  list: () => apiClient.get('admin/providers'),
}
