import { apiClient } from './client.js'

export const settings = {
  get: () => apiClient.get('admin/settings'),
  save: (data) => apiClient.post('admin/settings', data),
  generateApiKey: () => apiClient.post('admin/settings/generate-api-key'),
  regenerateWebhookSecret: () => apiClient.post('admin/settings/regenerate-webhook-secret'),
  testWebhook: () => apiClient.post('admin/settings/test-webhook'),
  emailNotifications: () => apiClient.get('admin/email-notifications'),
  previewEmail: (data) => apiClient.post('admin/email-notifications/preview', data),
  testEmail: (data) => apiClient.post('admin/email-notifications/test', data),
  saveEmail: (key, data) => apiClient.post(`admin/email-notifications/${key}`, data),
  saveEmailBrandTemplate: (data) => apiClient.post('admin/email-notifications/brand-template', data),
}
