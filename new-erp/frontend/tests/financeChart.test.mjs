import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { createRequire } from 'node:module'

const require = createRequire(import.meta.url)
const compiler = require('vue-template-compiler')
const source = await readFile(new URL('../src/components/finance/FinanceChart.vue', import.meta.url), 'utf8')
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/^import .*$/gm, '').replace('export default', 'return')
const featureNames = ['LineChart', 'BarChart', 'PieChart', 'TitleComponent', 'TooltipComponent', 'GridComponent', 'LegendComponent', 'AriaComponent', 'SVGRenderer']

function makeChart({ width = 700, height = 300, useObserver = true, empty = false, option = { series: [] } } = {}) {
  const instances = [], observers = [], ticks = [], registrations = [], events = []
  const listeners = new Map()
  const dom = { clientWidth: width, clientHeight: height }
  class Observer {
    constructor(callback) { this.callback = callback; this.disconnected = false; observers.push(this) }
    observe(node) { this.node = node }
    disconnect() { this.disconnected = true }
  }
  const dependencies = {
    ...Object.fromEntries(featureNames.map(name => [name, name])),
    use: features => registrations.push(features),
    init: (container, theme, opts) => {
      const handlers = new Map()
      const chart = {
        container, theme, opts, handlers, options: [], sizes: [], disposed: false, offCalls: [], disposeCount: 0,
        on: (event, handler) => handlers.set(event, handler),
        off: (event, handler) => { chart.offCalls.push([event, handler]); if (handlers.get(event) === handler) handlers.delete(event) },
        setOption: (value, options) => chart.options.push([value, options]),
        resize: size => chart.sizes.push(size),
        dispose: () => { chart.disposed = true; chart.disposeCount++ },
        isDisposed: () => chart.disposed,
      }
      instances.push(chart)
      return chart
    },
    ResizeObserver: useObserver ? Observer : undefined,
    window: { addEventListener: (name, handler) => listeners.set(name, handler), removeEventListener: (name, handler) => { if (listeners.get(name) === handler) listeners.delete(name) } },
  }
  const options = new Function(...Object.keys(dependencies), script)(...Object.values(dependencies))
  const vm = { option, empty, emptyText: '暂无统计数据', label: '收付款趋势', height, $refs: { plot: dom }, $el: {}, $emit: (...args) => events.push(args), $nextTick: callback => ticks.push(callback) }
  Object.entries(options.methods).forEach(([name, fn]) => { vm[name] = fn.bind(vm) })
  Object.entries(options.computed).forEach(([name, get]) => Object.defineProperty(vm, name, { get: () => get.call(vm) }))
  options.created.call(vm)
  const flush = () => { while (ticks.length) ticks.shift()() }
  const mount = () => options.mounted.call(vm)
  const destroy = () => options.beforeDestroy.call(vm)
  const changeOption = next => { vm.option = next; options.watch.option.handler.call(vm) }
  const changeEmpty = next => { vm.empty = next; options.watch.empty.call(vm) }
  return { vm, options, instances, observers, ticks, registrations, events, listeners, dom, flush, mount, destroy, changeOption, changeEmpty }
}

test('FinanceChart 模板可编译，按需注册图表组件及 SVG 渲染器，实例不进入响应式 data', () => {
  assert.deepEqual(compiler.compile(compiler.parseComponent(source).template.content).errors, [])
  const chart = makeChart()
  assert.deepEqual(chart.registrations, [featureNames])
  assert.equal(chart.options.data, undefined)
  assert.equal(chart.options.props.height.default, 300)
  assert.match(source, /role="img"\s+:aria-label="label"/)
  assert.doesNotMatch(source, /from ['"]echarts['"]/)
})

test('挂载后等待 DOM 更新且只初始化一次，用 notMerge 完整替换图表数据', () => {
  const chart = makeChart({ option: { series: [{ name: '收款', data: [12] }] } })
  chart.mount(); chart.vm.queueChartUpdate(); assert.equal(chart.instances.length, 0); assert.equal(chart.ticks.length, 1)
  chart.flush()
  assert.equal(chart.instances.length, 1)
  assert.deepEqual(chart.instances[0].opts, { renderer: 'svg', width: 700, height: 300 })
  assert.deepEqual(chart.instances[0].options, [[chart.vm.option, { notMerge: true }]])
  chart.changeOption({ series: [{ name: '付款', data: [5] }] }); chart.flush()
  assert.equal(chart.instances.length, 1); assert.equal(chart.instances[0].options.length, 2)
  assert.deepEqual(chart.instances[0].options[1], [chart.vm.option, { notMerge: true }])
  assert.equal(chart.options.watch.option.deep, true)
})

test('零尺寸挂载不初始化，容器恢复尺寸后初始化；侧栏收缩触发 resize', () => {
  const chart = makeChart({ width: 0, height: 0 })
  chart.mount(); chart.flush(); assert.equal(chart.instances.length, 0)
  assert.equal(chart.observers[0].node, chart.vm.$el); assert.equal(chart.listeners.size, 0)
  chart.dom.clientWidth = 640; chart.dom.clientHeight = 300; chart.observers[0].callback(); chart.flush()
  assert.equal(chart.instances.length, 1)
  chart.dom.clientWidth = 480; chart.observers[0].callback(); chart.flush()
  assert.deepEqual(chart.instances[0].sizes, [{ width: 480, height: 300 }])
  chart.observers[0].callback(); chart.flush(); assert.equal(chart.instances[0].sizes.length, 1)
})

test('空态不生成示例图，清除旧实例并以最新 option 恢复', () => {
  const chart = makeChart({ empty: true })
  chart.mount(); chart.flush(); assert.equal(chart.instances.length, 0)
  chart.changeEmpty(false); chart.flush(); const first = chart.instances[0]
  chart.changeEmpty(true)
  assert.equal(first.disposed, true); assert.equal(chart.vm._chartInstance, null); assert.equal(first.handlers.size, 0)
  chart.changeOption({ series: [{ data: [9] }] }); chart.flush(); assert.equal(chart.instances.length, 1)
  chart.changeEmpty(false); chart.flush()
  assert.equal(chart.instances.length, 2); assert.deepEqual(chart.instances[1].options, [[chart.vm.option, { notMerge: true }]])
})

test('无 ResizeObserver 时注册 window resize，并在销毁时移除监听与实例', () => {
  const chart = makeChart({ useObserver: false })
  chart.mount(); chart.flush(); const instance = chart.instances[0]
  assert.equal(chart.listeners.size, 1)
  chart.dom.clientWidth = 420; chart.listeners.get('resize')(); chart.flush()
  assert.deepEqual(instance.sizes, [{ width: 420, height: 300 }])
  chart.destroy()
  assert.equal(chart.listeners.size, 0); assert.equal(instance.disposed, true); assert.equal(instance.disposeCount, 1)
})

test('销毁发生在延迟 nextTick 前时，不再初始化；迟到观察器回调也不重建', () => {
  const chart = makeChart()
  chart.mount(); chart.destroy(); chart.flush(); chart.observers[0].callback(); chart.flush()
  assert.equal(chart.instances.length, 0); assert.equal(chart.observers[0].disconnected, true); assert.equal(chart.ticks.length, 0)
})

test('销毁既有图表会解绑点击与尺寸观察器，清理可重复执行', () => {
  const chart = makeChart()
  chart.mount(); chart.flush(); const instance = chart.instances[0]
  const click = instance.handlers.get('click'); const point = { name: '供应商甲', value: 90, seriesType: 'bar' }
  click(point); assert.deepEqual(chart.events, [['chart-click', point]])
  chart.destroy(); chart.destroy(); click(point)
  assert.equal(chart.events.length, 1); assert.equal(chart.observers[0].disconnected, true); assert.equal(instance.handlers.size, 0); assert.equal(instance.disposeCount, 1)
})

test('隐藏期间保留待更新 option，重新可见再绘制；高度变化在 DOM 更新后 resize', () => {
  const chart = makeChart()
  chart.mount(); chart.flush(); const instance = chart.instances[0]
  chart.dom.clientWidth = 0; chart.changeOption({ series: [{ data: [77] }] }); chart.flush()
  assert.equal(instance.options.length, 1)
  chart.dom.clientWidth = 500; chart.dom.clientHeight = 240; chart.vm.height = 240; chart.options.watch.height.call(chart.vm)
  assert.deepEqual(chart.vm.chartStyle, { height: '240px' }); chart.flush()
  assert.deepEqual(instance.sizes, [{ width: 500, height: 240 }]); assert.equal(instance.options.length, 2)
  chart.vm.height = Number.NaN; assert.deepEqual(chart.vm.chartStyle, { height: '300px' })
})
