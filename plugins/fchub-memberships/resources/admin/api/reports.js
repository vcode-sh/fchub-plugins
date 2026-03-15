import { apiClient } from './client.js'

export const reports = {
  overview: (params) => apiClient.get('admin/reports/overview', params),
  membersOverTime: (params) => apiClient.get('admin/reports/members-over-time', params),
  planDistribution: (params) => apiClient.get('admin/reports/plan-distribution', params),
  churn: (params) => apiClient.get('admin/reports/churn', params),
  revenue: (params) => apiClient.get('admin/reports/revenue', params),
  contentPopularity: (params) => apiClient.get('admin/reports/content-popularity', params),
  expiringSoon: (params) => apiClient.get('admin/reports/expiring-soon', params),
  renewalRate: (params) => apiClient.get('admin/reports/renewal-rate', params),
  trialConversion: (params) => apiClient.get('admin/reports/trial-conversion', params),
  retentionCohort: (params) => apiClient.get('admin/reports/retention-cohort', params),
}
