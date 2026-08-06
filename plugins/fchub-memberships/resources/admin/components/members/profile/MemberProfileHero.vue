<template>
	<section class="profile-hero" aria-labelledby="member-profile-title">
		<div class="profile-identity-row">
			<span class="profile-avatar" aria-hidden="true">{{ initials }}</span>
			<div class="profile-identity">
				<span class="profile-eyebrow">MEMBER WORKSPACE</span>
				<h1 id="member-profile-title">{{ member.display_name }}</h1>
				<div class="profile-meta">
					<span
						><el-icon><Message /></el-icon
						>{{ member.email || member.user_email }}</span
					>
					<span
						><el-icon><Calendar /></el-icon>Registered
						{{ formatDate(member.registered_at) }}</span
					>
				</div>
			</div>
			<div class="profile-actions">
				<el-button
					type="primary"
					class="profile-primary-action"
					@click="$emit('grant')"
				>
					<el-icon><Plus /></el-icon>
					Grant access
				</el-button>
				<el-popconfirm
					title="Revoke every current grant for this member?"
					confirm-button-text="Revoke all"
					confirm-button-type="danger"
					:disabled="!accessState.canRevokeAll"
					@confirm="$emit('revoke-all')"
				>
					<template #reference>
						<el-button
							type="danger"
							plain
							:loading="revokingAll"
							:disabled="!accessState.canRevokeAll"
						>
							<el-icon><CircleClose /></el-icon>
							Revoke all
						</el-button>
					</template>
				</el-popconfirm>
			</div>
		</div>

		<div class="profile-summary" role="region" aria-label="Membership summary">
			<article class="profile-stat">
				<span class="profile-stat-icon is-primary"
					><el-icon><Key /></el-icon
				></span>
				<span
					><strong>{{ summary.activeCount }}</strong
					><small>Active access</small></span
				>
			</article>
			<article class="profile-stat">
				<span class="profile-stat-icon is-neutral"
					><el-icon><Document /></el-icon
				></span>
				<span
					><strong>{{ summary.historyCount }}</strong
					><small>Grant history</small></span
				>
			</article>
			<article class="profile-stat">
				<span class="profile-stat-icon is-success"
					><el-icon><List /></el-icon
				></span>
				<span
					><strong>{{ summary.activityCount }}</strong
					><small>Activity</small></span
				>
			</article>
		</div>
	</section>
</template>

<script setup>
import {
	Calendar,
	CircleClose,
	Document,
	Key,
	List,
	Message,
	Plus,
} from "@element-plus/icons-vue";

defineProps({
	member: { type: Object, required: true },
	initials: { type: String, required: true },
	summary: { type: Object, required: true },
	accessState: { type: Object, required: true },
	revokingAll: { type: Boolean, default: false },
	formatDate: { type: Function, required: true },
});

defineEmits(["grant", "revoke-all"]);
</script>

<style scoped>
.profile-hero {
	overflow: hidden;
	margin-bottom: 16px;
	border: 1px solid var(--fchub-border-color);
	border-radius: 12px;
	background: linear-gradient(
		135deg,
		color-mix(in srgb, var(--fchub-card-bg) 96%, var(--el-color-primary) 4%),
		var(--fchub-card-bg)
	);
	box-shadow: 0 1px 2px rgba(16, 24, 40, 0.03);
}
.profile-identity-row {
	display: grid;
	grid-template-columns: 56px minmax(0, 1fr) auto;
	align-items: center;
	gap: 16px;
	padding: 22px 24px;
}
.profile-avatar {
	display: grid;
	width: 56px;
	height: 56px;
	place-items: center;
	border-radius: 15px;
	color: #fff;
	background: var(--el-color-primary);
	box-shadow: 0 6px 18px
		color-mix(in srgb, var(--el-color-primary) 25%, transparent);
	font-size: 16px;
	font-weight: 780;
	letter-spacing: 0.04em;
}
.profile-identity {
	min-width: 0;
}
.profile-eyebrow {
	color: var(--el-color-primary);
	font-size: 10px;
	font-weight: 800;
	letter-spacing: 0.09em;
}
.profile-identity h1 {
	margin: 3px 0 7px;
	color: var(--fchub-text-primary);
	font-size: 23px;
	font-weight: 730;
	line-height: 1.2;
}
.profile-meta {
	display: flex;
	flex-wrap: wrap;
	gap: 8px 18px;
	color: var(--fchub-text-secondary);
	font-size: 12px;
}
.profile-meta span {
	min-width: 0;
	display: inline-flex;
	align-items: center;
	gap: 5px;
	overflow-wrap: anywhere;
}
.profile-actions {
	display: flex;
	align-items: center;
	gap: 8px;
}
.profile-summary {
	display: grid;
	grid-template-columns: repeat(3, minmax(0, 1fr));
	border-top: 1px solid var(--fchub-border-color);
	background: color-mix(
		in srgb,
		var(--fchub-card-bg) 97%,
		var(--el-color-primary) 3%
	);
}
.profile-stat {
	min-width: 0;
	display: flex;
	align-items: center;
	gap: 11px;
	padding: 14px 24px;
}
.profile-stat + .profile-stat {
	border-left: 1px solid var(--fchub-border-color);
}
.profile-stat-icon {
	display: grid;
	width: 34px;
	height: 34px;
	flex: 0 0 auto;
	place-items: center;
	border-radius: 9px;
}
.profile-stat-icon.is-primary {
	color: var(--el-color-primary);
	background: color-mix(
		in srgb,
		var(--el-color-primary) 10%,
		var(--fchub-card-bg)
	);
}
.profile-stat-icon.is-neutral {
	color: var(--fchub-text-secondary);
	background: color-mix(
		in srgb,
		var(--fchub-text-secondary) 9%,
		var(--fchub-card-bg)
	);
}
.profile-stat-icon.is-success {
	color: var(--el-color-success);
	background: color-mix(
		in srgb,
		var(--el-color-success) 10%,
		var(--fchub-card-bg)
	);
}
.profile-stat > span:last-child {
	min-width: 0;
	display: grid;
	gap: 1px;
}
.profile-stat strong {
	color: var(--fchub-text-primary);
	font-size: 18px;
	line-height: 1.1;
}
.profile-stat small {
	color: var(--fchub-text-secondary);
	font-size: 11px;
}
@media (max-width: 782px) {
	.profile-identity-row {
		grid-template-columns: 48px minmax(0, 1fr);
		align-items: start;
		gap: 12px;
		padding: 18px 16px;
	}
	.profile-avatar {
		width: 48px;
		height: 48px;
		border-radius: 13px;
		font-size: 14px;
	}
	.profile-identity h1 {
		font-size: 20px;
	}
	.profile-meta {
		display: grid;
		gap: 5px;
	}
	.profile-actions {
		grid-column: 1 / -1;
		width: 100%;
	}
	.profile-actions :deep(.el-button),
	.profile-actions :deep(.el-popconfirm) {
		min-width: 0;
		flex: 1 1 0;
	}
	.profile-actions :deep(.el-button + .el-button) {
		margin-left: 0;
	}
	.profile-stat {
		justify-content: center;
		padding: 12px 8px;
	}
	.profile-stat-icon {
		display: none;
	}
	.profile-stat > span:last-child {
		justify-items: center;
		text-align: center;
	}
}
@media (max-width: 480px) {
	.profile-actions {
		display: grid;
		grid-template-columns: repeat(2, minmax(0, 1fr));
	}
	.profile-actions :deep(.el-button) {
		width: 100%;
		padding-inline: 10px;
	}
}
</style>
