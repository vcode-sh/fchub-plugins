export async function runCli(args: string[]): Promise<void> {
	const command = args[0]

	if (command === 'setup') {
		const { runSetup } = await import('./setup.js')
		await runSetup()
		return
	}

	console.error(`Unknown command: ${command}`)
	console.error('Run fluentcart-mcp --help for usage.')
	process.exit(1)
}
