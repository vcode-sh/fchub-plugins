import { apiClient } from './client.js'

export const account = {
  myAccess: () => apiClient.get('my-access'),
}
