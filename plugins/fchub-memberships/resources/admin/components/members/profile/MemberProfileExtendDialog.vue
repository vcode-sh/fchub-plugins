<template>
	<el-dialog
		:model-value="visible"
		title="Extend membership"
		width="400px"
		@update:model-value="$emit('update:visible', $event)"
	>
		<div class="extend-presets">
			<el-button
				v-for="preset in presets"
				:key="preset.label"
				size="small"
				:type="date === preset.value ? 'primary' : 'default'"
				plain
				@click="$emit('update:date', preset.value)"
				>{{ preset.label }}</el-button
			>
		</div>
		<el-form label-position="top"
			><el-form-item label="New expiry date" required
				><el-date-picker
					:model-value="date"
					type="date"
					placeholder="Select new expiry date"
					:format="datePickerFormat"
					value-format="YYYY-MM-DD"
					class="full-width"
					@update:model-value="$emit('update:date', $event)" /></el-form-item
		></el-form>
		<template #footer
			><el-button @click="$emit('update:visible', false)">Cancel</el-button
			><el-button type="primary" :loading="loading" :disabled="!date" @click="$emit('confirm')"
				>Extend</el-button
			></template
		>
	</el-dialog>
</template>

<script setup>
defineProps({
	visible: { type: Boolean, default: false },
	date: { type: String, required: true },
	presets: { type: Array, default: () => [] },
	loading: { type: Boolean, default: false },
	datePickerFormat: { type: String, required: true },
});
defineEmits(["update:visible", "update:date", "confirm"]);
</script>

<style scoped>
.extend-presets {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	margin-bottom: 14px;
}
.extend-presets :deep(.el-button + .el-button) {
	margin-left: 0;
}
.full-width {
	width: 100%;
}
</style>
