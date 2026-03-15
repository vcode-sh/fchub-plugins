<template>
  <div class="fchub-loading">
    <div v-for="n in 2" :key="n" class="fchub-skeleton-card">
      <div class="fchub-skeleton-card__inner">
        <!-- Header -->
        <div class="fchub-skeleton-card__header">
          <div class="fchub-skeleton fchub-skeleton--title"></div>
          <div class="fchub-skeleton fchub-skeleton--badge"></div>
        </div>

        <!-- Description -->
        <div class="fchub-skeleton fchub-skeleton--desc"></div>

        <!-- Stats row -->
        <div class="fchub-skeleton-stats">
          <div v-for="s in 3" :key="s" class="fchub-skeleton-stat">
            <div class="fchub-skeleton fchub-skeleton--stat-label"></div>
            <div class="fchub-skeleton fchub-skeleton--stat-value"></div>
          </div>
        </div>

        <!-- Progress bar -->
        <div class="fchub-skeleton fchub-skeleton--progress"></div>

        <!-- Content rows -->
        <div class="fchub-skeleton-card__divider"></div>
        <div class="fchub-skeleton-card__rows">
          <div v-for="r in 3" :key="r" class="fchub-skeleton-row">
            <div class="fchub-skeleton fchub-skeleton--dot"></div>
            <div class="fchub-skeleton fchub-skeleton--row-text" :style="{ width: rowWidths[r - 1] }"></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
const rowWidths = ['75%', '60%', '45%']
</script>

<style scoped>
.fchub-loading {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.fchub-skeleton-card {
  background: var(--portal-card-bg);
  border: 1px solid var(--portal-border);
  border-radius: var(--portal-radius);
  overflow: hidden;
}

.fchub-skeleton-card__inner {
  padding: 24px;
}

.fchub-skeleton {
  border-radius: 4px;
  background: linear-gradient(
    90deg,
    var(--portal-skeleton-base) 0%,
    var(--portal-skeleton-shine) 40%,
    var(--portal-skeleton-base) 80%
  );
  background-size: 300% 100%;
  animation: fchub-shimmer 1.8s ease-in-out infinite;
}

.fchub-skeleton-card__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 8px;
}

.fchub-skeleton--title {
  height: 18px;
  width: 55%;
}

.fchub-skeleton--badge {
  height: 22px;
  width: 60px;
  border-radius: var(--portal-radius-sm);
}

.fchub-skeleton--desc {
  height: 13px;
  width: 40%;
  margin-bottom: 16px;
}

/* Stats */
.fchub-skeleton-stats {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 12px 0;
  border-bottom: 1px solid var(--portal-border);
  margin-bottom: 16px;
}

.fchub-skeleton-stat {
  display: flex;
  flex-direction: column;
  gap: 4px;
  flex: 1;
}

.fchub-skeleton--stat-label {
  height: 10px;
  width: 50%;
}

.fchub-skeleton--stat-value {
  height: 14px;
  width: 70%;
}

/* Progress */
.fchub-skeleton--progress {
  height: 4px;
  width: 100%;
  border-radius: 2px;
  margin-bottom: 16px;
}

/* Content rows */
.fchub-skeleton-card__divider {
  height: 1px;
  background: var(--portal-border);
  margin-bottom: 12px;
}

.fchub-skeleton-card__rows {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.fchub-skeleton-row {
  display: flex;
  align-items: center;
  gap: 10px;
}

.fchub-skeleton--dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
}

.fchub-skeleton--row-text {
  height: 14px;
  border-radius: 4px;
}

@media (max-width: 480px) {
  .fchub-skeleton-card__inner {
    padding: 16px;
  }

  .fchub-skeleton-stats {
    flex-direction: column;
    gap: 8px;
  }
}
</style>
