import { existsSync, mkdirSync, writeFileSync } from 'node:fs'
import { dirname } from 'node:path'
import * as p from '@clack/prompts'
import { testConnection } from '../api/test-connection.js'
import { getConfigPath } from '../config/types.js'

export async function runSetup(): Promise<void> {
	p.intro('fluentcart-mcp setup')

	const url = await p.text({
		message: 'WordPress URL',
		placeholder: 'https://your-store.com',
		validate: (value) => {
			if (!value) return 'URL is required'
			try {
				new URL(value)
			} catch {
				return 'Enter a valid URL (e.g. https://your-store.com)'
			}
		},
	})
	if (p.isCancel(url)) {
		p.cancel('Setup cancelled.')
		process.exit(0)
	}

	const username = await p.text({
		message: 'WordPress username',
		placeholder: 'admin',
		validate: (value) => {
			if (!value) return 'Username is required'
		},
	})
	if (p.isCancel(username)) {
		p.cancel('Setup cancelled.')
		process.exit(0)
	}

	const appPassword = await p.password({
		message: 'Application Password',
		validate: (value) => {
			if (!value) return 'Application Password is required'
		},
	})
	if (p.isCancel(appPassword)) {
		p.cancel('Setup cancelled.')
		process.exit(0)
	}

	const cleanUrl = url.replace(/\/+$/, '')

	const s = p.spinner()
	s.start('Testing connection...')

	const result = await testConnection(cleanUrl, username, appPassword)

	if (!result.ok) {
		s.stop('Connection failed.')
		p.log.error(`${result.error.code}: ${result.error.message}`)

		const retry = await p.confirm({ message: 'Try again?' })
		if (p.isCancel(retry) || !retry) {
			p.cancel('Setup cancelled.')
			process.exit(0)
		}
		return runSetup()
	}

	s.stop(`Connected to ${result.storeName}`)

	const configPath = getConfigPath()
	const configDir = dirname(configPath)
	if (!existsSync(configDir)) {
		mkdirSync(configDir, { recursive: true })
	}

	const config = {
		url: cleanUrl,
		username,
		appPassword,
	}

	writeFileSync(configPath, JSON.stringify(config, null, 2), 'utf-8')
	p.log.success(`Config written to ${configPath}`)

	p.outro("You're all set. Run fluentcart-mcp to start the server.")
}
