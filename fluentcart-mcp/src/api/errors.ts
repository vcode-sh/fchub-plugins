export type ErrorCode =
	| 'AUTH_FAILED'
	| 'FORBIDDEN'
	| 'NOT_FOUND'
	| 'VALIDATION_ERROR'
	| 'TIMEOUT'
	| 'CONNECTION_ERROR'
	| 'RATE_LIMITED'
	| 'SERVER_ERROR'
	| 'UNKNOWN'

export class FluentCartApiError extends Error {
	constructor(
		public readonly code: ErrorCode,
		message: string,
		public readonly status?: number,
		public readonly detail?: unknown,
	) {
		super(message)
		this.name = 'FluentCartApiError'
	}
}

const STATUS_MAP: Record<number, [ErrorCode, string]> = {
	401: ['AUTH_FAILED', 'Authentication failed'],
	403: ['FORBIDDEN', 'Permission denied'],
	404: ['NOT_FOUND', 'Resource not found'],
	422: ['VALIDATION_ERROR', 'Validation error'],
	429: ['RATE_LIMITED', 'Rate limited'],
}

export function errorFromStatus(
	status: number,
	message: string,
	detail?: unknown,
): FluentCartApiError {
	const mapping = STATUS_MAP[status]
	if (mapping) {
		const [code, label] = mapping
		return new FluentCartApiError(code, `${label}: ${message}`, status, detail)
	}
	if (status >= 500) {
		return new FluentCartApiError('SERVER_ERROR', `Server error: ${message}`, status, detail)
	}
	return new FluentCartApiError('UNKNOWN', `API error ${status}: ${message}`, status, detail)
}
