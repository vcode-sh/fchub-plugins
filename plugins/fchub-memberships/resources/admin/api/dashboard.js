import { apiClient } from './client.js'

export const dashboard = {
  load: () => apiClient.get('admin/dashboard'),
}
