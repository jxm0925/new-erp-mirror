<script>
export default {
  name: 'DashboardWidget',
  props: { configuration: Object, page: String, metricColumns: {type: Number, default: 6} },
  render(h) {
    const item = this.configuration
    if (!item || !item.enabled || item.position !== this.page) return null
    const span = item.width === 'metric' ? 24 / this.metricColumns : {full:24,half:12,third:8}[item.width] || 24
    return h('section', {class: ['dashboard-configured-widget', item.width === 'metric' ? 'dashboard-configured-metric' : 'dashboard-panel', !item.drilldown && 'dashboard-widget-no-drill'], style: {order:item.order, gridColumn:`span ${span}`}, on: {
      '!click': event => this.blockDrill(event), '!keydown': event => {if (['Enter',' '].includes(event.key)) this.blockDrill(event)}
    }}, this.$slots.default)
  },
  methods: {
    blockDrill(event) {
      if (!this.configuration.drilldown && event.target.closest('.dashboard-chart, .dashboard-metric, .el-button--text, .dashboard-reminders button')) {
        event.preventDefault()
        event.stopPropagation()
      }
    }
  }
}
</script>
