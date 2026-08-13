<template>
	<section class="profile-panel check-panel" role="region" aria-label="Access check">
		<header class="profile-panel-header">
			<div>
				<span class="profile-section-eyebrow">TRIAGE</span>
				<h2>Can they open it?</h2>
				<p>Ask the access evaluator about a protected item.</p>
			</div>
		</header>

		<el-select
			:model-value="selected"
			filterable
			remote
			clearable
			reserve-keyword
			placeholder="Search protected content"
			:remote-method="search"
			:loading="searching"
			class="check-select"
			@update:model-value="$emit('update:selected', $event)"
		>
			<el-option
				v-for="option in options"
				:key="option.value"
				:label="option.label"
				:value="option.value"
			/>
		</el-select>

		<el-button
			type="primary"
			plain
			class="check-button"
			:disabled="!selected"
			:loading="checking"
			@click="$emit('check')"
			>Check access</el-button
		>

		<div v-if="result" class="check-result" :class="{ 'is-allowed': result.allowed }">
			<span class="check-result-icon" aria-hidden="true"
				><el-icon><CircleCheck v-if="result.allowed" /><CircleClose v-else /></el-icon
			></span>
			<div>
				<strong>{{ result.headline }}</strong>
				<p>{{ result.detail }}</p>
				<small>{{ result.resource }}</small>
			</div>
		</div>

		<p class="check-note">
			Covers content protected by a rule. URL patterns and menu protection are decided at
			request time and are not checked here.
		</p>
	</section>
</template>

<script setup>
import { CircleCheck, CircleClose } from "@element-plus/icons-vue";

defineProps({
	options: { type: Array, required: true },
	selected: { type: String, default: "" },
	result: { type: Object, default: null },
	searching: { type: Boolean, default: false },
	checking: { type: Boolean, default: false },
	search: { type: Function, required: true },
});

defineEmits(["check", "update:selected"]);
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
	margin-bottom: 14px;
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
.check-select {
	width: 100%;
}
.check-button {
	width: 100%;
	margin-top: 10px;
}
.check-result {
	display: grid;
	grid-template-columns: 30px minmax(0, 1fr);
	gap: 10px;
	margin-top: 14px;
	padding: 12px;
	border: 1px solid var(--fchub-border-color);
	border-radius: 10px;
	background: color-mix(in srgb, var(--fchub-card-bg) 96%, var(--el-color-danger) 4%);
}
.check-result.is-allowed {
	background: color-mix(in srgb, var(--fchub-card-bg) 96%, var(--el-color-success) 4%);
}
.check-result-icon {
	color: var(--el-color-danger);
	font-size: 20px;
}
.check-result.is-allowed .check-result-icon {
	color: var(--el-color-success);
}
.check-result strong {
	color: var(--fchub-text-primary);
	font-size: 13px;
}
.check-result p {
	margin: 3px 0 0;
	color: var(--fchub-text-secondary);
	font-size: 12px;
	line-height: 1.45;
}
.check-result small {
	display: block;
	margin-top: 5px;
	color: var(--fchub-text-secondary);
	font-size: 11px;
	overflow-wrap: anywhere;
}
.check-note {
	margin: 12px 0 0;
	color: var(--fchub-text-secondary);
	font-size: 11px;
	line-height: 1.5;
}
@media (max-width: 782px) {
	.profile-panel {
		padding: 18px 16px;
	}
}
</style>
