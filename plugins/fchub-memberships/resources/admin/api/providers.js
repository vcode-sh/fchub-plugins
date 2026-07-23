import { apiClient } from './client.js'

export const providers = {
  list: () => apiClient.get('admin/providers'),
  fluentCrmHealth: () => apiClient.get('admin/integrations/fluentcrm/health'),
  reconciliationPage: (params) => apiClient.get('admin/provider-reconciliation', params),
}
