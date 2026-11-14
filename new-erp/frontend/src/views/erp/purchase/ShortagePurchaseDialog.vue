<template>
  <el-dialog
    :visible="visible"
    :width="dialogWidth"
    top="5vh"
    custom-class="master-style-dialog shortage-purchase-dialog"
    :close-on-click-modal="false"
    append-to-body
    @update:visible="v => $emit('update:visible', v)"
    @open="init"
    @closed="loadRevision++"
  >
    <div slot="title" class="dialog-header-custom">
      <div class="dialog-title-left">
        <span class="dialog-icon-box"><i class="el-icon-shopping-cart-2" /></span>
        <span class="dialog-main-title">{{ workOrderNo ? `缺料转采购 · ${workOrderNo}` : '生成采购需求' }}</span>
        <el-tag size="mini" type="success">采购需求草稿</el-tag>
        <span v-if="lines.length" class="dialog-count-pill">已选 {{ lines.length }} 种物料</span>
      </div>
    </div>

    <div v-loading="loading" class="dialog-body-wrap">
      <div class="erp-page-tip dialog-tip">
        <i class="el-icon-info" />
        <span>
          按净缺口合并生成一张采购需求（草稿），每种物料一行；可选采购单位（如按卷采购自动向上取整），数量可微调，期望到货日默认取关联工单最早计划开工日（已在途采购量已扣减）。
        </span>
      </div>

      <div v-if="!lines.length && !loading" class="empty-state">
        <i class="el-icon-box" />
        <span>没有需要采购的缺料物料</span>
      </div>

      <div v-else class="line-card-list">
        <div v-for="(l, i) in lines" :key="l.item_id" class="shortage-line-card">
          <div class="line-left">
            <div class="line-head">
              <span class="line-index">{{ i + 1 }}</span>
              <span class="code-badge mono">{{ l.item_code }}</span>
              <span class="line-item-name">{{ l.item_name }}</span>
              <span v-if="l.spec" class="line-spec">{{ l.spec }}</span>
            </div>

            <div class="line-metrics">
              <span class="metric-pill is-short">
                净缺口 <b>{{ qty(l.shortage_qty) }} {{ l.unit_name || '' }}</b>
              </span>
              <span class="metric-pill">
                库存可用 <b>{{ qty(l.available_qty) }}</b>
              </span>
              <span class="metric-pill">
                采购在途 <b>{{ qty(l.in_transit_qty) }}</b>
              </span>
              <span v-if="l.earliest_date" class="metric-pill is-date">
                <i class="el-icon-date" /> 最早 {{ l.earliest_date }} 开工
              </span>
            </div>

            <div v-if="shortWorkOrders(l).length" class="line-wos">
              <span class="wo-caption">涉及工单：</span>
              <span v-for="w in shortWorkOrders(l)" :key="w.id" class="wo-mini-chip">
                <span class="mono">{{ w.work_order_no }}</span>
                <small>缺 {{ qty(w.shortage_qty) }}</small>
              </span>
            </div>

            <div v-if="l.requests && l.requests.length" class="line-req-warn">
              <i class="el-icon-warning-outline" />
              <span>
                已有进行中采购需求：{{ l.requests.map(r => `${r.request_no}（${r.request_status_label}，${qty(r.qty)}）`).join('、') }}
              </span>
            </div>
          </div>

          <div class="line-right">
            <div class="qty-header">
              <span class="qty-label">本次采购数量 & 单位</span>
              <el-button
                v-if="lines.length > 1"
                type="text"
                size="mini"
                class="remove-btn"
                icon="el-icon-delete"
                @click="lines.splice(i, 1)"
              >
                移除
              </el-button>
            </div>

            <div class="qty-controls">
              <el-input-number
                v-model="l.qty"
                size="small"
                :min="0.0001"
                :precision="unitOf(l).decimal_places"
                :step="1"
                controls-position="right"
                class="qty-input"
              />
              <el-select
                v-if="l.purchase_units && l.purchase_units.length > 1"
                v-model="l.unit_id"
                size="small"
                class="unit-select"
                @change="onUnit(l)"
              >
                <el-option v-for="u in l.purchase_units" :key="u.unit_id" :value="u.unit_id" :label="unitLabel(u, l)" />
              </el-select>
              <span v-else class="unit-static">{{ l.unit_name || '基本单位' }}</span>
            </div>

            <div v-if="!unitOf(l).is_base" :class="['conv-hint', baseOf(l) + 0.00001 < l.shortage_qty ? 'is-danger' : 'is-ok']">
              <span>= <b>{{ qty(baseOf(l)) }}</b> {{ l.unit_name }}</span>
              <span v-if="baseOf(l) > l.shortage_qty + 0.00001" class="surplus-badge">
                余量 +{{ qty(baseOf(l) - l.shortage_qty) }} {{ l.unit_name }} 到货入库
              </span>
              <span v-else-if="baseOf(l) + 0.00001 < l.shortage_qty" class="short-badge">
                少于净缺口，请核对数量
              </span>
            </div>
            <div v-else-if="l.purchase_units && l.purchase_units.length <= 1" class="conv-warn">
              <i class="el-icon-info" />
              未维护采购换算，将按{{ l.unit_name || '库存单位' }}申请；整卷/整箱采购请先在物料档案维护“采购换算”
            </div>
          </div>
        </div>
      </div>

      <div v-if="workOrderId && page * 10 < total" class="load-more-wrap">
        <el-button size="small" plain icon="el-icon-refresh-right" :loading="loading" :disabled="saving" @click="loadMore">
          继续加载缺料（已加载 {{ Math.min(page * 10, total) }} / {{ total }} 项）
        </el-button>
      </div>

      <div class="remark-box">
        <div class="remark-label">申请备注（选填）</div>
        <el-input
          v-model="remark"
          type="textarea"
          :rows="2"
          size="small"
          maxlength="500"
          show-word-limit
          placeholder="选填；不填将自动记录涉及的工单号"
        />
      </div>
    </div>

    <div slot="footer" class="dialog-footer-custom">
      <el-button size="small" @click="$emit('update:visible', false)">取消</el-button>
      <el-button
        size="small"
        type="success"
        icon="el-icon-check"
        :loading="saving"
        :disabled="loading || !lines.length"
        @click="submit"
      >
        生成采购需求{{ lines.length ? `（${lines.length} 种）` : '' }}
      </el-button>
    </div>
  </el-dialog>
</template>

<script>
import { listProductionShortages, createShortagePurchaseRequest } from '../../../api/erp/purchase'
import { qty, errorText } from '../production/mfg-helpers'

export default {
  name: 'ShortagePurchaseDialog',
  props: {
    visible: { type: Boolean, default: false },
    rows: { type: Array, default: () => [] },
    workOrderId: { type: [Number, String], default: null },
    workOrderNo: { type: String, default: '' }
  },
  data: () => ({
    loading: false,
    saving: false,
    lines: [],
    remark: '',
    page: 0,
    total: 0,
    loadRevision: 0
  }),
  computed: {
    dialogWidth() {
      return window.innerWidth < 768 ? '95%' : '840px'
    }
  },
  watch: {
    visible(value) {
      if (!value) this.loadRevision++
    },
    workOrderId() {
      if (this.visible) this.init()
    }
  },
  methods: {
    qty,
    shortWorkOrders(line) {
      return (line.work_orders || []).filter(w => w.shortage_qty > 0)
    },
    toLine(r) {
      const units = r.purchase_units || []
      const unit = units[0] || { unit_id: null, factor: 1, decimal_places: 4, is_base: true }
      return { ...r, unit_id: unit.unit_id, qty: this.suggest(Number(r.shortage_qty), unit) }
    },
    unitOf(l) {
      return (l.purchase_units || []).find(u => u.unit_id === l.unit_id) || {
        unit_id: null,
        unit_name: l.unit_name,
        factor: 1,
        decimal_places: 4,
        is_base: true
      }
    },
    // 按缺口向上取整到采购单位的精度：缺 3 米、1 卷 = 300 米 → 1 卷。
    suggest(base, unit) {
      const places = Math.min(4, Number(unit.decimal_places || 0))
      const scale = Math.pow(10, places)
      return Math.max(1 / scale, Math.ceil(base / Number(unit.factor || 1) * scale - 1e-9) / scale)
    },
    baseOf(l) {
      return Math.round(Number(l.qty || 0) * Number(this.unitOf(l).factor || 1) * 10000) / 10000
    },
    unitLabel(u, l) {
      if (u.is_base) return `${u.unit_name}（库存单位）`
      return `${u.unit_name}（采购单位，1${u.unit_name}=${qty(u.factor)}${l.unit_name || ''}）`
    },
    onUnit(l) {
      l.qty = this.suggest(Number(l.shortage_qty), this.unitOf(l))
    },
    async init() {
      this.loadRevision++
      this.remark = ''
      this.loading = false
      this.page = 0
      this.total = 0
      this.lines = []
      if (!this.workOrderId) {
        this.lines = this.rows.map(this.toLine)
        return
      }
      await this.loadMore()
    },
    async loadMore() {
      const revision = this.loadRevision
      const workOrderId = this.workOrderId
      const page = this.page + 1
      this.loading = true
      try {
        const { data } = await listProductionShortages({ work_order_id: workOrderId, page, per_page: 10 })
        if (revision !== this.loadRevision || workOrderId !== this.workOrderId || !this.visible) return
        this.lines.push(...(data.data || []).filter(row => !this.lines.some(line => Number(line.item_id) === Number(row.item_id))).map(this.toLine))
        this.page = Number(data.current_page || page)
        this.total = Number(data.total || 0)
      } catch (e) {
        if (revision === this.loadRevision) this.$message.error(errorText(e, '缺料加载失败'))
      } finally {
        if (revision === this.loadRevision) this.loading = false
      }
    },
    async submit() {
      if (this.lines.length > 100) return this.$message.warning('每张采购需求最多 100 种物料，请移除多余物料后分批生成')
      if (this.lines.some(l => !(Number(l.qty) > 0))) return this.$message.warning('采购数量要大于 0')
      this.saving = true
      try {
        const { data } = await createShortagePurchaseRequest({
          lines: this.lines.map(l => {
            const unit = this.unitOf(l)
            if (!l.unit_id) return { item_id: l.item_id, qty: Number(l.qty) }
            return unit.is_base
              ? { item_id: l.item_id, qty: Number(l.qty), purchase_unit_id: l.unit_id }
              : { item_id: l.item_id, purchase_unit_id: l.unit_id, purchase_qty: Number(l.qty) }
          }),
          work_order_id: this.workOrderId ? Number(this.workOrderId) : undefined,
          remark: this.remark || undefined
        })
        this.$message.success(data.message || '已生成采购需求')
        this.$emit('update:visible', false)
        this.$emit('done', data.data)
      } catch (e) {
        this.$message.error(errorText(e, '生成采购需求失败'))
      } finally {
        this.saving = false
      }
    }
  }
}
</script>

<style scoped>
.mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}

.dialog-header-custom {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}

.dialog-title-left {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}

.dialog-icon-box {
  width: 28px;
  height: 28px;
  border-radius: 7px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  color: #008b4b;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 15px;
}

.dialog-main-title {
  font-size: 16px;
  font-weight: 700;
  color: #0f172a;
}

.dialog-count-pill {
  padding: 2px 8px;
  border-radius: 999px;
  background: #f1f5f9;
  color: #475569;
  font-size: 11px;
  font-weight: 600;
}

.dialog-body-wrap {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.dialog-tip {
  margin-bottom: 0;
}

.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  padding: 36px 0;
  color: #94a3b8;
  font-size: 13px;
}

.empty-state i {
  font-size: 28px;
}

.line-card-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
  max-height: 52vh;
  overflow-y: auto;
  padding-right: 2px;
}

.shortage-line-card {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
  padding: 12px 14px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  flex-wrap: wrap;
}

.line-left {
  flex: 1 1 320px;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 7px;
}

.line-head {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
}

.line-index {
  width: 20px;
  height: 20px;
  border-radius: 999px;
  background: #008b4b;
  color: #ffffff;
  font-size: 11px;
  font-weight: 700;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.code-badge {
  padding: 1px 6px;
  border-radius: 4px;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  color: #334155;
  font-size: 11px;
  font-weight: 600;
}

.line-item-name {
  font-size: 14px;
  font-weight: 600;
  color: #0f172a;
}

.line-spec {
  font-size: 12px;
  color: #64748b;
}

.line-metrics {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
}

.metric-pill {
  padding: 2px 8px;
  border-radius: 4px;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  color: #475569;
  font-size: 11px;
}

.metric-pill b {
  color: #0f172a;
}

.metric-pill.is-short {
  background: #fef2f2;
  border-color: #fecaca;
  color: #b91c1c;
}

.metric-pill.is-short b {
  color: #dc2626;
}

.metric-pill.is-date {
  background: #f0fdf4;
  border-color: #bbf7d0;
  color: #008b4b;
}

.line-wos {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 5px;
  font-size: 11px;
  color: #64748b;
}

.wo-caption {
  color: #64748b;
}

.wo-mini-chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 1px 6px;
  border-radius: 4px;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  color: #334155;
}

.wo-mini-chip small {
  color: #dc2626;
  font-weight: 600;
}

.line-req-warn {
  display: flex;
  align-items: center;
  gap: 5px;
  font-size: 11px;
  color: #b45309;
  background: #fffbeb;
  border: 1px solid #fde68a;
  border-radius: 4px;
  padding: 4px 8px;
}

.line-right {
  flex: 0 0 auto;
  min-width: 300px;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 6px;
}

.qty-header {
  width: 100%;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
}

.qty-label {
  font-size: 11px;
  font-weight: 600;
  color: #475569;
}

.remove-btn {
  color: #ef4444 !important;
  padding: 0;
}

.remove-btn:hover {
  color: #dc2626 !important;
}

.qty-controls {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
  justify-content: flex-end;
}

.qty-input {
  width: 130px;
}

.unit-select {
  width: 210px;
}

.unit-static {
  padding: 4px 10px;
  border-radius: 4px;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  color: #334155;
  font-size: 12px;
  font-weight: 500;
}

.conv-hint {
  display: inline-flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
  font-size: 11px;
}

.conv-hint.is-ok {
  color: #475569;
}

.conv-hint.is-danger {
  color: #dc2626;
}

.surplus-badge {
  padding: 1px 6px;
  border-radius: 4px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  color: #008b4b;
  font-weight: 600;
}

.short-badge {
  padding: 1px 6px;
  border-radius: 4px;
  background: #fef2f2;
  border: 1px solid #fecaca;
  color: #dc2626;
  font-weight: 600;
}

.conv-warn {
  max-width: 340px;
  font-size: 11px;
  color: #b45309;
  text-align: right;
  line-height: 1.4;
}

.load-more-wrap {
  display: flex;
  justify-content: center;
}

.remark-box {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.remark-label {
  font-size: 12px;
  font-weight: 600;
  color: #334155;
}

.dialog-footer-custom {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  gap: 8px;
}

.dialog-footer-custom ::v-deep .el-button--success {
  background-color: #008b4b;
  border-color: #008b4b;
}

.dialog-footer-custom ::v-deep .el-button--success:hover,
.dialog-footer-custom ::v-deep .el-button--success:focus {
  background-color: #00763f;
  border-color: #00763f;
}

.dialog-body-wrap ::v-deep .el-input__inner:focus,
.dialog-body-wrap ::v-deep .el-textarea__inner:focus {
  border-color: #008b4b;
}

.dialog-body-wrap ::v-deep .el-input-number__decrease:hover,
.dialog-body-wrap ::v-deep .el-input-number__increase:hover {
  color: #008b4b;
}

@media (max-width: 767px) {
  .line-right {
    width: 100%;
    min-width: 0;
    align-items: stretch;
  }
  .qty-controls {
    justify-content: flex-start;
  }
  .unit-select {
    width: 100%;
  }
  .conv-warn {
    text-align: left;
    max-width: 100%;
  }
}
</style>
