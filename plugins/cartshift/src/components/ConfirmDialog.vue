<template>
  <div
    v-if="open"
    class="cartshift-modal-backdrop"
    @click.self="cancel"
  >
    <div
      ref="dialogEl"
      :class="['cartshift-modal', { 'cartshift-modal-danger': tone === 'danger' }]"
      role="alertdialog"
      aria-modal="true"
      :aria-labelledby="titleId"
      :aria-describedby="bodyId"
      tabindex="-1"
      @keydown.esc.prevent.stop="cancel"
      @keydown.tab="trapFocus"
    >
      <h2 :id="titleId" class="cartshift-modal-title">{{ title }}</h2>

      <div :id="bodyId" class="cartshift-modal-body">
        <slot />
      </div>

      <div class="cartshift-modal-actions">
        <button ref="cancelEl" class="button" @click="cancel">
          {{ cancelLabel }}
        </button>
        <button
          :class="['button', tone === 'danger' ? 'button-link-delete' : 'button-primary']"
          :disabled="confirmDisabled"
          @click="confirm"
        >
          {{ confirmLabel }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, watch, nextTick, onBeforeUnmount } from 'vue';

let uid = 0;

const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, required: true },
  confirmLabel: { type: String, default: 'Confirm' },
  cancelLabel: { type: String, default: 'Cancel' },
  confirmDisabled: { type: Boolean, default: false },
  // 'danger' colours the confirm button as destructive.
  tone: { type: String, default: 'default' },
});

const emit = defineEmits(['confirm', 'cancel']);

uid += 1;
const titleId = `cartshift-modal-title-${uid}`;
const bodyId = `cartshift-modal-body-${uid}`;

const dialogEl = ref(null);
const cancelEl = ref(null);

// Where focus was before the dialog stole it, so it can be handed back.
let previouslyFocused = null;

/**
 * Keep Tab inside the dialog. A modal that lets focus wander off behind the
 * backdrop is a modal in name only.
 */
function trapFocus(event) {
  const root = dialogEl.value;
  if (!root) return;

  const focusable = root.querySelectorAll(
    'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
  );

  if (focusable.length === 0) return;

  const first = focusable[0];
  const last = focusable[focusable.length - 1];

  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault();
    last.focus();
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault();
    first.focus();
  }
}

function restoreFocus() {
  if (previouslyFocused && typeof previouslyFocused.focus === 'function') {
    previouslyFocused.focus();
  }
  previouslyFocused = null;
}

function confirm() {
  emit('confirm');
}

function cancel() {
  emit('cancel');
}

watch(
  () => props.open,
  async (isOpen) => {
    if (isOpen) {
      previouslyFocused = document.activeElement;
      await nextTick();
      // Land on Cancel, not on the button that deletes things.
      (cancelEl.value || dialogEl.value)?.focus();
    } else {
      restoreFocus();
    }
  }
);

onBeforeUnmount(() => {
  previouslyFocused = null;
});
</script>
