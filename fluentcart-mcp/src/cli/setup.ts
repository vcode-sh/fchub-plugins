import { mkdirSync } from 'node:fs'
import { dirname } from 'node:path'
import { getConfigDir, getConfigPath } from '../config/types.js'

export async function runSetup(): Promise<void> {
	// @clack/prompts will be added as a dependency when setup is fleshed out.
	// For now, a placeholder that explains what's needed.
	console.log(`
┌  FluentCart MCP Server Setup
│
◆  Not yet implemented — coming soon.
│
│  For now, configure via environment variables:
│
│    export FLUENTCART_URL="https://your-site.com"
│    export FLUENTCART_USERNAME="admin"
│    export FLUENTCART_APP_PASSWORD="xxxx xxxx xxxx xxxx"
│
│  Or create a config file manually:
│    ${getConfigPath()}
│
│  {
│    "url": "https://your-site.com",
│    "username": "admin",
│    "appPassword": "xxxx xxxx xxxx xxxx"
│  }
│
└  Generate an Application Password at:
   WordPress Admin → Users → Your Profile → Application Passwords
`)

	// Ensure config directory exists for when interactive setup is added
	mkdirSync(dirname(getConfigDir()), { recursive: true })
}
