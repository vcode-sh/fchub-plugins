import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import {
  buildPlanRulesPayload,
  hasReadOnlyPlanRules,
  isPlanRuleControlLocked,
} from '../../resources/admin/utils/planRulePayload.js'

const editorPath = resolve(process.cwd(), 'resources/admin/pages/Plans/PlanEditor.vue')
const editorSource = readFileSync(editorPath, 'utf8')

describe('plan resource capabilities', () => {
  it('offers the all-resources sentinel only to resource types that allow it', () => {
    expect(editorSource).toContain('<el-option v-if="getTypeConfig(rule.resource_type)?.allow_all" label="All of this type" value="0" />')
    expect(editorSource).toContain('allow_all: type.allow_all === true')
    expect(editorSource).toContain("rule.resource_id = getTypeConfig(rule.resource_type)?.allow_all ? '0' : ''")
  })

  it('validates nested external resource IDs as positive integers', () => {
    expect(editorSource).toContain(':rules="resourceIdRules(rule)"')
    expect(editorSource).toContain('function resourceIdRules(rule)')
    expect(editorSource).toContain("/^[1-9]\\d*$/.test(String(value ?? ''))")
  })

  it('normalises historical courses in the editor loader', () => {
    expect(editorSource).toContain("r.resource_type === 'sfwd-courses' ? 'ld_course' : r.resource_type")
    expect(editorSource).toContain('read_only: r.read_only === true')
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
