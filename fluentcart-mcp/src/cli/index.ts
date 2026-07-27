import {
	formatGuardStateReport,
	inspectGuardState,
	type ResolutionOutcome,
	resolveGuardClaim,
} from './guard-state.js'

const GUARD_STATE_USAGE = `Usage:
  fluentcart-mcp guard-state inspect
  fluentcart-mcp guard-state resolve \\
    --entity-hash <sha256> \\
    --claim-hash <sha256> \\
    --outcome confirmed-completed|confirmed-not-executed \\
    --evidence-reference <non-secret operator reference>

Reads FLUENTCART_GUARD_STATE_DIR. Run with the server stopped.`

const OUTCOMES: ResolutionOutcome[] = ['confirmed-completed', 'confirmed-not-executed']

function parseFlags(args: string[]): Record<string, string> {
	const flags: Record<string, string> = {}
	for (let index = 0; index < args.length; index += 1) {
		const arg = args[index]
		if (arg === undefined || !arg.startsWith('--')) continue
		const next = args[index + 1]
		const hasValue = next !== undefined && !next.startsWith('--')
		// A flag with no value becomes an empty string, which the validators reject. Nothing is
		// inferred: an operator who forgets --evidence-reference must be told, not guessed at.
		flags[arg.slice(2)] = hasValue ? next : ''
		if (hasValue) index += 1
	}
	return flags
}

function requireStateDir(): string {
	const stateDir = process.env.FLUENTCART_GUARD_STATE_DIR
	if (!stateDir) {
		throw new Error(
			'FLUENTCART_GUARD_STATE_DIR is not set. guard-state operates on the directory the guarded server was configured with.',
		)
	}
	return stateDir
}

async function runGuardState(args: string[]): Promise<void> {
	const action = args[0]

	if (action === 'inspect') {
		process.stdout.write(formatGuardStateReport(await inspectGuardState(requireStateDir())))
		return
	}

	if (action === 'resolve') {
		const flags = parseFlags(args.slice(1))
		const outcome = flags.outcome as ResolutionOutcome | undefined
		if (outcome === undefined || !OUTCOMES.includes(outcome)) {
			throw new Error(`--outcome must be one of: ${OUTCOMES.join(', ')}`)
		}

		const claim = await resolveGuardClaim({
			stateDir: requireStateDir(),
			entityHash: flags['entity-hash'] ?? '',
			claimHash: flags['claim-hash'] ?? '',
			outcome,
			evidenceReference: flags['evidence-reference'] ?? '',
			// Only a local shell reaches this branch; no transport can set it.
			invocation: 'local-cli',
		})

		process.stdout.write(
			`resolved ${claim.claimHash} as ${claim.outcome ?? outcome}; the entity lock is released.\n`,
		)
		return
	}

	throw new Error(GUARD_STATE_USAGE)
}

export async function runCli(args: string[]): Promise<void> {
	const command = args[0]

	switch (command) {
		case 'setup': {
			const { runSetup } = await import('./setup.js')
			await runSetup()
			break
		}
		case 'guard-state': {
			try {
				await runGuardState(args.slice(1))
			} catch (error) {
				console.error(error instanceof Error ? error.message : String(error))
				process.exit(1)
			}
			break
		}
		default:
			console.error(`Unknown command: ${command}`)
			console.error('Run fluentcart-mcp --help for usage.')
			process.exit(1)
	}
}
