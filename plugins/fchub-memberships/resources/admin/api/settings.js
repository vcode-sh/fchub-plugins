import { apiClient } from './client.js'

export const settings = {
  get: () => apiClient.get('admin/settings'),
  save: (data) => apiClient.post('admin/settings', data),
  generateApiKey: () => apiClient.post('admin/settings/generate-api-key'),
  regenerateWebhookSecret: () => apiClient.post('admin/settings/regenerate-webhook-secret'),
  testWebhook: () => apiClient.post('admin/settings/test-webhook'),
}
