import { apiClient } from './client.js'

export const importMembers = {
  parse: (data) => apiClient.post('admin/import/parse', data),
  prepare: (data) => apiClient.post('admin/import/prepare', data),
  execute: (data) => apiClient.post('admin/import/execute', data),
}
