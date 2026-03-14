import { apiClient } from './client.js'

export const members = {
  list: (params) => apiClient.get('admin/members', params),
  get: (id) => apiClient.get(`admin/members/${id}`),
  grant: (data) => apiClient.post('admin/members/grant', data),
  revoke: (data) => apiClient.post('admin/members/revoke', data),
  extend: (data) => apiClient.post('admin/members/extend', data),
  dripTimeline: (userId) => apiClient.get(`admin/members/${userId}/drip-timeline`),
  export: (params) => apiClient.get('admin/members/export', params),
  pause: (data) => apiClient.post('admin/members/pause', data),
  resume: (data) => apiClient.post('admin/members/resume', data),
  bulkGrant: (data) => apiClient.post('admin/members/bulk-grant', data),
  bulkRevoke: (data) => apiClient.post('admin/members/bulk-revoke', data),
  bulkExtend: (data) => apiClient.post('admin/members/bulk-extend', data),
  bulkExport: (data) => apiClient.post('admin/members/bulk-export', data),
  auditLog: (userId, params) => apiClient.get(`admin/members/${userId}/audit-log`, params),
  activity: (userId, params) => apiClient.get(`admin/members/${userId}/activity`, params),
}
