<template>
  <section class="operation-output-plan" v-loading="loading">
    <div class="operation-output-heading"><h3>工序产出计划</h3><div><el-tag v-if="plan" :type="plan.status === 'frozen' ? 'success' : 'info'" size="small">{{ planStatus }}</el-tag><el-button size="small" icon="el-icon-refresh" @click="load">刷新预检</el-button></div></div>
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <template v-if="plan">
      <p v-if="plan.reference_basis" class="operation-reference">数量基准：{{ plan.reference_basis.reference_item_name || plan.reference_basis.item_name || '路线参考产出' }} {{ quantity(plan.reference_basis.reference_base_qty) }} {{ plan.reference_basis.reference_base_unit_name || plan.reference_basis.base_unit_name || '' }}</p>
      <el-alert v-for="(issue,index) in messages" :key="`${issue.code}-${index}`" :title="issueTitle(issue)" type="warning" :closable="false" class="plan-issue" />
      <article v-for="operation in plan.operations || []" :key="operation.routing_operation_id" class="operation-output-card">
        <h4>{{ operation.sequence }} · {{ operation.operation_name || '工序' }}</h4>
        <div v-for="row in operation.outputs || []" :key="row.operation_output_line_uuid" class="operation-output-row">
          <div class="operation-output-item"><strong>{{ row.item_code || row.item_code_snapshot }}</strong><span>{{ row.item_name || row.item_name_snapshot }}</span><small>{{ row.spec || row.spec_snapshot || '—' }}</small><el-tag size="mini" :type="row.output_scope === 'intermediate' ? 'info' : 'success'">{{ row.output_scope === 'intermediate' ? '工序中间产出' : '工单产出' }}</el-tag></div>
          <div><label>产出类型</label>{{ outputRole(row.output_role) }}</div>
          <div><label>计划数量</label>{{ quantity(row.planned_base_qty) }} {{ row.base_unit_name || row.base_unit_name_snapshot }}</div>
          <div><label>检验要求</label>{{ qualityMode(row.quality_mode) }}</div>
          <div><label>完成去向</label>{{ outputMode(row.output_mode) }}</div>
          <div><label>不入库可继续</label>{{ row.allow_continue_without_warehouse === true ? '是' : row.allow_continue_without_warehouse === false ? '否' : '—' }}</div>
        </div>
        <el-empty v-if="!(operation.outputs || []).length" :image-size="40" description="该工序未指定独立产出" />
      </article>
      <el-empty v-if="!loading && !(plan.operations || []).length" :image-size="55" description="尚无工序产出计划" />
    </template>
  </section>
</template>

<script>
import { getWorkOrderOutputPlanPreview } from '../../../api/erp/production-output-plans'
export default {
  name:'WorkOrderOperationOutputPlan',
  props:{workOrder:{type:Object,required:true}},
  data:() => ({ loading:false,error:'',plan:null,loadSequence:0 }),
  computed:{
    planStatus () { return ({frozen:'已冻结',legacy_snapshot:'历史快照',preview:'计划预检'})[this.plan?.status] || '—' },
    messages () {
      const rows=[...(this.plan?.issues || []),...(this.plan?.execution_blockers || [])]
      return Array.from(new Map(rows.filter(row=>row && row.message).map(row=>[
        [row.code,row.routing_operation_id || '',row.output_rule_key || '',row.details?.work_order_output_line_uuid || '',row.details?.item_id || '',row.message].join(':'),row
      ])).values())
    }
  },
  watch:{'workOrder.business_version':{immediate:true,handler(){if(this.workOrder.id)this.load()}}},
  beforeDestroy(){this.loadSequence++},
  methods:{
    issueTitle(issue){
      const operation=(this.plan?.operations || []).find(row=>Number(row.routing_operation_id)===Number(issue.routing_operation_id))
      const output=(operation?.outputs || []).find(row=>issue.output_rule_key ? row.output_rule_key===issue.output_rule_key : Number(row.item_id)===Number(issue.details?.item_id))
      const context=[operation ? `${operation.sequence} · ${operation.operation_name || '工序'}` : '',output ? output.item_name || output.item_code || '' : ''].filter(Boolean).join('，')
      const details=issue.details || {}
      const expected=details.planned_base_qty ?? details.executor_target_base_qty
      const amounts=details.rule_base_qty!==undefined && expected!==undefined ? `规则计算 ${this.quantity(details.rule_base_qty)}，计划 ${this.quantity(expected)}${output?.base_unit_name ? ` ${output.base_unit_name}` : ''}` : ''
      return `${context ? `${context}：` : ''}${issue.message}${amounts ? `（${amounts}）` : ''}`
    },
    async load(){
      const sequence=++this.loadSequence; const id=this.workOrder.id
      if(!id)return
      this.loading=true; this.error=''; this.plan=null
      try{const response=await getWorkOrderOutputPlanPreview(id);if(sequence===this.loadSequence && id===this.workOrder.id)this.plan=response.data.data}
      catch(error){if(sequence===this.loadSequence)this.error=error.userMessage || '工序产出计划读取失败'}
      finally{if(sequence===this.loadSequence)this.loading=false}
    },
    quantity(value){return value===null || value===undefined || value==='' ? '—' : String(value).replace(/(\.\d*?)0+$/,'$1').replace(/\.$/,'')},
    outputMode(value){return ({flow_only:'直接交接',warehouse_optional:'可选入库',warehouse_required:'合格后入库'})[value] || '—'},
    outputRole(value){return ({product:'产品',by_product:'副产品'})[value] || '—'},
    qualityMode(value){return ({none:'无',required:'必须检验'})[value] || '—'}
  }
}
</script>

<style scoped>
.operation-output-plan {
  margin-top: 24px;
  padding-top: 20px;
  border-top: 1px solid #e2e8f0;
  box-sizing: border-box;
  min-width: 0;
}
.operation-output-heading {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 16px;
}
.operation-output-heading h3 {
  margin: 0;
}
.operation-output-heading > div {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}
.operation-reference {
  color: #475569;
  font-size: 13px;
  overflow-wrap: anywhere;
}
.plan-issue {
  margin-bottom: 10px;
}
.operation-output-card {
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  margin-top: 12px;
  padding: 14px;
  box-sizing: border-box;
  min-width: 0;
}
.operation-output-card h4 {
  margin: 0 0 12px;
  font-size: 14px;
}
.operation-output-row {
  display: grid;
  grid-template-columns: minmax(0, 2fr) repeat(5, minmax(0, 1fr));
  align-items: start;
  gap: 12px;
  padding: 10px 0;
  border-top: 1px solid #f1f5f9;
}
.operation-output-row > div {
  min-width: 0;
  overflow-wrap: anywhere;
  font-size: 13px;
}
.operation-output-row label {
  display: block;
  color: #64748b;
  font-size: 12px;
  margin-bottom: 6px;
}
.operation-output-item {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 5px;
}
.operation-output-item small {
  color: #64748b;
}
.operation-output-item .el-tag {
  height: auto;
  white-space: normal;
}
@media (max-width: 780px) {
  .operation-output-row {
    grid-template-columns: minmax(0, 1fr);
  }
  .operation-output-card {
    padding: 12px;
  }
}
</style>
