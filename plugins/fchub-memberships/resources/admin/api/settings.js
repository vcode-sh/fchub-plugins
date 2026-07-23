import { apiClient } from './client.js'

export const settings = {
  get: () => apiClient.get('admin/settings'),
  save: (data) => apiClient.post('admin/settings', data),
  generateApiKey: () => apiClient.post('admin/settings/generate-api-key'),
  revokeApiKey: () => apiClient.post('admin/settings/revoke-api-key'),
  regenerateWebhookSecret: () => apiClient.post('admin/settings/regenerate-webhook-secret'),
  getWebhookHealth: () => apiClient.get('admin/webhooks/health'),
  listWebhookDeliveries: ({ page = 1, per_page = 20, status = '' } = {}) =>
    apiClient.get('admin/webhooks/deliveries', { page, per_page, status }),
  retryWebhookDelivery: (deliveryId) =>
    apiClient.post(`admin/webhooks/deliveries/${deliveryId}/retry`),
  testWebhook: () => apiClient.post('admin/webhooks/test'),
  emailNotifications: () => apiClient.get('admin/email-notifications'),
  previewEmail: (data) => apiClient.post('admin/email-notifications/preview', data),
  testEmail: (data) => apiClient.post('admin/email-notifications/test', data),
  saveEmail: (key, data) => apiClient.post(`admin/email-notifications/${key}`, data),
  saveEmailBrandTemplate: (data) => apiClient.post('admin/email-notifications/brand-template', data),
}
