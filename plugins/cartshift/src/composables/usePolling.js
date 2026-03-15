import { onUnmounted } from 'vue';

export function usePolling() {
  let intervalId = null;

  function startPolling(callback, interval = 2000) {
    if (intervalId) return;
    intervalId = setInterval(callback, interval);
  }

  function stopPolling() {
    if (intervalId) {
      clearInterval(intervalId);
      intervalId = null;
    }
  }

  onUnmounted(() => {
    stopPolling();
  });

  return { startPolling, stopPolling };
}
