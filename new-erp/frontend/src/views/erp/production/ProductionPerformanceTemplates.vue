<template>
  <section class="production-page perf-tpl-page">
    <div class="page-heading">
      <div><p class="eyebrow">生产管理 / 生产基础</p><h1>提成模板</h1><p>按工序设一套提成比例：一套设为默认模板，个别工艺路线可指定其他模板；路线上不再单独设比例。已经生成的工单仍按原来的比例计算。</p></div>
      <el-button type="success" icon="el-icon-plus" @click="createNew">新建模板</el-button>
    </div>
    <div class="perf-tpl-layout">
      <aside class="form-card perf-tpl-list" v-loading="listLoading">
        <el-input v-model="keyword" size="small" clearable prefix-icon="el-icon-search" placeholder="搜索模板名称" @change="search" />
        <div v-if="!templates.length" class="perf-tpl-empty">还没有模板，点右上角“新建模板”</div>
        <div v-for="row in templates" :key="row.id" :class="['perf-tpl-card', { active: form && form.id === row.id }]" @click="select(row)">
          <div class="perf-tpl-card-title"><span>{{ row.template_name }}</span><span><el-tag v-if="row.is_default" size="mini" type="warning">默认</el-tag> <el-tag size="mini" :type="row.status === 'enabled' ? 'success' : 'info'">{{ row.status === 'enabled' ? '启用' : '停用' }}</el-tag></span></div>
          <div class="perf-tpl-card-sub">{{ row.lines.length }} 道工序 · 合计 {{ percent(row.total_rate) }}%</div>
          <div class="perf-tpl-card-ops">{{ row.lines.map(l => `${l.operation_name} ${percent(l.performance_rate)}%`).join('、') }}</div>
          <div v-if="row.routing_count" class="perf-tpl-card-sub">指定路线 {{ row.routing_count }} 条</div>
        </div>
        <el-pagination v-if="total > 10" small layout="prev, pager, next" :current-page="page" :page-size="10" :total="total" @current-change="changePage" />
      </aside>
      <main class="form-card perf-tpl-editor" v-loading="saving">
        <div v-if="!form" class="perf-tpl-empty big"><i class="el-icon-coin" /><p>选择左边的模板查看和修改，或者新建一个。</p><p class="hint">例：内贸自制设备 —— 下料 0.5%、焊接 1.5%、打磨 0.5%、总装 2%、试水 0.5%</p></div>
        <template v-else>
          <div class="perf-tpl-form">
            <label><span>模板名称</span><el-input v-model="form.template_name" maxlength="120" placeholder="如：内贸自制设备" /></label>
            <label><span>状态</span><el-switch v-model="form.enabled" active-text="启用" inactive-text="停用" /></label>
            <label class="wide"><span>说明（可留空）</span><el-input v-model="form.remark" maxlength="500" placeholder="适用的产品或订单类型" /></label>
          </div>
          <div class="perf-tpl-lines-head"><h2>各工序提成比例</h2><span>比例按“订单折后含税金额 × 比例”算出这道工序的提成，再按工时分给参与的人</span></div>
          <el-table :data="form.lines" border size="small" class="perf-tpl-table">
            <el-table-column label="工序" min-width="180"><template slot-scope="{ row }"><el-select v-model="row.operation_id" filterable placeholder="选择工序" size="small" class="full"><el-option v-for="op in operations" :key="op.id" :label="op.operation_name" :value="op.id" :disabled="usedOperation(op.id, row)"><span>{{ op.operation_name }}</span><span class="op-no">{{ op.operation_no }}</span></el-option></el-select></template></el-table-column>
            <el-table-column label="比例" width="150"><template slot-scope="{ row }"><el-input v-model="row.percent" size="small" placeholder="如 1.5"><template slot="append">%</template></el-input></template></el-table-column>
            <el-table-column label="1 万元订单约" width="120" align="right"><template slot-scope="{ row }"><span class="money">{{ sample(row.percent) }}</span></template></el-table-column>
            <el-table-column label="" width="60" align="center"><template slot-scope="{ $index }"><el-button type="text" class="danger" icon="el-icon-delete" @click="form.lines.splice($index, 1)" /></template></el-table-column>
          </el-table>
          <div class="perf-tpl-total"><el-button size="small" icon="el-icon-plus" @click="addLine">添加工序</el-button><span>合计 <b>{{ totalPercent }}%</b>，1 万元订单约 <b>{{ sample(totalPercent) }}</b> 元</span></div>
          <div v-if="form.id" class="perf-tpl-lines-head"><h2>使用范围</h2><span>{{ detail.is_default ? '默认模板：未指定模板的工艺路线都按本模板计提成' : '非默认模板：只对下列指定路线生效' }}</span></div>
          <div v-if="form.id" class="perf-tpl-ops">
            <el-tag v-for="r in detail.routings" :key="r.id" size="small" closable @close="unbind(r)">{{ r.routing_no }} V{{ r.version }} {{ r.routing_name }}</el-tag>
            <span v-if="!detail.routings.length" class="perf-tpl-card-sub">没有单独指定的工艺路线</span>
          </div>
          <div class="form-actions">
            <el-button v-if="form.id" class="danger" @click="remove">删除</el-button>
            <el-button v-if="form.id" :disabled="detail.is_default" @click="makeDefault">{{ detail.is_default ? '已是默认模板' : '设为默认模板' }}</el-button>
            <el-button icon="el-icon-share" :disabled="!form.id" @click="openApply">指定工艺路线</el-button>
            <el-button type="success" @click="save">保存</el-button>
          </div>
        </template>
      </main>
    </div>

    <el-dialog :visible.sync="apply.open" :title="apply.step === 1 ? '选择要指定本模板的工艺路线' : '确认比例变化'" width="820px" custom-class="perf-tpl-dialog" append-to-body>
      <template v-if="apply.step === 1">
        <div class="perf-tpl-apply-filter"><el-input v-model="apply.keyword" size="small" clearable placeholder="路线编码 / 名称" @change="loadRoutings(1)" /><el-select v-model="apply.status" size="small" @change="loadRoutings(1)"><el-option label="已生效" value="active" /><el-option label="草稿" value="draft" /><el-option label="全部" value="" /></el-select><span>已选 <b>{{ apply.selected.length }}</b> 条</span></div>
        <el-table ref="routingTable" :data="apply.routings" border size="small" row-key="id" v-loading="apply.loading" @selection-change="rows => syncSelection(rows)">
          <el-table-column type="selection" width="44" reserve-selection :selectable="row => !['retired', 'disabled'].includes(row.status)" />
          <el-table-column prop="routing_no" label="路线编码" min-width="130" />
          <el-table-column prop="routing_name" label="路线名称" min-width="150" />
          <el-table-column label="产出物料" min-width="150"><template slot-scope="{ row }">{{ row.output_item ? row.output_item.item_name : '-' }}</template></el-table-column>
          <el-table-column label="版本" width="60"><template slot-scope="{ row }">V{{ row.version }}</template></el-table-column>
          <el-table-column prop="operations_count" label="工序数" width="70" />
        </el-table>
        <div class="table-footer"><span>共 {{ apply.total }} 条</span><el-pagination small layout="prev, pager, next" :current-page="apply.page" :page-size="10" :total="apply.total" @current-change="loadRoutings" /></div>
      </template>
      <template v-else>
        <p class="perf-tpl-tip">指定后这些路线按本模板计提成，不在本模板里的工序不计提成。路线本身不保存比例；已生成的工单不受影响。</p>
        <div class="perf-tpl-preview" v-loading="apply.loading">
          <div v-for="r in apply.preview" :key="r.routing_id" class="perf-tpl-preview-card">
            <div class="perf-tpl-preview-head"><b>{{ r.routing_no }} V{{ r.version }}</b><span>{{ r.routing_name }}</span><span class="muted">当前：{{ r.current_template ? r.current_template.template_name + (r.current_template.source === 'default' ? '（默认）' : '（指定）') : '未配置模板' }}</span><el-tag v-if="r.blocked_reason" size="mini" type="info">{{ r.blocked_reason }}</el-tag><el-tag v-else-if="r.already_bound" size="mini" type="success">已指定本模板</el-tag><el-tag v-else-if="r.changed_count" size="mini" type="warning">{{ r.changed_count }} 道工序比例变化</el-tag><el-tag v-else size="mini" type="success">比例一致</el-tag></div>
            <div class="perf-tpl-ops">
              <div v-for="op in r.operations" :key="op.routing_operation_id" :class="['perf-tpl-op', { changed: op.changed, unmatched: !op.matched }]">
                <span class="name">{{ op.operation_name }}</span>
                <span v-if="op.changed">{{ op.current_rate === null ? '不计' : percent(op.current_rate) + '%' }} → <b>{{ op.new_rate === null ? '不计' : percent(op.new_rate) + '%' }}</b></span>
                <span v-else-if="op.matched">{{ percent(op.new_rate) }}%</span>
                <span v-else class="muted">不计提成（不在模板中）</span>
              </div>
            </div>
          </div>
        </div>
      </template>
      <span slot="footer">
        <template v-if="apply.step === 1"><el-button size="small" @click="apply.open = false">取消</el-button><el-button size="small" type="success" :disabled="!apply.selected.length" @click="loadPreview">下一步：预览变化</el-button></template>
        <template v-else><el-button size="small" @click="apply.step = 1">上一步</el-button><el-button size="small" type="success" :loading="apply.saving" :disabled="!changedRoutings" @click="confirmApply">确认指定（{{ changedRoutings }} 条路线）</el-button></template>
      </span>
    </el-dialog>
  </section>
</template>
<script>
import { listPerformanceTemplates, getPerformanceTemplate, createPerformanceTemplate, updatePerformanceTemplate, deletePerformanceTemplate, previewPerformanceTemplate, applyPerformanceTemplate, unbindPerformanceTemplate, setDefaultPerformanceTemplate, listPerformanceTemplateOperations, listProductionRoutings } from '../../../api/erp/production'

// 百分比（最多 4 位小数）与比例（6 位小数）用整数换算，避免浮点误差。
function percentToRate (value) {
  const text = String(value ?? '').trim()
  if (!/^\d+(\.\d{1,4})?$/.test(text)) return null
  const [whole, decimals = ''] = text.split('.')
  const scaled = Number(whole) * 10000 + Number(decimals.padEnd(4, '0'))
  if (scaled > 1000000) return null
  return (scaled / 1000000).toFixed(6)
}
function rateToPercent (value) {
  if (value === null || value === undefined || value === '') return ''
  return String(Number((Number(value) * 100).toFixed(4)))
}

export default {
  name: 'ProductionPerformanceTemplates',
  data: () => ({
    listLoading: false, saving: false, templates: [], total: 0, page: 1, keyword: '', operations: [], form: null, detail: { is_default: false, routings: [] },
    apply: { open: false, step: 1, keyword: '', status: 'active', routings: [], total: 0, page: 1, selected: [], preview: [], loading: false, saving: false }
  }),
  computed: {
    totalPercent () { return rateToPercent(this.form ? this.form.lines.reduce((sum, row) => sum + Number(percentToRate(row.percent) || 0), 0).toFixed(6) : 0) || '0' },
    changedRoutings () { return this.apply.preview.filter(r => !r.blocked_reason && !r.already_bound).length }
  },
  created () { this.load(); listPerformanceTemplateOperations().then(r => { this.operations = r.data.data || [] }).catch(() => {}) },
  methods: {
    percent: rateToPercent,
    sample (p) { const rate = percentToRate(p); return rate === null ? '-' : (Number(rate) * 10000).toFixed(2) },
    usedOperation (id, row) { return this.form.lines.some(line => line !== row && Number(line.operation_id) === Number(id)) },
    async load () {
      this.listLoading = true
      try { const r = await listPerformanceTemplates({ keyword: this.keyword, page: this.page, per_page: 10 }); this.templates = r.data.data || []; this.total = r.data.total || 0 } catch (e) { this.$message.error(e.userMessage || '提成模板加载失败') } finally { this.listLoading = false }
    },
    search () { this.page = 1; this.load() },
    changePage (p) { this.page = p; this.load() },
    toForm (t) { return { id: t.id, template_name: t.template_name, remark: t.remark || '', enabled: t.status === 'enabled', business_version: t.business_version, lines: t.lines.map(l => ({ operation_id: l.operation_id, percent: rateToPercent(l.performance_rate) })) } },
    select (row) { this.form = this.toForm(row); this.detail = { is_default: row.is_default, routings: [] }; this.loadDetail(row.id) },
    async loadDetail (id) {
      try { const r = await getPerformanceTemplate(id); if (this.form && this.form.id === id) this.detail = { is_default: r.data.data.is_default, routings: r.data.data.routings || [] } } catch (e) { this.$message.error(e.userMessage || '使用范围加载失败') }
    },
    async makeDefault () {
      const saved = this.templates.find(t => t.id === this.form.id)
      if (!this.form.enabled || (saved && saved.status !== 'enabled')) return this.$message.warning('模板已停用，请先启用并保存')
      try { await this.$confirm('设为默认后，所有未指定模板的工艺路线都按本模板计提成（已生成的工单不受影响）。确认？', '设为默认模板', { type: 'warning' }) } catch (e) { return }
      try { const r = await setDefaultPerformanceTemplate(this.form.id); this.$message.success(r.data.message || '已设为默认'); this.loadDetail(this.form.id); this.load() } catch (e) { this.$message.error(e.userMessage || '设置失败') }
    },
    async unbind (routing) {
      try { await this.$confirm(`取消后“${routing.routing_no} V${routing.version}”改按默认模板计提成。确认？`, '取消指定', { type: 'warning' }) } catch (e) { return }
      try { const r = await unbindPerformanceTemplate(this.form.id, { routing_ids: [routing.id] }); this.$message.success(r.data.message || '已取消'); this.loadDetail(this.form.id); this.load() } catch (e) { this.$message.error(e.userMessage || '取消失败') }
    },
    createNew () { this.detail = { is_default: false, routings: [] }; this.form = { id: null, template_name: '', remark: '', enabled: true, business_version: 0, lines: [{ operation_id: null, percent: '' }] } },
    addLine () { this.form.lines.push({ operation_id: null, percent: '' }) },
    payload () {
      const f = this.form
      if (!f.template_name.trim()) throw new Error('请填写模板名称')
      const lines = f.lines.filter(l => l.operation_id || String(l.percent).trim())
      if (!lines.length) throw new Error('请至少设置一道工序的比例')
      return { template_name: f.template_name.trim(), remark: f.remark, status: f.enabled ? 'enabled' : 'disabled', expected_version: f.business_version || undefined,
        lines: lines.map(l => { if (!l.operation_id) throw new Error('有比例没有选择工序'); const rate = percentToRate(l.percent); if (rate === null) throw new Error('比例请填写 0 到 100 之间的数字，最多 4 位小数'); return { operation_id: l.operation_id, performance_rate: rate } }) }
    },
    async save () {
      let data; try { data = this.payload() } catch (e) { return this.$message.warning(e.message) }
      this.saving = true
      try {
        const r = this.form.id ? await updatePerformanceTemplate(this.form.id, data) : await createPerformanceTemplate(data)
        this.$message.success(r.data.message || '已保存'); this.form = this.toForm(r.data.data); this.detail = { is_default: r.data.data.is_default, routings: r.data.data.routings || [] }; this.load()
      } catch (e) { this.$message.error(e.userMessage || '保存失败') } finally { this.saving = false }
    },
    async remove () {
      try { await this.$confirm('正在作为默认模板或被路线指定的模板不能删除。确认删除？', '删除提成模板', { type: 'warning' }) } catch (e) { return }
      try { await deletePerformanceTemplate(this.form.id); this.$message.success('已删除'); this.form = null; this.load() } catch (e) { this.$message.error(e.userMessage || '删除失败') }
    },
    async openApply () {
      const saved = this.templates.find(t => t.id === this.form.id)
      if (saved && JSON.stringify(this.toForm(saved)) !== JSON.stringify(this.form)) return this.$message.warning('模板有未保存的修改，请先保存再指定')
      if (!this.form.enabled) return this.$message.warning('模板已停用，请先启用并保存')
      Object.assign(this.apply, { open: true, step: 1, selected: [], preview: [], keyword: '', status: 'active' })
      this.$nextTick(() => { if (this.$refs.routingTable) this.$refs.routingTable.clearSelection() })
      this.loadRoutings(1)
    },
    async loadRoutings (p) {
      this.apply.page = p || this.apply.page; this.apply.loading = true
      try { const r = await listProductionRoutings({ keyword: this.apply.keyword, status: this.apply.status, page: this.apply.page, per_page: 10 }); this.apply.routings = r.data.data || []; this.apply.total = r.data.total || 0 } catch (e) { this.$message.error(e.userMessage || '工艺路线加载失败') } finally { this.apply.loading = false }
    },
    syncSelection (rows) { this.apply.selected = rows.map(r => r.id) },
    async loadPreview () {
      if (this.apply.selected.length > 50) return this.$message.warning('一次最多指定 50 条路线')
      this.apply.step = 2; this.apply.loading = true
      try { const r = await previewPerformanceTemplate(this.form.id, { routing_ids: this.apply.selected }); this.apply.preview = r.data.data || [] } catch (e) { this.$message.error(e.userMessage || '预览失败'); this.apply.step = 1 } finally { this.apply.loading = false }
    },
    async confirmApply () {
      this.apply.saving = true
      try { const r = await applyPerformanceTemplate(this.form.id, { routing_ids: this.apply.selected }); this.$message.success(r.data.message || '已指定'); this.apply.open = false; this.load(); this.loadDetail(this.form.id) } catch (e) { this.$message.error(e.userMessage || '指定失败') } finally { this.apply.saving = false }
    }
  }
}
</script>
<style scoped src="./production-master.css"></style>
<style scoped>
.perf-tpl-layout { display: grid; grid-template-columns: 320px minmax(0, 1fr); gap: 16px; align-items: start; }
.perf-tpl-layout > * { min-width: 0; box-sizing: border-box; }
.perf-tpl-list { padding: 14px; display: flex; flex-direction: column; gap: 10px; }
.perf-tpl-card { border: 1px solid #e5eaf0; border-radius: 5px; padding: 10px 12px; cursor: pointer; transition: border-color .15s; }
.perf-tpl-card:hover { border-color: #8fd3b0; }
.perf-tpl-card.active { border-color: #008b4b; background: #f0faf5; }
.perf-tpl-card-title { display: flex; justify-content: space-between; align-items: center; font-weight: 600; color: #152941; gap: 8px; }
.perf-tpl-card-sub { font-size: 12px; color: #77869a; margin-top: 4px; }
.perf-tpl-card-ops { font-size: 12px; color: #526276; margin-top: 4px; line-height: 18px; word-break: break-all; }
.perf-tpl-empty { color: #909399; font-size: 13px; text-align: center; padding: 20px 0; }
.perf-tpl-empty.big { padding: 70px 10px; }
.perf-tpl-empty.big i { font-size: 40px; color: #c0c8d2; }
.perf-tpl-empty .hint { font-size: 12px; color: #a0aab6; }
.perf-tpl-editor { min-height: 420px; }
.perf-tpl-form { display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); gap: 14px 24px; }
.perf-tpl-form label > span { display: block; color: #526276; font-size: 12px; font-weight: 600; margin-bottom: 7px; }
.perf-tpl-form .wide { grid-column: 1 / -1; }
.perf-tpl-lines-head { display: flex; align-items: baseline; gap: 12px; margin: 20px 0 10px; flex-wrap: wrap; }
.perf-tpl-lines-head h2 { margin: 0; font-size: 15px; color: #152941; }
.perf-tpl-lines-head span { font-size: 12px; color: #77869a; }
.perf-tpl-table .full { width: 100%; }
.op-no { float: right; color: #a0aab6; font-size: 12px; margin-left: 10px; }
.money { color: #008b4b; }
.danger { color: #d9534f; }
.perf-tpl-total { display: flex; justify-content: space-between; align-items: center; margin-top: 10px; color: #526276; flex-wrap: wrap; gap: 8px; }
.perf-tpl-total b { color: #152941; }
.perf-tpl-apply-filter { display: flex; gap: 10px; align-items: center; margin-bottom: 10px; flex-wrap: wrap; }
.perf-tpl-apply-filter .el-input { width: 220px; }
.perf-tpl-apply-filter .el-select { width: 110px; }
.perf-tpl-tip { margin: 0 0 10px; color: #77869a; font-size: 13px; }
.perf-tpl-preview { max-height: 56vh; overflow: auto; display: flex; flex-direction: column; gap: 10px; }
.perf-tpl-preview-card { border: 1px solid #e5eaf0; border-radius: 5px; padding: 10px 12px; }
.perf-tpl-preview-head { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 8px; }
.perf-tpl-preview-head span { color: #526276; }
.perf-tpl-ops { display: flex; flex-wrap: wrap; gap: 6px; }
.perf-tpl-op { border-radius: 4px; background: #f5f7fa; padding: 4px 8px; font-size: 12px; display: flex; gap: 6px; }
.perf-tpl-op .name { color: #152941; font-weight: 600; }
.perf-tpl-op.changed { background: #fdf6ec; }
.perf-tpl-op.changed b { color: #e6a23c; }
.perf-tpl-op.unmatched { opacity: .7; }
.perf-tpl-op .muted, .perf-tpl-preview-head .muted { color: #909399; font-size: 12px; }
::v-deep .perf-tpl-dialog { max-width: 96vw; }
@media (max-width: 900px) {
  .perf-tpl-layout { grid-template-columns: 1fr; }
  .perf-tpl-form { grid-template-columns: 1fr; }
  .perf-tpl-editor { padding: 14px; }
  .form-actions { flex-wrap: wrap; }
}
</style>
