<template>
  <section class="production-page demand-detail-page" v-loading="loading">
    <div class="page-heading">
      <div>
        <div class="page-crumb"><span>生产管理</span><b>/</b><span>生产需求</span><b>/</b><strong>生产需求详情</strong></div>
        <h1>生产需求详情</h1>
      </div>
      <div class="heading-actions"><el-button @click="$router.back()">返回</el-button><el-button v-if="demand.actions && demand.actions.create_work_order" type="success" @click="openDrawer">创建工单草稿</el-button></div>
    </div>

    <template v-if="demand.id">
    <section class="card trace-card">
      <h3>来源追溯</h3>
      <div class="trace-row">
        <div class="trace-node"><i class="el-icon-document" /><b>ERP账套</b><small>-</small></div><span>→</span>
        <div class="trace-node"><i class="el-icon-document" /><b>销售订单</b><small>{{ demand.sales_order && demand.sales_order.order_no || '-' }}</small></div><span>→</span>
        <div class="trace-node"><i class="el-icon-s-order" /><b>订单行</b><small>{{ demand.sales_order_line && demand.sales_order_line.line_no ? `第 ${demand.sales_order_line.line_no} 行` : '-' }}</small></div><span>→</span>
        <div class="trace-node active-trace"><i class="el-icon-s-operation" /><b>生产需求</b><small>{{ demand.demand_no || '-' }}</small></div>
      </div>
    </section>

    <section class="card context-card">
      <h3>影响上下文</h3>
      <div class="context-grid">
        <p><label>客户</label><strong>{{ demand.customer || (demand.sales_order && demand.sales_order.customer_name) || '-' }}</strong></p>
        <p><label>订单日期</label><strong>{{ demand.sales_order && demand.sales_order.order_date || '-' }}</strong></p>
        <p><label>需求交期</label><strong>{{ demand.required_delivery_date || '-' }}</strong></p>
        <p><label>订单状态</label><el-tag type="success" size="mini">{{ orderStatusText(demand.sales_order && demand.sales_order.order_status) }}</el-tag></p>
        <p><label>生产确认状态</label><el-tag type="warning" size="mini">{{ productionConfirmText(demand.sales_order && demand.sales_order.production_confirm_status) }}</el-tag></p>
        <p><label>联系人</label><strong>{{ demand.sales_order && demand.sales_order.contact_name || '-' }}</strong></p><p><label>联系电话</label><strong>{{ demand.sales_order && demand.sales_order.contact_phone || '-' }}</strong></p><p><label>订单金额</label><strong>{{ money(demand.sales_order && demand.sales_order.total_amount, demand.sales_order && demand.sales_order.currency) }}</strong></p><p><label>订单币种</label><strong>{{ demand.sales_order && demand.sales_order.currency || '-' }}</strong></p>
      </div>
    </section>

    <section class="card product-card">
      <h3>产品明细 <small>（订单行）</small></h3>
      <div class="product-grid">
        <p><label>产品名称</label><strong>{{ demand.product && demand.product.name || '-' }}</strong></p>
        <p><label>SKU / 物料编码</label><strong>{{ demand.product && demand.product.sku || '-' }}</strong></p>
        <p><label>计量单位</label><strong>{{ demand.quantity && demand.quantity.unit_name || '-' }}</strong></p>
        <p><label>物料名称</label><strong>{{ demand.product && demand.product.item_name || '-' }}</strong></p><p><label>规格型号</label><strong>{{ demand.product && demand.product.specification || '-' }}</strong></p>
        <p><label>BOM版本</label><strong>{{ demand.readiness && demand.readiness.bom_version || '-' }}</strong></p>
        <p><label>BOM状态</label><b :class="{ green: demand.readiness && demand.readiness.bom_match_status }">{{ demand.readiness && demand.readiness.bom_match_status || '-' }}</b></p>
      </div>
    </section>

    <section class="card readiness-card">
      <h3>可用性与约束校验</h3>
      <div class="readiness-grid">
        <div><i :class="readinessIcon(demand.create_work_order_gate && demand.create_work_order_gate.allowed)" /><b>创建工单门禁</b><strong>{{ demand.create_work_order_gate && demand.create_work_order_gate.allowed ? '允许' : '受限' }}</strong></div>
        <div><i :class="readinessIcon(demand.readiness && demand.readiness.is_active)" /><b>需求有效</b><strong>{{ demand.readiness && demand.readiness.is_active ? '有效' : '已关闭' }}</strong></div>
        <div><i class="el-icon-info" /><b>BOM匹配</b><strong>{{ demand.readiness && demand.readiness.bom_match_status || '-' }}</strong></div>
        <div><i class="el-icon-info" /><b>已有工单分配</b><strong>{{ demand.readiness && demand.readiness.has_work_order_allocation ? '是' : '否' }}</strong></div>
        <div><i class="el-icon-info" /><b>剩余可拆</b><strong>{{ number(demand.quantity && demand.quantity.remaining_qty) }} {{ demand.quantity && demand.quantity.unit_name || '' }}</strong></div>
      </div>
    </section>

    <section class="split-grid summary-grid">
      <section class="card quantity-card"><h3>数量汇总</h3><div class="quantity-grid"><div><label>需求总量</label><strong>{{ number(demand.quantity && demand.quantity.production_qty) }} {{ demand.quantity && demand.quantity.unit_name || '' }}</strong></div><div><label>已排产数量</label><strong>{{ number(demand.quantity && demand.quantity.allocated_qty) }} {{ demand.quantity && demand.quantity.unit_name || '' }}</strong></div><div class="remaining"><label>剩余未排产</label><strong>{{ number(demand.quantity && demand.quantity.remaining_qty) }} {{ demand.quantity && demand.quantity.unit_name || '' }}</strong></div></div></section>
      <section class="card demand-info"><h3>生产需求信息</h3><p><label>生产需求编号</label><strong>{{ demand.demand_no || '-' }}</strong></p><p><label>需求状态</label><strong>{{ statusText(demand.status) }}</strong></p><p><label>需求日期</label><strong>{{ demand.required_delivery_date || '-' }}</strong></p><p><label>来源类型</label><strong>销售订单</strong></p></section>
    </section>

    <section class="split-grid lower-grid">
      <section class="card existing-card">
        <h3>现有工单 <small>（已关联 {{ workOrderTotal }} 个工单）</small></h3>
        <el-table :data="demand.work_orders || []" size="small">
          <el-table-column prop="work_order_no" label="工单编号" min-width="140" /><el-table-column prop="production_location_name" label="生产地点" min-width="100" /><el-table-column prop="planned_date" label="计划日期" width="110" /><el-table-column prop="target_qty" label="数量" width="75" /><el-table-column label="状态" width="90"><template slot-scope="{ row }"><el-tag size="mini">{{ row.status }}</el-tag></template></el-table-column>
        </el-table>
        <div class="total-line"><span>已排产合计</span><b>{{ number(demand.quantity && demand.quantity.allocated_qty) }} {{ demand.quantity && demand.quantity.unit_name || '' }}</b></div>
        <el-pagination class="child-pagination" small background layout="prev, pager, next" :current-page="workOrderPage" :page-size="workOrderPerPage" :total="workOrderTotal" @current-change="changeWorkOrderPage" />
      </section>
      <section class="card split-card">
        <h3>拆分工作台</h3>
        <div class="split-form"><label>计划数量 <em>*</em><el-input v-model="draft.target_qty" placeholder="请输入计划数量" /></label><label>计划日期 / 生产批次 <em>*</em><div class="inline-fields"><el-date-picker v-model="draft.planned_date" value-format="yyyy-MM-dd" placeholder="选择计划日期" /><el-input v-model="draft.production_batch" placeholder="生产批次" /></div></label><label>负责人（可选）<el-select v-model="draft.responsible_user_legacy_id" placeholder="请选择生产负责人" clearable filterable><el-option v-for="user in productionUsers" :key="user.user_id" :label="displayUser(user)" :value="user.user_id" /></el-select></label><label>生产地点（仅有主数据时）<el-input v-model="draft.production_location_name" placeholder="请输入生产地点" /></label><el-button v-if="demand.actions && demand.actions.create_work_order" type="success" @click="openDrawer">＋ 添加拆分项</el-button></div>
      </section>
    </section>

    <el-drawer title="创建工单草稿" :visible.sync="drawerVisible" direction="rtl" size="390px" append-to-body>
      <div class="drawer-body"><div class="drawer-success"><i class="el-icon-success" /> <span>填写后将保存为 DRAFT</span></div><dl><dt>来源需求</dt><dd>{{ demand.demand_no || '-' }}</dd><dt>可拆数量</dt><dd>{{ number(demand.quantity && demand.quantity.remaining_qty) }} {{ demand.quantity && demand.quantity.unit_name || '' }}</dd></dl><el-form label-position="top"><el-form-item label="拆分数量 *"><el-input v-model="draft.target_qty" placeholder="请输入拆分数量" /></el-form-item><p class="hint">最大可拆分数量：{{ number(demand.quantity && demand.quantity.remaining_qty) }} {{ demand.quantity && demand.quantity.unit_name || '' }}</p><el-form-item label="计划日期 / 生产批次 *"><el-date-picker v-model="draft.planned_date" value-format="yyyy-MM-dd" placeholder="选择计划日期" /><el-input v-model="draft.production_batch" placeholder="生产批次" /></el-form-item><el-form-item label="负责人（可选）"><el-select v-model="draft.responsible_user_legacy_id" placeholder="请选择生产负责人" clearable filterable><el-option v-for="user in productionUsers" :key="user.user_id" :label="displayUser(user)" :value="user.user_id" /></el-select></el-form-item><el-form-item label="生产地点"><el-input v-model="draft.production_location_name" placeholder="请输入生产地点" /></el-form-item></el-form></div><div class="drawer-footer"><el-button @click="drawerVisible = false">取消草稿</el-button><el-button type="success" @click="saveDraft">保存并进入详情</el-button></div>
    </el-drawer>
    </template>
  </section>
</template>

<script>
import { getProductionDemand, createWorkOrderDraft } from '../../../api/erp/production'
import { listUsers } from '../../../api/erp/rbac'

export default {
  name: 'ProductionDemandDetail',
  data: () => ({ loading: false, drawerVisible: false, draftQueryConsumed: false, workOrderPage: 1, workOrderPerPage: 20, workOrderTotal: 0, productionUsers: [], demand: { actions: {}, quantity: {}, product: {}, readiness: {}, create_work_order_gate: {}, work_orders: [] }, draft: { target_qty: '', planned_date: '', production_batch: '', responsible_user_legacy_id: '', production_location_name: '' } }),
  created() { this.fetchDemand(); this.fetchProductionUsers() },
  methods: {
    async fetchDemand() { this.loading = true; try { const response = await getProductionDemand(this.$route.params.id, { page: this.workOrderPage, per_page: this.workOrderPerPage }); this.demand = response.data.data || this.demand; this.workOrderTotal = this.demand.work_orders_pagination && this.demand.work_orders_pagination.total || (this.demand.work_orders || []).length; if (!this.draftQueryConsumed && this.$route.query.draft === '1') { this.draftQueryConsumed = true; if (this.demand.actions && this.demand.actions.create_work_order === true) this.drawerVisible = true } } catch (error) { this.$message.error(error.userMessage || '生产需求加载失败') } finally { this.loading = false } },
    async fetchProductionUsers() { try { const response = await listUsers({ scope: 'production', status: 'normal', page: 1, per_page: 100 }); this.productionUsers = response.data.data || response.data || [] } catch (error) { this.productionUsers = [] } },
    changeWorkOrderPage(page) { this.workOrderPage = page; this.fetchDemand() },
    openDrawer() { if (this.demand.actions && this.demand.actions.create_work_order) this.drawerVisible = true },
    async saveDraft() { if (!(this.demand.actions && this.demand.actions.create_work_order === true)) { this.drawerVisible = false; return this.$message.warning('当前需求不可拆分') } if (!this.draft.target_qty) return this.$message.warning('请输入拆分数量'); try { const response = await createWorkOrderDraft({ production_demand_id: this.demand.id, expected_demand_version: this.demand.business_version, client_command_id: `wo03-${Date.now()}`, ...this.draft }); this.drawerVisible = false; this.$message.success('工单草稿已保存'); const id = response.data.data && response.data.data.id; if (id) this.$router.push(`/production/work-orders/${id}`) } catch (error) { this.$message.error(error.userMessage || '草稿保存失败') } },
    number(value) { return Number(value || 0).toLocaleString('zh-CN', { maximumFractionDigits: 4 }) },
    money(value, currency) { return value === null || value === undefined ? '-' : `${this.number(value)} ${currency || ''}`.trim() },
    statusText(status) { return ({ confirmed: '资金已放行', ready: '资金已放行', pending: '待确认', blocked: '资金阻断' })[status] || status || '待确认' },
    orderStatusText(status) { return ({ draft: '草稿', confirmed: '已确认', cancelled: '已取消' })[status] || status || '待确认' },
    productionConfirmText(status) { return ({ pending: '待生产确认', confirmed: '已确认', blocked: '受阻' })[status] || status || '待生产确认' },
    readinessIcon(value) { return value ? 'el-icon-success ready-icon' : 'el-icon-warning warning-icon' },
    displayUser(user) { return user.display_name || '未命名用户' }
  }
}
</script>

<style scoped>
.production-page{padding:14px 14px 20px;background:#fff;min-height:100vh;color:#243348}.page-heading{display:flex;justify-content:space-between;align-items:flex-start;min-height:66px;margin:0}.page-heading h1{font-size:22px;margin:18px 0 0;color:#14253b}.demand-detail-page .page-heading{min-height:47px}.demand-detail-page .page-heading h1{display:none}.page-crumb{display:flex;align-items:center;gap:13px;color:#17345b;font-size:14px;font-weight:600}.page-crumb b{color:#9aaabd;font-weight:400}.page-crumb strong{font-weight:600}.heading-actions{display:flex;gap:12px}.card{background:#fff;border:1px solid #e0e7ef;border-radius:5px;margin-bottom:10px;padding:13px 20px}.card h3{margin:0 0 12px;color:#1d3048;font-size:14px}.card h3 small{color:#8996a7;font-weight:400}.trace-row{display:flex;align-items:center;gap:12px}.trace-row>span{font-size:20px;color:#91a1b4}.trace-node{flex:1;display:grid;grid-template-columns:30px 1fr;align-items:center;padding:9px 10px;border:1px solid #e7edf2;border-radius:4px}.trace-node i{grid-row:span 2;font-size:20px;color:#98a5b3}.trace-node small{color:#66758a;margin-top:4px}.trace-node.active-trace{border-color:#7bd5a4;background:#f2fbf6}.trace-node.active-trace i,.green{color:#008b4b}.context-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px 30px}.context-grid p,.product-grid p,.demand-info p{margin:0;display:flex;flex-direction:column;gap:5px}.context-grid label,.product-grid label,.demand-info label,.quantity-grid label{color:#8794a4;font-size:12px}.context-grid strong,.product-grid strong,.demand-info strong{color:#33455d;font-weight:500}.product-grid{display:grid;grid-template-columns:2fr 1.5fr 1fr 1fr 1fr 1fr 1fr;gap:12px}.readiness-grid{display:grid;grid-template-columns:repeat(5,1fr);border-top:1px solid #eef2f5}.readiness-grid>div{display:grid;grid-template-columns:22px 1fr;align-items:center;padding:9px 8px;border-right:1px solid #edf1f4}.readiness-grid>div:last-child{border:0}.readiness-grid i{grid-row:span 2;color:#12a15c}.readiness-grid i.el-icon-info{color:#e88a16}.readiness-grid b{font-size:12px;color:#53647a}.readiness-grid strong{font-size:12px;color:#008b4b;font-weight:500}.warning-icon{color:#e88a16!important}.split-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.summary-grid .card{min-height:127px}.quantity-grid{display:grid;grid-template-columns:repeat(3,1fr);border:1px solid #e9eef2;border-radius:4px}.quantity-grid>div{padding:14px;text-align:center;border-right:1px solid #eef1f4}.quantity-grid>div:last-child{border:0}.quantity-grid strong{display:block;margin-top:7px;color:#263b55;font-size:19px;font-weight:500}.quantity-grid .remaining{background:#f0fbf5}.quantity-grid .remaining strong{color:#008b4b}.demand-info{display:grid;grid-template-columns:1fr 1fr;gap:9px 22px}.demand-info h3{grid-column:1/-1;margin-bottom:0}.lower-grid .card{height:294px;min-height:294px;overflow:hidden}.existing-card{padding-bottom:9px}.existing-card ::v-deep .el-table th{background:#f8fafc;color:#53647a}.total-line{display:flex;justify-content:space-between;padding:10px 8px 0}.child-pagination{margin-top:9px;text-align:right}.split-form{display:grid;gap:10px}.split-form label{color:#728198;font-size:12px;display:grid;gap:5px}.split-form em,.drawer-body em{color:#e34c4c;font-style:normal}.split-form .el-date-editor,.inline-fields{width:100%}.inline-fields{display:grid;grid-template-columns:1fr 1fr;gap:7px}.drawer-body{padding:0 22px 80px}.drawer-success{background:#f0fbf5;color:#008b4b;padding:11px;margin-bottom:18px}.drawer-body dl{display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:12px}.drawer-body dt{color:#8794a4}.drawer-body dd{margin:0;color:#33455d}.drawer-body ::v-deep .el-form-item{margin-bottom:16px}.drawer-body ::v-deep .el-date-editor,.drawer-body ::v-deep .el-input{width:100%}.hint{color:#e98219;font-size:12px;margin:-10px 0 14px}.drawer-footer{position:absolute;bottom:0;left:0;right:0;padding:15px 22px;display:flex;justify-content:flex-end;gap:8px;border-top:1px solid #e7ebef;background:#fff}@media(max-width:1100px){.context-grid,.readiness-grid{grid-template-columns:repeat(3,1fr)}.product-grid{grid-template-columns:1fr 1fr}}
</style>

<style scoped>
.trace-card{margin-bottom:0;padding-top:15px;padding-bottom:15px}.context-card{padding:10px 20px 17px}.context-card h3{margin-bottom:7px}.context-grid{row-gap:5px}.context-grid p{gap:1px}.context-grid label{font-size:11px;line-height:1.15}.context-grid strong{font-size:12px;line-height:1.2}
.product-card{padding:15px 20px 23px}.product-card h3{margin-bottom:15px}.readiness-card{padding:10px 20px}.readiness-card h3{margin-bottom:7px}.readiness-grid>div{padding:6px 8px}.readiness-grid b,.readiness-grid strong{line-height:1.2}
.summary-grid{margin-bottom:11px}.summary-grid .card{min-height:127px;padding-top:12px;padding-bottom:12px}.summary-grid .demand-info{gap:6px 22px}.lower-grid .card{height:294px;min-height:294px}
.split-form .el-select,.drawer-body ::v-deep .el-select{width:100%}
@media(max-width:767px){
  .production-page{padding:12px}
  .page-heading{min-height:50px}
  .heading-actions{gap:8px}
  .heading-actions .el-button{padding:9px 12px}
  .card{padding:13px 14px}
  .trace-row{overflow-x:auto;padding-bottom:6px}
  .trace-node{flex:0 0 150px}
  .context-grid,.product-grid,.readiness-grid{grid-template-columns:1fr 1fr;gap:10px 14px}
  .readiness-grid>div:nth-child(2n){border-right:0}
  .split-grid{grid-template-columns:1fr}
  .lower-grid .card{height:auto;min-height:294px}
  .drawer-body{padding-left:16px;padding-right:16px}
  .drawer-footer{padding-left:16px;padding-right:16px}
}
</style>
