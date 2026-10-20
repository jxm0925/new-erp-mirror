<template>
  <section class="mfg-page">
    <div class="mfg-hero">
      <div class="mfg-crumb">生产管理 / 序列号查询</div>
      <div class="mfg-title hero-gap">这台设备的一生</div>
      <div class="search-row">
        <el-input v-model="keyword" class="mono" clearable placeholder="输入或扫码序列号，例如 SN2610-0015" @keyup.enter.native="search" />
        <el-button class="primary-btn" :loading="loading" @click="search">查询</el-button>
      </div>
      <div v-if="result" class="mfg-meta">
        <span>产品<b>{{ result.work_order.product_name || result.work_order.item_name }}</b></span>
        <span>现在<b class="now">{{ result.status_label }}<template v-if="result.current_operation_name"> · {{ result.current_operation_name }}</template></b></span>
        <span>工单<b class="mono lk-light" @click="openWo">{{ result.work_order.work_order_no }}</b></span>
        <span v-if="result.sales_order">订单 / 客户<b>{{ result.sales_order.sales_order_no }} · {{ result.sales_order.customer_name }}</b></span>
        <span v-else>来源<b>备货</b></span>
      </div>
    </div>
    <div class="mfg-body">
      <div v-if="!result" class="card">
        <div class="empty">{{ message || '输入序列号查看这台设备从下达到售后的全过程' }}</div>
        <div v-if="suggestions.length" class="sugg"><span class="sub">你是不是要找：</span><span v-for="s in suggestions" :key="s" class="chip c-b mono" @click="keyword = s; search()">{{ s }}</span></div>
      </div>
      <div v-else class="mfg-grid">
        <div>
          <div class="card">
            <h2>经历 <small>从下达到售后，谁在什么时候做了什么</small></h2>
            <div class="timeline">
              <div v-for="(e, i) in result.events" :key="i" :class="['ev', dotClass(e.action)]">
                <span class="dot" /><span class="time">{{ (e.created_at || '').slice(5, 16) }}</span>
                <div><b>{{ e.content }}</b><div class="sub">{{ e.operator_name || '系统' }}</div></div>
              </div>
              <div v-if="pendingOps.length" class="ev todo"><span class="dot" /><span class="time">待进行</span><div class="sub">{{ pendingOps.join(' › ') }}</div></div>
              <div v-if="shipOps.length" class="ev todo"><span class="dot" /><span class="time">待进行</span><div class="sub">发货：{{ shipOps.join(' › ') }}</div></div>
              <div class="ev todo"><span class="dot" /><span class="time">以后</span><div class="sub">售后记录会接在这里</div></div>
            </div>
          </div>
          <div class="card">
            <h2>装了哪些关键部件</h2>
            <el-table v-if="result.components.length" :data="result.components" size="small">
              <el-table-column prop="component_item_name" label="部件" min-width="120" />
              <el-table-column prop="component_spec" label="规格" min-width="100" />
              <el-table-column label="部件序列号 / 批次" min-width="150"><template slot-scope="s"><span class="mono">{{ s.row.component_serial_no || (s.row.batch_no ? '批次 ' + s.row.batch_no : '-') }}</span></template></el-table-column>
              <el-table-column label="装配" min-width="110"><template slot-scope="s">{{ s.row.installed_by_name || '-' }} {{ (s.row.installed_at || '').slice(5, 10) }}</template></el-table-column>
            </el-table>
            <div v-else class="sub">还没有登记关键部件（装配报工时登记，后续阶段上线）。</div>
          </div>
        </div>
        <div>
          <div class="card">
            <h2>基本信息</h2>
            <div class="kv"><span>序列号</span><b class="mono">{{ result.serial_no }}</b></div>
            <div class="kv"><span>产品</span><b>{{ result.work_order.product_name || result.work_order.item_name }}</b></div>
            <div class="kv" v-if="result.work_order.sku_code"><span>SKU</span><b>{{ result.work_order.sku_code }}</b></div>
            <div class="kv" v-if="result.work_order.special_requirement"><span>配置</span><b>{{ result.work_order.special_requirement }}</b></div>
            <div class="kv"><span>BOM / 路线</span><b>{{ result.work_order.bom_no ? bomLabel(result.work_order.bom_name, result.work_order.bom_no, result.work_order.bom_version) : '-' }} · {{ result.work_order.routing_name ? `${result.work_order.routing_name} V${result.work_order.routing_version}` : '-' }}</b></div>
            <div class="kv"><span>返工次数</span><b :class="{ warn: result.rework_count }">{{ result.rework_count || 0 }}</b></div>
            <div class="kv"><span>工单状态</span><b>{{ result.work_order.status_label }}</b></div>
          </div>
          <div class="card">
            <h2>操作</h2>
            <div class="ops">
              <el-button size="small" @click="openWo">看工单</el-button>
              <el-button v-if="result.sales_order" size="small" @click="$router.push(`/sales/orders/${result.sales_order.id}/detail`)">看订单</el-button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>

<script>
import { lookupSerial } from '../../../api/erp/production'
import { bomLabel, errorText } from './mfg-helpers'

export default {
  name: 'ProductionSerialTrace',
  data: () => ({ keyword: '', loading: false, result: null, message: '', suggestions: [] }),
  computed: {
    pendingOps() {
      if (!this.result || ['stored', 'completed', 'scrapped', 'cancelled'].includes(this.result.status)) return []
      const ops = this.result.operations.filter(o => o.execution_context === 'production').map(o => o.operation_name)
      const cur = ops.indexOf(this.result.current_operation_name)
      return cur >= 0 ? ops.slice(cur) : ops
    },
    shipOps() {
      if (!this.result || ['scrapped', 'cancelled'].includes(this.result.status)) return []
      return this.result.operations.filter(o => o.execution_context !== 'production').map(o => o.operation_name)
    }
  },
  watch: { '$route.query.sn'(v) { if (v && v !== this.keyword) { this.keyword = v; this.search() } } },
  created() {
    if (this.$route.query.sn) { this.keyword = String(this.$route.query.sn); this.search() }
  },
  methods: {
    bomLabel,
    async search() {
      const sn = (this.keyword || '').trim()
      if (!sn) return this.$message.warning('请输入序列号')
      this.loading = true
      try {
        const { data } = await lookupSerial(sn)
        this.result = data.data
        this.message = data.data ? '' : (data.message || '没有找到这个序列号。')
        this.suggestions = data.suggestions || []
        if (this.$route.query.sn !== sn) this.$router.replace({ query: { sn } }).catch(() => {})
      } catch (e) {
        this.result = null
        this.message = errorText(e, '查询失败')
      } finally {
        this.loading = false
      }
    },
    openWo() { this.$router.push(`/production/work-orders/${this.result.work_order.id}`) },
    dotClass(action) {
      if (['rejected', 'rework', 'cancelled', 'scrapped'].includes(action)) return 'bad'
      if (['paused'].includes(action)) return 'doing'
      return 'done'
    }
  }
}
</script>

<style scoped src="./mfg-v2.css"></style>
<style scoped>
.hero-gap { margin-top: 10px; }
.search-row { display: flex; gap: 8px; margin-top: 12px; max-width: 700px; }
.primary-btn { background: #ffb020; border-color: #ffb020; color: #2b1d00; font-weight: 600; }
.now { color: #ffb020 !important; }
.lk-light { cursor: pointer; text-decoration: underline; }
.sugg { display: flex; gap: 6px; flex-wrap: wrap; justify-content: center; }
.sugg .chip { cursor: pointer; }
.timeline { position: relative; padding-left: 4px; }
.ev { display: grid; grid-template-columns: 18px 96px 1fr; gap: 8px; padding: 8px 0; position: relative; }
.ev:not(:last-child):after { content: ''; position: absolute; left: 7px; top: 24px; bottom: -6px; width: 1px; background: #d5dbe5; }
.dot { width: 14px; height: 14px; border-radius: 50%; border: 2px solid #c9d2e0; background: #fff; margin-top: 2px; }
.ev.done .dot { background: #4ade80; border-color: #c9f2d9; }
.ev.bad .dot { background: #ff5a5f; border-color: #ffd0d1; }
.ev.doing .dot { background: #ffb020; border-color: #ffe3a8; }
.time { color: #8a95a8; font-size: 12px; }
.ev.todo .sub { color: #a5afc0; font-weight: 600; }
.ops { display: flex; gap: 8px; flex-wrap: wrap; }
@media (max-width: 767px) {
  .ev { grid-template-columns: 18px 72px 1fr; }
}
</style>
