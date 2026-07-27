const test = require('node:test')

const {
  lifecyclePreservationSpec,
} = require('./run-lifecycle-preservation.cjs')

test(
  '1.4.0 to 1.4.1 preserves representative membership state without schema drift',
  {
    skip: process.env.WPORG_LIFECYCLE_RUN !== '1',
    timeout: 180_000,
  },
  lifecyclePreservationSpec({
    slug: 'fchub-memberships',
    candidateName: 'fchub-memberships-1.4.1.zip',
    previousName: 'fchub-memberships-1.4.0.zip',
    preparedMarker: 'Prepared Memberships migration preservation fixture\\.',
    verifiedMarker: 'Verified Memberships migration preservation fixture\\.',
  }),
)
