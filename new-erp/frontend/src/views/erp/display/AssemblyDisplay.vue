<template>
  <main class="assembly-display">
    <header class="display-header">
      <div class="display-brand"><strong>简谈</strong><span></span><h1>总装通知屏</h1></div>
      <div class="display-status">
        <span class="connection-state" :class="{ connected: connectionState === 'connected' }"><i class="el-icon-success" />{{ connectionLabel }}</span>
        <button v-if="pendingChanges.length" class="pending-changes" type="button" @click="openPendingChange">待查看修改 {{ pendingChanges.length }}</button>
        <button class="sound-toggle" :class="{ enabled: soundEnabled }" type="button" :title="soundError || (soundEnabled ? '声音已启用，点击关闭' : '点击启用声音提醒')" :aria-label="soundEnabled ? '关闭声音提醒' : '启用声音提醒'" @click="toggleSound"><i :class="soundEnabled ? 'el-icon-bell' : 'el-icon-close-notification'" /></button>
        <span class="display-date">{{ dateLabel }}<b>{{ timeLabel }}</b></span>
      </div>
    </header>

    <nav class="display-navigation" aria-label="工单类别">
      <div class="category-tabs"><button v-for="item in categories" :key="item.value" type="button" :class="{ active: filters.type === item.value }" @click="changeFilter('type', item.value)">{{ item.label }}</button></div>
      <div class="display-stats"><span title="当前分类今天新建的生产工单数，不跟随搜索和状态筛选">今日工单 <b>{{ stats ? stats.today_orders : '—' }}</b> 单</span><span class="stats-divider">/</span><span title="今日当前分类新建生产工单所关联订单的整单应收金额，按订单去重；非今日收款">销售额 <b>{{ stats ? money(stats.sales_amount, 0) : '—' }}</b></span></div>
    </nav>

    <section class="display-filters" aria-label="工单查询">
      <form class="search-fields" @submit.prevent="search">
        <label class="date-field"><span>创建日期</span><div class="date-range"><el-date-picker v-model="filters.create_from" type="date" value-format="yyyy-MM-dd" placeholder="开始日期" popper-class="assembly-date-picker" :clearable="true" /><span>至</span><el-date-picker v-model="filters.create_to" type="date" value-format="yyyy-MM-dd" placeholder="结束日期" popper-class="assembly-date-picker" :clearable="true" /></div></label>
        <label><span>客户名称</span><el-input v-model="filters.customer" placeholder="客户姓名/手机号" clearable /></label>
        <label><span>产品名称</span><el-input v-model="filters.product_name" placeholder="产品名称" clearable /></label>
        <label><span>订单号</span><el-input v-model="filters.order_no" placeholder="订单号" clearable /></label>
        <el-button class="search-button" type="success" native-type="submit" :loading="loading">查询</el-button>
      </form>
      <div class="order-types"><span>订单类型</span><button v-for="item in orderTypes" :key="item.value" type="button" :class="{ active: filters.order_type === item.value }" @click="changeFilter('order_type', item.value)">{{ item.label }}</button></div>
    </section>

    <nav class="work-status-tabs" aria-label="工单状态"><button v-for="item in statuses" :key="item.value" type="button" :class="{ active: filters.status === item.value }" @click="changeFilter('status', item.value)">{{ item.label }}</button></nav>

    <section class="display-results">
      <div v-if="urgentOrders.length" class="urgent-orders" role="status"><i class="el-icon-warning" /><strong>当天发货待接单（{{ urgentOrders.length }}）</strong><span v-for="order in urgentOrders" :key="order.id">{{ order.work_order_no }} 已等待 <b>{{ waitMinutes(order) }}</b> 分钟</span></div>
      <div v-if="listError" class="display-error" role="alert"><i class="el-icon-warning-outline" />{{ listError }}<el-button type="text" @click="loadList">重新加载</el-button></div>
      <div v-loading="loading" class="work-order-table-wrap">
        <table class="work-order-table">
          <colgroup><col class="col-number"><col class="col-type"><col class="col-remarks"><col class="col-sales"><col class="col-customer"><col class="col-device"><col class="col-note"><col class="col-platform"><col class="col-address"><col class="col-stage"><col class="col-created"><col class="col-action"></colgroup>
          <thead><tr><th>订单号</th><th>类型</th><th>生产备注</th><th>销售</th><th>客户名</th><th>设备</th><th>工单备注</th><th>成交平台</th><th>地址</th><th>工序</th><th>下单时间</th><th>操作</th></tr></thead>
          <tbody>
            <tr v-for="row in rows" :key="row.id" :class="{ 'new-order': newWorkOrderIds.includes(String(row.id)) }">
              <td><span v-if="newWorkOrderIds.includes(String(row.id))" class="new-label">新</span>{{ row.work_order_no || '—' }}</td>
              <td><div class="tag-list"><span v-for="(tag, index) in row.tags || []" :key="tag.code || index" class="display-tag" :class="tagClass(tag)">{{ tag.label }}</span><span v-if="!(row.tags || []).length">—</span></div></td>
              <td class="production-remarks"><div>焊接：{{ text(row.production_remarks && row.production_remarks.welding) }}</div><div>流程：{{ text(row.production_remarks && row.production_remarks.process) }}</div><div>物流：{{ text(row.production_remarks && row.production_remarks.logistics) }}</div></td>
              <td>{{ text(row.salesperson) }}</td><td>{{ text(row.customer_name) }}</td>
              <td>{{ row.device_name || productSummary(row.products) || '—' }}<template v-if="row.device_name && row.quantity != null"> × {{ row.quantity }}{{ row.unit }}</template></td>
              <td><div>{{ row.order_note }}</div><div>{{ row.electric_label }}</div><div>{{ row.need_pump_label }}</div><span v-if="!row.order_note && !row.electric_label && !row.need_pump_label">—</span></td>
              <td>{{ text(row.platform_name) }}</td><td>{{ text(row.address) }}</td>
              <td><span class="display-tag stage-tag" :class="stageClass(row.stage_name)">{{ text(row.stage_name) }}</span></td>
              <td>{{ formatDateTime(row.created_at) }}</td><td><el-button type="text" @click="openDetail(row)">查看</el-button></td>
            </tr>
            <tr v-if="!loading && !rows.length && !listError"><td colspan="12" class="display-empty">暂无符合条件的工单</td></tr>
          </tbody>
        </table>
      </div>
      <footer class="display-pagination"><div>共 {{ total == null ? '—' : total }} 条<span class="page-size">10 条/页</span></div><el-pagination background :current-page="page" :page-size="10" :total="total || 0" :pager-count="5" layout="prev, pager, next" @current-change="changePage" /></footer>
    </section>

    <el-dialog title="工单详情" :visible.sync="detailVisible" width="1060px" top="8vh" custom-class="assembly-dialog assembly-detail-dialog" :close-on-click-modal="false" @closed="afterDetailClosed">
      <div v-loading="detailLoading" class="dialog-content">
        <div v-if="detailError" class="dialog-error" role="alert">{{ detailError }}<el-button type="text" @click="loadDetail">重新加载</el-button></div>
        <template v-else-if="detail">
          <div class="detail-tags"><span v-for="(tag, index) in detail.tags || []" :key="tag.code || index" class="display-tag" :class="tagClass(tag)">{{ tag.label }}</span><span v-if="detail.need_pump_label" class="display-tag tag-blue">{{ detail.need_pump_label }}</span><span v-if="detail.electric_label" class="display-tag tag-neutral">{{ detail.electric_label }}</span></div>
          <div class="detail-order-line"><div><span>工单号</span>{{ detail.work_order_no }}</div><div><span>工单状态</span><b class="display-tag" :class="stageClass(detail.status_label)">{{ text(detail.status_label) }}</b></div></div>
          <h3 class="detail-section-heading">基本信息</h3>
          <div class="detail-basic"><dl><dt>工单备注</dt><dd>{{ text(detail.order_note) }}</dd><dt>焊接备注</dt><dd>{{ text(detail.production_remarks && detail.production_remarks.welding) }}</dd><dt>流程备注</dt><dd>{{ text(detail.production_remarks && detail.production_remarks.process) }}</dd><dt>物流备注</dt><dd>{{ text(detail.production_remarks && detail.production_remarks.logistics) }}</dd></dl><dl><dt>客户名</dt><dd>{{ text(detail.customer_name) }}</dd><dt>手机号</dt><dd>{{ text(detail.customer_phone) }}</dd><dt>客户地址</dt><dd>{{ text(detail.address) }}</dd></dl></div>
          <h3 class="detail-section-heading">设备信息</h3>
          <div class="detail-products-scroll"><table class="detail-products"><thead><tr><th>名称</th><th>数量</th><th>价格</th><th>备注</th><th>图纸</th></tr></thead><tbody><tr v-for="(product, index) in detail.products || []" :key="product.id || index"><td>{{ text(product.name) }}</td><td>{{ text(product.quantity) }}{{ product.unit }}</td><td>{{ product.unit_price == null ? '—' : money(product.unit_price) }}</td><td>{{ text(product.remark) }}</td><td><AssemblyAttachments :files="product.attachments || []" /></td></tr><tr v-if="!(detail.products || []).length"><td colspan="5" class="display-empty">暂无设备信息</td></tr></tbody></table></div>
        </template>
      </div>
      <span slot="footer" class="detail-footer"><el-button plain type="success" :disabled="!detail || !!detailError" @click="openHistory">修改记录</el-button><el-button type="success" @click="detailVisible = false">关闭</el-button></span>
    </el-dialog>

    <el-dialog title="修改记录" :visible.sync="historyVisible" width="1100px" top="8vh" custom-class="assembly-dialog assembly-history-dialog" :close-on-click-modal="false" @closed="afterHistoryClosed">
      <div v-loading="historyLoading" class="dialog-content">
        <p class="history-order">工单 <span>{{ detail && detail.work_order_no }}</span></p>
        <div v-if="historyError" class="dialog-error" role="alert">{{ historyError }}<el-button type="text" @click="loadHistory">重新加载</el-button></div>
        <template v-else-if="versions.length">
          <div class="version-tabs"><button v-for="(version, index) in versions" :key="version.id || index" type="button" :class="{ active: versionIndex === index }" @click="versionIndex = index">{{ version.label || (version.is_original ? '原数据' : `第${index}次修改`) }}</button></div>
          <div class="version-meta"><span>{{ currentVersion.is_original ? '创建人' : '修改人' }}</span>{{ text(currentVersion.actor_name) }}<span>{{ currentVersion.is_original ? '创建日期' : '修改日期' }}</span>{{ formatDateTime(currentVersion.occurred_at) }}</div>
          <AssemblySnapshot :snapshot="currentVersion.snapshot || {}" />
        </template>
        <div v-else-if="!historyLoading" class="display-empty">暂无修改记录</div>
      </div>
      <span slot="footer"><el-button type="success" @click="historyVisible = false">关闭</el-button></span>
    </el-dialog>

    <el-dialog :visible.sync="changeVisible" width="1020px" top="8vh" custom-class="assembly-dialog assembly-change-dialog" :close-on-click-modal="false" @closed="afterChangeClosed">
      <template slot="title"><span class="change-title"><i class="el-icon-warning" />订单修改提醒</span></template>
      <div v-loading="changeLoading" class="dialog-content">
        <p class="change-order">{{ change ? `工单${change.work_order_no || ''}有修改` : '订单修改提醒' }}</p>
        <div v-if="changeError" class="dialog-error" role="alert">{{ changeError }}<el-button type="text" @click="loadChange">重新加载</el-button></div>
        <template v-else-if="change">
          <div class="version-tabs"><button type="button" :class="{ active: changeSide === 'before' }" @click="changeSide = 'before'">修改前</button><button type="button" :class="{ active: changeSide === 'after' }" @click="changeSide = 'after'">修改后</button></div>
          <div v-if="changeSummaries.length" class="change-summary"><div v-for="(item, index) in changeSummaries" :key="index">{{ item.label }}：<span>{{ item.before }}</span><i class="el-icon-right" /><strong>{{ item.after }}</strong></div></div>
          <AssemblySnapshot :snapshot="change[changeSide] || {}" :previous="changeSide === 'after' ? change.before : null" show-headings />
        </template>
      </div>
      <span slot="footer"><el-button @click="changeVisible = false">关闭</el-button></span>
    </el-dialog>
  </main>
</template>

<script>
import { getAssemblyChange, getAssemblyHistory, getAssemblyWorkOrder, listAssemblyWorkOrders } from '@/api/erp/assemblyDisplay'
import { connectAssemblyDisplay } from '@/services/assemblyDisplayRealtime'
import AssemblyAttachments from './AssemblyAttachments.vue'
import AssemblySnapshot from './AssemblySnapshot.vue'

const localDate = date => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`

export default {
  name: 'AssemblyDisplay',
  components: { AssemblyAttachments, AssemblySnapshot },
  data() {
    const today = new Date()
    const yesterday = new Date(today)
    yesterday.setDate(today.getDate() - 1)
    return {
      filters: { type: 'assemble', status: 'wait', create_from: localDate(yesterday), create_to: localDate(today), customer: '', product_name: '', order_no: '', order_type: 'all' },
      categories: [{ value: 'all', label: '全部工单' }, { value: 'produce', label: '生产计划' }, { value: 'assemble', label: '总装工单' }, { value: 'testing', label: '发货工单' }],
      orderTypes: [{ value: 'all', label: '全部' }, { value: 'ordinary', label: '普通订单' }, { value: 'delayed', label: '延迟订单' }, { value: 'urgent', label: '加急订单' }, { value: 'customized', label: '定制订单' }],
      statuses: [{ value: 'wait', label: '待接单' }, { value: 'accept', label: '进行中' }, { value: 'complete', label: '已完成' }, { value: 'cancel', label: '已取消' }],
      rows: [], stats: null, total: 0, page: 1, loading: false, listError: '', listRequest: 0,
      urgentOrders: [], urgentLoadedAt: 0, urgentSignature: '', now: Date.now(), connectionState: 'connecting',
      soundEnabled: false, soundError: '', pendingChanges: [], activeChangeEvent: null, newWorkOrderIds: [],
      detailVisible: false, detailLoading: false, detailError: '', detail: null, detailId: null,
      historyVisible: false, historyLoading: false, historyError: '', versions: [], versionIndex: 0,
      changeVisible: false, changeLoading: false, changeError: '', change: null, changeSide: 'after'
    }
  },
  computed: {
    dateLabel() { const date = new Date(this.now); return `${localDate(date)}　星期${'日一二三四五六'[date.getDay()]}` },
    timeLabel() { const date = new Date(this.now); return `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}` },
    connectionLabel() { return ({ connected: '系统已连接', connecting: '系统连接中', unavailable: '连接中断，正在重连', disconnected: '系统已断开', failed: '连接失败，正在重连' })[this.connectionState] || '系统连接中' },
    currentVersion() { return this.versions[this.versionIndex] || {} },
    changeSummaries() {
      if (!this.change) return []
      const before = this.change.before || {}
      const after = this.change.after || {}
      const summary = []
      const add = (label, oldValue, value) => { if (JSON.stringify(oldValue == null ? '' : oldValue) !== JSON.stringify(value == null ? '' : value)) summary.push({ label, before: this.text(oldValue), after: this.text(value) }) }
      ;[['customer_name', '客户名'], ['customer_phone', '手机号'], ['address', '客户地址'], ['order_note', '工单备注']].forEach(([field, label]) => add(label, before[field], after[field]))
      const previousProducts = before.products || []
      ;(after.products || []).forEach((product, index) => {
        const previous = (product.id != null ? previousProducts.find(item => String(item.id) === String(product.id)) : previousProducts[index]) || {}
        if (!Object.keys(previous).length) { summary.push({ label: '新增设备', before: '—', after: `${product.name || '设备'} × ${product.quantity == null ? '—' : product.quantity}${product.unit || ''}` }); return }
        ;[['name', '设备名称'], ['quantity', '设备数量'], ['unit', '设备单位'], ['remark', '设备备注']].forEach(([field, label]) => add(label, previous[field], product[field]))
        const fileNames = files => (files || []).map(file => file.name || '附件').join('、') || '—'
        if (JSON.stringify(previous.attachments || []) !== JSON.stringify(product.attachments || [])) summary.push({ label: '设备图纸', before: fileNames(previous.attachments), after: fileNames(product.attachments) })
      })
      previousProducts.forEach((product, index) => {
        const found = product.id != null ? (after.products || []).some(item => String(item.id) === String(product.id)) : (after.products || [])[index]
        if (!found) summary.push({ label: '移除设备', before: `${product.name || '设备'} × ${product.quantity == null ? '—' : product.quantity}${product.unit || ''}`, after: '—' })
      })
      return summary
    }
  },
  created() {
    this.seenEvents = new Set()
    this.speechQueue = []
    this.nextUrgentAt = 0
    this.loadList()
    this.clockTimer = window.setInterval(() => { this.now = Date.now() }, 1000)
    try { this.stopRealtime = connectAssemblyDisplay(this.onRealtimeEvent, this.onConnectionState) } catch (error) { this.connectionState = 'failed' }
  },
  beforeDestroy() {
    this.destroyed = true
    this.listRequest += 1
    if (this.stopRealtime) this.stopRealtime()
    window.clearInterval(this.clockTimer)
    window.clearTimeout(this.urgentTimer)
    window.clearTimeout(this.refreshTimer)
    window.clearTimeout(this.speechTimer)
    if (this.speaking && window.speechSynthesis) window.speechSynthesis.cancel()
  },
  methods: {
    text(value) { return value === null || value === undefined || value === '' ? '—' : value },
    money(value, decimals = 2) { const number = Number(value); return Number.isFinite(number) ? `¥${number.toLocaleString('zh-CN', { minimumFractionDigits: decimals, maximumFractionDigits: decimals })}` : '—' },
    formatDateTime(value) {
      if (!value) return '—'
      const date = new Date(value)
      if (Number.isNaN(date.getTime())) return String(value)
      return `${localDate(date)} ${[date.getHours(), date.getMinutes(), date.getSeconds()].map(number => String(number).padStart(2, '0')).join(':')}`
    },
    productSummary(products) { return (products || []).map(product => `${product.name || '—'} × ${product.quantity == null ? '—' : product.quantity}${product.unit || ''}`).join('\n') },
    tagClass(tag) { const code = String(tag.code || ''); if (/urgent|加急/.test(code + tag.label)) return 'tag-red'; if (/custom|特殊/.test(code + tag.label)) return 'tag-purple'; if (/delay|延期|延迟|today|当天/.test(code + tag.label)) return 'tag-amber'; return 'tag-blue' },
    stageClass(label) { return /已完成|完工|已发货/.test(label || '') ? 'tag-green' : /取消|关闭/.test(label || '') ? 'tag-neutral' : /中$/.test(label || '') ? 'tag-green' : /准备/.test(label || '') ? 'tag-blue' : 'tag-amber' },
    changeFilter(field, value) { if (this.filters[field] === value) return; this.filters[field] = value; this.search() },
    search() {
      if (this.filters.create_from && this.filters.create_to && this.filters.create_from > this.filters.create_to) { this.$message.warning('开始日期不能晚于结束日期'); return }
      this.page = 1
      this.loadList()
    },
    changePage(page) { this.page = page; this.loadList() },
    async loadList(refreshUrgentTimer = true) {
      const request = ++this.listRequest
      this.loading = true
      this.listError = ''
      try {
        const response = await listAssemblyWorkOrders({ ...this.filters, page: this.page, per_page: 10 })
        if (request !== this.listRequest || this.destroyed) return false
        const data = response.data.data
        const list = data.list
        const lastPage = Math.max(1, Number(list.last_page) || 1)
        if (this.page > lastPage) { this.page = lastPage; return this.loadList(refreshUrgentTimer) }
        this.rows = list.data || []
        this.total = Number(list.total) || 0
        this.stats = data.stats || null
        this.urgentOrders = data.urgent_orders || []
        this.urgentLoadedAt = Date.now()
        const signature = this.urgentOrders.map(order => `${order.id}:${order.work_order_no}`).join('|')
        const changed = signature !== this.urgentSignature
        this.urgentSignature = signature
        if (refreshUrgentTimer) this.scheduleUrgent(changed)
        return true
      } catch (error) {
        if (request !== this.listRequest || this.destroyed) return false
        this.listError = error.userMessage || '工单加载失败，请稍后重试'
        this.rows = []
        this.stats = null
        this.total = null
        this.urgentOrders = []
        this.urgentSignature = ''
        if (refreshUrgentTimer) this.scheduleUrgent()
        return false
      } finally {
        if (request === this.listRequest) this.loading = false
      }
    },
    async openDetail(row) {
      this.detailId = row.id
      this.detail = null
      this.detailVisible = true
      this.newWorkOrderIds = this.newWorkOrderIds.filter(id => id !== String(row.id))
      await this.loadDetail()
    },
    async loadDetail() {
      const id = this.detailId
      this.detailLoading = true
      this.detailError = ''
      try { const response = await getAssemblyWorkOrder(id); if (this.detailId === id && !this.destroyed) this.detail = response.data.data } catch (error) { if (this.detailId === id) this.detailError = error.userMessage || '工单详情加载失败' } finally { if (this.detailId === id) this.detailLoading = false }
    },
    openHistory() { this.historyVisible = true; this.versions = []; this.versionIndex = 0; this.loadHistory() },
    async loadHistory() {
      const id = this.detailId
      this.historyLoading = true
      this.historyError = ''
      try { const response = await getAssemblyHistory(id); if (this.detailId === id && !this.destroyed) this.versions = response.data.data.versions || [] } catch (error) { this.historyError = error.userMessage || '修改记录加载失败' } finally { this.historyLoading = false }
    },
    afterDetailClosed() { this.detailId = null; this.detail = null; this.openPendingChange() },
    afterHistoryClosed() { this.openPendingChange() },
    onConnectionState(state) {
      const reconnect = state === 'connected' && this.connectionState !== 'connected'
      this.connectionState = state
      if (reconnect) this.scheduleRefresh()
    },
    scheduleRefresh() { window.clearTimeout(this.refreshTimer); this.refreshTimer = window.setTimeout(() => { if (!this.destroyed) this.loadList() }, 250) },
    onRealtimeEvent(event) {
      if (!event || !event.event_id || this.seenEvents.has(String(event.event_id))) return
      this.seenEvents.add(String(event.event_id))
      if (this.seenEvents.size > 300) this.seenEvents.delete(this.seenEvents.values().next().value)
      this.scheduleRefresh()
      if (event.type === 'work_order.created') {
        this.newWorkOrderIds = [...new Set([...this.newWorkOrderIds, ...(event.work_order_ids || []).map(String)])].slice(-100)
        this.$notify({ title: '新工单已生成', message: '', type: 'success', duration: 7500 })
        this.speak('新工单已生成')
      } else if (event.type === 'order.changed' && event.change_id) {
        if (this.activeChangeEvent && String(this.activeChangeEvent.change_id) === String(event.change_id)) return
        if (this.pendingChanges.some(item => String(item.change_id) === String(event.change_id))) return
        this.pendingChanges.push(event)
        this.$notify({ title: '有工单被修改', message: '点击查看修改内容', type: 'warning', duration: 0, onClick: this.openPendingChange })
        this.speak('有工单被修改，请查看修改内容')
        this.openPendingChange()
      }
    },
    openPendingChange() {
      // 正在阅读详情或历史时保留消息排队，避免新快照覆盖当前内容。
      if (this.detailVisible || this.historyVisible || this.changeVisible || !this.pendingChanges.length) return
      this.activeChangeEvent = this.pendingChanges.shift()
      this.change = null
      this.changeSide = 'after'
      this.changeVisible = true
      this.loadChange()
    },
    async loadChange() {
      const event = this.activeChangeEvent
      if (!event) return
      this.changeLoading = true
      this.changeError = ''
      try { const response = await getAssemblyChange(event.change_id); if (this.activeChangeEvent === event && !this.destroyed) this.change = response.data.data } catch (error) { if (this.activeChangeEvent === event) this.changeError = error.userMessage || '修改内容加载失败，请重试' } finally { if (this.activeChangeEvent === event) this.changeLoading = false }
    },
    afterChangeClosed() { this.activeChangeEvent = null; this.change = null; this.openPendingChange() },
    toggleSound() {
      if (this.soundEnabled) { this.soundEnabled = false; this.speechQueue = []; this.scheduleUrgent(); if (window.speechSynthesis) window.speechSynthesis.cancel(); return }
      if (!window.speechSynthesis || !window.SpeechSynthesisUtterance) { this.soundError = '当前浏览器不支持声音播报'; this.$message.warning(this.soundError); return }
      this.soundError = ''
      this.speak('声音提醒已开启', true)
    },
    speak(message, enable = false) {
      if ((!this.soundEnabled && !enable) || !window.speechSynthesis || this.destroyed) return
      if (this.speaking) { if (!enable && !this.speechQueue.includes(message)) this.speechQueue.push(message); return }
      const utterance = new window.SpeechSynthesisUtterance(message)
      utterance.lang = 'zh-CN'
      this.speaking = true
      let done = false
      const finish = (error, canceled = false) => {
        if (done) return
        done = true
        window.clearTimeout(this.speechTimer)
        this.speaking = false
        if (canceled) return
        if (error) { this.soundEnabled = false; this.speechQueue = []; this.soundError = '声音播放失败，请点击重新启用'; if (!this.destroyed) this.$message.warning(this.soundError) }
        else if (enable) { this.soundEnabled = true; this.soundError = ''; this.scheduleUrgent(true) }
        if (this.soundEnabled && this.speechQueue.length) this.speak(this.speechQueue.shift())
      }
      utterance.onend = () => finish(false)
      utterance.onerror = event => finish(true, ['canceled', 'interrupted'].includes(event.error) && (!this.soundEnabled || this.destroyed))
      this.speechTimer = window.setTimeout(() => finish(true), 20000)
      try { window.speechSynthesis.speak(utterance) } catch (error) { finish(true) }
    },
    waitSeconds(order) { return Math.max(0, Number(order.wait_seconds) || 0) + Math.max(0, Math.floor((this.now - this.urgentLoadedAt) / 1000)) },
    waitMinutes(order) { return Math.floor(this.waitSeconds(order) / 60) },
    urgentDelay() {
      const longest = Math.max(0, ...this.urgentOrders.map(this.waitSeconds))
      return (longest >= 3600 ? 30 : longest >= 1800 ? 60 : longest >= 900 ? 120 : longest >= 300 ? 180 : 300) * 1000
    },
    scheduleUrgent(immediate = false) {
      window.clearTimeout(this.urgentTimer)
      if (this.destroyed) return
      const audible = this.soundEnabled && this.urgentOrders.length
      if (!audible) this.nextUrgentAt = 0
      else if (immediate || !this.nextUrgentAt) this.nextUrgentAt = Date.now() + 1000
      const delay = audible ? Math.min(60000, Math.max(1000, this.nextUrgentAt - Date.now())) : 60000
      this.urgentTimer = window.setTimeout(async () => {
        // 接单/取消可能来自未广播的其它端，催促前必须重新读取，失败不能继续播缓存。
        const fresh = await this.loadList(false)
        if (this.destroyed) return
        if (fresh && this.soundEnabled && this.urgentOrders.length && Date.now() >= this.nextUrgentAt) {
          const numbers = this.urgentOrders.slice(0, 3).map(order => order.work_order_no).join('，')
          this.speak(`当天发货有${this.urgentOrders.length}张工单待接单。${numbers}${this.urgentOrders.length > 3 ? '等工单' : ''}，请及时处理`)
          this.nextUrgentAt = Date.now() + this.urgentDelay()
        }
        this.scheduleUrgent()
      }, delay)
    }
  }
}
</script>

<style scoped>
.assembly-display {
  display: flex;
  flex-direction: column;
  width: 100%;
  min-width: 0;
  height: 100vh;
  overflow: auto;
  color: #172033;
  background: #f8fafb;
  font-size: 16px;
}
.display-header {
  display: flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: space-between;
  min-height: 64px;
  gap: 20px;
  padding: 10px 28px;
  background: #f0f3f5;
}
.display-brand,
.display-status,
.display-date,
.display-navigation,
.category-tabs,
.search-fields,
.search-fields label,
.date-range,
.order-types,
.work-status-tabs,
.display-pagination {
  display: flex;
  align-items: center;
  min-width: 0;
}
.display-brand {
  gap: 20px;
  white-space: nowrap;
}
.display-brand strong {
  color: #008b4b;
  font-size: 32px;
  letter-spacing: 2px;
}
.display-brand > span {
  width: 1px;
  height: 32px;
  background: #aeb8c2;
}
.display-brand h1 {
  margin: 0;
  font-size: 27px;
  font-weight: 600;
}
.display-status {
  gap: 20px;
}
.connection-state {
  font-size: 14px;
  white-space: nowrap;
  color: #9a6700;
}
.connection-state i {
  margin-right: 8px;
}
.connection-state.connected {
  color: #172033;
}
.connection-state.connected i {
  color: #12b76a;
}
.sound-toggle,
.pending-changes {
  padding: 0;
  border: 0;
  color: #64748b;
  background: transparent;
  cursor: pointer;
}
.sound-toggle {
  font-size: 27px;
  line-height: 1;
}
.sound-toggle.enabled,
.pending-changes {
  color: #008b4b;
}
.pending-changes {
  font-size: 14px;
}
.display-date {
  gap: 14px;
  padding-left: 22px;
  border-left: 1px solid #aeb8c2;
  white-space: nowrap;
  font-size: 18px;
}
.display-date b {
  font-size: 27px;
  font-weight: 600;
}
.display-navigation {
  flex-shrink: 0;
  justify-content: space-between;
  gap: 16px;
  min-height: 66px;
  padding: 0 28px;
  border-bottom: 1px solid #e2e8f0;
  background: #fff;
}
.category-tabs {
  align-self: stretch;
  gap: 36px;
}
.category-tabs button,
.work-status-tabs button,
.version-tabs button {
  position: relative;
  align-self: stretch;
  padding: 0 2px;
  border: 0;
  border-bottom: 3px solid transparent;
  background: transparent;
  color: #172033;
  font-size: 20px;
  white-space: nowrap;
  cursor: pointer;
}
.category-tabs button.active,
.work-status-tabs button.active,
.version-tabs button.active {
  border-bottom-color: #009953;
  color: #009953;
  font-weight: 600;
}
.display-stats {
  font-size: 18px;
  white-space: nowrap;
}
.display-stats b {
  font-size: 24px;
  font-weight: 600;
}
.display-stats .stats-divider {
  margin: 0 16px;
}
.display-filters {
  flex-shrink: 0;
  margin: 18px 22px 14px;
  padding: 18px 16px 16px;
  border: 1px solid #e2e8f0;
  border-radius: 7px;
  background: #fff;
}
.search-fields {
  gap: 24px;
}
.search-fields label {
  flex: 1;
  gap: 14px;
}
.search-fields label > span,
.order-types > span {
  flex-shrink: 0;
  white-space: nowrap;
}
.search-fields .date-field {
  flex: 1.65;
}
.date-range {
  gap: 10px;
}
.search-fields ::v-deep .el-date-editor {
  width: 100%;
  min-width: 0;
}
.search-fields ::v-deep .el-input__inner {
  height: 44px;
  padding-left: 14px;
  border-color: #d8dfe8;
  color: #172033;
  font-size: 16px;
}
.search-fields ::v-deep .el-input__inner:focus {
  border-color: #008b4b;
}
.search-fields ::v-deep .el-date-editor .el-input__inner {
  padding-left: 12px;
  padding-right: 30px;
}
.search-fields ::v-deep .el-date-editor .el-input__prefix {
  right: 5px;
  left: auto;
}
.search-button {
  flex-shrink: 0;
  width: 114px;
  height: 44px;
  font-size: 17px;
}
.order-types {
  flex-wrap: wrap;
  gap: 14px;
  margin-top: 16px;
}
.order-types button {
  min-width: 104px;
  height: 42px;
  padding: 0 20px;
  border: 1px solid #d8dfe8;
  border-radius: 5px;
  background: #fff;
  color: #334155;
  cursor: pointer;
}
.order-types button.active {
  border-color: #008b4b;
  background: #008b4b;
  color: #fff;
}
.work-status-tabs {
  flex-shrink: 0;
  height: 46px;
  gap: 24px;
  padding: 0 22px;
  border-bottom: 1px solid #e2e8f0;
}
.work-status-tabs button {
  min-width: 108px;
  padding: 0 14px;
  font-size: 18px;
}
.work-status-tabs button.active {
  border: 1px solid #e2e8f0;
  border-bottom: 0;
  border-radius: 7px 7px 0 0;
  background: #fff;
}
.work-status-tabs button.active::after {
  position: absolute;
  bottom: 0;
  right: 18px;
  left: 18px;
  height: 3px;
  background: #008b4b;
  content: '';
}
.display-results {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-height: 300px;
  min-width: 0;
  padding: 20px 22px 10px;
  background: #fff;
}
.urgent-orders {
  display: flex;
  flex-shrink: 0;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px 24px;
  margin-bottom: 16px;
  padding: 10px 22px;
  border: 1px solid #f9cc74;
  border-radius: 7px;
  background: #fff9ec;
  color: #b75d00;
}
.urgent-orders > i {
  margin-right: -12px;
  color: #ef9300;
  font-size: 24px;
}
.urgent-orders span {
  overflow-wrap: anywhere;
}
.display-error,
.dialog-error {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: center;
  gap: 10px;
  padding: 20px;
  color: #b45309;
  background: #fffbeb;
}
.work-order-table-wrap {
  flex: 1;
  min-height: 120px;
  overflow: auto;
  border: 1px solid #dce3eb;
  border-radius: 6px;
}
.work-order-table {
  width: 100%;
  border-collapse: separate;
  border-spacing: 0;
  table-layout: fixed;
  font-size: 16px;
}
.col-number { width: 11.5%; }
.col-type { width: 7.2%; }
.col-remarks { width: 10.1%; }
.col-sales { width: 5.4%; }
.col-customer { width: 7.1%; }
.col-device { width: 12.8%; }
.col-note { width: 10.8%; }
.col-platform { width: 6.4%; }
.col-address { width: 8.8%; }
.col-stage { width: 7.2%; }
.col-created { width: 7.2%; }
.col-action { width: 5.5%; }
.work-order-table th,
.work-order-table td {
  padding: 16px;
  border-right: 1px solid #e2e8f0;
  border-bottom: 1px solid #e2e8f0;
  text-align: left;
  vertical-align: middle;
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}
.work-order-table th {
  position: sticky;
  top: 0;
  z-index: 1;
  padding-top: 12px;
  padding-bottom: 12px;
  background: #f0f4f6;
  font-weight: 600;
}
.work-order-table th:last-child,
.work-order-table td:last-child {
  border-right: 0;
  text-align: center;
}
.work-order-table tr:last-child td {
  border-bottom: 0;
}
.work-order-table td:first-child {
  position: relative;
}
.work-order-table .new-order {
  background: #f0faf5;
}
.new-label {
  display: block;
  width: 34px;
  margin: -12px 0 6px -14px;
  padding: 3px;
  border-radius: 3px 3px 12px 3px;
  background: #36bf7a;
  color: #fff;
  text-align: center;
  line-height: 1.3;
}
.production-remarks {
  line-height: 1.55;
}
.tag-list,
.detail-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.display-tag {
  display: inline-block;
  max-width: 100%;
  padding: 5px 10px;
  border-radius: 5px;
  font-weight: 600;
  line-height: 1.45;
  white-space: normal;
}
.tag-amber {
  color: #a65e00;
  background: #fff0ca;
}
.tag-red {
  color: #ce3d29;
  background: #ffe5e0;
}
.tag-purple {
  color: #7447c3;
  background: #eee5ff;
}
.tag-blue {
  color: #27669c;
  background: #e4f2fb;
}
.tag-green {
  color: #008b4b;
  background: #dff7e9;
}
.tag-neutral {
  color: #475569;
  background: #e9edf3;
}
.stage-tag {
  text-align: center;
}
.display-empty {
  padding: 54px 20px !important;
  text-align: center !important;
  color: #94a3b8;
}
.display-pagination {
  flex-shrink: 0;
  justify-content: space-between;
  gap: 16px;
  padding-top: 12px;
  color: #475569;
}
.display-pagination > div:first-child {
  display: flex;
  align-items: center;
  gap: 16px;
}
.page-size {
  display: inline-flex;
  align-items: center;
  gap: 26px;
  height: 38px;
  padding: 0 22px;
  border: 1px solid #d8dfe8;
  border-radius: 4px;
}
.page-size i {
  color: #64748b;
  font-size: 12px;
}
.display-pagination ::v-deep .el-pagination button,
.display-pagination ::v-deep .el-pager li {
  height: 38px;
  min-width: 40px;
  margin: 0 5px;
  padding: 0 12px;
  border: 1px solid #e2e8f0;
  background: #fff;
  font-size: 16px;
  line-height: 36px;
}
.display-pagination ::v-deep .el-pager li.active {
  border-color: #008b4b;
  background: #008b4b;
}
.detail-tags {
  gap: 14px;
  margin-bottom: 18px;
}
.detail-tags .display-tag {
  padding: 7px 18px;
}
.detail-order-line {
  display: flex;
  justify-content: space-between;
  gap: 20px;
  margin-bottom: 24px;
  font-size: 20px;
}
.detail-order-line span:not(.display-tag) {
  margin-right: 24px;
  color: #475569;
}
.detail-section-heading {
  margin: 22px 0 18px;
  padding-bottom: 7px;
  border-bottom: 1px solid #dfe5ec;
  color: #172033;
  font-size: 22px;
}
.detail-basic {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  gap: 40px;
  margin-bottom: 28px;
}
.detail-basic dl {
  display: grid;
  grid-template-columns: 100px minmax(0, 1fr);
  align-content: start;
  gap: 14px 14px;
  margin: 0;
}
.detail-basic dl + dl {
  padding-left: 42px;
  border-left: 1px solid #e2e8f0;
}
.detail-basic dt {
  color: #64748b;
}
.detail-basic dd {
  min-width: 0;
  margin: 0;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.detail-products-scroll {
  overflow: auto;
}
.detail-products {
  width: 100%;
  border: 1px solid #dfe5ec;
  border-spacing: 0;
  border-radius: 5px;
}
.detail-products th,
.detail-products td {
  padding: 14px;
  border-right: 1px solid #e2e8f0;
  border-bottom: 1px solid #e2e8f0;
  text-align: left;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.detail-products th {
  background: #f1f5f9;
}
.detail-products tr:last-child td {
  border-bottom: 0;
}
.detail-products tr > *:last-child {
  border-right: 0;
}
.detail-products th:first-child {
  width: 26%;
}
.detail-products th:nth-child(2) {
  width: 9%;
}
.detail-products th:nth-child(3) {
  width: 12%;
}
.detail-products th:nth-child(4) {
  width: 21%;
}
.detail-footer {
  display: flex;
  justify-content: space-between;
}
.history-order,
.change-order {
  margin: 0 0 20px;
  font-size: 21px;
}
.history-order span {
  margin-left: 20px;
}
.version-tabs {
  display: flex;
  min-width: 0;
  min-height: 48px;
  margin-bottom: 18px;
  border-bottom: 1px solid #e2e8f0;
  overflow: auto;
}
.version-tabs button {
  flex-shrink: 0;
  min-width: 116px;
  padding: 10px 20px;
  font-size: 18px;
}
.version-meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 14px;
  margin: 0 0 18px;
  padding: 14px 24px;
  border-radius: 6px;
  background: #f4f6f8;
}
.version-meta span {
  color: #475569;
}
.version-meta span:not(:first-child) {
  margin-left: 48px;
}
.change-title {
  display: flex;
  align-items: center;
  gap: 14px;
}
.change-title i {
  color: #f59e0b;
  font-size: 29px;
}
.change-summary {
  display: flex;
  flex-wrap: wrap;
  gap: 12px 28px;
  margin-bottom: 20px;
  padding: 16px 22px;
  border: 1px solid #f9cc74;
  border-radius: 6px;
  background: #fff9ec;
}
.change-summary > div {
  min-width: 0;
  overflow-wrap: anywhere;
}
.change-summary i {
  margin: 0 16px;
}
.change-summary strong {
  color: #b65b00;
}
@media (max-width: 1400px) {
  .search-fields {
    flex-wrap: wrap;
    gap: 14px 20px;
  }
  .search-fields label {
    flex-basis: 26%;
  }
  .search-fields .date-field {
    flex-basis: 42%;
  }
  .work-order-table th,
  .work-order-table td {
    padding: 12px 8px;
    font-size: 14px;
  }
  .category-tabs {
    gap: 24px;
  }
}
@media (max-width: 1000px) {
  .display-header,
  .display-navigation {
    flex-wrap: wrap;
    gap: 12px;
    padding: 12px 20px;
  }
  .category-tabs {
    min-height: 42px;
  }
  .display-status {
    flex-wrap: wrap;
    gap: 12px;
  }
  .work-order-table {
    min-width: 1100px;
  }
}
@media (max-width: 780px) {
  .assembly-display {
    height: auto;
    min-height: 100vh;
    font-size: 14px;
  }
  .display-brand h1 {
    font-size: 21px;
  }
  .display-brand strong {
    font-size: 26px;
  }
  .display-date {
    padding-left: 0;
    border-left: 0;
    font-size: 14px;
  }
  .display-date b {
    font-size: 20px;
  }
  .display-navigation {
    padding-bottom: 0;
  }
  .category-tabs {
    width: 100%;
    gap: 20px;
    overflow: auto;
  }
  .category-tabs button {
    font-size: 16px;
  }
  .display-stats {
    padding-bottom: 12px;
    font-size: 14px;
  }
  .display-stats b {
    font-size: 18px;
  }
  .display-filters {
    margin: 12px;
    padding: 14px 12px;
  }
  .search-fields {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
  }
  .search-fields label > span {
    width: 64px;
  }
  .order-types {
    gap: 8px;
  }
  .order-types button {
    min-width: 0;
    padding: 0 10px;
  }
  .work-status-tabs {
    gap: 10px;
    padding: 0 12px;
    overflow: auto;
  }
  .work-status-tabs button {
    min-width: 75px;
    padding: 0 8px;
    font-size: 16px;
  }
  .display-results {
    padding: 12px;
  }
  .work-order-table-wrap {
    max-height: 65vh;
  }
  .display-pagination {
    flex-wrap: wrap;
  }
  .detail-basic {
    grid-template-columns: minmax(0, 1fr);
    gap: 18px;
  }
  .detail-basic dl + dl {
    padding-left: 0;
    border-left: 0;
  }
  .detail-order-line {
    flex-wrap: wrap;
    font-size: 16px;
  }
  .detail-products {
    min-width: 650px;
  }
  .version-meta span:not(:first-child) {
    margin-left: 0;
  }
}
</style>

<style>
.assembly-display .el-button--text,
.assembly-dialog .el-button--text,
.assembly-preview-dialog .el-button--text {
  color: #008b4b;
}
.assembly-display .el-button--text:hover,
.assembly-dialog .el-button--text:hover,
.assembly-preview-dialog .el-button--text:hover {
  color: #006334;
}
.assembly-dialog .el-button--success.is-plain {
  border-color: #008b4b;
  background: #fff;
  color: #008b4b;
}
.assembly-dialog .el-button--success.is-plain:hover {
  background: #f0fdf4;
  color: #00763f;
}
.assembly-dialog .el-button:not(.el-button--success):hover,
.assembly-preview-dialog .el-button:not(.el-button--success):hover {
  border-color: #008b4b;
  background: #f0fdf4;
  color: #008b4b;
}
.assembly-display .el-loading-spinner .path,
.assembly-dialog .el-loading-spinner .path,
.assembly-preview-dialog .el-loading-spinner .path {
  stroke: #008b4b;
}
.assembly-date-picker .el-date-table td.today span,
.assembly-date-picker .el-date-table td.available:hover,
.assembly-date-picker .el-picker-panel__icon-btn:hover {
  color: #008b4b;
}
.assembly-date-picker .el-date-table td.current:not(.disabled) span {
  background: #008b4b;
  color: #fff;
}
.assembly-dialog.el-dialog,
.assembly-preview-dialog.el-dialog {
  display: flex;
  flex-direction: column;
  max-width: calc(100vw - 40px);
  max-height: 85vh;
  margin-bottom: 20px;
  overflow: hidden;
  border-radius: 10px;
  box-shadow: 0 12px 42px rgba(23, 32, 51, 0.18);
}
.assembly-dialog .el-dialog__header {
  flex-shrink: 0;
  padding: 22px 30px 18px;
  border-bottom: 1px solid #e2e8f0;
}
.assembly-dialog .el-dialog__title,
.assembly-dialog .change-title {
  color: #111827;
  font-size: 26px;
  font-weight: 600;
  line-height: 1.4;
}
.assembly-dialog .el-dialog__headerbtn {
  top: 26px;
  right: 28px;
  font-size: 24px;
}
.assembly-dialog .el-dialog__body {
  flex: 1;
  min-height: 80px;
  padding: 20px 30px 28px;
  overflow: auto;
  color: #172033;
  font-size: 18px;
  line-height: 1.5;
}
.assembly-dialog .el-dialog__footer {
  flex-shrink: 0;
  padding: 16px 30px;
  border-top: 1px solid #e2e8f0;
}
.assembly-dialog .el-dialog__footer .el-button {
  min-width: 110px;
  padding: 13px 24px;
  font-size: 18px;
}
.assembly-history-dialog .el-dialog__header,
.assembly-change-dialog .el-dialog__header {
  padding-bottom: 8px;
  border-bottom: 0;
}
.assembly-history-dialog .el-dialog__body,
.assembly-change-dialog .el-dialog__body {
  padding-top: 4px;
}
.assembly-history-dialog .el-dialog__footer,
.assembly-change-dialog .el-dialog__footer {
  border-top: 0;
}
.assembly-preview-dialog .el-dialog__body {
  min-height: 0;
  overflow: auto;
}
@media (max-width: 780px) {
  .assembly-dialog .el-dialog__header,
  .assembly-dialog .el-dialog__body,
  .assembly-dialog .el-dialog__footer {
    padding-right: 16px;
    padding-left: 16px;
  }
  .assembly-dialog .el-dialog__body {
    font-size: 15px;
  }
}
</style>
