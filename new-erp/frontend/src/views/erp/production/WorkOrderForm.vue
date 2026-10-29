<template>
  <section class="mfg-page">
    <div class="mfg-hero">
      <div class="mfg-crumb"><a @click="back">生产管理 / 工单</a> / 新建备货工单</div>
      <div class="mfg-hrow">
        <div>
          <div class="mfg-title">新建备货工单</div>
          <div class="mfg-hero-sub">给库存备货用。订单要生产的，确认订单时会自动生成，不用在这里建</div>
        </div>
        <div class="mfg-actions">
          <el-button class="ghost" @click="back">取消</el-button>
          <el-button class="ghost" :loading="saving === 'plan'" :disabled="!!saving" @click="submit(false)">保存为计划</el-button>
          <el-button v-if="$can('production.work_order.publish')" class="primary" :loading="saving === 'release'" :disabled="!!saving" @click="submit(true)">保存并下达</el-button>
        </div>
      </div>
    </div>
    <div class="mfg-body">
      <div class="mfg-grid">
        <div>
          <div class="card">
            <h2>做什么、做多少</h2>
            <div class="f-grid">
              <label class="span2"><span class="req">产品</span>
                <el-select v-model="form.item_id" filterable remote clearable :remote-method="searchItems" :loading="itemLoading" placeholder="输入物料编码或名称搜索" @change="onItem" @focus="items.length || searchItems('')">
                  <el-option v-for="it in items" :key="it.id" :label="`${it.item_name} · ${it.item_code}`" :value="it.id" />
                </el-select>
              </label>
              <label><span class="req">数量</span><el-input-number v-model="form.planned_qty" :min="1" :max="100000" :step="1" controls-position="right" @change="schedulePreview" /></label>
              <label><span>为什么备货</span>
                <el-radio-group v-model="form.stock_reason" size="small">
                  <el-radio-button label="alert">库存预警</el-radio-button><el-radio-button label="regular">常规备货</el-radio-button><el-radio-button label="other">其他</el-radio-button>
                </el-radio-group>
              </label>
              <label><span class="req">计划开工</span><el-date-picker v-model="form.planned_start_date" type="date" value-format="yyyy-MM-dd" :clearable="false" /></label>
              <label><span class="req">要求完工</span><el-date-picker v-model="form.planned_finish_date" type="date" value-format="yyyy-MM-dd" :clearable="false" /></label>
              <label><span>负责人</span>
                <el-select v-model="form.owner_legacy_id" filterable remote clearable :remote-method="searchOwners" placeholder="搜索姓名" @focus="searchOwners('')">
                  <el-option v-for="u in owners" :key="u.id" :label="u.name" :value="u.id" />
                </el-select>
              </label>
              <label><span>入库仓库</span>
                <paged-record-select v-model="form.warehouse_id" entity="warehouses" :params="{ status: 'enabled' }" title="选择入库仓库" placeholder="默认用物料的默认仓库" size="medium" />
              </label>
              <label class="span4"><span>备注</span><el-input v-model="form.remark" maxlength="500" placeholder="例如：双十一前备货" /></label>
              <label class="span4"><span>优先级</span><el-radio-group v-model="form.priority" size="small"><el-radio-button :label="1">特急</el-radio-button><el-radio-button :label="2">加急</el-radio-button><el-radio-button :label="3">普通</el-radio-button><el-radio-button :label="4">可延后</el-radio-button></el-radio-group></label>
            </div>
          </div>

          <div class="card" v-loading="previewLoading">
            <h2>怎么做 <small>按产品自动带出默认版本，可换</small></h2>
            <div v-if="!preview" class="empty">先选产品</div>
            <template v-else>
              <div class="f-grid">
                <label class="span2"><span>BOM</span>
                  <el-select v-model="form.bom_id" clearable placeholder="没有可用 BOM" @change="schedulePreview">
                    <el-option v-for="b in preview.bom_options" :key="b.id" :label="`${bomLabel(b.bom_name, b.bom_no, b.version)}${b.is_default ? '（默认）' : ''}`" :value="b.id" />
                  </el-select>
                </label>
                <label class="span2"><span>工艺路线</span>
                  <el-select v-model="form.routing_id" clearable placeholder="没有生效的工艺路线" @change="schedulePreview">
                    <el-option v-for="r in preview.routing_options" :key="r.id" :label="`${r.routing_name} V${r.version}${r.is_default ? '（默认）' : ''}`" :value="r.id" />
                  </el-select>
                </label>
              </div>
              <div class="ops-line">
                <span class="sub">工序</span>
                <div class="flow">
                  <template v-for="(op, i) in prodOps"><span v-if="i" :key="'a' + i" class="arrow">›</span><span :key="'p' + i" class="chip c-b">{{ op.operation_name }}</span></template>
                  <span v-if="!prodOps.length" class="sub">无车间工序</span>
                  <template v-if="shipOps.length"><span class="sub ship-label">发货时再做：</span><span v-for="(op, i) in shipOps" :key="'s' + i" class="chip c-g">{{ op.operation_name }}{{ op.packaging_scheme_name ? `（${op.packaging_scheme_name}）` : '' }}</span></template>
                </div>
              </div>
              <div v-for="(b, i) in preview.block_reasons" :key="i" class="note warn-note">{{ b }}。可以先保存为计划，补齐后再下达。</div>
            </template>
          </div>

          <div class="card" v-if="preview">
            <h2>料够不够 <small>按 {{ form.planned_qty }} 台、分工序算；不够只提醒，可以照样下达</small></h2>
            <div v-if="!preview.kitting.length" class="empty">没有车间工序或 BOM，算不了齐套</div>
            <div v-else class="kit-grid">
              <div v-for="k in preview.kitting" :key="k.operation_key" :class="['kit', { short: k.status === 'short' }]">
                <h3>{{ k.operation_name }} <span :class="k.status === 'short' ? 'warn' : (k.status === 'ready' ? 'ok' : 'sub')">{{ k.status === 'short' ? `缺 ${k.shortage_count} 项` : (k.status === 'ready' ? '齐套' : '不领料') }}</span></h3>
                <div v-for="m in k.materials" :key="m.component_item_id" class="row">
                  <span>{{ m.component_item_name }}</span>
                  <b v-if="!m.participates_in_kitting" class="sub">辅料</b>
                  <b v-else :class="{ warn: m.shortage_qty > 0 }">{{ qty(m.covered_qty) }} / {{ qty(m.required_qty) }} {{ m.unit_name || '' }}</b>
                </div>
              </div>
            </div>
            <p v-if="shortText" class="warn short-line">{{ shortText }}</p>
          </div>

          <div class="card">
            <h2>序列号 <small>下达时生成，可打印标签贴在设备上</small></h2>
            <span class="sub">保存并下达后按设备生成 {{ form.planned_qty }} 个序列号（前缀按物料设置，没有设置用 SN）。</span>
          </div>
        </div>

        <div>
          <div class="card">
            <h2>预计</h2>
            <div class="kv"><span>标准工时</span><b>{{ preview ? `每台设备 ${Number(((preview.standard_minutes_per_unit || 0) / 60).toFixed(2))} 小时` : '-' }}</b></div>
            <div class="kv" v-if="preview && preview.estimated_material_cost_per_unit !== null && preview.estimated_material_cost_per_unit !== undefined"><span>预计材料成本</span><b>{{ money(preview.estimated_material_cost_per_unit) }} / 台</b></div>
            <div class="kv"><span>当前库存</span><b>{{ preview ? `${qty(preview.item.stock_qty)} ${preview.item.unit_name || ''}` : '-' }}</b></div>
          </div>
          <div class="card">
            <h2>两个按钮的区别</h2>
            <p class="line"><b>保存为计划</b>：先占个位，车间看不到</p>
            <p class="line"><b>保存并下达</b>：生成序列号，车间就能开工</p>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>

<script>
import { searchProductionOptions, previewWorkOrder, createWorkOrder } from '../../../api/erp/production'
import PagedRecordSelect from '@/components/common/PagedRecordSelect.vue'
import { qty, money, bomLabel, errorText, searchProductionUsers } from './mfg-helpers'

const day = offset => { const d = new Date(); d.setDate(d.getDate() + offset); return d.toISOString().slice(0, 10) }

export default {
  name: 'WorkOrderForm',
  components: { PagedRecordSelect },
  data: () => ({
    form: { item_id: null, planned_qty: 1, stock_reason: 'alert', planned_start_date: day(0), planned_finish_date: day(7), owner_legacy_id: null, warehouse_id: null, bom_id: null, routing_id: null, remark: '', priority: 3 },
    items: [], itemLoading: false, owners: [], preview: null, previewLoading: false, saving: '', timer: null, previewSeq: 0
  }),
  computed: {
    prodOps() { return this.preview ? this.preview.operations.filter(o => o.execution_context === 'production') : [] },
    shipOps() { return this.preview ? this.preview.operations.filter(o => o.execution_context !== 'production') : [] },
    shortText() {
      if (!this.preview) return ''
      const list = []
      this.preview.kitting.forEach(k => k.materials.forEach(m => { if (m.shortage_qty > 0) list.push(`${m.component_item_name}缺 ${qty(m.shortage_qty)}${m.unit_name || ''}`) }))
      return list.length ? list.join('、') + '，请采购跟进。' : ''
    }
  },
  beforeDestroy() { clearTimeout(this.timer) },
  methods: {
    qty, money, bomLabel,
    back() { this.$router.push('/production/work-orders') },
    async searchItems(keyword) {
      this.itemLoading = true
      try {
        const { data } = await searchProductionOptions('items', { keyword: keyword || undefined, per_page: 20 })
        this.items = data.data || []
      } catch (e) {
        this.$message.error(errorText(e, '产品加载失败'))
      } finally {
        this.itemLoading = false
      }
    },
    async searchOwners(keyword) { try { this.owners = await searchProductionUsers(keyword) } catch (e) { this.owners = [] } },
    onItem() {
      this.form.bom_id = null
      this.form.routing_id = null
      this.preview = null
      if (this.form.item_id) this.loadPreview(true)
    },
    schedulePreview() {
      clearTimeout(this.timer)
      if (this.form.item_id) this.timer = setTimeout(() => this.loadPreview(false), 300)
    },
    async loadPreview(pickDefaults) {
      const seq = ++this.previewSeq
      this.previewLoading = true
      try {
        const params = { item_id: this.form.item_id, planned_qty: this.form.planned_qty || 1 }
        if (this.form.bom_id) params.bom_id = this.form.bom_id
        if (this.form.routing_id) params.routing_id = this.form.routing_id
        const { data } = await previewWorkOrder(params)
        if (seq !== this.previewSeq) return
        this.preview = data.data
        if (pickDefaults) {
          this.form.bom_id = this.preview.bom ? (this.preview.bom.bom_id || this.preview.bom.id) : null
          this.form.routing_id = this.preview.routing ? this.preview.routing.id : null
          if (!this.form.warehouse_id && this.preview.item.default_warehouse_id) this.form.warehouse_id = this.preview.item.default_warehouse_id
        }
      } catch (e) {
        if (seq === this.previewSeq) this.$message.error(errorText(e, '预览失败'))
      } finally {
        if (seq === this.previewSeq) this.previewLoading = false
      }
    },
    async submit(release) {
      const f = this.form
      if (!f.item_id) return this.$message.warning('请选择产品')
      if (!f.planned_qty || f.planned_qty <= 0) return this.$message.warning('请填写数量')
      if (!f.planned_start_date || !f.planned_finish_date) return this.$message.warning('请填写计划开工和要求完工')
      if (f.planned_finish_date < f.planned_start_date) return this.$message.warning('要求完工不能早于计划开工')
      if (release && this.preview && this.preview.block_reasons.length) {
        try { await this.$confirm(`${this.preview.block_reasons.join('；')}。资料不全的工单会停在计划，确定继续保存？`, '资料不全', { type: 'warning' }) } catch (e) { return }
      }
      const owner = this.owners.find(u => u.id === f.owner_legacy_id)
      const payload = { ...f, owner_name: owner ? owner.name : null, release }
      Object.keys(payload).forEach(k => { if (payload[k] === null || payload[k] === '') delete payload[k] })
      this.saving = release ? 'release' : 'plan'
      try {
        const { data } = await createWorkOrder(payload)
        this.$message.success(data.message || '备货工单已保存')
        this.$router.replace(`/production/work-orders/${data.data.id}`)
      } catch (e) {
        this.$message.error(errorText(e, '保存失败'))
      } finally {
        this.saving = ''
      }
    }
  }
}
</script>

<style scoped src="./mfg-v2.css"></style>
<style scoped>
.f-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px 16px; }
.f-grid label > span { display: block; color: #5b6780; font-size: 12px; margin-bottom: 6px; }
.f-grid .req:before { content: '*'; color: #d92d20; margin-right: 3px; }
.f-grid .span2 { grid-column: span 2; }
.f-grid .span4 { grid-column: 1 / -1; }
.f-grid .el-radio-group { display: flex; width: 100%; }
.f-grid .el-radio-button { flex: 1; }
.f-grid ::v-deep .el-radio-button__inner { width: 100%; padding: 10px 4px; }
.f-grid .el-select, .f-grid .el-date-editor, .f-grid .el-input-number { width: 100%; }
.ops-line { display: flex; gap: 12px; align-items: center; margin-top: 14px; flex-wrap: wrap; }
.ship-label { margin-left: 12px; }
.warn-note { margin-top: 12px; border-color: #ff5a5f; background: #fff6f5; color: #8a1d14; }
.short-line { margin: 10px 0 0; font-size: 12px; }
.line { margin: 6px 0; color: #5b6780; font-size: 12px; }
.line b { color: #172033; }
@media (max-width: 1100px) {
  .f-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 767px) {
  .f-grid { grid-template-columns: 1fr; }
  .f-grid .span2 { grid-column: auto; }
}
</style>
