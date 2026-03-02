import { createClient } from '../../src/api/client.js'
import type { ResolvedConfig } from '../../src/config/types.js'

const testConfig: ResolvedConfig = {
	url: 'https://example.com',
	username: 'admin',
	appPassword: 'test-pass',
	adminBase: 'https://example.com/wp-json/fluent-cart/v2',
	publicBase: 'https://example.com/wp-json/fluent-cart-public/v2',
}

const mockFetch = vi.fn()
vi.stubGlobal('fetch', mockFetch)

function mockResponse(overrides: Partial<Response> & { json?: () => Promise<unknown> } = {}) {
	return {
		ok: true,
		status: 200,
		statusText: 'OK',
		json: () => Promise.resolve({ data: 'test' }),
		...overrides,
	}
}

describe('createClient', () => {
	beforeEach(() => {
		mockFetch.mockReset()
		mockFetch.mockResolvedValue(mockResponse())
	})

	it('returns object with get, post, put, delete', () => {
		const client = createClient(testConfig)
		expect(client).toHaveProperty('get')
		expect(client).toHaveProperty('post')
		expect(client).toHaveProperty('put')
		expect(client).toHaveProperty('delete')
		expect(typeof client.get).toBe('function')
		expect(typeof client.post).toBe('function')
		expect(typeof client.put).toBe('function')
		expect(typeof client.delete).toBe('function')
	})

	describe('GET requests', () => {
		it('sends Authorization: Basic header with correct base64 encoding', async () => {
			const client = createClient(testConfig)
			await client.get('/products')

			const expectedCredentials = Buffer.from('admin:test-pass').toString('base64')
			expect(mockFetch).toHaveBeenCalledWith(
				expect.any(String),
				expect.objectContaining({
					headers: expect.objectContaining({
						Authorization: `Basic ${expectedCredentials}`,
					}),
				}),
			)
		})

		it('appends query params to URL', async () => {
			const client = createClient(testConfig)
			await client.get('/products', { page: 1, per_page: 10, search: 'widget' })

			const calledUrl = mockFetch.mock.calls[0][0] as string
			const url = new URL(calledUrl)
			expect(url.searchParams.get('page')).toBe('1')
			expect(url.searchParams.get('per_page')).toBe('10')
			expect(url.searchParams.get('search')).toBe('widget')
		})

		it('uses adminBase URL by default', async () => {
			const client = createClient(testConfig)
			await client.get('/products')

			const calledUrl = mockFetch.mock.calls[0][0] as string
			expect(calledUrl).toContain('https://example.com/wp-json/fluent-cart/v2/products')
		})
	})

	describe('POST requests', () => {
		it('sends JSON body with Content-Type: application/json', async () => {
			const client = createClient(testConfig)
			const body = { title: 'New Product', price: 29.99 }
			await client.post('/products', body)

			expect(mockFetch).toHaveBeenCalledWith(
				expect.any(String),
				expect.objectContaining({
					method: 'POST',
					headers: expect.objectContaining({
						'Content-Type': 'application/json',
					}),
					body: JSON.stringify(body),
				}),
			)
		})
	})

	describe('PUT requests', () => {
		it('sends JSON body', async () => {
			const client = createClient(testConfig)
			const body = { title: 'Updated Product' }
			await client.put('/products/1', body)

			expect(mockFetch).toHaveBeenCalledWith(
				expect.any(String),
				expect.objectContaining({
					method: 'PUT',
					body: JSON.stringify(body),
				}),
			)
		})
	})

	describe('DELETE requests', () => {
		it('works with params', async () => {
			const client = createClient(testConfig)
			await client.delete('/products/1', { force: true })

			const calledUrl = mockFetch.mock.calls[0][0] as string
			expect(calledUrl).toContain('force=true')
			expect(mockFetch).toHaveBeenCalledWith(
				expect.any(String),
				expect.objectContaining({ method: 'DELETE' }),
			)
		})
	})

	describe('error handling', () => {
		it('401 throws "Authentication failed" message', async () => {
			mockFetch.mockResolvedValue(
				mockResponse({
					ok: false,
					status: 401,
					statusText: 'Unauthorized',
					json: () => Promise.resolve({ message: 'Invalid credentials' }),
				}),
			)

			const client = createClient(testConfig)
			await expect(client.get('/products')).rejects.toThrow('Authentication failed')
		})

		it('403 throws "Permission denied" message', async () => {
			mockFetch.mockResolvedValue(
				mockResponse({
					ok: false,
					status: 403,
					statusText: 'Forbidden',
					json: () => Promise.resolve({ message: 'Forbidden' }),
				}),
			)

			const client = createClient(testConfig)
			await expect(client.get('/products')).rejects.toThrow('Permission denied')
		})

		it('404 throws "Resource not found" message', async () => {
			mockFetch.mockResolvedValue(
				mockResponse({
					ok: false,
					status: 404,
					statusText: 'Not Found',
					json: () => Promise.resolve({ message: 'Product not found' }),
				}),
			)

			const client = createClient(testConfig)
			await expect(client.get('/products/999')).rejects.toThrow('Resource not found')
		})

		it('422 throws "Validation error" with body', async () => {
			const validationBody = { message: 'Invalid input', errors: { title: 'required' } }
			mockFetch.mockResolvedValue(
				mockResponse({
					ok: false,
					status: 422,
					statusText: 'Unprocessable Entity',
					json: () => Promise.resolve(validationBody),
				}),
			)

			const client = createClient(testConfig)
			await expect(client.post('/products', {})).rejects.toThrow('Validation error')
		})

		it('timeout throws "Request timed out" message', async () => {
			const abortError = new DOMException('The operation was aborted', 'AbortError')
			mockFetch.mockRejectedValue(abortError)

			const timeoutConfig: ResolvedConfig = { ...testConfig, timeout: 100 }
			const client = createClient(timeoutConfig)
			await expect(client.get('/products')).rejects.toThrow('Request timed out after 100ms')
		})

		it('unknown error (500) throws "API error 500" message', async () => {
			mockFetch.mockResolvedValue(
				mockResponse({
					ok: false,
					status: 500,
					statusText: 'Internal Server Error',
					json: () => Promise.resolve({ message: 'Server error' }),
				}),
			)

			const client = createClient(testConfig)
			await expect(client.get('/products')).rejects.toThrow('API error 500')
		})
	})

	describe('query param filtering', () => {
		it('excludes null and undefined params from URL query string', async () => {
			const client = createClient(testConfig)
			await client.get('/products', {
				page: 1,
				search: null,
				category: undefined,
				status: 'active',
			})

			const calledUrl = mockFetch.mock.calls[0][0] as string
			const url = new URL(calledUrl)
			expect(url.searchParams.get('page')).toBe('1')
			expect(url.searchParams.get('status')).toBe('active')
			expect(url.searchParams.has('search')).toBe(false)
			expect(url.searchParams.has('category')).toBe(false)
		})
	})

	describe('isPublic flag', () => {
		it('uses publicBase URL when isPublic is true', async () => {
			const client = createClient(testConfig)
			await client.get('/products', undefined, true)

			const calledUrl = mockFetch.mock.calls[0][0] as string
			expect(calledUrl).toContain('https://example.com/wp-json/fluent-cart-public/v2/products')
		})

		it('uses adminBase URL when isPublic is false', async () => {
			const client = createClient(testConfig)
			await client.get('/products', undefined, false)

			const calledUrl = mockFetch.mock.calls[0][0] as string
			expect(calledUrl).toContain('https://example.com/wp-json/fluent-cart/v2/products')
		})
	})
})
