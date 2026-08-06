<template>
	<el-dialog
		:model-value="visible"
		title="Extend Grant"
		width="400px"
		@update:model-value="$emit('update:visible', $event)"
	>
		<el-form label-position="top"
			><el-form-item label="New Expiry Date" required
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
			><el-button
				type="primary"
				@click="$emit('confirm')"
				:loading="loading"
				:disabled="!date"
				>Extend</el-button
			></template
		>
	</el-dialog>
</template>

<script setup>
defineProps({
	visible: { type: Boolean, default: false },
	date: { type: String, required: true },
	loading: { type: Boolean, default: false },
	datePickerFormat: { type: String, required: true },
});
defineEmits(["update:visible", "update:date", "confirm"]);
</script>

<style scoped>
.full-width {
	width: 100%;
}
</style>
