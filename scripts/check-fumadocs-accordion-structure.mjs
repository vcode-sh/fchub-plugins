import assert from 'node:assert/strict'
import { readdir, readFile } from 'node:fs/promises'
import path from 'node:path'
import test from 'node:test'

const docsRoot = path.resolve('web-docs/content/docs')

async function mdxFiles(directory) {
  const entries = await readdir(directory, { withFileTypes: true })
  const files = []

  for (const entry of entries) {
    const entryPath = path.join(directory, entry.name)
    if (entry.isDirectory()) {
      files.push(...await mdxFiles(entryPath))
    } else if (entry.isFile() && entry.name.endsWith('.mdx')) {
      files.push(entryPath)
    }
  }

  return files
}

function validateAccordionStructure(source, file) {
  const stack = []
  const tokens = source.matchAll(/<(\/?)(Accordions|Accordion)\b[^>]*>/g)

  for (const token of tokens) {
    const closing = token[1] === '/'
    const component = token[2]

    if (!closing) {
      if (component === 'Accordion') {
        assert.equal(
          stack.at(-1),
          'Accordions',
          `${file}: <Accordion> must be a direct child of <Accordions>`,
        )
      }
      stack.push(component)
      continue
    }

    assert.equal(
      stack.pop(),
      component,
      `${file}: closing </${component}> does not match the open accordion component`,
    )
  }

  assert.deepEqual(stack, [], `${file}: accordion components are not balanced`)
}

test('Fumadocs accordion items are wrapped by an accordion root', async () => {
  const files = await mdxFiles(docsRoot)

  for (const file of files) {
    validateAccordionStructure(await readFile(file, 'utf8'), path.relative(process.cwd(), file))
  }
})
