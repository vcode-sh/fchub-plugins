import assert from 'node:assert/strict'

function processGroupExists(processGroupId) {
	try {
		process.kill(-processGroupId, 0)
		return true
	} catch (error) {
		if (error.code === 'ESRCH' || error.code === 'EPERM') return false
		throw error
	}
}

async function waitForProcessGroupExit(processGroupId, timeoutMs) {
	const deadline = Date.now() + timeoutMs
	while (Date.now() < deadline) {
		if (!processGroupExists(processGroupId)) return true
		await new Promise((resolve) => setTimeout(resolve, 10))
	}
	return !processGroupExists(processGroupId)
}

async function waitForLeaderExit(child, timeoutMs) {
	if (child.exitCode !== null || child.signalCode !== null) return true
	return Promise.race([
		new Promise((resolve) => child.once('close', () => resolve(true))),
		new Promise((resolve) => setTimeout(() => resolve(false), timeoutMs)),
	])
}

export async function stopClientProcess(child, options = {}) {
	if (!child?.pid || !processGroupExists(child.pid)) return
	const graceMs = options.graceMs ?? 2_000
	try {
		process.kill(-child.pid, 'SIGTERM')
	} catch (error) {
		if (error.code === 'ESRCH') return
		throw error
	}
	if (await waitForProcessGroupExit(child.pid, graceMs)) {
		await waitForLeaderExit(child, Math.max(graceMs, 1_000))
		return
	}
	try {
		process.kill(-child.pid, 'SIGKILL')
	} catch (error) {
		if (error.code === 'ESRCH') return
		throw error
	}
	assert.ok(
		await waitForProcessGroupExit(child.pid, graceMs),
		`isolated client process group ${child.pid} survived SIGKILL`,
	)
	assert.ok(
		await waitForLeaderExit(child, Math.max(graceMs, 1_000)),
		`isolated client leader ${child.pid} did not exit`,
	)
}
