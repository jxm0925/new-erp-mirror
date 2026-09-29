<template>
  <div class="pm-table-scroll">
    <table class="pm-material-table">
      <thead><tr><th class="pm-index">序号</th><th>物料信息</th><template v-if="mode === 'plan'"><th>需求数量</th><th>已配数量</th><th>待配数量</th><th>库存来源</th></template><template v-else-if="mode === 'assign' || mode === 'pick'"><th>库存来源</th><th v-if="mode === 'pick'">计划数量</th></template><template v-else-if="mode === 'send'"><th>{{ redelivery ? '拒收数量' : '已拣' }}</th><th>{{ redelivery ? '已补送' : '已分配' }}</th><th>{{ redelivery ? '可补送' : '可配送' }}</th></template><template v-else-if="mode === 'receipt'"><th>配送数量</th><th>实收数量</th><th>拒收数量</th></template><th>{{ lastLabel }}</th><th class="pm-expand" /></tr></thead>
      <tbody>
        <template v-for="(group, index) in groups">
          <tr :key="group.key"><td>{{ index + 1 }}</td><td class="pm-material-name"><strong>{{ group.name }}</strong><small>{{ group.code }}<template v-if="group.spec"> · {{ group.spec }}</template></small></td>
            <template v-if="mode === 'plan'"><td>{{ qty(group.required) }} {{ group.unit }}</td><td>{{ qty(group.allocated) }} {{ group.unit }}</td><td class="pm-warning">{{ qty(group.remaining) }} {{ group.unit }}</td><td><span v-if="group.sources.length > 1">已选 {{ group.sources.length }} 个库存来源</span><span v-else-if="group.sources.length">{{ location(group.sources[0]) }}<small>批次 {{ group.sources[0].batch_no }}</small></span><span v-else>未选择库存</span><el-button type="text" @click="$emit('stock', group)">选择库存</el-button></td></template>
            <template v-else-if="mode === 'assign' || mode === 'pick'"><td>{{ group.sources.length > 1 ? '已选 ' + group.sources.length + ' 个库存来源' : location(group.sources[0]) }}<small v-if="group.sources.length === 1">{{ group.sources[0].batch_no }}</small></td><td v-if="mode === 'pick'">{{ sum(group, 'planned') }} {{ group.unit }}</td></template>
            <template v-else-if="mode === 'send'"><td>{{ sum(group, 'picked') }} {{ group.unit }}</td><td>{{ sum(group, 'allocated') }} {{ group.unit }}</td><td>{{ sum(group, 'available') }} {{ group.unit }}</td></template>
            <template v-else><td>{{ sum(group, 'delivered') }} {{ group.unit }}</td><td>{{ sum(group, 'received') }} {{ group.unit }}</td><td :class="{ 'pm-warning': Number(sum(group, 'rejected')) > 0 }">{{ sum(group, 'rejected') }} {{ group.unit }}</td></template>
            <td class="pm-quantity"><span v-if="mode === 'receipt'">{{ sum(group, 'pending') }} {{ group.unit }}</span><span v-else-if="mode === 'assign'">{{ sum(group, 'planned') }} {{ group.unit }}</span><el-input v-else-if="editable && group.sources.length === 1" v-model="group.sources[0].quantity" size="small" inputmode="decimal" :class="{ 'pm-invalid': invalid(group.sources[0]) }"><span slot="append">{{ group.unit }}</span></el-input><strong v-else>{{ sum(group, 'quantity') }} {{ group.unit }}</strong></td>
            <td><el-button v-if="group.sources.length" type="text" :icon="group.expanded ? 'el-icon-arrow-up' : 'el-icon-arrow-down'" :aria-label="(group.expanded ? '收起' : '展开') + group.name" @click="$set(group, 'expanded', !group.expanded)" /></td>
          </tr>
          <tr v-if="group.expanded && group.sources.length" :key="group.key + '-sources'"><td /><td :colspan="columns - 1" class="pm-sources-cell"><table class="pm-source-table"><thead><tr><th>库位</th><th>批次</th><th v-if="mode === 'plan'">可用数量</th><th v-else-if="mode === 'pick'">计划数量</th><template v-else-if="mode === 'send'"><th>{{ redelivery ? '拒收数量' : '已拣' }}</th><th>{{ redelivery ? '已补送' : '已分配' }}</th><th>{{ redelivery ? '可补送' : '可配送' }}</th></template><template v-else-if="mode === 'receipt'"><th>配送数量</th><th>实收数量</th><th>拒收数量</th></template><th>{{ lastLabel }}</th><th v-if="mode === 'plan'">操作</th><th v-if="mode === 'receipt'">拒收原因</th></tr></thead><tbody>
            <tr v-for="source in group.sources" :key="source.id"><td>{{ location(source) }}</td><td>{{ source.batch_no }}</td><td v-if="mode === 'plan'">{{ qty(source.picking_available_qty) }} {{ group.unit }}</td><td v-else-if="mode === 'pick'">{{ qty(source.planned) }} {{ group.unit }}</td><template v-else-if="mode === 'send'"><td>{{ qty(source.picked) }} {{ group.unit }}</td><td>{{ qty(source.allocated) }} {{ group.unit }}</td><td>{{ qty(source.available) }} {{ group.unit }}</td></template><template v-else-if="mode === 'receipt'"><td>{{ qty(source.delivered) }} {{ group.unit }}</td><td>{{ qty(source.received) }} {{ group.unit }}</td><td>{{ qty(source.rejected) }} {{ group.unit }}</td></template>
              <td class="pm-quantity"><span v-if="mode === 'receipt'">{{ qty(source.pending) }} {{ group.unit }}</span><span v-else-if="mode === 'assign'">{{ qty(source.planned) }} {{ group.unit }}</span><template v-else-if="editable"><el-input v-model="source.quantity" size="small" inputmode="decimal" :class="{ 'pm-invalid': invalid(source) }"><span slot="append">{{ group.unit }}</span></el-input><small v-if="invalid(source)" class="pm-danger">{{ Number(source.quantity) > limit(source) ? '超出可用数量' : '请输入有效数量' }}</small><el-button v-if="source.serialRequired && mode !== 'plan'" type="text" @click="$emit('serials', source)">选择序列号（{{ (source.serials || []).length }}）</el-button></template><span v-else>{{ qty(source.quantity) }} {{ group.unit }}</span></td>
              <td v-if="mode === 'plan'"><el-button type="text" @click="group.sources.splice(group.sources.indexOf(source), 1)">移除</el-button></td><td v-if="mode === 'receipt'">{{ source.rejectReason || '—' }}</td>
            </tr></tbody></table><div v-if="mode === 'plan'" class="pm-source-summary"><el-button type="text" icon="el-icon-plus" @click="$emit('stock', group)">添加库存来源</el-button><strong>合计：{{ sum(group, 'quantity') }} {{ group.unit }}</strong></div></td></tr>
        </template>
        <tr v-if="!groups.length"><td :colspan="columns" class="pm-empty">暂无物料明细</td></tr>
      </tbody>
    </table>
  </div>
</template>
<script>
export default {
  props: { groups: { type: Array, default: () => [] }, mode: { type: String, default: 'plan' }, editable: Boolean, redelivery: Boolean },
  computed: { columns() { return this.mode === 'plan' ? 8 : this.mode === 'assign' ? 5 : this.mode === 'pick' ? 6 : 7 }, lastLabel() { return ({ plan: '本次配料', pick: '实拣数量', assign: '计划配料', send: this.redelivery ? '本次补送' : '本次配送', receipt: '待确认' })[this.mode] } },
  methods: {
    qty(value) { return Number(value || 0).toLocaleString('zh-CN', { maximumFractionDigits: 8, useGrouping: false }) },
    sum(group, field) { return this.qty(group.sources.reduce((sum, row) => sum + (Number(row[field]) || 0), 0)) },
    location(row) { return (row && row.location && (row.location.location_code || row.location.location_name)) || '—' },
    limit(row) { return Number(this.mode === 'plan' ? row.picking_available_qty : this.mode === 'pick' ? row.planned : row.available) },
    invalid(row) { return !/^\d+(\.\d{1,8})?$/.test(String(row.quantity)) || Number(row.quantity) < 0 || (this.mode === 'plan' && Number(row.quantity) === 0) || Number(row.quantity) > this.limit(row) }
  }
}
</script>
