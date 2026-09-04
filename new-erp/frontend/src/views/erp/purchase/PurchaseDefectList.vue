<template>
  <section class="defect-page" :class="{ 'drawer-open': drawerVisible }">
    <div class="defect-head">
      <div>
        <h1>不合格品处理 <em>{{ pagination.total }}</em></h1>
        <p>来自采购到货验收结果；选择处理方式后立即生成对应业务单据，不能手工把未执行动作标成已完成。</p>
      </div>
      <div>
        <el-button v-if="$can('purchase.exchange.view')" size="small" icon="el-icon-refresh-right" @click="$router.push('/purchase/exchanges')">采购换货单</el-button>
        <el-button size="small" icon="el-icon-refresh" @click="load">刷新</el-button>
        <el-button size="small" type="success" @click="$router.push('/purchase/receipts')">返回采购到货</el-button>
      </div>
    </div>

    <section class="defect-filter">
      <el-input v-model="query.receipt_no" size="small" placeholder="到货单号" clearable />
      <el-input v-model="query.purchase_order_no" size="small" placeholder="采购订单号" clearable />
      <el-select v-model="query.supplier_id" size="small" clearable filterable placeholder="供应商">
        <el-option v-for="s in suppliers" :key="s.id" :label="s.supplier_name" :value="s.id" />
      </el-select>
      <el-input v-model="query.keyword" size="small" placeholder="物料编码/名称" clearable />
      <el-select v-model="query.handling_status" size="small" clearable placeholder="处理状态">
        <el-option label="待处理" value="pending" />
        <el-option label="处理中" value="processing" />
        <el-option label="已完成" value="completed" />
        <el-option label="已取消" value="cancelled" />
      </el-select>
      <el-select v-model="query.handling_method" size="small" clearable placeholder="处理方式">
        <el-option label="退供应商" value="return_supplier" />
        <el-option label="换货" value="exchange" />
        <el-option label="让步接收" value="concession" />
        <el-option label="返修" value="repair" />
        <el-option label="报废" value="scrap" />
        <el-option label="待决定" value="pending" />
      </el-select>
      <el-date-picker v-model="query.dateRange" type="daterange" size="small" range-separator="至" start-placeholder="开始日期" end-placeholder="结束日期" value-format="yyyy-MM-dd" />
      <el-button size="small" type="success" @click="search">查询</el-button>
      <el-button size="small" @click="resetQuery">重置</el-button>
    </section>

    <div class="defect-table-card">
      <el-alert title="退供应商会生成正式采购退货单；让步接收会真实转入待过账合格数量；换货、返修和报废会生成处理中业务单，不能直接标记完成。已入库后的质量问题请从库存余额发起。" type="warning" :closable="false" show-icon class="defect-tip" />
      <el-table v-loading="loading" :data="defectRows" size="mini" border empty-text="暂无不合格或待处理到货明细">
        <el-table-column prop="receipt_no" label="到货单号" min-width="150" />
        <el-table-column prop="purchase_order_no" label="采购订单" min-width="150" />
        <el-table-column prop="supplier_name" label="供应商" min-width="170" />
        <el-table-column prop="item_code" label="物料编码" min-width="150" />
        <el-table-column prop="item_name" label="物料名称" min-width="180" />
        <el-table-column label="到货" width="82" align="right"><template slot-scope="{ row }">{{ qtyText(row.receipt_qty, row.unit_name) }}</template></el-table-column>
        <el-table-column label="不合格" width="88" align="right"><template slot-scope="{ row }">{{ qtyText(row.unqualified_qty, row.unit_name) }}</template></el-table-column>
        <el-table-column label="剩余" width="82" align="right"><template slot-scope="{ row }">{{ qtyText(row.remaining_qty, row.unit_name) }}</template></el-table-column>
        <el-table-column label="处理方式" width="96">
          <template slot-scope="{ row }">{{ methodText(row.handling_method) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="82">
          <template slot-scope="{ row }"><el-tag size="mini" :type="row.handling_status === 'completed' ? 'success' : 'warning'">{{ statusText(row.handling_status) }}</el-tag></template>
        </el-table-column>
        <el-table-column label="操作" width="96" fixed="right">
          <template slot-scope="{ row }">
            <el-button type="text" size="mini" @click="openHandle(row)">{{ Number(row.remaining_qty || 0) > 0 ? '处理' : '查看处理' }}</el-button>
          </template>
        </el-table-column>
      </el-table>
      <div class="defect-pager">
        <span />
        <el-pagination small layout="total, sizes, prev, pager, next" :page-sizes="[10, 20, 50, 100]" :current-page.sync="pagination.page" :page-size.sync="pagination.per_page" :total="pagination.total" @current-change="load" @size-change="handleSizeChange" />
      </div>
    </div>

    <aside v-if="drawerVisible" class="defect-drawer">
      <div class="drawer-head">
        <h2>不合格品处理</h2>
        <i class="el-icon-close" @click="drawerVisible=false" />
      </div>
      <div class="drawer-body">
        <section class="drawer-card">
          <h3>到货与物料</h3>
          <dl>
            <dt>到货单号</dt><dd>{{ selectedRow.receipt_no }}</dd>
            <dt>采购订单</dt><dd>{{ selectedRow.purchase_order_no }}</dd>
            <dt>供应商</dt><dd>{{ selectedRow.supplier_name }}</dd>
            <dt>物料编码</dt><dd>{{ selectedRow.item_code }}</dd>
            <dt>物料名称</dt><dd>{{ selectedRow.item_name }}</dd>
          </dl>
        </section>
        <section class="drawer-card">
          <h3>验收数量</h3>
          <dl>
            <dt>到货数量</dt><dd>{{ qtyText(selectedRow.receipt_qty, selectedRow.unit_name) }}</dd>
            <dt>合格数量</dt><dd>{{ qtyText(selectedRow.qualified_qty, selectedRow.unit_name) }}</dd>
            <dt>不合格数量</dt><dd>{{ qtyText(selectedRow.unqualified_qty, selectedRow.unit_name) }}</dd>
            <dt>质量待处理数量</dt><dd>{{ qtyText(selectedRow.remaining_qty, selectedRow.unit_name) }}</dd>
          </dl>
        </section>
        <section v-if="canHandleSelected" class="drawer-card">
          <h3>执行处理</h3>
          <el-alert class="defect-method-tip" :title="methodTip" type="info" :closable="false" show-icon />
          <el-form label-position="top" size="small">
            <el-form-item label="瑕疵原因"><el-input v-model="handleForm.defect_reason" placeholder="如：尺寸偏差、外观划伤、少件" /></el-form-item>
            <el-form-item label="瑕疵描述"><el-input v-model="handleForm.defect_description" type="textarea" :rows="3" /></el-form-item>
            <el-form-item label="责任方"><el-select v-model="handleForm.responsible_party" class="full" placeholder="请选择责任方"><el-option label="供应商" value="供应商" /><el-option label="采购" value="采购" /><el-option label="仓库" value="仓库" /><el-option label="物流" value="物流" /><el-option label="待判定" value="待判定" /></el-select></el-form-item>
            <el-form-item label="处理方式">
              <el-select v-model="handleForm.handling_method" class="full">
                <el-option label="退供应商" value="return_supplier" />
                <el-option label="换货" value="exchange" />
                <el-option label="让步接收" value="concession" />
                <el-option label="返修" value="repair" />
                <el-option label="报废" value="scrap" />
                <el-option label="待决定" value="pending" />
              </el-select>
            </el-form-item>
            <el-form-item :label="`处理数量（${selectedRow.unit_name || '基本单位'}）`"><el-input-number ref="handleQtyInput" v-model="handleForm.handling_qty" :min="0" :max="maxHandleQty" controls-position="right" @input="syncHandlingQty" @change="syncHandlingQty" /></el-form-item>
            <el-form-item label="处理备注"><el-input v-model="handleForm.remark" type="textarea" :rows="3" /></el-form-item>
          </el-form>
        </section>
        <section v-if="selectedRow.handlings && selectedRow.handlings.length" class="drawer-card">
          <h3>历史处理</h3>
          <div v-for="h in selectedRow.handlings" :key="h.id" class="history-line">
            <div class="history-title">
              <b>{{ methodText(h.handling_method) }} / {{ qtyText(h.handling_qty, selectedRow.unit_name) }}</b>
              <el-tag size="mini" :type="h.handling_status === 'completed' ? 'success' : h.handling_status === 'cancelled' ? 'info' : 'warning'">{{ statusText(h.handling_status) }}</el-tag>
            </div>
            <span>处理单：{{ h.handling_no || '--' }}　业务单：{{ h.business_doc_no || '--' }}</span>
            <small>当前环节：{{ stepText(h.current_step) }}</small>
            <small>原因：{{ h.defect_reason || '-' }}　责任方：{{ h.responsible_party || '-' }}</small>
            <small v-if="h.result_description">处理结果：{{ h.result_description }}</small>
            <small>创建：{{ dateTime(h.created_at) }}<template v-if="h.completed_at">　完成：{{ dateTime(h.completed_at) }}</template></small>
            <div v-if="h.logs && h.logs.length" class="handling-logs">
              <div v-for="log in h.logs" :key="log.id"><i />{{ dateTime(log.created_at) }}　{{ log.operator_name || '系统' }}　{{ log.content || actionText(log.action) }}</div>
            </div>
            <div class="history-actions">
              <el-button v-if="h.business_doc_type === 'purchase_return'" type="text" size="mini" @click="$router.push('/purchase/returns')">查看采购退货</el-button>
              <el-button v-if="h.exchange_order" type="text" size="mini" @click="$router.push(`/purchase/exchanges/${h.exchange_order.id}`)">查看采购换货单</el-button>
              <el-button v-if="h.replacement_receipt_id" type="text" size="mini" @click="$router.push(`/purchase/receipts/${h.replacement_receipt_id}/edit`)">查看替换品到货单</el-button>
              <el-button v-for="action in handlingActions(h)" :key="action.value" type="text" size="mini" @click="runHandlingAction(h, action)">{{ action.label }}</el-button>
            </div>
          </div>
        </section>
        <el-alert title="系统根据处理方式决定状态：退供应商/让步接收在业务动作成功后完成；换货/返修/报废生成处理中单据，后续业务动作完成前不会减少剩余责任。" type="warning" :closable="false" show-icon />
      </div>
      <div class="drawer-footer">
        <el-button size="small" @click="drawerVisible=false">取消</el-button>
        <el-button v-if="canHandleSelected" size="small" type="success" @click="saveHandle">执行处理</el-button>
      </div>
    </aside>
  </section>
</template>

<script>
import { listEntity } from '@/api/erp/master'
import { actionDefectHandling, listDefectHandlings, saveDefectHandling } from '@/api/erp/purchase'

export default {
  name: 'PurchaseDefectList',
  data() {
    return {
      loading: false,
      rows: [],
      suppliers: [],
      query: { receipt_no: '', purchase_order_no: '', supplier_id: '', keyword: '', handling_status: '', handling_method: '', dateRange: [] },
      pagination: { page: 1, per_page: 20, total: 0 },
      drawerVisible: false,
      selectedRow: {},
      handleForm: this.emptyHandleForm()
    }
  },
  computed: {
    defectRows() {
      return this.rows.map(row => {
        const latest = row.latest_handling || {}
        return {
          ...row,
          handling_method: latest.handling_method || 'pending',
          handling_status: latest.handling_status || 'pending',
          business_doc_no: latest.business_doc_no || '--'
        }
      })
    },
    maxHandleQty() {
      return Number(this.selectedRow.remaining_qty || 0)
    },
    canHandleSelected() {
      return this.$can('purchase.quality.handle') && Number(this.selectedRow.remaining_qty || 0) > 0
    },
    methodTip() {
      return ({
        return_supplier: '退供应商：立即生成正式采购退货单；未入库不合格品不伪造库存出入库流水。',
        exchange: '换货：生成正式采购换货单，逐步登记原货退回、供应商收货、补发物流、替换验收及序列号对应；替换品不新增应付。',
        concession: '让步接收：库存未过账前可把处理数量转为合格数量，后续库存过账进入正常库存。',
        repair: '返修：生成返修处理单并进入处理中，返修完成和复检通过后才能完成。',
        scrap: '报废：生成报废审批单并进入处理中，审批和损失确认完成前不会标记完成。',
        pending: '待决定：只记录原因和说明，不关闭待处理数量。'
      })[this.handleForm.handling_method] || ''
    }
  },
  created() {
    if (this.$route.query.receipt_no) this.query.receipt_no = this.$route.query.receipt_no
    this.loadSuppliers()
    this.load()
  },
  methods: {
    async loadSuppliers() {
      const res = await listEntity('suppliers', { per_page: 200 })
      this.suppliers = res.data.data || []
    },
    async load() {
      this.loading = true
      try {
        const res = await listDefectHandlings({
          page: this.pagination.page,
          per_page: this.pagination.per_page,
          receipt_no: this.query.receipt_no,
          purchase_order_no: this.query.purchase_order_no,
          supplier_id: this.query.supplier_id,
          keyword: this.query.keyword,
          handling_status: this.query.handling_status,
          handling_method: this.query.handling_method,
          date_from: this.query.dateRange?.[0] || '',
          date_to: this.query.dateRange?.[1] || ''
        })
        this.rows = res.data.data || []
        this.pagination.page = Number(res.data.current_page || 1)
        this.pagination.per_page = Number(res.data.per_page || this.pagination.per_page)
        this.pagination.total = Number(res.data.total || 0)
      } catch (e) {
        this.$message.error(e.userMessage || '不合格品数据加载失败')
      } finally {
        this.loading = false
      }
    },
    search() {
      this.pagination.page = 1
      this.load()
    },
    resetQuery() {
      this.query = { receipt_no: '', purchase_order_no: '', supplier_id: '', keyword: '', handling_status: '', handling_method: '', dateRange: [] }
      this.search()
    },
    handleSizeChange(size) {
      this.pagination.per_page = size
      this.pagination.page = 1
      this.load()
    },
    emptyHandleForm() {
      return { defect_reason: '', defect_description: '', responsible_party: '待判定', handling_method: 'pending', handling_qty: 0, remark: '' }
    },
    openHandle(row) {
      this.selectedRow = { ...row }
      this.handleForm = { ...this.emptyHandleForm(), receipt_item_id: row.receipt_item_id, handling_qty: Number(row.remaining_qty || 0) }
      this.drawerVisible = true
    },
    methodText(v) {
      return ({ pending: '待决定', return_supplier: '退供应商', exchange: '换货', concession: '让步接收', repair: '返修', scrap: '报废' })[v] || '-'
    },
    statusText(v) {
      return ({ pending: '待处理', processing: '处理中', completed: '已完成', cancelled: '已取消' })[v] || '-'
    },
    stepText(v) {
      return ({ pending_decision: '待决定', return_pending_approval: '退货单待审核', return_pending_outbound: '待退回供应商', return_completed: '已退回供应商', exchange_pending_original_return: '待退回原不合格品', exchange_pending_replacement_receipt: '等待替换品到货验收', exchange_completed: '换货完成', concession_pending_approval: '让步接收待审批', concession_completed: '让步接收完成', repair_pending_start: '待开始返修', repair_in_progress: '返修中', repair_completed: '返修复检完成', scrap_pending_approval: '报废待审批', scrap_pending_confirmation: '待确认实物报废', scrap_completed: '报废完成' })[v] || v || '-'
    },
    actionText(v) {
      return ({ create: '创建处理单', create_return: '生成采购退货单', approve_concession: '批准让步接收', start_repair: '开始返修', complete_repair: '返修完成', approve_scrap: '批准报废', confirm_scrap: '确认实物报废', confirm_exchange_return: '确认原件已退回', complete_exchange: '确认换货完成' })[v] || v || '-'
    },
    dateTime(value) {
      return value ? String(value).replace('T', ' ').replace(/\.\d+Z?$/, '').replace(/Z$/, '').slice(0, 19) : '--'
    },
    handlingActions(h) {
      const map = {
        concession_pending_approval: [{ value: 'approve_concession', label: '审批让步接收', needsResult: true }],
        repair_pending_start: [{ value: 'start_repair', label: '开始返修' }],
        repair_in_progress: [{ value: 'complete_repair', label: '完成返修并复检', needsResult: true }],
        scrap_pending_approval: [{ value: 'approve_scrap', label: '审批报废' }],
        scrap_pending_confirmation: [{ value: 'confirm_scrap', label: '确认实物已报废', needsResult: true }],
        exchange_pending_original_return: [],
        exchange_pending_replacement_receipt: []
      }
      return map[h.current_step] || []
    },
    async runHandlingAction(handling, action) {
      try {
        let result = ''
        if (action.needsResult) {
          const response = await this.$prompt(`请输入“${action.label}”的执行结果和复检/处置结论`, '业务确认', { confirmButtonText: action.label, cancelButtonText: '取消', inputType: 'textarea', inputValidator: value => String(value || '').trim() ? true : '处理结果不能为空' })
          result = response.value
        } else {
          await this.$confirm(`确认执行“${action.label}”？系统将记录操作人、时间和状态变化。`, '业务确认', { type: 'warning', confirmButtonText: action.label })
        }
        await actionDefectHandling(handling.id, { action: action.value, result_description: result })
        this.$message.success(`${action.label}已完成`)
        await this.load()
        const fresh = this.defectRows.find(row => String(row.id) === String(this.selectedRow.id))
        if (fresh) this.openHandle(fresh)
      } catch (e) {
        if (e !== 'cancel' && e !== 'close') this.$message.error(e.userMessage || `${action.label}失败`)
      }
    },
    qtyText(value, unit) {
      return `${Number(value || 0).toLocaleString('zh-CN', { maximumFractionDigits: 8 })} ${unit || ''}`.trim()
    },
    syncHandlingQty(value) {
      this.handleForm.handling_qty = Number(value || 0)
    },
    async saveHandle() {
      if (!this.selectedRow.id) return
      const visibleInput = this.$refs.handleQtyInput && this.$refs.handleQtyInput.$el && this.$refs.handleQtyInput.$el.querySelector('input')
      this.handleForm.handling_qty = Number(visibleInput ? visibleInput.value : this.handleForm.handling_qty || 0)
      if (!this.handleForm.defect_reason) return this.$message.warning('请输入处理原因')
      if (!this.handleForm.responsible_party) return this.$message.warning('请输入责任方')
      if (Number(this.handleForm.handling_qty || 0) <= 0) return this.$message.warning('请输入处理数量')
      try {
        await saveDefectHandling(this.handleForm)
        this.$message.success('处理已执行，已生成对应业务单据')
        this.drawerVisible = false
        this.pagination.page = 1
        await this.load()
      } catch (e) {
        this.$message.error(e.userMessage || '保存处理失败')
      }
    }
  }
}
</script>

<style scoped>
.defect-page{position:relative;min-height:calc(100vh - 52px);min-width:960px;padding:16px 18px;background:#f7f8f9;transition:padding-right .18s}.defect-page.drawer-open{padding-right:408px}.defect-head{height:64px;display:flex;justify-content:space-between;align-items:flex-start}.defect-head h1{margin:0;font-size:17px}.defect-head h1 em{margin-left:7px;color:#6c7882;font-style:normal;font-size:12px;font-weight:400}.defect-head p{margin:4px 0 0;color:#77828c;font-size:10px}.defect-head>div:last-child{display:flex;gap:8px}.defect-filter{min-height:58px;margin-bottom:12px;padding:10px 12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:#fff;border:1px solid #dfe5e9;border-radius:4px}.defect-filter .el-input{width:180px}.defect-filter .el-select{width:170px}.defect-filter .el-date-editor{width:260px}.defect-table-card{background:#fff;border:1px solid #dfe5e9;border-radius:4px;overflow:hidden}.defect-table-card ::v-deep .el-table__body-wrapper{overflow-x:auto}.defect-table-card ::v-deep .cell{overflow-wrap:anywhere}.defect-tip{margin:10px}.defect-pager{height:46px;padding:0 12px;display:flex;align-items:center;justify-content:space-between;color:#69747d;border-top:1px solid #edf0f2}.defect-method-tip{margin-bottom:10px}.history-line{display:grid;gap:3px;padding:8px 0;border-bottom:1px solid #eef2f5}.history-line:last-child{border-bottom:0}.history-line span{color:#66717c}.history-line small{color:#8a96a3}.defect-drawer{position:fixed;top:52px;right:0;bottom:0;width:390px;background:#fff;border-left:1px solid #dfe5e9;z-index:9;box-shadow:-8px 0 24px rgba(25,43,58,.08)}.drawer-head{height:58px;padding:0 14px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #e2e7eb}.drawer-head h2{font-size:16px}.drawer-head i{cursor:pointer;color:#65717b}.drawer-body{height:calc(100% - 122px);padding:10px 12px 84px;overflow:auto}.drawer-card{min-width:0;margin-bottom:8px;padding:12px;border:1px solid #e1e7eb;border-radius:4px}.drawer-card h3{margin:0 0 10px;font-size:12px}.drawer-card dl{display:grid;grid-template-columns:86px minmax(0,1fr);gap:9px 10px;margin:0}.drawer-card dt{color:#76818b}.drawer-card dd{min-width:0;margin:0;color:#2d3842;overflow-wrap:anywhere}.full,.drawer-card .el-input,.drawer-card .el-select,.drawer-card .el-input-number{width:100%;max-width:100%}.drawer-card ::v-deep .el-input__inner{width:100%;padding-right:30px}.drawer-card ::v-deep .el-input-number .el-input__inner{padding-left:10px;padding-right:42px;text-align:left}.drawer-card ::v-deep textarea{width:100%;box-sizing:border-box}.drawer-footer{position:absolute;left:0;right:0;bottom:0;height:64px;padding:12px;display:flex;gap:10px;background:#fff;border-top:1px solid #e2e7eb}.drawer-footer .el-button{flex:1}@media(max-width:1180px){.defect-page.drawer-open{padding-right:18px}.defect-drawer{width:390px}}
.history-title{display:flex;align-items:center;justify-content:space-between;gap:8px}.handling-logs{margin-top:3px;padding:7px 8px;background:#f7f9fa;border-radius:3px;color:#687680;font-size:11px}.handling-logs div{padding:3px 0}.handling-logs i{display:inline-block;width:5px;height:5px;margin-right:6px;border-radius:50%;background:#13a063;vertical-align:middle}.history-actions{display:flex;gap:10px;flex-wrap:wrap}
</style>
