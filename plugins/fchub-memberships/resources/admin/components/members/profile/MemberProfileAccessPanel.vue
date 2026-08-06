<template>
	<section
		class="profile-panel access-panel"
		role="region"
		aria-label="Current access"
	>
		<header class="profile-panel-header">
			<div>
				<span class="profile-section-eyebrow">ACCESS</span>
				<h2>Current access</h2>
				<p>{{ accessState.description }}</p>
			</div>
			<el-tag
				:type="accessState.hasAccess ? 'success' : 'info'"
				effect="light"
				>{{ accessState.title }}</el-tag
			>
		</header>
		<div v-if="activeGrants.length" class="access-grant-list">
			<article
				v-for="grant in activeGrants"
				:key="grant.id"
				class="access-grant-card"
			>
				<div class="grant-card-heading">
					<button
						class="grant-plan-button"
						type="button"
						@click="$emit('open-drip', grant)"
					>
						{{ grant.plan_title }}
					</button>
					<div class="grant-card-status">
						<el-tag :type="statusTagType(grant.status)" size="small">{{
							normaliseSourceLabel(grant.status)
						}}</el-tag>
						<el-tag
							v-if="
								grant.trial_ends_at &&
								new Date(grant.trial_ends_at) > new Date()
							"
							type="info"
							size="small"
							>Trial</el-tag
						>
					</div>
				</div>
				<dl class="grant-facts">
					<div>
						<dt>Granted</dt>
						<dd>{{ formatDate(grant.created_at) }}</dd>
					</div>
					<div>
						<dt>Access ends</dt>
						<dd>
							{{ grant.expires_at ? formatDate(grant.expires_at) : "Lifetime" }}
						</dd>
					</div>
					<div>
						<dt>Source</dt>
						<dd>{{ normaliseSourceLabel(grant.source_type) }}</dd>
					</div>
				</dl>
				<div class="grant-card-actions">
					<el-button
						v-if="grant.status === 'active'"
						size="small"
						plain
						@click="$emit('pause', grant)"
						>Pause</el-button
					>
					<el-button
						v-if="grant.status === 'paused'"
						size="small"
						type="success"
						plain
						@click="$emit('resume', grant)"
						>Resume</el-button
					>
					<el-button size="small" @click="$emit('extend', grant)"
						>Extend</el-button
					>
					<el-popconfirm
						title="Revoke this grant?"
						confirm-button-text="Revoke"
						confirm-button-type="danger"
						@confirm="$emit('revoke', grant)"
						><template #reference
							><el-button size="small" type="danger" plain
								>Revoke</el-button
							></template
						></el-popconfirm
					>
				</div>
			</article>
		</div>
		<div v-else class="access-empty-state">
			<span class="access-empty-icon" aria-hidden="true"
				><el-icon><Key /></el-icon
			></span>
			<div>
				<h3>No active access</h3>
				<p>This member cannot open plan-protected content yet.</p>
			</div>
			<el-button type="primary" plain @click="$emit('grant')"
				>Grant access</el-button
			>
		</div>
	</section>
</template>

<script setup>
import { Key } from "@element-plus/icons-vue";
defineProps({
	activeGrants: { type: Array, required: true },
	accessState: { type: Object, required: true },
	formatDate: { type: Function, required: true },
	statusTagType: { type: Function, required: true },
	normaliseSourceLabel: { type: Function, required: true },
});
defineEmits(["grant", "pause", "resume", "extend", "revoke", "open-drip"]);
</script>

<style scoped>
.profile-panel {
	min-width: 0;
	padding: 20px;
	border: 1px solid var(--fchub-border-color);
	border-radius: 12px;
	background: var(--fchub-card-bg);
	box-sizing: border-box;
	box-shadow: 0 1px 2px rgba(16, 24, 40, 0.03);
}
.profile-panel-header {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 16px;
	margin-bottom: 17px;
}
.profile-panel-header > div {
	min-width: 0;
}
.profile-section-eyebrow {
	color: var(--el-color-primary);
	font-size: 10px;
	font-weight: 800;
	letter-spacing: 0.09em;
}
.profile-panel-header h2 {
	margin: 3px 0 4px;
	color: var(--fchub-text-primary);
	font-size: 17px;
	font-weight: 700;
	line-height: 1.25;
}
.profile-panel-header p {
	margin: 0;
	color: var(--fchub-text-secondary);
	font-size: 12px;
	line-height: 1.5;
}
.access-grant-list {
	display: grid;
	gap: 10px;
}
.access-grant-card {
	padding: 16px;
	border: 1px solid var(--fchub-border-color);
	border-radius: 10px;
	background: color-mix(
		in srgb,
		var(--fchub-card-bg) 98%,
		var(--el-color-primary) 2%
	);
}
.grant-card-heading,
.grant-card-status,
.grant-card-actions {
	display: flex;
	align-items: center;
}
.grant-card-heading {
	justify-content: space-between;
	gap: 12px;
}
.grant-card-status,
.grant-card-actions {
	flex-wrap: wrap;
	gap: 6px;
}
.grant-plan-button {
	min-width: 0;
	margin: 0;
	padding: 0;
	border: 0;
	color: var(--fchub-text-primary);
	background: transparent;
	font: inherit;
	font-size: 15px;
	font-weight: 700;
	text-align: left;
	cursor: pointer;
}
.grant-plan-button:hover,
.grant-plan-button:focus-visible {
	color: var(--el-color-primary);
}
.grant-facts {
	display: grid;
	grid-template-columns: repeat(3, minmax(0, 1fr));
	gap: 12px;
	margin: 14px 0;
}
.grant-facts div {
	min-width: 0;
}
.grant-facts dt,
.grant-facts dd {
	margin: 0;
}
.grant-facts dt {
	margin-bottom: 3px;
	color: var(--fchub-text-secondary);
	font-size: 10px;
	font-weight: 700;
	letter-spacing: 0.04em;
	text-transform: uppercase;
}
.grant-facts dd {
	color: var(--fchub-text-primary);
	font-size: 12px;
	line-height: 1.4;
	overflow-wrap: anywhere;
}
.grant-card-actions {
	justify-content: flex-end;
	padding-top: 12px;
	border-top: 1px solid var(--fchub-border-color);
}
.grant-card-actions :deep(.el-button + .el-button) {
	margin-left: 0;
}
.access-empty-state {
	display: grid;
	grid-template-columns: 44px minmax(0, 1fr) auto;
	align-items: center;
	gap: 14px;
	padding: 18px;
	border: 1px dashed
		color-mix(in srgb, var(--el-color-primary) 25%, var(--fchub-border-color));
	border-radius: 10px;
	background: color-mix(
		in srgb,
		var(--fchub-card-bg) 96%,
		var(--el-color-primary) 4%
	);
}
.access-empty-icon {
	display: grid;
	width: 44px;
	height: 44px;
	place-items: center;
	border-radius: 12px;
	color: var(--el-color-primary);
	background: color-mix(
		in srgb,
		var(--el-color-primary) 10%,
		var(--fchub-card-bg)
	);
	font-size: 20px;
}
.access-empty-state h3 {
	margin: 0 0 3px;
	color: var(--fchub-text-primary);
	font-size: 14px;
}
.access-empty-state p {
	margin: 0;
	color: var(--fchub-text-secondary);
	font-size: 12px;
	line-height: 1.45;
}
@media (max-width: 782px) {
	.profile-panel {
		padding: 18px 16px;
	}
	.profile-panel-header {
		gap: 10px;
	}
	.access-empty-state {
		grid-template-columns: 40px minmax(0, 1fr);
		padding: 16px;
	}
	.access-empty-icon {
		width: 40px;
		height: 40px;
	}
	.access-empty-state .el-button {
		grid-column: 1 / -1;
		width: 100%;
		margin-left: 0;
	}
}
@media (max-width: 480px) {
	.profile-panel-header {
		flex-direction: column;
	}
	.profile-panel-header > .el-tag {
		align-self: flex-start;
	}
	.grant-card-heading {
		align-items: flex-start;
	}
	.grant-facts {
		gap: 8px;
	}
	.grant-card-actions {
		display: grid;
		grid-template-columns: repeat(3, minmax(0, 1fr));
	}
	.grant-card-actions :deep(.el-button) {
		width: 100%;
		margin-left: 0;
		padding-inline: 7px;
	}
}
</style>
