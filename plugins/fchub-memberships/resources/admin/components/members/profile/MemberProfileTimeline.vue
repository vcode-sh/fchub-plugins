<template>
	<section class="profile-panel timeline-panel" role="region" aria-label="Membership history">
		<header class="profile-panel-header">
			<div>
				<span class="profile-section-eyebrow">AUDIT TRAIL</span>
				<h2>History</h2>
				<p>Every recorded change to this member's access.</p>
			</div>
			<el-select
				v-if="typeOptions.length > 1"
				:model-value="filter"
				size="small"
				class="timeline-filter"
				@update:model-value="$emit('update:filter', $event)"
			>
				<el-option label="All events" value="" />
				<el-option
					v-for="type in typeOptions"
					:key="type"
					:label="eventLabel(type)"
					:value="type"
				/>
			</el-select>
		</header>

		<div v-loading="loading">
			<ol v-if="visibleEvents.length" class="timeline-feed">
				<li v-for="(event, index) in visibleEvents" :key="`${event.type}-${event.date}-${index}`">
					<span class="timeline-dot" :class="`is-${eventColor(event.type)}`" aria-hidden="true"></span>
					<div class="timeline-event">
						<div class="timeline-topline">
							<el-tag :type="eventColor(event.type)" size="small">{{
								eventLabel(event.type)
							}}</el-tag>
							<time>{{ formatDate(event.date) }}</time>
						</div>
						<p class="timeline-description">{{ event.description }}</p>
					</div>
				</li>
			</ol>
			<div v-else-if="!loading" class="compact-empty-state">
				<el-icon><List /></el-icon
				><span>{{ events.length ? "No events of this type." : "Nothing recorded yet." }}</span>
			</div>
			<div v-if="!filter && total > events.length" class="timeline-load-more">
				<el-button size="small" :loading="loadingMore" @click="$emit('load-more')"
					>Load more ({{ events.length }} of {{ total }})</el-button
				>
			</div>
		</div>
	</section>
</template>

<script setup>
import { computed } from "vue";
import { List } from "@element-plus/icons-vue";
import { normaliseSourceLabel } from "@/pages/Members/memberProfileUi.js";

const props = defineProps({
	events: { type: Array, required: true },
	total: { type: Number, required: true },
	filter: { type: String, default: "" },
	loading: { type: Boolean, default: false },
	loadingMore: { type: Boolean, default: false },
	formatDate: { type: Function, required: true },
});

defineEmits(["load-more", "update:filter"]);

const EVENT_COLORS = {
	granted: "success",
	renewed: "success",
	extended: "success",
	resumed: "success",
	drip_sent: "success",
	paused: "warning",
	expired: "warning",
	trial_expired: "warning",
	drip_scheduled: "info",
	trial_converted: "info",
	revoked: "danger",
	drip_failed: "danger",
};

const typeOptions = computed(() => [...new Set(props.events.map((event) => event.type))].sort());
const visibleEvents = computed(() =>
	props.filter ? props.events.filter((event) => event.type === props.filter) : props.events,
);

function eventColor(type) {
	return EVENT_COLORS[type] || "info";
}

function eventLabel(type) {
	return normaliseSourceLabel(type);
}
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
.timeline-filter {
	width: 150px;
	flex: 0 0 auto;
}
.timeline-feed {
	margin: 0;
	padding: 0;
	list-style: none;
}
.timeline-feed li {
	display: grid;
	grid-template-columns: 12px minmax(0, 1fr);
	gap: 10px;
	padding: 10px 0;
	border-top: 1px solid var(--fchub-border-color);
}
.timeline-feed li:first-child {
	border-top: 0;
	padding-top: 0;
}
.timeline-dot {
	width: 8px;
	height: 8px;
	margin-top: 6px;
	border-radius: 50%;
	background: var(--el-color-info);
}
.timeline-dot.is-success {
	background: var(--el-color-success);
}
.timeline-dot.is-warning {
	background: var(--el-color-warning);
}
.timeline-dot.is-danger {
	background: var(--el-color-danger);
}
.timeline-event {
	min-width: 0;
}
.timeline-topline {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}
.timeline-topline time {
	color: var(--fchub-text-secondary);
	font-size: 11px;
}
.timeline-description {
	margin: 5px 0 0;
	color: var(--fchub-text-primary);
	font-size: 12px;
	line-height: 1.5;
	overflow-wrap: anywhere;
}
.compact-empty-state {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 14px 0;
	color: var(--fchub-text-secondary);
	font-size: 12px;
}
.timeline-load-more {
	margin-top: 12px;
	text-align: center;
}
@media (max-width: 782px) {
	.profile-panel {
		padding: 18px 16px;
	}
}
</style>
