<template>
  <el-dialog :title="workOrderNo ? `缺料转采购 · ${workOrderNo}` : '生成采购需求'" :visible="visible" :width="dialogWidth" custom-class="master-style-dialog" :close-on-click-modal="false" append-to-body @update:visible="v => $emit('update:visible', v)" @open="init" @closed="loadRevision++">
    <div v-loading="loading">
      <p class="sub">按缺口生成一张采购需求（草稿），每种物料一行；可选采购单位（如按卷买电线，按缺口向上取整），数量可改，期望到货日取相关工单最早计划开工日。已在途的采购已经扣掉。</p>
      <div v-if="!lines.length && !loading" class="empty">没有需要采购的缺料</div>
      <div v-for="(l, i) in lines" :key="l.item_id" class="line">
        <div class="line-main">
          <div class="name">{{ l.item_name }} <span class="sub mono">{{ l.item_code }}</span><span v-if="l.spec" class="sub"> · {{ l.spec }}</span></div>
          <div class="sub">
            缺 <b class="danger">{{ qty(l.shortage_qty) }}</b> {{ l.unit_name || '' }} · 库存可用 {{ qty(l.available_qty) }} · 在途 {{ qty(l.in_transit_qty) }}
            <span v-if="l.earliest_date"> · 最早 {{ l.earliest_date }} 开工</span>
          </div>
          <div class="sub">{{ l.work_orders.filter(w => w.shortage_qty > 0).map(w => `${w.work_order_no} 缺 ${qty(w.shortage_qty)}`).join('、') }}</div>
        </div>
        <div class="line-qty">
          <div class="qty-row">
            <el-input-number v-model="l.qty" size="small" :min="0.0001" :precision="unitOf(l).decimal_places" :step="1" controls-position="right" />
            <el-select v-if="l.purchase_units && l.purchase_units.length > 1" v-model="l.unit_id" size="small" class="unit-select" @change="onUnit(l)">
              <el-option v-for="u in l.purchase_units" :key="u.unit_id" :value="u.unit_id" :label="unitLabel(u, l)" />
            </el-select>
            <span v-else class="sub">{{ l.unit_name || '' }}</span>
            <el-button v-if="lines.length > 1" type="text" size="mini" @click="lines.splice(i, 1)">移除</el-button>
          </div>
          <div v-if="!unitOf(l).is_base" :class="['sub', baseOf(l) + 0.00001 < l.shortage_qty ? 'danger' : '']">
            = {{ qty(baseOf(l)) }} {{ l.unit_name }}<template v-if="baseOf(l) > l.shortage_qty + 0.00001">，多出 {{ qty(baseOf(l) - l.shortage_qty) }} {{ l.unit_name }} 到货入库</template><template v-else-if="baseOf(l) + 0.00001 < l.shortage_qty">，少于缺口</template>
          </div>
          <div v-else-if="l.purchase_units && l.purchase_units.length <= 1" class="sub warn">未维护采购换算，将按{{ l.unit_name || '库存单位' }}采购；整卷/整箱采购请先到物料档案维护“采购换算”</div>
        </div>
      </div>
      <el-button v-if="workOrderId && page * 10 < total" size="small" :loading="loading" :disabled="saving" @click="loadMore">继续加载缺料（已加载 {{ Math.min(page * 10, total) }} / {{ total }} 项）</el-button>
      <el-input v-model="remark" class="remark" size="small" maxlength="500" placeholder="备注（选填，不填自动写涉及的工单）" />
    </div>
    <span slot="footer">
      <el-button size="small" @click="$emit('update:visible', false)">取消</el-button>
      <el-button size="small" type="primary" :loading="saving" :disabled="loading || !lines.length" @click="submit">生成采购需求</el-button>
    </span>
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
  data: () => ({ loading: false, saving: false, lines: [], remark: '', page: 0, total: 0, loadRevision: 0 }),
  computed: {
    dialogWidth() { return window.innerWidth < 768 ? '94%' : '760px' }
  },
  watch: { visible(value) { if (!value) this.loadRevision++ }, workOrderId() { if (this.visible) this.init() } },
  methods: {
    qty,
    toLine(r) {
      const units = r.purchase_units || []
      const unit = units[0] || { unit_id: null, factor: 1, decimal_places: 4, is_base: true }
      return { ...r, unit_id: unit.unit_id, qty: this.suggest(Number(r.shortage_qty), unit) }
    },
    unitOf(l) {
      return (l.purchase_units || []).find(u => u.unit_id === l.unit_id) || { unit_id: null, unit_name: l.unit_name, factor: 1, decimal_places: 4, is_base: true }
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
      if (!this.workOrderId) { this.lines = this.rows.map(this.toLine); return }
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
      } finally { if (revision === this.loadRevision) this.loading = false }
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
            return unit.is_base ? { item_id: l.item_id, qty: Number(l.qty), purchase_unit_id: l.unit_id }
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
.sub { color: #8a95a8; font-size: 12px; }
.mono { font-family: ui-monospace, Menlo, monospace; }
.danger { color: #d92d20; }
.empty { color: #8a95a8; text-align: center; padding: 24px 0; }
.line { display: flex; justify-content: space-between; gap: 12px; padding: 10px 0; border-bottom: 1px solid #eef1f5; flex-wrap: wrap; }
.line-main { flex: 1; min-width: 240px; display: grid; gap: 4px; }
.line-main .name { font-weight: 600; }
.line-qty { display: grid; gap: 4px; justify-items: end; }
.qty-row { display: flex; align-items: center; gap: 6px; }
.unit-select { width: 230px; }
.warn { color: #d97706; max-width: 320px; text-align: right; }
.remark { margin-top: 12px; }
</style>
