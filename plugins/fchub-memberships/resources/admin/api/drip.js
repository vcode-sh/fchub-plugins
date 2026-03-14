import { apiClient } from './client.js'

export const drip = {
  overview: () => apiClient.get('admin/drip/overview'),
  calendar: (params) => apiClient.get('admin/drip/calendar', params),
  queue: (params) => apiClient.get('admin/drip/notifications', params),
  retry: (id) => apiClient.post(`admin/drip/notifications/${id}/retry`),
  stats: () => apiClient.get('admin/drip/stats'),
}
