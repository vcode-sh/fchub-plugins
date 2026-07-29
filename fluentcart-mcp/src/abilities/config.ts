export type AbilitiesConfig =
	| { enabled: false }
	| { enabled: true; username: string; appPassword: string }

/**
 * Resolve the optional WordPress Abilities bridge.
 *
 * The bridge deliberately has a separate principal. Falling back to the general FluentCart REST
 * credentials would silently widen that principal's reachable surface whenever an operator
 * enabled the bridge, which is not an acceptable permission change.
 */
export function resolveAbilitiesConfig(): AbilitiesConfig {
	const mode = process.env.FLUENTCART_ABILITIES_MODE
	if (mode === undefined || mode === '' || mode === 'disabled') return { enabled: false }
	if (mode !== 'enabled') {
		throw new Error(
			`Invalid FLUENTCART_ABILITIES_MODE "${mode}". Expected "disabled" or "enabled".`,
		)
	}

	const username = process.env.FLUENTCART_ABILITIES_USERNAME
	const appPassword = process.env.FLUENTCART_ABILITIES_APP_PASSWORD
	if (!(username && appPassword)) {
		throw new Error(
			'FLUENTCART_ABILITIES_MODE=enabled requires both FLUENTCART_ABILITIES_USERNAME and FLUENTCART_ABILITIES_APP_PASSWORD. The bridge never reuses the general REST credentials.',
		)
	}

	return { enabled: true, username, appPassword }
}
