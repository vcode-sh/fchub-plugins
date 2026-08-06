<template>
	<section
		v-if="timeline.length"
		class="profile-panel drip-panel"
		aria-labelledby="drip-heading"
	>
		<header class="profile-panel-header">
			<div>
				<span class="profile-section-eyebrow">DELIVERY</span>
				<h2 id="drip-heading">Drip schedule</h2>
				<p>Upcoming and unlocked content for current plans.</p>
			</div>
		</header>
		<div
			v-for="planTimeline in timeline"
			:key="planTimeline.plan_id"
			class="drip-plan-group"
		>
			<h3 class="drip-plan-title">{{ planTimeline.plan_title }}</h3>
			<el-timeline
				><el-timeline-item
					v-for="item in planTimeline.items"
					:key="item.id"
					:type="dripItemType(item.status)"
					:hollow="item.status === 'locked'"
					:timestamp="
						item.status === 'unlocked'
							? formatDate(item.unlocked_at)
							: item.status === 'upcoming'
								? `Unlocks ${formatDate(item.unlock_date)}`
								: 'Locked'
					"
					><div class="drip-item">
						<span class="drip-item-title">{{ item.title }}</span
						><el-tag :type="dripItemType(item.status)" size="small">{{
							item.status
						}}</el-tag>
					</div></el-timeline-item
				></el-timeline
			>
		</div>
	</section>
</template>

<script setup>
defineProps({
	timeline: { type: Array, required: true },
	formatDate: { type: Function, required: true },
	dripItemType: { type: Function, required: true },
});
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
.drip-plan-group {
	margin-bottom: 22px;
}
.drip-plan-group:last-child {
	margin-bottom: 0;
}
.drip-plan-title {
	margin: 0 0 12px;
	color: var(--fchub-text-primary);
	font-size: 14px;
	font-weight: 700;
}
.drip-item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}
.drip-item-title {
	color: var(--fchub-text-primary);
	font-size: 13px;
}
@media (max-width: 782px) {
	.profile-panel {
		padding: 18px 16px;
	}
	.profile-panel-header {
		gap: 10px;
	}
}
@media (max-width: 480px) {
	.profile-panel-header {
		flex-direction: column;
	}
}
</style>
