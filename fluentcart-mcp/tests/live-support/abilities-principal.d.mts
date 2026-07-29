export function buildLiveChildEnvironment(
	fileValues: Record<string, string | undefined>,
	ambient?: NodeJS.ProcessEnv,
): NodeJS.ProcessEnv

export function provisionAbilitiesPrincipal(options: {
	run?: (args: string[]) => Promise<string>
	runId: string
}): Promise<{
	principal: { username: string; password: string }
	cleanup(): Promise<void>
}>

export function runAbilitiesLauncher(options: {
	childEnv: NodeJS.ProcessEnv
	target: URL
	runId: string
	run?: (args: string[]) => Promise<string>
	provision?: (options: { run: (args: string[]) => Promise<string>; runId: string }) => Promise<{
		principal: { username: string; password: string }
		cleanup(): Promise<void>
	}>
	runTests: (childEnv: NodeJS.ProcessEnv) => Promise<number>
}): Promise<number>
