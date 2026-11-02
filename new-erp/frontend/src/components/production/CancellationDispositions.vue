<template>
  <section v-if="total || error" class="cancellation-dispositions">
    <div class="panel-head"><strong>订单取消 · {{ total }} 张工单待生产处理</strong><el-button size="mini" :loading="loading" @click="load">刷新</el-button></div>
    <el-alert title="工单已暂停。3个工作日内决定去向；继续暂停仍计入待处理，最长两周，逾期需主管督办。" type="warning" :closable="false" show-icon />
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <el-table :data="rows" size="small" border style="margin-top:12px">
      <el-table-column prop="sales_order_no" label="原订单" min-width="135" />
      <el-table-column label="工单" min-width="140"><template slot-scope="{row}"><el-button type="text" size="mini" @click="$router.push('/production/work-orders/' + row.work_order_id)">{{ row.work_order_no }}</el-button></template></el-table-column>
      <el-table-column prop="item_name" label="产品 / 半成品" min-width="140" />
      <el-table-column label="处置进度" min-width="130"><template slot-scope="{row}"><el-tag size="mini" :type="row.overdue ? 'danger' : 'warning'">{{ labels[row.status] || row.status }}{{ row.overdue ? ' · 已逾期' : '' }}</el-tag></template></el-table-column>
      <el-table-column label="处理期限" min-width="145"><template slot-scope="{row}">{{ date(row.due_at) }}</template></el-table-column>
      <el-table-column prop="reason" label="原因" min-width="160" show-overflow-tooltip />
      <el-table-column v-if="$can('production.work_order.edit')" label="主管处置" min-width="270"><template slot-scope="{row}">
        <template v-if="row.status !== 'terminating'"><el-button type="text" size="mini" @click="open(row,'stock')">转备货</el-button><el-button type="text" size="mini" @click="open(row,'postpone')">继续暂停</el-button><el-button type="text" size="mini" @click="open(row,'terminate')">终止生产</el-button></template>
        <template v-else><el-button v-if="$can('production.scrap.approve')" type="text" size="mini" @click="open(row,'scrap')">报废在制设备</el-button><el-button type="text" size="mini" @click="open(row,'finish_termination')">确认处置办结</el-button></template>
      </template></el-table-column>
    </el-table>
    <el-pagination :page-size="10" :current-page.sync="page" :total="total" layout="total, prev, pager, next" @current-change="load" />
    <el-dialog :title="actionLabels[action]" :visible.sync="dialog" width="600px" append-to-body :close-on-click-modal="false">
      <template v-if="selected"><p>{{ selected.work_order_no }} · {{ selected.item_name }}</p>
        <el-alert :title="hints[action]" type="warning" :closable="false" />
        <el-form label-position="top" size="small" style="margin-top:14px">
          <el-form-item label="处理原因（必填）"><el-input v-model.trim="reason" type="textarea" :rows="2" maxlength="500" /></el-form-item>
          <el-form-item v-if="action === 'postpone'" label="下次处理日期（不得超过取消后两周）"><el-date-picker v-model="due" type="date" value-format="yyyy-MM-dd" :picker-options="dateOptions" /></el-form-item>
          <template v-if="action === 'scrap'">
            <el-form-item label="选择无法恢复的在制设备"><el-select v-model="unitIds" multiple placeholder="选择设备编号" style="width:100%"><el-option v-for="unit in units" :key="unit.id" :value="unit.id" :label="unit.serial_no || ('第' + unit.unit_no + '台')" /></el-select></el-form-item>
            <el-form-item label="现场照片（必填，最多9张）"><input type="file" accept="image/*" multiple :disabled="uploading" @change="upload" /><p v-if="uploading">正在上传…</p><div v-for="(m,i) in media" :key="m.path"><a :href="m.url" target="_blank" rel="noopener noreferrer">现场照片 {{ i+1 }}</a> <el-button type="text" size="mini" @click="media.splice(i,1)">移除</el-button></div></el-form-item>
          </template>
        </el-form>
      </template>
      <span slot="footer"><el-button @click="dialog=false">返回</el-button><el-button type="success" :loading="saving" :disabled="uploading" @click="submit">确认{{ actionLabels[action] }}</el-button></span>
    </el-dialog>
  </section>
</template>
<script>
import api from '@/api/erp/master'
export default {
  props: { workOrderId: [String, Number], salesOrderId: [String, Number] },
  data: () => ({ rows: [], page: 1, total: 0, loading: false, revision: 0, error: '', selected: null, action: '', dialog: false, saving: false, reason: '', due: '', units: [], unitIds: [], media: [], uploading: false,
    labels: { pending: '待主管决定', postponed: '继续暂停', terminating: '终止生产处置中' },
    actionLabels: { stock: '转备货', postpone: '继续暂停', terminate: '终止生产', finish_termination: '处置办结', scrap: '报废在制设备' },
    hints: { stock: '确认后解除原订单交付关联，保留追溯，并恢复备货生产；尚未下达的工单保留计划状态。', postpone: '暂停不算办结，提醒将继续保留。', terminate: '停止后续生产；先办理未使用料退回、已装部件拆回或无法恢复设备的报废，去向落实后再办结。', finish_termination: '系统会检查退料、配送、入库、子工单和在制设备去向，尚有未处理事项不能办结。', scrap: '只报废所选在制设备，保留实际工时、材料和成本记录。可回收材料请继续办理拆回，最后确认报废设备已处理。' } }),
  computed: { dateOptions() { const deadline = this.selected?.deadline_at; return { disabledDate: d => d < new Date(new Date().toDateString()) || (deadline && d > new Date(deadline)) } } },
  watch: { workOrderId() { this.page=1; this.load() }, salesOrderId() { this.page=1; this.load() } },
  created() { this.load() },
  activated() { this.load() },
  methods: {
    date(v) { return v ? new Date(v).toLocaleString('zh-CN',{hour12:false}) : '-' },
    async load() { const revision=++this.revision;this.loading=true; this.error=''; try { const r=await api.get('/v1/erp/production/cancellation-dispositions',{params:{page:this.page,per_page:10,work_order_id:this.workOrderId || undefined,sales_order_id:this.salesOrderId || undefined}});if(revision!==this.revision)return;this.rows=r.data.data;this.total=r.data.total } catch(e){if(revision===this.revision)this.error=e.userMessage || '生产处置清单加载失败'}finally{if(revision===this.revision)this.loading=false} },
    async open(row,action) { this.selected=row;this.action=action;this.reason='';this.due='';this.unitIds=[];this.media=[];this.dialog=true;if(action==='scrap'){try{const r=await api.get(`/v1/erp/production/work-orders/${row.work_order_id}`);this.units=(r.data.data.units || []).filter(u=>!['stored','scrapped','cancelled','pending_receipt'].includes(u.status))}catch(e){this.$message.error(e.userMessage || '设备清单加载失败');this.dialog=false}} },
    async upload(event) { const files=Array.from(event.target.files || []);if(this.media.length+files.length>9)return this.$message.warning('最多上传9张照片');this.uploading=true;try{for(const file of files){const form=new FormData();form.append('file',file);const r=await api.post('/v1/erp/production/tasks/media',form,{headers:{'Content-Type':'multipart/form-data'}});this.media.push(r.data.data)}}catch(e){this.$message.error(e.userMessage || '照片上传失败')}finally{this.uploading=false;event.target.value=''} },
    async submit() { if(!this.reason)return this.$message.warning('请填写处理原因');if(this.action==='postpone'&&!this.due)return this.$message.warning('请选择下次处理日期');if(this.action==='scrap'&&(!this.unitIds.length||!this.media.length))return this.$message.warning('请选择设备并上传现场照片');this.saving=true;try{const data={action:this.action,reason:this.reason,expected_version:this.selected.business_version,...(this.action==='postpone'?{due_at:this.due}:{}),...(this.action==='scrap'?{unit_ids:this.unitIds,media:this.media}:{})};await api.post(`/v1/erp/production/cancellation-dispositions/${this.selected.id}/decision`,data);this.dialog=false;this.$message.success('处置已记录');await this.load();this.$emit('changed')}catch(e){this.$message.error(e.userMessage || '处置未完成，请核对待办事项')}finally{this.saving=false} }
  }
}
</script>
<style scoped>
.cancellation-dispositions { background:#fff; border:1px solid #e6a23c; border-radius:8px; padding:16px; margin:16px 0; }
.panel-head { display:flex; align-items:center; gap:16px; margin-bottom:12px; }
</style>
