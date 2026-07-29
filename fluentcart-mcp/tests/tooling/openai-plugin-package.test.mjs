import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { existsSync, readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { describe, it } from 'node:test'
import { fileURLToPath } from 'node:url'
import { digestInputPaths } from '../../scripts/release-contract-inputs.mjs'

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..', '..')
const packageRoot = resolve(repoRoot, 'fluentcart-mcp')
const pluginRoot = resolve(packageRoot, 'openai-plugin')
const packageJson = readJson(resolve(packageRoot, 'package.json'))

function readJson(path) {
	assert.ok(existsSync(path), `required package file is missing: ${path}`)
	return JSON.parse(readFileSync(path, 'utf8'))
}

describe('OpenAI plugin package', () => {
	it('installs the exact FluentCart MCP release without credentials or lifecycle hooks', () => {
		const pluginManifest = readJson(resolve(pluginRoot, '.codex-plugin/plugin.json'))
		const serverManifest = readJson(resolve(pluginRoot, '.mcp.json'))
		const marketplace = readJson(resolve(repoRoot, '.agents/plugins/marketplace.json'))

		assert.deepEqual(pluginManifest, {
			name: 'fluentcart-mcp',
			version: packageJson.version,
			mcpServers: './.mcp.json',
		})
		assert.equal(existsSync(resolve(pluginRoot, '.app.json')), false)
		assert.deepEqual(serverManifest, {
			fluentcart: {
				command: 'npx',
				args: ['-y', `fluentcart-mcp@${packageJson.version}`],
			},
		})
		assert.deepEqual(marketplace, {
			name: 'fchub-plugins',
			plugins: [
				{
					name: 'fluentcart-mcp',
					source: {
						source: 'git-subdir',
						url: 'https://github.com/vcode-sh/fchub-plugins.git',
						path: './fluentcart-mcp/openai-plugin',
						ref: 'main',
					},
					policy: {
						installation: 'AVAILABLE',
						authentication: 'ON_INSTALL',
					},
					category: 'Productivity',
				},
			],
		})
	})

	it('ships the plugin manifests in the npm archive and release source digest', () => {
		const packed = JSON.parse(
			execFileSync('npm', ['pack', '--dry-run', '--json', '--ignore-scripts'], {
				cwd: packageRoot,
				encoding: 'utf8',
			}),
		)
		const archiveFiles = packed[0].files.map(({ path }) => path)

		assert.ok(archiveFiles.includes('openai-plugin/.codex-plugin/plugin.json'))
		assert.ok(archiveFiles.includes('openai-plugin/.mcp.json'))
		assert.ok(digestInputPaths().includes('openai-plugin/.codex-plugin/plugin.json'))
		assert.ok(digestInputPaths().includes('openai-plugin/.mcp.json'))
	})
})
