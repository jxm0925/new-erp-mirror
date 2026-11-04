<template>
  <section class="production-page perf-tpl-page">
    <!-- 顶部统一标头 (.page-head) -->
    <header class="page-head">
      <div class="head-left">
        <span class="head-icon"><i class="el-icon-coin" /></span>
        <div class="head-title-wrap">
          <div class="title-row">
            <h1 class="page-title">工序提成模板</h1>
            <el-tag size="small" type="success" effect="plain" class="head-tag">
              共 {{ total }} 项
            </el-tag>
          </div>
          <div class="sub-row">
            统一配置标准工序销售提成比例与计酬规则，支持默认模板与按商品分类指定；BOM 节点上的指定比例优先，已生成的工单保持原计酬口径
          </div>
        </div>
      </div>
      <div class="head-actions">
        <el-button size="small" icon="el-icon-refresh" class="btn-refresh" :loading="listLoading" @click="load">
          刷新
        </el-button>
        <el-button size="small" type="success" icon="el-icon-plus" class="btn-theme-create" @click="createNew">
          新建模板
        </el-button>
      </div>
    </header>

    <div class="perf-tpl-layout">
      <!-- 左侧模板列表 -->
      <aside class="form-card perf-tpl-list" v-loading="listLoading">
        <div class="list-head">
          <div class="card-title">模板列表</div>
        </div>
        <el-input v-model="keyword" size="small" clearable prefix-icon="el-icon-search" placeholder="搜索模板名称" @change="search" />
        <div v-if="!templates.length" class="perf-tpl-empty">暂无提成模板，点击右上角「新建模板」</div>
        <div class="tpl-cards-scroll">
          <div v-for="row in templates" :key="row.id" :class="['perf-tpl-card', { active: form && form.id === row.id }]" @click="select(row)">
            <div class="perf-tpl-card-title">
              <span class="tpl-name">{{ row.template_name }}</span>
              <span class="tpl-badges">
                <el-tag v-if="row.is_default" size="mini" type="warning" effect="dark">默认</el-tag>
                <el-tag size="mini" :type="row.status === 'enabled' ? 'success' : 'info'">{{ row.status === 'enabled' ? '启用' : '停用' }}</el-tag>
              </span>
            </div>
            <div class="perf-tpl-card-sub">{{ row.lines.length }} 道工序 · 比例合计 <b class="total-rate">{{ percent(row.total_rate) }}%</b></div>
            <div class="perf-tpl-card-ops">{{ row.lines.map(l => `${l.operation_name} ${percent(l.performance_rate)}%`).join('、') }}</div>
            <div v-if="row.category_count" class="perf-tpl-card-scope">适用分类 {{ row.category_count }} 个</div>
          </div>
        </div>
        <div v-if="total > 10" class="list-pagination">
          <el-pagination small layout="prev, pager, next" :current-page="page" :page-size="10" :total="total" @current-change="changePage" />
        </div>
      </aside>

      <!-- 右侧模板配置器 -->
      <main class="form-card perf-tpl-editor" v-loading="saving">
        <div v-if="!form" class="perf-tpl-empty big">
          <i class="el-icon-document" />
          <p class="empty-title">请选择左侧模板查看与编辑，或新建提成模板</p>
          <p class="hint">提成比例按“订单折后金额 × 工序比例”计算工序总提成，并根据各工序实际报工工时加权分摊至作业人员。</p>
        </div>

        <template v-else>
          <div class="section-block">
            <div class="card-head">
              <div class="card-title">模板基础设置</div>
            </div>
            <div class="perf-tpl-form">
              <label>
                <span>模板名称</span>
                <el-input v-model="form.template_name" size="small" maxlength="120" placeholder="例如：内贸标准设备提成模板" />
              </label>
              <label>
                <span>启用状态</span>
                <el-switch v-model="form.enabled" active-color="#008b4b" active-text="启用" inactive-text="停用" />
              </label>
              <label class="wide">
                <span>模板说明</span>
                <el-input v-model="form.remark" size="small" maxlength="500" placeholder="说明适用的产品系列、工艺类型或销售业务范围（选填）" />
              </label>
            </div>
          </div>

          <div class="section-block">
            <div class="card-head">
              <div class="card-title">工序提成比例配置</div>
              <span class="sub-explain">计算公式：订单折后金额 × 工序提成比例，按实际有效报工工时分摊</span>
            </div>
            <el-table :data="form.lines" border size="small" class="perf-tpl-table">
              <el-table-column label="工序名称" min-width="180">
                <template slot-scope="{ row }">
                  <el-select v-model="row.operation_id" filterable placeholder="选择工序" size="small" class="full">
                    <el-option v-for="op in operations" :key="op.id" :label="op.operation_name" :value="op.id" :disabled="usedOperation(op.id, row)">
                      <span>{{ op.operation_name }}</span>
                      <span class="op-no">{{ op.operation_no }}</span>
                    </el-option>
                  </el-select>
                </template>
              </el-table-column>
              <el-table-column label="提成比例" width="160">
                <template slot-scope="{ row }">
                  <el-input v-model="row.percent" size="small" placeholder="如 1.5">
                    <template slot="append">%</template>
                  </el-input>
                </template>
              </el-table-column>
              <el-table-column label="每 1 万元订单金额参考" width="170" align="right">
                <template slot-scope="{ row }">
                  <span class="money">约 {{ sample(row.percent) }} 元</span>
                </template>
              </el-table-column>
              <el-table-column label="操作" width="70" align="center">
                <template slot-scope="{ $index }">
                  <el-button type="text" class="danger-btn" icon="el-icon-delete" @click="form.lines.splice($index, 1)" />
                </template>
              </el-table-column>
            </el-table>
            <div class="perf-tpl-total">
              <el-button size="small" type="success" plain class="btn-theme-plain" icon="el-icon-plus" @click="addLine">添加工序</el-button>
              <span class="total-summary">提成比例合计: <b>{{ totalPercent }}%</b>，每 1 万元订单提成额约 <b class="total-money">{{ sample(totalPercent) }}</b> 元</span>
            </div>
          </div>

          <div v-if="form.id" class="section-block">
            <div class="card-head">
              <div class="card-title">适用范围</div>
              <span class="sub-explain">兜底顺序：BOM 本行指定 → 商品分类（本级，再逐级上级）→ 默认模板 → 未配置；已生成的工单不受影响</span>
            </div>
            <div class="scope-row">
              <span class="scope-label">默认模板</span>
              <el-tag v-if="detail.is_default" size="small" type="success" effect="plain">是</el-tag>
              <el-tag v-else size="small" type="info" effect="plain">否</el-tag>
              <span class="empty-hint">所有没有命中分类模板、也没有在 BOM 里指定的工序，按默认模板计提成</span>
            </div>
            <div class="scope-row">
              <span class="scope-label">商品分类</span>
              <el-tag v-for="c in detail.categories" :key="c.id" size="small" type="success" effect="plain" closable class="scope-tag" @close="unbindCategory(c)">
                {{ c.category_name }}
              </el-tag>
              <span v-if="!detail.categories.length" class="empty-hint">暂无绑定的商品分类</span>
              <el-button size="mini" type="success" plain class="btn-theme-plain" icon="el-icon-plus" @click="openCategoryPicker">选择商品分类</el-button>
            </div>
            <div class="scope-tip">商品未填分类时按默认模板计提成。</div>
          </div>

          <div class="form-actions">
            <el-button v-if="form.id" type="danger" plain class="btn-danger-plain" @click="remove">删除模板</el-button>
            <el-button v-if="form.id" icon="el-icon-star-off" :disabled="detail.is_default" @click="makeDefault">{{ detail.is_default ? '已是默认模板' : '设为默认模板' }}</el-button>
            <el-button icon="el-icon-connection" :disabled="!form.id" @click="openCategoryPicker">选择商品分类</el-button>
            <el-button type="success" icon="el-icon-check" class="btn-theme-create" @click="save">保存模板</el-button>
          </div>
        </template>
      </main>
    </div>

    <!-- 选择商品分类弹窗 -->
    <el-dialog :visible.sync="categoryPicker.open" title="选择商品分类" width="560px" custom-class="perf-tpl-dialog" append-to-body>
      <p class="perf-tpl-tip">勾选使用本模板的商品分类；取消已勾选的分类将解除该分类与本模板的绑定。下级分类未单独指定时自动沿用上级的模板。</p>
      <div class="category-tree-box" v-loading="categoryPicker.loading">
        <el-tree
          v-if="categoryPicker.tree.length"
          ref="categoryTree"
          :data="categoryPicker.tree"
          show-checkbox
          check-strictly
          node-key="id"
          :props="{ label: 'category_name', children: 'children' }"
          :default-checked-keys="categoryPicker.checked"
        />
        <div v-else-if="!categoryPicker.loading" class="perf-tpl-empty">暂无商品分类</div>
      </div>
      <span slot="footer">
        <el-button size="small" @click="categoryPicker.open = false">取消</el-button>
        <el-button size="small" type="success" :loading="categoryPicker.saving" @click="confirmCategories">确认指定</el-button>
      </span>
    </el-dialog>
  </section>
</template>

<script>
import { listPerformanceTemplates, getPerformanceTemplate, createPerformanceTemplate, updatePerformanceTemplate, deletePerformanceTemplate, bindPerformanceTemplateCategories, unbindPerformanceTemplateCategories, setDefaultPerformanceTemplate, listPerformanceTemplateOperations } from '../../../api/erp/production'
import { listEntity } from '../../../api/erp/master'

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
    listLoading: false, saving: false, templates: [], total: 0, page: 1, keyword: '', operations: [], form: null, detail: { is_default: false, categories: [] },
    categoryPicker: { open: false, loading: false, saving: false, tree: [], checked: [] }
  }),
  computed: {
    totalPercent () { return rateToPercent(this.form ? this.form.lines.reduce((sum, row) => sum + Number(percentToRate(row.percent) || 0), 0).toFixed(6) : 0) || '0' }
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
    select (row) { this.form = this.toForm(row); this.detail = { is_default: row.is_default, categories: [] }; this.loadDetail(row.id) },
    async loadDetail (id) {
      try { const r = await getPerformanceTemplate(id); if (this.form && this.form.id === id) this.detail = { is_default: r.data.data.is_default, categories: r.data.data.categories || [] } } catch (e) { this.$message.error(e.userMessage || '使用范围加载失败') }
    },
    async makeDefault () {
      const saved = this.templates.find(t => t.id === this.form.id)
      if (!this.form.enabled || (saved && saved.status !== 'enabled')) return this.$message.warning('模板已停用，请先启用并保存')
      try { await this.$confirm('设为默认后，未命中商品分类模板、也未在 BOM 里指定的工序都将按本模板计提成（已生成工单不受影响）。确认？', '设为默认模板', { type: 'warning' }) } catch (e) { return }
      try { const r = await setDefaultPerformanceTemplate(this.form.id); this.$message.success(r.data.message || '已设为默认模板'); this.loadDetail(this.form.id); this.load() } catch (e) { this.$message.error(e.userMessage || '设置失败') }
    },
    async unbindCategory (category) {
      try { await this.$confirm(`取消后分类“${category.category_name}”及其下级将改按上级分类或默认模板计提成。确认？`, '取消指定分类', { type: 'warning' }) } catch (e) { return }
      try { const r = await unbindPerformanceTemplateCategories(this.form.id, { category_ids: [category.id] }); this.$message.success(r.data.message || '已取消指定'); this.loadDetail(this.form.id); this.load() } catch (e) { this.$message.error(e.userMessage || '取消失败') }
    },
    createNew () { this.detail = { is_default: false, categories: [] }; this.form = { id: null, template_name: '', remark: '', enabled: true, business_version: 0, lines: [{ operation_id: null, percent: '' }] } },
    addLine () { this.form.lines.push({ operation_id: null, percent: '' }) },
    payload () {
      const f = this.form
      if (!f.template_name.trim()) throw new Error('请填写模板名称')
      const lines = f.lines.filter(l => l.operation_id || String(l.percent).trim())
      if (!lines.length) throw new Error('请至少设置一道工序的提成比例')
      return { template_name: f.template_name.trim(), remark: f.remark, status: f.enabled ? 'enabled' : 'disabled', expected_version: f.business_version || undefined,
        lines: lines.map(l => { if (!l.operation_id) throw new Error('有比例未选择对应工序'); const rate = percentToRate(l.percent); if (rate === null) throw new Error('比例请填写 0 到 100 之间的数字，最多 4 位小数'); return { operation_id: l.operation_id, performance_rate: rate } }) }
    },
    async save () {
      let data; try { data = this.payload() } catch (e) { return this.$message.warning(e.message) }
      this.saving = true
      try {
        const r = this.form.id ? await updatePerformanceTemplate(this.form.id, data) : await createPerformanceTemplate(data)
        this.$message.success(r.data.message || '提成模板已保存'); this.form = this.toForm(r.data.data); this.detail = { is_default: r.data.data.is_default, categories: r.data.data.categories || [] }; this.load()
      } catch (e) { this.$message.error(e.userMessage || '保存失败') } finally { this.saving = false }
    },
    async remove () {
      try { await this.$confirm('正在作为默认模板或已被工艺路线指定的模板不可删除。确认删除该模板？', '删除提成模板', { type: 'warning' }) } catch (e) { return }
      try { await deletePerformanceTemplate(this.form.id); this.$message.success('模板已删除'); this.form = null; this.load() } catch (e) { this.$message.error(e.userMessage || '删除失败') }
    },
    async openCategoryPicker () {
      if (!this.form.id) return
      const saved = this.templates.find(t => t.id === this.form.id)
      if (saved && JSON.stringify(this.toForm(saved)) !== JSON.stringify(this.form)) return this.$message.warning('模板有未保存的修改，请先保存再指定')
      if (!this.form.enabled) return this.$message.warning('模板已停用，请先启用并保存')
      Object.assign(this.categoryPicker, { open: true, loading: true, checked: this.detail.categories.map(c => c.id) })
      try { const r = await listEntity('categories', { category_type: 'product', tree: 1 }); this.categoryPicker.tree = r.data.data || [] } catch (e) { this.$message.error(e.userMessage || '商品分类加载失败') } finally { this.categoryPicker.loading = false }
    },
    async confirmCategories () {
      const checked = (this.$refs.categoryTree ? this.$refs.categoryTree.getCheckedKeys() : this.categoryPicker.checked).map(Number)
      const bound = this.detail.categories.map(c => Number(c.id))
      const add = checked.filter(id => !bound.includes(id))
      const remove = bound.filter(id => !checked.includes(id))
      if (!add.length && !remove.length) { this.categoryPicker.open = false; return }
      this.categoryPicker.saving = true
      try {
        if (add.length) await bindPerformanceTemplateCategories(this.form.id, { category_ids: add })
        if (remove.length) await unbindPerformanceTemplateCategories(this.form.id, { category_ids: remove })
        this.$message.success('适用范围已更新')
        this.categoryPicker.open = false
        this.load(); this.loadDetail(this.form.id)
      } catch (e) { this.$message.error(e.userMessage || '指定失败') } finally { this.categoryPicker.saving = false }
    }
  }
}
</script>

<style scoped src="./production-master.css"></style>
<style scoped>
.perf-tpl-page {
  padding: 16px 20px 30px;
  background: #f8fafc;
  min-height: calc(100vh - 52px);
  box-sizing: border-box;
}

/* 顶部标头 (.page-head) */
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
.head-tag {
  border-radius: 4px;
  font-weight: 500;
}
.sub-row {
  font-size: 13px;
  color: #64748b;
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
.btn-theme-create {
  background: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
}
.btn-theme-create:hover {
  background: #00763f !important;
  border-color: #00763f !important;
}

/* 布局 */
.perf-tpl-layout {
  display: grid;
  grid-template-columns: 340px minmax(0, 1fr);
  gap: 14px;
  align-items: start;
}
.perf-tpl-layout > * {
  min-width: 0;
  box-sizing: border-box;
}

/* 左侧列表 */
.perf-tpl-list {
  padding: 16px;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.list-head {
  padding-bottom: 10px;
  border-bottom: 1px solid #e2e8f0;
}
.tpl-cards-scroll {
  display: flex;
  flex-direction: column;
  gap: 10px;
  max-height: calc(100vh - 280px);
  overflow-y: auto;
}
.perf-tpl-card {
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 12px;
  cursor: pointer;
  background: #ffffff;
  transition: all 0.2s;
}
.perf-tpl-card:hover {
  border-color: #bbf7d0;
  background: #fcfdfd;
}
.perf-tpl-card.active {
  border-color: #008b4b;
  background: #f0fdf4;
  box-shadow: 0 0 0 1px #008b4b;
}
.perf-tpl-card-title {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
}
.tpl-name {
  font-weight: 700;
  color: #0f172a;
  font-size: 14px;
}
.tpl-badges {
  display: flex;
  align-items: center;
  gap: 4px;
}
.perf-tpl-card-sub {
  font-size: 12px;
  color: #64748b;
  margin-top: 6px;
}
.total-rate {
  color: #008b4b;
}
.perf-tpl-card-ops {
  font-size: 12px;
  color: #475569;
  margin-top: 6px;
  line-height: 1.5;
  word-break: break-all;
}
.perf-tpl-card-scope {
  font-size: 11px;
  color: #008b4b;
  margin-top: 6px;
  font-weight: 600;
}
.list-pagination {
  padding-top: 8px;
  border-top: 1px solid #f1f5f9;
}

/* 空状态 */
.perf-tpl-empty {
  color: #94a3b8;
  font-size: 13px;
  text-align: center;
  padding: 20px 0;
}
.perf-tpl-empty.big {
  padding: 90px 20px;
  text-align: center;
}
.perf-tpl-empty.big i {
  font-size: 48px;
  color: #cbd5e1;
  margin-bottom: 12px;
}
.empty-title {
  font-size: 15px;
  font-weight: 600;
  color: #334155;
  margin: 0 0 8px;
}
.perf-tpl-empty .hint {
  font-size: 13px;
  color: #64748b;
  max-width: 520px;
  margin: 0 auto;
  line-height: 1.5;
}

/* 右侧编辑器 */
.perf-tpl-editor {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  padding: 20px 24px;
  min-height: 480px;
}
.section-block {
  margin-bottom: 24px;
}
.card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-bottom: 12px;
  margin-bottom: 16px;
  border-bottom: 1px solid #e2e8f0;
  flex-wrap: wrap;
  gap: 8px;
}
.card-title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 15px;
  font-weight: 700;
  color: #0f172a;
}
.card-title::before {
  content: "";
  display: inline-block;
  width: 3px;
  height: 14px;
  background: #008b4b;
  border-radius: 2px;
}
.sub-explain {
  font-size: 12px;
  color: #64748b;
}

.perf-tpl-form {
  display: grid;
  grid-template-columns: minmax(0, 2fr) minmax(0, 1fr);
  gap: 14px 24px;
  align-items: center;
}
.perf-tpl-form label > span {
  display: block;
  color: #475569;
  font-size: 12px;
  font-weight: 600;
  margin-bottom: 6px;
}
.perf-tpl-form .wide {
  grid-column: 1 / -1;
}

.perf-tpl-table {
  border-radius: 6px;
  overflow: hidden;
}
.perf-tpl-table ::v-deep th {
  background: #f8fafc;
  color: #334155;
  font-weight: 600;
}
.perf-tpl-table .full {
  width: 100%;
}
.op-no {
  float: right;
  color: #94a3b8;
  font-size: 12px;
  font-family: ui-monospace, monospace;
}
.money {
  color: #008b4b;
  font-family: ui-monospace, monospace;
  font-weight: 600;
}
.danger-btn {
  color: #dc2626;
  padding: 0;
}
.danger-btn:hover {
  color: #b91c1c;
}

.perf-tpl-total {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: 14px;
  color: #475569;
  font-size: 13px;
  flex-wrap: wrap;
  gap: 10px;
}
.total-summary b {
  color: #0f172a;
}
.total-money {
  color: #008b4b !important;
}
.btn-theme-plain {
  border-color: #008b4b !important;
  color: #008b4b !important;
  background: #ffffff !important;
}
.btn-theme-plain:hover {
  background: #f0fdf4 !important;
}

.scope-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
}
.scope-label {
  width: 64px;
  flex-shrink: 0;
  font-size: 13px;
  font-weight: 600;
  color: #475569;
}
.scope-tag {
  font-size: 12px;
}
.scope-tip {
  padding: 8px 12px;
  border-radius: 6px;
  font-size: 12px;
  background: #fffbeb;
  border: 1px solid #fde68a;
  color: #92400e;
}
.empty-hint {
  font-size: 12px;
  color: #94a3b8;
}
.category-tree-box {
  max-height: 50vh;
  overflow-y: auto;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 10px;
}

.form-actions {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  gap: 10px;
  margin-top: 24px;
  padding-top: 18px;
  border-top: 1px solid #f1f5f9;
}
.btn-danger-plain {
  border-color: #fecaca !important;
  color: #dc2626 !important;
  background: #fff !important;
}
.btn-danger-plain:hover {
  background: #fef2f2 !important;
  border-color: #dc2626 !important;
}

/* 弹窗 */
.perf-tpl-tip {
  margin: 0 0 12px;
  color: #64748b;
  font-size: 13px;
  line-height: 1.5;
}

@media (max-width: 900px) {
  .perf-tpl-layout {
    grid-template-columns: 1fr;
  }
  .perf-tpl-form {
    grid-template-columns: 1fr;
  }
  .perf-tpl-editor {
    padding: 16px;
  }
  .form-actions {
    flex-wrap: wrap;
  }
}
</style>

