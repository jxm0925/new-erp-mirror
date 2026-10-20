<template>
  <section class="mfg-page" v-loading="loading">
    <div class="mfg-hero">
      <div class="mfg-crumb"><a @click="$router.push('/production/tech')">生产管理 / 定制技术</a> / {{ wo.work_order_no || '' }}</div>
      <div class="mfg-hrow">
        <div>
          <div class="mfg-title">
            {{ wo.product_name || '定制工单' }} <span v-if="wo.planned_qty">× {{ qty(wo.planned_qty) }}</span>
            <span v-if="tech.status" :class="['pill', tech.status === 'pending' ? 'p-y' : 'p-g']">{{ tech.status_label }}</span>
            <span class="pill p-b">定制</span>
            <span v-if="wo.is_urgent" class="pill p-r">加急</span>
          </div>
          <div class="mfg-meta">
            <div>订单<b>{{ wo.sales_order_no || '-' }}</b></div>
            <div>客户<b>{{ wo.customer_name || '-' }}</b></div>
            <div>交期<b>{{ shortDate(wo.required_date) }}</b></div>
            <div>生产负责人<b>{{ wo.owner_name || '-' }}</b></div>
            <div>技术负责人<b>{{ tech.tech_owner_name || '还没人处理' }}</b></div>
          </div>
        </div>
        <div class="mfg-actions">
          <el-button v-if="wo.id" class="ghost" @click="$router.push(`/production/work-orders/${wo.id}`)">看工单</el-button>
          <el-button v-if="editable" class="primary" :loading="releasing" @click="release">下发车间</el-button>
        </div>
      </div>
    </div>

    <div v-if="d" class="mfg-body">
      <div class="mfg-grid">
        <div>
          <div class="card">
            <h2>客户要什么 <small>销售下单时填的定制说明</small></h2>
            <div class="want">{{ d.custom_description || '销售没填定制说明，可在订单里补充。' }}</div>
            <div v-if="d.configuration.length" class="cfg"><span v-for="(c, i) in d.configuration" :key="i" class="chip c-n">{{ c }}</span></div>
            <div v-if="d.reference_attachments.length" class="refs">
              <span class="sub">参考附件：</span>
              <a v-for="a in d.reference_attachments" :key="a.id" class="lk" :href="a.url" target="_blank" rel="noopener">{{ a.name }}</a>
            </div>
          </div>

          <div class="card">
            <h2>设计图 <small>车间小程序里能看到最新一版</small></h2>
            <div v-if="tech.drawings_updated_at" class="note upd">下发后改过图纸（{{ tech.drawings_updated_at.slice(5, 16) }}），车间会看到“图纸已更新”。</div>
            <div class="dwg-grid">
              <div v-for="g in d.drawings" :key="g.id" class="dwg">
                <a class="thumb" :href="g.url" target="_blank" rel="noopener">
                  <img v-if="isImage(g)" :src="g.url" :alt="g.drawing_name">
                  <span v-else>{{ g.original_name || g.drawing_name }}</span>
                </a>
                <div class="dwg-info">
                  <b>{{ g.drawing_name }} V{{ g.version_no }}</b>
                  <div class="sub">{{ g.uploaded_by || '-' }} {{ (g.uploaded_at || '').slice(5, 10) }}<span v-if="g.replaced_drawing_id"> · 替换了 V{{ g.version_no - 1 }}</span></div>
                  <div v-if="canUpload" class="dwg-ops">
                    <span class="lk" @click="pickFile(g)">替换</span>
                    <span class="lk del" @click="removeDrawing(g)">删除</span>
                  </div>
                </div>
              </div>
              <div v-if="canUpload" class="dwg add" @click="pickFile(null)">
                <i class="el-icon-plus" />
                <div>上传图纸</div>
                <div class="sub">PDF / 图片 / DWG / DXF / STEP / ZIP</div>
              </div>
              <div v-if="!d.drawings.length && !canUpload" class="empty">还没有设计图</div>
            </div>
            <input ref="file" type="file" class="hidden-file" accept=".pdf,.png,.jpg,.jpeg,.webp,.gif,.dwg,.dxf,.step,.stp,.zip" @change="upload">
            <div v-if="d.drawing_history.length" class="hist">
              <span class="lk" @click="showHistory = !showHistory">{{ showHistory ? '收起' : '看' }}旧版本（{{ d.drawing_history.length }}）</span>
              <div v-if="showHistory">
                <div v-for="g in d.drawing_history" :key="g.id" class="sub">
                  <a :href="g.url" target="_blank" rel="noopener" class="lk">{{ g.drawing_name }} V{{ g.version_no }}</a> · {{ g.uploaded_by }} {{ (g.uploaded_at || '').slice(5, 16) }}
                </div>
              </div>
            </div>
          </div>

          <div class="card">
            <h2>
              <span>定制 BOM <span v-if="tech.bom_confirmed" class="chip c-g">已确认</span><span v-else class="chip c-o">没确认</span></span>
              <span class="h-right">
                <small v-if="d.base_bom">从标准 BOM「{{ bomLabel(d.base_bom.bom_name, d.base_bom.bom_no, d.base_bom.version) }}」复制，只给这张工单用</small>
                <el-button v-if="editable && d.base_bom" size="mini" @click="openBom">编辑 BOM</el-button>
              </span>
            </h2>
            <div v-if="!d.base_bom" class="note">工单还没有基础 BOM，请生产主管先在工单里选 BOM。</div>
            <template v-else>
              <div class="chg-sum sub">改 {{ d.bom_changes.changed }} 行 · 加 {{ d.bom_changes.added }} 行 · 删 {{ d.bom_changes.removed }} 行 · 同 {{ d.bom_changes.same }} 行</div>
              <div class="tbl-wrap">
                <table class="bom">
                  <thead><tr><th>变化</th><th>物料</th><th>规格</th><th>标准用量</th><th>定制用量</th><th>工序</th><th>可用库存</th></tr></thead>
                  <tbody>
                    <tr v-for="r in visibleLines" :key="r.key" :class="'r-' + r.change">
                      <td><span :class="['tag', 't-' + r.change]">{{ CHANGE[r.change] }}</span></td>
                      <td>
                        <div>{{ r.component_item_name }}</div>
                        <div class="sub">{{ r.component_item_code }}<span v-if="r.base_item_name"> · 原 {{ r.base_item_name }}</span><span v-if="r.remark"> · {{ r.remark }}</span></div>
                      </td>
                      <td>{{ r.spec || '-' }}</td>
                      <td>{{ r.base_qty === null ? '-' : qty(r.base_qty) + ' ' + (r.unit_name || '') }}</td>
                      <td><b>{{ r.change === 'removed' ? '0' : qty(r.qty) + ' ' + (r.unit_name || '') }}</b><div v-if="r.loss_rate || r.fixed_qty" class="sub">损耗 {{ qty(r.loss_rate) }}%<span v-if="r.fixed_qty"> · 固定 {{ qty(r.fixed_qty) }}</span></div></td>
                      <td>{{ r.operation_name || '-' }}</td>
                      <td :class="r.change !== 'removed' && r.available_qty < r.required_qty ? 'warn' : ''">{{ qty(r.available_qty) }}</td>
                    </tr>
                    <tr v-if="sameCount && !showSame" class="r-same">
                      <td><span class="tag t-same">同</span></td>
                      <td colspan="6" class="sub">其余 {{ sameCount }} 行和标准 BOM 一样 · <span class="lk" @click="showSame = true">展开</span></td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <div v-if="showSame && sameCount" class="sub fold"><span class="lk" @click="showSame = false">收起相同的行</span></div>
            </template>
          </div>

          <div class="card">
            <h2>工艺路线 <small>默认沿用标准路线，要换请生产主管在工单里调整</small></h2>
            <div v-if="d.routing">{{ d.routing.routing_name }} V{{ d.routing.version }}：{{ d.routing.path }}</div>
            <div v-else class="warn">工单还没选工艺路线</div>
          </div>
        </div>

        <div>
          <div class="card">
            <h2>下发前检查</h2>
            <div v-for="(c, i) in d.checks" :key="i" class="chk">
              <i :class="c.ok === true ? 'el-icon-check ok' : (c.ok === false ? 'el-icon-close warn' : 'el-icon-warning-outline amb')" />
              <span>{{ c.text }}</span>
            </div>
            <template v-if="editable">
              <el-input v-model="remark" type="textarea" :rows="3" maxlength="500" placeholder="给车间的说明（可不填）" class="rmk" />
              <el-button class="go" type="warning" :loading="releasing" :disabled="!canRelease" @click="release">下发车间</el-button>
              <div class="sub tip">下发后工单自动下达、生成序列号，车间就能接单。下发之前工单停在“计划”，车间看不到。</div>
            </template>
            <div v-else-if="tech.status === 'released'" class="sub tip">{{ tech.released_by_name || '' }} {{ (tech.released_at || '').slice(5, 16) }} 已下发车间<span v-if="tech.remark">：{{ tech.remark }}</span></div>
            <div v-else class="sub tip">只有定制技术负责人能上传图纸、确认 BOM 和下发。</div>
          </div>
          <div class="card">
            <h2>记录</h2>
            <div v-if="!d.logs.length" class="sub">暂无记录</div>
            <div v-for="(l, i) in d.logs" :key="i" class="log sub">{{ (l.created_at || '').slice(5, 10) }} {{ l.operator_name || '系统' }} {{ l.content }}</div>
          </div>
          <div class="card">
            <h2>之后</h2>
            <div class="sub tip">做完不用再审，按正常产品入库、发货。<br>价格由销售在订单里手填，这里不管价格。</div>
          </div>
        </div>
      </div>
    </div>

    <el-dialog title="编辑定制 BOM" :visible.sync="bomDialog" :width="dialogWidth" :close-on-click-modal="false" append-to-body>
      <div class="sub dlg-tip">改这里只影响这张工单，不改 BOM 主档。不要的料直接删掉这行；用量填 1 台的用量。</div>
      <div class="tbl-wrap">
        <table class="bom edit">
          <thead><tr><th style="min-width:220px">物料</th><th>用量</th><th>损耗率%</th><th>固定用量</th><th>用在哪道工序</th><th>备注</th><th></th></tr></thead>
          <tbody>
            <tr v-for="(r, i) in editRows" :key="r.uid">
              <td>
                <el-select v-model="r.component_item_id" size="mini" filterable remote :remote-method="k => searchItems(k, r)" :loading="r.loading" placeholder="搜物料编码 / 名称" @focus="!r.options.length && searchItems('', r)">
                  <el-option v-for="o in r.options" :key="o.id" :label="o.item_name + (o.item_code ? '（' + o.item_code + '）' : '')" :value="o.id" />
                </el-select>
                <div v-if="r.base_line_id" class="sub">标准行：{{ r.base_name }}</div>
                <div v-else class="sub ok">新加的料</div>
              </td>
              <td><el-input-number v-model="r.qty" size="mini" :min="0" :precision="4" :controls="false" class="num" /></td>
              <td><el-input-number v-model="r.loss_rate" size="mini" :min="0" :max="99.99" :precision="2" :controls="false" class="num" /></td>
              <td><el-input-number v-model="r.fixed_qty" size="mini" :min="0" :precision="4" :controls="false" class="num" /></td>
              <td>
                <el-select v-model="r.target_routing_operation_id" size="mini" clearable placeholder="不指定">
                  <el-option v-for="op in d.operations" :key="op.routing_operation_id" :label="op.operation_name" :value="op.routing_operation_id" />
                </el-select>
              </td>
              <td><el-input v-model="r.remark" size="mini" maxlength="200" /></td>
              <td><span class="lk del" @click="editRows.splice(i, 1)">删</span></td>
            </tr>
          </tbody>
        </table>
      </div>
      <el-button size="mini" icon="el-icon-plus" class="add-line" @click="addLine">加一行</el-button>
      <span slot="footer">
        <el-button size="small" @click="bomDialog = false">取消</el-button>
        <el-button size="small" :loading="saving" @click="saveBom(false)">先保存</el-button>
        <el-button size="small" type="primary" :loading="saving" @click="saveBom(true)">保存并确认</el-button>
      </span>
    </el-dialog>
  </section>
</template>

<script>
import { getProductionTech, saveProductionTechBom, uploadProductionDrawing, deleteProductionDrawing, releaseProductionTech, searchProductionOptions } from '../../../api/erp/production'
import { qty, shortDate, bomLabel, errorText, isCancel } from './mfg-helpers'

const CHANGE = { changed: '改', added: '加', removed: '删', same: '同' }
let uid = 0

export default {
  name: 'ProductionTechDetail',
  data: () => ({
    CHANGE, loading: false, d: null, remark: '', releasing: false, saving: false, uploading: false,
    showSame: false, showHistory: false, replaceTarget: null, bomDialog: false, editRows: []
  }),
  computed: {
    wo() { return (this.d && this.d.work_order) || {} },
    tech() { return (this.d && this.d.tech) || {} },
    editable() { return !!(this.d && this.d.can_manage && this.tech.status === 'pending' && this.wo.status === 'planned') },
    canUpload() { return !!(this.d && this.d.can_manage && !['completed', 'closed', 'cancelled'].includes(this.wo.status)) },
    dialogWidth() { return window.innerWidth < 1000 ? '96%' : '980px' },
    canRelease() { return this.d && this.d.checks.every(c => c.ok !== false) },
    sameCount() { return this.d ? this.d.bom_lines.filter(r => r.change === 'same').length : 0 },
    visibleLines() {
      if (!this.d) return []
      const order = { changed: 0, added: 1, removed: 2, same: 3 }
      const rows = this.d.bom_lines.filter(r => this.showSame || r.change !== 'same')
      return rows.slice().sort((a, b) => order[a.change] - order[b.change])
    }
  },
  watch: { '$route.params.id'() { this.load() } },
  created() { this.load() },
  methods: {
    qty, shortDate, bomLabel,
    async load() {
      this.loading = true
      try {
        const { data } = await getProductionTech(this.$route.params.id)
        this.apply(data.data)
      } catch (e) {
        this.$message.error(errorText(e, '定制工单加载失败'))
      } finally {
        this.loading = false
      }
    },
    apply(data) {
      if (!data) return this.load()
      const canManage = data.can_manage === undefined && this.d ? this.d.can_manage : data.can_manage
      this.d = { ...data, can_manage: canManage }
      if (!this.remark && data.tech && data.tech.remark && data.tech.status === 'pending') this.remark = data.tech.remark
    },
    isImage(g) { return /^image\//.test(g.mime_type || '') },
    pickFile(target) {
      this.replaceTarget = target
      this.$refs.file.value = ''
      this.$refs.file.click()
    },
    async upload(ev) {
      const file = ev.target.files && ev.target.files[0]
      if (!file) return
      if (file.size > 50 * 1024 * 1024) return this.$message.error('图纸不能超过 50MB')
      const form = new FormData()
      form.append('file', file)
      if (this.replaceTarget) form.append('replace_drawing_id', this.replaceTarget.id)
      this.uploading = true
      const loading = this.$loading({ text: '正在上传图纸…' })
      try {
        const { data } = await uploadProductionDrawing(this.wo.id, form)
        this.$message.success(data.message || '图纸已上传')
        this.apply(data.data)
      } catch (e) {
        this.$message.error(errorText(e, '图纸上传失败'))
      } finally {
        loading.close()
        this.uploading = false
        this.replaceTarget = null
      }
    },
    async removeDrawing(g) {
      try {
        await this.$confirm(`删除图纸「${g.drawing_name} V${g.version_no}」？`, '删除图纸', { type: 'warning', confirmButtonText: '删除', cancelButtonText: '取消' })
        await deleteProductionDrawing(g.id)
        this.$message.success('图纸已删除')
        this.load()
      } catch (e) {
        if (!isCancel(e)) this.$message.error(errorText(e, '删除失败'))
      }
    },
    openBom() {
      this.editRows = this.d.bom_lines.filter(r => r.change !== 'removed').map(r => ({
        uid: ++uid, base_line_id: r.base_line_id, base_name: r.base_item_name || r.component_item_name,
        component_item_id: r.component_item_id, qty: r.qty, loss_rate: r.loss_rate, fixed_qty: r.fixed_qty,
        target_routing_operation_id: r.target_routing_operation_id || null, remark: r.remark || '',
        options: [{ id: r.component_item_id, item_name: r.component_item_name, item_code: r.component_item_code }], loading: false
      }))
      this.bomDialog = true
    },
    addLine() {
      this.editRows.push({ uid: ++uid, base_line_id: null, base_name: '', component_item_id: null, qty: 1, loss_rate: 0, fixed_qty: 0, target_routing_operation_id: null, remark: '', options: [], loading: false })
    },
    async searchItems(keyword, row) {
      row.loading = true
      try {
        const { data } = await searchProductionOptions('items', { keyword: keyword || undefined, purpose: 'routing', per_page: 20 })
        const list = (data.data || []).map(o => ({ id: o.id, item_name: o.item_name, item_code: o.item_code }))
        const current = row.options.find(o => o.id === row.component_item_id)
        row.options = current && !list.some(o => o.id === current.id) ? [current, ...list] : list
      } catch (e) {
        this.$message.error(errorText(e, '物料加载失败'))
      } finally {
        row.loading = false
      }
    },
    async saveBom(confirmed) {
      const bad = this.editRows.findIndex(r => !r.component_item_id || !(Number(r.qty) > 0))
      if (!this.editRows.length) return this.$message.error('定制 BOM 至少要有一行物料')
      if (bad >= 0) return this.$message.error(`第 ${bad + 1} 行请选物料并填大于 0 的用量`)
      this.saving = true
      try {
        const lines = this.editRows.map(r => ({
          base_line_id: r.base_line_id || null, component_item_id: r.component_item_id, qty: r.qty, loss_rate: r.loss_rate || 0,
          fixed_qty: r.fixed_qty || 0, target_routing_operation_id: r.target_routing_operation_id || null, remark: r.remark || null
        }))
        const { data } = await saveProductionTechBom(this.wo.id, { lines, confirmed })
        this.$message.success(data.message || '已保存')
        this.bomDialog = false
        this.apply(data.data)
      } catch (e) {
        this.$message.error(errorText(e, '保存失败'))
      } finally {
        this.saving = false
      }
    },
    async release() {
      if (!this.canRelease) return this.$message.warning('下发前检查还有没完成的项')
      try {
        await this.$confirm('下发后工单自动下达、生成序列号，车间就能接单；定制 BOM 不能再改（图纸还能换）。确定下发？', '下发车间', { type: 'warning', confirmButtonText: '下发车间', cancelButtonText: '取消' })
      } catch (e) { return }
      this.releasing = true
      try {
        const { data } = await releaseProductionTech(this.wo.id, { remark: this.remark || null })
        this.$message.success(data.message || '已下发车间')
        this.apply(data.data)
      } catch (e) {
        this.$message.error(errorText(e, '下发失败'))
      } finally {
        this.releasing = false
      }
    }
  }
}
</script>

<style scoped src="./mfg-v2.css"></style>
<style scoped>
.want { background: #f6f8fb; border-radius: 8px; padding: 12px 14px; line-height: 1.7; white-space: pre-wrap; }
.cfg { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 10px; }
.refs { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 10px; font-size: 12px; }
.upd { margin-bottom: 12px; }
.dwg-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
.dwg { border: 1px solid #e3e8f0; border-radius: 8px; overflow: hidden; }
.thumb { display: flex; align-items: center; justify-content: center; height: 92px; background: repeating-linear-gradient(135deg, #eef1f5, #eef1f5 8px, #f6f8fb 8px, #f6f8fb 16px); color: #8a95a8; font-weight: 600; text-decoration: none; padding: 0 8px; text-align: center; word-break: break-all; }
.thumb img { max-width: 100%; max-height: 92px; object-fit: contain; }
.dwg-info { padding: 8px 10px; }
.dwg-ops { display: flex; gap: 12px; margin-top: 4px; font-size: 12px; }
.lk.del { color: #d92d20; }
.dwg.add { border-style: dashed; display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 140px; color: #5b6780; cursor: pointer; gap: 4px; }
.dwg.add i { font-size: 22px; }
.dwg.add:hover { border-color: #1f6feb; color: #1f6feb; }
.hidden-file { display: none; }
.hist { margin-top: 10px; font-size: 12px; display: grid; gap: 4px; }
.h-right { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; justify-content: flex-end; }
.chg-sum { margin-bottom: 8px; }
.tbl-wrap { overflow-x: auto; }
.bom { width: 100%; border-collapse: collapse; font-size: 12px; }
.bom th { color: #8a95a8; font-weight: 500; text-align: left; padding: 8px 6px; border-bottom: 1px solid #e3e8f0; white-space: nowrap; }
.bom td { padding: 8px 6px; border-bottom: 1px solid #f0f2f6; vertical-align: top; }
.r-changed { background: #fffaf0; } .r-added { background: #f1faf5; } .r-removed { background: #fff5f4; }
.r-removed td:not(:first-child) { text-decoration: line-through; color: #c0261b; }
.tag { display: inline-block; width: 22px; height: 22px; line-height: 22px; text-align: center; border-radius: 11px; font-weight: 700; font-size: 11px; }
.t-changed { background: #fff1e0; color: #b25c00; } .t-added { background: #e6f7ef; color: #11804a; } .t-removed { background: #ffe9e7; color: #c0261b; } .t-same { background: #eef1f5; color: #5b6780; }
.fold { margin-top: 8px; }
.chk { display: flex; gap: 8px; align-items: flex-start; padding: 6px 0; line-height: 1.5; }
.chk i { margin-top: 3px; font-weight: 700; }
.rmk { margin-top: 10px; }
.go { width: 100%; margin-top: 10px; font-weight: 600; }
.tip { line-height: 1.7; margin-top: 10px; }
.log { padding: 4px 0; line-height: 1.6; }
.dlg-tip { margin-bottom: 10px; }
.bom.edit td { vertical-align: middle; }
.bom.edit .el-select { width: 100%; }
.num { width: 90px; }
.add-line { margin-top: 10px; }
@media (max-width: 1100px) {
  .dwg-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 767px) {
  .dwg-grid { grid-template-columns: 1fr; }
  .h-right { justify-content: flex-start; }
}
</style>
