export function hasReadOnlyPlanRules(rules) {
  return rules.some((rule) => rule.read_only === true)
}

export function isPlanRuleControlLocked(rules) {
  return hasReadOnlyPlanRules(rules)
}

export function buildPlanRulesPayload(rules) {
  if (hasReadOnlyPlanRules(rules)) {
    return undefined
  }

  return rules.map((item) => {
    const rule = {
      resource_type: item.resource_type,
      resource_id: item.resource_id,
      drip_type: item.drip_type,
    }
    if (item.drip_type === 'delayed') {
      rule.drip_delay_days = item.drip_delay_days
    }
    if (item.drip_type === 'fixed_date') {
      rule.drip_date = item.drip_date
    }
    return rule
  })
}
