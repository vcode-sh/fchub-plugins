// Scenarios: what a merchant asks about how the shop itself is set up — money, mail, access,
// paperwork, and the integrations bolted on the side. Scored on discovery, answer and cost
// together; see support/scenario.ts. Ground truth is the store's own tables, never a second
// endpoint. The storefront half of the subject is in scenarios-config-storefront.
import { beforeAll, describe, it } from 'vitest'
import type { ToolDefinition } from '../../src/tools/_factory.js'
import * as fixture from './support/config-fixture.js'
import { getLiveClient } from './support/live-client.js'
import type { Scenario } from './support/scenario.js'

const { buildConfigTools, count, ok, roleExists, SQL, storeHolds, sweep } = fixture
const must: typeof fixture.must = fixture.must
let tools: ToolDefinition[]

beforeAll(() => {
	tools = buildConfigTools(getLiveClient())
})

const SCENARIOS: Scenario[] = [
	{
		id: 'config/store-identity',
		question: 'How is my store set up — what is it called, what currency, which country?',
		discovery: { query: 'how is my store set up', expect: 'fluentcart_settings_get_store' },
		budget: 2_400,
		run: async (ctx) => {
			const { body } = await ok(ctx, 'fluentcart_settings_get_store')
			const store = (body.settings ?? {}) as Record<string, string>
			for (const key of ['store_name', 'currency', 'store_country', 'order_mode']) {
				must(typeof store[key] === 'string' && store[key] !== '', `${key} is missing`)
				must(storeHolds(ctx, key, store[key]), `${key}=${store[key]} is not what is stored`)
			}
			const { store_name, currency, store_country, order_mode } = store
			ctx.note(`${store_name}: ${currency}, ${store_country}, order mode ${order_mode}`)
		},
	},
	{
		id: 'config/payments-live',
		question: 'Which payment methods are switched on?',
		discovery: { query: 'which payment methods are enabled', expect: 'fluentcart_payment_get_all' },
		budget: 10_500,
		run: async (ctx) => {
			const { body } = await ok(ctx, 'fluentcart_payment_get_all')
			const gateways = (body.gateways ?? []) as { slug?: string; status?: boolean }[]
			const live = gateways.filter((row) => row.status).map((row) => String(row.slug))
			const active = fixture.activeGateways(ctx)
			const named = gateways.every((row) => row.slug)
			const consistent = live.every((slug) => active.includes(slug))
			must(live.length > 0, 'no gateway is live, so the store takes no money at all')
			must(named, 'a gateway row carries no slug, so nothing can address it')
			must(consistent, `${live} includes a gateway the store has not switched on`)
			// The other direction is not symmetrical, and that asymmetry is the finding.
			const hidden = active.filter((slug) => !gateways.some((row) => row.slug === slug))
			must(hidden.length > 0, 'nothing is configured-but-absent now; scenario needs new data')
			ctx.note(`live: ${live.join(', ')}; active in store but not mentioned: ${hidden.join(', ')}`)
		},
	},
	{
		id: 'config/modules',
		question: 'Which FluentCart modules are switched on?',
		discovery: { query: 'what modules are enabled', expect: 'fluentcart_settings_get_modules' },
		budget: 1_600,
		run: async (ctx) => {
			const { body, text } = await ok(ctx, 'fluentcart_settings_get_modules')
			const fields = body.fields as Record<string, { schema?: Record<string, unknown> }>
			const offered = Object.keys(fields?.modules_settings?.schema ?? {})
			must(offered.length > 0, 'no module is described at all')
			must(!/[a-z0-9]{24,}/i.test(text), 'a module credential looks unredacted in the payload')
			const [[saved]] = ctx.db(SQL.moduleSettings)
			ctx.note(`offered: ${offered.join(', ')}; stored module settings are ${saved}`)
		},
	},
	{
		id: 'config/emails-to-customers',
		question: 'What emails does the store send to customers, and who from?',
		discovery: { query: 'what emails does the store send', expect: 'fluentcart_email_list' },
		budget: 12_500,
		run: async (ctx) => {
			type Row = { recipient?: string; settings?: { active?: string } }
			const { body, chars } = await ok(ctx, 'fluentcart_email_list')
			const rows = (body.data ?? {}) as Record<string, Row>
			const names = Object.keys(rows)
			must(names.length > 0, 'the store reports no email notifications at all')
			const mine = names.filter((name) => rows[name].recipient === 'customer')
			const live = mine.filter((name) => rows[name].settings?.active === 'yes')
			must(live.length > 0, 'not one customer-facing email is switched on')
			const [[config]] = ctx.db(SQL.emailConfig)
			const stored = Object.keys(JSON.parse(config).notification_config ?? {})
			const complete = stored.every((name) => names.includes(name))
			must(complete, `the store configures ${stored}, and the answer omits some of it`)
			const sender = await ok(ctx, 'fluentcart_email_settings_get')
			const from = ((sender.body.data ?? {}) as Record<string, string>).from_email
			must(from === JSON.parse(config).from_email, `sender ${from} is not what is stored`)
			ctx.note(`${live.length} of ${mine.length} customer emails on, from ${from}, ${chars} chars`)
		},
	},
	{
		id: 'config/renewal-reminders',
		question: 'Do subscribers get warned before a renewal charges them?',
		discovery: { query: 'renewal reminder emails', expect: 'fluentcart_email_reminder_settings' },
		budget: 2_000,
		run: async (ctx) => {
			const { body } = await ok(ctx, 'fluentcart_email_reminder_settings')
			const settings = (body.settings ?? {}) as Record<string, string>
			const master = settings.reminders_enabled
			must(master !== undefined, 'no master reminder switch is reported')
			must(storeHolds(ctx, 'reminders_enabled', master), `reminders_enabled=${master} not stored`)
			// The trap: intervals read "yes" while the master switch is off, so a caller reading only
			// the interval keys concludes the store warns people when it sends nothing.
			const isOn = (key: string) => key.endsWith('_reminders_enabled') && settings[key] === 'yes'
			const on = Object.keys(settings).filter(isOn)
			ctx.note(`master switch ${master}; intervals set to yes: ${on.join(', ') || 'none'}`)
		},
	},
	{
		id: 'config/who-manages',
		question: 'Who has admin access to the shop?',
		discovery: { query: 'shop managers', expect: 'fluentcart_role_managers' },
		budget: 400,
		run: async (ctx) => {
			const { body } = await ok(ctx, 'fluentcart_role_managers')
			const managers = (body.managers ?? []) as unknown[]
			const admins = count(ctx, SQL.admins)
			must(admins > 0, 'this WordPress has no administrator, which cannot be right')
			const found = ctx.search('who has admin access to the shop')
			must(!found.includes('fluentcart_role_managers'), 'discoverable now; tighten this scenario')
			ctx.note(`${managers.length} role holders against ${admins} WordPress administrators`)
			ctx.note(`the plain question returns ${found.slice(0, 2).join(', ')} instead`)
		},
	},
	{
		id: 'config/role-candidates',
		question: 'Who could I make a shop manager?',
		discovery: { query: 'who can be given a shop role', expect: 'fluentcart_role_user_list' },
		budget: 800,
		run: async (ctx) => {
			const blank = await ok(ctx, 'fluentcart_role_user_list', { per_page: 10 })
			const candidates = count(ctx, SQL.nonAdmins)
			must(candidates > 0, 'no non-administrator users exist; the scenario needs new data')
			const total = ((blank.body.users ?? {}) as { total?: number }).total
			must(Number(total) === 0, `an unsearched list returns ${total} now, not the documented 0`)
			const hit = await ok(ctx, 'fluentcart_role_user_list', { search: 'nowak' })
			const matched = ((hit.body.users ?? {}) as { total?: number }).total
			must(Number(matched) > 0, 'searching by a surname the store holds found nobody')
			ctx.note(`${candidates} users could hold a role; without a search term the answer is 0`)
		},
	},
	{
		id: 'config/permission-roles',
		question: 'Which WordPress roles can be given shop permissions?',
		discovery: { query: 'shop permissions by role', expect: 'fluentcart_settings_get_permissions' },
		budget: 400,
		run: async (ctx) => {
			const { body } = await ok(ctx, 'fluentcart_settings_get_permissions')
			const roles = ((body.roles ?? {}) as { roles?: { key: string }[] }).roles ?? []
			must(roles.length > 0, 'no assignable role is reported')
			for (const role of roles)
				must(roleExists(ctx, role.key), `${role.key} is not a WordPress role`)
			const keys = roles.map((role) => role.key)
			must(!keys.includes('administrator'), 'administrator is listed now; it was not before')
			ctx.note(`assignable: ${keys.join(', ')} — administrator is not among them`)
		},
	},
	{
		id: 'config/print-templates',
		question: 'Show me the invoice and packing-slip templates.',
		discovery: {
			query: 'print invoice template',
			expect: 'fluentcart_settings_print_templates_get',
		},
		budget: 800,
		run: async (ctx) => {
			const { body } = await ok(ctx, 'fluentcart_settings_print_templates_get')
			const templates = (body.templates ?? []) as { key: string; content_characters: number }[]
			must(templates.length > 0, 'the store reports no print templates')
			for (const row of templates) {
				// The stored blob is escaped, so it can only be longer than the body it decodes to.
				const held = count(
					ctx,
					`select length(meta_value) from wp_fct_meta where meta_key='${row.key}';`,
				)
				const sane = row.content_characters > 0 && row.content_characters <= held
				must(sane, `${row.key} reports ${row.content_characters} against ${held} stored`)
			}
			const total = templates.reduce((sum, row) => sum + row.content_characters, 0)
			ctx.note(`${templates.length} templates, ${total} characters of HTML held behind a flag`)
		},
	},
	{
		id: 'config/pdf-renderer',
		question: 'Are my receipt PDFs actually being produced?',
		discovery: {
			query: 'pdf receipt renderer installed',
			expect: 'fluentcart_pdf_template_status',
		},
		budget: 1_200,
		run: async (ctx) => {
			const status = await ok(ctx, 'fluentcart_pdf_template_status')
			const list = await ok(ctx, 'fluentcart_pdf_template_list')
			const templates = (list.body.templates ?? []) as unknown[]
			must(templates.length > 0, 'no receipt template is configured')
			must(status.body.renderer_available === false, 'the PDF add-on is installed now; update this')
			const missing = await ctx.call('fluentcart_pdf_template_get', { template: 'no_such_zzz' })
			must(missing.isError, 'asking for a template that does not exist succeeded')
			ctx.note(`${templates.length} receipt templates configured, and nothing renders them`)
		},
	},
	{
		id: 'config/integrations-connected',
		question: 'Is anything connected to FluentCRM or Mailchimp?',
		discovery: { query: 'integration feeds', expect: 'fluentcart_integration_get_global_feeds' },
		budget: 3_200,
		run: async (ctx) => {
			const { body, text } = await ok(ctx, 'fluentcart_integration_get_global_feeds')
			const feeds = (body.feeds ?? []) as unknown[]
			const offered = Object.keys((body.available_integrations ?? {}) as Record<string, unknown>)
			must(offered.includes('fluentcrm'), 'FluentCRM is not offered as an integration at all')
			must(!/mailchimp/i.test(text), 'Mailchimp is offered now; it was not before')
			const configured = ctx.db(SQL.orderIntegrations).map(([key]) => key)
			const hidden = configured.filter((key) => !offered.includes(key))
			must(hidden.length > 0, 'every stored order integration is visible now; needs new data')
			ctx.note(`${feeds.length} feeds run and ${offered.length} providers are offered`)
			ctx.note(`${hidden.join(', ')} is configured in the database and named by neither list`)
		},
	},
	{
		id: 'config/integration-absent-provider',
		question: 'Show me the settings for the FluentCRM integration.',
		budget: 5_000,
		run: async (ctx) => {
			// Reached from the provider list above rather than by searching, which is how an agent
			// arrives here: it reads a provider key, then asks that provider for its settings.
			const tool = 'fluentcart_integration_get_global_settings'
			const crm = await ctx.call(tool, { settings_key: 'fluentcrm' })
			must(crm.isError, 'fluentcrm has global settings now; the description says it has none')
			must(/fluentcrm/i.test(crm.text), 'the refusal does not name the key that was asked for')
			await ok(ctx, tool, { settings_key: 'memberships' })
			ctx.note('only providers declaring disable_global_settings false answer here')
			ctx.note('FluentCRM, the webhook sender and WP User refuse; per-feed settings are the way')
		},
	},
	{
		id: 'config/settings-group-missing',
		question: 'Show me just the checkout settings group.',
		budget: 4_500,
		run: async (ctx) => {
			// Reached from settings_get_store's own schema rather than by searching.
			const group = 'no_such_group_zzz'
			const named = await ok(ctx, 'fluentcart_settings_get_store', { settings_name: group })
			const fields = (named.body.fields ?? {}) as Record<string, unknown>
			const echoed = group in fields && fields[group] === null
			must(echoed, 'the unknown group name is no longer echoed back as null')
			const settings = (named.body.settings ?? {}) as Record<string, unknown>
			must(typeof settings.store_name === 'string', 'naming a group filters the settings now')
			ctx.note('naming a group neither filters the settings nor fails: everything comes back')
			ctx.note('an unknown name returns as null, so a typo is indistinguishable from a hit')
		},
	},
	{
		id: 'config/store-context',
		question: 'Give me the orientation summary for this store.',
		discovery: {
			query: 'store orientation currency versions write mode',
			expect: 'fluentcart_get_store_context',
		},
		budget: 600,
		run: async (ctx) => {
			const context = await ok(ctx, 'fluentcart_get_store_context')
			const store = context.body.store as Record<string, unknown>
			const runtime = context.body.runtime as Record<string, unknown>
			must(typeof store.origin === 'string', 'store context omitted its origin')
			must(runtime.wordpress === null, 'store context guessed a WordPress version')
			must(runtime.fluentcartCore === null, 'store context guessed a FluentCart version')
			must(
				/^sha256:[0-9a-f]{64}$/.test(String(runtime.routeProfileDigest)),
				'store context omitted its route-profile digest',
			)
			ctx.note('runtime versions remain null because FluentCart does not expose them')
		},
	},
]

describe('store configuration scenarios', () => {
	it('answers every one', async () => {
		await sweep(tools, SCENARIOS)
	}, 300_000)
})
