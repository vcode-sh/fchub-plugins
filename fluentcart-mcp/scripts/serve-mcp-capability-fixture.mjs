#!/usr/bin/env node

import { createServer } from 'node:http'

const portFlag = process.argv.indexOf('--port')
const port = Number.parseInt(portFlag === -1 ? '39082' : (process.argv[portFlag + 1] ?? ''), 10)
if (!Number.isInteger(port) || port < 1 || port > 65_535) {
	throw new Error('--port must be an integer from 1 to 65535')
}

const document = {
	namespaces: ['fluent-cart/v2'],
	routes: {
		'/fluent-cart/v2': { endpoints: [{ methods: ['GET'] }] },
		'/fluent-cart/v2/app/init': { endpoints: [{ methods: ['GET'] }] },
	},
}

const server = createServer((request, response) => {
	if (
		request.method === 'GET' &&
		(request.url === '/wp-json/fluent-cart/v2' || request.url === '/wp-json/fluent-cart/v2/')
	) {
		response.writeHead(200, { 'Content-Type': 'application/json' })
		response.end(JSON.stringify(document))
		return
	}

	response.writeHead(404, { 'Content-Type': 'application/json' })
	response.end(JSON.stringify({ code: 'rest_no_route' }))
})

server.listen(port, '0.0.0.0', () => {
	process.stdout.write(`fixture REST index listening on ${port}\n`)
})

for (const signal of ['SIGINT', 'SIGTERM']) {
	process.on(signal, () => server.close(() => process.exit(0)))
}
