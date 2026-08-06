<template>
	<section
		class="profile-panel activity-panel"
		role="region"
		aria-label="Activity timeline"
	>
		<header class="profile-panel-header">
			<div>
				<span class="profile-section-eyebrow">RECENT EVENTS</span>
				<h2>Activity</h2>
				<p>Membership changes and automated events.</p>
			</div>
		</header>
		<div v-loading="loading">
			<ol v-if="events.length" class="activity-feed">
				<li
					v-for="(event, idx) in events"
					:key="`${event.type}-${event.date}-${idx}`"
					class="activity-feed-item"
				>
					<span
						class="activity-dot"
						:class="`is-${eventColor(event.type)}`"
						aria-hidden="true"
					></span>
					<div class="activity-event">
						<div class="activity-event-topline">
							<el-tag :type="eventColor(event.type)" size="small">{{
								eventLabel(event.type)
							}}</el-tag
							><time>{{ formatDate(event.date) }}</time>
						</div>
						<p class="activity-description">{{ event.description }}</p>
						<div
							v-if="
								event.metadata &&
								(event.metadata.context ||
									event.metadata.plan_title ||
									event.metadata.source_type)
							"
							class="activity-details"
						>
							<span v-if="event.metadata.plan_title">{{
								event.metadata.plan_title
							}}</span
							><span v-if="event.metadata.source_type">{{
								normaliseSourceLabel(event.metadata.source_type)
							}}</span
							><span v-if="event.metadata.context">{{
								event.metadata.context
							}}</span>
						</div>
					</div>
				</li>
			</ol>
			<div v-else-if="!loading" class="compact-empty-state">
				<el-icon><List /></el-icon><span>No activity recorded.</span>
			</div>
			<div v-if="total > events.length" class="activity-load-more">
				<el-button
					@click="$emit('load-more')"
					:loading="loadingMore"
					size="small"
					>Load more ({{ events.length }} of {{ total }})</el-button
				>
			</div>
		</div>
	</section>
</template>

<script setup>
import { List } from "@element-plus/icons-vue";
defineProps({
	events: { type: Array, required: true },
	total: { type: Number, required: true },
	loading: { type: Boolean, default: false },
	loadingMore: { type: Boolean, default: false },
	formatDate: { type: Function, required: true },
	eventColor: { type: Function, required: true },
	eventLabel: { type: Function, required: true },
	normaliseSourceLabel: { type: Function, required: true },
});
defineEmits(["load-more"]);
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
.activity-feed {
	margin: 0;
	padding: 0;
	list-style: none;
}
.activity-feed-item {
	position: relative;
	display: grid;
	grid-template-columns: 12px minmax(0, 1fr);
	gap: 10px;
	padding-bottom: 18px;
}
.activity-feed-item:not(:last-child)::before {
	content: "";
	position: absolute;
	top: 13px;
	bottom: 0;
	left: 5px;
	width: 1px;
	background: var(--fchub-border-color);
}
.activity-dot {
	position: relative;
	z-index: 1;
	width: 10px;
	height: 10px;
	margin-top: 4px;
	border: 2px solid var(--fchub-card-bg);
	border-radius: 50%;
	background: var(--el-color-info);
	box-shadow: 0 0 0 1px var(--fchub-border-color);
}
.activity-dot.is-success {
	background: var(--el-color-success);
}
.activity-dot.is-warning {
	background: var(--el-color-warning);
}
.activity-dot.is-danger {
	background: var(--el-color-danger);
}
.activity-event {
	min-width: 0;
}
.activity-event-topline {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}
.activity-event-topline time {
	flex: 0 0 auto;
	color: var(--fchub-text-secondary);
	font-size: 10px;
}
.activity-description {
	margin: 7px 0 0;
	color: var(--fchub-text-primary);
	font-size: 12px;
	font-weight: 550;
	line-height: 1.45;
	overflow-wrap: anywhere;
}
.activity-details {
	display: flex;
	flex-wrap: wrap;
	gap: 4px 9px;
	margin-top: 5px;
	color: var(--fchub-text-secondary);
	font-size: 10px;
	line-height: 1.4;
}
.compact-empty-state {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 8px;
	min-height: 72px;
	padding: 12px;
	border: 1px dashed var(--fchub-border-color);
	border-radius: 9px;
	color: var(--fchub-text-secondary);
	font-size: 12px;
	box-sizing: border-box;
}
.activity-load-more {
	padding-top: 12px;
	text-align: center;
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
