import { createHmac, timingSafeEqual } from 'node:crypto'
import { base64UrlDecode, base64UrlEncode, canonicalJson } from './encoding.js'

/**
 * Short-lived proof that a specific operator saw a specific preview of a specific entity state.
 *
 * The token is the only thing standing between "an agent asked for a refund preview" and "an
 * agent refunded a customer". It therefore binds the exact tool, the exact entity, a fingerprint
 * of the state the preview described and a digest of the exact mutation fields. If any of those
 * moved between preview and execution, the token no longer verifies.
 *
 * The token carries no personal, order-content or payment data: every field is either a stable
 * public identifier or a digest, so a leaked token discloses nothing about a customer.
 */

export const CONFIRMATION_TOKEN_VERSION = 1

/** Fixed, not configurable: a preview is only meaningful while the state it described holds. */
export const CONFIRMATION_TTL_SECONDS = 300
export const CONFIRMATION_TTL_MS = CONFIRMATION_TTL_SECONDS * 1000

/** HMAC-SHA256 needs at least this much key material to be worth calling a secret. */
export const MIN_SECRET_BYTES = 32

const SIGNATURE_BYTES = 32
const NONCE_MIN_LENGTH = 16
const NONCE_MAX_LENGTH = 128
const MAX_TOKEN_LENGTH = 4096

export interface ConfirmationClaims {
	version: 1
	/** Public MCP tool name allowed to spend this token. */
	tool: string
	/** Opaque entity reference such as `order:42`. Never a customer or payment identifier. */
	entity: string
	/** Digest of the entity state the preview described. */
	stateFingerprint: string
	/** Digest of canonical JSON over the exact public mutation fields. */
	operationDigest: string
	/** Epoch milliseconds. */
	issuedAt: number
	/** Epoch milliseconds, always `issuedAt + CONFIRMATION_TTL_MS`. */
	expiresAt: number
	nonce: string
}

/** The part of a token an execution call must state up front and independently. */
export type ConfirmationBinding = Omit<ConfirmationClaims, 'issuedAt' | 'expiresAt' | 'nonce'>

export type ConfirmationErrorCode =
	| 'MALFORMED'
	| 'BAD_SIGNATURE'
	| 'EXPIRED'
	| 'NOT_YET_VALID'
	| 'CLAIM_MISMATCH'

export class ConfirmationError extends Error {
	readonly code: ConfirmationErrorCode

	constructor(code: ConfirmationErrorCode, message: string) {
		super(message)
		this.name = 'ConfirmationError'
		this.code = code
	}
}

const CLAIM_KEYS = [
	'version',
	'tool',
	'entity',
	'stateFingerprint',
	'operationDigest',
	'issuedAt',
	'expiresAt',
	'nonce',
] as const

export function createConfirmationClaims(
	binding: ConfirmationBinding,
	issuedAt: number,
	nonce: string,
): ConfirmationClaims {
	return { ...binding, issuedAt, expiresAt: issuedAt + CONFIRMATION_TTL_MS, nonce }
}

export function signConfirmation(claims: ConfirmationClaims, secret: Uint8Array): string {
	assertSecret(secret)
	assertClaimShape(claims)

	const payload = Buffer.from(canonicalJson(claims), 'utf8')
	return `${base64UrlEncode(payload)}.${base64UrlEncode(sign(payload, secret))}`
}

/**
 * Verify a token against the caller's independently supplied expectations.
 *
 * Order matters: the signature is checked over the raw decoded bytes before the payload is
 * parsed, so no attacker-chosen structure is ever interpreted on an unauthenticated token.
 */
export function verifyConfirmation(
	token: string,
	expected: ConfirmationBinding,
	secret: Uint8Array,
	now: number,
): ConfirmationClaims {
	assertSecret(secret)

	const { payload, signature } = splitToken(token)
	if (!signatureMatches(payload, signature, secret)) {
		throw new ConfirmationError('BAD_SIGNATURE', 'Confirmation token signature is not valid')
	}

	const claims = parseClaims(payload)
	assertFreshness(claims, now)
	assertBinding(claims, expected)
	return claims
}

function sign(payload: Buffer, secret: Uint8Array): Buffer {
	return createHmac('sha256', secret).update(payload).digest()
}

/**
 * Constant-time signature comparison.
 *
 * `timingSafeEqual` throws on unequal lengths, which would both leak length and surface as an
 * unhandled crash, so length is rejected first and only equal-length buffers reach it.
 */
function signatureMatches(payload: Buffer, signature: Uint8Array, secret: Uint8Array): boolean {
	if (signature.length !== SIGNATURE_BYTES) return false
	const expected = sign(payload, secret)
	if (expected.length !== signature.length) return false
	return timingSafeEqual(expected, signature)
}

function splitToken(token: string): { payload: Buffer; signature: Uint8Array } {
	if (typeof token !== 'string' || token.length === 0 || token.length > MAX_TOKEN_LENGTH) {
		throw new ConfirmationError('MALFORMED', 'Confirmation token is not a well-formed token')
	}

	const segments = token.split('.')
	const [payloadText, signatureText] = segments
	if (segments.length !== 2 || !payloadText || !signatureText) {
		throw new ConfirmationError('MALFORMED', 'Confirmation token is not a well-formed token')
	}

	try {
		return {
			payload: Buffer.from(base64UrlDecode(payloadText)),
			signature: base64UrlDecode(signatureText),
		}
	} catch {
		throw new ConfirmationError('MALFORMED', 'Confirmation token is not a well-formed token')
	}
}

function parseClaims(payload: Buffer): ConfirmationClaims {
	let parsed: unknown
	try {
		parsed = JSON.parse(payload.toString('utf8'))
	} catch {
		throw new ConfirmationError('MALFORMED', 'Confirmation token payload is not valid JSON')
	}

	try {
		assertClaimShape(parsed)
		return parsed
	} catch (error) {
		throw new ConfirmationError('MALFORMED', (error as Error).message)
	}
}

function assertClaimShape(value: unknown): asserts value is ConfirmationClaims {
	if (value === null || typeof value !== 'object' || Array.isArray(value)) {
		throw new Error('Confirmation claims are not an object')
	}

	const keys = Object.keys(value)
	// Exact key set: an unexpected field would be carried, signed and trusted by a later version.
	if (keys.length !== CLAIM_KEYS.length || !CLAIM_KEYS.every((key) => keys.includes(key))) {
		throw new Error('Confirmation claims do not match the expected field set')
	}

	const claims = value as Record<string, unknown>
	if (claims.version !== CONFIRMATION_TOKEN_VERSION) {
		throw new Error('Confirmation claims use an unsupported version')
	}
	assertIdentifier(claims.tool, 'tool')
	assertIdentifier(claims.entity, 'entity')
	assertIdentifier(claims.stateFingerprint, 'stateFingerprint')
	assertIdentifier(claims.operationDigest, 'operationDigest')
	assertTimestamp(claims.issuedAt, 'issuedAt')
	assertTimestamp(claims.expiresAt, 'expiresAt')

	if (
		typeof claims.nonce !== 'string' ||
		claims.nonce.length < NONCE_MIN_LENGTH ||
		claims.nonce.length > NONCE_MAX_LENGTH
	) {
		throw new Error('Confirmation claims carry an unusable nonce')
	}
	if ((claims.expiresAt as number) - (claims.issuedAt as number) !== CONFIRMATION_TTL_MS) {
		throw new Error('Confirmation claims do not use the fixed token lifetime')
	}
}

function assertIdentifier(value: unknown, field: string): void {
	if (typeof value !== 'string' || value.length === 0 || value.length > 256) {
		throw new Error(`Confirmation claim "${field}" is not a usable identifier`)
	}
}

function assertTimestamp(value: unknown, field: string): void {
	if (typeof value !== 'number' || !Number.isSafeInteger(value) || value < 0) {
		throw new Error(`Confirmation claim "${field}" is not a usable timestamp`)
	}
}

function assertFreshness(claims: ConfirmationClaims, now: number): void {
	if (!Number.isFinite(now)) throw new ConfirmationError('MALFORMED', 'Current time is not finite')
	if (now < claims.issuedAt) {
		throw new ConfirmationError('NOT_YET_VALID', 'Confirmation token is not valid yet')
	}
	// Inclusive: at exactly `expiresAt` the 300-second window has elapsed.
	if (now >= claims.expiresAt) {
		throw new ConfirmationError('EXPIRED', 'Confirmation token has expired; take a fresh preview')
	}
}

function assertBinding(claims: ConfirmationClaims, expected: ConfirmationBinding): void {
	const mismatched =
		claims.version !== expected.version ||
		claims.tool !== expected.tool ||
		claims.entity !== expected.entity ||
		claims.stateFingerprint !== expected.stateFingerprint ||
		claims.operationDigest !== expected.operationDigest

	if (mismatched) {
		throw new ConfirmationError(
			'CLAIM_MISMATCH',
			'Confirmation token does not match this action or the current state; take a fresh preview',
		)
	}
}

function assertSecret(secret: Uint8Array): void {
	if (secret.length < MIN_SECRET_BYTES) {
		throw new Error(`Guard signing secret must be at least ${MIN_SECRET_BYTES} bytes`)
	}
}
