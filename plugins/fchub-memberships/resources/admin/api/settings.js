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
  cancelWebhookDelivery: (deliveryId) =>
    apiClient.post(`admin/webhooks/deliveries/${deliveryId}/cancel`),
  testWebhook: () => apiClient.post('admin/webhooks/test'),
  listWebhookEndpoints: () => apiClient.get('admin/webhooks/endpoints'),
  createWebhookEndpoint: (payload) => apiClient.post('admin/webhooks/endpoints', payload),
  rotateWebhookEndpointSecret: (endpointId) =>
    apiClient.post(`admin/webhooks/endpoints/${endpointId}/secret`),
  testWebhookEndpoint: (endpointId) =>
    apiClient.post(`admin/webhooks/endpoints/${endpointId}/test`),
  activateWebhookEndpoint: (endpointId) =>
    apiClient.post(`admin/webhooks/endpoints/${endpointId}/activate`),
  pauseWebhookEndpoint: (endpointId) =>
    apiClient.post(`admin/webhooks/endpoints/${endpointId}/pause`),
  deleteWebhookEndpoint: (endpointId) =>
    apiClient.del(`admin/webhooks/endpoints/${endpointId}`),
  emailNotifications: () => apiClient.get('admin/email-notifications'),
  previewEmail: (data) => apiClient.post('admin/email-notifications/preview', data),
  testEmail: (data) => apiClient.post('admin/email-notifications/test', data),
  saveEmail: (key, data) => apiClient.post(`admin/email-notifications/${key}`, data),
  saveEmailBrandTemplate: (data) => apiClient.post('admin/email-notifications/brand-template', data),
}
