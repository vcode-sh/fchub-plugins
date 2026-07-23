<template>
  <section class="fchub-community" aria-label="Community access">
    <header class="fchub-community__header">
      <div>
        <p class="fchub-community__eyebrow">FluentCommunity</p>
        <h2>Community access</h2>
      </div>
      <span
        v-if="showVerification"
        class="fchub-community__verification"
        :class="{ 'is-verified': community.profile.is_verified }"
      >
        {{ community.profile.is_verified ? 'Verified profile' : 'Profile not verified' }}
      </span>
    </header>

    <p v-if="pendingCount" class="fchub-community__pending" role="status">
      {{ pendingCount }} {{ pendingCount === 1 ? 'access update needs' : 'access updates need' }} attention
    </p>

    <div v-if="hasCoreAccess" class="fchub-community__grid">
      <section v-if="spaces.length" class="fchub-community__group" aria-labelledby="community-spaces-heading">
        <h3 id="community-spaces-heading">Spaces</h3>
        <ul>
          <li v-for="space in spaces" :key="`space-${space.id}`">
            <strong>{{ space.title }}</strong>
            <span>{{ planLabel(space.plan_ids) }}</span>
          </li>
        </ul>
      </section>

      <section v-if="courses.length" class="fchub-community__group" aria-labelledby="community-courses-heading">
        <h3 id="community-courses-heading">Courses</h3>
        <ul>
          <li v-for="course in courses" :key="`course-${course.id}`">
            <div class="fchub-community__course-heading">
              <strong>{{ course.title }}</strong>
              <span v-if="hasProgress(course.progress)">{{ progress(course.progress) }}%</span>
              <span v-else>Access being prepared</span>
            </div>
            <progress v-if="hasProgress(course.progress)" :value="progress(course.progress)" max="100">
              {{ progress(course.progress) }}%
            </progress>
            <span>{{ planLabel(course.plan_ids) }}</span>
          </li>
        </ul>
      </section>
    </div>

    <p v-else class="fchub-community__empty">No Community enrolments yet.</p>

    <div v-if="showProContext" class="fchub-community__pro">
      <div v-if="showBadges">
        <span>Badges</span>
        <strong>{{ community.profile.badges.length }}</strong>
      </div>
      <div v-if="showPoints">
        <span>Points</span>
        <strong>{{ community.profile.total_points }}</strong>
      </div>
      <div v-if="showLevel">
        <span>Level</span>
        <strong>{{ community.profile.level.title }}</strong>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  community: { type: Object, required: true },
})

const spaces = computed(() => Array.isArray(props.community.spaces) ? props.community.spaces : [])
const courses = computed(() => Array.isArray(props.community.courses) ? props.community.courses : [])
const pendingCount = computed(() => Math.max(0, Number(props.community.pending_access_count) || 0))
const hasCoreAccess = computed(() => spaces.value.length > 0 || courses.value.length > 0)
const showVerification = computed(() => (
  props.community.profile !== null && capabilityAvailable('profile_verification_read')
))
const showBadges = computed(() => (
  capabilityAvailable('badges') && Array.isArray(props.community.profile?.badges)
))
const showPoints = computed(() => (
  capabilityAvailable('points')
  && props.community.profile?.total_points !== null
  && props.community.profile?.total_points !== undefined
  && Number.isFinite(Number(props.community.profile.total_points))
))
const showLevel = computed(() => (
  capabilityAvailable('leaderboard_levels') && Boolean(props.community.profile?.level?.title)
))
const showProContext = computed(() => showBadges.value || showPoints.value || showLevel.value)

function capabilityAvailable(key) {
  const capability = props.community.capabilities?.[key]
  return capability === 'available'
    || capability?.status === 'available'
}

function progress(value) {
  return Math.max(0, Math.min(100, Math.round(Number(value) || 0)))
}

function hasProgress(value) {
  return value !== null && value !== '' && Number.isFinite(Number(value))
}

function planLabel(planIds) {
  const count = Array.isArray(planIds) ? planIds.length : 0
  if (count === 0) return 'Community access'
  return count === 1 ? 'Included by 1 plan' : `Included by ${count} plans`
}
</script>

<style scoped>
.fchub-community {
  padding: 22px;
  border: 1px solid var(--portal-border);
  border-radius: var(--portal-radius);
  background: var(--portal-card-bg);
}

.fchub-community__header,
.fchub-community__course-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.fchub-community__eyebrow {
  margin: 0 0 3px;
  color: var(--portal-text-muted);
  font-size: 11px;
  font-weight: 600;
  letter-spacing: .05em;
  text-transform: uppercase;
}

.fchub-community h2,
.fchub-community h3,
.fchub-community p {
  margin-top: 0;
}

.fchub-community h2 {
  margin-bottom: 0;
  font-size: 17px;
}

.fchub-community h3 {
  margin-bottom: 10px;
  font-size: 13px;
}

.fchub-community__verification {
  padding: 4px 8px;
  border-radius: var(--portal-radius-sm);
  background: var(--portal-badge-revoked-bg);
  color: var(--portal-badge-revoked-text);
  font-size: 11px;
  font-weight: 600;
}

.fchub-community__verification.is-verified {
  background: var(--portal-badge-active-bg);
  color: var(--portal-badge-active-text);
}

.fchub-community__pending {
  margin: 14px 0 0;
  padding: 9px 11px;
  border-radius: var(--portal-radius-sm);
  background: var(--portal-badge-paused-bg);
  color: var(--portal-badge-paused-text);
  font-size: 12px;
  font-weight: 600;
}

.fchub-community__grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 18px;
  margin-top: 18px;
}

.fchub-community__group {
  min-width: 0;
}

.fchub-community__group ul {
  display: grid;
  gap: 8px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.fchub-community__group li {
  display: flex;
  min-width: 0;
  flex-direction: column;
  gap: 4px;
  padding: 10px;
  border-radius: var(--portal-radius-sm);
  background: var(--portal-hover-bg);
}

.fchub-community__group strong {
  overflow-wrap: anywhere;
}

.fchub-community__group li > span,
.fchub-community__course-heading span {
  color: var(--portal-text-muted);
  font-size: 11px;
}

.fchub-community progress {
  width: 100%;
  height: 6px;
  accent-color: var(--portal-accent-blue);
}

.fchub-community__empty {
  margin: 16px 0 0;
  color: var(--portal-text-muted);
  font-size: 13px;
}

.fchub-community__pro {
  display: flex;
  gap: 12px;
  margin-top: 16px;
}

.fchub-community__pro div {
  display: flex;
  flex-direction: column;
}

@media (max-width: 640px) {
  .fchub-community {
    padding: 16px;
  }

  .fchub-community__header {
    align-items: flex-start;
    flex-direction: column;
  }

  .fchub-community__grid {
    grid-template-columns: 1fr;
  }
}
</style>
