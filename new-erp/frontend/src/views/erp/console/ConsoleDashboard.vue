<template>
  <section class="console-page">
    <div class="console-hero">
      <div class="sun-badge"><i class="el-icon-sunny" /></div>
      <div class="hero-copy">
        <h1>{{ greeting }}，{{ currentUserName }}</h1>
        <p>今天是 {{ todayText }}　当前有 <b>{{ totalTodo }}</b> 项待办、<b>{{ warningRows.length }}</b> 项异常需要处理</p>
      </div>
      <div class="hero-art"><i class="el-icon-document-checked" /></div>
      <div class="quick-actions">
        <el-button size="small" icon="el-icon-document-add" @click="$router.push('/purchase/requests/create')">新建采购需求</el-button>
        <el-button size="small" icon="el-icon-truck" @click="$router.push('/purchase/receipts')">采购到货验收</el-button>
        <el-button size="small" icon="el-icon-sort" @click="$router.push('/inventory/adjustments')">库存调整</el-button>
        <el-button size="small" icon="el-icon-share" @click="$router.push('/bom/create')">新建BOM</el-button>
      </div>
    </div>

    <div class="todo-cards">
      <article v-for="card in taskCards" :key="card.key" class="todo-card" @click="$router.push(card.to)">
        <i :class="[card.icon, card.tone]" />
        <div><span>{{ card.name }}</span><strong>{{ card.count }}</strong><small>当前实时待办</small></div>
        <em>查看 <i class="el-icon-arrow-right" /></em>
      </article>
    </div>

    <div class="console-grid">
      <section class="console-panel todo-panel">
        <div class="panel-head">
          <h2>我的待办</h2>
          <el-tabs v-model="todoTab">
            <el-tab-pane label="我的待办" name="todo" />
            <el-tab-pane label="即将超时" name="soon" />
          </el-tabs>
        </div>
        <el-table v-if="visibleTodos.length" :data="visibleTodos" size="mini" class="console-table" height="230">
          <el-table-column label="优先级" width="66"><template slot-scope="{ row }"><el-tag size="mini" :type="priorityType(row.priority)">{{ row.priority }}</el-tag></template></el-table-column>
          <el-table-column prop="type" label="业务类型" width="90" />
          <el-table-column label="单据编号" min-width="138"><template slot-scope="{ row }"><a @click.stop="$router.push(row.to)">{{ row.no }}</a></template></el-table-column>
          <el-table-column prop="summary" label="业务摘要" min-width="180" show-overflow-tooltip />
          <el-table-column prop="owner" label="提交人" width="76" />
          <el-table-column prop="time" label="提交时间" width="126" />
          <el-table-column prop="wait" label="等待时长" width="78" />
          <el-table-column label="当前状态" width="82"><template slot-scope="{ row }"><el-tag size="mini" type="warning">{{ row.statusText }}</el-tag></template></el-table-column>
          <el-table-column label="操作" width="78"><template slot-scope="{ row }"><el-button type="text" size="mini" @click="$router.push(row.to)">{{ row.action }}</el-button></template></el-table-column>
        </el-table>
        <div v-else class="console-empty"><i class="el-icon-finished" /><strong>当前没有需要处理的事项</strong><span>可通过右上角快捷入口创建新业务，或进入业务模块查看历史记录。</span></div>
        <button class="panel-link" @click="$router.push('/purchase/requests')">查看全部待办 <i class="el-icon-arrow-right" /></button>
      </section>

      <section class="console-panel warning-panel">
        <div class="panel-head warning-head">
          <h2>异常与预警</h2>
          <nav>
            <button v-for="tab in warningTabs" :key="tab" :class="{ active: warningTab === tab }" @click="warningTab = tab">{{ tab }}</button>
          </nav>
        </div>
        <div v-if="filteredWarnings.length" class="warning-list">
          <article v-for="item in filteredWarnings" :key="item.id" @click="$router.push(item.to)">
            <el-tag size="mini" :type="warningType(item.level)">{{ item.level }}</el-tag>
            <div><b>{{ item.title }}</b><span>{{ item.object }}</span></div>
            <p>{{ item.desc }}</p>
            <time>{{ item.time }}</time>
            <a>{{ item.action }}</a>
          </article>
        </div>
        <div v-else class="console-empty small"><i class="el-icon-circle-check" /><strong>当前没有异常与预警</strong></div>
        <button class="panel-link" @click="$router.push('/purchase/receipts')">查看全部异常与预警 <i class="el-icon-arrow-right" /></button>
      </section>
    </div>

    <div class="module-overview">
      <article v-for="module in modules" :key="module.title" class="module-card">
        <h3><i :class="module.icon" />{{ module.title }}</h3>
        <dl>
          <template v-for="metric in module.metrics">
            <dt :key="metric.label + '-dt'">{{ metric.label }}</dt><dd :key="metric.label + '-dd'">{{ metric.value }}</dd>
          </template>
        </dl>
        <div class="module-links"><button v-for="link in module.links" :key="link.text" @click="$router.push(link.to)">{{ link.text }}</button></div>
      </article>
    </div>


    <div class="bottom-grid">
      <section class="console-panel chart-panel">
        <div class="panel-head"><h2>最近30天采购趋势</h2></div>
        <div v-if="purchaseTrendTotal" class="mini-chart">
          <span v-for="(bar,index) in purchaseTrend" :key="index" :style="{ height: `${bar}%` }" />
        </div>
        <div v-else class="console-empty chart-empty"><span>暂无可展示趋势数据</span></div>
        <button class="panel-link" @click="$router.push('/purchase/orders')">查看明细 <i class="el-icon-arrow-right" /></button>
      </section>
      <section class="console-panel chart-panel">
        <div class="panel-head"><h2>最近30天库存事务趋势</h2></div>
        <div v-if="inventoryTrendTotal" class="line-chart">
          <span v-for="(bar,index) in inventoryTrend" :key="index" :style="{ height: `${bar}%` }" />
        </div>
        <div v-else class="console-empty chart-empty"><span>暂无可展示趋势数据</span></div>
        <button class="panel-link" @click="$router.push('/inventory/transactions')">查看库存流水明细 <i class="el-icon-arrow-right" /></button>
      </section>
      <section class="console-panel ops-panel">
        <div class="panel-head"><h2>最近业务更新</h2></div>
        <el-table v-if="recentOps.length" :data="recentOps" size="mini" height="215">
          <el-table-column prop="time" label="操作时间" width="118" />
          <el-table-column prop="operator" label="操作人" width="70" />
          <el-table-column prop="module" label="业务模块" width="70" />
          <el-table-column prop="object" label="业务对象" min-width="120" />
          <el-table-column prop="content" label="更新内容" min-width="130" show-overflow-tooltip />
        </el-table>
        <div v-else class="console-empty chart-empty"><span>暂无最近操作记录</span></div>
      </section>
    </div>
  </section>
</template>

<script>
import { listEntity, listRelations } from '@/api/erp/master'
import { listPurchase, listDefectHandlings } from '@/api/erp/purchase'
import { listPendingReceipts, listInventoryAdjustments, listInventoryBalances, listInventoryTransactions } from '@/api/erp/inventory'
import { listBoms } from '@/api/erp/bom'

export default {
  name: 'ConsoleDashboard',
  data: () => ({
    loading: false,
    todoTab: 'todo',
    warningTab: '全部',
    warningTabs: ['全部', '主数据', '采购', '库存', 'BOM'],
    failedModules: [],
    totals: {},
    rows: {
      requests: [], plans: [], orders: [], receipts: [], defects: [], pendingReceipts: [],
      adjustments: [], balances: [], transactions: [], boms: [], relations: []
    }
  }),
  computed: {
    todayText() { return new Intl.DateTimeFormat('zh-CN', { year: 'numeric', month: 'long', day: 'numeric', weekday: 'long' }).format(new Date()) },
    currentUserName() { const user = JSON.parse(localStorage.getItem('erp_user') || '{}'); return user.nickname || user.username || '用户' },
    greeting() { const hour = new Date().getHours(); return hour < 6 ? '夜深了' : hour < 12 ? '早上好' : hour < 18 ? '下午好' : '晚上好' },
    taskCards() {
      return [
        { key: 'req', name: '待确认采购需求', count: this.count('requestsDraft'), icon: 'el-icon-document', tone: 'blue', to: '/purchase/requests' },
        { key: 'plan', name: '待审核采购计划', count: this.count('plansSubmitted'), icon: 'el-icon-tickets', tone: 'orange', to: '/purchase/plans' },
        { key: 'order', name: '待审核采购订单', count: this.count('ordersSubmitted'), icon: 'el-icon-notebook-2', tone: 'green', to: '/purchase/orders' },
        { key: 'receipt', name: '待过账采购到货', count: this.count('pendingReceipts'), icon: 'el-icon-truck', tone: 'purple', to: '/inventory/posting' },
        { key: 'adjust', name: '待处理库存调整', count: this.count('adjustmentsSubmitted'), icon: 'el-icon-sort', tone: 'blue', to: '/inventory/adjustments' },
        { key: 'bom', name: '待审核/待启用BOM', count: this.count('bomTodo'), icon: 'el-icon-share', tone: 'orange', to: '/bom/boms' }
      ]
    },
    totalTodo() { return this.taskCards.reduce((sum, item) => sum + Number(item.count || 0), 0) },
    todoRows() {
      const rows = []
      this.rows.requests.filter(r => r.request_status === 'draft').slice(0, 2).forEach(r => rows.push(this.todo('高', '采购需求', r.request_no, r.remark || '新增采购需求待确认', r.created_by || '系统', r.created_at, '待确认', '去确认', '/purchase/requests')))
      this.rows.plans.filter(r => r.plan_status === 'submitted' || r.audit_status === 'pending').slice(0, 2).forEach(r => rows.push(this.todo('中', '采购计划', r.plan_no, '采购计划等待审核', r.created_by || '系统', r.created_at, '待审核', '去审核', '/purchase/plans')))
      this.rows.orders.filter(r => r.purchase_status === 'submitted' || r.audit_status === 'pending').slice(0, 2).forEach(r => rows.push(this.todo('中', '采购订单', r.purchase_order_no, '采购订单待审核', r.created_by || '系统', r.created_at, '待审核', '去审核', '/purchase/orders')))
      this.rows.pendingReceipts.slice(0, 2).forEach(r => rows.push(this.todo('高', '采购到货', r.receipt_no, `${r.item_count || 0}种物料等待库存过账`, r.confirmed_by || '系统', r.confirmed_at || r.created_at, '待过账', '去过账', '/inventory/posting')))
      this.rows.adjustments.filter(r => r.status === 'submitted' || r.adjustment_status === 'submitted').slice(0, 2).forEach(r => rows.push(this.todo('中', '库存调整', r.adjustment_no, '库存调整单等待确认过账', r.created_by || '系统', r.created_at, '待处理', '去处理', '/inventory/adjustments')))
      this.rows.boms.filter(r => r.audit_status === 'pending' && r.submitted_at).slice(0, 2).forEach(r => rows.push(this.todo('低', 'BOM审核', r.bom_no, 'BOM版本等待审核', r.created_by || '系统', r.submitted_at || r.created_at, '待审核', '去审核', '/bom/boms')))
      return rows.slice(0, 8)
    },
    visibleTodos() {
      if (this.todoTab === 'soon') return this.todoRows.filter(r => r.priority === '高')
      return this.todoRows
    },
    warningRows() {
      const rows = []
      this.rows.pendingReceipts.slice(0, 3).forEach((r, i) => rows.push({ id: `receipt-${i}`, level: i ? '警告' : '严重', module: '采购', title: '到货长时间未过账', object: r.receipt_no || '--', desc: '该到货单已确认，等待库存过账。', time: this.shortTime(r.confirmed_at || r.created_at), action: '去过账', to: '/inventory/posting' }))
      this.rows.defects.filter(r => Number(r.pending_qty || 0) > 0).slice(0, 2).forEach((r, i) => rows.push({ id: `defect-${i}`, level: '警告', module: '采购', title: '到货存在不合格数量', object: r.receipt_no || r.item_code || '--', desc: `${r.pending_qty || 0} 件不合格数量待处理。`, time: this.shortTime(r.created_at), action: '去处理', to: '/purchase/receipts' }))
      const skuTotal = this.count('skus')
      const relationTotal = this.count('relations')
      if (skuTotal > relationTotal) rows.push({ id: 'sku-relation', level: '提醒', module: '主数据', title: 'SKU未关联Item', object: `SKU ${skuTotal}`, desc: '部分SKU尚未建立SKU-Item关系。', time: '当前', action: '去关联', to: '/master/sku-item-relations' })
      this.rows.boms.filter(r => r.audit_status === 'pending' && r.submitted_at).slice(0, 2).forEach((r, i) => rows.push({ id: `bom-${i}`, level: '警告', module: 'BOM', title: 'BOM等待审核', object: r.bom_no, desc: '该BOM已提交，等待审核或启用。', time: this.shortTime(r.submitted_at), action: '查看BOM', to: '/bom/boms' }))
      return rows.slice(0, 8)
    },
    filteredWarnings() {
      return this.warningTab === '全部' ? this.warningRows : this.warningRows.filter(item => item.module === this.warningTab)
    },
    modules() {
      return [
        { title: '主数据中心', icon: 'el-icon-menu', metrics: [
          { label: 'Product总数', value: this.count('products') }, { label: 'SKU总数', value: this.count('skus') },
          { label: 'Item总数', value: this.count('items') }, { label: 'SKU-Item关联率', value: this.relationRate },
          { label: '停用物料', value: this.disabledItems }, { label: '缺少Item关联SKU', value: Math.max(0, this.count('skus') - this.count('relations')) }
        ], links: [{ text: '产品管理', to: '/master/products' }, { text: 'SKU管理', to: '/master/skus' }, { text: '物料管理', to: '/master/items' }, { text: 'SKU-Item关系', to: '/master/sku-item-relations' }] },
        { title: '采购管理', icon: 'el-icon-shopping-cart-2', metrics: [
          { label: '本月采购需求', value: this.count('requests') }, { label: '本月采购订单', value: this.count('orders') },
          { label: '待到货', value: this.ordersNotReceived }, { label: '待过账', value: this.count('pendingReceipts') },
          { label: '本月采购金额', value: this.purchaseAmount }, { label: '不合格到货', value: this.rows.defects.length }
        ], links: [{ text: '采购需求', to: '/purchase/requests' }, { text: '采购计划', to: '/purchase/plans' }, { text: '采购订单', to: '/purchase/orders' }, { text: '到货验收', to: '/purchase/receipts' }, { text: '价格历史', to: '/purchase/orders' }] },
        { title: '库存管理', icon: 'el-icon-house', metrics: [
          { label: '有库存物料', value: this.stockItems }, { label: '库存总数量', value: this.stockQty },
          { label: '今日入库事务', value: this.todayInTx }, { label: '今日库存流水', value: this.todayTx },
          { label: '待处理库存调整', value: this.count('adjustmentsSubmitted') }, { label: '库存预警', value: this.inventoryWarnings }
        ], links: [{ text: '库存余额', to: '/inventory/balances' }, { text: '库存流水', to: '/inventory/transactions' }, { text: '到货过账', to: '/inventory/posting' }, { text: '库存调整', to: '/inventory/adjustments' }, { text: '仓库库位', to: '/master/warehouse-locations' }] },
        { title: 'BOM管理', icon: 'el-icon-share', metrics: [
          { label: 'BOM总数', value: this.count('boms') }, { label: '已启用', value: this.bomsBy('status', 'active') },
          { label: '待审核', value: this.rows.boms.filter(r => r.audit_status === 'pending' && r.submitted_at).length }, { label: '默认BOM', value: this.rows.boms.filter(r => r.is_default).length },
          { label: '即将过期', value: this.expiringBoms }, { label: '缺少默认BOM', value: '—' }
        ], links: [{ text: 'BOM列表', to: '/bom/boms' }, { text: '新建BOM', to: '/bom/create' }, { text: 'BOM审核', to: '/bom/boms' }, { text: 'BOM展开', to: '/bom/expand' }, { text: '版本管理', to: '/bom/boms' }] }
      ]
    },
    relationRate() { return this.count('skus') ? `${Math.min(100, Math.round(this.count('relations') / this.count('skus') * 1000) / 10)}%` : '0%' },
    disabledItems() { return this.rows.items?.filter(i => ['disabled', 'inactive'].includes(i.status)).length || 0 },
    ordersNotReceived() { return this.rows.orders.filter(r => ['not_received', 'partial'].includes(r.receipt_status)).length },
    purchaseAmount() { return `¥${this.rows.orders.reduce((n, r) => n + Number(r.total_amount || r.amount || 0), 0).toLocaleString()}` },
    stockItems() { return new Set(this.rows.balances.filter(r => Number(r.quantity_on_hand || 0) > 0).map(r => r.item_id)).size },
    stockQty() { return this.rows.balances.reduce((n, r) => n + Number(r.quantity_on_hand || 0), 0).toLocaleString() },
    todayInTx() { return this.rows.transactions.filter(r => this.isToday(r.posted_at || r.created_at) && r.transaction_type === 'purchase_receipt_posting').length },
    todayTx() { return this.rows.transactions.filter(r => this.isToday(r.posted_at || r.created_at)).length },
    inventoryWarnings() { return this.rows.balances.filter(r => Number(r.quantity_defective || 0) > 0 || Number(r.quantity_pending || 0) > 0).length },
    expiringBoms() { const limit = new Date(); limit.setDate(limit.getDate() + 7); return this.rows.boms.filter(r => r.expire_date && new Date(r.expire_date) <= limit).length },
    purchaseTrend() { return this.trendFrom(this.rows.orders, 'created_at') },
    inventoryTrend() { return this.trendFrom(this.rows.transactions, 'posted_at') },
    purchaseTrendTotal() { return this.purchaseTrend.reduce((n, v) => n + v, 0) },
    inventoryTrendTotal() { return this.inventoryTrend.reduce((n, v) => n + v, 0) },
    recentOps() {
      const ops = []
      this.rows.requests.slice(0, 3).forEach(r => ops.push(this.op(r.created_at, r.created_by || '系统', '采购', r.request_no, '采购需求创建')))
      this.rows.adjustments.slice(0, 3).forEach(r => ops.push(this.op(r.created_at, r.created_by || '系统', '库存', r.adjustment_no, '库存调整提交')))
      this.rows.boms.slice(0, 3).forEach(r => ops.push(this.op(r.updated_at || r.created_at, r.updated_by || '系统', 'BOM', r.bom_no, 'BOM版本维护')))
      return ops.sort((a, b) => String(b.time).localeCompare(String(a.time))).slice(0, 10)
    }
  },
  created() { this.load() },
  methods: {
    async load() {
      this.loading = true
      const jobs = {
        requests: listPurchase('requests', { per_page: 100 }),
        plans: listPurchase('plans', { per_page: 100 }),
        orders: listPurchase('orders', { per_page: 100 }),
        receipts: listPurchase('receipts', { per_page: 100 }),
        defects: listDefectHandlings({ per_page: 100 }),
        pendingReceipts: listPendingReceipts({ per_page: 100 }),
        adjustments: listInventoryAdjustments({ per_page: 100 }),
        balances: listInventoryBalances({ per_page: 100 }),
        transactions: listInventoryTransactions({ per_page: 100 }),
        boms: listBoms({ per_page: 100 }),
        products: listEntity('products', { per_page: 100 }),
        skus: listEntity('skus', { per_page: 100 }),
        items: listEntity('items', { per_page: 100 }),
        relations: listRelations({ per_page: 100 })
      }
      const entries = await Promise.all(Object.entries(jobs).map(async ([key, promise]) => [key, await promise.catch(error => ({ error }))]))
      this.failedModules = []
      entries.forEach(([key, res]) => {
        if (res.error) { this.failedModules.push(key); return }
        this.$set(this.rows, key, this.extractRows(res.data))
        this.$set(this.totals, key, this.extractTotal(res.data))
      })
      this.totals.requestsDraft = this.rows.requests.filter(r => r.request_status === 'draft').length
      this.totals.plansSubmitted = this.rows.plans.filter(r => r.plan_status === 'submitted' && r.audit_status === 'pending').length
      this.totals.ordersSubmitted = this.rows.orders.filter(r => r.purchase_status === 'submitted' && r.audit_status === 'pending').length
      this.totals.pendingReceipts = this.rows.pendingReceipts.filter(r => (r.posting_status || r.stock_post_status) === 'pending').length || this.rows.pendingReceipts.length
      this.totals.adjustmentsSubmitted = this.rows.adjustments.filter(r => (r.status || r.adjustment_status) === 'submitted').length
      this.totals.bomTodo = this.rows.boms.filter(r => (r.audit_status === 'pending' && r.submitted_at) || (r.audit_status === 'approved' && r.status !== 'active')).length
      if (this.failedModules.length) this.$message.warning(`部分工作台数据加载失败：${this.failedModules.join('、')}`)
      this.loading = false
    },
    extractRows(data) { return data?.data || data?.rows || [] },
    extractTotal(data) { return Number(data?.total ?? data?.meta?.total ?? this.extractRows(data).length ?? 0) },
    count(key) { return Number(this.totals[key] ?? this.rows[key]?.length ?? 0) },
    todo(priority, type, no, summary, owner, time, statusText, action, to) {
      return { priority, type, no: no || '--', summary, owner, time: this.shortTime(time), wait: this.waitText(time), statusText, action, to }
    },
    op(time, operator, module, object, content) { return { time: this.shortTime(time), operator, module, object: object || '--', content } },
    priorityType(v) { return ({ 高: 'danger', 中: 'warning', 低: 'info' })[v] || 'info' },
    warningType(v) { return ({ 严重: 'danger', 警告: 'warning', 提醒: '' })[v] || '' },
    shortTime(v) { return v ? String(v).replace('T', ' ').slice(0, 16) : '--' },
    waitText(v) {
      if (!v) return '--'
      const diff = Math.max(1, Math.round((Date.now() - new Date(v).getTime()) / 3600000))
      return diff < 24 ? `${diff}小时` : `${Math.round(diff / 24)}天`
    },
    isToday(v) { const now = new Date(); const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`; return v && String(v).slice(0, 10) === today },
    bomsBy(field, value) { return this.rows.boms.filter(r => r[field] === value).length },
    trendFrom(rows, field) {
      const buckets = Array.from({ length: 30 }, () => 0)
      rows.forEach(row => {
        const value = row[field] || row.created_at
        if (!value) return
        const day = Math.floor((new Date().setHours(0, 0, 0, 0) - new Date(String(value).slice(0, 10)).setHours(0, 0, 0, 0)) / 86400000)
        if (day >= 0 && day < 30) buckets[29 - day] += 1
      })
      const max = Math.max(...buckets, 1)
      return buckets.map(v => v ? Math.max(8, Math.round(v / max * 92)) : 3)
    }
  }
}
</script>

<style scoped>
.console-page { min-height: calc(100vh - 52px); padding: 14px 16px 22px; background: #f7f8f9; color: #25313b; }
.console-hero { min-height: 112px; display: grid; grid-template-columns: 76px minmax(260px,1fr) 250px 420px; gap: 16px; align-items: center; padding: 16px; border: 1px solid #e2e6ea; border-radius: 5px; background: linear-gradient(105deg,#fff 0%,#f7fbf8 58%,#eef8f2 100%); }
.sun-badge { width: 58px; height: 58px; display: grid; place-items: center; border-radius: 50%; background: #fff7e8; color: #f59e0b; font-size: 34px; box-shadow: 0 8px 20px rgba(16,24,40,.06); }
.hero-copy h1 { margin: 0 0 6px; font-size: 22px; font-weight: 700; }
.hero-copy p { margin: 0; color: #59636d; font-size: 13px; }
.hero-copy b { color: #e3342f; font-size: 18px; }
.hero-art { justify-self: center; color: #9bd7b6; font-size: 70px; opacity: .78; }
.quick-actions { display: grid; grid-template-columns: repeat(2,1fr); gap: 10px; }
.quick-actions .el-button { height: 38px; margin: 0; background: #fff; }
.todo-cards { display: grid; grid-template-columns: repeat(6,1fr); gap: 10px; margin: 10px 0; }
.todo-card { min-height: 82px; display: grid; grid-template-columns: 40px 1fr 48px; gap: 10px; align-items: center; padding: 12px; border: 1px solid #e2e6ea; border-radius: 5px; background: #fff; cursor: pointer; transition: transform .15s ease, box-shadow .15s ease; }
.todo-card:hover { transform: translateY(-1px); box-shadow: 0 8px 22px rgba(17,24,39,.06); }
.todo-card>i { width: 34px; height: 34px; display: grid; place-items: center; border-radius: 7px; color: #fff; font-size: 18px; }
.todo-card .blue { background: #4f83f1; }.todo-card .orange { background: #f59e30; }.todo-card .green { background: #20b69a; }.todo-card .purple { background: #7c5ce6; }
.todo-card span,.todo-card small { display: block; color: #64717d; }.todo-card strong { display: block; font-size: 22px; line-height: 1.15; }.todo-card em { color: #3b82f6; font-style: normal; font-size: 12px; }
.console-grid { display: grid; grid-template-columns: minmax(560px,1.35fr) minmax(420px,.95fr); gap: 10px; }
.console-panel,.module-card,.migration-strip { border: 1px solid #e2e6ea; border-radius: 5px; background: #fff; }
.panel-head { height: 48px; display: flex; align-items: center; justify-content: space-between; padding: 0 14px; border-bottom: 1px solid #edf0f2; }
.panel-head h2 { margin: 0; font-size: 14px; }
.panel-head .el-tabs { flex: 1; margin-left: 20px; }
.panel-head ::v-deep .el-tabs__header { margin: 0; }
.panel-head ::v-deep .el-tabs__nav-wrap::after { display: none; }
.console-table { padding: 0 12px; }
.console-table a { color: #2f6fec; cursor: pointer; }
.panel-link { width: 100%; height: 34px; border: 0; border-top: 1px solid #edf0f2; background: #fff; color: #2f6fec; cursor: pointer; }
.warning-head nav { display: flex; gap: 18px; }
.warning-head button,.seg button { border: 0; background: transparent; color: #5f6b76; cursor: pointer; }
.warning-head button.active,.seg button.active { color: #07883f; font-weight: 700; }
.warning-list { padding: 8px 12px 0; max-height: 230px; overflow: auto; }
.warning-list article { display: grid; grid-template-columns: 46px 1.05fr 1.2fr 112px 58px; gap: 10px; align-items: center; min-height: 42px; border-bottom: 1px solid #edf0f2; cursor: pointer; }
.warning-list b,.warning-list span { display: block; }.warning-list span,.warning-list p,.warning-list time { color: #64717d; margin: 0; }.warning-list a { color: #2f6fec; }
.module-overview { display: grid; grid-template-columns: repeat(4,1fr); gap: 10px; margin-top: 10px; }
.module-card { padding: 12px; }
.module-card h3 { display: flex; align-items: center; gap: 8px; margin: 0 0 12px; font-size: 14px; }
.module-card h3 i { color: #10a464; }
.module-card dl { display: grid; grid-template-columns: 1fr 58px 1fr 58px; gap: 8px 10px; margin: 0; }
.module-card dt { color: #64717d; }.module-card dd { margin: 0; text-align: right; font-weight: 700; }
.module-links { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 12px; }
.module-links button { height: 24px; padding: 0 8px; border: 1px solid #dce2e8; border-radius: 3px; background: #f9fafb; color: #35404b; cursor: pointer; font-size: 11px; }
.migration-strip { height: 42px; display: flex; align-items: center; gap: 32px; margin-top: 10px; padding: 0 14px; }
.migration-strip h3 { margin: 0; font-size: 13px; }.migration-strip small,.migration-strip span { color: #64717d; }.migration-strip button { margin-left: auto; border: 0; background: transparent; color: #9aa3ac; cursor: pointer; }
.green-dot { display: inline-block; width: 6px; height: 6px; margin-right: 5px; border-radius: 50%; background: #12b76a; }
.bottom-grid { display: grid; grid-template-columns: 1fr 1fr .95fr; gap: 10px; margin-top: 10px; }
.chart-panel,.ops-panel { min-height: 262px; }
.mini-chart,.line-chart { height: 176px; display: flex; align-items: end; gap: 5px; padding: 22px 24px 12px; }
.mini-chart span,.line-chart span { flex: 1; min-width: 4px; border-radius: 4px 4px 0 0; background: linear-gradient(180deg,#2fc889,#d5f5e4); }
.line-chart span { background: linear-gradient(180deg,#7c5ce6,#dcd7ff); }
.console-empty { min-height: 210px; display: grid; place-content: center; justify-items: center; color: #8a95a0; text-align: center; }
.console-empty i { font-size: 30px; color: #19a463; }.console-empty strong { margin-top: 8px; color: #46515c; }.console-empty span { margin-top: 4px; }
.console-empty.small { min-height: 210px; }.chart-empty { min-height: 176px; }
@media(max-width:1366px){.console-page{padding:12px}.console-hero{grid-template-columns:64px 1fr 300px}.hero-art{display:none}.todo-cards{grid-template-columns:repeat(3,1fr)}.module-overview{grid-template-columns:repeat(2,1fr)}.bottom-grid{grid-template-columns:1fr}.console-grid{grid-template-columns:1fr}.quick-actions{grid-template-columns:repeat(2,1fr)}}
</style>
