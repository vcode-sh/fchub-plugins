/**
 * Reads all tool definitions and outputs the tools array as JSON.
 * Usage: npx tsx scripts/generate-manifest-tools.ts
 *
 * Outputs a JSON array of { name, description } objects suitable for
 * pasting into manifest.json under the "tools" key.
 */

import { createClient } from '../src/api/client.js'
import { resolveApiUrls } from '../src/config/types.js'
import { createAllTools } from '../src/tools/index.js'

// Dummy client -- tools are only inspected for metadata, never called
const config = resolveApiUrls({
	url: 'https://placeholder.invalid',
	username: 'unused',
	appPassword: 'unused',
})

const client = createClient(config)
const tools = createAllTools(client).map((t) => ({
	name: t.name,
	description: t.description,
}))

process.stdout.write(JSON.stringify(tools, null, 2) + '\n')
