<template>
	<section class="profile-hero" aria-labelledby="member-profile-title">
		<div class="profile-identity-row">
			<img
				v-if="member.avatar_url"
				class="profile-avatar"
				:src="member.avatar_url"
				alt=""
				width="56"
				height="56"
			/>
			<span v-else class="profile-avatar profile-avatar-initials" aria-hidden="true">{{
				initials
			}}</span>
			<div class="profile-identity">
				<span class="profile-eyebrow">MEMBER WORKSPACE</span>
				<h1 id="member-profile-title">
					<a v-if="member.edit_url" :href="member.edit_url">{{ member.display_name }}</a>
					<template v-else>{{ member.display_name }}</template>
				</h1>
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
				<el-button type="primary" class="profile-primary-action" @click="$emit('grant')">
					<el-icon><Plus /></el-icon>
					Grant access
				</el-button>
				<el-popconfirm
					title="Revoke every current membership for this member?"
					confirm-button-text="Revoke all"
					confirm-button-type="danger"
					:disabled="!canRevokeAll"
					@confirm="$emit('revoke-all')"
				>
					<template #reference>
						<el-button type="danger" plain :loading="revokingAll" :disabled="!canRevokeAll">
							<el-icon><CircleClose /></el-icon>
							Revoke all
						</el-button>
					</template>
				</el-popconfirm>
			</div>
		</div>

		<p class="profile-verdict" :class="{ 'is-active': verdict.hasAccess }">
			<span class="profile-verdict-dot" aria-hidden="true"></span>
			<strong>{{ verdict.headline }}</strong>
			<span v-if="verdict.detail" class="profile-verdict-detail">{{ verdict.detail }}</span>
		</p>
	</section>
</template>

<script setup>
import { Calendar, CircleClose, Message, Plus } from "@element-plus/icons-vue";

defineProps({
	member: { type: Object, required: true },
	initials: { type: String, required: true },
	verdict: { type: Object, required: true },
	canRevokeAll: { type: Boolean, default: false },
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
	width: 56px;
	height: 56px;
	border-radius: 15px;
	box-shadow: 0 6px 18px color-mix(in srgb, var(--el-color-primary) 25%, transparent);
	object-fit: cover;
}
.profile-avatar-initials {
	display: grid;
	place-items: center;
	color: #fff;
	background: var(--el-color-primary);
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
.profile-identity h1 a {
	color: inherit;
	text-decoration: none;
}
.profile-identity h1 a:hover,
.profile-identity h1 a:focus-visible {
	color: var(--el-color-primary);
	text-decoration: underline;
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
.profile-verdict {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 4px 10px;
	margin: 0;
	padding: 13px 24px;
	border-top: 1px solid var(--fchub-border-color);
	background: color-mix(in srgb, var(--fchub-card-bg) 97%, var(--el-color-primary) 3%);
	font-size: 13px;
}
.profile-verdict strong {
	color: var(--fchub-text-primary);
	font-size: 14px;
}
.profile-verdict-detail {
	color: var(--fchub-text-secondary);
	font-size: 12px;
}
.profile-verdict-dot {
	width: 8px;
	height: 8px;
	align-self: center;
	border-radius: 50%;
	background: var(--fchub-text-secondary);
}
.profile-verdict.is-active .profile-verdict-dot {
	background: var(--el-color-success);
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
	.profile-verdict {
		padding: 12px 16px;
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
