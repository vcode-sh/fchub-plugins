import { createHash } from 'node:crypto'
import { canonicalJson, type DiscoveredAbility } from './schema.js'

export type AbilityMethod = 'GET' | 'POST'

// Generated from the audited 1.5.5 and 1.6.0 status-only fixtures. Keeping both exact
// fingerprints preserves the older verified bridge without weakening fail-closed discovery.
export const APPROVED_FALLBACK_FINGERPRINTS: ReadonlySet<string> = new Set([
	'sha256:6ea128e10b59b249c9aa1bae1e0e7ae159f6750b25c8a3d0a4d8ec4a80109f21',
	'sha256:ea6ea1c9f873acfbc07d594205f41c59c8bc478e235b4618c163755823f1cb31',
	'sha256:b4e851048258d8025efdb1ce5a66d16c4a8fdec9de5b830c9009518e6f5ff5e0',
	'sha256:cd028b1cf48c10bc4da065abbfdd86ef244be166a20c20d64242abd2db4bca0f',
	'sha256:a27d1009e9f87164348289a81aff7376835a210b7f437beb493572464f6e8b52',
	'sha256:193386897289557c87741ab6d07ca70caecd8f138c423340cd61af9b4a6443a8',
	'sha256:9c0a81f7d8eeac31ad39b9c3940bedae4ce8413286457db9d596e5fe3228d43d',
	'sha256:08929de45b351d8400f4ebc31c6782bc9d31e704fc0ac84d1715abf8455e418b',
	'sha256:ad1d57f0f25798084a259a4108d583a0b0654602fdd2904fa3204c92c90a9e76',
	'sha256:d3a710b4d3b28b0709f1bc446571cfcf92ccbd8f117011b980ee366a1b6b4616',
	'sha256:1bd792c89fcc7373bb959c970bb00d2ad7ea03c8f61ae56de3a6859e82159be6',
	'sha256:03663649262c5abc7856eceba356549bcd28a8ce79a318b5ffcf42b815136670',
	'sha256:f2e1f60401c5ab446681f66eae09fa079e8aac36ae69fb137e924bfc0b36c01b',
	'sha256:3bc0c25cd780064e44a87dd6ddc61ec2c401e039ee10391f28b33e0a9476045a',
	'sha256:5749a69ad9c474eb5d607d806eac3026bc4a33fdfbbac0da8d0ea224592c7d1e',
	'sha256:3e8573e4afe563495c342444b453f0f6c81394e17fe2b1e9554f7eea395bdfa5',
	'sha256:03ecbdc6f9d697dcf25b69384222f8c55ac2b9e47f15ce59485c096730d8e6b4',
	'sha256:ff302f5468b2025a0ab90a068b5aaabdf67d3d90813f592b55bfceb4087e7b7d',
	'sha256:00a44a4f6e0c22f81529f161c76e602c6926996cb8ff23548518ea0c29155898',
	'sha256:87309b0f53710509706c56e05a866cd5cfcd7d7b321e662c4ae01d457c584d3a',
	'sha256:bb9f68f81e04cb1c84c0d0db68b65e09a58b770530588d36144486b8a9f9b627',
	'sha256:dc842b31e2e89599391df321e30d11cc8e139f22cd2e589f7a77f57b4c39fb57',
	'sha256:b8d0efab8788d5e1552fa60f279f1fd6830550fb53d80bed5001697c0f1bda30',
	'sha256:22a6c5c35dd41a030fab54627ed801db89240ec9e10598304ae325491066e52e',
	'sha256:d9da5d48a75c4ad9a81d89fe681dd03f00c289b5f4476d83f4bab6affdde324c',
	'sha256:744772f4c4918046239bcdf7820011b5969dd09e2750ab6c01436868f6bf5532',
	'sha256:85951d0b4c5f77293b71a1c6606bd3787e76fb5199e836eca9372528593dac2e',
	'sha256:5124d86a2a91bdd9c90b131ceee733bc30c2ff81a6ab4b492e7ef935ebe8ce9a',
	'sha256:cec5436057c3be4dc2748d1b2eb543a165339d5777318f8e036f040b9441e2cb',
	'sha256:2f069edf53ffbf322877897840a0764a15f19ae37007a7735b4b5d6ad33d1aeb',
	'sha256:9bc54cca14afffa1f5fbcaaebeabb917e97d014656492f378251a4301f8ddcc5',
	'sha256:6231cfb5e9dbcd021a31f2c3fc8e3de147ac08325fb274cade0ea3245ef9e7a3',
	'sha256:90883e801a99130151b2d312227c1ad27faba80fc32030102c57dfeb690ed077',
	'sha256:6083e5b54bc1abf613c063d04cd0125798c9b85d39da0b962e822bd7956ce78a',
])

export function fingerprintAbility(ability: DiscoveredAbility): `sha256:${string}` {
	const methods = [...new Set(ability.rest.methods.map((method) => method.toUpperCase()))].sort()
	const projection = {
		name: ability.name,
		category: ability.category,
		inputSchema: ability.inputSchema,
		outputSchema: ability.outputSchema,
		annotations: {
			abilitiesReadonly: ability.annotations.abilitiesReadonly,
			abilitiesDestructive: ability.annotations.abilitiesDestructive,
			abilitiesIdempotent: ability.annotations.abilitiesIdempotent,
			mcpReadOnlyHint: ability.annotations.mcpReadOnlyHint,
			mcpDestructiveHint: ability.annotations.mcpDestructiveHint,
			mcpIdempotentHint: ability.annotations.mcpIdempotentHint,
			mcpOpenWorldHint: ability.annotations.mcpOpenWorldHint,
		},
		rest: {
			runPath: ability.rest.runPath,
			methods,
		},
	}
	return `sha256:${createHash('sha256').update(canonicalJson(projection)).digest('hex')}`
}

export function selectAbilityMethod(
	ability: DiscoveredAbility,
	approvedFallbackFingerprints: ReadonlySet<string>,
): AbilityMethod | null {
	const annotations = ability.annotations
	const safeDestructive =
		annotations.abilitiesDestructive === false || annotations.abilitiesDestructive === null
	const safeMcpDestructive =
		annotations.mcpDestructiveHint === false || annotations.mcpDestructiveHint === null
	if (!(safeDestructive && safeMcpDestructive)) return null

	const mcpRead = annotations.mcpReadOnlyHint === true || annotations.mcpReadOnlyHint === null
	if (annotations.abilitiesReadonly === true && mcpRead) return 'GET'
	if (
		annotations.abilitiesReadonly === null &&
		annotations.mcpReadOnlyHint === true &&
		approvedFallbackFingerprints.has(fingerprintAbility(ability))
	) {
		return 'POST'
	}
	return null
}
