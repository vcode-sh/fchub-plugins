import { apiClient } from './client.js'

export const plans = {
  list: (params) => apiClient.get('admin/plans', params),
  get: (id) => apiClient.get(`admin/plans/${id}`),
  create: (data) => apiClient.post('admin/plans', data),
  update: (id, data) => apiClient.put(`admin/plans/${id}`, data),
  remove: (id) => apiClient.del(`admin/plans/${id}`),
  duplicate: (id) => apiClient.post(`admin/plans/${id}/duplicate`),
  options: () => apiClient.get('admin/plans/options'),
  dripSchedule: (id) => apiClient.get(`admin/plans/${id}/drip-schedule`),
  linkedProducts: (id) => apiClient.get(`admin/plans/${id}/linked-products`),
  linkProduct: (id, data) => apiClient.post(`admin/plans/${id}/link-product`, data),
  unlinkProduct: (id, feedId) => apiClient.del(`admin/plans/${id}/unlink-product/${feedId}`),
  searchProducts: (params) => apiClient.get('admin/plans/search-products', params),
  export: (id) => apiClient.get(`admin/plans/${id}/export`),
  exportAll: () => apiClient.get('admin/plans/export-all'),
  import: (data) => apiClient.post('admin/plans/import', data),
  schedule: (id, data) => apiClient.post(`admin/plans/${id}/schedule`, data),
  resolveResources: (data) => apiClient.post('admin/plans/resolve-resources', data),
}
