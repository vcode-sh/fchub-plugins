import { resolveServerContext } from '../src/server.js'

const ctx = resolveServerContext()
const toolMap = new Map<string, (typeof ctx.tools)[0]>()
for (const t of ctx.tools) toolMap.set(t.name, t)

async function call(name: string, input: Record<string, unknown> = {}) {
	const tool = toolMap.get(name)
	if (!tool) return { error: 'not found' }
	const result = await tool.handler(input)
	return JSON.parse(result.content[0]?.text ?? '{}')
}

async function run() {
	const del = await call('fluentcart_attribute_group_delete', { group_id: 41 })
	console.log('Delete group 41:', JSON.stringify(del))

	const delProd = await call('fluentcart_product_delete', { product_id: 267 })
	console.log('Delete product 267:', JSON.stringify(delProd))
}

run().catch(console.error)
