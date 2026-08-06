<template>
	<el-drawer
		:model-value="visible"
		:title="`Drip Timeline — ${plan?.plan_title || ''}`"
		direction="rtl"
		size="520px"
		@update:model-value="$emit('update:visible', $event)"
	>
		<div v-loading="loading">
			<template v-if="items.length > 0"
				><el-timeline
					><el-timeline-item
						v-for="item in items"
						:key="item.rule_id || item.id"
						:type="detailType(item)"
						:hollow="item.status === 'locked'"
						:timestamp="detailTimestamp(item)"
						><div class="drip-detail-item">
							<div class="drip-detail-header">
								<span class="drip-detail-title">{{
									item.resource_title || item.title
								}}</span
								><el-tag :type="detailType(item)" size="small">{{
									item.status === "unlocked"
										? "Unlocked"
										: item.status === "scheduled"
											? "Upcoming"
											: "Locked"
								}}</el-tag>
							</div>
							<div class="drip-detail-meta">
								<span v-if="item.resource_type">{{ item.resource_type }}</span
								><span v-if="item.days_offset">
									· {{ item.days_offset }} day{{
										item.days_offset !== 1 ? "s" : ""
									}}
									delay</span
								>
							</div>
							<div
								v-if="item.notification_scheduled != null"
								class="drip-detail-notification"
							>
								<el-tag
									size="small"
									:type="item.notification_scheduled ? 'success' : 'info'"
									>Notification:
									{{
										item.notification_scheduled ? "Scheduled" : "Pending"
									}}</el-tag
								>
							</div>
						</div></el-timeline-item
					></el-timeline
				></template
			>
			<el-empty
				v-else-if="!loading"
				description="No drip schedule for this plan"
				:image-size="60"
			/>
		</div>
	</el-drawer>
</template>

<script setup>
defineProps({
	visible: { type: Boolean, default: false },
	plan: { type: Object, default: null },
	loading: { type: Boolean, default: false },
	items: { type: Array, required: true },
	detailType: { type: Function, required: true },
	detailTimestamp: { type: Function, required: true },
});
defineEmits(["update:visible"]);
</script>

<style scoped>
.drip-detail-item {
	display: grid;
	gap: 6px;
}
.drip-detail-header,
.drip-detail-notification {
	display: flex;
	align-items: center;
	gap: 8px;
}
.drip-detail-title {
	color: var(--fchub-text-primary);
	font-size: 13px;
	font-weight: 600;
}
.drip-detail-meta {
	color: var(--fchub-text-secondary);
	font-size: 11px;
}
</style>
