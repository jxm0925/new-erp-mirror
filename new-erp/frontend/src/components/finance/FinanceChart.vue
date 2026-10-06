<template>
  <div class="finance-chart" :style="chartStyle" role="img" :aria-label="label">
    <div v-show="!empty" ref="plot" class="finance-chart__plot" />
    <div v-if="empty" class="finance-chart__empty">{{ emptyText }}</div>
  </div>
</template>

<script>
import { init, use } from 'echarts/core'
import { LineChart, BarChart, PieChart } from 'echarts/charts'
import { TitleComponent, TooltipComponent, GridComponent, LegendComponent, AriaComponent } from 'echarts/components'
import { SVGRenderer } from 'echarts/renderers'

use([LineChart, BarChart, PieChart, TitleComponent, TooltipComponent, GridComponent, LegendComponent, AriaComponent, SVGRenderer])

export default {
  name: 'FinanceChart',
  props: {
    option: { type: Object, required: true },
    empty: { type: Boolean, default: false },
    emptyText: { type: String, default: '暂无统计数据' },
    label: { type: String, default: '财务统计图表' },
    height: { type: Number, default: 300 },
  },
  computed: {
    chartStyle() { return { height: `${Number.isFinite(this.height) && this.height >= 0 ? this.height : 300}px` } },
  },
  watch: {
    option: { deep: true, handler() { this._optionDirty = true; this.queueChartUpdate() } },
    empty() {
      this._optionDirty = true
      if (this.empty) this.disposeChart()
      this.queueChartUpdate()
    },
    height() { this.queueChartUpdate() },
  },
  created() {
    // ECharts 实例不放入 data，避免 Vue 2 遍历并观测内部对象。
    this._chartInstance = null
    this._chartWidth = 0
    this._chartHeight = 0
    this._chartMounted = false
    this._chartDestroyed = false
    this._updateQueued = false
    this._optionDirty = true
    this._resizeObserver = null
    this._windowResizeListening = false
    this._resizeHandler = () => this.queueChartUpdate()
    this._clickHandler = point => {
      if (!this._chartDestroyed && !this.empty) this.$emit('chart-click', point)
    }
  },
  mounted() {
    this._chartMounted = true
    if (typeof ResizeObserver === 'function') {
      this._resizeObserver = new ResizeObserver(this._resizeHandler)
      this._resizeObserver.observe(this.$el)
    } else if (typeof window !== 'undefined') {
      window.addEventListener('resize', this._resizeHandler)
      this._windowResizeListening = true
    }
    this.queueChartUpdate()
  },
  beforeDestroy() {
    this._chartDestroyed = true
    this._chartMounted = false
    this._resizeObserver?.disconnect()
    this._resizeObserver = null
    if (this._windowResizeListening && typeof window !== 'undefined') window.removeEventListener('resize', this._resizeHandler)
    this._windowResizeListening = false
    this.disposeChart()
  },
  methods: {
    queueChartUpdate() {
      if (!this._chartMounted || this._chartDestroyed || this._updateQueued) return
      this._updateQueued = true
      this.$nextTick(() => {
        this._updateQueued = false
        if (!this._chartMounted || this._chartDestroyed) return
        this.updateChart()
      })
    },
    updateChart() {
      if (!this._chartMounted || this._chartDestroyed) return
      if (this.empty) { this.disposeChart(); return }
      const container = this.$refs.plot
      const width = container?.clientWidth
      const height = container?.clientHeight
      if (!Number.isFinite(width) || !Number.isFinite(height) || width <= 0 || height <= 0) return

      if (this._chartInstance?.isDisposed()) this._chartInstance = null
      if (!this._chartInstance) {
        this._chartInstance = init(container, null, { renderer: 'svg', width, height })
        this._chartInstance.on('click', this._clickHandler)
        this._chartWidth = width
        this._chartHeight = height
        this._optionDirty = true
      } else if (width !== this._chartWidth || height !== this._chartHeight) {
        this._chartInstance.resize({ width, height })
        this._chartWidth = width
        this._chartHeight = height
      }
      if (this._optionDirty) {
        this._chartInstance.setOption(this.option, { notMerge: true })
        this._optionDirty = false
      }
    },
    disposeChart() {
      const chart = this._chartInstance
      this._chartInstance = null
      this._chartWidth = 0
      this._chartHeight = 0
      this._optionDirty = true
      if (chart && !chart.isDisposed()) {
        chart.off('click', this._clickHandler)
        chart.dispose()
      }
    },
  },
}
</script>

<style scoped>
.finance-chart {
  position: relative;
  width: 100%;
  min-width: 0;
  max-width: 100%;
  overflow: hidden;
  box-sizing: border-box;
}
.finance-chart__plot {
  width: 100%;
  height: 100%;
  min-width: 0;
}
.finance-chart__empty {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
  min-width: 0;
  padding: 16px;
  color: #8a95a0;
  font-size: 13px;
  text-align: center;
  overflow-wrap: anywhere;
}
</style>
