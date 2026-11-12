<template>
  <section class="dashboard-metrics" :style="{ '--metric-columns': columns || items.length }">
    <button v-for="item in items" :key="item.key" type="button" class="dashboard-metric" :class="item.tone" :disabled="!item.drilldown || item.value === null || item.value === undefined" @click="$emit('drilldown', item.drilldown, item.label)">
      <span class="metric-icon"><i :class="item.icon || 'el-icon-data-analysis'" /></span>
      <span class="metric-content"><span class="metric-label">{{ item.label }}</span><strong>{{ item.value === null || item.value === undefined ? '—' : item.value }}</strong><small>{{ item.caption }}</small></span>
    </button>
  </section>
</template>
<script>
export default {
  name: 'DashboardMetrics',
  props: { items: { type: Array, default: () => [] }, columns: Number }
}
</script>
