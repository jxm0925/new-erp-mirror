<template>
  <div class="technical-panel" v-loading="loading">
    <section class="technical-source">
      <div><label>来源订单</label><strong>{{ source.sales_order_no || source.no || '自主生产' }}</strong></div>
      <div><label>客户</label><strong>{{ source.customer || '—' }}</strong></div>
      <div><label>产出物料</label><strong>{{ product.item_name || product.name || '—' }}</strong></div>
      <div><label>计划数量</label><strong>{{ workOrder.target_qty }} {{ (workOrder.quantity || {}).unit_name }}</strong></div>
      <div><label>资料版本</label><strong>{{ workOrder.technical_version ? `V${workOrder.technical_version} 已确认` : (['DRAFT','WAIT_RELEASE'].includes(workOrder.status) ? '待确认' : '发布时已冻结') }}</strong></div>
    </section>
    <section class="technical-card">
      <h3>生产资料</h3>
      <div class="technical-fields">
        <label><span>BOM版本 <em>*</em></span><el-input :value="bomLabel" readonly :disabled="!editable" @focus="openPicker('boms')"><i slot="suffix" class="el-icon-search" @click="openPicker('boms')" /></el-input></label>
        <label><span>工艺路线 <em>*</em></span><el-input :value="routingLabel" readonly :disabled="!editable" @focus="openPicker('routings')"><i slot="suffix" class="el-icon-search" @click="openPicker('routings')" /></el-input></label>
        <label><span>成品配置 <em v-if="form.output_is_custom">*</em></span><el-input :value="form.output_is_custom ? configLabel(form.output_configuration) : '标准规格'" readonly :disabled="!editable || !form.output_is_custom" @focus="openPicker('configurations')"><i v-if="form.output_is_custom" slot="suffix" class="el-icon-search" @click="openPicker('configurations')" /></el-input></label>
        <label><span>图纸版本 <em v-if="form.output_is_custom">*</em></span><el-input v-model.trim="form.drawing_reference" :disabled="!editable" maxlength="255" /></label>
      </div>
      <WorkOrderTechnicalAttachments v-model="form.attachments" :work-order-id="workOrder.id" :version="workOrder.business_version" :editable="editable" @uploading="attachmentUploading=$event;$emit('saving',$event || saving)" />
      <h3>用料规格</h3>
      <el-table :data="form.materials" border empty-text="请选择 BOM 版本">
        <el-table-column type="index" label="序号" width="75" align="center" />
        <el-table-column label="物料" min-width="180"><template slot-scope="{row}">{{ row.component_item_name }}<small class="material-code">{{ row.component_item_code }}</small></template></el-table-column>
        <el-table-column label="BOM用量" min-width="130"><template slot-scope="{row}">{{ Number(row.per_output_qty) }} {{ row.unit_name }}</template></el-table-column>
        <el-table-column label="配置版本" min-width="220"><template slot-scope="{row}"><el-input v-if="row.is_custom_item" :value="configLabel(row.configuration)" :disabled="!editable" readonly @focus="openPicker('configurations', row)"><i slot="suffix" class="el-icon-search" @click="openPicker('configurations', row)" /></el-input><span v-else>标准规格</span></template></el-table-column>
        <el-table-column label="确认规格" min-width="240"><template slot-scope="{row}">{{ specification(row.configuration) || row.spec || '—' }}</template></el-table-column>
      </el-table>
      <p class="technical-tip"><i class="el-icon-info" /> 技术资料确认后用于发布检查。</p>
      <h3 class="reason-heading">修订原因</h3>
      <el-input v-model.trim="reason" type="textarea" :rows="3" maxlength="500" show-word-limit :disabled="!editable" :placeholder="workOrder.technical_version ? '请输入本次修订原因' : '请输入修订原因（选填）'" />
      <div class="technical-history"><span>历史版本</span><el-button type="text" @click="showHistory">查看历史版本 <i class="el-icon-arrow-right" /></el-button></div>
    </section>
    <el-dialog class="technical-picker" :title="pickerTitle" :visible.sync="picker.visible" width="1080px" append-to-body :close-on-click-modal="false">
      <div class="technical-picker-search"><el-input v-model.trim="picker.keyword" clearable placeholder="编码 / 名称 / 规格" @keyup.enter.native="searchOptions" @clear="searchOptions" /><el-button type="success" icon="el-icon-search" @click="searchOptions">查询</el-button></div>
      <div class="technical-picker-body"><aside><strong>物料分类</strong><el-button type="text" @click="selectCategory(null)">全部分类</el-button><el-tree :data="categories" node-key="id" :props="{label:'category_name',children:'children'}" default-expand-all @node-click="selectCategory" /></aside>
      <el-table :data="picker.rows" v-loading="picker.loading" border height="360" highlight-current-row @row-click="picker.current=$event" @row-dblclick="chooseOption">
        <el-table-column width="50"><template slot-scope="{row}"><el-radio :value="picker.current && picker.current.id" :label="row.id" @change="picker.current=row">&nbsp;</el-radio></template></el-table-column>
        <el-table-column label="编码" min-width="150"><template slot-scope="{row}">{{ row.bom_no || row.routing_no || row.configuration_no }}</template></el-table-column>
        <el-table-column label="名称 / 图纸" min-width="180"><template slot-scope="{row}">{{ row.bom_name || row.routing_name || row.drawing_reference }}</template></el-table-column>
        <el-table-column label="版本" width="100"><template slot-scope="{row}">{{ row.version || row.version_no }}</template></el-table-column>
        <el-table-column v-if="picker.type==='configurations'" label="规格" min-width="210"><template slot-scope="{row}">{{ specification(row) }}</template></el-table-column>
      </el-table></div>
      <div class="technical-picker-footer"><el-pagination small layout="total, prev, pager, next" :page-size="20" :current-page="picker.page" :total="picker.total" @current-change="changeOptionPage" /><div><el-button @click="picker.visible=false">取消</el-button><el-button type="success" :disabled="!picker.current" @click="chooseOption(picker.current)">确定选择</el-button></div></div>
    </el-dialog>
    <el-dialog class="technical-history-dialog" title="生产资料历史版本" :visible.sync="historyVisible" width="1000px" append-to-body>
      <el-table :data="historyRows" border><el-table-column type="expand"><template slot-scope="{row}"><div class="history-details"><p>BOM版本：{{ row.snapshot.bom_snapshot.bom_no }} {{ row.snapshot.bom_snapshot.version }}</p><p>工艺路线：{{ row.snapshot.routing_snapshot.routing_no }} V{{ row.snapshot.routing_snapshot.version }}</p><p>成品配置：{{ configLabel(row.snapshot.output_configuration) || '标准规格' }}</p><p>图纸版本：{{ row.snapshot.drawing_reference || '—' }}</p><WorkOrderTechnicalAttachments :work-order-id="workOrder.id" :value="row.snapshot.attachments || []" /><el-table :data="row.snapshot.materials" border><el-table-column prop="component_item_name" label="物料" /><el-table-column label="配置版本"><template slot-scope="scope">{{ configLabel(scope.row.configuration) || '标准规格' }}</template></el-table-column><el-table-column label="确认规格"><template slot-scope="scope">{{ specification(scope.row.configuration) || scope.row.spec || '—' }}</template></el-table-column></el-table></div></template></el-table-column><el-table-column prop="version_no" label="版本" width="90" /><el-table-column prop="confirmed_at" label="确认时间" min-width="160" /><el-table-column prop="reason" label="修订原因" min-width="240" /></el-table>
      <el-pagination small layout="total, prev, pager, next" :page-size="20" :current-page="historyPage" :total="historyTotal" @current-change="loadHistory" />
    </el-dialog>
  </div>
</template>

<script>
import { getWorkOrderTechnical, confirmWorkOrderTechnical, listWorkOrderTechnicalVersions } from '../../../api/erp/production'
import { getItemCategoryTree } from '../../../api/erp/master'
import WorkOrderTechnicalAttachments from './WorkOrderTechnicalAttachments.vue'
export default {
  components: { WorkOrderTechnicalAttachments },
  name: 'WorkOrderTechnicalPanel', props: { workOrder: { type: Object, required: true } },
  data: () => ({ loading:false, saving:false, attachmentUploading:false, pending:null, form:{materials:[]}, reason:'', categories:[], picker:{visible:false,type:'boms',keyword:'',category_id:null,page:1,rows:[],total:0,current:null,target:null,loading:false}, historyVisible:false, historyRows:[],historyPage:1,historyTotal:0 }),
  computed: {
    source() { return this.workOrder.source || {} }, product() { return this.workOrder.product || {} },
    editable() { return !this.pending && ['DRAFT','WAIT_RELEASE'].includes(this.workOrder.status) && this.$can('production.technical.prepare') },
    bomLabel() { const b=this.form.bom_snapshot||{}; return [b.bom_no,b.version].filter(Boolean).join('  ') },
    routingLabel() { const r=this.form.routing_snapshot||{}; return r.routing_no ? `${r.routing_no}  V${r.version}` : '' },
    pickerTitle() { return {boms:'选择BOM版本',routings:'选择工艺路线',configurations:'选择配置版本'}[this.picker.type] }
  },
  watch: { 'workOrder.business_version': { immediate:true, handler() { if(this.workOrder.id) this.load() } } },
  methods: {
    async load() { this.loading=true; try { const r=await getWorkOrderTechnical(this.workOrder.id); this.form={...r.data.data,attachments:r.data.data.attachments||[]}; this.reason='' } catch(e) { this.$message.error(e.userMessage||'生产资料读取失败') } finally { this.loading=false } },
    configLabel(c) { return c ? `${c.configuration_no}  V${c.version_no}` : '' },
    specification(c) { if(!c) return ''; const d=c.dimensions||{}; const parts=[]; for(const [k,label] of [['length_mm','长'],['width_mm','宽'],['height_mm','高'],['thickness_mm','厚'],['diameter_mm','直径'],['outer_diameter_mm','外径'],['inner_diameter_mm','内径']]) if(d[k]) parts.push(`${label}${d[k]}mm`); return parts.join(' × ') },
    async openPicker(type,target=null) { if(!this.editable || this.picker.visible) return; this.picker={visible:true,type,target,keyword:'',category_id:null,page:1,rows:[],total:0,current:null,loading:false}; this.loadOptions(); if(!this.categories.length) { try { const r=await getItemCategoryTree(); this.categories=r.data.data||[] } catch(e) { this.$message.error(e.userMessage||'分类读取失败') } } },
    async loadOptions() { const picker=this.picker; const sequence=this.optionSequence=(this.optionSequence||0)+1; picker.loading=true; try { const r=await getWorkOrderTechnical(this.workOrder.id,{type:picker.type,bom_id:this.form.bom_id,item_id:picker.target ? picker.target.component_item_id : this.product.item_id,keyword:picker.keyword,category_id:picker.category_id,page:picker.page,per_page:20}); if(this.picker===picker && sequence===this.optionSequence) { picker.rows=r.data.data||[]; picker.total=r.data.total||0 } } catch(e) { if(this.picker===picker && sequence===this.optionSequence) this.$message.error(e.userMessage||'资料查询失败') } finally { if(this.picker===picker && sequence===this.optionSequence) picker.loading=false } },
    searchOptions() { this.picker.page=1; this.loadOptions() }, selectCategory(row) { if(row && row.children && row.children.length) return; this.picker.category_id=row ? row.id : null; this.searchOptions() }, changeOptionPage(page) { this.picker.page=page; this.loadOptions() },
    async chooseOption(row) { if(!row) return; try { if(this.picker.type==='boms') { const r=await getWorkOrderTechnical(this.workOrder.id,{bom_id:row.id}); this.form={...this.form,bom_id:row.id,bom_snapshot:r.data.data.bom_snapshot,materials:r.data.data.materials} } else if(this.picker.type==='routings') { this.form.production_routing_id=row.id; this.form.routing_snapshot={routing_no:row.routing_no,version:row.version} } else if(this.picker.target) this.$set(this.picker.target,'configuration',row); else { this.form.output_configuration=row; if(!this.form.drawing_reference) this.form.drawing_reference=row.drawing_reference } this.picker.visible=false } catch(e) { this.$message.error(e.userMessage||'选择资料失败') } },
    async confirm() { if(this.loading || this.attachmentUploading || this.saving || (!this.editable && !this.pending)) return; if(!this.pending) { if(!this.form.bom_id || !this.form.production_routing_id) return this.$message.error('请选择 BOM 版本和工艺路线'); if(this.workOrder.technical_version && !this.reason) return this.$message.error('请填写修订原因'); this.pending={client_command_id:`technical-${Date.now()}-${Math.random().toString(16).slice(2)}`,expected_version:this.workOrder.business_version,bom_id:this.form.bom_id,production_routing_id:this.form.production_routing_id,output_configuration_id:this.form.output_configuration?.id||null,drawing_reference:this.form.drawing_reference,attachment_ids:(this.form.attachments||[]).map(row=>row.id),materials:this.form.materials.map(r=>({bom_item_id:r.bom_item_id,configuration_id:r.configuration?.id||null})),reason:this.reason} } this.saving=true; this.$emit('saving',true); try { await confirmWorkOrderTechnical(this.workOrder.id,this.pending); this.pending=null; this.$message.success('生产资料已确认'); this.$emit('updated') } catch(e) { if(e.response && e.response.status>=400 && e.response.status<500 && !['command_processing','command_recovery_required','recovery_required'].includes(e.errorCode)) this.pending=null; this.$message.error(e.userMessage||'确认失败，请重试') } finally { this.saving=false; this.$emit('saving',false) } },
    showHistory() { this.historyVisible=true; this.loadHistory(1) }, async loadHistory(page) { this.historyPage=page; try { const r=await listWorkOrderTechnicalVersions(this.workOrder.id,{page,per_page:20}); this.historyRows=r.data.data||[]; this.historyTotal=r.data.total||0 } catch(e) { this.$message.error(e.userMessage||'历史版本读取失败') } }
  }
}
</script>

<style scoped>
.technical-panel{color:#243b57;min-width:0}.technical-source,.technical-card{background:#fff;border:1px solid #e1e7ef;border-radius:4px}.technical-source{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));padding:23px 26px;margin-bottom:16px}.technical-source>div{padding:0 32px;border-right:1px solid #e5ebf3;min-width:0}.technical-source>div:first-child{padding-left:0}.technical-source>div:last-child{border:0}.technical-source label{display:block;color:#7c8aa0;font-size:13px;margin-bottom:11px}.technical-source strong{font-size:14px;word-break:break-word}.technical-card{padding:18px}.technical-card h3{font-size:17px;margin:5px 0 22px;color:#122c44}.technical-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));column-gap:32px;row-gap:22px;margin-bottom:36px}.technical-fields>label{display:flex;align-items:center;gap:18px;min-width:0}.technical-fields>label>span{width:100px;flex-shrink:0;font-size:14px}.technical-fields em{color:#f56c6c;font-style:normal}.technical-panel ::v-deep .el-input__inner{color:#334b69}.technical-panel ::v-deep .el-input__suffix{display:flex;align-items:center;right:13px}.technical-panel ::v-deep .el-input__suffix i{cursor:pointer}.technical-panel ::v-deep .el-table th{background:#f5f7fa;color:#29405e}.technical-panel ::v-deep .el-table .cell{white-space:normal;word-break:break-word}.material-code{display:block;color:#8390a2;font-size:12px;margin-top:3px}.technical-tip{color:#8390a2;font-size:13px;margin:13px 0 38px}.technical-tip i{margin-right:7px}.technical-card .reason-heading{margin-bottom:10px}.technical-history{display:flex;align-items:center;gap:58px;margin-top:37px;font-size:14px}.technical-picker-search{display:flex;gap:12px;margin-bottom:14px}.technical-picker-body{display:grid;grid-template-columns:210px minmax(0,1fr);gap:12px}.technical-picker-body>aside{border:1px solid #e2e8f0;padding:10px;height:360px;overflow:auto}.technical-picker-body>aside>strong{display:block}.technical-picker-footer{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:16px;flex-wrap:wrap}.technical-picker ::v-deep .el-dialog,.technical-history-dialog ::v-deep .el-dialog{max-width:calc(100vw - 32px);margin-top:5vh!important}.technical-picker ::v-deep .el-table .cell{white-space:normal;word-break:break-word}.history-details{padding:10px 20px}.technical-panel ::v-deep .el-button--success{background:#07883f;border-color:#07883f}@media(max-width:1100px){.technical-source>div{padding:0 16px}.technical-fields{column-gap:20px}.technical-fields>label{gap:10px}.technical-fields>label>span{width:85px}}@media(max-width:767px){.technical-source{grid-template-columns:repeat(2,minmax(0,1fr));gap:20px;padding:20px}.technical-source>div{padding:0;border:0}.technical-fields{grid-template-columns:1fr;gap:16px}.technical-card{padding:14px}.technical-picker-body{grid-template-columns:1fr}.technical-picker-body>aside{height:140px}.technical-picker-footer{align-items:flex-end}.technical-picker-search .el-button{padding:10px}.technical-source>div:nth-child(3){grid-column:1/-1}}
</style>
