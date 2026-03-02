const config = window.fchubMembershipsAdmin || {};
const baseUrl = (config.rest_url || '/wp-json/fchub-memberships/v1/').replace(/\/$/, '');
const nonce = config.nonce || '';

async function request(method, endpoint, { params, body } = {}) {
    let url = `${baseUrl}/${endpoint}`;

    if (params) {
        const qs = new URLSearchParams(
            Object.fromEntries(Object.entries(params).filter(([, v]) => v != null && v !== ''))
        ).toString();
        if (qs) url += `?${qs}`;
    }

    const options = {
        method,
        headers: { 'X-WP-Nonce': nonce },
    };

    if (body !== undefined) {
        options.headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(body);
    }

    const response = await fetch(url, options);

    if (!response.ok) {
        const error = await response.json().catch(() => ({}));
        throw Object.assign(new Error(error.message || response.statusText), {
            status: response.status,
            data: error,
        });
    }

    return response.json();
}

const api = {
    get: (endpoint, params) => request('GET', endpoint, { params }),
    post: (endpoint, data) => request('POST', endpoint, { body: data }),
    put: (endpoint, data) => request('PUT', endpoint, { body: data }),
    del: (endpoint) => request('DELETE', endpoint),
};

export default api;

export const plans = {
    list: (params) => api.get('admin/plans', params),
    get: (id) => api.get(`admin/plans/${id}`),
    create: (data) => api.post('admin/plans', data),
    update: (id, data) => api.put(`admin/plans/${id}`, data),
    remove: (id) => api.del(`admin/plans/${id}`),
    duplicate: (id) => api.post(`admin/plans/${id}/duplicate`),
    options: () => api.get('admin/plans/options'),
    dripSchedule: (id) => api.get(`admin/plans/${id}/drip-schedule`),
    linkedProducts: (id) => api.get(`admin/plans/${id}/linked-products`),
    linkProduct: (id, data) => api.post(`admin/plans/${id}/link-product`, data),
    unlinkProduct: (id, feedId) => api.del(`admin/plans/${id}/unlink-product/${feedId}`),
    searchProducts: (params) => api.get('admin/plans/search-products', params),
    export: (id) => api.get(`admin/plans/${id}/export`),
    exportAll: () => api.get('admin/plans/export-all'),
    import: (data) => api.post('admin/plans/import', data),
    schedule: (id, data) => api.post(`admin/plans/${id}/schedule`, data),
    resolveResources: (data) => api.post('admin/plans/resolve-resources', data),
};

export const members = {
    list: (params) => api.get('admin/members', params),
    get: (id) => api.get(`admin/members/${id}`),
    grant: (data) => api.post('admin/members/grant', data),
    revoke: (data) => api.post('admin/members/revoke', data),
    extend: (data) => api.post('admin/members/extend', data),
    dripTimeline: (userId) => api.get(`admin/members/${userId}/drip-timeline`),
    export: (params) => api.get('admin/members/export', params),
    pause: (data) => api.post('admin/members/pause', data),
    resume: (data) => api.post('admin/members/resume', data),
    bulkGrant: (data) => api.post('admin/members/bulk-grant', data),
    bulkRevoke: (data) => api.post('admin/members/bulk-revoke', data),
    bulkExtend: (data) => api.post('admin/members/bulk-extend', data),
    bulkExport: (data) => api.post('admin/members/bulk-export', data),
    auditLog: (userId, params) => api.get(`admin/members/${userId}/audit-log`, params),
    activity: (userId, params) => api.get(`admin/members/${userId}/activity`, params),
};

export const content = {
    list: (params) => api.get('admin/content', params),
    resourceTypes: () => api.get('admin/content/resource-types'),
    searchResources: (params) => api.get('admin/content/search-resources', params),
    protect: (data) => api.post('admin/content/protect', data),
    unprotect: (id) => api.del(`admin/content/${id}`),
    unprotectByResource: (data) => api.post('admin/content/unprotect', data),
    bulkProtect: (data) => api.post('admin/content/bulk-protect', data),
    bulkUnprotect: (data) => api.post('admin/content/bulk-unprotect', data),
    update: (id, data) => api.put(`admin/content/${id}`, data),
    remove: (id) => api.del(`admin/content/${id}`),
};

export const drip = {
    overview: () => api.get('admin/drip/overview'),
    calendar: (params) => api.get('admin/drip/calendar', params),
    queue: (params) => api.get('admin/drip/notifications', params),
    retry: (id) => api.post(`admin/drip/notifications/${id}/retry`),
    stats: () => api.get('admin/drip/stats'),
};

export const reports = {
    overview: (params) => api.get('admin/reports/overview', params),
    membersOverTime: (params) => api.get('admin/reports/members-over-time', params),
    planDistribution: (params) => api.get('admin/reports/plan-distribution', params),
    churn: (params) => api.get('admin/reports/churn', params),
    retentionCohort: (params) => api.get('admin/reports/retention-cohort', params),
    revenue: (params) => api.get('admin/reports/revenue', params),
    contentPopularity: (params) => api.get('admin/reports/content-popularity', params),
    expiringSoon: (params) => api.get('admin/reports/expiring-soon', params),
    renewalRate: (params) => api.get('admin/reports/renewal-rate', params),
    trialConversion: (params) => api.get('admin/reports/trial-conversion', params),
};

export const settings = {
    get: () => api.get('admin/settings'),
    save: (data) => api.post('admin/settings', data),
    generateApiKey: () => api.post('admin/settings/generate-api-key'),
    regenerateWebhookSecret: () => api.post('admin/settings/regenerate-webhook-secret'),
    testWebhook: () => api.post('admin/settings/test-webhook'),
};

export const importMembers = {
    parse: (data) => api.post('admin/import/parse', data),
    prepare: (data) => api.post('admin/import/prepare', data),
    execute: (data) => api.post('admin/import/execute', data),
};

export const account = {
    myAccess: () => api.get('my-access'),
};

export const accessCheck = {
    check: (params) => api.get('check-access', params),
};
