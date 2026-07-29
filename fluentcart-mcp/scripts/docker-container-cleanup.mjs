import { spawnSync } from 'node:child_process'

function defaultRunDocker(args) {
	return spawnSync('docker', args, { encoding: 'utf8', timeout: 30_000 })
}

function detailOf(result) {
	return (result.stderr || result.stdout || result.error?.message || '').trim()
}

function reportsAbsent(result) {
	return (
		result.status !== 0 &&
		/(?:No such container|No such object):?\s*/i.test(detailOf(result))
	)
}

export function verifyDockerContainerAbsent(containerId, options = {}) {
	const runDocker = options.runDocker ?? defaultRunDocker
	const inspected = runDocker(['inspect', containerId])
	if (inspected.status === 0) throw new Error(`container ${containerId} still exists`)
	if (!reportsAbsent(inspected)) {
		throw new Error(
			`could not verify absence of container ${containerId}: ${detailOf(inspected) || `exit ${inspected.status}`}`,
		)
	}
}

export function removeDockerContainer(containerId, options = {}) {
	if (!containerId) return
	const runDocker = options.runDocker ?? defaultRunDocker
	const removed = runDocker(['rm', '-f', containerId])
	const inspected = runDocker(['inspect', containerId])
	if (inspected.status === 0) {
		throw new Error(
			`docker rm failed: ${detailOf(removed) || `exit ${removed.status}`}; container ${containerId} still exists`,
		)
	}
	if (!reportsAbsent(inspected)) {
		throw new Error(
			`could not verify removal of container ${containerId}: ${detailOf(inspected) || `exit ${inspected.status}`}`,
		)
	}
}
