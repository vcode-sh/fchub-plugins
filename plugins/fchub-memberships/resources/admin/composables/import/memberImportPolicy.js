export function buildLevelMappings(levels = [], mappings = {}) {
  return levels.map((level) => {
    const mapping = mappings[level.name] || {}
    const action = mapping.action === 'create'
      ? 'create_new'
      : mapping.action === 'map'
        ? 'map_existing'
        : 'skip'

    return {
      level_name: level.name,
      action,
      title: mapping.title,
      duration_type: mapping.duration_type,
      duration_days: mapping.duration_days,
      plan_id: mapping.plan_id,
    }
  })
}

export function membersForImport(members = [], levelMappings = []) {
  const skippedLevels = new Set(
    levelMappings.filter((mapping) => mapping.action === 'skip').map((mapping) => mapping.level_name)
  )

  return members.filter((member) => !skippedLevels.has(member.level_name))
}

export function importPreview(levels = [], mappings = {}, existingPlans = []) {
  const breakdown = levels.map((level) => {
    const mapping = mappings[level.name]
    let targetPlan = '-'

    if (mapping?.action === 'create') {
      targetPlan = mapping.title || level.name
    } else if (mapping?.action === 'map') {
      const plan = existingPlans.find((candidate) => candidate.id === mapping.plan_id)
      targetPlan = plan?.title || `Plan #${mapping.plan_id}`
    }

    return {
      level: level.name,
      action: mapping?.action || 'skip',
      target_plan: targetPlan,
      member_count: level.count,
      expiry_type: level.has_expiry ? 'Dated' : 'Lifetime',
    }
  })

  return {
    membersToImport: levels
      .filter((level) => mappings[level.name]?.action !== 'skip')
      .reduce((total, level) => total + level.count, 0),
    plansToCreate: levels.filter((level) => mappings[level.name]?.action === 'create').length,
    levelsSkipped: levels.filter((level) => mappings[level.name]?.action === 'skip').length,
    breakdown,
  }
}

export function allLevelsMapped(levels = [], mappings = {}) {
  return levels.every((level) => {
    const mapping = mappings[level.name]
    if (!mapping) return false
    if (mapping.action === 'skip') return true
    if (mapping.action === 'create') return Boolean(mapping.title)
    if (mapping.action === 'map') return Boolean(mapping.plan_id)
    return false
  })
}

export function groupImportResults(results = []) {
  const grouped = { imported: [], skipped: [], extended: [], failed: [] }

  for (const result of results) {
    const status = result.status || 'failed'
    if (grouped[status]) {
      grouped[status].push(result)
    } else {
      grouped.failed.push(result)
    }
  }

  return grouped
}

export function buildImportReportCsv(results = []) {
  const headers = ['Email', 'Plan', 'Status', 'Message']
  const rows = results.map((result) => [
    result.email || '',
    result.plan || '',
    result.status || '',
    result.message || '',
  ])

  return [
    headers.join(','),
    ...rows.map((row) => row.map((cell) => `"${String(cell).replace(/"/g, '""')}"`).join(',')),
  ].join('\n')
}
