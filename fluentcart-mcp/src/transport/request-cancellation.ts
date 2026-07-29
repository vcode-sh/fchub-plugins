import type { RequestHandler } from 'express'
import { getTransportPrincipal, type TransportPrincipal } from './auth.js'

export type RegisteredRequestId = string | number

interface Registration {
	abort: () => void
	release: () => void
}

interface RegistryOptions {
	horizonMs?: number
	maxPrincipals?: number
	maxRegistrationsPerId?: number
	maxRequestIdsPerPrincipal?: number
	timeoutMs?: number
}

interface RequestSlot {
	blocked: boolean
	registrations: Set<Registration>
	tombstone?: NodeJS.Timeout
}

export type CancellationRoute =
	| { kind: 'request'; requestId: RegisteredRequestId }
	| { kind: 'cancellation'; requestId: RegisteredRequestId }

const DEFAULT_HORIZON_MS = 310_000
const DEFAULT_MAX_PRINCIPALS = 16
const DEFAULT_MAX_REGISTRATIONS_PER_ID = 8
const DEFAULT_MAX_REQUEST_IDS_PER_PRINCIPAL = 1_024
const DEFAULT_TIMEOUT_MS = 310_000

function isRequestId(value: unknown): value is RegisteredRequestId {
	return typeof value === 'string' || (typeof value === 'number' && Number.isSafeInteger(value))
}

/** Extract only valid single-message JSON-RPC request and cancellation identities. */
export function cancellationRoute(body: unknown): CancellationRoute | undefined {
	if (body === null || typeof body !== 'object' || Array.isArray(body)) return undefined
	const message = body as Record<string, unknown>
	if (message.jsonrpc !== '2.0' || typeof message.method !== 'string') return undefined

	if (message.method === 'notifications/cancelled') {
		if (message.id !== undefined) return undefined
		const params = message.params
		if (params === null || typeof params !== 'object' || Array.isArray(params)) return undefined
		const requestId = (params as Record<string, unknown>).requestId
		return isRequestId(requestId) ? { kind: 'cancellation', requestId } : undefined
	}

	return isRequestId(message.id) ? { kind: 'request', requestId: message.id } : undefined
}

function principalKey(principal: TransportPrincipal): string {
	return `${principal.kind}\0${principal.id}`
}

function positiveInteger(value: number, label: string): number {
	if (!Number.isSafeInteger(value) || value < 1) {
		throw new RangeError(`${label} must be a positive whole number.`)
	}
	return value
}

/**
 * Route legacy cancellation POSTs without sharing request identity across principals.
 *
 * Reuse, collision, and unknown cancellation leave a bounded tombstone. Once ownership becomes
 * ambiguous, routing stays disabled for that id until every live request and the replay horizon
 * have passed. Cardinality saturation disables the whole service registry instead of evicting
 * safety state and risking a wrong cancellation.
 */
export class RequestCancellationRegistry {
	private readonly entries = new Map<string, Map<RegisteredRequestId, RequestSlot>>()
	private readonly horizonMs: number
	private readonly maxPrincipals: number
	private readonly maxRegistrationsPerId: number
	private readonly maxRequestIdsPerPrincipal: number
	private readonly timeoutMs: number
	private closed = false
	private routingDisabled = false

	constructor(options: RegistryOptions = {}) {
		this.horizonMs = positiveInteger(
			options.horizonMs ?? DEFAULT_HORIZON_MS,
			'Cancellation registry horizon',
		)
		this.maxPrincipals = positiveInteger(
			options.maxPrincipals ?? DEFAULT_MAX_PRINCIPALS,
			'Cancellation registry principal limit',
		)
		this.maxRegistrationsPerId = positiveInteger(
			options.maxRegistrationsPerId ?? DEFAULT_MAX_REGISTRATIONS_PER_ID,
			'Cancellation registry per-id registration limit',
		)
		this.maxRequestIdsPerPrincipal = positiveInteger(
			options.maxRequestIdsPerPrincipal ?? DEFAULT_MAX_REQUEST_IDS_PER_PRINCIPAL,
			'Cancellation registry per-principal request-id limit',
		)
		this.timeoutMs = positiveInteger(
			options.timeoutMs ?? DEFAULT_TIMEOUT_MS,
			'Cancellation registry timeout',
		)
	}

	register(
		principal: TransportPrincipal,
		requestId: RegisteredRequestId,
		abort: () => void,
		onRelease: () => void = () => undefined,
	): (() => void) | undefined {
		if (this.closed || this.routingDisabled) return undefined
		const key = principalKey(principal)
		let requests = this.entries.get(key)
		if (!requests) {
			if (this.entries.size >= this.maxPrincipals) return this.disableRouting()
			requests = new Map()
			this.entries.set(key, requests)
		}
		const existing = requests.get(requestId)
		if (!existing && requests.size >= this.maxRequestIdsPerPrincipal) {
			return this.disableRouting()
		}
		const slot = existing ?? { blocked: false, registrations: new Set<Registration>() }
		if (!existing) requests.set(requestId, slot)
		else slot.blocked = true
		if (slot.registrations.size >= this.maxRegistrationsPerId) return this.disableRouting()
		if (slot.tombstone) clearTimeout(slot.tombstone)
		slot.tombstone = undefined

		let timer: NodeJS.Timeout | undefined
		let released = false
		const registration: Registration = {
			abort,
			release: () => {
				if (released) return
				released = true
				if (timer) clearTimeout(timer)
				slot.registrations.delete(registration)
				slot.blocked = true
				try {
					onRelease()
				} catch {
					// Response cleanup is best-effort and never weakens the routing tombstone.
				}
				if (slot.registrations.size === 0 && !this.closed) {
					this.armTombstone(key, requests, requestId, slot)
				}
			},
		}
		slot.registrations.add(registration)
		timer = setTimeout(registration.release, this.timeoutMs)
		timer.unref()
		return registration.release
	}

	cancel(principal: TransportPrincipal, requestId: RegisteredRequestId): boolean {
		if (this.closed || this.routingDisabled) return false
		const key = principalKey(principal)
		let requests = this.entries.get(key)
		if (!requests) {
			if (this.entries.size >= this.maxPrincipals) {
				this.disableRouting()
				return false
			}
			requests = new Map()
			this.entries.set(key, requests)
		}
		let slot = requests.get(requestId)
		if (!slot) {
			if (requests.size >= this.maxRequestIdsPerPrincipal) {
				this.disableRouting()
				return false
			}
			slot = { blocked: true, registrations: new Set() }
			requests.set(requestId, slot)
			this.armTombstone(key, requests, requestId, slot)
			return false
		}
		if (slot.blocked || slot.registrations.size !== 1) {
			slot.blocked = true
			if (slot.registrations.size === 0) this.armTombstone(key, requests, requestId, slot)
			return false
		}
		const registration = slot.registrations.values().next().value as Registration
		registration.release()
		try {
			registration.abort()
		} catch {
			// The original connection may already be closing; cancellation remains a no-op.
		}
		return true
	}

	close(): void {
		if (this.closed) return
		this.closed = true
		for (const requests of [...this.entries.values()]) {
			for (const slot of [...requests.values()]) {
				if (slot.tombstone) clearTimeout(slot.tombstone)
				for (const registration of [...slot.registrations]) registration.release()
			}
		}
		this.entries.clear()
	}

	private armTombstone(
		key: string,
		requests: Map<RegisteredRequestId, RequestSlot>,
		requestId: RegisteredRequestId,
		slot: RequestSlot,
	): void {
		if (slot.tombstone) clearTimeout(slot.tombstone)
		slot.tombstone = setTimeout(() => {
			slot.tombstone = undefined
			if (slot.registrations.size !== 0 || requests.get(requestId) !== slot) return
			requests.delete(requestId)
			if (requests.size === 0) this.entries.delete(key)
		}, this.horizonMs)
		slot.tombstone.unref()
	}

	private disableRouting(): undefined {
		this.routingDisabled = true
		return undefined
	}
}

/** Bind registry lifetime to the original Express response without logging request data. */
export function createRequestCancellationMiddleware(
	registry: RequestCancellationRegistry,
): RequestHandler {
	return (req, res, next) => {
		const route = cancellationRoute(req.body)
		const principal = getTransportPrincipal(req)
		if (!(route && principal)) {
			next()
			return
		}
		if (route.kind === 'cancellation') {
			registry.cancel(principal, route.requestId)
			next()
			return
		}

		let release: (() => void) | undefined
		const completed = () => release?.()
		const detach = () => {
			res.off('finish', completed)
			res.off('close', completed)
		}
		release = registry.register(principal, route.requestId, () => res.destroy(), detach)
		if (release) {
			res.once('finish', completed)
			res.once('close', completed)
		}
		next()
	}
}
