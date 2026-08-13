<template>
	<article class="membership-card">
		<div class="membership-heading">
			<div class="membership-title">
				<h3>{{ membership.plan_title }}</h3>
				<el-tag :type="statusTagType(membership.status)" size="small">{{
					normaliseSourceLabel(membership.status)
				}}</el-tag>
				<el-tag v-if="isTrialActive" type="info" size="small">Trial</el-tag>
			</div>
			<span class="membership-term">{{ describeMembershipTerm(membership) }}</span>
		</div>

		<dl class="membership-facts">
			<div>
				<dt>Source</dt>
				<dd>
					<a v-if="membership.source.url" :href="membership.source.url">{{
						membership.source.label
					}}</a>
					<template v-else>{{ membership.source.label }}</template>
					<small v-if="membership.source.actor">by {{ membership.source.actor }}</small>
				</dd>
			</div>
			<div v-if="subscription">
				<dt>Subscription</dt>
				<dd>
					{{ normaliseSourceLabel(subscription.status) }}
					<small v-if="subscription.canceled_at"
						>cancelled {{ formatDate(subscription.canceled_at) }}</small
					>
					<small v-else-if="subscription.next_billing_date"
						>renews {{ formatDate(subscription.next_billing_date) }}</small
					>
				</dd>
			</div>
			<div>
				<dt>Started</dt>
				<dd>{{ formatDate(membership.created_at) }}</dd>
			</div>
			<div>
				<dt>Unlocks</dt>
				<dd>{{ membership.resources.length }} resources</dd>
			</div>
		</dl>

		<div class="membership-controls">
			<el-button text size="small" @click="$emit('toggle', membership)">
				<el-icon><ArrowDown v-if="!expanded" /><ArrowUp v-else /></el-icon>
				{{ expanded ? "Hide detail" : "Show detail" }}
			</el-button>
			<div class="membership-actions">
				<el-button v-if="membership.status === 'paused'" size="small" type="success" plain @click="$emit('resume', membership)"
					>Resume</el-button
				>
				<el-button v-else-if="canPause" size="small" plain @click="$emit('pause', membership)"
					>Pause</el-button
				>
				<el-button size="small" @click="$emit('extend', membership)">Extend</el-button>
				<el-popconfirm
					title="Revoke this membership?"
					confirm-button-text="Revoke"
					confirm-button-type="danger"
					@confirm="$emit('revoke', membership)"
					><template #reference
						><el-button size="small" type="danger" plain>Revoke</el-button></template
					></el-popconfirm
				>
			</div>
		</div>

		<div v-if="expanded" class="membership-detail">
			<section class="membership-detail-block">
				<div class="membership-detail-heading">
					<h4>Resources</h4>
					<el-button
						size="small"
						plain
						:loading="providerChecking"
						@click="$emit('check-providers', membership)"
						>Check providers</el-button
					>
				</div>
				<ul class="membership-resources">
					<li v-for="resource in membership.resources" :key="resource.grant_id">
						<span class="resource-name"
							>{{ normaliseSourceLabel(resource.resource_type) }}
							<code>{{ resource.resource_id }}</code></span
						>
						<span class="resource-provider">{{
							normaliseSourceLabel(resource.provider)
						}}</span>
						<el-tag :type="statusTagType(resource.status)" size="small">{{
							resource.status
						}}</el-tag>
						<span v-if="providerVerdict(resource)" class="resource-provider-state">{{
							providerVerdict(resource)
						}}</span>
					</li>
				</ul>
				<p v-if="providerState" class="membership-detail-note">
					Provider state is read live. WordPress access is local, so it reports as such;
					uncertified providers cannot be verified from here.
				</p>
			</section>

			<section v-if="drip && drip.length" class="membership-detail-block">
				<h4>Drip schedule</h4>
				<el-timeline>
					<el-timeline-item
						v-for="item in drip"
						:key="item.id"
						:type="dripItemType(item.status)"
						:hollow="item.status === 'locked'"
						:timestamp="dripTimestamp(item)"
						><div class="drip-item">
							<span>{{ item.title }}</span
							><el-tag :type="dripItemType(item.status)" size="small">{{
								item.status
							}}</el-tag>
						</div></el-timeline-item
					>
				</el-timeline>
			</section>
		</div>
	</article>
</template>

<script setup>
import { computed } from "vue";
import { ArrowDown, ArrowUp } from "@element-plus/icons-vue";
import {
	describeMembershipTerm,
	normaliseSourceLabel,
	statusTagType,
} from "@/pages/Members/memberProfileUi.js";

const props = defineProps({
	membership: { type: Object, required: true },
	expanded: { type: Boolean, default: false },
	drip: { type: Array, default: null },
	providerState: { type: Array, default: null },
	providerChecking: { type: Boolean, default: false },
	formatDate: { type: Function, required: true },
});

defineEmits(["toggle", "pause", "resume", "extend", "revoke", "check-providers"]);

const subscription = computed(() => props.membership.source?.subscription || null);
const canPause = computed(() =>
	["active", "scheduled"].includes(props.membership.status),
);
const isTrialActive = computed(
	() =>
		Boolean(props.membership.trial_ends_at) &&
		new Date(props.membership.trial_ends_at) > new Date(),
);

const PROVIDER_VERDICTS = {
	local_only: "local",
	provider_uncertified: "not verifiable",
	provider_unknown: "unknown provider",
	provider_unavailable: "provider unavailable",
	in_sync: "in sync",
};

function providerVerdict(resource) {
	const match = (props.providerState || []).find(
		(item) =>
			item.provider === resource.provider &&
			item.resource_type === resource.resource_type &&
			String(item.resource_id) === String(resource.resource_id),
	);
	if (!match) return "";

	return (
		PROVIDER_VERDICTS[match.classification] ||
		normaliseSourceLabel(match.classification).toLowerCase()
	);
}

function dripItemType(status) {
	return { unlocked: "success", upcoming: "warning", locked: "info" }[status] || "info";
}

function dripTimestamp(item) {
	if (item.status === "unlocked" && (item.unlocked_at || item.unlock_date)) {
		return `Unlocked ${props.formatDate(item.unlocked_at || item.unlock_date)}`;
	}
	return item.unlock_date ? `Unlocks ${props.formatDate(item.unlock_date)}` : "Locked";
}
</script>

<style scoped>
.membership-card {
	padding: 16px;
	border: 1px solid var(--fchub-border-color);
	border-radius: 10px;
	background: color-mix(in srgb, var(--fchub-card-bg) 98%, var(--el-color-primary) 2%);
}
.membership-heading {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	justify-content: space-between;
	gap: 8px 12px;
}
.membership-title {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	min-width: 0;
}
.membership-title h3 {
	margin: 0;
	color: var(--fchub-text-primary);
	font-size: 15px;
	font-weight: 700;
}
.membership-term {
	color: var(--fchub-text-secondary);
	font-size: 12px;
}
.membership-facts {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
	gap: 12px;
	margin: 14px 0;
}
.membership-facts div {
	min-width: 0;
}
.membership-facts dt,
.membership-facts dd {
	margin: 0;
}
.membership-facts dt {
	margin-bottom: 3px;
	color: var(--fchub-text-secondary);
	font-size: 10px;
	font-weight: 700;
	letter-spacing: 0.04em;
	text-transform: uppercase;
}
.membership-facts dd {
	color: var(--fchub-text-primary);
	font-size: 12px;
	line-height: 1.4;
	overflow-wrap: anywhere;
}
.membership-facts dd small {
	display: block;
	color: var(--fchub-text-secondary);
	font-size: 11px;
}
.membership-controls {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	padding-top: 12px;
	border-top: 1px solid var(--fchub-border-color);
}
.membership-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}
.membership-actions :deep(.el-button + .el-button) {
	margin-left: 0;
}
.membership-detail {
	display: grid;
	gap: 16px;
	margin-top: 14px;
	padding-top: 14px;
	border-top: 1px dashed var(--fchub-border-color);
}
.membership-detail-heading {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
}
.membership-detail h4 {
	margin: 0 0 8px;
	color: var(--fchub-text-primary);
	font-size: 12px;
	font-weight: 700;
	letter-spacing: 0.04em;
	text-transform: uppercase;
}
.membership-detail-heading h4 {
	margin: 0;
}
.membership-resources {
	margin: 8px 0 0;
	padding: 0;
	list-style: none;
}
.membership-resources li {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	padding: 7px 0;
	border-top: 1px solid var(--fchub-border-color);
	font-size: 12px;
}
.membership-resources li:first-child {
	border-top: 0;
}
.resource-name {
	min-width: 0;
	flex: 1 1 160px;
	color: var(--fchub-text-primary);
}
.resource-provider {
	color: var(--fchub-text-secondary);
}
.resource-provider-state {
	color: var(--fchub-text-secondary);
	font-style: italic;
}
.membership-detail-note {
	margin: 10px 0 0;
	color: var(--fchub-text-secondary);
	font-size: 11px;
	line-height: 1.5;
}
.drip-item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	font-size: 12px;
}
@media (max-width: 480px) {
	.membership-actions {
		display: grid;
		grid-template-columns: repeat(3, minmax(0, 1fr));
		width: 100%;
	}
	.membership-actions :deep(.el-button) {
		width: 100%;
		padding-inline: 7px;
	}
}
</style>
