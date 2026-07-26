import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const pluginRoot = path.resolve(import.meta.dirname, '../..')
const sourceCssPath = path.join(pluginRoot, 'resources/admin/styles/variables.css')
const builtAssetsPath = path.join(pluginRoot, 'assets/dist/assets')

function readBuiltCss() {
  return fs
    .readdirSync(builtAssetsPath)
    .filter((filename) => filename.endsWith('.css'))
    .map((filename) => fs.readFileSync(path.join(builtAssetsPath, filename), 'utf8'))
    .join('\n')
}

describe('local Inter distribution', () => {
  it('uses licensed local variable font faces in source and built CSS', () => {
    const sourceCss = fs.readFileSync(sourceCssPath, 'utf8')
    const builtCss = readBuiltCss()

    expect(sourceCss).not.toContain('fonts.googleapis.com')
    expect(sourceCss).not.toContain('fonts.gstatic.com')
    expect(sourceCss).toContain('@font-face')
    expect(sourceCss).toContain('font-weight: 400 700')
    expect(sourceCss).toContain('font-display: swap')
    expect(sourceCss).toContain("../fonts/inter-latin.woff2")
    expect(sourceCss).toContain("../fonts/inter-latin-ext.woff2")
    expect(builtCss).not.toMatch(/https?:\/\/[^)"']+\.(?:woff2?|ttf)/)

    for (const filename of ['inter-latin.woff2', 'inter-latin-ext.woff2']) {
      const fontPath = path.join(pluginRoot, 'resources/admin/fonts', filename)
      expect(fs.statSync(fontPath).size).toBeGreaterThan(0)
    }

    expect(fs.readFileSync(path.join(pluginRoot, 'licenses/Inter-OFL.txt'), 'utf8')).toContain(
      'SIL OPEN FONT LICENSE Version 1.1',
    )
  })

  it('emits both font subsets as hashed WOFF2 assets', () => {
    const filenames = fs.readdirSync(builtAssetsPath)

    expect(filenames).toEqual(
      expect.arrayContaining([
        expect.stringMatching(/^inter-latin-[A-Za-z0-9_-]+\.woff2$/),
        expect.stringMatching(/^inter-latin-ext-[A-Za-z0-9_-]+\.woff2$/),
      ]),
    )
  })
})
