import assert from 'node:assert/strict'
import { readdirSync, readFileSync } from 'node:fs'
import { dirname, join, relative, resolve } from 'node:path'
import { describe, it } from 'node:test'
import { fileURLToPath } from 'node:url'
import ts from 'typescript'

const PACKAGE_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..')
const INTEGRATION_ROOT = join(PACKAGE_ROOT, 'tests', 'integration')

function testRoot(expression) {
	if (ts.isIdentifier(expression)) return expression.text
	if (ts.isPropertyAccessExpression(expression)) return testRoot(expression.expression)
	if (ts.isCallExpression(expression)) return testRoot(expression.expression)
	return null
}

function testCallback(node) {
	if (!(ts.isCallExpression(node) && ['it', 'test'].includes(testRoot(node.expression))))
		return null
	return node.arguments.find(
		(argument) => ts.isArrowFunction(argument) || ts.isFunctionExpression(argument),
	)
}

function silentTestReturns(source, fileName = 'fixture.test.ts') {
	const sourceFile = ts.createSourceFile(
		fileName,
		source,
		ts.ScriptTarget.Latest,
		true,
		ts.ScriptKind.TS,
	)
	const findings = []

	function inspectCallback(callback) {
		function visit(node) {
			if (node !== callback && (ts.isArrowFunction(node) || ts.isFunctionExpression(node))) return
			if (ts.isReturnStatement(node) && node.expression === undefined) {
				const line = sourceFile.getLineAndCharacterOfPosition(node.getStart(sourceFile)).line + 1
				findings.push(`${fileName}:${line}`)
			}
			ts.forEachChild(node, visit)
		}
		visit(callback)
	}

	function visit(node) {
		const callback = testCallback(node)
		if (callback) inspectCallback(callback)
		ts.forEachChild(node, visit)
	}
	visit(sourceFile)
	return findings
}

function integrationFiles(directory) {
	return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
		const path = join(directory, entry.name)
		if (entry.isDirectory()) return integrationFiles(path)
		return entry.isFile() && entry.name.endsWith('.test.ts') ? [path] : []
	})
}

describe('live test honesty', () => {
	it('detects a silent prerequisite return in a test but ignores a nested helper', () => {
		const source = `
			it('lies about running', async () => {
				if (!fixtureId) return
			})
			it('uses an explicit skip', async ({ skip }) => {
				if (!fixtureId) skip('fixture unavailable')
				const helper = () => { return }
			})
		`
		assert.deepEqual(silentTestReturns(source), ['fixture.test.ts:3'])
	})

	it('reports every unavailable live prerequisite as skipped or blocked', () => {
		const findings = integrationFiles(INTEGRATION_ROOT).flatMap((path) =>
			silentTestReturns(readFileSync(path, 'utf8'), relative(PACKAGE_ROOT, path)),
		)
		assert.deepEqual(
			findings,
			[],
			`bare return marks a live test passed without exercising it:\n${findings.join('\n')}`,
		)
	})
})
