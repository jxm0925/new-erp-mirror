<template>
  <section v-if="total || error" class="cancellation-dispositions">
    <div class="panel-head"><strong>订单变更 / 取消 · {{ total }} 个编号待处理</strong><el-button size="mini" :loading="loading" @click="load">刷新</el-button></div>
    <el-alert title="暂停等待不计工时。已开工编号由实际接单人决定去向；退料和拆回物料须经仓库接收入库后办结。" type="warning" :closable="false" show-icon />
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <el-table :data="rows" size="small" border style="margin-top:12px">
      <el-table-column prop="sales_order_no" label="原订单" min-width="135" />
      <el-table-column label="工单" min-width="140"><template slot-scope="{row}"><el-button type="text" size="mini" @click="$router.push('/production/work-orders/' + row.work_order_id)">{{ row.work_order_no }}</el-button></template></el-table-column>
      <el-table-column prop="item_name" label="产品 / 半成品" min-width="140" />
      <el-table-column label="本次处理编号" min-width="150"><template slot-scope="{row}">{{ row.unit_serial_no || ('第' + (row.unit_no || row.work_order_unit_id) + '个') }}</template></el-table-column>
      <el-table-column label="处置进度" min-width="130"><template slot-scope="{row}"><el-tag size="mini" :type="row.overdue ? 'danger' : 'warning'">{{ labels[row.status] || row.status }}{{ row.overdue ? ' · 已逾期' : '' }}</el-tag></template></el-table-column>
      <el-table-column label="处理期限" min-width="145"><template slot-scope="{row}">{{ date(row.due_at) }}</template></el-table-column>
      <el-table-column label="实际接单人" min-width="110"><template slot-scope="{row}">{{ (row.decider_names || []).join('、') || '尚未接单' }}</template></el-table-column>
      <el-table-column prop="reason" label="原因" min-width="160" />
      <el-table-column label="生产处置" min-width="300"><template slot-scope="{row}">
        <template v-if="row.can_decide">
          <el-dropdown v-if="actionsFor(row).length" trigger="click" @command="item => open(row,item)"><el-button type="text" size="mini">选择去向<i class="el-icon-arrow-down el-icon--right" /></el-button><el-dropdown-menu slot="dropdown"><el-dropdown-item v-for="item in actionsFor(row)" :key="item" :command="item">{{ actionLabels[item] }}</el-dropdown-item></el-dropdown-menu></el-dropdown>
        </template><span v-else>等待实际接单人处理</span>
        <el-button v-if="row.can_request_material_return && row.operations && row.operations.length" type="text" size="mini" @click="open(row,'return')">申请退料</el-button>
        <el-button v-if="row.can_record_labor || row.can_decide" type="text" size="mini" @click="open(row,'labor')">处理计时</el-button>
      </template></el-table-column>
    </el-table>
    <el-pagination background :page-size="10" :current-page.sync="page" :total="total" layout="total, prev, pager, next" @current-change="load" />
    <el-dialog :title="actionLabels[action]" :visible.sync="dialog" width="600px" append-to-body :close-on-click-modal="false">
      <template v-if="selected"><p>{{ selected.work_order_no }} · {{ selected.item_name }} · {{ selected.unit_serial_no || ('第' + (selected.unit_no || selected.work_order_unit_id) + '个') }}</p><p>本次只处理这个编号，其余设备继续原来的安排。</p>
        <el-alert v-if="hints[action]" :title="hints[action]" type="warning" :closable="false" />
        <el-form label-position="top" size="small" style="margin-top:14px">
          <template v-if="action === 'labor'">
            <el-alert v-if="laborError" :title="laborError" type="error" :closable="false" />
            <div v-loading="laborLoading">
              <p>已记录处理工时：{{ Number(labor.totals && labor.totals.total_minutes || 0).toFixed(2) }} 分钟</p>
              <p v-if="labor.active_session">当前正在{{ activityLabels[labor.active_session.activity] || '处理' }} · {{ date(labor.active_session.started_at) }} 开始</p>
              <el-form-item v-if="labor.can_start" label="本次工作"><el-select v-model="activity"><el-option v-for="(label,value) in activityLabels" :key="value" :value="value" :label="label" /></el-select></el-form-item>
              <el-button v-if="labor.can_start" type="success" :loading="saving" @click="recordLabor('start')">开始计时</el-button>
              <el-button v-if="labor.can_pause" :loading="saving" @click="recordLabor('pause')">暂停计时</el-button>
              <el-button v-if="labor.can_finish" type="success" :loading="saving" @click="recordLabor('finish')">完成本次处理</el-button>
            </div>
          </template>
          <el-form-item v-else-if="action === 'return'" label="退料工序"><el-select v-model="operationId" style="width:100%"><el-option v-for="op in selected.operations" :key="op.id" :value="op.id" :label="op.name" /></el-select></el-form-item>
          <el-form-item v-else label="处理原因（必填）"><el-input v-model.trim="reason" type="textarea" :rows="2" maxlength="500" /></el-form-item>
          <el-form-item v-if="action === 'postpone'" label="下次处理日期（不得超过取消后两周）"><el-date-picker v-model="due" type="date" value-format="yyyy-MM-dd" :picker-options="dateOptions" /></el-form-item>
          <template v-if="action === 'scrap'">
            <el-form-item label="本次报废设备">{{ selected.unit_serial_no || ('第' + (selected.unit_no || selected.work_order_unit_id) + '个') }}</el-form-item>
            <el-form-item label="现场照片（必填，最多9张）"><input type="file" accept="image/*" multiple :disabled="uploading" @change="upload" /><p v-if="uploading">正在上传…</p><div v-for="(m,i) in media" :key="m.path"><a :href="m.url" target="_blank" rel="noopener noreferrer">现场照片 {{ i+1 }}</a> <el-button type="text" size="mini" @click="media.splice(i,1)">移除</el-button></div></el-form-item>
          </template>
        </el-form>
      </template>
      <span slot="footer"><el-button @click="dialog=false">返回</el-button><el-button v-if="action !== 'labor'" type="success" :loading="saving" :disabled="uploading" @click="submit">{{ action === 'return' ? '填写退料申请' : '确认' + actionLabels[action] }}</el-button></span>
    </el-dialog>
    <material-return-dialog v-if="selected" :visible.sync="returnVisible" :work-order-id="Number(selected.work_order_id)" :operation-id="Number(operationId)" :operation-name="operationName" :disposition-id="Number(selected.id)" default-return-type="cancellation" @done="changed" />
  </section>
</template>
<script>
import api from '@/api/erp/master'
import MaterialReturnDialog from '@/views/erp/production/MaterialReturnDialog.vue'
export default {
  components: { MaterialReturnDialog },
  props: { workOrderId: [String, Number], salesOrderId: [String, Number] },
  data: () => ({ rows: [], page: 1, total: 0, loading: false, revision: 0, error: '', selected: null, action: '', dialog: false, saving: false, reason: '', due: '', unitIds: [], media: [], uploading: false,
    returnVisible: false, operationId: 0, labor: {}, laborLoading: false, laborError: '', activity: 'material_return',
    labels: { pending: '待接单人决定', postponed: '继续暂停', terminating: '退料 / 拆解 / 报废处理中' },
    actionLabels: { cancel: '取消后续生产', stock: '继续做备货', dismantle: '拆解退料', postpone: '继续暂停', terminate: '终止生产', finish_termination: '处置办结', scrap: '报废在制设备', return: '申请退料', labor: '处理计时' },
    activityLabels: { material_return: '退料', dismantle: '拆解', scrap: '报废处理' },
    hints: { cancel: '停止这个编号的后续生产。它已领的材料须先退回仓库，仍有待处理事项时保留待办。', dismantle: '只拆本次编号上的可用材料并申请退回仓库，仓库真实接收入库后再办结。', stock: '这个编号继续做备货，完工后入库；其余编号保留原来的订单安排。', postpone: '这个编号继续暂停，提醒保留，暂停等待不计工时。', terminate: '停止这个编号的后续生产，落实退料、拆回或报废后再办结。', finish_termination: '系统核对这个编号的退料、配送和入库去向，尚有未处理事项不能办结。', scrap: '只报废本次编号，保留已经发生的工时和成本。可回收材料仍须办理拆回入库。', labor: '仅在实际处理这个编号时开始计时，等待仓库或暂停作业时请暂停计时。' } }),
  computed: {
    operationName() { return (this.selected?.operations || []).find(op => op.id === this.operationId)?.name || '' },
    dateOptions() { const deadline = this.selected?.deadline_at; return { disabledDate: d => d < new Date(new Date().toDateString()) || (deadline && d > new Date(deadline)) } }
  },
  watch: { workOrderId() { this.page=1; this.load() }, salesOrderId() { this.page=1; this.load() } },
  created() { this.load() },
  activated() { this.load() },
  methods: {
    date(v) { return v ? new Date(v).toLocaleString('zh-CN',{hour12:false}) : '-' },
    actionsFor(row) { return (row.allowed_actions || []).filter(action => this.actionLabels[action]) },
    async loadLabor() {
      this.laborLoading=true;this.laborError='';this.labor={};const id=this.selected.id
      try { const r=await api.get(`/v1/erp/production/cancellation-dispositions/${id}/labor`);if(this.selected?.id===id)this.labor=r.data.data || {} } catch(e) { if(this.selected?.id===id)this.laborError=e.userMessage || '处理工时加载失败' } finally { this.laborLoading=false }
    },
    async recordLabor(action) {
      this.saving=true
      try { await api.post(`/v1/erp/production/cancellation-dispositions/${this.selected.id}/labor/${action}`,action==='start'?{activity:this.activity}:{});await this.loadLabor();this.$message.success('处理工时已记录');this.$emit('changed') } catch(e) { this.$message.error(e.userMessage || '处理计时未完成') } finally { this.saving=false }
    },
    changed() { this.load();this.$emit('changed') },
    async load() { const revision=++this.revision;this.loading=true; this.error=''; try { const r=await api.get('/v1/erp/production/cancellation-dispositions',{params:{page:this.page,per_page:10,work_order_id:this.workOrderId || undefined,sales_order_id:this.salesOrderId || undefined}});if(revision!==this.revision)return;this.rows=r.data.data;this.total=r.data.total } catch(e){if(revision===this.revision)this.error=e.userMessage || '生产处置清单加载失败'}finally{if(revision===this.revision)this.loading=false} },
    async open(row,action) { this.selected=row;this.action=action;this.reason='';this.due='';this.unitIds=row.work_order_unit_id ? [row.work_order_unit_id] : [];this.media=[];this.dialog=true;this.operationId=row.operations?.[0]?.id || 0;if(action==='labor')await this.loadLabor() },
    async upload(event) { const files=Array.from(event.target.files || []);if(this.media.length+files.length>9)return this.$message.warning('最多上传9张照片');this.uploading=true;try{for(const file of files){const form=new FormData();form.append('file',file);const r=await api.post('/v1/erp/production/tasks/media',form,{headers:{'Content-Type':'multipart/form-data'}});this.media.push(r.data.data)}}catch(e){this.$message.error(e.userMessage || '照片上传失败')}finally{this.uploading=false;event.target.value=''} },
    async submit() { if(this.action==='return'){if(!this.selected.can_request_material_return)return this.$message.warning('当前账号或处置状态不能申请退料');if(!this.operationId)return this.$message.warning('请选择退料工序');this.dialog=false;this.returnVisible=true;return}if(!this.reason)return this.$message.warning('请填写处理原因');if(this.action==='postpone'&&!this.due)return this.$message.warning('请选择下次处理日期');if(this.action==='scrap'&&(!this.unitIds.length||!this.media.length))return this.$message.warning('请选择设备并上传现场照片');this.saving=true;try{const data={action:this.action,reason:this.reason,expected_version:this.selected.business_version,...(this.action==='postpone'?{due_at:this.due}:{}),...(this.action==='scrap'?{unit_ids:this.unitIds,media:this.media}:{})};await api.post(`/v1/erp/production/cancellation-dispositions/${this.selected.id}/decision`,data);this.dialog=false;this.$message.success('处置已记录');await this.load();this.$emit('changed')}catch(e){this.$message.error(e.userMessage || '处置未完成，请核对待办事项')}finally{this.saving=false} }
  }
}
</script>
<style scoped>
.cancellation-dispositions { background:#fff; border:1px solid #e6a23c; border-radius:8px; padding:16px; margin:16px 0; }
.panel-head { display:flex; align-items:center; gap:16px; margin-bottom:12px; }
</style>
