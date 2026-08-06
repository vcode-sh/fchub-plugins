<template>
  <section class="cpw-step cpw-resource-step">
    <div class="cpw-context-strip">
      <span>
        <small>Protecting</small>
        <strong>{{ categorySelectionLabel }}</strong>
      </span>
      <button type="button" @click="emit('select-step', 0)">Change content type</button>
    </div>

    <el-alert
      v-if="resourceError"
      :title="resourceError"
      type="error"
      :closable="false"
      show-icon
      class="cpw-alert"
    />

    <el-form label-position="top" class="cpw-form">
      <el-form-item v-if="!form.resource_type" label="Resource type" required>
        <el-select
          v-model="form.resource_type"
          placeholder="Choose a resource type"
          class="cpw-control"
          @change="emit('type-change')"
        >
          <el-option v-for="type in categoryTypes" :key="type.value" :label="type.label" :value="type.value" />
        </el-select>
      </el-form-item>

      <template v-if="form.resource_type">
        <el-form-item v-if="form.resource_type === 'url_pattern'" label="URL pattern" required>
          <el-input v-model="form.resource_id" placeholder="e.g. /members-only/*" />
          <p class="cpw-field-help">Use <code>*</code> as a wildcard. The pattern <code>/premium/*</code> protects every matching URL.</p>
        </el-form-item>

        <el-form-item v-else-if="form.resource_type === 'special_page'" label="Special page" required>
          <el-select
            v-model="form.resource_id"
            placeholder="Choose a special page"
            class="cpw-control"
            :loading="resourceLoading"
            no-data-text="No special pages are available"
          >
            <el-option v-for="item in resourceOptions" :key="item.id" :label="item.label || item.title" :value="String(item.id)" />
          </el-select>
        </el-form-item>

        <el-form-item v-else-if="form.resource_type === 'menu_item'" label="Menu item" required>
          <el-select
            v-model="form.resource_id"
            placeholder="Choose a menu item"
            class="cpw-control"
            :loading="resourceLoading"
            filterable
            no-data-text="No menu items are available"
          >
            <el-option v-for="item in resourceOptions" :key="item.id" :label="item.label || item.title" :value="String(item.id)" />
          </el-select>
        </el-form-item>

        <el-form-item v-else-if="form.resource_type === 'comment'" label="Comment protection" required>
          <el-radio-group v-model="form.commentMode" class="cpw-choice-group" @change="emit('comment-mode-change')">
            <el-radio value="all" border>All protected content comments</el-radio>
            <el-radio value="specific" border>Comments on one post</el-radio>
          </el-radio-group>
          <el-select
            v-if="form.commentMode === 'specific'"
            v-model="form.resource_id"
            filterable
            remote
            :remote-method="searchResources"
            :loading="resourceLoading"
            placeholder="Choose or search for a post"
            class="cpw-control cpw-nested-control"
            popper-class="cpw-resource-popper"
            no-data-text="No matching posts"
            @visible-change="onResourcePickerVisibility"
          >
            <el-option v-for="item in resourceOptions" :key="item.id" :label="item.label || item.title" :value="String(item.id)" />
          </el-select>
        </el-form-item>

        <el-form-item v-else label="Resource" required>
          <el-select
            v-model="form.resource_id"
            filterable
            remote
            :remote-method="searchResources"
            :loading="resourceLoading"
            :placeholder="resourcePlaceholder"
            class="cpw-control"
            popper-class="cpw-resource-popper"
            no-data-text="No matching content"
            @visible-change="onResourcePickerVisibility"
          >
            <el-option v-for="item in resourceOptions" :key="item.id" :label="item.label || item.title" :value="String(item.id)" />
          </el-select>
          <p class="cpw-field-help">Browse recent resources or type at least 2 characters to search. Only the selected resource will be protected.</p>
        </el-form-item>
      </template>
    </el-form>
  </section>
</template>

<script setup>
const props = defineProps({
  form: { type: Object, required: true },
  categoryTypes: { type: Array, default: () => [] },
  categorySelectionLabel: { type: String, required: true },
  resourceLoading: Boolean,
  resourceError: { type: String, default: '' },
  resourceOptions: { type: Array, default: () => [] },
  resourcePlaceholder: { type: String, required: true },
  searchResources: { type: Function, required: true },
})

const emit = defineEmits(['select-step', 'type-change', 'comment-mode-change'])

function onResourcePickerVisibility(visible) {
  if (visible) {
    props.searchResources('')
  }
}
</script>

<style>
.cpw-context-strip {
  justify-content: space-between;
  margin: 0 0 20px;
}

.cpw-context-strip button,
.cpw-review-heading button {
  border: 0;
  color: var(--el-color-primary);
  background: transparent;
  font: inherit;
  font-size: 12px;
  font-weight: 650;
  cursor: pointer;
}

.cpw-form {
  max-width: 720px;
}

.cpw-form .el-form-item__label {
  margin-bottom: 6px;
  font-weight: 650;
}

.cpw-control {
  width: 100%;
}

.cpw-field-help {
  width: 100%;
  margin: 7px 0 0;
  color: var(--fchub-text-secondary);
  font-size: 12px;
  line-height: 1.45;
}

.cpw-field-help code {
  color: var(--fchub-text-primary);
  background: var(--el-fill-color-light);
  padding: 1px 4px;
  border-radius: 4px;
}

.cpw-resource-popper {
  max-width: calc(100vw - 16px);
  box-sizing: border-box;
}

.cpw-resource-popper .el-select-dropdown,
.cpw-resource-popper .el-select-dropdown__wrap,
.cpw-resource-popper .el-select-dropdown__list {
  max-width: 100%;
}

.cpw-resource-popper .el-select-dropdown__item {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.cpw-alert {
  margin-bottom: 18px;
}

.cpw-choice-group {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
  width: 100%;
}

.cpw-choice-group .el-radio.is-bordered {
  width: 100%;
  height: auto;
  min-height: 44px;
  margin: 0;
}

.cpw-nested-control {
  margin-top: 12px;
}
</style>
