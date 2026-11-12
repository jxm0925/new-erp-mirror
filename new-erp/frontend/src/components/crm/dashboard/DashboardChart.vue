<template>
  <div class="dashboard-chart">
    <finance-chart :option="option" :empty="!points.length" :empty-text="emptyText" :label="title" :height="height" @chart-click="select" />
    <div class="dashboard-chart-accessible"><button v-for="(row,index) in points" :key="index" type="button" @click="$emit('select', row, series[0] && series[0].key)">{{ row.label || row.name }}，查看明细</button></div>
  </div>
</template>
<script>
import FinanceChart from '@/components/finance/FinanceChart.vue'
import { use } from 'echarts/core'
import { FunnelChart } from 'echarts/charts'
use([FunnelChart])

const colors = ['#008b4b', '#64c977', '#a6e5a9', '#c5efbf', '#e1f7cf']
export default {
  name: 'DashboardChart',
  components: { FinanceChart },
  props: {
    title: String,
    rows: { type: Array, default: () => [] },
    series: { type: Array, default: () => [{ key: 'count', label: '数量' }] },
    type: { type: String, default: 'bar' },
    vertical: Boolean,
    height: { type: Number, default: 240 },
    emptyText: { type: String, default: '暂无统计数据' }
  },
  computed: {
    points() { return this.rows.filter(row => this.series.some(item => row[item.key] !== null && row[item.key] !== undefined && Number.isFinite(Number(row[item.key])))) },
    option() {
      const labels = this.points.map(row => row.label || row.name || '未填写')
      const base = { color: colors, animation: false, aria: { enabled: true }, textStyle: { fontFamily: 'Microsoft YaHei, sans-serif', color: '#1e293b', fontSize: 12 }, tooltip: { trigger: 'axis', confine: true } }
      if (this.type === 'funnel') return { ...base, tooltip: { trigger: 'item', confine: true }, series: [{ type: 'funnel', sort: 'none', left: '27%', right: '15%', top: 8, bottom: 6, minSize: '12%', gap: 2, label: { show: true, position: 'left', color: '#1e293b', formatter: '{b}' }, labelLine: { show: false }, data: this.points.map((row,index) => ({ name: labels[index], value: row[this.series[0].key], itemStyle: { color: colors[Math.min(Math.floor(index / 2), colors.length - 1)] }, label: { formatter: `${labels[index]}  ${row[this.series[0].key]}` } })) }] }
      const category = { type: 'category', data: labels, inverse: !this.vertical, axisTick: { show: false }, axisLine: { lineStyle: { color: '#cbd5e1' } }, axisLabel: { color: '#1e293b', interval: 0 } }
      const value = { type: 'value', minInterval: 1, axisLabel: { color: '#475569' }, splitLine: { lineStyle: { color: '#f1f5f9' } } }
      return { ...base, legend: { show: this.series.length > 1, top: 0, itemWidth: 10, itemHeight: 10, textStyle: { color: '#334155', fontSize: 12 } }, grid: { left: 8, right: 35, top: this.series.length > 1 ? 35 : 10, bottom: 10, containLabel: true }, xAxis: this.vertical ? category : value, yAxis: this.vertical ? value : category, series: this.series.map((item,index) => ({ name: item.label, type: 'bar', barMaxWidth: 20, barGap: '12%', itemStyle: { color: colors[index % colors.length] }, label: { show: true, position: this.vertical ? 'top' : 'right', color: '#1e293b', fontSize: 12 }, data: this.points.map(row => row[item.key] === null || row[item.key] === undefined ? null : Number(row[item.key])) })) }
    }
  },
  methods: { select(point) { if (this.points[point.dataIndex]) this.$emit('select', this.points[point.dataIndex], (this.series[point.seriesIndex] || {}).key) } }
}
</script>
