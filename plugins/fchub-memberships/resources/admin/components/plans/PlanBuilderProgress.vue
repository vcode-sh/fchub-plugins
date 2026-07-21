<template>
  <nav class="builder-progress" aria-label="Plan creation progress">
    <ol class="builder-progress-list">
      <li
        v-for="step in steps"
        :key="step.id"
        class="builder-progress-item"
      >
        <button
          type="button"
          class="builder-step"
          :class="{
            'is-active': step.id === activeStep,
            'is-complete': completedSteps.includes(step.id),
          }"
          :aria-current="step.id === activeStep ? 'step' : undefined"
          @click="selectStep(step.id)"
        >
          <span class="builder-step-marker" aria-hidden="true">
            <el-icon v-if="completedSteps.includes(step.id) && step.id !== activeStep"><Check /></el-icon>
            <template v-else>{{ step.number }}</template>
          </span>
          <span class="builder-step-copy">
            <span class="builder-step-eyebrow">{{ step.eyebrow }}</span>
            <strong>{{ step.label }}</strong>
            <small>{{ step.description }}</small>
          </span>
        </button>
      </li>
    </ol>
  </nav>
</template>

<script setup>
import { Check } from '@element-plus/icons-vue'

const props = defineProps({
  steps: {
    type: Array,
    required: true,
  },
  activeStep: {
    type: String,
    required: true,
  },
  completedSteps: {
    type: Array,
    default: () => [],
  },
})

const emit = defineEmits(['select'])

function selectStep(stepId) {
  if (props.steps.some(({ id }) => id === stepId)) {
    emit('select', stepId)
  }
}
</script>

<style scoped>
.builder-progress-list {
  display: grid;
  gap: 10px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.builder-progress-item {
  margin: 0;
}

.builder-step {
  width: 100%;
  display: grid;
  grid-template-columns: 32px minmax(0, 1fr);
  gap: 12px;
  align-items: start;
  padding: 13px;
  border: 1px solid transparent;
  border-radius: 10px;
  color: var(--fchub-text-secondary);
  background: transparent;
  text-align: left;
  cursor: pointer;
  transition: background-color 160ms ease, border-color 160ms ease, color 160ms ease;
}

.builder-step:hover {
  background: var(--fchub-card-bg);
  border-color: var(--fchub-border-color);
}

.builder-step:focus-visible {
  outline: 3px solid color-mix(in srgb, var(--el-color-primary) 25%, transparent);
  outline-offset: 2px;
}

.builder-step.is-active {
  color: var(--fchub-text-primary);
  background: var(--fchub-card-bg);
  border-color: color-mix(in srgb, var(--el-color-primary) 35%, var(--fchub-border-color));
  box-shadow: 0 5px 18px rgb(38 55 95 / 7%);
}

.builder-step-marker {
  display: grid;
  place-items: center;
  width: 30px;
  height: 30px;
  border: 1px solid var(--fchub-border-color);
  border-radius: 50%;
  color: var(--fchub-text-secondary);
  background: var(--fchub-card-bg);
  font-size: 12px;
  font-weight: 700;
}

.builder-step.is-active .builder-step-marker {
  color: #fff;
  border-color: var(--el-color-primary);
  background: var(--el-color-primary);
}

.builder-step.is-complete:not(.is-active) .builder-step-marker {
  color: var(--el-color-success);
  border-color: color-mix(in srgb, var(--el-color-success) 35%, var(--fchub-border-color));
  background: color-mix(in srgb, var(--el-color-success) 9%, var(--fchub-card-bg));
}

.builder-step-copy {
  min-width: 0;
  display: grid;
  gap: 2px;
}

.builder-step-eyebrow {
  display: none;
  color: var(--el-color-primary);
  font-size: 10px;
  font-weight: 800;
  letter-spacing: 0.09em;
}

.builder-step-copy strong {
  color: inherit;
  font-size: 13px;
  line-height: 1.35;
}

.builder-step-copy small {
  color: var(--fchub-text-secondary);
  font-size: 11px;
  line-height: 1.4;
}

@media (max-width: 782px) {
  .builder-progress {
    width: 100%;
    overflow-x: auto;
    padding: 2px 1px 8px;
    scroll-snap-type: x proximity;
    scrollbar-width: thin;
  }

  .builder-progress-list {
    display: flex;
    width: max-content;
    gap: 9px;
  }

  .builder-progress-item {
    width: 104px;
    flex: 0 0 104px;
    scroll-snap-align: start;
  }

  .builder-step {
    min-height: 84px;
    grid-template-columns: 1fr;
    gap: 4px;
    padding: 13px 14px;
    border-color: var(--fchub-border-color);
    background: var(--fchub-card-bg);
  }

  .builder-step-marker,
  .builder-step-copy small {
    display: none;
  }

  .builder-step-eyebrow {
    display: block;
  }

  .builder-step-copy strong {
    overflow-wrap: anywhere;
    font-size: 14px;
    line-height: 1.3;
  }

  .builder-step.is-active {
    border-color: var(--el-color-primary);
    box-shadow: inset 0 0 0 1px var(--el-color-primary), 0 6px 16px rgb(38 55 95 / 8%);
  }
}
</style>
