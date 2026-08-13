<template>
	<section class="profile-panel access-panel" role="region" aria-label="Memberships">
		<header class="profile-panel-header">
			<div>
				<span class="profile-section-eyebrow">ACCESS</span>
				<h2>Memberships</h2>
				<p>Every plan this member holds, current and ended.</p>
			</div>
			<el-tag :type="currentCount ? 'success' : 'info'" effect="light">{{
				currentCount ? `${currentCount} current` : "None current"
			}}</el-tag>
		</header>

		<div v-if="memberships.length" class="membership-list">
			<MembershipCard
				v-for="membership in memberships"
				:key="membership.key"
				:membership="membership"
				:expanded="expandedKeys.includes(membership.key)"
				:drip="dripByKey[membership.key] || null"
				:provider-state="providerStateByKey[membership.key] || null"
				:provider-checking="providerCheckPending === membership.key"
				:format-date="formatDate"
				@toggle="$emit('toggle', $event)"
				@pause="$emit('pause', $event)"
				@resume="$emit('resume', $event)"
				@extend="$emit('extend', $event)"
				@revoke="$emit('revoke', $event)"
				@check-providers="$emit('check-providers', $event)"
			/>
		</div>

		<div v-else class="access-empty-state">
			<span class="access-empty-icon" aria-hidden="true"
				><el-icon><Key /></el-icon
			></span>
			<div>
				<h3>No memberships</h3>
				<p>This member cannot open plan-protected content yet.</p>
			</div>
			<el-button type="primary" plain @click="$emit('grant')">Grant access</el-button>
		</div>
	</section>
</template>

<script setup>
import { computed } from "vue";
import { Key } from "@element-plus/icons-vue";
import MembershipCard from "./MembershipCard.vue";
import { isCurrentMembership } from "@/pages/Members/memberProfileUi.js";

const props = defineProps({
	memberships: { type: Array, required: true },
	expandedKeys: { type: Array, default: () => [] },
	dripByKey: { type: Object, default: () => ({}) },
	providerStateByKey: { type: Object, default: () => ({}) },
	providerCheckPending: { type: String, default: "" },
	formatDate: { type: Function, required: true },
});

defineEmits(["grant", "toggle", "pause", "resume", "extend", "revoke", "check-providers"]);

const currentCount = computed(() => props.memberships.filter(isCurrentMembership).length);
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
.membership-list {
	display: grid;
	gap: 10px;
}
.access-empty-state {
	display: grid;
	grid-template-columns: 44px minmax(0, 1fr) auto;
	align-items: center;
	gap: 14px;
	padding: 18px;
	border: 1px dashed color-mix(in srgb, var(--el-color-primary) 25%, var(--fchub-border-color));
	border-radius: 10px;
	background: color-mix(in srgb, var(--fchub-card-bg) 96%, var(--el-color-primary) 4%);
}
.access-empty-icon {
	display: grid;
	width: 44px;
	height: 44px;
	place-items: center;
	border-radius: 12px;
	color: var(--el-color-primary);
	background: color-mix(in srgb, var(--el-color-primary) 10%, var(--fchub-card-bg));
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
}
</style>
