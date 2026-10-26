<template>
  <el-dialog :title="workOrderNo ? `缺料转采购 · ${workOrderNo}` : '生成采购需求'" :visible="visible" :width="dialogWidth" custom-class="master-style-dialog" :close-on-click-modal="false" append-to-body @update:visible="v => $emit('update:visible', v)" @open="init" @closed="loadRevision++">
    <div v-loading="loading">
      <p class="sub">按缺口生成一张采购需求（草稿），每种物料一行；数量可改，期望到货日取相关工单最早计划开工日。已在途的采购已经扣掉。</p>
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
          <el-input-number v-model="l.qty" size="small" :min="0.0001" :precision="4" :step="1" controls-position="right" />
          <span class="sub">{{ l.unit_name || '' }}</span>
          <el-button v-if="lines.length > 1" type="text" size="mini" @click="lines.splice(i, 1)">移除</el-button>
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
    toLine(r) { return { ...r, qty: Number(r.shortage_qty) } },
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
          lines: this.lines.map(l => ({ item_id: l.item_id, qty: Number(l.qty) })),
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
.line-qty { display: flex; align-items: center; gap: 6px; }
.remark { margin-top: 12px; }
</style>
