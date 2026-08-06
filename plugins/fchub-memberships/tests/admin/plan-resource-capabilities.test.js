import { describe, expect, it } from 'vitest'
import {
  buildPlanRulesPayload,
  hasReadOnlyPlanRules,
  isPlanRuleControlLocked,
} from '../../resources/admin/utils/planRulePayload.js'
import { normalisePlanForm } from '../../resources/admin/pages/Plans/planEditorForm.js'

describe('plan resource capabilities', () => {
  it('normalises historical courses in the editor loader', () => {
    const form = normalisePlanForm({
      rules: [{
        resource_type: 'sfwd-courses',
        resource_id: 41,
        read_only: true,
      }],
    })

    expect(form.rules[0]).toMatchObject({
      resource_type: 'ld_course',
      resource_id: '41',
      read_only: true,
    })
  })

  it('locks the complete rule set and omits it from the payload when a legacy lesson is present', () => {
    const legacyLesson = {
      resource_type: 'sfwd-lessons',
      resource_id: '51',
      read_only: true,
      drip_type: 'immediate',
    }
    const editableCourse = {
      resource_type: 'ld_course',
      resource_id: '41',
      read_only: false,
      drip_type: 'delayed',
      drip_delay_days: 2,
    }
    const rules = [legacyLesson, editableCourse]

    expect(hasReadOnlyPlanRules(rules)).toBe(true)
    expect(isPlanRuleControlLocked(rules, legacyLesson)).toBe(true)
    expect(isPlanRuleControlLocked(rules, editableCourse)).toBe(true)
    expect(buildPlanRulesPayload(rules)).toBeUndefined()
  })

  it('serialises editable rule sets without presentation fields', () => {
    const rules = [{
      resource_type: 'ld_course',
      resource_id: '41',
      resource_label: 'Course 41',
      read_only: false,
      drip_type: 'fixed_date',
      drip_date: '2026-08-01',
    }]

    expect(hasReadOnlyPlanRules(rules)).toBe(false)
    expect(isPlanRuleControlLocked(rules, rules[0])).toBe(false)
    expect(buildPlanRulesPayload(rules)).toEqual([{
      resource_type: 'ld_course',
      resource_id: '41',
      drip_type: 'fixed_date',
      drip_date: '2026-08-01',
    }])
  })
})
