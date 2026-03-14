import { apiClient } from './client.js'

export const content = {
  list: (params) => apiClient.get('admin/content', params),
  resourceTypes: () => apiClient.get('admin/content/resource-types'),
  searchResources: (params) => apiClient.get('admin/content/search-resources', params),
  protect: (data) => apiClient.post('admin/content/protect', data),
  unprotect: (id) => apiClient.del(`admin/content/${id}`),
  unprotectByResource: (data) => apiClient.post('admin/content/unprotect', data),
  bulkProtect: (data) => apiClient.post('admin/content/bulk-protect', data),
  bulkUnprotect: (data) => apiClient.post('admin/content/bulk-unprotect', data),
  update: (id, data) => apiClient.put(`admin/content/${id}`, data),
  remove: (id) => apiClient.del(`admin/content/${id}`),
}
