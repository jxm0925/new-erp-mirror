<template>
  <section class="assembly-panel" v-loading="loading">
    <div class="assembly-heading">
      <div><h3>部件生产准备</h3><el-tag v-if="plan" size="small" :type="plan.status==='blocked'?'warning':'success'">{{ statusName }}</el-tag></div>
      <div class="assembly-actions"><el-button size="small" :disabled="busy" @click="load">刷新</el-button><el-button v-if="canPrepare || canRetry || canRecover" size="small" type="success" :loading="busy" @click="prepare">{{ canRecover ? '核对上次生产准备' : (canRetry ? '重试生产准备' : '确认部件生产准备') }}</el-button></div>
    </div>
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <el-alert v-for="(issue,index) in issues" :key="`${issue.code || 'issue'}-${index}`" :title="issue.message || issue" type="warning" :closable="false" />
    <div v-if="components.length" class="assembly-components">
      <article v-for="(row,index) in components" :key="`${row.parent_work_order_id}-${row.bom_item_id}-${index}`" class="assembly-component">
        <div class="component-title"><strong>{{ row.item && row.item.name || '—' }}</strong><span>{{ row.item && row.item.code }}</span><el-tag size="mini">{{ strategyName(row.manufacturing_strategy) }}</el-tag></div>
        <p v-if="row.item && row.item.spec" class="component-spec">{{ row.item.spec }}</p>
        <p class="component-path">{{ componentPath(row) }}</p>
        <dl class="component-facts"><div><dt>需求数量</dt><dd>{{ quantity(row.required_base_qty) }} {{ unit(row) }}</dd></div><div><dt>库存预留</dt><dd>{{ quantity(row.inventory_reserved_base_qty) }} {{ unit(row) }}</dd></div><div><dt>安排生产</dt><dd>{{ quantity(row.production_base_qty) }} {{ unit(row) }}</dd></div><div><dt>部件工单</dt><dd><el-button v-if="row.child_work_order_id" type="text" @click="openChild(row)">{{ row.child_work_order_no }}</el-button><span v-else>—</span></dd></div></dl>
      </article>
    </div>
    <el-empty v-else-if="!loading && !error" :image-size="60" description="当前工单没有需要准备的自制部件" />
  </section>
</template>

<script>
import { getAssemblyPlan, prepareAssembly, pendingAssemblyCommand, executeAssemblyCommand, productionQuantity } from '../../../api/erp/assembly-production'

export default {
  name: 'WorkOrderAssemblyPanel',
  props: { workOrder: { type: Object, required: true } },
  data: () => ({ loading: false, busy: false, plan: null, error: '', pending: null }),
  computed: {
    components () { return this.plan?.components || [] },
    issues () { return [...(this.plan?.issues || []), ...(this.plan?.material_preparation?.issues || [])].filter((issue, index, rows) => rows.findIndex(row => (row.message || row) === (issue.message || issue)) === index) },
    statusName () { return ({ pending: '待准备', preview: '待确认', prepared: '部件已准备', blocked: '准备受阻', not_required: '无需自产部件准备', legacy_snapshot: '历史工单', cancelled: '已取消' })[this.plan?.status] || '—' },
    canPrepare () { return this.plan?.actions?.can_prepare === true },
    canRetry () { return this.plan?.actions?.can_retry === true },
    canRecover () { return !!this.pending && this.$can('production.work_order.view') && this.$can('production.work_order.edit') }
  },
  mounted () { this.load() },
  watch: { 'workOrder.id' () { this.load() } },
  methods: {
    quantity: productionQuantity,
    unit (row) { return row.item?.base_unit_name || row.base_unit_name || '' },
    strategyName (value) { return ({ make: '自制', purchase: '外购', unspecified: '未指定' })[value] || value || '未指定' },
    componentPath (row) {
      const names = [row.item?.name || '—']
      let parent = row.parent_path
      const seen = new Set()
      while (parent && parent !== 'root' && !seen.has(parent)) {
        seen.add(parent)
        const entry = this.components.find(component => component.path_key === parent)
        if (!entry) break
        names.unshift(entry.item?.name || '—'); parent = entry.parent_path
      }
      return names.join(' → ')
    },
    openChild (row) { this.$router.push(`/production/work-orders/${row.child_work_order_id}`) },
    async load () {
      const id = this.workOrder.id
      if (!id) return
      const sequence = this.sequence = (this.sequence || 0) + 1
      this.loading = true; this.error = ''; this.pending = pendingAssemblyCommand(`prepare-${id}`)
      try { const response = await getAssemblyPlan(id); if (sequence === this.sequence) this.plan = response.data.data || null }
      catch (error) { if (sequence === this.sequence) this.error = error.userMessage || '部件生产准备加载失败' }
      finally { if (sequence === this.sequence) this.loading = false }
    },
    async prepare () {
      if (this.busy || (!this.canPrepare && !this.canRetry && !this.canRecover)) return
      const id = this.workOrder.id
      this.busy = true
      try {
        await executeAssemblyCommand(`prepare-${id}`, { expected_version: this.pending?.payload?.expected_version ?? this.plan?.work_order_version }, data => prepareAssembly(id, data))
        this.$message.success('部件生产准备已确认'); await this.load(); this.$emit('updated')
      } catch (error) { this.$message.error(error.userMessage || '生产准备失败，请重试原操作'); await this.load() }
      finally { this.busy = false }
    }
  }
}
</script>

<style scoped>
.assembly-panel {
  color: #33455d;
  min-width: 0;
  padding: 18px 20px;
  background: #fff;
  border: 1px solid #e6ebf0;
  border-radius: 5px;
}
.assembly-heading, .assembly-heading>div, .assembly-actions, .component-title {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
  min-width: 0;
}
.assembly-heading {
  justify-content: space-between;
  margin-bottom: 16px;
}
.assembly-heading h3 {
  font-size: 14px;
  margin: 0;
}
.assembly-panel .el-alert {
  margin-bottom: 10px;
}
.assembly-components {
  display: grid;
  gap: 12px;
}
.assembly-component {
  min-width: 0;
  padding: 14px;
  border: 1px solid #e6ebf0;
  border-radius: 5px;
}
.component-title {
  font-size: 13px;
  overflow-wrap: anywhere;
}
.component-title>span, .component-spec, .component-path {
  color: #728098;
  font-size: 12px;
  overflow-wrap: anywhere;
}
.component-spec, .component-path {
  margin: 8px 0;
  line-height: 1.6;
}
.component-facts {
  display: grid;
  grid-template-columns: repeat(4,minmax(0,1fr));
  gap: 12px;
  margin: 14px 0 0;
}
.component-facts>div {
  min-width: 0;
}
.component-facts dt {
  font-size: 12px;
  color: #728098;
  margin-bottom: 5px;
}
.component-facts dd {
  margin: 0;
  font-size: 13px;
  overflow-wrap: anywhere;
}
.component-facts .el-button {
  padding: 0;
  white-space: normal;
  text-align: left;
  color: #008b4b;
}
@media(max-width:780px) {
  .assembly-panel {
    padding: 12px;
  }
  .component-facts {
    grid-template-columns: repeat(2,minmax(0,1fr));
  }
}
@media(max-width:380px) {
  .component-facts {
    grid-template-columns: minmax(0,1fr);
  }
}
</style>
