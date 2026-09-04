const fs = require('fs')
const path = require('path')
const compiler = require('vue-template-compiler')

const root = path.resolve(__dirname, '..')
const files = [
  'src/api/erp/production.js',
  'src/main.js',
  'src/App.vue',
  'src/views/erp/production/ProductionDemandList.vue',
  'src/views/erp/production/ProductionDemandDetail.vue',
  'src/views/erp/production/WorkOrderList.vue',
  'src/views/erp/production/WorkOrderDetail.vue',
]

function fail(file, message) {
  console.error(`${file}: ${message}`)
  process.exitCode = 1
}

function lintJavaScript(file, source) {
  const withoutImports = source.split('\n').filter((line) => !line.trim().startsWith('import ')).join('\n')
  const executable = withoutImports
    .replace(/export\s+default\s+/, 'const __component = ')
    .replace(/export\s+(?=(const|function|class)\b)/g, '')
  try {
    new Function(executable)
  } catch (error) {
    fail(file, `JavaScript parse failed: ${error.message}`)
  }
}

for (const relative of files) {
  const file = path.join(root, relative)
  const source = fs.readFileSync(file, 'utf8')
  if (source.includes('\uFFFD')) fail(relative, 'contains U+FFFD')
  if (source.includes('\0')) fail(relative, 'contains NUL')
  if (file.endsWith('.vue')) {
    const descriptor = compiler.parseComponent(source)
    if (!descriptor.template) fail(relative, 'missing template')
    const compiled = compiler.compile(descriptor.template.content)
    for (const error of compiled.errors) fail(relative, `template: ${error}`)
    if (descriptor.script) lintJavaScript(relative, descriptor.script.content)
  } else {
    lintJavaScript(relative, source)
  }
}

if (!process.exitCode) console.log(`Production lint passed (${files.length} files).`)
