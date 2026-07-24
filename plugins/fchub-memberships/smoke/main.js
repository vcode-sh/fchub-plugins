window.fchubMembershipsAdmin = {
  rest_url: 'https://example.com/wp-json/fchub-memberships/v1/',
  nonce: 'nonce',
  locale: 'en_US',
  date_format: 'Y-m-d',
  time_format: 'H:i',
  currency: {
    code: 'USD',
    symbol: '$',
    position: 'before',
    decimal_sep: '.',
    thousand_sep: ',',
  },
}

window.matchMedia = window.matchMedia || (() => ({
  matches: false,
  addEventListener() {},
  removeEventListener() {},
}))

Object.defineProperty(navigator, 'clipboard', {
  configurable: true,
  value: {
    writeText: async () => undefined,
  },
})

window.ResizeObserver = window.ResizeObserver || class {
  observe() {}
  unobserve() {}
  disconnect() {}
}

window.__fchubSmokeRequests = []
window.__fchubSmokeHoldMutations = false
window.__fchubSmokeReleaseMutation = null
window.__fchubSmokeFailResourceSearch = false
window.__fchubSmokeWebhookHistoryReads = 0
window.__fchubSmokeHoldCredentials = false
window.__fchubSmokeReleaseCredential = null

let smokeWebhookUrls = 'https://127.0.0.1/webhook'
let smokeWebhookDestinationsConfigured = false
let smokeWebhookRetried = false
let smokeWebhookCancelled = false
let smokeWebhookEndpointSequence = 0
let smokeWebhookEndpoints = []
let smokeAccessApi = {
  configured: true,
  prefix: 'fchub_abc123',
  rotated_at: '2026-07-22 12:00:00',
}
const smokeRuntimeSettingsStorageKey = 'fchub-smoke-runtime-settings'
const smokeRuntimeSettingsDefaults = {
  expiry_warning_days: 7,
  trial_expiry_notice_days: 3,
  hide_protected_in_archive: 'no',
  uninstall_remove_data: 'no',
}
let smokeRuntimeSettings = { ...smokeRuntimeSettingsDefaults }
try {
  smokeRuntimeSettings = {
    ...smokeRuntimeSettings,
    ...JSON.parse(window.sessionStorage.getItem(smokeRuntimeSettingsStorageKey) || '{}'),
  }
} catch {
  window.sessionStorage.removeItem(smokeRuntimeSettingsStorageKey)
}

const emailTheme = {
  logo_url: '',
  logo_width: 160,
  header_style: 'brand',
  header_text: '',
  header_alignment: 'center',
  header_background: '#2563eb',
  primary_color: '#2563eb',
  background_color: '#f3f4f6',
  panel_color: '#ffffff',
  content_color: '#374151',
  link_color: '#2563eb',
  content_width: 600,
  content_padding: 32,
  border_radius: 12,
  font_family: 'system',
  footer_text: '',
  footer_html: '',
  footer_background: '#f9fafb',
  footer_color: '#6b7280',
}

const commonEmailVariables = {
  '{user_name}': { label: 'Member name', type: 'text', sample: 'Jamie Member' },
  '{user_email}': { label: 'Member email', type: 'text', sample: 'jamie@example.com' },
  '{plan_name}': { label: 'Plan name', type: 'text', sample: 'Premium Membership' },
  '{site_name}': { label: 'Site name', type: 'text', sample: 'FCHub Playground' },
}

const emailNotification = ({ key, label, description, group, settingKey, variables, subject, preheader, content }) => {
  const defaultTemplate = {
    version: 1,
    subject,
    preheader,
    blocks: [{ id: 'message-content', type: 'rich_text', content }],
  }

  return {
    key,
    label,
    description,
    group,
    setting_key: settingKey,
    variables: { ...commonEmailVariables, ...variables },
    delivery: 'built_in',
    template: {
      ...defaultTemplate,
      blocks: defaultTemplate.blocks.map((block) => ({ ...block })),
    },
    default_template: defaultTemplate,
    theme_override: null,
  }
}

window.fetch = async (input, init = {}) => {
  const url = String(input)
  const method = String(init.method || 'GET').toUpperCase()
  let requestBody = null

  if (method !== 'GET') {
    requestBody = init.body ?? null
    if (typeof requestBody === 'string') {
      try {
        requestBody = JSON.parse(requestBody)
      } catch {
        // Keep malformed bodies visible to the test instead of concealing them.
      }
    }
    window.__fchubSmokeRequests.push({ url, method, body: requestBody })

    if (window.__fchubSmokeHoldMutations) {
      await new Promise((resolve) => {
        window.__fchubSmokeReleaseMutation = resolve
      })
    }
  }

  if (url.includes('/admin/reports/overview')) {
    return { ok: true, status: 200, json: async () => ({ data: { active_members: 1, new_this_month: 1, churned_this_month: 0, churn_rate: 0 } }) }
  }
  if (url.includes('/admin/reports/members-over-time')) {
    return { ok: true, status: 200, json: async () => ({ data: [{ date: '2026-03-01', count: 1 }] }) }
  }
  if (url.includes('/admin/reports/plan-distribution')) {
    return { ok: true, status: 200, json: async () => ({ data: [{ plan_title: 'Gold Plan', count: 1 }] }) }
  }
  if (url.includes('/admin/reports/churn')) {
    return { ok: true, status: 200, json: async () => ({ data: { current_rate: 0, over_time: [{ month: '2026-03', churn_rate: 0, churned: 0, active_start: 1 }] } }) }
  }
  if (url.includes('/admin/reports/revenue')) {
    return { ok: true, status: 200, json: async () => ({ data: { per_plan: [{ plan_title: 'Gold Plan', revenue: 100 }], mrr: 100, arpm: 100, ltv: [{ plan_title: 'Gold Plan', ltv: 100, total_revenue: 100 }] } }) }
  }
  if (url.includes('/admin/reports/content-popularity')) {
    return { ok: true, status: 200, json: async () => ({ data: { most_accessed: [{ title: 'Members Post', resource_type: 'post', member_count: 1 }] } }) }
  }
  if (url.includes('/admin/reports/expiring-soon')) {
    return { ok: true, status: 200, json: async () => ({ data: [{ user_name: 'Alice Example', user_email: 'alice@example.com', plan_title: 'Gold Plan', expires_at: '2026-03-20' }] }) }
  }
  if (url.includes('/admin/reports/renewal-rate')) {
    return { ok: true, status: 200, json: async () => ({ data: { overall_rate: 100, renewed_members: 1, avg_renewals_per_member: 1, by_plan: [], over_time: [{ month: '2026-03', total_renewals: 1 }] } }) }
  }
  if (url.includes('/admin/reports/trial-conversion')) {
    return { ok: true, status: 200, json: async () => ({ data: { overall_rate: 100, total_trials: 1, total_converted: 1, total_dropped: 0, by_plan: [] } }) }
  }
  if (url.includes('/admin/plans/options')) {
    return { ok: true, status: 200, json: async () => ({ data: [{ id: 5, title: 'Gold Plan', label: 'Gold Plan' }] }) }
  }
  if (url.includes('/admin/plans/search-products')) {
    return { ok: true, status: 200, json: async () => ({ data: [] }) }
  }
  if (url.includes('/admin/plans/5/linked-products')) {
    return { ok: true, status: 200, json: async () => ({ data: [] }) }
  }
  if (url.endsWith('/admin/plans/5') && method === 'PUT') {
    return { ok: true, status: 200, json: async () => ({ data: { id: 5, ...JSON.parse(init.body || '{}') } }) }
  }
  if (url.includes('/admin/plans/5')) {
    return {
      ok: true,
      status: 200,
      json: async () => ({
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
      }),
    }
  }
  if (url.endsWith('/admin/plans') && method === 'POST') {
    return { ok: true, status: 201, json: async () => ({ data: { id: 6, ...JSON.parse(init.body || '{}') } }) }
  }
  if (url.includes('/admin/plans')) {
    return { ok: true, status: 200, json: async () => ({
      data: [{ id: 5, title: 'Gold Plan', slug: 'gold-plan', status: 'active', duration_type: 'lifetime', members_count: 1, rules_count: 0, drip_count: 0, history_count: 1, created_at: '2026-03-01 10:00:00' }],
      total: 1,
      summary: { total: 1, active: 1, needs_content: 1, scheduled: 0 },
    }) }
  }
  if (url.includes('/admin/members/21/activity')) {
    return { ok: true, status: 200, json: async () => ({ data: [{ type: 'grant_created', date: '2026-03-01 10:00:00', description: 'Access granted', metadata: { plan_title: 'Gold Plan' } }], total: 1 }) }
  }
  if (url.includes('/admin/members/21')) {
    return {
      ok: true,
      status: 200,
      json: async () => ({
        data: {
          user: {
            id: 21,
            display_name: 'Alice Example',
            email: 'alice@example.com',
            user_email: 'alice@example.com',
            registered_at: '2025-01-10 09:15:00',
            avatar_url: 'https://example.com/avatar/21',
          },
          plans: [{ plan_id: 5, plan_title: 'Gold Plan', grants: [{ id: 100, plan_id: 5, status: 'active', created_at: '2026-03-01 10:00:00', expires_at: null, source_type: 'manual' }], progress: { items: [] } }],
          history: [{ id: 100, plan_id: 5, plan_title: 'Gold Plan', status: 'active', created_at: '2026-03-01 10:00:00', expires_at: null, source_type: 'manual' }],
        },
      }),
    }
  }
  if (url.includes('/admin/members') && new URL(url).searchParams.get('users_only')) {
    const query = (new URL(url).searchParams.get('search') || '').toLowerCase()
    const users = [
      { id: 21, display_name: 'Alice Example', email: 'alice@example.com', registered_at: '2026-03-10 10:00:00' },
      { id: 22, display_name: 'Alexandria Montgomery With An Exceptionally Long Name', email: 'alexandria.montgomery.with.a.long.address@example.com', registered_at: '2026-03-09 10:00:00' },
      { id: 23, display_name: 'Bob Example', email: 'bob@example.com', registered_at: '2026-03-08 10:00:00' },
      { id: 24, display_name: 'Carla Example', email: 'carla@example.com', registered_at: '2026-03-07 10:00:00' },
      { id: 25, display_name: 'Diego Example', email: 'diego@example.com', registered_at: '2026-03-06 10:00:00' },
      { id: 26, display_name: 'Emilia Example', email: 'emilia@example.com', registered_at: '2026-03-05 10:00:00' },
      { id: 27, display_name: 'Farah Example', email: 'farah@example.com', registered_at: '2026-03-04 10:00:00' },
      { id: 28, display_name: 'George Example', email: 'george@example.com', registered_at: '2026-03-03 10:00:00' },
      { id: 29, display_name: 'Hannah Example', email: 'hannah@example.com', registered_at: '2026-03-02 10:00:00' },
      { id: 30, display_name: 'Ibrahim Example', email: 'ibrahim@example.com', registered_at: '2026-03-01 10:00:00' },
    ]
    const matches = query
      ? users.filter((user) => `${user.display_name} ${user.email}`.toLowerCase().includes(query))
      : users
    return { ok: true, status: 200, json: async () => ({ data: matches, total: matches.length }) }
  }
  if (url.includes('/admin/members')) {
    return { ok: true, status: 200, json: async () => ({
      data: [{ user_id: 21, display_name: 'Alice Example', user_email: 'alice@example.com', plan_id: 5, plan_title: 'Gold Plan', status: 'active', created_at: '2026-03-01 10:00:00', expires_at: null, source_type: 'manual' }],
      total: 1,
      summary: { active: 1, expiring_soon: 0, paused: 0, ended: 0 },
    }) }
  }
  if (url.includes('/admin/content/resource-types')) {
    return {
      ok: true,
      status: 200,
      json: async () => ({
        data: [
          { key: 'post', label: 'Posts', group: 'content', searchable: true, allow_all: true },
          { key: 'page', label: 'Pages', group: 'content', searchable: true, allow_all: true },
          { key: 'category', label: 'Categories', group: 'taxonomy', searchable: true, allow_all: true },
          { key: 'post_tag', label: 'Tags', group: 'taxonomy', searchable: true, allow_all: true },
          { key: 'lesson', label: 'Lessons', group: 'content', searchable: true, allow_all: true },
          { key: 'menu_item', label: 'Menu Items', group: 'navigation', icon: 'menu', searchable: false, supports_bulk: false, allow_all: true, provider: 'wordpress_core', adapter: 'FChubMemberships\\Adapters\\WordPressContentAdapter', source: 'WordPress' },
          { key: 'comment', label: 'Comments', group: 'advanced', icon: 'admin-comments', searchable: false, supports_bulk: false, allow_all: true, provider: 'wordpress_core', adapter: 'FChubMemberships\\Adapters\\WordPressContentAdapter', source: 'WordPress' },
          { key: 'url_pattern', label: 'URL Patterns', group: 'advanced', icon: 'admin-links', searchable: false, supports_bulk: false, allow_all: true, provider: 'wordpress_core', adapter: 'FChubMemberships\\Adapters\\WordPressContentAdapter', source: '' },
          { key: 'special_page', label: 'Special Pages', group: 'advanced', icon: 'admin-home', searchable: false, supports_bulk: false, allow_all: true, provider: 'wordpress_core', adapter: 'FChubMemberships\\Adapters\\WordPressContentAdapter', source: '' },
          { key: 'more_tag', label: 'More Tag Content', group: 'advanced', icon: 'editor-insertmore', searchable: true, supports_bulk: true, allow_all: true, provider: 'wordpress_core', adapter: 'FChubMemberships\\Adapters\\WordPressContentAdapter', source: '' },
        ],
        groups: {
          content: 'Content',
          taxonomy: 'Taxonomies',
          navigation: 'Navigation',
          advanced: 'Advanced',
        },
        select_options: [
          { value: 'post', label: 'Posts' },
          { value: 'page', label: 'Pages' },
          { value: 'category', label: 'Categories' },
          { value: 'post_tag', label: 'Tags' },
          { value: 'lesson', label: 'Lessons' },
          { value: 'menu_item', label: 'Menu Items', group: 'navigation', source: 'WordPress' },
          { value: 'comment', label: 'Comments', group: 'advanced', source: 'WordPress' },
          { value: 'url_pattern', label: 'URL Patterns', group: 'advanced', source: '' },
          { value: 'special_page', label: 'Special Pages', group: 'advanced', source: '' },
          { value: 'more_tag', label: 'More Tag Content', group: 'advanced', source: '' },
        ],
      }),
    }
  }
  if (url.includes('/admin/content/search-resources')) {
    if (window.__fchubSmokeFailResourceSearch) {
      return { ok: false, status: 503, json: async () => ({ message: 'Content search is temporarily unavailable' }) }
    }

    const parsedUrl = new URL(url)
    const type = parsedUrl.searchParams.get('type') || 'post'
    const resources = {
      post: [
        { id: '55', label: 'Members Post', type: 'post', type_label: 'Posts' },
        { id: '182', label: 'Mobile-First Design Is Not Just About Screen Size', type: 'post', type_label: 'Posts' },
      ],
      page: [{ id: '56', label: 'Member Welcome', type: 'page', type_label: 'Pages' }],
      category: [{ id: '7', label: 'Premium Articles', type: 'category', type_label: 'Categories' }],
      post_tag: [{ id: '8', label: 'Member News', type: 'post_tag', type_label: 'Tags' }],
      lesson: [{ id: '91', label: 'Advanced Lesson', type: 'lesson', type_label: 'Lessons' }],
      menu_item: [{ id: '12', label: 'Member Area', type: 'menu_item', type_label: 'Menu Items' }],
      special_page: [{ id: 'shop', label: 'Shop page', type: 'special_page', type_label: 'Special Pages' }],
    }
    return { ok: true, status: 200, json: async () => ({ data: resources[type] || [] }) }
  }
  if (url.includes('/admin/content/protect') && method === 'POST') {
    return { ok: true, status: 201, json: async () => ({ data: { id: 301 } }) }
  }
  if (url.includes('/admin/content')) {
    return { ok: true, status: 200, json: async () => ({ data: [], total: 0 }) }
  }
  if (url.includes('/admin/drip/calendar')) {
    return { ok: true, status: 200, json: async () => ({ data: { '2026-03-20': 1 } }) }
  }
  if (url.includes('/admin/drip/notifications')) {
    return { ok: true, status: 200, json: async () => ({ data: [{ id: 1, user_email: 'alice@example.com', content_title: 'Locked Lesson', plan_title: 'Gold Plan', scheduled_at: '2026-03-20 10:00:00', status: 'pending' }], total: 1 }) }
  }
  if (url.includes('/admin/drip/overview')) {
    return { ok: true, status: 200, json: async () => ({ data: { total_rules: 1, pending: 1, sent_today: 0, failed: 0 } }) }
  }
  if (url.endsWith('/admin/email-notifications')) {
    return {
      ok: true,
      status: 200,
      json: async () => ({
        data: {
          notifications: [
            emailNotification({
              key: 'access_granted',
              label: 'Access granted',
              description: 'Sent as soon as membership access is granted.',
              group: 'access',
              settingKey: 'email_access_granted',
              variables: {
                '{account_url}': { label: 'Account URL', type: 'url', sample: 'https://fchub.vcode.sh/account/' },
                '{resources_list}': { label: 'Protected resources', type: 'rich', sample: '<ul><li>Getting Started</li><li>Member Library</li></ul>' },
                '{drip_schedule}': { label: 'Drip schedule', type: 'rich', sample: '<h3>Coming Soon</h3><ul><li>Advanced Workshop &mdash; Friday</li></ul>' },
              },
              subject: 'Welcome to {plan_name}!',
              preheader: 'Your membership is active and ready to use.',
              content: `<h2>Welcome to {plan_name}, {user_name}!</h2>
<p>Thank you for joining. Your membership is now active and you have immediate access to the following resources:</p>
{resources_list}
{drip_schedule}
<p>You can manage your membership and access all your content from your account:</p>
<p><a href="{account_url}" style="display:inline-block;padding:12px 24px;background-color:#2563eb;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600">Go to My Account</a></p>
<p>If you have any questions, feel free to reply to this email.</p>
<p>Best regards,<br>{site_name}</p>`,
            }),
            emailNotification({
              key: 'access_expiring',
              label: 'Access expiring',
              description: 'Warns a member before their access expires.',
              group: 'access',
              settingKey: 'email_access_expiring',
              variables: {
                '{days}': { label: 'Days remaining', type: 'number', sample: '7' },
                '{expires_at}': { label: 'Expiry date', type: 'date', sample: '29 July 2026' },
                '{renewal_url}': { label: 'Renewal URL', type: 'url', sample: 'https://fchub.vcode.sh/pricing/' },
                '{resources_list}': { label: 'Protected resources', type: 'rich', sample: '<ul><li>Member Library</li><li>Private Resources</li></ul>' },
              },
              subject: 'Your {plan_name} access expires in {days} days',
              preheader: 'A clear reminder before membership access ends.',
              content: `<h2>Your access is expiring soon, {user_name}</h2>
<p>Your <strong>{plan_name}</strong> membership expires on <strong>{expires_at}</strong> ({days} days from now).</p>
<p>When your access expires, you will lose access to the following resources:</p>
{resources_list}
<p>Renew now to keep your access:</p>
<p><a href="{renewal_url}" style="display:inline-block;padding:12px 24px;background-color:#2563eb;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600">Renew Membership</a></p>
<p>If you have any questions, feel free to reply to this email.</p>
<p>Best regards,<br>{site_name}</p>`,
            }),
            emailNotification({
              key: 'access_revoked',
              label: 'Access revoked',
              description: 'Confirms when membership access has been removed.',
              group: 'access',
              settingKey: 'email_access_revoked',
              variables: {
                '{reason}': { label: 'Reason', type: 'text', sample: 'Your membership has ended.' },
                '{support_url}': { label: 'Support URL', type: 'url', sample: 'https://fchub.vcode.sh/contact/' },
                '{repurchase_url}': { label: 'Purchase URL', type: 'url', sample: 'https://fchub.vcode.sh/pricing/' },
              },
              subject: 'Your {plan_name} access has ended',
              preheader: 'A helpful explanation and a clear next step.',
              content: `<h2>Access Removed</h2>
<p>Hi {user_name},</p>
<p>Your access to <strong>{plan_name}</strong> has been removed.</p>
<p><strong>Reason:</strong> {reason}</p>
<p>If you believe this was done in error or need help, please contact our support team:</p>
<p><a href="{support_url}" style="display:inline-block;padding:12px 24px;background-color:#6b7280;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600">Contact Support</a></p>
<p>You can also re-purchase access at any time:</p>
<p><a href="{repurchase_url}" style="display:inline-block;padding:12px 24px;background-color:#2563eb;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600">Re-purchase Membership</a></p>
<p>Best regards,<br>{site_name}</p>`,
            }),
            emailNotification({
              key: 'membership_paused',
              label: 'Membership paused',
              description: 'Explains what changes while a membership is paused.',
              group: 'lifecycle',
              settingKey: 'email_membership_paused',
              variables: {
                '{resume_url}': { label: 'Resume URL', type: 'url', sample: 'https://fchub.vcode.sh/account/' },
              },
              subject: 'Your {plan_name} membership is paused',
              preheader: 'Membership is paused and can be resumed from the account.',
              content: `<h2>Your membership has been paused, {user_name}</h2>
<p>Your <strong>{plan_name}</strong> membership has been paused. While paused, you will not have access to the membership content.</p>
<p>You can resume your membership at any time from your account:</p>
<p><a href="{resume_url}" style="display:inline-block;padding:12px 24px;background-color:#2563eb;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600">Resume Membership</a></p>
<p>If you have any questions, feel free to reply to this email.</p>
<p>Best regards,<br>{site_name}</p>`,
            }),
            emailNotification({
              key: 'membership_resumed',
              label: 'Membership resumed',
              description: 'Welcomes a member back after access resumes.',
              group: 'lifecycle',
              settingKey: 'email_membership_resumed',
              variables: {
                '{account_url}': { label: 'Account URL', type: 'url', sample: 'https://fchub.vcode.sh/account/' },
                '{expires_at}': { label: 'Expiry date', type: 'date', sample: '21 August 2026' },
              },
              subject: 'Your {plan_name} membership is active again',
              preheader: 'Membership access has resumed.',
              content: `<h2>Welcome back, {user_name}!</h2>
<p>Your <strong>{plan_name}</strong> membership is active again! You now have full access to all your membership content.</p>
<p>Your membership is valid until <strong>{expires_at}</strong>.</p>
<p>Access your content from your account:</p>
<p><a href="{account_url}" style="display:inline-block;padding:12px 24px;background-color:#2563eb;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600">Go to My Account</a></p>
<p>If you have any questions, feel free to reply to this email.</p>
<p>Best regards,<br>{site_name}</p>`,
            }),
            emailNotification({
              key: 'trial_expiring',
              label: 'Trial expiring',
              description: 'Reminds a member before their trial ends.',
              group: 'trial',
              settingKey: 'email_trial_expiring',
              variables: {
                '{days}': { label: 'Days remaining', type: 'number', sample: '3' },
                '{trial_ends_at}': { label: 'Trial end date', type: 'date', sample: '25 July 2026' },
                '{upgrade_url}': { label: 'Upgrade URL', type: 'url', sample: 'https://fchub.vcode.sh/pricing/' },
              },
              subject: 'Your {plan_name} trial ends in {days} days',
              preheader: 'A timely reminder before the trial ends.',
              content: `<h2>Your trial is ending soon, {user_name}</h2>
<p>Your free trial of <strong>{plan_name}</strong> ends on <strong>{trial_ends_at}</strong> ({days} days from now).</p>
<p>To keep your access and continue enjoying all the benefits, upgrade to a paid membership today:</p>
<p><a href="{upgrade_url}" style="display:inline-block;padding:12px 24px;background-color:#2563eb;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600">Upgrade Now</a></p>
<p>If you have any questions, feel free to reply to this email.</p>
<p>Best regards,<br>{site_name}</p>`,
            }),
            emailNotification({
              key: 'trial_converted',
              label: 'Trial converted',
              description: 'Confirms that a trial became a paid membership.',
              group: 'trial',
              settingKey: 'email_trial_converted',
              variables: {
                '{account_url}': { label: 'Account URL', type: 'url', sample: 'https://fchub.vcode.sh/account/' },
                '{expires_at}': { label: 'Expiry date', type: 'date', sample: '22 July 2027' },
              },
              subject: 'Welcome to your paid {plan_name} membership',
              preheader: 'The paid membership is active.',
              content: `<h2>Welcome aboard, {user_name}!</h2>
<p>Your trial has been successfully converted to a full <strong>{plan_name}</strong> membership.</p>
<p>Your membership is now active and will remain valid until <strong>{expires_at}</strong>.</p>
<p>You can manage your membership from your account:</p>
<p><a href="{account_url}" style="display:inline-block;padding:12px 24px;background-color:#2563eb;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600">Go to My Account</a></p>
<p>Thank you for your support!</p>
<p>Best regards,<br>{site_name}</p>`,
            }),
            emailNotification({
              key: 'drip_content_unlocked',
              label: 'Drip content unlocked',
              description: 'Notifies a member when scheduled content becomes available.',
              group: 'content',
              settingKey: 'email_drip_unlocked',
              variables: {
                '{resource_title}': { label: 'Resource title', type: 'text', sample: 'Advanced Workshop' },
                '{resource_url}': { label: 'Resource URL', type: 'url', sample: 'https://fchub.vcode.sh/members/advanced-workshop/' },
                '{progress}': { label: 'Drip progress', type: 'rich', sample: '<p><strong>3 of 8</strong> resources unlocked</p>' },
                '{next_drip_item}': { label: 'Next drip item', type: 'rich', sample: '<p>Next: Member Q&amp;A on Friday</p>' },
              },
              subject: 'New content is available: {resource_title}',
              preheader: 'A new membership resource is ready.',
              content: `<h2>New Content Unlocked!</h2>
<p>Hi {user_name},</p>
<p>A new piece of content from your <strong>{plan_name}</strong> membership is now available:</p>
<h3>{resource_title}</h3>
<p><a href="{resource_url}" style="display:inline-block;padding:12px 24px;background-color:#2563eb;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600">View Content</a></p>
{progress}
{next_drip_item}
<p>Best regards,<br>{site_name}</p>`,
            }),
          ],
          theme: { ...emailTheme },
          brand_template: { ...emailTheme },
          fluentcrm_available: true,
        },
      }),
    }
  }
  if (url.includes('/admin/webhooks/health')) {
    return {
      ok: true,
      status: 200,
      json: async () => ({
        data: {
          status: 'off',
          pending_count: 0,
          processing_count: 0,
          retrying_count: 0,
          succeeded_count: 4,
          failed_count: 1,
          last_success_at: '2026-07-22 12:00:00',
        },
      }),
    }
  }
  if (url.includes('/admin/webhooks/deliveries/91/retry')) {
    smokeWebhookRetried = true
    smokeWebhookCancelled = false
    return { ok: true, status: 202, json: async () => ({ data: { id: 91, status: 'pending' } }) }
  }
  if (url.includes('/admin/webhooks/deliveries/91/cancel')) {
    smokeWebhookCancelled = true
    return { ok: true, status: 200, json: async () => ({ data: { id: 91, status: 'cancelled' } }) }
  }
  const endpointAction = url.match(/\/admin\/webhooks\/endpoints\/([^/?]+)\/(secret|test|activate|pause)$/)
  if (endpointAction) {
    const [, endpointId, action] = endpointAction
    const endpointIndex = smokeWebhookEndpoints.findIndex(({ id }) => id === endpointId)
    const endpoint = smokeWebhookEndpoints[endpointIndex]
    if (!endpoint) {
      return { ok: false, status: 404, json: async () => ({ message: 'Webhook endpoint not found.' }) }
    }
    if (action === 'secret') {
      smokeWebhookEndpoints[endpointIndex] = {
        ...endpoint,
        status: 'paused',
        secret_configured: true,
        requires_rotation: false,
        last_test_status: '',
        last_tested_at: null,
      }
      return {
        ok: true,
        status: 200,
        json: async () => ({
          data: {
            endpoint: { ...smokeWebhookEndpoints[endpointIndex] },
            secret: 'webhook_endpoint_one_time_smoke_secret',
          },
        }),
      }
    }
    if (action === 'test') {
      smokeWebhookEndpoints[endpointIndex] = {
        ...endpoint,
        last_test_status: 'succeeded',
        last_tested_at: '2026-07-24 10:00:00',
      }
      return {
        ok: true,
        status: 200,
        json: async () => ({
          data: {
            endpoint: { ...smokeWebhookEndpoints[endpointIndex] },
            delivery: { id: 93, status: 'succeeded', response_code: 204 },
          },
        }),
      }
    }
    smokeWebhookEndpoints[endpointIndex] = {
      ...endpoint,
      status: action === 'activate' ? 'active' : 'paused',
    }
    return {
      ok: true,
      status: 200,
      json: async () => ({ data: { endpoint: { ...smokeWebhookEndpoints[endpointIndex] } } }),
    }
  }
  const endpointDelete = url.match(/\/admin\/webhooks\/endpoints\/([^/?]+)$/)
  if (endpointDelete && method === 'DELETE') {
    smokeWebhookEndpoints = smokeWebhookEndpoints.filter(({ id }) => id !== endpointDelete[1])
    return { ok: true, status: 200, json: async () => ({ data: { deleted: true } }) }
  }
  if (url.includes('/admin/webhooks/endpoints')) {
    if (method === 'POST') {
      smokeWebhookEndpointSequence += 1
      const endpoint = {
        id: `we_smoke_${smokeWebhookEndpointSequence}`,
        name: String(requestBody?.name || ''),
        url: String(requestBody?.url || ''),
        status: 'draft',
        secret_configured: false,
        requires_rotation: false,
        last_test_status: '',
        last_tested_at: null,
      }
      smokeWebhookEndpoints.push(endpoint)
      return { ok: true, status: 201, json: async () => ({ data: { endpoint: { ...endpoint } } }) }
    }
    return {
      ok: true,
      status: 200,
      json: async () => ({ data: { endpoints: smokeWebhookEndpoints.map((endpoint) => ({ ...endpoint })) } }),
    }
  }
  if (url.includes('/admin/webhooks/deliveries')) {
    window.__fchubSmokeWebhookHistoryReads += 1
    return {
      ok: true,
      status: 200,
      json: async () => ({
        data: {
          deliveries: [{
            id: 91,
            event_id: 'evt_smoke_failed',
            event_type: 'access.revoked',
            destination_url: 'https://hooks.example.com/memberships',
            status: smokeWebhookCancelled ? 'cancelled' : (smokeWebhookRetried ? 'pending' : 'failed'),
            attempt_count: smokeWebhookRetried ? 0 : 7,
            response_code: smokeWebhookRetried ? null : 503,
            error_message: smokeWebhookRetried ? '' : 'webhook_http_503',
            next_attempt_at: smokeWebhookRetried && !smokeWebhookCancelled ? '2026-07-22 12:06:00' : null,
            last_attempt_at: '2026-07-22 12:05:00',
            delivered_at: null,
            created_at: '2026-07-22 12:00:00',
            updated_at: '2026-07-22 12:05:00',
          }],
          page: 1,
          per_page: 20,
        },
      }),
    }
  }
  if (url.includes('/admin/webhooks/test')) {
    return {
      ok: true,
      status: 200,
      json: async () => ({
        data: {
          event_id: 'evt_smoke_test',
          success: false,
          results: [{
            id: 92,
            destination_url: 'https://hooks.example.com/memberships',
            status: 'retrying',
          }],
        },
      }),
    }
  }
  if (url.includes('/admin/settings/generate-api-key')) {
    if (window.__fchubSmokeHoldCredentials) {
      await new Promise((resolve) => {
        window.__fchubSmokeReleaseCredential = () => {
          window.__fchubSmokeReleaseCredential = null
          resolve()
        }
      })
    }
    smokeAccessApi = {
      configured: true,
      prefix: 'fchub_one_ti',
      rotated_at: '2026-07-22 12:10:00',
    }
    return {
      ok: true,
      status: 200,
      json: async () => ({
        data: {
          api_key: 'fchub_one_time_smoke_key',
          access_api: { ...smokeAccessApi },
        },
      }),
    }
  }
  if (url.includes('/admin/settings/revoke-api-key')) {
    smokeAccessApi = { configured: false, prefix: null, rotated_at: null }
    return { ok: true, status: 200, json: async () => ({ data: { access_api: { ...smokeAccessApi } } }) }
  }
  if (url.includes('/admin/settings/regenerate-webhook-secret')) {
    return {
      ok: true,
      status: 200,
      json: async () => ({ data: { webhook_secret: 'webhook_one_time_smoke_secret' } }),
    }
  }
  if (url.includes('/admin/settings')) {
    if (method === 'POST') {
      smokeWebhookUrls = String(requestBody?.webhook_urls ?? smokeWebhookUrls)
      smokeWebhookDestinationsConfigured = smokeWebhookUrls === 'https://hooks.example.com/memberships'
      for (const key of Object.keys(smokeRuntimeSettingsDefaults)) {
        if (Object.prototype.hasOwnProperty.call(requestBody ?? {}, key)) {
          smokeRuntimeSettings[key] = requestBody[key]
        }
      }
      window.sessionStorage.setItem(
        smokeRuntimeSettingsStorageKey,
        JSON.stringify(smokeRuntimeSettings),
      )
    }
    return {
      ok: true,
      status: 200,
      json: async () => ({
        data: {
          default_protection_mode: 'content_replace',
          restriction_message_no_access: 'No access',
          restriction_message_paused: 'Paused',
          default_redirect_url: '',
          email_access_granted: 'yes',
          email_access_expiring: 'yes',
          expiry_warning_days: smokeRuntimeSettings.expiry_warning_days,
          trial_expiry_notice_days: smokeRuntimeSettings.trial_expiry_notice_days,
          email_access_revoked: 'yes',
          email_drip_unlocked: 'yes',
          hide_protected_in_archive: smokeRuntimeSettings.hide_protected_in_archive,
          uninstall_remove_data: smokeRuntimeSettings.uninstall_remove_data,
          access_api: { ...smokeAccessApi },
          debug_mode: 'no',
          webhook_enabled: 'no',
          webhook_urls: smokeWebhookUrls,
          webhook_secret_configured: true,
          webhook_destinations_configured: smokeWebhookDestinationsConfigured,
          webhook_status: smokeWebhookDestinationsConfigured ? 'off' : 'needs_setup',
          fluentcrm_enabled: 'no',
          fluentcrm_tag_prefix: 'member:',
          fluentcrm_default_list: '',
          fluentcrm_auto_create_tags: 'yes',
          fc_enabled: 'no',
          fc_space_mappings: {},
          fc_badge_mappings: {},
          fc_remove_badge_on_revoke: 'no',
          membership_mode: 'stack',
        },
      }),
    }
  }
  if (url.includes('/admin/fluentcrm-lists') || url.includes('/admin/fc-spaces') || url.includes('/admin/fc-badges')) {
    return { ok: true, status: 200, json: async () => ({ data: [] }) }
  }
  if (url.includes('/admin/import/parse')) {
    return { ok: true, status: 200, json: async () => ({ data: { format: 'generic', levels: [], members: [], stats: {}, warnings: [], preview: [] } }) }
  }
  if (url.includes('/admin/import/prepare') || url.includes('/admin/import/execute')) {
    return { ok: true, status: 200, json: async () => ({ data: { mappings: [], processed: 0 } }) }
  }

  return { ok: true, status: 200, json: async () => ({ data: {} }) }
}

await import('../resources/admin/main.js')
