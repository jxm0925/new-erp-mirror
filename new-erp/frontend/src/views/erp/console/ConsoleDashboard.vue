<template>
  <section v-loading="loading" class="console-page">
    <div class="console-hero">
      <div class="sun-badge"><i class="el-icon-sunny" /></div>
      <div class="hero-copy">
        <h1>{{ greeting }}，{{ currentUserName }}</h1>
        <p>今天是 {{ todayText }}　当前有 <b>{{ dashboard ? totalTodo : '—' }}</b> 项待办、<b>{{ dashboard ? warningRows.length : '—' }}</b> 类异常需要处理</p>
        <small v-if="dashboard" class="data-time">数据更新：{{ shortTime(dashboard.as_of) }} <button @click="load()">刷新</button></small>
      </div>
      <div class="hero-art"><i class="el-icon-document-checked" /></div>
      <div class="quick-actions">
        <el-button v-if="$can('purchase.request.create')" size="small" icon="el-icon-document-add" @click="$router.push('/purchase/requests/create')">新建采购需求</el-button>
        <el-button v-if="$can('purchase.receipt.view')" size="small" icon="el-icon-truck" @click="$router.push('/purchase/receipts')">采购到货验收</el-button>
        <el-button v-if="$can('inventory.adjustment.view')" size="small" icon="el-icon-sort" @click="$router.push('/inventory/adjustments')">库存调整</el-button>
        <el-button v-if="$can('bom.manage.create')" size="small" icon="el-icon-share" @click="$router.push('/bom/create')">新建BOM</el-button>
      </div>
    </div>

    <el-alert v-if="loadError" type="error" :closable="false" :title="loadError" show-icon><el-button type="text" @click="load()">重新加载</el-button></el-alert>
    <template v-if="dashboard">
    <div class="todo-cards">
      <article v-for="card in taskCards" :key="card.key" class="todo-card" @click="selectTodoType(card.key)">
        <i :class="[card.icon, card.tone]" />
        <div><span>{{ card.name }}</span><strong>{{ card.count === null ? '无权限' : card.count }}</strong><small>当前实时待办</small></div>
        <em>查看 <i class="el-icon-arrow-right" /></em>
      </article>
    </div>

    <div class="console-grid">
      <section class="console-panel todo-panel">
        <div class="panel-head">
          <h2>{{ todoTitle }}</h2>
          <el-tabs v-model="todoTab">
            <el-tab-pane label="业务待办" name="todo" />
            <el-tab-pane label="优先处理" name="soon" />
          </el-tabs>
        </div>
        <el-table v-if="visibleTodos.length" :data="visibleTodos" size="mini" class="console-table" height="230">
          <el-table-column label="优先级" width="76"><template slot-scope="{ row }"><el-tag size="mini" :type="priorityType(row.priority)">{{ row.priority }}</el-tag></template></el-table-column>
          <el-table-column prop="type" label="业务类型" width="90" />
          <el-table-column label="单据编号" min-width="138"><template slot-scope="{ row }"><a @click.stop="$router.push(row.to)">{{ row.no }}</a></template></el-table-column>
          <el-table-column prop="summary" label="业务摘要" min-width="180" show-overflow-tooltip />
          <el-table-column prop="owner" label="经办人" width="76" />
          <el-table-column label="状态时间" width="126"><template slot-scope="{ row }">{{ shortTime(row.time) }}</template></el-table-column>
          <el-table-column label="等待时长" width="78"><template slot-scope="{ row }">{{ waitText(row.time) }}</template></el-table-column>
          <el-table-column label="当前状态" width="82"><template slot-scope="{ row }"><el-tag size="mini" type="warning">{{ row.statusText }}</el-tag></template></el-table-column>
          <el-table-column label="操作" width="86" fixed="right"><template slot-scope="{ row }"><el-button type="text" size="mini" @click="$router.push(row.to)">{{ row.action }}</el-button></template></el-table-column>
        </el-table>
        <div v-else class="console-empty"><i class="el-icon-finished" /><strong>{{ hasTodoAccess ? '当前范围没有需要处理的事项' : '无待办查看权限' }}</strong><span>可通过右上角快捷入口创建新业务，或进入业务模块查看历史记录。</span></div>
        <el-pagination class="todo-pagination" small layout="total, prev, pager, next" :pager-count="5" :current-page="todoPage" :page-size="8" :total="dashboard.todos.total" @current-change="changeTodoPage" />
        <button class="panel-link" @click="showAllTodos">查看全部待办 <i class="el-icon-arrow-right" /></button>
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
            <time>{{ item.time ? shortTime(item.time) : '当前' }}</time>
            <a>{{ item.action }}</a>
          </article>
        </div>
        <div v-else class="console-empty small"><i class="el-icon-circle-check" /><strong>{{ dashboard.warning_available ? '当前没有可查看的异常与预警' : '无异常查看权限' }}</strong></div>
        <button class="panel-link" @click="warningTab = '全部'">查看全部异常与预警 <i class="el-icon-arrow-right" /></button>
      </section>
    </div>

    <div class="module-overview">
      <article v-for="module in modules" :key="module.title" class="module-card">
        <h3><i :class="module.icon" />{{ module.title }}</h3>
        <dl>
          <template v-for="metric in module.metrics">
            <dt :key="metric.label + '-dt'">{{ metric.label }}</dt><dd :key="metric.label + '-dd'">{{ metric.value === null || metric.value === undefined ? '无权限' : metric.value }}</dd>
          </template>
        </dl>
        <div class="module-links"><button v-for="link in module.links" :key="link.text" @click="$router.push(link.to)">{{ link.text }}</button></div>
      </article>
    </div>


    <div class="bottom-grid">
      <section class="console-panel chart-panel">
        <div class="panel-head"><h2>最近30天已审核采购单</h2></div>
        <div v-if="purchaseTrendTotal > 0" class="mini-chart">
          <span v-for="(bar,index) in purchaseTrend" :key="index" :style="{ height: `${bar.height}%` }" :title="`${bar.date}：${bar.count}笔`" />
        </div>
        <div v-else class="console-empty chart-empty"><span>{{ dashboard.trends.purchase === null ? '无查看权限' : '暂无可展示趋势数据' }}</span></div>
        <button class="panel-link" @click="$router.push('/purchase/orders')">查看明细 <i class="el-icon-arrow-right" /></button>
      </section>
      <section class="console-panel chart-panel">
        <div class="panel-head"><h2>最近30天库存事务趋势</h2></div>
        <div v-if="inventoryTrendTotal > 0" class="line-chart">
          <span v-for="(bar,index) in inventoryTrend" :key="index" :style="{ height: `${bar.height}%` }" :title="`${bar.date}：${bar.count}笔`" />
        </div>
        <div v-else class="console-empty chart-empty"><span>{{ dashboard.trends.inventory === null ? '无查看权限' : '暂无可展示趋势数据' }}</span></div>
        <button class="panel-link" @click="$router.push('/inventory/transactions')">查看库存流水明细 <i class="el-icon-arrow-right" /></button>
      </section>
      <section class="console-panel ops-panel">
        <div class="panel-head"><h2>最近业务更新</h2></div>
        <el-table v-if="recentOps.length" :data="recentOps" size="mini" height="215">
          <el-table-column label="操作时间" width="145"><template slot-scope="{ row }">{{ shortTime(row.time) }}</template></el-table-column>
          <el-table-column prop="operator" label="操作人" width="70" />
          <el-table-column prop="module" label="业务模块" width="80" />
          <el-table-column prop="object" label="业务对象" min-width="120" />
          <el-table-column prop="content" label="更新内容" min-width="130" show-overflow-tooltip />
        </el-table>
        <div v-else class="console-empty chart-empty"><span>暂无最近操作记录</span></div>
      </section>
    </div>
    </template>
  </section>
</template>

<script>
import { getConsoleDashboard } from '@/api/erp/console'
import { consoleTime, consoleWait, consoleTrend, consoleAmounts, consoleQuantities } from '@/utils/consoleDashboard'

export default {
  name: 'ConsoleDashboard',
  data: () => ({ loading: false, loadError: '', dashboard: null, todoTab: 'todo', todoType: '', todoPage: 1, warningTab: '全部', warningTabs: ['全部', '主数据', '采购', '库存', 'BOM'], loadVersion: 0 }),
  computed: {
    todayText() { return new Intl.DateTimeFormat('zh-CN', { timeZone: this.timezone, year: 'numeric', month: 'long', day: 'numeric', weekday: 'long' }).format(new Date()) },
    timezone() { return this.dashboard?.timezone || 'Asia/Shanghai' },
    currentUserName() { try { const u = JSON.parse(localStorage.getItem('erp_user') || '{}'); return u.nickname || u.username || '用户' } catch { return '用户' } },
    greeting() { const h = Number(new Intl.DateTimeFormat('en-GB', { hour: 'numeric', hourCycle: 'h23', timeZone: this.timezone }).format(new Date())); return h < 6 ? '夜深了' : h < 12 ? '早上好' : h < 18 ? '下午好' : '晚上好' },
    taskCards() {
      return [
        ['requests', '待确认采购需求', 'el-icon-document', 'blue'], ['plans', '待审核采购计划', 'el-icon-tickets', 'orange'],
        ['orders', '待审核采购订单', 'el-icon-notebook-2', 'green'], ['receipts', '待过账采购到货', 'el-icon-truck', 'purple'],
        ['adjustments', '待处理库存调整', 'el-icon-sort', 'blue'], ['boms', '待审核/待启用BOM', 'el-icon-share', 'orange']
      ].map(([key, name, icon, tone]) => ({ key, name, icon, tone, count: this.dashboard?.counts[key] ?? null }))
    },
    totalTodo() { return this.dashboard?.total_todo ?? 0 },
    todoTitle() { return this.taskCards.find(c => c.key === this.todoType)?.name || '业务待办' },
    hasTodoAccess() { return this.taskCards.some(c => c.count !== null) },
    visibleTodos() { return this.dashboard?.todos?.data || [] },
    warningRows() { return this.dashboard?.warnings || [] },
    filteredWarnings() { return this.warningTab === '全部' ? this.warningRows : this.warningRows.filter(r => r.module === this.warningTab) },
    recentOps() { return this.dashboard?.recent || [] },
    purchaseTrend() { return consoleTrend(this.dashboard?.trends.purchase) },
    inventoryTrend() { return consoleTrend(this.dashboard?.trends.inventory) },
    purchaseTrendTotal() { return (this.dashboard?.trends.purchase || []).reduce((n, r) => n + r.count, 0) },
    inventoryTrendTotal() { return (this.dashboard?.trends.inventory || []).reduce((n, r) => n + r.count, 0) },
    modules() {
      const d = this.dashboard || {}, m = d.master || {}, p = d.purchase || {}, i = d.inventory || {}, b = d.bom || {}
      const metric = (label, value) => ({ label, value })
      const link = (text, to) => ({ text, to })
      return [
        { title: '主数据中心', icon: 'el-icon-menu', metrics: [metric('Product总数', m.products), metric('SKU总数', m.skus), metric('Item总数', m.items), metric('SKU-Item关联率', m.relation_rate), metric('停用物料', m.disabled_items), metric('缺少Item关联SKU', m.missing_skus)], links: [link('产品管理', '/master/products'), link('SKU管理', '/master/skus'), link('物料管理', '/master/items'), link('SKU-Item关系', '/master/sku-item-relations')] },
        { title: '采购管理', icon: 'el-icon-shopping-cart-2', metrics: [metric('本月采购需求', p.requests_month), metric('本月采购订单', p.orders_month), metric('待到货', p.awaiting_delivery), metric('待过账', p.receipts_to_post), metric('本月已审核金额', consoleAmounts(p.approved_amounts_month)), metric('不合格到货待处理', p.defects)], links: [link('采购需求', '/purchase/requests'), link('采购计划', '/purchase/plans'), link('采购订单', '/purchase/orders'), link('到货验收', '/purchase/receipts'), link('不合格品处理', '/purchase/defects')] },
        { title: '库存管理', icon: 'el-icon-house', metrics: [metric('有库存物料', i.stock_items), metric('库存数量（按单位）', consoleQuantities(i.quantity_by_unit)), metric('今日入库事务', i.today_in), metric('今日库存流水', i.today_transactions), metric('待处理库存调整', i.adjustments), metric('库存预警', i.alerts)], links: [link('库存余额', '/inventory/balances'), link('库存流水', '/inventory/transactions'), link('到货过账', '/inventory/posting'), link('库存调整', '/inventory/adjustments'), link('仓库库位', '/master/warehouse-locations')] },
        { title: 'BOM管理', icon: 'el-icon-share', metrics: [metric('BOM总数', b.total), metric('已启用且有效', b.active), metric('待审核', b.pending), metric('有效默认BOM', b.defaults), metric('7天内到期', b.expiring), metric('缺少默认BOM的SKU', b.missing_defaults)], links: [link('BOM列表', '/bom/boms'), link('新建BOM', '/bom/create'), link('BOM审核', '/bom/boms'), link('BOM展开', '/bom/expand'), link('版本管理', '/bom/boms')] }
      ]
    }
  },
  watch: { todoTab() { this.todoPage = 1; this.load() } },
  created() { this.load() },
  activated() { this.refresh() },
  mounted() { window.addEventListener('focus', this.refresh) },
  beforeDestroy() { this.loadVersion++; window.removeEventListener('focus', this.refresh) },
  methods: {
    refresh() { if (!this.loading) this.load() },
    async load() {
      const version = ++this.loadVersion
      this.loading = true
      this.loadError = ''
      try {
        const res = await getConsoleDashboard({ page: this.todoPage, per_page: 8, type: this.todoType || undefined, priority: this.todoTab === 'soon' ? 'high' : undefined })
        if (version !== this.loadVersion) return
        this.dashboard = res.data.data
        if (this.todoPage > 1 && !this.dashboard.todos.data.length) { this.todoPage = 1; return this.load() }
      } catch (error) {
        if (version !== this.loadVersion) return
        this.dashboard = null
        this.loadError = error.response?.data?.message || '控制台数据加载失败，请重新加载。'
      } finally { if (version === this.loadVersion) this.loading = false }
    },
    selectTodoType(type) { if (this.dashboard?.counts[type] === null) return; this.todoType = type; this.todoPage = 1; this.todoTab = 'todo'; this.load(); this.$nextTick(() => this.$el.querySelector('.todo-panel')?.scrollIntoView({ block: 'start' })) },
    showAllTodos() { this.todoType = ''; this.todoPage = 1; this.todoTab = 'todo'; this.load() },
    changeTodoPage(page) { this.todoPage = page; this.load() },
    shortTime(v) { return consoleTime(v, this.timezone) },
    waitText(v) { return consoleWait(v) },
    priorityType(v) { return ({ 紧急: 'danger', 高: 'danger', 中: 'warning', 低: 'info' })[v] || 'info' },
    warningType(v) { return ({ 严重: 'danger', 警告: 'warning', 提醒: '' })[v] || '' }
  }
}
</script>

<style scoped>
.console-page { min-height: calc(100vh - 52px); padding: 14px 16px 22px; background: #f7f8f9; color: #25313b; }
.console-hero { min-height: 112px; display: grid; grid-template-columns: 76px minmax(0,1fr) 180px minmax(280px,420px); gap: 16px; align-items: center; padding: 16px; border: 1px solid #e2e6ea; border-radius: 5px; background: linear-gradient(105deg,#fff 0%,#f7fbf8 58%,#eef8f2 100%); }
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
.console-grid { display: grid; grid-template-columns: minmax(0,1.35fr) minmax(0,.95fr); gap: 10px; }
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
.module-card dl { display: grid; grid-template-columns: minmax(0,1fr) minmax(0,1fr); gap: 8px 10px; margin: 0; }
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
@media(max-width:1440px){.console-page{padding:12px}.console-hero{grid-template-columns:64px minmax(0,1fr) 300px}.hero-art{display:none}.todo-cards{grid-template-columns:repeat(3,minmax(0,1fr))}.module-overview{grid-template-columns:repeat(2,minmax(0,1fr))}.bottom-grid{grid-template-columns:minmax(0,1fr)}.console-grid{grid-template-columns:minmax(0,1fr)}.quick-actions{grid-template-columns:repeat(2,1fr)}}
.console-page,.console-page * { box-sizing: border-box; }
.console-hero>*,.console-grid>*,.module-card,.module-card dd,.todo-card>* { min-width: 0; }
.module-card dd { overflow-wrap: anywhere; }
.data-time { display: block; margin-top: 6px; color: #64717d; }
.data-time button { border: 0; background: transparent; color: #008b4b; cursor: pointer; }
.todo-pagination { padding: 8px 12px; overflow: auto; }
.console-panel { min-width: 0; overflow: hidden; }
@media(max-width:780px){.quick-actions{grid-template-columns:repeat(2,minmax(0,1fr))}.quick-actions .el-button{width:100%;min-width:0;padding:0 4px;font-size:11px;white-space:normal}}
@media(max-width:780px){.console-hero{grid-template-columns:48px minmax(0,1fr)}.quick-actions{grid-column:1/-1}.sun-badge{width:42px;height:42px;font-size:26px}.hero-copy h1{font-size:18px}.todo-cards{grid-template-columns:repeat(2,minmax(0,1fr))}.todo-card{grid-template-columns:28px minmax(0,1fr);padding:8px;gap:6px}.todo-card em{grid-column:2}.todo-card>i{width:26px;height:26px;font-size:14px}.module-overview{grid-template-columns:1fr}.warning-head{height:auto;min-height:48px;flex-wrap:wrap;gap:8px;padding:8px}.warning-head nav{gap:10px;flex-wrap:wrap}.warning-list article{grid-template-columns:46px minmax(0,1fr);gap:6px}.warning-list article p,.warning-list article time,.warning-list article a{grid-column:2}.mini-chart,.line-chart{gap:2px;padding-left:12px;padding-right:12px}.mini-chart span,.line-chart span{min-width:0}.console-page{padding:8px}.panel-head{padding:0 8px}.panel-head .el-tabs{margin-left:8px}.console-empty{padding:12px}}
</style>
