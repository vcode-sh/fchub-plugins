<template>
  <section class="cpw-step cpw-review-step">
    <div class="cpw-review-group">
      <div class="cpw-review-heading">
        <span>
          <small>CONTENT</small>
          <strong>Protected resource</strong>
        </span>
        <button type="button" @click="emit('select-step', 0)">Edit</button>
      </div>
      <dl>
        <div>
          <dt>Type</dt>
          <dd>{{ form.resource_type_label || form.resource_type }}</dd>
        </div>
        <div>
          <dt>Resource</dt>
          <dd>{{ resourceDisplayName }}</dd>
        </div>
      </dl>
    </div>

    <div class="cpw-review-group">
      <div class="cpw-review-heading">
        <span>
          <small>ACCESS</small>
          <strong>Membership plans</strong>
        </span>
        <button type="button" @click="emit('select-step', 2)">Edit</button>
      </div>
      <div class="cpw-plan-tags">
        <el-tag v-for="id in form.plan_ids" :key="id" type="info">
          {{ planOptionsMap[id] || `Plan #${id}` }}
        </el-tag>
      </div>
    </div>

    <div class="cpw-review-group">
      <div class="cpw-review-heading">
        <span>
          <small>VISITOR EXPERIENCE</small>
          <strong>When access is blocked</strong>
        </span>
        <button type="button" @click="emit('select-step', 2)">Edit</button>
      </div>
      <dl>
        <div>
          <dt>Teaser</dt>
          <dd>{{ form.show_teaser === 'yes' ? 'Shown' : 'Hidden' }}</dd>
        </div>
        <div v-if="form.restriction_message">
          <dt>Message</dt>
          <dd class="cpw-review-long">{{ form.restriction_message }}</dd>
        </div>
        <div v-if="form.redirect_url">
          <dt>Redirect</dt>
          <dd class="cpw-review-long">{{ form.redirect_url }}</dd>
        </div>
        <div v-if="!form.restriction_message && !form.redirect_url">
          <dt>Fallback</dt>
          <dd>Site default restriction message</dd>
        </div>
      </dl>
    </div>
  </section>
</template>

<script setup>
defineProps({
  form: { type: Object, required: true },
  planOptionsMap: { type: Object, required: true },
  resourceDisplayName: { type: String, required: true },
})

const emit = defineEmits(['select-step'])
</script>

<style>
.cpw-review-step {
  display: grid;
  gap: 14px;
}

.cpw-review-group {
  overflow: hidden;
  border: 1px solid var(--fchub-border-color);
  border-radius: 10px;
  background: var(--fchub-card-bg);
}

.cpw-review-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 13px 16px;
  border-bottom: 1px solid var(--fchub-border-color);
  background: color-mix(in srgb, var(--fchub-card-bg) 94%, var(--el-color-primary) 6%);
}

.cpw-review-heading > span {
  display: grid;
  gap: 2px;
}

.cpw-review-heading strong {
  color: var(--fchub-text-primary);
  font-size: 13px;
}

.cpw-review-group dl {
  margin: 0;
}

.cpw-review-group dl > div {
  display: grid;
  grid-template-columns: 120px minmax(0, 1fr);
  gap: 16px;
  padding: 12px 16px;
}

.cpw-review-group dl > div + div {
  border-top: 1px solid var(--fchub-border-color);
}

.cpw-review-group dt,
.cpw-review-group dd {
  margin: 0;
  font-size: 13px;
  line-height: 1.45;
}

.cpw-review-group dt {
  color: var(--fchub-text-secondary);
  font-weight: 550;
}

.cpw-review-group dd {
  color: var(--fchub-text-primary);
}

.cpw-review-long {
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}

.cpw-plan-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  padding: 14px 16px;
}
</style>
