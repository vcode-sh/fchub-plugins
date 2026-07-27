const test = require('node:test')

const {
  lifecyclePreservationSpec,
} = require('./run-lifecycle-preservation.cjs')

test(
  '1.4.0 to 1.4.1 preserves rates, events, settings, and customer selection',
  {
    skip: process.env.WPORG_LIFECYCLE_RUN !== '1',
    timeout: 180_000,
  },
  lifecyclePreservationSpec({
    slug: 'fchub-multi-currency',
    candidateName: 'fchub-multi-currency-1.4.1.zip',
    previousName: 'fchub-multi-currency-1.4.0.zip',
    preparedMarker: 'Prepared Multi-Currency migration preservation fixture\\.',
    verifiedMarker: 'Verified Multi-Currency migration preservation fixture\\.',
  }),
)
