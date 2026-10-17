<template>
  <section class="production-task-workbench">
    <header class="ptw-page-heading"><div><h1>生产作业</h1><p>查看本人作业、接单（接单即验收上道工序产出）和办理质检。</p></div><el-button size="small" :loading="loading" @click="load">刷新</el-button></header>
    <div v-if="pending.length" class="ptw-pending"><el-alert title="有操作结果尚未确认，请重试原操作后核对。" type="warning" :closable="false" show-icon /><el-button v-for="job in pending" :key="job.method + job.path" size="small" :loading="busy" @click="recover(job)">重试原操作</el-button></div>
    <div class="ptw-filters"><el-input v-if="tab !== 'quality'" v-model.trim="keyword" size="small" clearable prefix-icon="el-icon-search" placeholder="工单号、产品或工序" @keyup.enter.native="search" /><el-select v-if="taskTab" v-model="executionFilter" size="small" @change="search"><el-option label="全部作业状态" value="all" /><el-option label="待办理" value="waiting" /><el-option label="加工中" value="running" /><el-option label="待齐套" value="kitting" /><el-option label="已完成" value="completed" /></el-select><el-button v-if="tab !== 'quality'" size="small" type="success" @click="search">查询</el-button></div>
    <el-tabs v-model="tab" @tab-click="changeTab">
      <el-tab-pane label="我的任务" name="owned" /><el-tab-pane label="可接任务" name="pool" /><el-tab-pane label="协同任务" name="collaboration" />
      <el-tab-pane v-if="$can('production.output.view')" label="待质检" name="quality" />
    </el-tabs>
    <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
    <el-table v-if="taskTab" key="tasks" :data="rows" border size="small" v-loading="loading" empty-text="暂无符合条件的任务">
      <el-table-column type="index" label="序号" width="58" :index="index => (page - 1) * 10 + index + 1" />
      <el-table-column label="工单号" min-width="165"><template slot-scope="{row}">{{ row.work_order && row.work_order.work_order_no || '—' }}</template></el-table-column>
      <el-table-column label="产品名称" min-width="155"><template slot-scope="{row}">{{ productName(row) }}</template></el-table-column>
      <el-table-column prop="operation_name_snapshot" label="工序" min-width="105" />
      <el-table-column label="设备编号" min-width="130"><template slot-scope="{row}">{{ deviceText(row) }}</template></el-table-column>
      <el-table-column label="执行对象" min-width="135"><template slot-scope="{row}">{{ (row.target_details || []).length }} 项<span class="ptw-subtext">{{ quantity(row) }}</span><span v-if="hasIncoming(row)" class="ptw-subtext">上道已交出，接单即验收</span></template></el-table-column>
      <el-table-column label="状态" min-width="160"><template slot-scope="{row}"><el-tag size="mini" type="success">{{ taskStatus(row) }}</el-tag></template></el-table-column>
      <el-table-column label="负责人" min-width="100"><template slot-scope="{row}">{{ row.assignee_user && row.assignee_user.display_name || '待接单' }}</template></el-table-column>
      <el-table-column label="操作" width="100"><template slot-scope="{row}"><el-button type="text" @click="openTask(row.id)">查看作业</el-button></template></el-table-column>
    </el-table>
    <el-table v-else key="quality" :data="rows" border size="small" v-loading="loading" empty-text="暂无待质检的产出">
      <el-table-column prop="output_no" label="产出单号" min-width="170" /><el-table-column label="工单号" min-width="170"><template slot-scope="{row}">{{ row.work_order && row.work_order.work_order_no || row.work_order_no || '—' }}</template></el-table-column><el-table-column prop="output_base_qty" label="产出数量" width="110" /><el-table-column label="操作" width="100"><template slot-scope="{row}"><el-button v-if="row.allowed_actions && row.allowed_actions.quality_inspect" type="text" @click="openQuality(row)">办理质检</el-button></template></el-table-column>
    </el-table>
    <el-pagination class="ptw-pagination" background layout="total, prev, pager, next" :current-page="page" :page-size="10" :total="total" :disabled="loading" @current-change="changePage" />
    <production-task-detail-dialog ref="detail" @updated="load" />
    <el-dialog title="生产产出质检" :visible.sync="quality.visible" width="736px" custom-class="production-task-dialog" append-to-body :close-on-click-modal="false" :show-close="!busy" :close-on-press-escape="!busy">
      <template v-if="quality.output"><p>{{ quality.output.output_no }} · 产出数量 {{ quality.output.output_base_qty }}</p><el-form label-position="top" size="small"><el-form-item label="整批检验结果"><el-radio-group v-model="quality.result" :disabled="busy || qualityPending"><el-radio label="passed">整批合格</el-radio><el-radio label="failed">整批不合格</el-radio></el-radio-group></el-form-item><el-form-item label="检验说明"><el-input v-model.trim="quality.reason" type="textarea" :rows="3" maxlength="1000" :disabled="busy || qualityPending" /></el-form-item><el-form-item v-if="quality.result === 'passed' && quality.output.output_mode_snapshot === 'warehouse_optional'" label="后续去向"><el-radio-group v-model="quality.nextStep" :disabled="busy || qualityPending"><el-radio label="direct_handover">直接交接</el-radio><el-radio label="warehouse">先入库</el-radio></el-radio-group></el-form-item></el-form></template>
      <span slot="footer"><el-button size="small" :disabled="busy" @click="quality.visible=false">关闭</el-button><el-button size="small" type="success" :loading="busy" :disabled="!quality.result || !!qualityPending" @click="submitQuality">确认质检结果</el-button></span>
    </el-dialog>
  </section>
</template>

<script>
import cachedPageRoute from '@/utils/cachedPageRoute'
import { listAssignmentTasks } from '@/api/erp/production-assignments'
import { executionBase, getExecutionOutputs, getExecutionOutput, writeExecutionCommand, pendingExecutionCommands, retryExecutionCommand } from '@/api/erp/production-execution'
import ProductionTaskDetailDialog from './ProductionTaskDetailDialog.vue'

export default {
  name: 'ProductionTaskWorkbench', mixins: [cachedPageRoute], components: { ProductionTaskDetailDialog },
  data: () => ({ tab:'owned', keyword:'', executionFilter:'all', page:1, rows:[], total:0, loading:false, busy:false, error:'', sequence:0, pending:[], quality:{visible:false,output:null,result:'',reason:'',nextStep:'direct_handover'} }),
  computed: {
    taskTab() { return this.tab !== 'quality' },
    qualityPending() { return this.quality.output && this.pending.some(job=>job.path===`${executionBase}/outputs/${this.quality.output.id}/quality-inspect`) }
  },
  created() { this.qualitySequence=0; this.load(); this.openRequestedTask() },
  watch: { 'pageRoute.query.task_id'() { this.openRequestedTask() }, 'pageRoute.query.work_order_id'() { this.search() } },
  async beforeRouteLeave(to,from,next) { if(this.$refs.detail?.visible && !await this.$refs.detail.canDiscard()) return next(false);next() },
  async beforeRouteUpdate(to,from,next) { if(this.$refs.detail?.visible && !await this.$refs.detail.canDiscard()) return next(false);next() },
  beforeDestroy() { this.sequence++; this.qualitySequence++ },
  methods: {
    productName(row) { return row.work_order?.output_item?.item_name || '未提供产品名称' },
    quantity(row) { const targets=row.target_details || []; if(!targets.length || targets.some(target=>target.missing)) return '待核对执行对象'; return targets.reduce((sum,target)=>sum+Number(target.planned_base_qty),0).toLocaleString('zh-CN',{maximumFractionDigits:8}) + ' ' + (row.work_order?.base_unit_name_snapshot || '') },
    taskStatus(row) { const labels=[...new Set((row.target_details || []).map(target=>target.status_label).filter(Boolean))]; return labels.join('、') || ({WAIT_CLAIM:'待接单',WAIT_ACCEPT:'待接受派单',CLAIMED:'已接单',COMPLETED:'已完成',CANCELLED:'已取消'})[row.status] || '待核对状态' },
    openRequestedTask() { const id=Number(this.pageRoute.query.task_id); if(Number.isSafeInteger(id) && id>0) this.$nextTick(()=>this.openTask(id)) },
    openTask(id) { this.$refs.detail.open(Number(id)) },
    changeTab() { this.page=1; this.rows=[]; this.total=0; this.load() }, search() { this.page=1; this.load() }, changePage(page) { this.page=page; this.load() },
    deviceText(row) { const list=[...new Set((row.target_details || []).map(target=>target.serial_no || target.production_unit_no).filter(Boolean))]; if(!list.length) return '—'; return list.length>3 ? `${list.slice(0,3).join('、')} 等${list.length}台` : list.join('、') },
    hasIncoming(row) { return (row.target_details || []).some(target=>(target.incoming_handovers || []).length) },
    refreshPending() { this.pending=pendingExecutionCommands() },
    async load() {
      const sequence=++this.sequence; this.loading=true; this.error=''
      try {
        this.refreshPending()
        const workOrderId=Number(this.pageRoute.query.work_order_id) || undefined
        const response=this.taskTab ? await listAssignmentTasks({view:this.tab,keyword:this.keyword,execution_filter:this.executionFilter,work_order_id:workOrderId,page:this.page,per_page:10})
          : await getExecutionOutputs({status:'WAIT_QUALITY',work_order_id:workOrderId,page:this.page,per_page:10})
        if(sequence!==this.sequence) return
        this.rows=response.data.data || []; this.total=Number(response.data.total || 0)
      } catch(error) { if(sequence===this.sequence) this.error=error.userMessage || error.message }
      finally { if(sequence===this.sequence) this.loading=false }
    },
    async recover(job) { if(this.busy) return; this.busy=true; try { await retryExecutionCommand(job); this.$message.success('原操作结果已确认'); this.quality.visible=false; await this.load() } catch(error) { this.error=error.userMessage || error.message; this.refreshPending() } finally { this.busy=false } },
    async openQuality(row) { if(this.busy)return;const sequence=++this.qualitySequence;try { const response=await getExecutionOutput(row.id);if(sequence!==this.qualitySequence)return; this.quality={visible:true,output:response.data.data,result:'',reason:'',nextStep:'direct_handover'}; this.refreshPending() } catch(error) { this.$message.error(error.userMessage || error.message) } },
    async submitQuality() {
      const output=this.quality.output
      if(this.busy || !this.quality.result || this.qualityPending || !output?.allowed_actions?.quality_inspect) return
      if(this.quality.result==='failed' && !this.quality.reason) return this.$message.error('请填写不合格原因')
      this.busy=true
      try { const passed=this.quality.result==='passed'; await writeExecutionCommand('POST',`${executionBase}/outputs/${output.id}/quality-inspect`,output.id,{expected_version:output.business_version,result:this.quality.result,qualified_base_qty:passed?output.output_base_qty:0,unqualified_base_qty:passed?0:output.output_base_qty,reason:this.quality.reason,next_step:output.output_mode_snapshot==='warehouse_required'?'warehouse':this.quality.nextStep}); this.quality.visible=false; this.$message.success('质检结果已保存'); await this.load() } catch(error) { this.error=error.userMessage || error.message; this.refreshPending() } finally { this.busy=false }
    }
  }
}
</script>

<style src="./production-task-workbench.css"></style>
