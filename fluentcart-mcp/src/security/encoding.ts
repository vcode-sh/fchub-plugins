import { createHash } from 'node:crypto'

/**
 * Deterministic encodings shared by the confirmation token and the idempotency ledger.
 *
 * Both consumers compare bytes that were produced on a previous run, possibly by a previous
 * process, so every function here must be stable across runs, platforms and locales.
 */

/** base64url with no padding, as used in every token segment. */
const BASE64URL_ALPHABET = /^[A-Za-z0-9_-]*$/

export class EncodingError extends Error {
	constructor(message: string) {
		super(message)
		this.name = 'EncodingError'
	}
}

/**
 * Serialise `value` so that two structurally equal values always produce identical bytes.
 *
 * Object keys are sorted by UTF-16 code unit rather than `localeCompare`, because locale-aware
 * ordering differs between machines and would silently break signature verification.
 * `undefined` properties are dropped; anything JSON cannot represent is rejected rather than
 * quietly coerced, since a signature over a coerced value proves nothing about the original.
 */
export function canonicalJson(value: unknown): string {
	return canonicalise(value, 0)
}

const MAX_CANONICAL_DEPTH = 32

function canonicalise(value: unknown, depth: number): string {
	if (depth > MAX_CANONICAL_DEPTH) throw new EncodingError('Value nests too deeply to canonicalise')
	if (value === null) return 'null'

	switch (typeof value) {
		case 'string':
		case 'boolean':
			return JSON.stringify(value)
		case 'number':
			if (!Number.isFinite(value))
				throw new EncodingError('Cannot canonicalise a non-finite number')
			return JSON.stringify(value)
		case 'object':
			return canonicaliseObject(value as object, depth)
		default:
			throw new EncodingError(`Cannot canonicalise a value of type ${typeof value}`)
	}
}

function canonicaliseObject(value: object, depth: number): string {
	if (Array.isArray(value)) {
		return `[${value.map((entry) => canonicalise(entry, depth + 1)).join(',')}]`
	}

	const entries = Object.entries(value as Record<string, unknown>)
		.filter(([, entry]) => entry !== undefined)
		.sort(([left], [right]) => compareCodeUnits(left, right))

	const body = entries
		.map(([key, entry]) => `${JSON.stringify(key)}:${canonicalise(entry, depth + 1)}`)
		.join(',')
	return `{${body}}`
}

function compareCodeUnits(left: string, right: string): number {
	if (left === right) return 0
	return left < right ? -1 : 1
}

/**
 * Hash the supplied parts into a lowercase hex digest.
 *
 * Each part is length-prefixed so that `('ab', 'c')` and `('a', 'bc')` cannot collide. Callers
 * use this for ledger path names, so a collision would let one operation read another's record.
 */
export function sha256Hex(...parts: readonly string[]): string {
	const hash = createHash('sha256')
	for (const part of parts) {
		hash.update(`${Buffer.byteLength(part, 'utf8')}:`)
		hash.update(part, 'utf8')
	}
	return hash.digest('hex')
}

export function base64UrlEncode(bytes: Uint8Array): string {
	return Buffer.from(bytes.buffer, bytes.byteOffset, bytes.byteLength).toString('base64url')
}

/**
 * Decode strict, unpadded base64url.
 *
 * Node's decoder silently ignores characters outside the alphabet, so a tampered token could
 * otherwise decode to the same bytes as the original. The alphabet check and the re-encode
 * comparison together make exactly one text encode to any given byte string.
 */
export function base64UrlDecode(text: string): Uint8Array {
	if (!BASE64URL_ALPHABET.test(text)) throw new EncodingError('Value is not base64url')
	if (text.length % 4 === 1) throw new EncodingError('Value is not base64url')

	const bytes = new Uint8Array(Buffer.from(text, 'base64url'))
	if (base64UrlEncode(bytes) !== text) throw new EncodingError('Value is not canonical base64url')
	return bytes
}
