import { vi } from 'vitest'

function jsonResponse(data, { ok = true, status = 200, statusText = 'OK' } = {}) {
  return {
    ok,
    status,
    statusText,
    json: async () => data,
  }
}

export const routeExpectations = [
  { path: '/', text: 'Dashboard' },
  { path: '/plans', text: 'Plans' },
  { path: '/plans/new', text: 'Create New Plan' },
  { path: '/plans/5/edit', text: 'Edit Plan' },
  { path: '/members', text: 'No members found' },
  { path: '/members/21', text: 'Alice Example' },
  { path: '/import', text: 'Import Members' },
  { path: '/content', text: 'Content Protection' },
  { path: '/drip', text: 'Drip Content' },
  { path: '/drip/calendar', text: 'Drip Calendar' },
  { path: '/reports', text: 'Reports' },
  { path: '/integrations', text: 'Integrations' },
  { path: '/settings', text: 'Settings' },
  { path: '/settings/email-studio', text: 'Email Studio' },
]

function apiPayload(url) {
  if (url.includes('/admin/reports/overview')) {
    return { data: { active_members: 1, new_this_month: 1, churned_this_month: 0, churn_rate: 0 } }
  }
  if (url.includes('/admin/reports/members-over-time')) {
    return { data: [{ date: '2026-03-01', count: 1 }] }
  }
  if (url.includes('/admin/reports/plan-distribution')) {
    return { data: [{ plan_title: 'Gold Plan', count: 1 }] }
  }
  if (url.includes('/admin/reports/churn')) {
    return { data: { current_rate: 0, over_time: [{ month: '2026-03', churn_rate: 0, churned: 0, active_start: 1 }] } }
  }
  if (url.includes('/admin/reports/revenue')) {
    return { data: { per_plan: [{ plan_title: 'Gold Plan', revenue: 100 }], mrr: 100, arpm: 100, ltv: [{ plan_title: 'Gold Plan', ltv: 100, total_revenue: 100 }] } }
  }
  if (url.includes('/admin/reports/content-popularity')) {
    return { data: { most_accessed: [{ title: 'Members Post', resource_type: 'post', member_count: 1 }] } }
  }
  if (url.includes('/admin/reports/expiring-soon')) {
    return { data: [{ user_name: 'Alice Example', user_email: 'alice@example.com', plan_title: 'Gold Plan', expires_at: '2026-03-20 23:59:59' }] }
  }
  if (url.includes('/admin/reports/renewal-rate')) {
    return { data: { overall_rate: 100, renewed_members: 1, avg_renewals_per_member: 1, by_plan: [], over_time: [{ month: '2026-03', total_renewals: 1 }] } }
  }
  if (url.includes('/admin/reports/trial-conversion')) {
    return { data: { overall_rate: 100, total_trials: 1, total_converted: 1, total_dropped: 0, by_plan: [] } }
  }
  if (url.includes('/admin/plans/export-all')) {
    return { data: [] }
  }
  if (url.includes('/admin/plans/options')) {
    return { data: [{ id: 5, title: 'Gold Plan', label: 'Gold Plan' }] }
  }
  if (url.includes('/admin/plans/search-products')) {
    return { data: [] }
  }
  if (url.includes('/admin/plans/5/linked-products')) {
    return { data: [] }
  }
  if (url.includes('/admin/plans/5')) {
    return {
      data: {
        id: 5,
        title: 'Edit Plan',
        slug: 'gold-plan',
        description: '',
        status: 'active',
        level: 1,
        includes_plan_ids: [],
        duration_type: 'lifetime',
        duration_days: null,
        trial_days: 0,
        grace_period_days: 0,
        meta: { membership_term: { mode: 'none' } },
        rules: [],
      },
    }
  }
  if (url.includes('/admin/plans')) {
    return {
      data: [{
        id: 5,
        title: 'Gold Plan',
        slug: 'gold-plan',
        status: 'active',
        duration_type: 'lifetime',
        members_count: 1,
        rules_count: 0,
        drip_count: 0,
        history_count: 1,
        created_at: '2026-03-01 10:00:00',
      }],
      total: 1,
      summary: { total: 1, active: 1, needs_content: 1, scheduled: 0 },
    }
  }
  if (url.includes('/admin/members/21/activity')) {
    return { data: [{ type: 'grant_created', date: '2026-03-01 10:00:00', description: 'Access granted', metadata: { plan_title: 'Gold Plan' } }], total: 1 }
  }
  if (url.includes('/admin/members/21')) {
    return {
      data: {
        user: {
          id: 21,
          display_name: 'Alice Example',
          email: 'alice@example.com',
          user_email: 'alice@example.com',
          registered_at: '2025-01-10 09:15:00',
          avatar_url: 'https://example.com/avatar/21',
        },
        plans: [{
          plan_id: 5,
          plan_title: 'Gold Plan',
          grants: [{ id: 100, plan_id: 5, status: 'active', created_at: '2026-03-01 10:00:00', expires_at: null, source_type: 'manual' }],
          progress: { items: [] },
        }],
        history: [{ id: 100, plan_id: 5, plan_title: 'Gold Plan', status: 'active', created_at: '2026-03-01 10:00:00', expires_at: null, source_type: 'manual' }],
      },
    }
  }
  if (url.includes('/admin/members')) {
    return {
      data: [{
        user_id: 21,
        display_name: 'Alice Example',
        user_email: 'alice@example.com',
        plan_id: 5,
        plan_title: 'Gold Plan',
        status: 'active',
        created_at: '2026-03-01 10:00:00',
        expires_at: null,
        source_type: 'manual',
      }],
      total: 1,
      summary: { active: 1, expiring_soon: 0, paused: 0, ended: 0 },
    }
  }
  if (url.includes('/admin/content/resource-types')) {
    return {
      data: [{ key: 'post', label: 'Posts', group: 'content', searchable: true }],
      groups: { content: 'Content' },
      select_options: [{ value: 'post', label: 'Posts' }],
    }
  }
  if (url.includes('/admin/content/search-resources')) {
    return { data: [{ id: '55', label: 'Members Post', type: 'post', type_label: 'Posts' }] }
  }
  if (url.includes('/admin/content')) {
    return {
      data: [],
      total: 0,
      summary: {
        total_rules: 0,
        resource_types: 0,
        teaser_rules: 0,
        unassigned_rules: 0,
        type_counts: {},
      },
    }
  }
  if (url.includes('/admin/drip/calendar')) {
    return { data: { '2026-03-20': 1 } }
  }
  if (url.includes('/admin/drip/notifications')) {
    return { data: [{ id: 1, user_email: 'alice@example.com', content_title: 'Locked Lesson', plan_title: 'Gold Plan', scheduled_at: '2026-03-20 10:00:00', status: 'pending' }], total: 1 }
  }
  if (url.includes('/admin/drip/overview')) {
    return { data: { total_rules: 1, pending: 1, sent_today: 0, failed: 0 } }
  }
  if (url.includes('/admin/webhooks/health')) {
    return {
      data: {
        status: 'off',
        pending_count: 0,
        processing_count: 0,
        retrying_count: 0,
        succeeded_count: 4,
        failed_count: 1,
        last_success_at: '2026-07-22 12:00:00',
      },
    }
  }
  if (url.includes('/admin/webhooks/deliveries/91/retry')) {
    return { data: { id: 91, status: 'pending' } }
  }
  if (url.includes('/admin/webhooks/deliveries')) {
    return {
      data: {
        deliveries: [{
          id: 91,
          event_id: 'evt_smoke_failed',
          event_type: 'access.revoked',
          destination_url: 'https://hooks.example.com/memberships',
          status: 'failed',
          attempt_count: 7,
          response_code: 503,
          error_message: 'webhook_http_503',
          next_attempt_at: null,
          last_attempt_at: '2026-07-22 12:05:00',
          delivered_at: null,
          created_at: '2026-07-22 12:00:00',
          updated_at: '2026-07-22 12:05:00',
        }],
        page: 1,
        per_page: 20,
      },
    }
  }
  if (url.includes('/admin/webhooks/test')) {
    return {
      data: {
        event_id: 'evt_smoke_test',
        success: false,
        results: [{ id: 92, destination_url: 'https://hooks.example.com/memberships', status: 'retrying' }],
      },
    }
  }
  if (url.includes('/admin/settings/generate-api-key')) {
    return {
      data: {
        api_key: 'fchub_one_time_smoke_key',
        access_api: { configured: true, prefix: 'fchub_one_ti', rotated_at: '2026-07-22 12:10:00' },
      },
    }
  }
  if (url.includes('/admin/settings/revoke-api-key')) {
    return { data: { access_api: { configured: false, prefix: null, rotated_at: null } } }
  }
  if (url.includes('/admin/settings/regenerate-webhook-secret')) {
    return { data: { webhook_secret: 'webhook_one_time_smoke_secret' } }
  }
  if (url.includes('/admin/settings')) {
    return {
      data: {
        default_protection_mode: 'content_replace',
        restriction_message_no_access: 'No access',
        restriction_message_paused: 'Paused',
        default_redirect_url: '',
        email_access_granted: 'yes',
        email_access_expiring: 'yes',
        expiry_warning_days: 7,
        trial_expiry_notice_days: 3,
        email_access_revoked: 'yes',
        email_drip_unlocked: 'yes',
        hide_protected_in_archive: 'no',
        uninstall_remove_data: 'no',
        access_api: { configured: true, prefix: 'fchub_abc123', rotated_at: '2026-07-22 12:00:00' },
        debug_mode: 'no',
        webhook_enabled: 'no',
        webhook_urls: 'https://127.0.0.1/webhook',
        webhook_secret_configured: true,
        webhook_destinations_configured: false,
        webhook_status: 'needs_setup',
        fluentcrm_enabled: 'no',
        fluentcrm_tag_prefix: 'member:',
        fluentcrm_default_list: '',
        fluentcrm_auto_create_tags: 'yes',
        fc_enabled: 'no',
        fc_space_mappings: {},
        membership_mode: 'stack',
      },
    }
  }
  if (url.includes('/admin/fluentcrm-lists') || url.includes('/admin/fc-spaces') || url.includes('/admin/fc-badges')) {
    return { data: [] }
  }
  if (url.includes('/admin/import/parse')) {
    return { data: { format: 'generic', levels: [], members: [], stats: {}, warnings: [], preview: [] } }
  }
  if (url.includes('/admin/import/prepare') || url.includes('/admin/import/execute')) {
    return { data: { mappings: [], processed: 0 } }
  }

  return { data: {} }
}

export function createAdminFetchMock() {
  return vi.fn(async (input) => {
    const url = String(input)
    return jsonResponse(apiPayload(url))
  })
}

export function createErrorResponse(message = 'Request failed', status = 422) {
  return jsonResponse({ message }, { ok: false, status, statusText: message })
}
