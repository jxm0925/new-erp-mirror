<template>
  <section class="production-page" v-loading="loading">
    <div class="page-heading">
      <div>
        <p class="eyebrow">生产管理 / 工单管理 / 工单详情</p>
        <div class="title-line"><h1>{{ workOrder.work_order_no || '工单详情' }}</h1><el-tag :type="statusType(workOrder.status)">{{ statusText(workOrder.status) }}</el-tag><span class="version">v{{ workOrder.business_version || 1 }}</span></div>
      </div>
      <div class="heading-actions">
        <el-button @click="$router.back()">返回</el-button>
        <el-button v-if="workOrder.source && workOrder.source.demand_id" @click="openSource">查看生产需求</el-button>
        <el-button v-if="canEdit" type="success" @click="save">保存</el-button>
        <el-button v-if="canSubmit" type="success" @click="submit">提交</el-button>
        <el-button v-if="canPublish" type="success" @click="publish">发布工单</el-button>
        <el-button v-if="canReturn" @click="returnDraft">退回草稿</el-button>
        <el-button v-if="canCancel" type="danger" plain @click="cancel">取消</el-button>
      </div>
    </div>

    <section class="trace-card card">
      <h3>1. 来源追溯</h3>
      <div class="trace-row">
        <div><i class="el-icon-document" /><b>{{ workOrder.source && workOrder.source.type_label || '销售订单' }}</b><small>{{ workOrder.source && (workOrder.source.no || workOrder.source.sales_order_no) || '-' }}</small></div><span>→</span>
        <div><i class="el-icon-s-order" /><b>{{ workOrder.source && workOrder.source.demand_id ? '生产需求' : '来源说明' }}</b><small>{{ workOrder.source && (workOrder.source.demand_no || workOrder.source.title) || '-' }}</small></div><span>→</span>
        <div class="active-trace"><i class="el-icon-s-operation" /><b>当前工单</b><small>{{ workOrder.work_order_no || '-' }}</small></div>
      </div>
    </section>

    <div class="detail-grid">
      <main>
        <section class="card overview">
          <h3>2. 工单概览</h3>
          <div class="overview-grid">
            <p><label>产品名称</label><strong>{{ workOrder.product && workOrder.product.name || '-' }}</strong></p>
            <p><label>SKU / 物料编码</label><strong>{{ workOrder.product && (workOrder.product.sku || workOrder.product.item_code) || '-' }}</strong></p>
            <p><label>计划数量</label><el-input v-if="canEdit" v-model="form.target_qty" size="small"><template slot="append">{{ workOrder.quantity && workOrder.quantity.unit_name || '' }}</template></el-input><strong v-else>{{ number(workOrder.quantity && workOrder.quantity.target_qty) }} {{ workOrder.quantity && workOrder.quantity.unit_name || '' }}</strong></p>
            <p><label>计划日期</label><el-date-picker v-if="canEdit" v-model="form.planned_date" type="date" value-format="yyyy-MM-dd" size="small" placeholder="请选择计划日期" /><strong v-else>{{ workOrder.plan && workOrder.plan.planned_date || '-' }}</strong></p>
            <p><label>负责人</label><el-select v-if="canEdit" v-model="form.responsible_user_legacy_id" size="small" placeholder="请选择生产负责人" clearable filterable><el-option v-for="user in productionUsers" :key="user.user_id" :label="displayUser(user)" :value="user.user_id" /></el-select><strong v-else>{{ workOrder.responsible_user && workOrder.responsible_user.display_name || '待分配' }}</strong></p>
            <p><label>生产地点 / 车间</label><el-input v-if="canEdit" v-model="form.production_location_name" size="small" /><strong v-else>{{ workOrder.plan && workOrder.plan.production_location_name || '-' }}</strong></p>
            <p><label>生产批次</label><el-input v-if="canEdit" v-model="form.production_batch" size="small" /><strong v-else>{{ workOrder.plan && workOrder.plan.production_batch || '-' }}</strong></p>
            <p><label>BOM 版本</label><strong>{{ bomLabel }}</strong></p>
            <p><label>产出物料</label><strong>{{ workOrder.product && workOrder.product.item_name || '-' }}</strong></p>
            <p><label>工艺路线</label><strong>{{ routeLabel }}</strong></p>
            <p v-if="workOrder.source_type==='stock_prebuild'"><label>备货目标路线工序</label><strong>{{ targetRouteOperationLabel }}</strong></p>
          </div>
        </section>

        <section class="split-grid">
          <section class="card"><h3>3. 产品 / SKU / 规格</h3><div class="info-grid"><p><label>产品名称</label><strong>{{ workOrder.product && workOrder.product.name || '-' }}</strong></p><p><label>SKU / 物料编码</label><strong>{{ workOrder.product && (workOrder.product.sku || workOrder.product.item_code) || '-' }}</strong></p><p><label>规格型号</label><strong>{{ workOrder.product && workOrder.product.specification || '-' }}</strong></p></div></section>
          <section class="card"><h3>4. 数量 / 日期 / 负责人</h3><div class="info-grid three"><p><label>计划数量</label><strong>{{ number(workOrder.quantity && workOrder.quantity.target_qty) }} {{ workOrder.quantity && workOrder.quantity.unit_name || '' }}</strong></p><p><label>计划日期</label><strong>{{ workOrder.plan && workOrder.plan.planned_date || '-' }}</strong></p><p><label>负责人</label><strong>{{ workOrder.responsible_user && workOrder.responsible_user.display_name || '待分配' }}</strong></p></div></section>
        </section>

        <section class="card facts-card">
          <div class="section-title"><h3>6. 业务事实</h3><span v-if="workOrder.status !== 'RELEASED'">工单发布后生成正式物料需求</span></div>
          <div class="fact-tabs"><button class="active">BOM / 物料需求</button></div>
          <div class="material-summary">
            <strong>物料明细</strong><span>共 {{ materialTotal }} 条</span>
          </div>
          <el-table :data="materials" border size="small" empty-text="当前工单尚未生成正式物料需求">
            <el-table-column prop="line_no" label="行号" width="70" align="center" />
            <el-table-column label="物料编码" min-width="135"><template slot-scope="scope">{{ scope.row.component && scope.row.component.code || '-' }}</template></el-table-column>
            <el-table-column label="物料名称" min-width="150"><template slot-scope="scope">{{ scope.row.component && scope.row.component.name || '-' }}</template></el-table-column>
            <el-table-column label="规格型号" min-width="130"><template slot-scope="scope">{{ scope.row.component && scope.row.component.specification || '-' }}</template></el-table-column>
            <el-table-column label="计量单位" width="95"><template slot-scope="scope">{{ scope.row.unit && scope.row.unit.name || '-' }}</template></el-table-column>
            <el-table-column label="单位用量" width="100"><template slot-scope="scope">{{ number(scope.row.formula && scope.row.formula.per_output_qty) }}</template></el-table-column>
            <el-table-column label="损耗率" width="90"><template slot-scope="scope">{{ number(scope.row.formula && scope.row.formula.loss_rate) }}%</template></el-table-column>
            <el-table-column label="需求数量" width="110"><template slot-scope="scope">{{ number(scope.row.quantity && scope.row.quantity.required_qty) }}</template></el-table-column>
            <el-table-column label="已领数量" width="100"><template slot-scope="scope">{{ number(scope.row.quantity && scope.row.quantity.issued_qty) }}</template></el-table-column>
            <el-table-column label="待领数量" width="100"><template slot-scope="scope">{{ number(scope.row.quantity && scope.row.quantity.remaining_qty) }}</template></el-table-column>
            <el-table-column label="状态" width="90"><template slot-scope="scope"><el-tag size="mini" type="success">{{ materialStatus(scope.row.status) }}</el-tag></template></el-table-column>
          </el-table>
          <div v-if="materialTotal > materialPerPage" class="material-page"><el-pagination small layout="prev, pager, next" :current-page="materialPage" :page-size="materialPerPage" :total="materialTotal" @current-change="changeMaterialPage" /></div>
        </section>
      </main>

      <aside class="card gate-card">
        <div class="section-title"><h3>5. 发布状态、检查、风险</h3><el-button v-if="canViewGate && workOrder.status === 'WAIT_RELEASE'" type="text" :loading="gateLoading" @click="fetchGate(true)">重新检查</el-button></div>
        <div class="release-state"><label>发布基线</label><strong>{{ workOrder.status === 'RELEASED' ? '已发布 / BOM 与物料已冻结' : '待发布检查' }}</strong><small v-if="workOrder.release && workOrder.release.released_at">{{ workOrder.release.released_at }}</small></div>
        <div v-if="gate" class="gate-result" :class="gate.allowed ? 'passed' : 'blocked'"><i :class="gate.allowed ? 'el-icon-success' : 'el-icon-warning'" /><span>{{ gate.allowed ? '发布检查通过' : '发布检查未通过' }}</span><small v-if="gate.immutable">历史发布证据（不可变）</small></div>
        <ul v-if="gate && gate.checks && gate.checks.length" class="gate-list"><li v-for="item in gate.checks" :key="item.key" :class="item.status"><i :class="item.status === 'passed' ? 'el-icon-circle-check' : 'el-icon-circle-close'" /><div><b>{{ gateName(item.key) }}</b><small>{{ item.message }}</small></div></li></ul>
        <el-empty v-else-if="canViewGate" :image-size="55" description="尚未执行发布检查" />
        <div v-else class="permission-empty">当前账号没有查看发布检查的权限。</div>
        <div v-if="workOrder.release && workOrder.release.reason" class="release-reason"><label>发布原因</label><p>{{ workOrder.release.reason }}</p></div>
      </aside>
    </div>

    <section class="card timeline-card">
      <h3>7. 工单状态时间线</h3>
      <div class="timeline">
        <div v-for="item in timeline" :key="item.status" :class="['timeline-step', { done: item.done, current: item.current }]">
          <span class="timeline-dot"><i v-if="item.done" class="el-icon-check" /></span><div><strong>{{ statusText(item.status) }}</strong><small>{{ item.label }}</small></div>
        </div>
      </div>
    </section>
  </section>
</template>

<script>
import { getWorkOrder, updateWorkOrderDraft, submitWorkOrder, getWorkOrderReleaseGate, publishWorkOrder, listWorkOrderMaterialRequirements, returnWorkOrderToDraft, cancelWorkOrder } from '../../../api/erp/production'
import { listUsers } from '../../../api/erp/rbac'

export default {
  name: 'WorkOrderDetail',
  data: () => ({ loading: false, gateLoading: false, workOrder: {}, gate: null, materials: [], materialTotal: 0, materialPage: 1, materialPerPage: 20, productionUsers: [], form: { target_qty: '', planned_date: '', production_batch: '', responsible_user_legacy_id: '', production_location_name: '' } }),
  computed: {
    canEdit() { return Boolean(this.workOrder.actions && this.workOrder.actions.edit) && this.$route.query.mode === 'edit' },
    canSubmit() { return Boolean(this.workOrder.actions && this.workOrder.actions.submit) },
    canReturn() { return Boolean(this.workOrder.actions && this.workOrder.actions.return_draft) },
    canCancel() { return Boolean(this.workOrder.actions && this.workOrder.actions.cancel) },
    canViewGate() { return Boolean(this.workOrder.actions && this.workOrder.actions.view_release_gate) },
    canViewMaterials() { return Boolean(this.workOrder.actions && this.workOrder.actions.view_materials) },
    canPublish() { return this.workOrder.status === 'WAIT_RELEASE' && this.gate && this.gate.allowed && this.$can('production.work_order.publish') },
    bomLabel() { const bom = this.workOrder.release && this.workOrder.release.bom; return bom ? `${bom.bom_no || 'BOM'} ${bom.version || ''}`.trim() : '发布后锁定' },
    routeLabel() { const r=this.workOrder.routing||{}; return r.id ? `${r.no||''} ${r.name||''} V${r.version||'-'}`.trim() : '未配置' },
    targetRouteOperationLabel() { const n=(this.workOrder.routing||{}).target_routing_operation; return n ? `${n.sequence} - ${n.operation_name||''}` : '-' },
    timeline() {
      const order = ['DRAFT', 'WAIT_RELEASE', 'RELEASED']
      const labels = { DRAFT: '草稿创建', WAIT_RELEASE: '待发布', RELEASED: '已发布' }
      const current = order.indexOf(this.workOrder.status)
      return order.map((status, index) => ({ status, label: labels[status], done: current >= index, current: current === index }))
    }
  },
  created() { this.fetchUsers(); this.fetchWorkOrder() },
  methods: {
    async fetchWorkOrder() {
      this.loading = true
      try {
        const response = await getWorkOrder(this.$route.params.id)
        this.workOrder = response.data.data || {}
        this.form = { target_qty: this.workOrder.target_qty, planned_date: this.workOrder.planned_date, production_batch: this.workOrder.production_batch, responsible_user_legacy_id: this.workOrder.responsible_user && this.workOrder.responsible_user.user_id, production_location_name: this.workOrder.production_location_name }
        const tasks = []
        if (this.canViewGate && ['WAIT_RELEASE', 'RELEASED'].includes(this.workOrder.status)) tasks.push(this.fetchGate(false))
        if (this.canViewMaterials && this.workOrder.status === 'RELEASED') tasks.push(this.fetchMaterials())
        await Promise.all(tasks)
      } catch (error) { this.$message.error(error.userMessage || '工单加载失败') } finally { this.loading = false }
    },
    async fetchUsers() { try { const response = await listUsers({ scope: 'production', status: 'normal', page: 1, per_page: 100 }); this.productionUsers = response.data.data || response.data || [] } catch (error) { this.productionUsers = [] } },
    async fetchGate(showMessage) {
      this.gateLoading = true
      try {
        const response = await getWorkOrderReleaseGate(this.workOrder.id || this.$route.params.id)
        this.gate = response.data.data
        if (showMessage) this.$message[this.gate.allowed ? 'success' : 'warning'](this.gate.allowed ? '发布检查已通过' : '发布检查未通过，请处理阻断项')
      } catch (error) { this.$message.error(error.userMessage || '发布检查失败') } finally { this.gateLoading = false }
    },
    async fetchMaterials() {
      try { const response = await listWorkOrderMaterialRequirements(this.workOrder.id, { page: this.materialPage, per_page: this.materialPerPage }); this.materials = response.data.data || []; this.materialTotal = response.data.total || 0 } catch (error) { this.materials = []; this.materialTotal = 0; this.$message.error(error.userMessage || '物料需求加载失败') }
    },
    async save() { try { await updateWorkOrderDraft(this.workOrder.id, { ...this.form, client_command_id: this.command('edit'), expected_version: this.workOrder.business_version }); this.$message.success('工单草稿已保存'); this.fetchWorkOrder() } catch (error) { this.$message.error(error.userMessage || '保存失败') } },
    async submit() { try { await submitWorkOrder(this.workOrder.id, { client_command_id: this.command('submit'), expected_version: this.workOrder.business_version, reason: '提交工单草稿' }); this.$message.success('已提交，等待发布'); this.fetchWorkOrder() } catch (error) { this.$message.error(error.userMessage || '提交失败') } },
    async publish() {
      try {
        const result = await this.$prompt('请输入本次发布原因，发布后 BOM 和正式物料需求不可更改。', '发布工单', { confirmButtonText: '确认发布', cancelButtonText: '取消', inputPattern: /\S+/, inputErrorMessage: '发布原因不能为空' })
        await publishWorkOrder(this.workOrder.id, { client_command_id: this.command('publish'), expected_version: this.workOrder.business_version, reason: result.value.trim() })
        this.$message.success('工单已发布，BOM 与物料需求已锁定')
        this.materialPage = 1
        await this.fetchWorkOrder()
      } catch (error) { if (error !== 'cancel' && error !== 'close') this.$message.error(error.userMessage || '发布失败') }
    },
    async returnDraft() { try { await returnWorkOrderToDraft(this.workOrder.id, { client_command_id: this.command('return'), expected_version: this.workOrder.business_version, reason: '退回草稿修改' }); this.$message.success('已退回草稿'); this.fetchWorkOrder() } catch (error) { this.$message.error(error.userMessage || '退回失败') } },
    async cancel() { try { await cancelWorkOrder(this.workOrder.id, { client_command_id: this.command('cancel'), expected_version: this.workOrder.business_version }); this.$message.success('工单已取消'); this.fetchWorkOrder() } catch (error) { this.$message.error(error.userMessage || '取消失败') } },
    changeMaterialPage(page) { this.materialPage = page; this.fetchMaterials() },
    command(action) { return `wo04-${action}-${this.workOrder.id}-${Date.now()}` },
    openSource() { if (this.workOrder.source && this.workOrder.source.demand_id) this.$router.push(`/production/demands/${this.workOrder.source.demand_id}`) },
    displayUser(user) { return user.display_name || '未命名用户' },
    number(value) { return Number(value || 0).toLocaleString('zh-CN', { maximumFractionDigits: 8 }) },
    materialStatus(status) { return ({ OPEN: '已计算' })[status] || status || '-' },
    gateName(key) { return ({ work_order_state: '工单状态', demand_active: '生产需求', source_valid: '工单来源', routing_snapshot: '工艺路线快照', quantity: '计划数量', responsible_user: '负责人', production_location: '生产地点 / 车间', bom_match: 'BOM 匹配', bom_effective: 'BOM 生效状态', bom_complete: 'BOM 完整性', custom_documents: '定制附件' })[key] || key },
    statusText(status) { return ({ DRAFT: '草稿', WAIT_RELEASE: '待发布', RELEASED: '已发布', CANCELLED: '已取消' })[status] || status || '-' },
    statusType(status) { return status === 'CANCELLED' ? 'danger' : status === 'WAIT_RELEASE' ? 'warning' : status === 'RELEASED' ? 'success' : '' }
  }
}
</script>

<style scoped>
.production-page{padding:24px 28px;background:#f7f9fb;min-height:calc(100vh - 54px);color:#27384e}.page-heading{display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:18px}.page-heading h1{margin:3px 0 0;font-size:22px;color:#152941}.eyebrow{margin:0;color:#008b4b;font-weight:600}.title-line,.heading-actions{display:flex;align-items:center;gap:9px}.version{padding:4px 9px;color:#1677d2;background:#edf6ff;border-radius:4px;font-weight:600}.card,.trace-card{background:#fff;border:1px solid #e6ebf0;border-radius:5px;margin-bottom:14px;padding:17px 20px}.card h3,.trace-card h3{margin:0 0 15px;color:#1d3048;font-size:14px}.trace-row{display:flex;align-items:center;gap:18px}.trace-row>span{font-size:24px;color:#9aa8b7}.trace-row>div{flex:1;display:grid;grid-template-columns:34px 1fr;align-items:center;padding:10px 13px;border:1px solid #e7edf2;border-radius:4px}.trace-row i{grid-row:span 2;font-size:22px;color:#98a5b3}.trace-row small{color:#66758a;margin-top:4px}.trace-row .active-trace{border-color:#7bd5a4;background:#f2fbf6}.trace-row .active-trace i{color:#008b4b}.detail-grid{display:grid;grid-template-columns:minmax(0,3fr) minmax(280px,1fr);gap:14px}.detail-grid>main,.detail-grid>aside{min-width:0}.overview-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:20px 28px}.overview-grid p,.info-grid p{min-width:0;margin:0;display:flex;flex-direction:column;gap:6px}.overview-grid label,.info-grid label,.release-state label,.release-reason label{color:#8794a4;font-size:12px}.overview-grid strong,.info-grid strong{font-weight:500;color:#33455d}.overview-grid .el-input,.overview-grid .el-select,.overview-grid .el-date-editor{width:100%}.split-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.info-grid{display:grid;grid-template-columns:1.5fr 1.2fr;gap:18px}.info-grid.three{grid-template-columns:repeat(3,1fr)}.section-title{display:flex;align-items:center;justify-content:space-between}.section-title h3{margin-bottom:12px}.section-title span{color:#8a97a7;font-size:12px}.fact-tabs{border-bottom:1px solid #e8edf2;margin-bottom:14px}.fact-tabs button{border:0;background:transparent;color:#008b4b;border-bottom:2px solid #008b4b;padding:0 14px 10px;font-weight:600}.material-summary{display:flex;gap:10px;align-items:center;margin:4px 0 10px}.material-summary span{color:#7f8da0}.material-page{display:flex;justify-content:flex-end;margin-top:12px}.gate-card{margin-bottom:14px}.release-state{padding:4px 0 13px;border-bottom:1px solid #edf1f4}.release-state strong,.release-state small{display:block;margin-top:6px}.release-state small{color:#7f8da0}.gate-result{margin:14px 0;padding:11px;border-radius:4px;display:flex;align-items:center;gap:8px}.gate-result.passed{background:#effaf4;color:#069552}.gate-result.blocked{background:#fff7ed;color:#d97706}.gate-result small{margin-left:auto}.gate-list{list-style:none;margin:0;padding:0}.gate-list li{display:flex;gap:8px;padding:8px 0;border-bottom:1px solid #f0f3f5}.gate-list li.passed i{color:#08a25b}.gate-list li.blocked i{color:#dc3d43}.gate-list b,.gate-list small{display:block}.gate-list small{color:#8290a1;margin-top:3px;line-height:1.45}.permission-empty{padding:20px 0;color:#8b97a6;text-align:center}.release-reason{margin-top:14px;padding:11px;background:#f7fafc}.release-reason p{margin:5px 0 0;line-height:1.5}.timeline{display:flex;align-items:flex-start}.timeline-step{position:relative;display:flex;gap:10px;flex:1;color:#9aa5b2}.timeline-step:not(:last-child):after{content:'';position:absolute;left:38px;right:12px;top:10px;height:1px;background:#cfd8e3}.timeline-dot{position:relative;z-index:1;width:20px;height:20px;border-radius:50%;border:2px solid #bac5d0;background:#fff;display:flex;align-items:center;justify-content:center}.timeline-step.done{color:#087f48}.timeline-step.done .timeline-dot{background:#0aa15a;border-color:#0aa15a;color:#fff}.timeline-step.current .timeline-dot{box-shadow:0 0 0 4px #d9f5e6}.timeline-step strong,.timeline-step small{display:block}.timeline-step small{margin-top:5px;font-size:12px}@media(max-width:1200px){.detail-grid{grid-template-columns:1fr}.overview-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:767px){.production-page{padding:16px 12px}.page-heading{height:auto;min-height:120px;align-items:flex-start;flex-direction:column;justify-content:flex-start;gap:12px}.title-line{flex-wrap:wrap}.heading-actions{width:100%;flex-wrap:wrap}.trace-row{overflow-x:auto;padding-bottom:6px}.trace-row>div{flex:0 0 170px}.overview-grid{gap:16px}.split-grid{grid-template-columns:1fr}.info-grid,.info-grid.three{grid-template-columns:1fr 1fr}.timeline{min-width:430px}.timeline-card{overflow-x:auto}.card,.trace-card{padding:15px 14px}}
</style>
