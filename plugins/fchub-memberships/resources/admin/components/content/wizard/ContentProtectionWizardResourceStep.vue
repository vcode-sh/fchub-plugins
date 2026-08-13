<template>
  <section class="cpw-step cpw-resource-step">
    <div class="cpw-context-strip">
      <span>
        <small>Protecting</small>
        <strong>{{ categorySelectionLabel }}</strong>
      </span>
      <button type="button" @click="emit('select-step', 0)">Change content type</button>
    </div>

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
            no-data-text="No special pages are available"
            @change="onSpecialPageChange"
          >
            <el-option v-for="item in specialPages" :key="item.id" :label="item.label" :value="String(item.id)" />
          </el-select>
        </el-form-item>

        <el-form-item v-else-if="form.resource_type === 'comment'" label="Comment protection" required>
          <el-radio-group v-model="form.commentMode" class="cpw-choice-group" @change="emit('comment-mode-change')">
            <el-radio value="all" border>All protected content comments</el-radio>
            <el-radio value="specific" border>Comments on one post</el-radio>
          </el-radio-group>
          <ResourcePicker
            v-if="form.commentMode === 'specific'"
            v-model="form.resource_id"
            v-model:label="form.resource_label"
            resource-type="post"
            type-label="Posts"
            class="cpw-nested-control"
          />
        </el-form-item>

        <el-form-item v-else label="Resource" required>
          <ResourcePicker
            v-model="form.resource_id"
            v-model:label="form.resource_label"
            :resource-type="form.resource_type"
            :type-label="form.resource_type_label || 'content'"
          />
          <p class="cpw-field-help">Browse the most recent items or type to search. Only the selected resource will be protected.</p>
        </el-form-item>
      </template>
    </el-form>
  </section>
</template>

<script setup>
import ResourcePicker from '@/components/content/ResourcePicker.vue'

const props = defineProps({
  form: { type: Object, required: true },
  categoryTypes: { type: Array, default: () => [] },
  categorySelectionLabel: { type: String, required: true },
  specialPages: { type: Array, default: () => [] },
})

const emit = defineEmits(['select-step', 'type-change', 'comment-mode-change'])

function onSpecialPageChange(id) {
  const page = props.specialPages.find((item) => String(item.id) === String(id))
  props.form.resource_label = page ? page.label : null
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
