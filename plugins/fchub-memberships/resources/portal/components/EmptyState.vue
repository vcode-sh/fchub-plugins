<template>
  <div class="fchub-empty">
    <div class="fchub-empty__icon">
      <svg width="40" height="40" viewBox="0 0 40 40" fill="none">
        <rect x="8" y="14" width="24" height="16" rx="2" stroke="currentColor" stroke-width="1.5" />
        <path d="M8 20H32" stroke="currentColor" stroke-width="1.5" />
        <circle cx="15" cy="25" r="2" stroke="currentColor" stroke-width="1.2" />
        <path d="M21 25H28" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" />
      </svg>
    </div>
    <p class="fchub-empty__text">No memberships yet</p>
    <p class="fchub-empty__subtext">
      Once you join a membership plan, your access details and content will appear here.
    </p>
  </div>
</template>

<style scoped>
.fchub-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 56px 24px;
  text-align: center;
}

.fchub-empty__icon {
  color: var(--portal-text-muted);
  margin-bottom: 16px;
  opacity: 0.6;
}

.fchub-empty__text {
  font-size: 15px;
  font-weight: 600;
  color: var(--portal-text-primary);
  margin-bottom: 4px;
}

.fchub-empty__subtext {
  font-size: 14px;
  color: var(--portal-text-muted);
  max-width: 320px;
  line-height: 1.5;
}
</style>
