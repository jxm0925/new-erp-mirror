<template>
  <div class="production-board-page" v-loading="loading && !board">
    <!-- 顶部统一标头 (.page-head) -->
    <header class="page-head">
      <div class="head-left">
        <span class="head-icon"><i class="el-icon-data-board" /></span>
        <div class="head-title-wrap">
          <div class="title-row">
            <h1 class="page-title">车间生产作业实时看板</h1>
            <span class="live-tag"><i class="el-icon-loading" /> 每 30 秒自动刷新</span>
          </div>
          <div class="sub-row" v-if="board">
            数据最后更新于：<b>{{ (board.refreshed_at || '').slice(11, 19) || (board.refreshed_at || '').slice(11, 16) }}</b>
          </div>
        </div>
      </div>
      <div class="head-actions">
        <el-button size="small" icon="el-icon-refresh" class="btn-refresh" :loading="loading" @click="load(false)">
          手动刷新
        </el-button>
      </div>
    </header>

    <!-- 顶部核心指标统计网格 -->
    <div class="metric-strip" v-if="board">
      <div class="metric-card">
        <div class="metric-icon icon-primary"><i class="el-icon-cpu" /></div>
        <div class="metric-info">
          <span class="metric-label">在制机台总量</span>
          <b class="metric-val text-primary">{{ board.stats.in_progress_units }} <small>台</small></b>
        </div>
      </div>
      <div class="metric-card">
        <div class="metric-icon icon-success"><i class="el-icon-circle-check" /></div>
        <div class="metric-info">
          <span class="metric-label">今日工序完成</span>
          <b class="metric-val text-success">{{ board.stats.today_passed }} <small>次</small></b>
        </div>
      </div>
      <div class="metric-card">
        <div class="metric-icon icon-warning"><i class="el-icon-user" /></div>
        <div class="metric-info">
          <span class="metric-label">待派工作业</span>
          <b class="metric-val text-warning">{{ board.stats.unclaimed }} <small>项</small></b>
        </div>
      </div>
      <div class="metric-card">
        <div class="metric-icon icon-danger"><i class="el-icon-refresh-left" /></div>
        <div class="metric-info">
          <span class="metric-label">在制返修机台</span>
          <b class="metric-val text-danger">{{ board.stats.rework_units }} <small>台</small></b>
        </div>
      </div>
      <div class="metric-card">
        <div class="metric-icon icon-info"><i class="el-icon-box" /></div>
        <div class="metric-info">
          <span class="metric-label">待入库机台</span>
          <b class="metric-val text-info">{{ board.stats.waiting_receipt }} <small>台</small></b>
        </div>
      </div>
      <div class="metric-card">
        <div class="metric-icon icon-purple"><i class="el-icon-time" /></div>
        <div class="metric-info">
          <span class="metric-label">今日累计工时</span>
          <b class="metric-val text-purple">{{ board.stats.today_hours }} <small>小时</small></b>
        </div>
      </div>
    </div>

    <!-- 主体区域 -->
    <div class="board-body">
      <!-- 筛选工具栏 -->
      <div class="toolbar-card">
        <div class="toolbar-left">
          <el-input
            v-model="filters.keyword"
            size="small"
            clearable
            placeholder="搜索工单号 / 序列号 / 产品名称"
            prefix-icon="el-icon-search"
            class="search-input"
            @keyup.enter.native="load(false)"
            @clear="load(false)"
          />
          <el-select
            v-model="filters.owner_legacy_id"
            size="small"
            clearable
            placeholder="全部负责人"
            class="owner-select"
            @change="load(false)"
          >
            <el-option v-for="u in (board ? board.owner_options : [])" :key="u.id" :label="u.name" :value="u.id" />
          </el-select>
          <el-checkbox v-model="filters.urgent_only" border size="small" class="theme-checkbox" @change="load(false)">
            只看加急
          </el-checkbox>
          <el-checkbox v-model="filters.custom_only" border size="small" class="theme-checkbox" @change="load(false)">
            只看定制
          </el-checkbox>
        </div>

        <div class="legend-bar">
          <span class="legend-item"><i class="lg-dot lg-waiting" /> 待作业</span>
          <span class="legend-item"><i class="lg-dot lg-doing" /> 进行中</span>
          <span class="legend-item"><i class="lg-dot lg-rework" /> 返工中</span>
          <span class="legend-item"><i class="lg-dot lg-done" /> 已完成</span>
        </div>
      </div>

      <!-- 看板网格 -->
      <div class="board-grid" v-if="board">
        <!-- 泳道主区域 (水平滚动) -->
        <div class="lanes-wrap">
          <!-- 动态工序泳道 -->
          <div v-for="col in board.columns" :key="col.key" class="board-lane">
            <div class="lane-header">
              <span class="lane-title">{{ col.title }}</span>
              <span class="lane-count-tag">{{ col.unit_count }} 台 · {{ col.people_count }} 人</span>
            </div>
            <div class="lane-content">
              <div
                v-for="c in col.cards"
                :key="c.operation_id"
                :class="['board-card', { 'is-rework': c.rework_count, 'is-paused': c.paused }]"
              >
                <div class="card-head-row">
                  <span class="mono-code">{{ c.work_order_no }}</span>
                  <div class="card-badges">
                    <span v-if="c.paused" class="chip chip-paused">暂停</span>
                    <span v-if="c.is_urgent" class="chip chip-urgent">加急</span>
                    <span v-if="c.is_custom" class="chip chip-custom">定制{{ c.has_drawing ? ' (附图纸)' : '' }}</span>
                  </div>
                </div>
                <div class="card-prod-title">
                  {{ c.product_name }}<template v-if="c.sku_name"> {{ c.sku_name }}</template>
                  <b class="card-qty">×{{ qtyText(c.planned_qty) }}</b>
                </div>
                <div class="card-op-sub" v-if="c.operation_name !== col.title">
                  {{ c.operation_name }}
                </div>
                <div class="sn-list">
                  <span
                    v-for="u in c.units"
                    :key="u.id"
                    :class="['sn-chip', u.status === 'rework' ? 'sn-rework' : (u.status === 'waiting' ? 'sn-waiting' : 'sn-doing')]"
                    :title="u.serial_no"
                  >
                    {{ u.short }}
                    <em v-if="u.pending_scrap" class="sn-flag">待报废</em>
                    <em v-else-if="u.status === 'rework'" class="sn-flag">返工</em>
                  </span>
                </div>
                <div class="card-footer-row">
                  <div class="card-owner-info">
                    <span v-if="c.paused" class="text-muted">{{ c.pause_reason || '工单已暂停' }}</span>
                    <span v-else-if="c.state === 'unclaimed'" class="text-warning font-medium">待指派派工</span>
                    <span v-else class="text-secondary">
                      {{ c.owner_name }} ·
                      <template v-if="c.timing.length">作业中{{ c.timing.length > 1 ? ` (${c.timing.length}人)` : '' }}，</template>
                      <template v-if="c.labor_minutes">已耗时 {{ minutesText(c.labor_minutes) }}</template>
                      <template v-else>已接单待开工</template>
                    </span>
                  </div>
                  <div class="card-actions">
                    <a v-if="board.permissions.assign && !c.paused" class="action-link-theme" @click="openAssign(c)">
                      {{ c.state === 'unclaimed' ? '派工' : '转派' }}
                    </a>
                    <a class="action-link-theme" @click="openWo(c.work_order_id)">详情</a>
                  </div>
                </div>
              </div>
              <div v-if="!col.cards.length" class="empty-lane">暂无在制任务</div>
            </div>
          </div>

          <div v-if="!board.columns.length" class="board-lane">
            <div class="lane-header"><span class="lane-title">生产工序</span></div>
            <div class="empty-lane">当前暂无在制作业工单</div>
          </div>

          <!-- 待入库成品泳道 -->
          <div class="board-lane lane-waiting-receipt">
            <div class="lane-header">
              <span class="lane-title">待入库成品</span>
              <span class="lane-count-tag count-success">{{ board.stats.waiting_receipt }} 台</span>
            </div>
            <div class="lane-content">
              <div v-for="w in board.waiting_receipt" :key="w.work_order_id" class="board-card">
                <div class="card-head-row">
                  <span class="mono-code">{{ w.work_order_no }}</span>
                  <span class="chip chip-receipt-ready">待入库</span>
                </div>
                <div class="card-prod-title">{{ w.product_name }}<template v-if="w.sku_name"> {{ w.sku_name }}</template></div>
                <div class="sn-list">
                  <span v-for="u in w.units" :key="u.id" class="sn-chip sn-done" :title="u.serial_no">{{ u.short }}</span>
                </div>
                <div class="card-footer-row">
                  <span class="text-secondary">{{ w.last_operation_name || '末道检验' }}合格 {{ lastPassed(w) }}</span>
                  <el-button v-if="board.permissions.receipt" size="mini" type="success" class="btn-receipt-action" @click="openReceipt(w)">
                    办理入库
                  </el-button>
                </div>
              </div>
              <div v-if="!board.waiting_receipt.length" class="empty-lane">暂无待入库成品</div>
            </div>
          </div>

          <!-- 今日已入库泳道 -->
          <div class="board-lane lane-stored">
            <div class="lane-header">
              <span class="lane-title">今日已入库</span>
              <span class="lane-count-tag count-primary">{{ todayStored }} 台</span>
            </div>
            <div class="lane-content">
              <div v-for="r in board.today_receipts" :key="r.id" class="board-card">
                <div class="card-head-row">
                  <span class="mono-code">{{ r.work_order_no }}</span>
                  <span class="chip chip-stored">已入库</span>
                </div>
                <div class="card-prod-title">{{ r.product_name }}<template v-if="r.sku_name"> {{ r.sku_name }}</template></div>
                <div class="sn-list">
                  <span v-for="sn in r.serial_nos" :key="sn" class="sn-chip sn-done" :title="sn">{{ sn.slice(-4) }}</span>
                </div>
                <div class="card-footer-row">
                  <span class="text-secondary">{{ r.operator_name }} · {{ r.warehouse_name }} {{ r.location_code }}</span>
                </div>
              </div>
              <div v-if="!board.today_receipts.length" class="empty-lane">今日暂无入库机台</div>
            </div>
          </div>
        </div>

        <!-- 右侧辅助面板 -->
        <div class="side-panel">
          <!-- 待确认报废卡片 -->
          <div class="side-card" v-if="board.pending_scraps.length">
            <div class="side-card-title">
              <h2 class="section-title">待确认报废机台 <small class="text-danger">{{ board.pending_scraps.length }} 台</small></h2>
            </div>
            <div v-for="s in board.pending_scraps" :key="s.id" class="scrap-item">
              <div class="scrap-head">
                <b class="mono-code">{{ s.serial_no }}</b>
                <span class="text-secondary">{{ s.operation_name }} · {{ s.reporter_name }}</span>
              </div>
              <div class="scrap-reason">
                {{ s.product_name }} · {{ [s.reason_tags.join('/'), s.reason].filter(Boolean).join('：') }}
              </div>
              <div v-if="s.photos.length" class="scrap-photos">
                <el-image v-for="p in s.photos" :key="p" :src="p" :preview-src-list="s.photos" fit="cover" />
              </div>
              <div v-if="board.permissions.scrap" class="scrap-actions">
                <el-button size="mini" type="danger" plain @click="review(s, true)">确认报废</el-button>
                <el-button size="mini" @click="review(s, false)">驳回报废，退回返工</el-button>
              </div>
            </div>
          </div>

          <!-- 待指派任务卡片 -->
          <div class="side-card">
            <div class="side-card-title">
              <h2 class="section-title">待指派作业任务 <small class="text-warning">{{ board.unclaimed.length }} 项</small></h2>
            </div>
            <div v-for="t in board.unclaimed" :key="t.operation_id" class="unclaimed-item">
              <div class="unclaimed-info">
                <b>{{ t.operation_name }} · {{ t.product_name }} ×{{ t.qty }}</b>
                <div class="text-muted small">{{ t.is_custom ? '定制 · ' : '' }}要求交期 {{ shortDate(t.planned_finish_date) }}</div>
              </div>
              <el-button v-if="board.permissions.assign" size="mini" type="success" plain @click="openAssign(t)">
                指派…
              </el-button>
            </div>
            <div v-if="!board.unclaimed.length" class="empty-tip">当前作业均已分配负责人</div>
            <div class="tip-footnote">车间生产人员亦可在移动端作业台自主认领任务。</div>
          </div>

          <!-- 实时报工动态卡片 -->
          <div class="side-card">
            <div class="side-card-title">
              <h2 class="section-title">实时报工动态</h2>
            </div>
            <div v-for="r in board.recent_reports" :key="r.id" class="report-item">
              <span class="report-time">{{ r.time }}</span>
              <span class="report-content">
                {{ r.reporter_name }} {{ r.operation_name }} <span class="mono-code">{{ r.short }}</span>
                <b v-if="r.result === 'passed'" class="text-success">检验合格</b>
                <template v-else-if="r.result === 'failed'">
                  <b class="text-danger">检验不合格</b>，{{ r.return_operation_name === r.operation_name ? '本道返工' : '退回 ' + r.return_operation_name + ' 工序' }}
                  <template v-if="r.reason">：{{ r.reason }}</template>
                </template>
                <template v-else>
                  <b class="text-danger">申请报废</b>
                  <template v-if="r.reason">：{{ r.reason }}</template>
                </template>
                <span v-if="r.is_rework" class="chip chip-urgent">返工</span>
              </span>
            </div>
            <div v-if="!board.recent_reports.length" class="empty-tip">今日暂无报工记录</div>
          </div>
        </div>
      </div>

      <!-- 底部业务说明条 -->
      <div class="board-footnote-alert">
        <i class="el-icon-info" />
        <span>看板说明：泳道依工艺阶段聚合呈现；点击“详情”可下钻查看工单单台流转进度；质检返工机台将回退至责任工序泳道并标识返工预警。</span>
      </div>
    </div>

    <!-- 派工居中弹窗 -->
    <el-dialog title="工序派工作业指派" :visible.sync="assign.visible" width="460px" custom-class="centered-modal" :close-on-click-modal="false">
      <div class="modal-notice">
        <i class="el-icon-info" />
        <span>{{ assign.title }}。系统仅展示具备本道工序操作资质与技能认证的生产人员。</span>
      </div>
      <el-form label-position="top" size="small" style="margin-top: 12px;">
        <el-form-item label="指定作业人员" required>
          <el-select
            v-model="assign.employee"
            filterable
            remote
            :remote-method="searchPeople"
            placeholder="输入姓名搜索车间员工"
            style="width: 100%"
            @focus="searchPeople('')"
          >
            <el-option v-for="u in people" :key="u.id" :label="u.name" :value="u.id" />
          </el-select>
        </el-form-item>
      </el-form>
      <span slot="footer">
        <el-button size="small" @click="assign.visible = false">取消</el-button>
        <el-button size="small" type="success" class="btn-theme-create" :loading="saving" :disabled="!assign.employee" @click="saveAssign">
          确认派工
        </el-button>
      </span>
    </el-dialog>

    <!-- 办理入库居中弹窗 -->
    <el-dialog
      title="成品办理入库确认"
      :visible.sync="receipt.visible"
      width="540px"
      custom-class="centered-modal"
      :close-on-click-modal="false"
      @close="receiptPicker.visible = false"
    >
      <template v-if="receipt.row">
        <div class="receipt-product-summary">
          <span class="mono-code">{{ receipt.row.work_order_no }}</span>
          <b>{{ receipt.row.product_name }}</b>
        </div>
        <el-form label-position="top" size="small">
          <el-form-item label="入库产品序列号（台）" required>
            <el-checkbox-group v-model="receipt.unit_ids" class="sn-checkbox-group">
              <el-checkbox v-for="u in receipt.row.units" :key="u.id" :label="u.id">{{ u.serial_no }}</el-checkbox>
            </el-checkbox-group>
          </el-form-item>
          <el-form-item label="入库目标仓库" required>
            <el-button class="receipt-selector-btn" :disabled="saving || !receipt.management_scope" @click="openReceiptPicker('warehouses')">
              <span>{{ receipt.warehouse_name || '选择目标仓库' }}</span>
              <i class="el-icon-search" />
            </el-button>
          </el-form-item>
          <el-form-item label="入库目标库位" required>
            <el-button class="receipt-selector-btn" :disabled="!receipt.warehouse_id || saving" @click="openReceiptPicker('locations')">
              <span>{{ receipt.location_name || '选择具体库位' }}</span>
              <i class="el-icon-search" />
            </el-button>
          </el-form-item>
          <el-form-item label="入库备注">
            <el-input v-model="receipt.remark" maxlength="500" placeholder="填写相关入库说明" />
          </el-form-item>
        </el-form>
        <div class="modal-notice">
          <i class="el-icon-info" />
          <span>业务说明：完成入库确认后，系统将自动核算入库暂估制造成本，并流转至产成品可用库存。</span>
        </div>
      </template>
      <span slot="footer">
        <el-button size="small" @click="receipt.visible = false">取消</el-button>
        <el-button
          size="small"
          type="success"
          class="btn-theme-create"
          :loading="saving"
          :disabled="!receipt.unit_ids.length || !receipt.location_id"
          @click="saveReceipt"
        >
          确认办理入库
        </el-button>
      </span>
    </el-dialog>

    <system-record-picker
      :visible.sync="receiptPicker.visible"
      :type="receiptPicker.type"
      :multiple="false"
      :value="receiptPickerValue"
      :warehouse-id="Number(receipt.warehouse_id || 0)"
      :location-purposes="receipt.allowed_location_purposes"
      :management-scope="receipt.management_scope || ''"
      @confirm="selectReceiptLocator"
    />
  </div>
</template>

<script>
import { getProductionBoard, taskAction, listTaskPeople, reviewScrapReport, receiveWorkOrderUnits } from '../../../api/erp/production'
import { getEntity } from '../../../api/erp/master'
import SystemRecordPicker from '../../../components/system/SystemRecordPicker.vue'
import { qty, shortDate, errorText, isCancel } from './mfg-helpers'

export default {
  name: 'ProductionBoard',
  components: { SystemRecordPicker },
  data: () => ({
    loading: false,
    saving: false,
    board: null,
    timer: null,
    people: [],
    filters: { keyword: '', owner_legacy_id: null, urgent_only: false, custom_only: false },
    assign: { visible: false, op: null, title: '', employee: null },
    receipt: {
      visible: false,
      row: null,
      unit_ids: [],
      management_scope: null,
      allowed_location_purposes: [],
      warehouse_id: null,
      warehouse_name: '',
      location_id: null,
      location_name: '',
      remark: ''
    },
    receiptPicker: { visible: false, type: 'warehouses' }
  }),
  computed: {
    todayStored() {
      return this.board ? Number(this.board.stats.today_received_qty || 0) : 0
    },
    receiptPickerValue() {
      const r = this.receipt
      return this.receiptPicker.type === 'warehouses'
        ? (r.warehouse_id ? [{ id: r.warehouse_id, warehouse_name: r.warehouse_name }] : [])
        : (r.location_id ? [{ id: r.location_id, location_name: r.location_name }] : [])
    }
  },
  created() {
    this.load(false)
    this.timer = setInterval(() => {
      if (!this.assign.visible && !this.receipt.visible) this.load(true)
    }, 30000)
  },
  beforeDestroy() {
    clearInterval(this.timer)
  },
  methods: {
    shortDate,
    qtyText: qty,
    minutesText(m) {
      const total = Math.round(Number(m || 0))
      const h = Math.floor(total / 60)
      return h ? `${h} 小时 ${String(total % 60).padStart(2, '0')} 分` : `${total} 分钟`
    },
    lastPassed(w) {
      const t = w.units.map(u => u.passed_at).filter(Boolean).sort().pop()
      return t ? t.slice(5, 10) : ''
    },
    async load(silent) {
      if (!silent) this.loading = true
      try {
        const params = {
          ...this.filters,
          urgent_only: this.filters.urgent_only ? 1 : undefined,
          custom_only: this.filters.custom_only ? 1 : undefined,
          owner_legacy_id: this.filters.owner_legacy_id || undefined,
          keyword: this.filters.keyword || undefined
        }
        const { data } = await getProductionBoard(params)
        this.board = data.data
      } catch (e) {
        if (!silent) this.$message.error(errorText(e, '看板数据加载失败'))
      } finally {
        this.loading = false
      }
    },
    openWo(id) {
      this.$router.push(`/production/work-orders/${id}`)
    },
    openAssign(c) {
      this.assign = { visible: true, op: c.operation_id, title: `${c.operation_name} · ${c.product_name}`, employee: null }
      this.people = []
    },
    async searchPeople(keyword) {
      if (!this.assign.op) return
      try {
        const { data } = await listTaskPeople(this.assign.op, { keyword: keyword || undefined })
        this.people = data.data || []
      } catch (e) {
        this.people = []
      }
    },
    async saveAssign() {
      this.saving = true
      try {
        const { data } = await taskAction(this.assign.op, { action: 'assign', employee_legacy_id: this.assign.employee })
        this.$message.success(data.message || '派工指派已完成')
        this.assign.visible = false
        this.load(true)
      } catch (e) {
        this.$message.error(errorText(e, '派工失败'))
      } finally {
        this.saving = false
      }
    },
    async review(s, approve) {
      let remark = ''
      try {
        const r = await this.$prompt(
          approve ? `确认将产品序列号「${s.serial_no}」判定报废？报废后该机台将终止生产。` : `驳回后序列号「${s.serial_no}」将退回「${s.operation_name}」继续返工。`,
          approve ? '确认报废审核' : '驳回报废确认',
          { inputPlaceholder: '请录入审核处理意见（选填）', confirmButtonText: approve ? '确认报废' : '驳回' }
        )
        remark = r.value || ''
      } catch (e) {
        if (isCancel(e)) return
      }
      try {
        const { data } = await reviewScrapReport(s.id, { approve, remark: remark || undefined })
        this.$message.success(data.message || '审核处理完成')
        this.load(true)
      } catch (e) {
        this.$message.error(errorText(e, '审核操作失败'))
      }
    },
    async openReceipt(w) {
      const r = this.receipt = {
        visible: true,
        row: w,
        unit_ids: w.units.map(u => u.id),
        management_scope: ['factory', 'office'].includes(w.management_scope) ? w.management_scope : null,
        allowed_location_purposes: w.allowed_location_purposes || [],
        warehouse_id: null,
        warehouse_name: '',
        location_id: null,
        location_name: '',
        remark: ''
      }
      if (!r.management_scope) {
        this.$message.error('产出物料管理范围未确定，请先核对物料档案')
        return
      }
      if (!w.warehouse_id) return
      try {
        const { data } = await getEntity('warehouses', w.warehouse_id, { include_location_summary: 1, management_scope: r.management_scope })
        if (this.receipt !== r || !r.visible || r.warehouse_id) return
        const warehouse = data
        if (warehouse.status !== 'enabled' || warehouse.management_scope !== r.management_scope) return
        r.warehouse_id = warehouse.id
        r.warehouse_name = warehouse.warehouse_name
      } catch (e) {
        if (this.receipt !== r || !r.visible || r.warehouse_id) return
        this.$message.error(errorText(e, '仓库信息加载失败，请手动选择'))
      }
    },
    openReceiptPicker(type) {
      if (this.saving || !this.receipt.visible || !this.receipt.management_scope || (type === 'locations' && !this.receipt.warehouse_id)) return
      if (!this.receipt.allowed_location_purposes.length) {
        this.$message.error('入库库位用途未确定，请刷新看板后重试')
        return
      }
      this.receiptPicker = { type, visible: true }
    },
    selectReceiptLocator(rows) {
      const row = rows[0]
      if (!row || !this.receipt.visible) return
      if (this.receiptPicker.type === 'warehouses') {
        if (Number(this.receipt.warehouse_id) !== Number(row.id)) {
          this.receipt.location_id = null
          this.receipt.location_name = ''
        }
        this.receipt.warehouse_id = row.id
        this.receipt.warehouse_name = row.warehouse_name
      } else {
        this.receipt.location_id = row.id
        this.receipt.location_name = [row.location_code, row.location_name].filter(Boolean).join(' ')
      }
    },
    async saveReceipt() {
      const r = this.receipt
      this.saving = true
      try {
        const { data } = await receiveWorkOrderUnits(r.row.work_order_id, {
          unit_ids: r.unit_ids,
          warehouse_id: r.warehouse_id,
          location_id: r.location_id,
          remark: r.remark || undefined
        })
        this.$message.success(data.message || '办理入库成功')
        r.visible = false
        this.load(true)
      } catch (e) {
        this.$message.error(errorText(e, '办理入库失败'))
      } finally {
        this.saving = false
      }
    }
  }
}
</script>

<style scoped>
.production-board-page {
  min-height: calc(100vh - 52px);
  padding: 16px 20px 30px;
  background: #f8fafc;
  color: #1e293b;
  box-sizing: border-box;
}

/* 顶部页面标头 (.page-head) */
.page-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  padding: 16px 20px;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  margin-bottom: 14px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
}
.head-left {
  display: flex;
  align-items: center;
  gap: 12px;
}
.head-icon {
  width: 38px;
  height: 38px;
  border-radius: 8px;
  background: #f0fdf4;
  color: #008b4b;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  flex-shrink: 0;
}
.head-title-wrap {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.title-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.page-title {
  margin: 0;
  font-size: 18px;
  font-weight: 700;
  color: #0f172a;
}
.live-tag {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 11px;
  padding: 2px 8px;
  border-radius: 10px;
  background: #f0fdf4;
  color: #008b4b;
  border: 1px solid #bbf7d0;
  font-weight: 500;
}
.sub-row {
  font-size: 12px;
  color: #64748b;
}
.sub-row b {
  color: #0f172a;
  font-family: "DejaVu Sans Mono", Menlo, Consolas, monospace;
}
.head-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}
.btn-refresh {
  border-color: #cbd5e1 !important;
  color: #475569 !important;
}
.btn-refresh:hover {
  border-color: #008b4b !important;
  color: #008b4b !important;
}

/* 顶部指标卡条 */
.metric-strip {
  display: grid;
  grid-template-columns: repeat(6, minmax(0, 1fr));
  gap: 12px;
  margin-bottom: 14px;
}
.metric-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 12px 14px;
  display: flex;
  align-items: center;
  gap: 12px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
}
.metric-icon {
  width: 40px;
  height: 40px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  flex-shrink: 0;
}
.icon-primary { background: #f0fdf4; color: #008b4b; }
.icon-success { background: #ecfdf5; color: #10b981; }
.icon-warning { background: #fffbeb; color: #f59e0b; }
.icon-danger  { background: #fef2f2; color: #ef4444; }
.icon-info    { background: #eff6ff; color: #3b82f6; }
.icon-purple  { background: #faf5ff; color: #8b5cf6; }

.metric-info {
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.metric-label {
  font-size: 12px;
  color: #64748b;
  white-space: nowrap;
}
.metric-val {
  font-size: 18px;
  font-weight: 700;
  line-height: 1.3;
}
.metric-val small {
  font-size: 12px;
  font-weight: 400;
  color: #64748b;
  margin-left: 2px;
}
.text-primary { color: #008b4b !important; }
.text-success { color: #10b981 !important; }
.text-warning { color: #f59e0b !important; }
.text-danger  { color: #ef4444 !important; }
.text-info    { color: #3b82f6 !important; }
.text-purple  { color: #8b5cf6 !important; }

/* 筛选工具栏卡片 */
.toolbar-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 12px 16px;
  margin-bottom: 14px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
}
.toolbar-left {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.search-input {
  width: 280px;
}
.owner-select {
  width: 150px;
}
.theme-checkbox ::v-deep.is-checked .el-checkbox__label {
  color: #008b4b;
}
.theme-checkbox ::v-deep.is-checked .el-checkbox__inner {
  background-color: #008b4b;
  border-color: #008b4b;
}
.legend-bar {
  display: flex;
  align-items: center;
  gap: 14px;
  font-size: 12px;
  color: #64748b;
}
.legend-item {
  display: flex;
  align-items: center;
  gap: 5px;
}
.lg-dot {
  width: 10px;
  height: 10px;
  border-radius: 3px;
  display: inline-block;
}
.lg-waiting { background: #f1f5f9; border: 1px solid #cbd5e1; }
.lg-doing   { background: #fef3c7; border: 1px solid #fde68a; }
.lg-rework  { background: #fee2e2; border: 1px solid #fecaca; }
.lg-done    { background: #dcfce7; border: 1px solid #bbf7d0; }

/* 看板网格布局 */
.board-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 300px;
  gap: 14px;
  align-items: start;
}

/* 泳道区域 */
.lanes-wrap {
  display: flex;
  gap: 12px;
  overflow-x: auto;
  padding-bottom: 10px;
  align-items: flex-start;
}
.board-lane {
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 10px;
  width: 250px;
  flex: 0 0 250px;
  min-height: 240px;
  box-sizing: border-box;
}
.lane-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 2px 4px 10px;
  border-bottom: 1px solid #e2e8f0;
  margin-bottom: 10px;
}
.lane-title {
  font-size: 14px;
  font-weight: 700;
  color: #1e293b;
}
.lane-count-tag {
  font-size: 11px;
  color: #64748b;
  background: #ffffff;
  padding: 2px 6px;
  border-radius: 4px;
  border: 1px solid #e2e8f0;
}
.count-success {
  color: #008b4b;
  border-color: #bbf7d0;
  background: #f0fdf4;
}
.count-primary {
  color: #3b82f6;
  border-color: #bfdbfe;
  background: #eff6ff;
}

/* 看板卡片 */
.lane-content {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.board-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 10px 12px;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
  transition: all 0.2s;
}
.board-card:hover {
  border-color: #cbd5e1;
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.06);
}
.board-card.is-rework {
  border-left: 3px solid #ef4444;
}
.board-card.is-paused {
  opacity: 0.7;
}

.card-head-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 6px;
}
.mono-code {
  font-family: "DejaVu Sans Mono", Menlo, Consolas, monospace;
  font-size: 11px;
  color: #64748b;
}
.card-badges {
  display: flex;
  gap: 4px;
}
.chip {
  font-size: 10px;
  padding: 1px 6px;
  border-radius: 4px;
  font-weight: 600;
}
.chip-paused { background: #f1f5f9; color: #64748b; }
.chip-urgent { background: #fee2e2; color: #b91c1c; }
.chip-custom { background: #f3e8ff; color: #7e22ce; }
.chip-receipt-ready { background: #f0fdf4; color: #15803d; }
.chip-stored { background: #eff6ff; color: #1d4ed8; }

.card-prod-title {
  font-weight: 600;
  font-size: 13px;
  color: #0f172a;
  margin: 6px 0 4px;
  line-height: 1.4;
}
.card-qty {
  color: #008b4b;
  margin-left: 2px;
}
.card-op-sub {
  font-size: 11px;
  color: #64748b;
  margin-bottom: 4px;
}
.sn-list {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin: 6px 0;
}
.sn-chip {
  font-family: "DejaVu Sans Mono", Menlo, Consolas, monospace;
  font-size: 11px;
  padding: 1px 5px;
  border-radius: 3px;
  background: #f1f5f9;
  color: #475569;
}
.sn-chip.sn-waiting { background: #f1f5f9; color: #64748b; }
.sn-chip.sn-doing   { background: #fef3c7; color: #92400e; }
.sn-chip.sn-rework  { background: #fee2e2; color: #b91c1c; font-weight: 600; }
.sn-chip.sn-done    { background: #dcfce7; color: #15803d; }
.sn-flag {
  font-style: normal;
  font-size: 10px;
  margin-left: 2px;
}

.card-footer-row {
  display: flex;
  justify-content: space-between;
  align-items: flex-end;
  gap: 6px;
  font-size: 11px;
  margin-top: 6px;
  padding-top: 6px;
  border-top: 1px dashed #f1f5f9;
}
.card-actions {
  display: flex;
  gap: 8px;
  flex-shrink: 0;
}
.action-link-theme {
  color: #008b4b;
  font-weight: 500;
  cursor: pointer;
  text-decoration: none;
}
.action-link-theme:hover {
  color: #00763f;
  text-decoration: underline;
}
.btn-receipt-action {
  background: #008b4b !important;
  border-color: #008b4b !important;
  padding: 3px 8px;
  font-size: 11px;
}
.btn-receipt-action:hover {
  background: #00763f !important;
}
.empty-lane {
  color: #94a3b8;
  font-size: 12px;
  text-align: center;
  padding: 30px 10px;
}

/* 侧边面板 */
.side-panel {
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.side-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 14px 16px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
}
.side-card-title {
  margin-bottom: 10px;
}
.section-title {
  margin: 0;
  font-size: 14px;
  font-weight: 700;
  color: #1e293b;
  display: flex;
  align-items: center;
  gap: 8px;
  padding-left: 8px;
  border-left: 3px solid #008b4b;
}
.section-title small {
  font-size: 12px;
  font-weight: 400;
}

/* 报废与未接任务列表 */
.scrap-item {
  padding: 8px 0;
  border-bottom: 1px solid #f1f5f9;
  font-size: 12px;
  line-height: 1.6;
}
.scrap-head {
  display: flex;
  justify-content: space-between;
}
.scrap-reason {
  color: #475569;
  margin-top: 2px;
}
.scrap-photos {
  display: flex;
  gap: 6px;
  margin-top: 4px;
}
.scrap-photos .el-image {
  width: 50px;
  height: 50px;
  border-radius: 4px;
}
.scrap-actions {
  display: flex;
  gap: 8px;
  margin-top: 6px;
}

.unclaimed-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
  padding: 8px 0;
  border-bottom: 1px solid #f1f5f9;
  font-size: 12px;
}
.unclaimed-info b {
  color: #0f172a;
}
.tip-footnote {
  font-size: 11px;
  color: #64748b;
  margin-top: 8px;
}
.empty-tip {
  color: #94a3b8;
  font-size: 12px;
  text-align: center;
  padding: 14px 0;
}

/* 报工动态 */
.report-item {
  display: grid;
  grid-template-columns: 42px 1fr;
  gap: 8px;
  padding: 7px 0;
  border-bottom: 1px solid #f1f5f9;
  font-size: 12px;
  line-height: 1.5;
}
.report-time {
  color: #94a3b8;
  font-family: "DejaVu Sans Mono", Menlo, Consolas, monospace;
}
.report-content {
  color: #334155;
}

/* 底部说明条 */
.board-footnote-alert {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 14px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 6px;
  color: #00763f;
  font-size: 12px;
  margin-top: 14px;
}
.board-footnote-alert i {
  font-size: 16px;
  color: #008b4b;
}

/* 弹窗样式 */
.modal-notice {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 8px 12px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 6px;
  color: #00763f;
  font-size: 12px;
  margin-bottom: 10px;
}
.btn-theme-create {
  background: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
  font-weight: 500;
}
.btn-theme-create:hover {
  background: #00763f !important;
  border-color: #00763f !important;
}
.receipt-product-summary {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 12px;
  background: #f8fafc;
  border-radius: 6px;
  margin-bottom: 12px;
  font-size: 13px;
}
.sn-checkbox-group {
  display: flex;
  flex-direction: column;
  gap: 4px;
  max-height: 120px;
  overflow-y: auto;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  padding: 8px 12px;
}
.receipt-selector-btn {
  width: 100%;
  text-align: left;
}
.receipt-selector-btn ::v-deep > span {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

@media (max-width: 1200px) {
  .metric-strip { grid-template-columns: repeat(3, 1fr); }
  .board-grid { grid-template-columns: 1fr; }
}
@media (max-width: 767px) {
  .metric-strip { grid-template-columns: repeat(2, 1fr); }
  .search-input { width: 100%; }
  .owner-select { width: 100%; }
}
</style>
