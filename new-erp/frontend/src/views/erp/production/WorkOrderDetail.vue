<template>
  <section class="mfg-page" v-loading="loading">
    <template v-if="wo">
      <div class="mfg-hero">
        <div class="hero-top">
          <div class="mfg-crumb"><a @click="$router.push('/production/work-orders')">生产管理 / 工单</a> / <span class="mono">{{ wo.work_order_no }}</span></div>
          <div class="mfg-crumb">
            <template v-if="wo.source_type === 'sales_order'">来源：销售订单 <a class="mono" @click="openOrder">{{ wo.sales_order_no }}</a><template v-if="wo.sales_order_line_no"> 第 {{ wo.sales_order_line_no }} 行</template> · {{ wo.customer_name }}</template>
            <template v-else>来源：备货 · {{ wo.stock_reason_label || '其他' }}</template>
          </div>
        </div>
        <div class="mfg-hrow">
          <div>
            <div class="mfg-title">{{ productName }} × {{ qty(wo.planned_qty) }} {{ wo.unit_name || '台' }}
              <span :class="['pill', heroPill]">{{ wo.status_label }}</span>
              <span v-if="wo.is_urgent" class="pill p-r">加急</span>
            </div>
            <div class="mfg-meta">
              <span v-if="wo.sku_code">SKU<b>{{ wo.sku_code }}</b></span>
              <span>工艺路线<b>{{ wo.routing ? `${wo.routing.routing_name} V${wo.routing.version}` : '未设置' }}</b></span>
              <span>BOM<b>{{ wo.bom ? bomLabel(wo.bom.bom_name, wo.bom.bom_no, wo.bom.version) : '未设置' }}</b></span>
              <span>负责人<b>{{ wo.owner_name || '未指定' }}</b></span>
              <span>入库仓库<b>{{ wo.warehouse_name || '物料默认' }}</b></span>
            </div>
          </div>
          <div class="due-box">
            <div class="sub-light">计划完工</div>
            <div class="due-main">{{ wo.planned_finish_date || '未定' }} <em v-if="dueDiff !== null" :class="dueDiff >= 0 ? 'g' : 'r'">{{ dueDiff >= 0 ? `比交期早 ${dueDiff} 天` : `比交期晚 ${-dueDiff} 天` }}</em></div>
            <div class="bar"><i :style="{ width: barPercent + '%' }" /></div>
            <div class="due-foot"><span>开工 {{ shortDate(wo.planned_start_date) }}</span><span>今天 {{ shortDate(today) }}</span><span v-if="wo.required_date">交期 {{ shortDate(wo.required_date) }}</span></div>
          </div>
        </div>
        <div class="hero-bottom">
          <div class="stepper">
            <span v-for="(s, i) in steps" :key="s.value" :class="{ on: s.value === stepValue, done: i < stepIndex }">{{ i + 1 }} {{ s.label }}</span>
          </div>
          <div class="mfg-actions">
            <el-button v-if="wo.status === 'planned' && $can('production.work_order.publish')" class="primary" @click="release">下达</el-button>
            <el-button v-if="canAdjust && $can('production.work_order.edit')" class="ghost" @click="openAdjust">调整</el-button>
            <el-button v-if="['released', 'in_progress'].includes(wo.status) && $can('production.work_order.edit')" class="ghost" @click="pause">暂停</el-button>
            <el-button v-if="wo.status === 'paused' && $can('production.work_order.edit')" class="primary" @click="resume">恢复</el-button>
            <el-button v-if="canCancel && $can('production.work_order.cancel')" class="ghost" @click="cancel">取消</el-button>
          </div>
        </div>
      </div>

      <div class="mfg-body">
        <div class="mfg-grid">
          <div>
            <div class="card">
              <h2>每台设备走到哪了
                <span class="unit-sum"><b>{{ unitCount('stored') }}</b> 已入库 <b>{{ unitCount('completed') }}</b> 等入库 <b>{{ unitCount('in_progress') }}</b> 在制 <b class="warn">{{ unitCount('rework') }}</b> 返工 <b>{{ unitCount('waiting') }}</b> 未开工</span>
              </h2>
              <div v-if="!wo.units.length" class="empty">{{ wo.status === 'planned' ? '下达后按台生成序列号' : '这张工单不逐台跟踪（数量不是整数或超过 1000 台）' }}</div>
              <div v-else class="unit-wrap">
                <table class="unit-table">
                  <thead>
                    <tr><th /><th :colspan="prodOps.length || 1" class="grp">车间生产（本工单）</th><th v-if="shipOps.length" :colspan="shipOps.length" class="grp ship">发货作业（销售发货单里办理）</th></tr>
                    <tr><th>序列号</th><th v-for="op in prodOps" :key="op.id">{{ op.operation_name }}</th><th v-if="!prodOps.length">-</th><th v-for="op in shipOps" :key="op.id" class="ship">{{ op.operation_name }}</th></tr>
                  </thead>
                  <tbody>
                    <tr v-for="u in visibleUnits" :key="u.id">
                      <td><div class="mono sn lk" @click="openSerial(u.serial_no)">{{ u.serial_no || ('第 ' + u.unit_no + ' 台') }}</div><div :class="u.status === 'rework' ? 'warn small' : 'sub'">{{ u.status === 'rework' ? `返工第 ${u.rework_count || 1} 次` : u.status_label }}<template v-if="u.current_operation_name && ['in_progress', 'rework'].includes(u.status)"> · {{ u.current_operation_name }}</template><template v-if="u.rework_count && u.status !== 'rework'"> · 返工过 {{ u.rework_count }} 次</template></div></td>
                      <td v-for="(op, i) in prodOps" :key="op.id"><div :class="['cell', cellState(u, i)]">{{ cellText(u, i) }}</div></td>
                      <td v-if="!prodOps.length"><div class="cell">-</div></td>
                      <td v-for="op in shipOps" :key="op.id" class="ship"><div class="cell dash">{{ u.status === 'stored' ? '待开发货单' : '' }}</div></td>
                    </tr>
                  </tbody>
                </table>
                <div v-if="wo.units.length > unitLimit" class="more"><span class="lk" @click="unitLimit = wo.units.length">显示全部 {{ wo.units.length }} 台</span></div>
              </div>
            </div>

            <div v-if="prodOps.length && wo.status !== 'planned'" class="card">
              <h2>每道工序现在 <small>车间在小程序里按台报工，这里实时汇总</small></h2>
              <div class="unit-wrap">
                <table class="unit-table op-table">
                  <thead><tr><th>工序</th><th>停在这道</th><th>做完</th><th>接单人</th><th>正在计时</th><th>累计工时</th></tr></thead>
                  <tbody>
                    <tr v-for="op in prodOps" :key="op.id">
                      <td><b>{{ op.operation_name }}</b> <span :class="['chip', op.status === 'done' ? 'c-g' : (op.status === 'in_progress' ? 'c-o' : 'c-n')]">{{ opStatus(op.status) }}</span></td>
                      <td>{{ op.here_qty || 0 }} 台<span v-if="op.rework_qty" class="warn"> · 返工 {{ op.rework_qty }}</span></td>
                      <td>{{ qty(op.completed_qty) }} / {{ qty(wo.planned_qty) }}</td>
                      <td>{{ op.owner_name || '没人接' }}</td>
                      <td>{{ (op.timing_names || []).join('、') || '-' }}</td>
                      <td>{{ op.labor_minutes ? hours(op.labor_minutes) : '-' }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <div class="card">
              <h2>每道工序的料齐了没 <small>按工艺路线把 BOM 分到工序；缺料只提醒，不拦下达</small></h2>
              <div v-if="!wo.kitting.length" class="empty">没有车间工序或 BOM，无法分工序算齐套</div>
              <div v-else class="kit-grid">
                <div v-for="k in wo.kitting" :key="k.operation_key" :class="['kit', { short: k.status === 'short' }]">
                  <h3>{{ k.operation_name }} <span :class="k.status === 'short' ? 'warn' : (k.status === 'ready' ? 'ok' : 'sub')">{{ kitLabel(k) }}</span></h3>
                  <div v-for="m in k.materials" :key="m.id || m.component_item_id" class="row">
                    <span>{{ m.component_item_name }}</span>
                    <b v-if="!m.participates_in_kitting" class="sub">辅料 · 不计数</b>
                    <b v-else :class="{ warn: m.shortage_qty > 0 }">{{ qty(m.covered_qty) }} / {{ qty(m.required_qty) }} {{ m.unit_name || '' }}</b>
                  </div>
                  <div v-if="!k.materials.length" class="row"><span>这道工序不领料</span></div>
                </div>
              </div>
            </div>

            <div class="two-col">
              <div class="card">
                <h2>成本 <small>暂估 · 未结算</small></h2>
                <template v-if="wo.estimated_material_cost_per_unit !== null && wo.estimated_material_cost_per_unit !== undefined">
                  <div class="cost-main">{{ money(wo.estimated_material_cost_per_unit) }} <span class="sub">/ 台材料暂估 · 合计 {{ money(wo.estimated_material_cost_per_unit * wo.planned_qty) }}</span></div>
                  <div class="kv"><span>标准工时</span><b>每台 {{ wo.standard_minutes_per_unit || 0 }} 分钟</b></div>
                  <p class="sub">按库存加权平均成本暂估；实际领料、人工、委外在后续阶段归集，完工后结算。</p>
                </template>
                <div v-else class="sub">当前账号看不到成本金额。标准工时：每台 {{ wo.standard_minutes_per_unit || 0 }} 分钟。</div>
              </div>
              <div class="card">
                <h2>最近动态</h2>
                <div v-if="!wo.logs.length" class="sub">暂无动态</div>
                <div v-for="log in wo.logs.slice(0, 8)" :key="log.id" class="log"><span class="sub">{{ shortDate(log.created_at) }}</span><span><b>{{ log.operator_name || '系统' }}</b> {{ log.content }}</span></div>
              </div>
            </div>
          </div>

          <div>
            <div v-if="wo.tech" class="card">
              <h2>定制技术 <span :class="['chip', wo.tech.status === 'pending' ? 'c-o' : 'c-g']">{{ wo.tech.status_label }}</span></h2>
              <div class="kv"><span>技术负责人</span><b>{{ wo.tech.tech_owner_name || '还没人处理' }}</b></div>
              <div v-if="wo.tech.released_at" class="kv"><span>下发车间</span><b>{{ wo.tech.released_at.slice(5, 16) }}</b></div>
              <div v-if="wo.tech.drawings_updated_at" class="note">下发后改过图纸（{{ wo.tech.drawings_updated_at.slice(5, 16) }}），车间会看到“图纸已更新”。</div>
              <div class="kv"><span>设计图</span><b>
                <template v-if="wo.tech.drawings.length"><a v-for="g in wo.tech.drawings" :key="g.id" class="lk dwg-lk" :href="g.url" target="_blank" rel="noopener">{{ g.drawing_name }} V{{ g.version_no }}</a></template>
                <template v-else>还没上传</template>
              </b></div>
              <div v-if="$can(['production.tech', 'production.tech.manage'])" class="kv"><span>{{ wo.tech.status === 'pending' ? '等技术传图、确认 BOM 后下发' : '图纸和定制 BOM' }}</span><b class="lk" @click="$router.push(`/production/tech/${wo.id}`)">去定制技术</b></div>
            </div>
            <div class="card">
              <h2>需要处理 <small>{{ wo.attention.length }} 件</small></h2>
              <div v-if="!wo.attention.length" class="att ok-att"><b>一切正常</b><p>没有缺料、暂停或延期。</p></div>
              <div v-for="(a, i) in wo.attention" :key="i" :class="['att', a.type === 'danger' ? 'red' : 'orange']"><b>{{ a.title }}</b><p>{{ a.text }}</p></div>
            </div>
            <div class="card">
              <template v-if="wo.sales_order">
                <h2>这张工单的订单</h2>
                <div class="kv"><span>销售订单</span><b class="mono lk" @click="openOrder">{{ wo.sales_order.sales_order_no }}</b></div>
                <div class="kv"><span>客户</span><b>{{ wo.sales_order.customer_name }}</b></div>
                <div class="kv" v-if="wo.sales_order.order_qty !== null"><span>本行订购</span><b>{{ qty(wo.sales_order.order_qty) }} {{ wo.sales_order.unit_name || '' }}<template v-if="wo.sales_order.inventory_qty"> · 用库存 {{ qty(wo.sales_order.inventory_qty) }}</template></b></div>
                <div class="kv"><span>交期</span><b>{{ wo.sales_order.required_delivery_date || '-' }}</b></div>
                <div class="kv" v-if="wo.special_requirement"><span>特殊要求</span><b>{{ wo.special_requirement }}</b></div>
                <template v-if="wo.sales_order.funding">
                  <div class="kv"><span>付款条件</span><b>{{ wo.sales_order.funding.policy_name || '未配置' }}</b></div>
                  <div class="kv"><span>开工</span><b :class="wo.sales_order.funding.production_funds_satisfied ? 'ok' : 'warn'">{{ wo.sales_order.funding.production_funds_satisfied ? '定金够了' : '定金未到' }}<template v-if="wo.sales_order.funding.net_received_amount !== undefined"> · 已收 {{ money(wo.sales_order.funding.net_received_amount) }}</template></b></div>
                </template>
              </template>
              <template v-else>
                <h2>备货信息</h2>
                <div class="kv"><span>为什么备货</span><b>{{ wo.stock_reason_label || '其他' }}</b></div>
                <div class="kv"><span>计划开工</span><b>{{ wo.planned_start_date || '-' }}</b></div>
                <div class="kv"><span>计划完工</span><b>{{ wo.planned_finish_date || '-' }}</b></div>
              </template>
              <div class="kv" v-if="wo.remark"><span>备注</span><b>{{ wo.remark }}</b></div>
              <div class="kv"><span>创建</span><b>{{ wo.created_by_name || '系统' }} {{ shortDate(wo.created_at) }}</b></div>
            </div>
          </div>
        </div>
      </div>
    </template>

    <el-dialog title="调整工单" :visible.sync="adjust.visible" width="560px" custom-class="mfg-dialog" :close-on-click-modal="false">
      <el-form label-position="top" size="small" class="adj-form">
        <el-form-item label="负责人">
          <el-select v-model="adjust.owner_legacy_id" filterable remote clearable :remote-method="searchOwners" placeholder="搜索姓名" @focus="searchOwners('')">
            <el-option v-for="u in owners" :key="u.id" :label="u.name" :value="u.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="计划开工"><el-date-picker v-model="adjust.planned_start_date" type="date" value-format="yyyy-MM-dd" /></el-form-item>
        <el-form-item label="计划完工"><el-date-picker v-model="adjust.planned_finish_date" type="date" value-format="yyyy-MM-dd" /></el-form-item>
        <el-form-item label="加急"><el-switch v-model="adjust.is_urgent" active-text="加急" /></el-form-item>
        <el-form-item v-if="wo && wo.source_type === 'stock' && wo.status === 'planned'" label="数量"><el-input-number v-model="adjust.planned_qty" :min="0.0001" :max="100000" controls-position="right" /></el-form-item>
        <template v-if="wo && !wo.started">
          <el-form-item label="BOM（未开工可换）">
            <el-select v-model="adjust.bom_id" placeholder="选择 BOM">
              <el-option v-for="b in adjust.bomOptions" :key="b.id" :label="`${bomLabel(b.bom_name, b.bom_no, b.version)}${b.is_default ? '（默认）' : ''}`" :value="b.id" />
            </el-select>
          </el-form-item>
          <el-form-item label="工艺路线（未开工可换）">
            <el-select v-model="adjust.routing_id" placeholder="选择工艺路线">
              <el-option v-for="r in adjust.routingOptions" :key="r.id" :label="`${r.routing_name} V${r.version}${r.is_default ? '（默认）' : ''}`" :value="r.id" />
            </el-select>
          </el-form-item>
        </template>
        <el-form-item label="备注" class="full"><el-input v-model="adjust.remark" type="textarea" :rows="2" maxlength="500" /></el-form-item>
      </el-form>
      <p v-if="wo && wo.source_type === 'sales_order'" class="sub">订单工单的数量跟着订单走，改数量请走订单变更。</p>
      <span slot="footer"><el-button size="small" @click="adjust.visible = false">取消</el-button><el-button size="small" type="primary" :loading="saving" @click="saveAdjust">保存</el-button></span>
    </el-dialog>
  </section>
</template>

<script>
import { getWorkOrder, getWorkOrderOptions, updateWorkOrder, releaseWorkOrder, pauseWorkOrder, resumeWorkOrder, cancelWorkOrder } from '../../../api/erp/production'
import { HERO_PILL, qty, money, shortDate, bomLabel, errorText, isCancel, searchProductionUsers } from './mfg-helpers'

const STEPS = [{ value: 'planned', label: '计划' }, { value: 'released', label: '已下达' }, { value: 'in_progress', label: '生产中' }, { value: 'completed', label: '已完工' }, { value: 'closed', label: '已关闭' }]

export default {
  name: 'WorkOrderDetail',
  data: () => ({ loading: false, saving: false, wo: null, owners: [], unitLimit: 50, steps: STEPS, today: new Date().toISOString().slice(0, 10),
    adjust: { visible: false, owner_legacy_id: null, planned_start_date: '', planned_finish_date: '', is_urgent: false, planned_qty: 1, bom_id: null, routing_id: null, remark: '', bomOptions: [], routingOptions: [] } }),
  computed: {
    productName() { return this.wo ? (this.wo.product_name || this.wo.item_name) : '' },
    heroPill() { return HERO_PILL[this.wo.status] || 'p-n' },
    stepValue() { return this.wo.status === 'paused' ? (this.wo.started ? 'in_progress' : 'released') : this.wo.status },
    stepIndex() { return STEPS.findIndex(s => s.value === this.stepValue) },
    prodOps() { return this.wo.operations.filter(o => o.execution_context === 'production') },
    shipOps() { return this.wo.operations.filter(o => o.execution_context !== 'production') },
    visibleUnits() { return this.wo.units.slice(0, this.unitLimit) },
    canAdjust() { return !['completed', 'closed', 'cancelled'].includes(this.wo.status) },
    canCancel() {
      // 订单工单跟着订单走：订单仍有效时只能取消订单，不能单独取消工单
      const orderOpen = this.wo.source_type === 'sales_order' && (!this.wo.sales_order || this.wo.sales_order.order_status === 'confirmed')
      return ['planned', 'released', 'paused'].includes(this.wo.status) && !this.wo.started && !orderOpen
    },
    dueDiff() {
      if (!this.wo.required_date || !this.wo.planned_finish_date) return null
      return Math.round((new Date(this.wo.required_date) - new Date(this.wo.planned_finish_date)) / 86400000)
    },
    barPercent() {
      const start = this.wo.planned_start_date && new Date(this.wo.planned_start_date)
      const end = (this.wo.required_date || this.wo.planned_finish_date) && new Date(this.wo.required_date || this.wo.planned_finish_date)
      if (!start || !end || end <= start) return 0
      return Math.max(0, Math.min(100, Math.round((new Date(this.today) - start) / (end - start) * 100)))
    }
  },
  watch: { '$route.params.id'() { this.load() } },
  created() { this.load() },
  methods: {
    qty, money, shortDate, bomLabel,
    async load() {
      this.loading = true
      try {
        const { data } = await getWorkOrder(this.$route.params.id)
        this.wo = data.data
      } catch (e) {
        this.$message.error(errorText(e, '工单加载失败'))
      } finally {
        this.loading = false
      }
    },
    unitCount(status) { return this.wo.units.filter(u => u.status === status).length },
    opStatus(status) { return ({ pending: '未开始', in_progress: '在做', done: '做完' })[status] || status },
    hours(minutes) { return minutes >= 60 ? `${Number((minutes / 60).toFixed(1))} 时` : `${Math.round(minutes)} 分` },
    unitOpIndex(u) { return this.prodOps.findIndex(o => o.id === u.current_operation_id) },
    cellState(u, i) {
      if (['stored', 'completed'].includes(u.status)) return 'done'
      if (u.status === 'scrapped' || u.status === 'cancelled') return 'off'
      if (u.status === 'waiting') return ''
      const cur = this.unitOpIndex(u)
      if (cur < 0) return i === 0 ? (u.status === 'rework' ? 'bad' : 'doing') : ''
      if (i < cur) return 'done'
      if (i === cur) return u.status === 'rework' ? 'bad' : 'doing'
      return ''
    },
    cellText(u, i) {
      const s = this.cellState(u, i)
      return ({ done: '✓ 完成', doing: '● 在做', bad: '返工中', off: u.status_label })[s] || '—'
    },
    kitLabel(k) {
      if (k.status === 'short') return `缺 ${k.shortage_count} 项`
      return k.status === 'ready' ? '齐套' : '不领料'
    },
    openOrder() { if (this.wo.sales_order_id) this.$router.push(`/sales/orders/${this.wo.sales_order_id}/detail`) },
    openSerial(sn) { if (sn && this.$can('production.serial.view')) this.$router.push({ path: '/production/serials', query: { sn } }) },
    async searchOwners(keyword) { try { this.owners = await searchProductionUsers(keyword) } catch (e) { this.owners = [] } },
    async run(fn, okText) {
      this.saving = true
      try {
        const { data } = await fn()
        this.wo = data.data
        this.$message.success(data.message || okText)
        return true
      } catch (e) {
        this.$message.error(errorText(e, '操作失败'))
        if (e && e.response && e.response.status === 409) this.load()
        return false
      } finally {
        this.saving = false
      }
    },
    async release() {
      try { await this.$confirm('下达后生成序列号，车间就能开工。缺料只提醒，不拦下达。', '下达工单', { confirmButtonText: '下达' }) } catch (e) { return }
      await this.run(() => releaseWorkOrder(this.wo.id, { expected_version: this.wo.business_version }), '工单已下达')
    },
    async pause() {
      let reason
      try { ({ value: reason } = await this.$prompt('暂停后车间暂时不能报工，请写明原因。', '暂停工单', { inputPlaceholder: '例如：客户要求改配置', inputValidator: v => !!(v && v.trim()) || '请填写暂停原因' })) } catch (e) { if (isCancel(e)) return }
      await this.run(() => pauseWorkOrder(this.wo.id, { expected_version: this.wo.business_version, reason: reason.trim() }), '工单已暂停')
    },
    async resume() {
      await this.run(() => resumeWorkOrder(this.wo.id, { expected_version: this.wo.business_version }), '工单已恢复')
    },
    async cancel() {
      let reason
      const tip = '取消后序列号作废，请写明原因。'
      try { ({ value: reason } = await this.$prompt(tip, '取消工单', { type: 'warning', inputPlaceholder: '取消原因', inputValidator: v => !!(v && v.trim()) || '请填写取消原因' })) } catch (e) { if (isCancel(e)) return }
      await this.run(() => cancelWorkOrder(this.wo.id, { expected_version: this.wo.business_version, reason: reason.trim() }), '工单已取消')
    },
    async openAdjust() {
      const wo = this.wo
      Object.assign(this.adjust, { visible: true, owner_legacy_id: wo.owner_legacy_id, planned_start_date: wo.planned_start_date, planned_finish_date: wo.planned_finish_date,
        is_urgent: wo.is_urgent, planned_qty: wo.planned_qty, bom_id: wo.bom_id, routing_id: wo.routing_id, remark: wo.remark || '', bomOptions: [], routingOptions: [] })
      this.owners = wo.owner_legacy_id ? [{ id: wo.owner_legacy_id, name: wo.owner_name }] : []
      if (!wo.started) {
        try {
          const { data } = await getWorkOrderOptions(wo.id)
          this.adjust.bomOptions = data.data.bom_options || []
          this.adjust.routingOptions = data.data.routing_options || []
        } catch (e) { this.$message.warning(errorText(e, 'BOM / 路线选项加载失败')) }
      }
    },
    async saveAdjust() {
      const a = this.adjust
      const wo = this.wo
      const payload = { expected_version: wo.business_version }
      const owner = this.owners.find(u => u.id === a.owner_legacy_id)
      if (a.owner_legacy_id !== wo.owner_legacy_id) { payload.owner_legacy_id = a.owner_legacy_id || null; payload.owner_name = owner ? owner.name : null }
      ;['planned_start_date', 'planned_finish_date', 'remark'].forEach(f => { if ((a[f] || '') !== (wo[f] || '')) payload[f] = a[f] || null })
      if (a.is_urgent !== wo.is_urgent) payload.is_urgent = a.is_urgent
      if (!wo.started) { if (a.bom_id !== wo.bom_id) payload.bom_id = a.bom_id; if (a.routing_id !== wo.routing_id) payload.routing_id = a.routing_id }
      if (wo.source_type === 'stock' && wo.status === 'planned' && Number(a.planned_qty) !== Number(wo.planned_qty)) payload.planned_qty = a.planned_qty
      if (Object.keys(payload).length === 1) { this.$message.info('没有需要保存的修改'); return }
      if (await this.run(() => updateWorkOrder(wo.id, payload), '工单已调整')) a.visible = false
    }
  }
}
</script>

<style scoped src="./mfg-v2.css"></style>
<style scoped>
.hero-top { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.hero-bottom { display: flex; justify-content: space-between; align-items: flex-end; gap: 12px; margin-top: 14px; flex-wrap: wrap; }
.stepper { display: flex; gap: 4px; flex-wrap: wrap; }
.stepper span { color: #6f7f9f; padding: 6px 14px; border-bottom: 3px solid transparent; }
.stepper span.done { color: #b9c5dc; }
.stepper span.on { color: #fff; font-weight: 700; border-color: #ffb020; }
.due-box { background: #1c2b4d; border: 1px solid #2b3d66; border-radius: 10px; padding: 12px 16px; min-width: 280px; }
.sub-light { color: #b9c5dc; font-size: 12px; }
.due-main { font-size: 19px; font-weight: 700; margin: 4px 0 8px; }
.due-main em { font-style: normal; font-size: 12px; margin-left: 6px; }
.due-main em.g { color: #4ade80; } .due-main em.r { color: #ff8a8d; }
.bar { height: 6px; background: #3a4f7d; border-radius: 3px; overflow: hidden; }
.bar i { display: block; height: 100%; background: #4ade80; }
.due-foot { display: flex; justify-content: space-between; gap: 10px; color: #8fa0bf; font-size: 11px; margin-top: 6px; }
.unit-sum { font-size: 12px; color: #5b6780; font-weight: 400; }
.unit-sum b { font-size: 16px; margin-left: 10px; color: #172033; }
.unit-sum b.warn { color: #d92d20; }
.unit-wrap { overflow-x: auto; }
.unit-table { width: 100%; border-collapse: separate; border-spacing: 4px; min-width: 560px; }
.unit-table th { font-size: 12px; color: #5b6780; font-weight: 600; text-align: left; padding: 4px; white-space: nowrap; }
.unit-table th.grp { color: #8a95a8; font-weight: 500; border-bottom: 2px solid #14213d; }
.unit-table th.grp.ship { border-color: #c9d2e0; }
.unit-table td { vertical-align: top; }
.sn { font-size: 12px; font-weight: 600; white-space: nowrap; }
.small { font-size: 12px; }
.cell { border-radius: 6px; background: #f3f5f9; color: #8a95a8; padding: 8px; font-size: 12px; min-height: 34px; }
.cell.done { background: #e6f7ef; color: #11804a; font-weight: 600; }
.cell.doing { background: #fff8e8; border: 1px solid #ffb020; color: #9a5b00; font-weight: 600; }
.cell.bad { background: #ffe9e7; border: 1px solid #f19a93; color: #c0261b; font-weight: 600; }
.cell.dash { background: #fff; border: 1px dashed #d5dbe5; color: #b0b9c8; }
.cell.off { background: #eef1f5; color: #8a95a8; text-decoration: line-through; }
.op-table td { font-size: 12px; padding: 6px 4px; border-bottom: 1px solid #f0f2f6; white-space: nowrap; }
.dwg-lk + .dwg-lk { margin-left: 8px; }
.more { text-align: center; padding-top: 8px; }
.tip { margin: 10px 0 0; }
.two-col { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
.cost-main { font-size: 22px; font-weight: 700; margin-bottom: 8px; }
.cost-main .sub { font-size: 12px; font-weight: 400; }
.log { display: grid; grid-template-columns: 48px 1fr; gap: 8px; padding: 6px 0; font-size: 12px; }
.att { border-left: 3px solid #ffb020; background: #fafbfd; border-radius: 6px; padding: 10px 12px; margin-bottom: 10px; }
.att.red { border-color: #ff5a5f; } .att.ok-att { border-color: #4ade80; }
.att b { font-size: 13px; } .att p { margin: 6px 0 0; color: #5b6780; font-size: 12px; line-height: 1.6; }
.adj-form { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 16px; }
.adj-form .full { grid-column: 1 / -1; }
.adj-form .el-select, .adj-form .el-date-editor, .adj-form .el-input-number { width: 100%; }
@media (max-width: 767px) {
  .due-box { min-width: 0; width: 100%; }
  .two-col, .adj-form { grid-template-columns: 1fr; }
  ::v-deep .mfg-dialog { width: 94% !important; }
}
</style>
