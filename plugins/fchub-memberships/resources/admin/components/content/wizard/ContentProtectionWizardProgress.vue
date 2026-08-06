<template>
  <nav class="cpw-progress" aria-label="Protection setup progress">
    <ol class="cpw-progress-list">
      <li
        v-for="progressStep in steps"
        :key="progressStep.id"
        ref="progressItems"
      >
        <button
          type="button"
          class="cpw-progress-card"
          :class="{
            'is-active': progressStep.id === step,
            'is-complete': progressStep.id < step,
          }"
          :aria-current="progressStep.id === step ? 'step' : undefined"
          :disabled="progressStep.id >= step"
          @click="selectStep(progressStep.id)"
        >
          <span class="cpw-progress-marker" aria-hidden="true">
            <el-icon v-if="progressStep.id < step"><Check /></el-icon>
            <template v-else>{{ progressStep.id + 1 }}</template>
          </span>
          <span class="cpw-progress-copy">
            <small>{{ progressStep.eyebrow }}</small>
            <strong>{{ progressStep.label }}</strong>
            <span>{{ progressStep.description }}</span>
          </span>
        </button>
      </li>
    </ol>
  </nav>
</template>

<script setup>
import { nextTick, ref, watch } from 'vue'
import { Check } from '@element-plus/icons-vue'
import { CONTENT_PROTECTION_STEPS } from '../contentProtectionWizardUi.js'

const props = defineProps({
  step: { type: Number, required: true },
})

const emit = defineEmits(['select-step'])
const steps = CONTENT_PROTECTION_STEPS
const progressItems = ref([])

function selectStep(targetStep) {
  if (targetStep < props.step) {
    emit('select-step', targetStep)
  }
}

watch(
  () => props.step,
  async (activeStep) => {
    await nextTick()
    progressItems.value[activeStep]?.scrollIntoView({
      behavior: 'smooth',
      block: 'nearest',
      inline: 'center',
    })
  },
  { flush: 'post' },
)
</script>

<style>
.cpw-progress {
  width: 100%;
  flex: 0 0 auto;
  padding: 12px 16px;
  box-sizing: border-box;
  border-bottom: 1px solid var(--fchub-border-color);
  background: color-mix(in srgb, var(--fchub-card-bg) 92%, var(--el-color-primary) 8%);
}

.cpw-progress-list {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 8px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.cpw-progress-list li {
  min-width: 0;
  margin: 0;
}

.cpw-progress-card {
  width: 100%;
  height: 66px;
  min-height: 66px;
  display: grid;
  grid-template-columns: 26px minmax(0, 1fr);
  align-items: center;
  gap: 8px;
  padding: 8px 10px;
  box-sizing: border-box;
  border: 1px solid var(--fchub-border-color);
  border-radius: 8px;
  color: var(--fchub-text-secondary);
  background: var(--fchub-card-bg);
  text-align: left;
  cursor: pointer;
  transition: border-color 160ms ease, box-shadow 160ms ease, color 160ms ease;
}

.cpw-progress-card:disabled {
  cursor: default;
  opacity: 1;
}

.cpw-progress-card:not(:disabled):hover {
  border-color: color-mix(in srgb, var(--el-color-primary) 45%, var(--fchub-border-color));
}

.cpw-progress-card.is-active {
  color: var(--fchub-text-primary);
  border-color: var(--el-color-primary);
  box-shadow: inset 0 0 0 1px var(--el-color-primary), 0 6px 16px rgb(38 55 95 / 7%);
}

.cpw-progress-card.is-complete {
  color: var(--fchub-text-primary);
}

.cpw-progress-marker {
  width: 24px;
  height: 24px;
  display: grid;
  place-items: center;
  border: 1px solid var(--fchub-border-color);
  border-radius: 50%;
  background: var(--fchub-card-bg);
  font-size: 11px;
  font-weight: 750;
}

.cpw-progress-card.is-active .cpw-progress-marker {
  color: #fff;
  border-color: var(--el-color-primary);
  background: var(--el-color-primary);
}

.cpw-progress-card.is-complete .cpw-progress-marker {
  color: var(--el-color-success);
  border-color: color-mix(in srgb, var(--el-color-success) 34%, var(--fchub-border-color));
  background: color-mix(in srgb, var(--el-color-success) 9%, var(--fchub-card-bg));
}

.cpw-progress-copy {
  min-width: 0;
  display: grid;
  align-content: center;
  gap: 0;
}

.cpw-progress-copy small,
.cpw-task-eyebrow,
.cpw-review-heading small {
  color: var(--el-color-primary);
  font-size: 10px;
  font-weight: 800;
  letter-spacing: 0.08em;
}

.cpw-progress-copy small {
  font-size: 9px;
  line-height: 1.15;
}

.cpw-progress-copy strong {
  color: inherit;
  font-size: 12px;
  line-height: 1.2;
}

.cpw-progress-copy span {
  display: -webkit-box;
  overflow: hidden;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  color: var(--fchub-text-secondary);
  font-size: 10px;
  line-height: 1.2;
}
</style>
