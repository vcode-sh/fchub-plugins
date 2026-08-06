import { computed, ref } from 'vue'

function errorMessage(error, fallback) {
  return error?.message || fallback
}

function normaliseAccessApi(value) {
  return {
    configured: value?.configured === true,
    prefix: value?.configured === true ? (value.prefix ?? null) : null,
    rotated_at: value?.configured === true ? (value.rotated_at ?? null) : null,
  }
}

function normaliseWebhookStatus(value) {
  return ['off', 'needs_setup', 'ready', 'degraded'].includes(value) ? value : 'off'
}

export function useWebhookSettingsOperations({ settings, isActive, confirm }) {
  const apiKeyBusy = ref(false)
  const webhookSecretBusy = ref(false)
  const revokeApiKeyBusy = ref(false)
  const testingWebhook = ref(false)
  const testResults = ref([])
  const accessApi = ref({ configured: false, prefix: null, rotated_at: null })
  const webhookStatus = ref('off')
  const webhookSecretConfigured = ref(false)
  const webhookDestinationsConfigured = ref(false)
  const webhookError = ref('')
  const oneTimeCredentials = ref({ apiKey: '', webhookSecret: '' })
  const webhookDeliveries = ref([])
  const webhookEndpoints = ref([])
  const endpointBusy = ref({ create: false })
  const oneTimeEndpointSecret = ref({ secret: '', name: '' })
  const webhookHistoryLoading = ref(false)
  const webhookHistoryError = ref('')
  const retryingDeliveryId = ref(null)
  let webhookRequestVersion = 0
  let credentialRequestGeneration = 0

  const credentialMutationBusy = computed(() => (
    apiKeyBusy.value || webhookSecretBusy.value || revokeApiKeyBusy.value
  ))
  const webhookBusy = computed(() => ({
    credentialMutation: credentialMutationBusy.value,
    apiKey: apiKeyBusy.value,
    webhookSecret: webhookSecretBusy.value,
    revokeApiKey: revokeApiKeyBusy.value,
    test: testingWebhook.value,
  }))
  const webhookHistory = computed(() => ({
    deliveries: webhookDeliveries.value,
    loading: webhookHistoryLoading.value,
    error: webhookHistoryError.value,
    retryingId: retryingDeliveryId.value,
  }))
  const hasPendingCredentialAcknowledgement = computed(() => Boolean(
    oneTimeCredentials.value.apiKey || oneTimeCredentials.value.webhookSecret
  ))

  function hydrate(data) {
    accessApi.value = normaliseAccessApi(data.access_api)
    webhookSecretConfigured.value = data.webhook_secret_configured === true
    webhookDestinationsConfigured.value = data.webhook_destinations_configured === true
    webhookStatus.value = normaliseWebhookStatus(data.webhook_status)
  }

  function applySaveResponse(data) {
    if (data.access_api) accessApi.value = normaliseAccessApi(data.access_api)
    if (typeof data.webhook_secret_configured === 'boolean') {
      webhookSecretConfigured.value = data.webhook_secret_configured
    }
    if (typeof data.webhook_destinations_configured === 'boolean') {
      webhookDestinationsConfigured.value = data.webhook_destinations_configured
    }
    if (data.webhook_status) webhookStatus.value = normaliseWebhookStatus(data.webhook_status)
    webhookError.value = ''
  }

  function clearOneTimeApiKey() {
    oneTimeCredentials.value = { ...oneTimeCredentials.value, apiKey: '' }
  }

  function clearOneTimeEndpointSecret() {
    oneTimeEndpointSecret.value = { secret: '', name: '' }
  }

  function clearOneTimeCredentials() {
    oneTimeCredentials.value = { apiKey: '', webhookSecret: '' }
    clearOneTimeEndpointSecret()
  }

  function invalidateWebhookRequests() {
    webhookRequestVersion += 1
    webhookHistoryLoading.value = false
    retryingDeliveryId.value = null
  }

  function invalidateCredentialRequests() {
    credentialRequestGeneration += 1
    apiKeyBusy.value = false
    webhookSecretBusy.value = false
    revokeApiKeyBusy.value = false
  }

  function credentialRequestIsCurrent(generation) {
    return generation === credentialRequestGeneration && isActive()
  }

  async function generateApiKey() {
    if (credentialMutationBusy.value || !isActive()) return
    const generation = credentialRequestGeneration
    apiKeyBusy.value = true
    webhookError.value = ''
    try {
      const response = await settings.generateApiKey()
      if (!credentialRequestIsCurrent(generation)) return
      const data = response.data ?? response
      if (typeof data.api_key !== 'string' || data.api_key === '') {
        throw new Error('The settings service did not return the new API key.')
      }
      accessApi.value = normaliseAccessApi(data.access_api)
      oneTimeCredentials.value = { apiKey: data.api_key, webhookSecret: '' }
    } catch (error) {
      if (credentialRequestIsCurrent(generation)) webhookError.value = errorMessage(error, 'The API key could not be generated.')
    } finally {
      if (credentialRequestIsCurrent(generation)) apiKeyBusy.value = false
    }
  }

  async function regenerateApiKey() {
    if (credentialMutationBusy.value || !await confirm('This invalidates the current API key immediately. Continue?', 'Regenerate API key', 'Regenerate')) return
    await generateApiKey()
  }

  async function revokeApiKey() {
    if (credentialMutationBusy.value || !await confirm('This immediately rejects external requests using the current key. Continue?', 'Revoke API key', 'Revoke')) return
    if (!isActive() || credentialMutationBusy.value) return
    const generation = credentialRequestGeneration
    revokeApiKeyBusy.value = true
    webhookError.value = ''
    clearOneTimeApiKey()
    try {
      const response = await settings.revokeApiKey()
      if (!credentialRequestIsCurrent(generation)) return
      accessApi.value = normaliseAccessApi((response.data ?? response).access_api)
    } catch (error) {
      if (credentialRequestIsCurrent(generation)) webhookError.value = errorMessage(error, 'The API key could not be revoked.')
    } finally {
      if (credentialRequestIsCurrent(generation)) revokeApiKeyBusy.value = false
    }
  }

  async function issueWebhookSecret() {
    if (!isActive() || credentialMutationBusy.value) return
    const generation = credentialRequestGeneration
    webhookSecretBusy.value = true
    webhookError.value = ''
    try {
      const response = await settings.regenerateWebhookSecret()
      if (!credentialRequestIsCurrent(generation)) return
      const data = response.data ?? response
      if (typeof data.webhook_secret !== 'string' || data.webhook_secret === '') {
        throw new Error('The settings service did not return the new webhook secret.')
      }
      webhookSecretConfigured.value = true
      oneTimeCredentials.value = { apiKey: '', webhookSecret: data.webhook_secret }
    } catch (error) {
      if (credentialRequestIsCurrent(generation)) webhookError.value = errorMessage(error, 'The webhook secret could not be generated.')
    } finally {
      if (credentialRequestIsCurrent(generation)) webhookSecretBusy.value = false
    }
  }

  async function generateWebhookSecret() {
    if (!credentialMutationBusy.value) await issueWebhookSecret()
  }

  async function regenerateWebhookSecret() {
    if (credentialMutationBusy.value || !await confirm('This invalidates the current signing secret immediately. Continue?', 'Regenerate webhook secret', 'Regenerate')) return
    await issueWebhookSecret()
  }

  async function refreshWebhookOperationalState() {
    if (!isActive()) return
    const requestVersion = ++webhookRequestVersion
    webhookHistoryLoading.value = true
    webhookHistoryError.value = ''
    webhookError.value = ''
    const [healthResult, historyResult, endpointsResult] = await Promise.allSettled([
      settings.getWebhookHealth(),
      settings.listWebhookDeliveries({ page: 1, per_page: 20, status: '' }),
      settings.listWebhookEndpoints(),
    ])
    if (requestVersion !== webhookRequestVersion || !isActive()) return
    if (healthResult.status === 'fulfilled') webhookStatus.value = normaliseWebhookStatus((healthResult.value.data ?? healthResult.value).status)
    else webhookError.value = errorMessage(healthResult.reason, 'Webhook health could not be loaded.')
    if (historyResult.status === 'fulfilled') {
      const history = historyResult.value.data ?? historyResult.value
      webhookDeliveries.value = Array.isArray(history.deliveries) ? history.deliveries.slice(0, 20) : []
    } else webhookHistoryError.value = errorMessage(historyResult.reason, 'Delivery history could not be loaded.')
    if (endpointsResult.status === 'fulfilled') {
      const endpointData = endpointsResult.value.data ?? endpointsResult.value
      webhookEndpoints.value = Array.isArray(endpointData.endpoints) ? endpointData.endpoints : []
    } else webhookError.value = errorMessage(endpointsResult.reason, 'Webhook endpoints could not be loaded.')
    webhookHistoryLoading.value = false
  }

  async function retryWebhookDelivery(deliveryId) {
    retryingDeliveryId.value = deliveryId
    webhookHistoryError.value = ''
    try {
      await settings.retryWebhookDelivery(deliveryId)
      await refreshWebhookOperationalState()
    } catch (error) {
      webhookHistoryError.value = errorMessage(error, 'The delivery could not be retried.')
    } finally {
      retryingDeliveryId.value = null
    }
  }

  async function cancelWebhookDelivery(deliveryId) {
    webhookHistoryError.value = ''
    try {
      await settings.cancelWebhookDelivery(deliveryId)
      await refreshWebhookOperationalState()
    } catch (error) {
      webhookHistoryError.value = errorMessage(error, 'The delivery could not be stopped.')
    }
  }

  function setEndpointBusy(id, action, value) {
    endpointBusy.value = id === 'create'
      ? { ...endpointBusy.value, create: value }
      : { ...endpointBusy.value, [id]: { ...(endpointBusy.value[id] || {}), [action]: value } }
  }

  async function createWebhookEndpoint(payload) {
    setEndpointBusy('create', 'create', true)
    webhookError.value = ''
    try {
      await settings.createWebhookEndpoint(payload)
      await refreshWebhookOperationalState()
      return true
    } catch (error) {
      webhookError.value = errorMessage(error, 'The webhook endpoint could not be added.')
      return false
    } finally {
      setEndpointBusy('create', 'create', false)
    }
  }

  async function rotateWebhookEndpointSecret(endpointId) {
    setEndpointBusy(endpointId, 'secret', true)
    webhookError.value = ''
    try {
      const response = await settings.rotateWebhookEndpointSecret(endpointId)
      const data = response.data ?? response
      if (!data.secret) throw new Error('The endpoint service did not return the new secret.')
      const endpoint = data.endpoint || webhookEndpoints.value.find(({ id }) => id === endpointId) || {}
      oneTimeEndpointSecret.value = { secret: data.secret, name: endpoint.name || 'webhook endpoint' }
      await refreshWebhookOperationalState()
    } catch (error) {
      webhookError.value = errorMessage(error, 'The endpoint secret could not be generated.')
    } finally {
      setEndpointBusy(endpointId, 'secret', false)
    }
  }

  async function runEndpointAction(endpointId, action, request, fallback) {
    setEndpointBusy(endpointId, action, true)
    webhookError.value = ''
    try {
      await request(endpointId)
      await refreshWebhookOperationalState()
    } catch (error) {
      webhookError.value = errorMessage(error, fallback)
    } finally {
      setEndpointBusy(endpointId, action, false)
    }
  }

  async function deleteWebhookEndpoint(id) {
    if (!await confirm('This removes the endpoint secret and stops future deliveries. Continue?', 'Delete webhook endpoint', 'Delete')) return
    await runEndpointAction(id, 'delete', settings.deleteWebhookEndpoint, 'The endpoint could not be deleted.')
  }

  return {
    apiKeyBusy,
    webhookSecretBusy,
    revokeApiKeyBusy,
    testingWebhook,
    testResults,
    accessApi,
    webhookStatus,
    webhookSecretConfigured,
    webhookDestinationsConfigured,
    webhookError,
    oneTimeCredentials,
    webhookEndpoints,
    endpointBusy,
    oneTimeEndpointSecret,
    webhookBusy,
    webhookHistory,
    hasPendingCredentialAcknowledgement,
    hydrate,
    applySaveResponse,
    clearOneTimeApiKey,
    clearOneTimeEndpointSecret,
    clearOneTimeCredentials,
    invalidateWebhookRequests,
    invalidateCredentialRequests,
    generateApiKey,
    regenerateApiKey,
    revokeApiKey,
    generateWebhookSecret,
    regenerateWebhookSecret,
    refreshWebhookOperationalState,
    retryWebhookDelivery,
    cancelWebhookDelivery,
    actions: {
      generateApiKey,
      regenerateApiKey,
      revokeApiKey,
      createEndpoint: createWebhookEndpoint,
      rotateEndpointSecret: rotateWebhookEndpointSecret,
      testEndpoint: (id) => runEndpointAction(id, 'test', settings.testWebhookEndpoint, 'The one-shot endpoint test failed.'),
      activateEndpoint: (id) => runEndpointAction(id, 'activate', settings.activateWebhookEndpoint, 'The endpoint could not be activated.'),
      pauseEndpoint: (id) => runEndpointAction(id, 'pause', settings.pauseWebhookEndpoint, 'The endpoint could not be paused.'),
      deleteEndpoint: deleteWebhookEndpoint,
    },
  }
}
