#!/usr/bin/env node
/**
 * Validate current-facing FluentCart MCP documentation routes and heading fragments.
 *
 * The public docs use flat MDX routes. Keeping this check source-based means a renamed page or
 * heading fails before deployment instead of becoming a surprisingly decorative 404.
 */

import { execFileSync } from 'node:child_process'
import { existsSync, readFileSync } from 'node:fs'
import { dirname, join, relative } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { currentFacingFiles } from './check-mcp-docs.mjs'

const REPO_ROOT = dirname(dirname(fileURLToPath(import.meta.url)))
const DOCS_PREFIX = '/docs/fluentcart-mcp'

function trackedFilesAt(repoRoot) {
	try {
		return execFileSync('git', ['ls-files'], { cwd: repoRoot, encoding: 'utf8' })
			.split('\n')
			.filter(Boolean)
	} catch {
		return []
	}
}

function withoutFencedCode(source) {
	let fence = null
	return source
		.split('\n')
		.map((line) => {
			const marker = line.match(/^\s*(`{3,}|~{3,})/)?.[1]
			if (marker && fence === null) {
				fence = { character: marker[0], length: marker.length }
				return ''
			}
			if (marker && marker[0] === fence?.character && marker.length >= fence.length) {
				fence = null
				return ''
			}
			return fence === null ? line : ''
		})
		.join('\n')
}

function headingText(markdown) {
	return markdown
		.replace(/<[^>]+>/g, '')
		.replace(/!\[([^\]]*)\]\([^)]*\)/g, '$1')
		.replace(/\[([^\]]+)\]\([^)]*\)/g, '$1')
		.replace(/[`*_~]/g, '')
		.trim()
}

function withoutInlineCode(source) {
	let output = ''
	let cursor = 0

	while (cursor < source.length) {
		if (source[cursor] !== '`') {
			output += source[cursor]
			cursor += 1
			continue
		}

		let markerLength = 1
		while (source[cursor + markerLength] === '`') markerLength += 1
		const marker = '`'.repeat(markerLength)
		const closing = source.indexOf(marker, cursor + markerLength)
		if (closing === -1) {
			output += marker
			cursor += markerLength
			continue
		}

		const end = closing + markerLength
		output += source
			.slice(cursor, end)
			.replace(/[^\n]/g, ' ')
		cursor = end
	}

	return output
}

function uniqueGithubAnchor(base, occurrences) {
	let anchor = base
	while (occurrences.has(anchor)) {
		occurrences.set(base, (occurrences.get(base) ?? 0) + 1)
		anchor = `${base}-${occurrences.get(base)}`
	}
	occurrences.set(anchor, 0)
	return anchor
}

/**
 * Fumadocs uses GitHub-style heading IDs. These docs use plain Latin headings, so the relevant
 * contract is lowercase text, punctuation removed, spaces changed to hyphens, and IDs made unique
 * with the same collision loop as github-slugger.
 */
export function headingAnchors(source) {
	const anchors = new Set()
	const occurrences = new Map()

	for (const line of withoutFencedCode(source).split('\n')) {
		const heading = line.match(/^\s{0,3}#{1,6}\s+(.+?)\s*#*\s*$/)?.[1]
		if (!heading) continue
		const base = headingText(heading)
			.toLowerCase()
			.replace(/[^\p{L}\p{M}\p{N}\s-]/gu, '')
			.replace(/ /g, '-')
		if (!base) continue
		anchors.add(uniqueGithubAnchor(base, occurrences))
	}

	return anchors
}

export function routeForDoc(relativePath) {
	const docsRoot = 'web-docs/content/docs/fluentcart-mcp/'
	if (!relativePath.startsWith(docsRoot) || !relativePath.endsWith('.mdx')) return null
	const page = relativePath.slice(docsRoot.length, -'.mdx'.length)
	if (page.startsWith('_') || page.includes('/_')) return null
	return page === 'index' ? DOCS_PREFIX : `${DOCS_PREFIX}/${page}`
}

export function docsRouteMap({
	repoRoot = REPO_ROOT,
	trackedFiles = trackedFilesAt(repoRoot),
	read = (path) => readFileSync(path, 'utf8'),
} = {}) {
	const routes = new Map()
	for (const relativePath of trackedFiles) {
		const route = routeForDoc(relativePath)
		if (!route) continue
		const absolutePath = join(repoRoot, relativePath)
		routes.set(route, {
			path: absolutePath,
			relativePath,
			anchors: headingAnchors(read(absolutePath)),
		})
	}
	return routes
}

export function documentationLinks(source) {
	const links = []
	const visibleSource = withoutInlineCode(withoutFencedCode(source))
	const patterns = [
		/!?\[(?:\\.|[^\]\\])*\]\(\s*<?([^)\s>]+)>?(?:\s+(?:"[^"]*"|'[^']*'|\([^)]*\)))?\s*\)/gs,
		/\bhref\s*=\s*["']([^"']+)["']/g,
	]

	for (const pattern of patterns) {
		for (const match of visibleSource.matchAll(pattern)) {
			links.push({
				offset: match.index,
				line: visibleSource.slice(0, match.index).split('\n').length,
				target: match[1],
			})
		}
	}

	return links
		.sort((left, right) => left.offset - right.offset)
		.map(({ line, target }) => ({ line, target }))
}

function internalMcpTarget(target) {
	let parsed
	try {
		parsed = new URL(target, 'https://fchub.co')
	} catch {
		return null
	}
	if (parsed.origin !== 'https://fchub.co' || !parsed.pathname.startsWith(DOCS_PREFIX)) return null
	return {
		route: parsed.pathname.replace(/\/+$/, '') || '/',
		fragment: decodeURIComponent(parsed.hash.slice(1)),
	}
}

export function checkMcpDocLinks({
	repoRoot = REPO_ROOT,
	trackedFiles = trackedFilesAt(repoRoot),
	files = currentFacingFiles({ repoRoot, trackedFiles, exists: existsSync }),
	read = (path) => readFileSync(path, 'utf8'),
} = {}) {
	const routes = docsRouteMap({ repoRoot, trackedFiles, read })
	const findings = []

	for (const path of files) {
		for (const link of documentationLinks(read(path))) {
			const target = internalMcpTarget(link.target)
			if (!target) continue
			const destination = routes.get(target.route)
			if (!destination) {
				findings.push({
					path,
					line: link.line,
					target: link.target,
					message: `missing FluentCart MCP route ${target.route}`,
				})
				continue
			}
			if (target.fragment && !destination.anchors.has(target.fragment)) {
				findings.push({
					path,
					line: link.line,
					target: link.target,
					message: `missing heading #${target.fragment} on ${target.route}`,
				})
			}
		}
	}

	return findings
}

if (process.argv[1] && pathToFileURL(process.argv[1]).href === import.meta.url) {
	const findings = checkMcpDocLinks()
	for (const finding of findings) {
		process.stdout.write(
			`${relative(REPO_ROOT, finding.path)}:${finding.line}: ${finding.message} (${finding.target})\n`,
		)
	}
	if (findings.length > 0) process.exit(1)
	process.stdout.write('All current-facing FluentCart MCP routes and heading fragments resolve.\n')
}
