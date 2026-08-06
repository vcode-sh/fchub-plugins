<template>
	<section
		class="profile-panel history-panel"
		aria-labelledby="history-heading"
	>
		<header class="profile-panel-header">
			<div>
				<span class="profile-section-eyebrow">AUDIT TRAIL</span>
				<h2 id="history-heading">Grant history</h2>
				<p>Every access record, including expired and revoked grants.</p>
			</div>
			<span class="profile-panel-count">{{ grants.length }} total</span>
		</header>
		<div v-if="grants.length" class="grant-history-list">
			<article
				v-for="grant in grants"
				:key="`history-${grant.id}-${grant.status}`"
				class="history-row"
			>
				<div class="history-plan">
					<strong>{{ grant.plan_title }}</strong
					><el-tag :type="statusTagType(grant.status)" size="small">{{
						normaliseSourceLabel(grant.status)
					}}</el-tag>
				</div>
				<dl class="history-facts">
					<div>
						<dt>Granted</dt>
						<dd>{{ formatDate(grant.created_at) }}</dd>
					</div>
					<div>
						<dt>Access ended</dt>
						<dd>
							{{
								grant.revoked_at
									? formatDate(grant.revoked_at)
									: grant.expires_at
										? formatDate(grant.expires_at)
										: "Lifetime"
							}}
						</dd>
					</div>
					<div>
						<dt>Source</dt>
						<dd>{{ normaliseSourceLabel(grant.source_type) }}</dd>
					</div>
				</dl>
			</article>
		</div>
		<div v-else class="compact-empty-state">
			<el-icon><Document /></el-icon><span>No grant history yet.</span>
		</div>
	</section>
</template>

<script setup>
import { Document } from "@element-plus/icons-vue";
defineProps({
	grants: { type: Array, required: true },
	formatDate: { type: Function, required: true },
	statusTagType: { type: Function, required: true },
	normaliseSourceLabel: { type: Function, required: true },
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
.profile-panel-count {
	flex: 0 0 auto;
	color: var(--fchub-text-secondary);
	font-size: 11px;
	font-weight: 650;
}
.grant-history-list {
	overflow: hidden;
	border: 1px solid var(--fchub-border-color);
	border-radius: 10px;
}
.history-row {
	display: grid;
	grid-template-columns: minmax(150px, 0.8fr) minmax(0, 1.2fr);
	align-items: center;
	gap: 18px;
	padding: 14px 16px;
}
.history-row + .history-row {
	border-top: 1px solid var(--fchub-border-color);
}
.history-plan {
	min-width: 0;
	display: flex;
	align-items: center;
	gap: 8px;
}
.history-plan strong {
	min-width: 0;
	color: var(--fchub-text-primary);
	font-size: 13px;
	overflow-wrap: anywhere;
}
.history-facts {
	display: grid;
	grid-template-columns: repeat(3, minmax(0, 1fr));
	gap: 12px;
	margin: 0;
}
.history-facts div {
	min-width: 0;
}
.history-facts dt,
.history-facts dd {
	margin: 0;
}
.history-facts dt {
	margin-bottom: 3px;
	color: var(--fchub-text-secondary);
	font-size: 10px;
	font-weight: 700;
	letter-spacing: 0.04em;
	text-transform: uppercase;
}
.history-facts dd {
	color: var(--fchub-text-primary);
	font-size: 12px;
	line-height: 1.4;
	overflow-wrap: anywhere;
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
@media (max-width: 782px) {
	.profile-panel {
		padding: 18px 16px;
	}
	.profile-panel-header {
		gap: 10px;
	}
	.history-row {
		grid-template-columns: 1fr;
		gap: 12px;
	}
}
@media (max-width: 480px) {
	.profile-panel-header {
		flex-direction: column;
	}
	.profile-panel-count {
		align-self: flex-start;
	}
	.history-facts {
		gap: 8px;
	}
}
</style>
