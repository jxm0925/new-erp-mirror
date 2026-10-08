<template>
  <div>
    <el-dialog title="工序产出规则" :visible.sync="visible" width="1100px" custom-class="routing-output-rules-dialog" append-to-body :close-on-click-modal="false" @closed="reset">
      <p class="reference-basis">数量基准：{{ referenceName }}，每 1 {{ referenceUnit }}的产出数量</p>
      <div class="rule-actions"><el-button v-if="!readOnly" size="small" type="success" icon="el-icon-plus" @click="openPicker">添加产出物料</el-button></div>
      <article v-for="row in draft" :key="row.output_rule_key" class="rule-card">
        <div class="rule-item"><strong>{{ row.item_code_snapshot || row.item_code }}</strong><span>{{ row.item_name_snapshot || row.item_name }}</span><small>{{ row.spec_snapshot || row.spec || '—' }}</small></div>
        <div class="rule-fields">
          <label><span>产出类型</span><el-select v-model="row.output_role" size="small" :disabled="readOnly" popper-class="routing-output-rules-select"><el-option label="产品" value="product" /><el-option label="副产品" value="by_product" /></el-select></label>
          <label><span>每参考单位产出</span><el-input v-model.trim="row.base_qty_per_reference_unit" size="small" inputmode="decimal" maxlength="29" :aria-label="`${itemName(row)}每参考单位产出`" :disabled="readOnly" placeholder="请填写比例"><template slot="append">{{ unitName(row) }}</template></el-input></label>
          <label><span>检验要求</span><el-select v-model="row.quality_mode" size="small" :disabled="readOnly" popper-class="routing-output-rules-select"><el-option label="无" value="none" /><el-option label="必须检验" value="required" /></el-select></label>
          <label><span>完成去向</span><el-select :value="row.output_mode" size="small" :disabled="readOnly" popper-class="routing-output-rules-select" @change="changeMode(row,$event)"><el-option label="直接交接" value="flow_only" /><el-option label="可选入库" value="warehouse_optional" /><el-option label="合格后入库" value="warehouse_required" /></el-select></label>
          <label><span>不入库可继续</span><el-switch v-model="row.allow_continue_without_warehouse" :disabled="readOnly || row.output_mode === 'warehouse_required'" :aria-label="`${itemName(row)}不入库可继续`" active-color="#008b4b" /></label>
          <label class="rule-remark"><span>备注</span><el-input v-model.trim="row.remark" size="small" maxlength="500" :aria-label="`${itemName(row)}产出规则备注`" :disabled="readOnly" /></label>
        </div>
        <el-button v-if="!readOnly" type="text" class="remove-rule" icon="el-icon-delete" @click="remove(row)">移除</el-button>
      </article>
      <el-empty v-if="!draft.length" :image-size="55" description="未设置产出规则" />
      <span slot="footer"><el-button @click="visible=false">{{ readOnly ? '关闭' : '取消' }}</el-button><el-button v-if="!readOnly" type="success" @click="confirm">确认规则</el-button></span>
    </el-dialog>
    <purchase-item-picker ref="picker" dialog-class="routing-output-item-picker" @select-multiple="addItems" />
  </div>
</template>

<script>
import PurchaseItemPicker from '@/components/purchase/PurchaseItemPicker.vue'
const uuid = () => window.crypto && window.crypto.randomUUID ? window.crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g,c => { const r=Math.random()*16|0; return (c==='x'?r:(r&3|8)).toString(16) })
const validRatio = value => /^\d{1,20}(?:\.\d{1,8})?$/.test(String(value)) && /[1-9]/.test(String(value))
export default {
  name:'RoutingOutputRulesDialog',
  components:{ PurchaseItemPicker },
  data:() => ({ visible:false,readOnly:false,draft:[],operationKey:null,referenceName:'',referenceUnit:'',defaultQuality:'none',defaultMode:'flow_only' }),
  methods:{
    open ({ operation,referenceItem,readOnly=false }) {
      this.reset()
      this.operationKey=operation.key; this.readOnly=readOnly
      // Saved ratios are expressed in their saved reference unit. A renamed master unit
      // must not make the same ratio appear to have a new denominator.
      const savedReference=(operation.output_rules || []).find(row => Number(row.reference_item_id)>0 && Number(row.reference_item_id)===Number(referenceItem?.id))
      this.referenceName=savedReference?.reference_item_name_snapshot || referenceItem?.item_name || '未选择参考物料'
      this.referenceUnit=savedReference?.reference_base_unit_name_snapshot || this.unitName(referenceItem || {})
      this.defaultQuality=operation.quality_mode || 'none'; this.defaultMode=operation.output_mode || 'flow_only'
      this.draft=JSON.parse(JSON.stringify(operation.output_rules || [])); this.visible=true
    },
    reset () { this.draft=[]; this.operationKey=null; this.referenceName=''; this.referenceUnit=''; if(this.$refs.picker)this.$refs.picker.visible=false },
    unitName (row) { const unit=row.unit?.standard_unit || row.unit?.standardUnit || row.unit; return row.base_unit_name_snapshot || row.base_unit_name || unit?.unit_name || unit?.symbol || '—' },
    itemName (row) { return row.item_name_snapshot || row.item_name || row.item_code_snapshot || row.item_code || '产出物料' },
    openPicker () {
      if(this.readOnly)return
      this.$refs.picker.open({ multiple:true,selected:[],params:{ management_scope:'factory',status:'enabled',is_purchase_item:undefined,is_stock_item:1 },title:'选择工序产出物料' })
    },
    addItems (items) {
      if(!this.visible || this.readOnly)return
      for(const item of items)if(!this.draft.some(row => Number(row.item_id)===Number(item.id)))this.draft.push({
        output_rule_key:uuid(),item_id:item.id,item_code:item.item_code,item_name:item.item_name,spec:item.spec,unit:item.unit,
        base_unit_name:this.unitName(item),output_role:'product',base_qty_per_reference_unit:'',
        quality_mode:this.defaultQuality,output_mode:this.defaultMode,allow_continue_without_warehouse:this.defaultMode!=='warehouse_required',remark:''
      })
    },
    remove (row) { if(!this.readOnly)this.draft=this.draft.filter(candidate => candidate.output_rule_key!==row.output_rule_key) },
    changeMode (row,mode) {
      if(this.readOnly)return
      row.output_mode=mode
      if(mode==='warehouse_required')row.allow_continue_without_warehouse=false
    },
    confirm () {
      if(this.readOnly)return
      if(this.draft.some(row => !validRatio(row.base_qty_per_reference_unit)))return this.$message.warning('产出比例必须大于零，最多八位小数；未确认的数量请先核实。')
      if(new Set(this.draft.map(row => Number(row.item_id))).size!==this.draft.length)return this.$message.warning('同一道工序的产出物料不能重复。')
      this.$emit('confirm',{ operationKey:this.operationKey,rules:JSON.parse(JSON.stringify(this.draft)) }); this.visible=false
    }
  }
}
</script>

<style>
.routing-output-rules-dialog {
  width: min(1100px, calc(100vw - 32px)) !important;
  max-width: calc(100vw - 32px);
  margin-top: 5vh !important;
  display: flex;
  flex-direction: column;
  max-height: 85vh;
}
.routing-output-rules-dialog .el-dialog__body {
  overflow-y: auto;
  min-height: 0;
  padding: 16px 20px;
}
.routing-output-rules-dialog .reference-basis {
  margin: 0 0 16px;
  color: #475569;
  overflow-wrap: anywhere;
}
.routing-output-rules-dialog .rule-actions {
  display: flex;
  flex-wrap: wrap;
  margin-bottom: 14px;
}
.routing-output-rules-dialog .rule-card {
  position: relative;
  box-sizing: border-box;
  min-width: 0;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 14px;
  margin-bottom: 12px;
}
.routing-output-rules-dialog .rule-item {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 12px;
  padding-right: 65px;
  margin-bottom: 12px;
  overflow-wrap: anywhere;
}
.routing-output-rules-dialog .rule-item small {
  color: #64748b;
}
.routing-output-rules-dialog .rule-fields {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
}
.routing-output-rules-dialog .rule-fields label {
  display: flex;
  flex-direction: column;
  gap: 6px;
  min-width: 0;
}
.routing-output-rules-dialog .rule-fields .el-select {
  width: 100%;
  min-width: 0;
}
.routing-output-rules-dialog .rule-fields label > span {
  color: #64748b;
  font-size: 12px;
}
.routing-output-rules-dialog .rule-remark {
  grid-column: 1 / -1;
}
.routing-output-rules-dialog .remove-rule {
  position: absolute;
  right: 14px;
  top: 8px;
  color: #ef4444;
}
.routing-output-rules-dialog .el-input__inner:focus {
  border-color: #008b4b;
}
.routing-output-rules-select .el-select-dropdown__item.selected {
  color: #008b4b;
}
.routing-output-item-picker {
  margin-top: 5vh !important;
  max-height: 85vh;
  display: flex;
  flex-direction: column;
}
.routing-output-item-picker .el-dialog__body {
  display: flex;
  flex-direction: column;
  min-height: 0;
  overflow: hidden;
}
.routing-output-item-picker .picker-filters,
.routing-output-item-picker .selected-review,
.routing-output-item-picker .picker-footer {
  flex-shrink: 0;
  min-width: 0;
}
.routing-output-item-picker .picker-body {
  min-height: 0;
  overflow-y: auto;
}
.routing-output-item-picker .el-pagination {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 4px 0;
  min-width: 0;
  white-space: normal;
}
.routing-output-item-picker .el-pager {
  display: flex;
  flex-wrap: wrap;
  min-width: 0;
}
@media (max-width: 780px) {
  .routing-output-rules-dialog .rule-fields {
    grid-template-columns: minmax(0, 1fr);
  }
  .routing-output-rules-dialog .el-dialog__body {
    padding: 14px;
  }
}
</style>
