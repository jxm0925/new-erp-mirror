const assert = require('node:assert/strict')
const fs = require('node:fs')
const path = require('node:path')
const compiler = require('vue-template-compiler')
const baselinePath = process.argv[2]
if (!baselinePath) throw new Error('Provide the template/style baseline JSON captured before the logic change.')
const baseline = JSON.parse(fs.readFileSync(baselinePath, 'utf8'))
const root = path.resolve(__dirname, '../src')
const logicalAttributes = new Set(['v-paged-scroll', 'remote', ':remote-method', ':loading'])
function structure (node) {
  if (!node) return null
  if (node.type !== 1) return { type: 'text', value: node.text.replace(/\{\{[\s\S]*?\}\}/g, '{{data}}').replace(/\s+/g, ' ').trim() }
  return {
    tag: node.tag,
    attrs: node.attrsList.filter(a => !logicalAttributes.has(a.name)).map(a => ({ name: a.name,
      value: a.name.startsWith('@') ? '{{handler}}' : a.name === ':label' && node.tag === 'el-option' ? '{{option data}}' : a.value })).sort((a, b) => a.name.localeCompare(b.name)),
    children: (node.children || []).map(structure).filter(n => n.type !== 'text' || n.value),
    slots: Object.entries(node.scopedSlots || {}).map(([key, value]) => [key, structure(value)]),
    alternatives: (node.ifConditions || []).filter(condition => condition.block !== node).map(condition => ({ expression: condition.exp, block: structure(condition.block) }))
  }
}
let checked = 0, changedBindings = 0
for (const [file, previous] of Object.entries(baseline)) {
  const current = fs.readFileSync(path.join(root, file), 'utf8').replace(/^\uFEFF/, '').replace(/\r\n/g, '\n')
  if (JSON.stringify(current.match(/<style[\s\S]*?<\/style>/g) || []) !== JSON.stringify(previous.styles)) throw new Error(`${file}: styles changed`)
  const before = compiler.parseComponent(previous.template).template
  const after = compiler.parseComponent(current).template
  if (!before || !after) { assert.equal(Boolean(before), Boolean(after)); continue }
  if (JSON.stringify(structure(compiler.compile(after.content).ast)) !== JSON.stringify(structure(compiler.compile(before.content).ast))) throw new Error(`${file}: visible structure, label or presentation attribute changed`)
  if (previous.template !== current.split('<script')[0]) changedBindings++
  checked++
}
console.log(`PASS: ${checked} Vue templates preserve visible structure, labels, classes and styles; ${changedBindings} templates only change data/event/paging bindings.`)
