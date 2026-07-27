import { createHmac } from 'node:crypto'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
	CONFIRMATION_TTL_MS,
	CONFIRMATION_TTL_SECONDS,
	type ConfirmationBinding,
	type ConfirmationClaims,
	ConfirmationError,
	createConfirmationClaims,
	MIN_SECRET_BYTES,
	signConfirmation,
	verifyConfirmation,
} from '../../src/security/confirmation-token.js'

const SECRET = new Uint8Array(Buffer.from('c'.repeat(64), 'utf8'))
const OTHER_SECRET = new Uint8Array(Buffer.from('d'.repeat(64), 'utf8'))
const ISSUED_AT = 1_700_000_000_000
const NONCE = '9f2c1d4e6a8b0c3d5e7f'

const BINDING: ConfirmationBinding = {
	version: 1,
	tool: 'fluentcart_order_refund',
	entity: 'order:42',
	stateFingerprint: 'a'.repeat(64),
	operationDigest: 'b'.repeat(64),
}

function claims(overrides: Partial<ConfirmationClaims> = {}): ConfirmationClaims {
	return { ...createConfirmationClaims(BINDING, ISSUED_AT, NONCE), ...overrides }
}

function token(overrides: Partial<ConfirmationClaims> = {}): string {
	return signConfirmation(claims(overrides), SECRET)
}

function decodePayload(value: string): Record<string, unknown> {
	const [payload] = value.split('.')
	return JSON.parse(Buffer.from(payload ?? '', 'base64url').toString('utf8'))
}

function expectRejection(run: () => unknown, code: string): void {
	try {
		run()
	} catch (error) {
		expect(error).toBeInstanceOf(ConfirmationError)
		expect((error as ConfirmationError).code).toBe(code)
		return
	}
	throw new Error(`Expected the token to be rejected with ${code}`)
}

describe('confirmation token lifetime', () => {
	it('fixes the lifetime at 300 seconds', () => {
		expect(CONFIRMATION_TTL_SECONDS).toBe(300)
		expect(CONFIRMATION_TTL_MS).toBe(300_000)
		expect(claims().expiresAt - claims().issuedAt).toBe(300_000)
	})

	it('accepts the token throughout the window and rejects it the instant it closes', () => {
		const value = token()
		expect(verifyConfirmation(value, BINDING, SECRET, ISSUED_AT).nonce).toBe(NONCE)
		expect(verifyConfirmation(value, BINDING, SECRET, ISSUED_AT + 299_999).tool).toBe(BINDING.tool)

		expectRejection(
			() => verifyConfirmation(value, BINDING, SECRET, ISSUED_AT + 300_000),
			'EXPIRED',
		)
		expectRejection(
			() => verifyConfirmation(value, BINDING, SECRET, ISSUED_AT + 900_000),
			'EXPIRED',
		)
	})

	it('rejects a token presented before it was issued', () => {
		expectRejection(
			() => verifyConfirmation(token(), BINDING, SECRET, ISSUED_AT - 1),
			'NOT_YET_VALID',
		)
	})

	it('refuses to sign or accept a stretched lifetime', () => {
		expect(() => signConfirmation(claims({ expiresAt: ISSUED_AT + 3_600_000 }), SECRET)).toThrow(
			/fixed token lifetime/,
		)

		// Signed with a valid TTL, then re-signed by an attacker who also holds the secret is out
		// of scope; this covers a payload minted by a future version with a different policy.
		const stretched = forgeToken({ ...claims(), expiresAt: ISSUED_AT + 3_600_000 }, SECRET)
		expectRejection(() => verifyConfirmation(stretched, BINDING, SECRET, ISSUED_AT), 'MALFORMED')
	})
})

describe('confirmation token binding', () => {
	it('round-trips every claim unchanged', () => {
		expect(verifyConfirmation(token(), BINDING, SECRET, ISSUED_AT)).toEqual(claims())
	})

	it('carries no data beyond the eight signed claims', () => {
		expect(Object.keys(decodePayload(token())).sort()).toEqual([
			'entity',
			'expiresAt',
			'issuedAt',
			'nonce',
			'operationDigest',
			'stateFingerprint',
			'tool',
			'version',
		])
	})

	const alterations: Array<[string, Partial<ConfirmationBinding>]> = [
		['tool', { tool: 'fluentcart_subscription_cancel' }],
		['entity', { entity: 'order:43' }],
		['stateFingerprint', { stateFingerprint: 'e'.repeat(64) }],
		['operationDigest', { operationDigest: 'f'.repeat(64) }],
	]

	for (const [field, change] of alterations) {
		it(`rejects a token whose ${field} no longer matches the action`, () => {
			expectRejection(
				() => verifyConfirmation(token(), { ...BINDING, ...change }, SECRET, ISSUED_AT),
				'CLAIM_MISMATCH',
			)
		})

		it(`rejects a payload re-encoded with a different ${field}`, () => {
			const forged = swapPayload(token(), { ...claims(), ...change })
			expectRejection(() => verifyConfirmation(forged, BINDING, SECRET, ISSUED_AT), 'BAD_SIGNATURE')
		})
	}
})

describe('confirmation token signature', () => {
	it('rejects a token signed with a different secret', () => {
		const foreign = signConfirmation(claims(), OTHER_SECRET)
		expectRejection(() => verifyConfirmation(foreign, BINDING, SECRET, ISSUED_AT), 'BAD_SIGNATURE')
	})

	it('rejects a flipped signature byte', () => {
		const [payload, signature] = token().split('.')
		const flipped = `${signature?.startsWith('A') ? 'B' : 'A'}${signature?.slice(1)}`
		expectRejection(
			() => verifyConfirmation(`${payload}.${flipped}`, BINDING, SECRET, ISSUED_AT),
			'BAD_SIGNATURE',
		)
	})

	it('rejects a short signature instead of throwing from the comparison', () => {
		const [payload, signature] = token().split('.')
		expectRejection(
			() => verifyConfirmation(`${payload}.${signature?.slice(0, 12)}`, BINDING, SECRET, ISSUED_AT),
			'BAD_SIGNATURE',
		)
	})

	const malformed: Array<[string, string]> = [
		['an empty token', ''],
		['a token with no signature segment', 'eyJhIjoxfQ'],
		['a token with three segments', 'eyJhIjoxfQ.AAAA.AAAA'],
		['an empty payload segment', '.AAAA'],
		['an empty signature segment', 'eyJhIjoxfQ.'],
		['characters outside the base64url alphabet', 'eyJhIjoxfQ.!!!!'],
		['standard base64 padding', 'eyJhIjoxfQ==.AAAA'],
		['base64 alphabet characters', 'eyJhIjoxfQ.a+b/c'],
	]

	for (const [label, value] of malformed) {
		it(`rejects ${label}`, () => {
			expectRejection(() => verifyConfirmation(value, BINDING, SECRET, ISSUED_AT), 'MALFORMED')
		})
	}

	it('rejects a correctly signed payload that is not JSON', () => {
		const forged = signRaw(Buffer.from('not-json-at-all', 'utf8'), SECRET)
		expectRejection(() => verifyConfirmation(forged, BINDING, SECRET, ISSUED_AT), 'MALFORMED')
	})

	it('rejects a correctly signed payload carrying an extra field', () => {
		const forged = forgeToken({ ...claims(), operator: 'someone@example.com' }, SECRET)
		expectRejection(() => verifyConfirmation(forged, BINDING, SECRET, ISSUED_AT), 'MALFORMED')
	})

	it('refuses to sign or verify with an undersized secret', () => {
		const weak = new Uint8Array(Buffer.from('x'.repeat(MIN_SECRET_BYTES - 1), 'utf8'))
		expect(() => signConfirmation(claims(), weak)).toThrow(/at least 32 bytes/)
		expect(() => verifyConfirmation(token(), BINDING, weak, ISSUED_AT)).toThrow(/at least 32 bytes/)
	})
})

describe('constant-time comparison', () => {
	beforeEach(() => {
		vi.resetModules()
		vi.doUnmock('node:crypto')
	})

	async function loadWithSpy(): Promise<{
		module: typeof import('../../src/security/confirmation-token.js')
		lengths: Array<[number, number]>
	}> {
		const lengths: Array<[number, number]> = []
		vi.doMock('node:crypto', async () => {
			const actual = await vi.importActual<typeof import('node:crypto')>('node:crypto')
			return {
				...actual,
				default: actual,
				timingSafeEqual(left: Uint8Array, right: Uint8Array) {
					lengths.push([left.byteLength, right.byteLength])
					return actual.timingSafeEqual(left, right)
				},
			}
		})
		return { module: await import('../../src/security/confirmation-token.js'), lengths }
	}

	it('compares equal-length buffers through timingSafeEqual', async () => {
		const { module, lengths } = await loadWithSpy()
		const value = module.signConfirmation(claims(), SECRET)
		module.verifyConfirmation(value, BINDING, SECRET, ISSUED_AT)

		expect(lengths).toHaveLength(1)
		expect(lengths[0]?.[0]).toBe(32)
		expect(lengths[0]?.[1]).toBe(32)
	})

	it('never hands a mismatched length to the comparison', async () => {
		const { module, lengths } = await loadWithSpy()
		const [payload, signature] = module.signConfirmation(claims(), SECRET).split('.')

		// Matched by message: the freshly imported module has its own error class identity.
		expect(() =>
			module.verifyConfirmation(`${payload}.${signature?.slice(0, 8)}`, BINDING, SECRET, ISSUED_AT),
		).toThrow(/signature is not valid/)
		expect(lengths).toHaveLength(0)
	})
})

/** Sign arbitrary claims, bypassing the shape check `signConfirmation` performs. */
function forgeToken(value: object, secret: Uint8Array): string {
	return signRaw(Buffer.from(stableJson(value), 'utf8'), secret)
}

function signRaw(payload: Buffer, secret: Uint8Array): string {
	const signature = createHmac('sha256', secret).update(payload).digest()
	return `${payload.toString('base64url')}.${signature.toString('base64url')}`
}

/** Replace the payload while keeping the original signature. */
function swapPayload(value: string, replacement: object): string {
	const [, signature] = value.split('.')
	const payload = Buffer.from(stableJson(replacement), 'utf8').toString('base64url')
	return `${payload}.${signature}`
}

function stableJson(value: object): string {
	const entries = Object.entries(value).sort(([left], [right]) => (left < right ? -1 : 1))
	return `{${entries.map(([key, entry]) => `${JSON.stringify(key)}:${JSON.stringify(entry)}`).join(',')}}`
}
