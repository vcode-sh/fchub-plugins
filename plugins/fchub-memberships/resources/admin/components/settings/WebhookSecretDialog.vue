<template>
  <div class="secret-dialog-backdrop" role="dialog" aria-modal="true" aria-labelledby="webhook-secret-title" @keydown.esc="emit('close')">
    <div class="secret-dialog">
      <span>Shown once</span>
      <h3 id="webhook-secret-title">Save the secret for {{ endpointName }}</h3>
      <p>Give this secret only to the receiver at this endpoint. Closing the dialog hides it permanently.</p>
      <code data-secret-value tabindex="0">{{ secret }}</code>
      <p v-if="copyError" class="copy-error" role="alert">Copy was blocked. Select the secret manually, then close this dialog.</p>
      <div class="secret-dialog-actions">
        <button type="button" class="dialog-button" data-copy-secret @click="copy">
          {{ copied ? 'Copied' : 'Copy secret' }}
        </button>
        <button type="button" class="dialog-button is-primary" data-close-secret-dialog @click="emit('close')">
          {{ copied ? 'Done' : 'Close' }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'

const props = defineProps({
  secret: { type: String, required: true },
  endpointName: { type: String, required: true },
})
const emit = defineEmits(['close'])
const copied = ref(false)
const copyError = ref(false)

async function copy() {
  try {
    await navigator.clipboard.writeText(props.secret)
    copied.value = true
    copyError.value = false
  } catch {
    copyError.value = true
  }
}
</script>

<style scoped>
.secret-dialog-backdrop { position: fixed; inset: 0; z-index: 100100; display: grid; place-items: center; padding: 18px; background: rgb(15 23 42 / 58%); }
.secret-dialog { display: grid; gap: 13px; width: min(100%, 520px); padding: 22px; border-radius: 12px; background: var(--fchub-card-bg, #fff); box-shadow: 0 24px 60px rgb(0 0 0 / 22%); }
.secret-dialog > span { color: var(--el-color-primary); font-size: 10px; font-weight: 750; letter-spacing: .08em; text-transform: uppercase; }
.secret-dialog h3, .secret-dialog p { margin: 0; }
.secret-dialog p { color: var(--fchub-text-secondary); font-size: 12px; line-height: 1.5; }
.secret-dialog code { padding: 12px; border: 1px solid var(--fchub-border-color); border-radius: 8px; background: var(--fchub-page-bg); overflow-wrap: anywhere; user-select: all; }
.secret-dialog .copy-error { color: var(--el-color-danger); }
.secret-dialog-actions { display: flex; justify-content: flex-end; gap: 8px; }
.dialog-button { min-height: 34px; padding: 6px 12px; border: 1px solid var(--fchub-border-color); border-radius: 7px; background: var(--fchub-card-bg); cursor: pointer; }
.dialog-button.is-primary { border-color: var(--el-color-primary); color: #fff; background: var(--el-color-primary); }
</style>
