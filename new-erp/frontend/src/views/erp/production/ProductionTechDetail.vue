<template>
  <div class="tech-detail-page" v-loading="loading">
    <!-- 顶部统一标头 (.page-head) -->
    <header class="page-head">
      <div class="head-left">
        <el-button size="small" icon="el-icon-back" class="btn-back" @click="$router.push('/production/tech')">
          返回定制工艺列表
        </el-button>
        <span class="head-icon"><i class="el-icon-set-up" /></span>
        <div class="head-title-wrap">
          <div class="title-row">
            <h1 class="page-title">{{ wo.product_name || '定制工单' }}</h1>
            <span v-if="wo.planned_qty" class="qty-tag">× {{ qty(wo.planned_qty) }} {{ wo.unit_name || '台' }}</span>
            <el-tag v-if="tech.status" size="small" :type="tech.status === 'pending' ? 'warning' : 'success'" effect="plain">
              {{ tech.status === 'pending' ? '待工艺确认' : '已下发车间' }}
            </el-tag>
            <el-tag size="small" type="info" effect="plain">定制工单</el-tag>
            <el-tag v-if="wo.is_urgent" size="small" type="danger" effect="dark" class="urgent-tag">加急制令</el-tag>
          </div>
          <div class="sub-row">
            <span>工单编号：<b class="mono-code">{{ wo.work_order_no || '-' }}</b></span>
            <span>销售订单：<b class="mono-code">{{ wo.sales_order_no || '-' }}</b></span>
            <span>客户：<b>{{ wo.customer_name || '-' }}</b></span>
            <span>要求交期：<b>{{ shortDate(wo.required_date) }}</b></span>
            <span>生产负责人：<b>{{ wo.owner_name || '-' }}</b></span>
            <span>工艺负责人：<b>{{ tech.tech_owner_name || '待工艺认领' }}</b></span>
          </div>
        </div>
      </div>
      <div class="head-actions">
        <el-button v-if="wo.id" size="small" icon="el-icon-document" class="btn-ghost" @click="$router.push(`/production/work-orders/${wo.id}`)">
          工单详情
        </el-button>
        <el-button v-if="editable" size="small" type="success" icon="el-icon-check" class="btn-theme-create" :loading="releasing" @click="release">
          下发车间
        </el-button>
      </div>
    </header>

    <!-- 主体区域 -->
    <div v-if="d" class="page-body">
      <div class="layout-grid">
        <!-- 左侧主区 -->
        <div class="main-column">
          <!-- 卡片 1：客户定制需求 -->
          <div class="content-card">
            <div class="card-title-bar">
              <h2 class="section-title">客户定制需求 <small class="text-muted">销售订单定制需求描述与附件</small></h2>
            </div>
            <div class="custom-desc-box">{{ d.custom_description || '当前订单未录入定制需求描述，可前往销售订单补充。' }}</div>
            <div v-if="d.configuration && d.configuration.length" class="config-tags">
              <span v-for="(c, i) in d.configuration" :key="i" class="config-chip">{{ c }}</span>
            </div>
            <div v-if="d.reference_attachments && d.reference_attachments.length" class="attachment-list">
              <span class="text-muted">参考技术附件：</span>
              <a v-for="a in d.reference_attachments" :key="a.id" class="theme-link" :href="a.url" target="_blank" rel="noopener">
                <i class="el-icon-paperclip" /> {{ a.name }}
              </a>
            </div>
          </div>

          <!-- 卡片 2：工艺图纸资料 -->
          <div class="content-card">
            <div class="card-title-bar">
              <h2 class="section-title">工艺图纸资料 <small class="text-muted">车间移动端与工位终端同步展示最新工程图纸</small></h2>
            </div>
            <div v-if="tech.drawings_updated_at" class="notice-alert">
              <i class="el-icon-info" />
              <span>图纸已于（{{ tech.drawings_updated_at.slice(5, 16) }}）完成版本更新，车间执行端已同步标记最新版本提醒。</span>
            </div>
            <div class="drawing-grid">
              <div v-for="g in d.drawings" :key="g.id" class="drawing-card">
                <a class="drawing-thumb" :href="g.url" target="_blank" rel="noopener">
                  <img v-if="isImage(g)" :src="g.url" :alt="g.drawing_name">
                  <span v-else class="file-icon-box"><i class="el-icon-document" /> {{ g.original_name || g.drawing_name }}</span>
                </a>
                <div class="drawing-info">
                  <div class="drawing-name" :title="g.drawing_name"><b>{{ g.drawing_name }}</b> <span class="ver-badge">V{{ g.version_no }}</span></div>
                  <div class="drawing-meta">{{ g.uploaded_by || '-' }} · {{ (g.uploaded_at || '').slice(5, 10) }}<span v-if="g.replaced_drawing_id"> · 更新自 V{{ g.version_no - 1 }}</span></div>
                  <div v-if="canUpload" class="drawing-ops">
                    <span class="theme-link" @click="pickFile(g)"><i class="el-icon-refresh" /> 更新版本</span>
                    <span class="danger-link" @click="removeDrawing(g)"><i class="el-icon-delete" /> 删除</span>
                  </div>
                </div>
              </div>
              <div v-if="canUpload" class="drawing-upload-card" @click="pickFile(null)">
                <i class="el-icon-upload" />
                <div class="upload-title">上传工程图纸</div>
                <div class="upload-sub">支持 PDF / 图片 / DWG / DXF / STEP / ZIP</div>
              </div>
              <div v-if="!d.drawings.length && !canUpload" class="empty-tip">暂无工程图纸资料</div>
            </div>
            <input ref="file" type="file" class="hidden-file-input" accept=".pdf,.png,.jpg,.jpeg,.webp,.gif,.dwg,.dxf,.step,.stp,.zip" @change="upload">
            <div v-if="d.drawing_history && d.drawing_history.length" class="history-section">
              <span class="theme-link" @click="showHistory = !showHistory">
                <i :class="showHistory ? 'el-icon-arrow-up' : 'el-icon-arrow-down'" />
                {{ showHistory ? '收起历史版本' : '查看历史版本图纸' }}（{{ d.drawing_history.length }} 份）
              </span>
              <div v-if="showHistory" class="history-list">
                <div v-for="g in d.drawing_history" :key="g.id" class="history-item">
                  <a :href="g.url" target="_blank" rel="noopener" class="theme-link"><i class="el-icon-document" /> {{ g.drawing_name }} V{{ g.version_no }}</a>
                  <span class="text-muted">· {{ g.uploaded_by }} {{ (g.uploaded_at || '').slice(5, 16) }}</span>
                </div>
              </div>
            </div>
          </div>

          <!-- 卡片 3：定制 BOM 清单 -->
          <div class="content-card">
            <div class="card-title-bar">
              <div class="title-left">
                <h2 class="section-title">定制 BOM 清单</h2>
                <el-tag size="mini" :type="tech.bom_confirmed ? 'success' : 'warning'" effect="plain">
                  {{ tech.bom_confirmed ? '已确认' : '待确认' }}
                </el-tag>
              </div>
              <div class="title-right">
                <span v-if="d.base_bom" class="base-bom-desc">基于标准BOM「{{ bomLabel(d.base_bom.bom_name, d.base_bom.bom_no, d.base_bom.version) }}」派生定制，仅对本工单有效</span>
                <el-button v-if="editable && d.base_bom" size="mini" icon="el-icon-edit" class="btn-theme-plain" @click="openBom">
                  编辑定制BOM
                </el-button>
              </div>
            </div>

            <div v-if="!d.base_bom" class="notice-alert">
              <i class="el-icon-warning text-warning" />
              <span>当前工单尚未关联基准BOM，请先在工单详情中配置基准BOM。</span>
            </div>
            <template v-else>
              <div class="bom-diff-summary">
                <span class="diff-tag diff-changed">变更 {{ d.bom_changes.changed }} 项</span>
                <span class="diff-tag diff-added">新增 {{ d.bom_changes.added }} 项</span>
                <span class="diff-tag diff-removed">停用 {{ d.bom_changes.removed }} 项</span>
                <span class="diff-tag diff-same">沿用 {{ d.bom_changes.same }} 项</span>
              </div>
              <div class="table-shell">
                <table class="bom-table">
                  <thead>
                    <tr>
                      <th style="width: 80px;">变更状态</th>
                      <th>物料编码与名称</th>
                      <th>规格型号</th>
                      <th>标准单台定额</th>
                      <th>定制单台定额</th>
                      <th>适用工序</th>
                      <th>可用库存</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="r in visibleLines" :key="r.key" :class="'row-' + r.change">
                      <td>
                        <span :class="['change-tag', 'tag-' + r.change]">{{ CHANGE[r.change] }}</span>
                      </td>
                      <td>
                        <div class="font-medium text-main">{{ r.component_item_name }}</div>
                        <div class="text-muted mono-code">
                          {{ r.component_item_code }}
                          <span v-if="r.base_item_name"> · 原基准：{{ r.base_item_name }}</span>
                          <span v-if="r.remark"> · {{ r.remark }}</span>
                        </div>
                      </td>
                      <td class="text-secondary">{{ r.spec || '-' }}</td>
                      <td class="text-secondary">{{ r.base_qty === null ? '-' : qty(r.base_qty) + ' ' + (r.unit_name || '') }}</td>
                      <td>
                        <b :class="r.change === 'removed' ? 'text-removed' : 'text-main'">
                          {{ r.change === 'removed' ? '0' : qty(r.qty) + ' ' + (r.unit_name || '') }}
                        </b>
                        <div v-if="r.change !== 'removed' && altQtyText(r.component_item_id, r.qty)" class="text-muted small">{{ altQtyText(r.component_item_id, r.qty) }}</div>
                        <div v-if="r.loss_rate || r.fixed_qty" class="text-muted small">
                          损耗率 {{ qty(r.loss_rate) }}%<span v-if="r.fixed_qty"> · 固定 {{ qty(r.fixed_qty) }}</span>
                        </div>
                      </td>
                      <td class="text-secondary">{{ r.operation_name || '-' }}</td>
                      <td :class="r.change !== 'removed' && r.available_qty < r.required_qty ? 'text-warning font-medium' : 'text-secondary'">
                        {{ qty(r.available_qty) }}
                      </td>
                    </tr>
                    <tr v-if="sameCount && !showSame" class="row-same-toggle">
                      <td><span class="change-tag tag-same">沿用</span></td>
                      <td colspan="6" class="text-muted">
                        其余 {{ sameCount }} 项与标准BOM保持一致 ·
                        <span class="theme-link" @click="showSame = true">展开明细</span>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <div v-if="showSame && sameCount" class="fold-bar">
                <span class="theme-link" @click="showSame = false">收起一致项</span>
              </div>
            </template>
          </div>

          <!-- 卡片 4：制造工艺路线 -->
          <div class="content-card">
            <div class="card-title-bar">
              <h2 class="section-title">制造工艺路线 <small class="text-muted">默认沿用产品标准工艺路线，如需调整请在工单详情中变更</small></h2>
            </div>
            <div v-if="d.routing" class="routing-box">
              <span class="font-medium text-main">{{ d.routing.routing_name }} V{{ d.routing.version }}</span>
              <span class="routing-path">{{ d.routing.path }}</span>
            </div>
            <div v-else class="notice-alert">
              <i class="el-icon-warning text-warning" />
              <span>当前工单尚未配置工艺路线。</span>
            </div>
          </div>
        </div>

        <!-- 右侧侧边区 -->
        <div class="side-column">
          <!-- 卡片 5：工艺下发前校验 -->
          <div class="content-card">
            <div class="card-title-bar">
              <h2 class="section-title">工艺下发前校验</h2>
            </div>
            <div class="check-list">
              <div v-for="(c, i) in d.checks" :key="i" class="check-item">
                <i :class="c.ok === true ? 'el-icon-circle-check check-ok' : (c.ok === false ? 'el-icon-circle-close check-fail' : 'el-icon-warning-outline check-warn')" />
                <span class="check-text">{{ c.text }}</span>
              </div>
            </div>
            <template v-if="editable">
              <el-input
                v-model="remark"
                type="textarea"
                :rows="3"
                maxlength="500"
                show-word-limit
                placeholder="车间作业指导备注（选填）"
                class="release-remark"
              />
              <el-button
                class="btn-release"
                type="success"
                :loading="releasing"
                :disabled="!canRelease"
                @click="release"
              >
                下发车间投产
              </el-button>
              <div class="tip-text">
                业务说明：确认下发后，工单将自动转为已下达状态并生成产品序列号，车间即可进入排产派工。下发前工单处于计划待审阶段。
              </div>
            </template>
            <div v-else-if="tech.status === 'released'" class="released-info">
              <i class="el-icon-circle-check text-success" />
              <span>{{ tech.released_by_name || '工艺员' }} 于 {{ (tech.released_at || '').slice(5, 16) }} 已下发车间<span v-if="tech.remark">：{{ tech.remark }}</span></span>
            </div>
            <div v-else class="tip-text">
              权限说明：仅工艺技术负责人拥有上传图纸、确认定制BOM及下发工单权限。
            </div>
          </div>

          <!-- 卡片 6：技术准备日志 -->
          <div class="content-card">
            <div class="card-title-bar">
              <h2 class="section-title">技术准备日志</h2>
            </div>
            <div v-if="!d.logs.length" class="empty-tip">暂无操作日志</div>
            <div v-for="(l, i) in d.logs" :key="i" class="log-item">
              <span class="log-date">{{ (l.created_at || '').slice(5, 10) }}</span>
              <span class="log-operator">{{ l.operator_name || '系统' }}</span>
              <span class="log-content">{{ l.content }}</span>
            </div>
          </div>

          <!-- 卡片 7：后续流转提示 -->
          <div class="content-card flow-tip-card">
            <div class="card-title-bar">
              <h2 class="section-title">后续流转提示</h2>
            </div>
            <p class="flow-tip-text">
              工艺确认下发后，工单遵循标准制造、检验、入库与发运流程；定制产品结算价格以销售订单锁定的合同金额为准。
            </p>
          </div>
        </div>
      </div>
    </div>

    <!-- 编辑定制 BOM 居中弹窗 -->
    <el-dialog title="编辑定制 BOM" :visible.sync="bomDialog" :width="dialogWidth" :close-on-click-modal="false" append-to-body>
      <div class="dialog-notice">
        <i class="el-icon-info" />
        <span>说明：修改仅作用于当前工单的定制BOM结构，不影响标准BOM主档案。单台定额用量请输入单件标准消耗。</span>
      </div>
      <div class="table-shell dialog-table-wrap">
        <table class="bom-table edit-mode">
          <thead>
            <tr>
              <th style="min-width: 220px;">物料编码与名称</th>
              <th style="width: 100px;">单台用量</th>
              <th style="width: 90px;">损耗率%</th>
              <th style="width: 90px;">固定用量</th>
              <th style="width: 130px;">关联工序</th>
              <th>备注说明</th>
              <th style="width: 50px;" align="center">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(r, i) in editRows" :key="r.uid">
              <td>
                <el-select
                  v-model="r.component_item_id"
                  size="mini"
                  filterable
                  remote
                  :remote-method="k => searchItems(k, r)"
                  :loading="r.loading"
                  placeholder="搜索物料编码 / 名称"
                  @focus="!r.options.length && searchItems('', r)"
                >
                  <el-option
                    v-for="o in r.options"
                    :key="o.id"
                    :label="o.item_name + (o.item_code ? ' (' + o.item_code + ')' : '')"
                    :value="o.id"
                  />
                </el-select>
                <div v-if="r.base_line_id" class="sub-indicator text-muted">基准物料：{{ r.base_name }}</div>
                <div v-else class="sub-indicator text-success">定制新增物料</div>
              </td>
              <td><el-input-number v-model="r.qty" size="mini" :min="0" :precision="4" :controls="false" class="num-input" /></td>
              <td><el-input-number v-model="r.loss_rate" size="mini" :min="0" :max="99.99" :precision="2" :controls="false" class="num-input" /></td>
              <td><el-input-number v-model="r.fixed_qty" size="mini" :min="0" :precision="4" :controls="false" class="num-input" /></td>
              <td>
                <el-select v-model="r.target_routing_operation_id" size="mini" clearable placeholder="不指定">
                  <el-option v-for="op in d.operations" :key="op.routing_operation_id" :label="op.operation_name" :value="op.routing_operation_id" />
                </el-select>
              </td>
              <td><el-input v-model="r.remark" size="mini" maxlength="200" placeholder="备注" /></td>
              <td align="center">
                <el-button type="text" size="mini" icon="el-icon-delete" class="danger-link" @click="editRows.splice(i, 1)">删除</el-button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <el-button size="mini" icon="el-icon-plus" class="btn-theme-plain add-line-btn" @click="addLine">添加物料行</el-button>
      <span slot="footer" class="dialog-footer">
        <el-button size="small" @click="bomDialog = false">取消</el-button>
        <el-button size="small" :loading="saving" @click="saveBom(false)">暂存草稿</el-button>
        <el-button size="small" type="success" class="btn-theme-create" :loading="saving" @click="saveBom(true)">保存并确认BOM</el-button>
      </span>
    </el-dialog>
  </div>
</template>

<script>
import unitOptionsMixin from './unit-options-mixin'
import { getProductionTech, saveProductionTechBom, uploadProductionDrawing, deleteProductionDrawing, releaseProductionTech, searchProductionOptions } from '../../../api/erp/production'
import { qty, shortDate, bomLabel, errorText, isCancel } from './mfg-helpers'

const CHANGE = { changed: '变更', added: '新增', removed: '停用', same: '沿用' }
let uid = 0

export default {
  name: 'ProductionTechDetail',
  mixins: [unitOptionsMixin],
  data: () => ({
    CHANGE,
    loading: false,
    d: null,
    remark: '',
    releasing: false,
    saving: false,
    uploading: false,
    showSame: false,
    showHistory: false,
    replaceTarget: null,
    bomDialog: false,
    editRows: []
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
    qty,
    shortDate,
    bomLabel,
    async load() {
      this.loading = true
      try {
        const { data } = await getProductionTech(this.$route.params.id)
        this.apply(data.data)
      } catch (e) {
        this.$message.error(errorText(e, '定制工艺工单详情加载失败'))
      } finally {
        this.loading = false
      }
    },
    apply(data) {
      this.ensureUnitOptions(((data && data.bom_lines) || []).map(r => r.component_item_id))
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
      if (file.size > 50 * 1024 * 1024) return this.$message.error('工程图纸大小不能超过 50MB')
      const form = new FormData()
      form.append('file', file)
      if (this.replaceTarget) form.append('replace_drawing_id', this.replaceTarget.id)
      this.uploading = true
      const loading = this.$loading({ text: '正在上传工程图纸…' })
      try {
        const { data } = await uploadProductionDrawing(this.wo.id, form)
        this.$message.success(data.message || '工程图纸已上传')
        this.apply(data.data)
      } catch (e) {
        this.$message.error(errorText(e, '工程图纸上传失败'))
      } finally {
        loading.close()
        this.uploading = false
        this.replaceTarget = null
      }
    },
    async removeDrawing(g) {
      try {
        await this.$confirm(`确定删除工程图纸「${g.drawing_name} V${g.version_no}」？`, '删除图纸确认', {
          type: 'warning',
          confirmButtonText: '删除',
          cancelButtonText: '取消'
        })
        await deleteProductionDrawing(g.id)
        this.$message.success('工程图纸已删除')
        this.load()
      } catch (e) {
        if (!isCancel(e)) this.$message.error(errorText(e, '删除失败'))
      }
    },
    openBom() {
      this.editRows = this.d.bom_lines.filter(r => r.change !== 'removed').map(r => ({
        uid: ++uid,
        base_line_id: r.base_line_id,
        base_name: r.base_item_name || r.component_item_name,
        component_item_id: r.component_item_id,
        qty: r.qty,
        loss_rate: r.loss_rate,
        fixed_qty: r.fixed_qty,
        target_routing_operation_id: r.target_routing_operation_id || null,
        remark: r.remark || '',
        options: [{ id: r.component_item_id, item_name: r.component_item_name, item_code: r.component_item_code }],
        loading: false
      }))
      this.bomDialog = true
    },
    addLine() {
      this.editRows.push({
        uid: ++uid,
        base_line_id: null,
        base_name: '',
        component_item_id: null,
        qty: 1,
        loss_rate: 0,
        fixed_qty: 0,
        target_routing_operation_id: null,
        remark: '',
        options: [],
        loading: false
      })
    },
    async searchItems(keyword, row) {
      row.loading = true
      try {
        const { data } = await searchProductionOptions('items', { keyword: keyword || undefined, purpose: 'routing', per_page: 20 })
        const list = (data.data || []).map(o => ({ id: o.id, item_name: o.item_name, item_code: o.item_code }))
        const current = row.options.find(o => o.id === row.component_item_id)
        row.options = current && !list.some(o => o.id === current.id) ? [current, ...list] : list
      } catch (e) {
        this.$message.error(errorText(e, '物料列表加载失败'))
      } finally {
        row.loading = false
      }
    },
    async saveBom(confirmed) {
      const bad = this.editRows.findIndex(r => !r.component_item_id || !(Number(r.qty) > 0))
      if (!this.editRows.length) return this.$message.error('定制 BOM 至少须包含一行有效物料')
      if (bad >= 0) return this.$message.error(`第 ${bad + 1} 行请选择物料并输入大于 0 的定额用量`)
      this.saving = true
      try {
        const lines = this.editRows.map(r => ({
          base_line_id: r.base_line_id || null,
          component_item_id: r.component_item_id,
          qty: r.qty,
          loss_rate: r.loss_rate || 0,
          fixed_qty: r.fixed_qty || 0,
          target_routing_operation_id: r.target_routing_operation_id || null,
          remark: r.remark || null
        }))
        const { data } = await saveProductionTechBom(this.wo.id, { lines, confirmed })
        this.$message.success(data.message || (confirmed ? '定制 BOM 已确认' : '草稿已暂存'))
        this.bomDialog = false
        this.apply(data.data)
      } catch (e) {
        this.$message.error(errorText(e, '定制 BOM 保存失败'))
      } finally {
        this.saving = false
      }
    },
    async release() {
      if (!this.canRelease) return this.$message.warning('下发前校验尚有未达标项，暂不可下发')
      try {
        await this.$confirm(
          '确认执行工艺下发？下发后工单将自动转为已下达状态并生成序列号，定制 BOM 清单将被锁定。',
          '下发车间确认',
          { type: 'warning', confirmButtonText: '确认下发', cancelButtonText: '取消' }
        )
      } catch (e) {
        return
      }
      this.releasing = true
      try {
        const { data } = await releaseProductionTech(this.wo.id, { remark: this.remark || null })
        this.$message.success(data.message || '工艺已成功下发车间')
        this.apply(data.data)
      } catch (e) {
        this.$message.error(errorText(e, '工艺下发失败'))
      } finally {
        this.releasing = false
      }
    }
  }
}
</script>

<style scoped>
.tech-detail-page {
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
  flex-wrap: wrap;
}
.btn-back {
  border-color: #cbd5e1 !important;
  color: #475569 !important;
}
.btn-back:hover {
  border-color: #008b4b !important;
  color: #008b4b !important;
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
  gap: 8px;
  flex-wrap: wrap;
}
.page-title {
  margin: 0;
  font-size: 18px;
  font-weight: 700;
  color: #0f172a;
}
.qty-tag {
  font-size: 14px;
  font-weight: 700;
  color: #008b4b;
  margin-right: 4px;
}
.urgent-tag {
  margin-left: 2px;
}
.sub-row {
  display: flex;
  gap: 14px;
  font-size: 12px;
  color: #64748b;
  flex-wrap: wrap;
}
.sub-row b {
  color: #1e293b;
}
.mono-code {
  font-family: "DejaVu Sans Mono", Menlo, Consolas, monospace;
}
.head-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}
.btn-ghost {
  border-color: #cbd5e1 !important;
  color: #334155 !important;
}
.btn-ghost:hover {
  border-color: #008b4b !important;
  color: #008b4b !important;
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

/* 布局网格 */
.page-body {
  box-sizing: border-box;
}
.layout-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 320px;
  gap: 16px;
  align-items: start;
}
.main-column, .side-column {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 14px;
}

/* 内容卡片规范 */
.content-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 16px 20px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
}
.card-title-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
  flex-wrap: wrap;
  gap: 8px;
}
.section-title {
  margin: 0;
  font-size: 15px;
  font-weight: 700;
  color: #1e293b;
  display: flex;
  align-items: center;
  gap: 8px;
  padding-left: 10px;
  border-left: 3px solid #008b4b;
}
.section-title small {
  font-size: 12px;
  color: #64748b;
  font-weight: 400;
}
.title-left {
  display: flex;
  align-items: center;
  gap: 8px;
}
.title-right {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

/* 提示条 */
.notice-alert {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 9px 14px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 6px;
  color: #00763f;
  font-size: 12px;
  margin-bottom: 12px;
}
.notice-alert i {
  font-size: 15px;
  color: #008b4b;
}

/* 需求卡片 */
.custom-desc-box {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 12px 14px;
  line-height: 1.7;
  font-size: 13px;
  color: #334155;
  white-space: pre-wrap;
}
.config-tags {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
  margin-top: 10px;
}
.config-chip {
  font-size: 12px;
  background: #f1f5f9;
  color: #475569;
  padding: 3px 10px;
  border-radius: 12px;
}
.attachment-list {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  margin-top: 10px;
  font-size: 12px;
}
.theme-link {
  color: #008b4b;
  font-weight: 500;
  cursor: pointer;
  text-decoration: none;
}
.theme-link:hover {
  color: #00763f;
  text-decoration: underline;
}
.danger-link {
  color: #ef4444 !important;
  cursor: pointer;
}
.danger-link:hover {
  text-decoration: underline;
}

/* 图纸卡片 */
.drawing-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
}
.drawing-card {
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  overflow: hidden;
  background: #ffffff;
}
.drawing-thumb {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 96px;
  background: #f1f5f9;
  color: #64748b;
  font-size: 12px;
  text-decoration: none;
  padding: 6px 8px;
  text-align: center;
}
.drawing-thumb img {
  max-width: 100%;
  max-height: 96px;
  object-fit: contain;
}
.file-icon-box {
  display: flex;
  align-items: center;
  gap: 4px;
  word-break: break-all;
}
.file-icon-box i {
  font-size: 18px;
  color: #008b4b;
}
.drawing-info {
  padding: 8px 10px;
}
.drawing-name {
  font-size: 12px;
  color: #0f172a;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.ver-badge {
  color: #008b4b;
  font-weight: 600;
}
.drawing-meta {
  font-size: 11px;
  color: #64748b;
  margin-top: 2px;
}
.drawing-ops {
  display: flex;
  gap: 12px;
  margin-top: 6px;
  font-size: 12px;
}
.drawing-upload-card {
  border: 1px dashed #cbd5e1;
  border-radius: 8px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-height: 140px;
  color: #64748b;
  cursor: pointer;
  background: #f8fafc;
  transition: all 0.2s;
  padding: 10px;
  text-align: center;
}
.drawing-upload-card i {
  font-size: 24px;
  color: #008b4b;
  margin-bottom: 4px;
}
.upload-title {
  font-weight: 600;
  color: #334155;
  font-size: 13px;
}
.upload-sub {
  font-size: 11px;
  color: #94a3b8;
  margin-top: 2px;
}
.drawing-upload-card:hover {
  border-color: #008b4b;
  background: #f0fdf4;
}
.hidden-file-input {
  display: none;
}
.history-section {
  margin-top: 10px;
  font-size: 12px;
}
.history-list {
  margin-top: 6px;
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding-left: 12px;
}
.history-item {
  font-size: 12px;
}

/* BOM 表格与差异 */
.base-bom-desc {
  font-size: 12px;
  color: #64748b;
}
.btn-theme-plain {
  border-color: #008b4b !important;
  color: #008b4b !important;
  background: #ffffff !important;
}
.btn-theme-plain:hover {
  background: #f0fdf4 !important;
}
.bom-diff-summary {
  display: flex;
  gap: 8px;
  margin-bottom: 10px;
  flex-wrap: wrap;
}
.diff-tag {
  font-size: 11px;
  padding: 2px 8px;
  border-radius: 4px;
  font-weight: 500;
}
.diff-changed { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }
.diff-added { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
.diff-removed { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
.diff-same { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }

.table-shell {
  overflow-x: auto;
}
.bom-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
}
.bom-table th {
  background: #f8fafc;
  color: #334155;
  font-weight: 600;
  text-align: left;
  padding: 8px 10px;
  border-bottom: 1px solid #e2e8f0;
  white-space: nowrap;
}
.bom-table td {
  padding: 8px 10px;
  border-bottom: 1px solid #f1f5f9;
  vertical-align: top;
}
.row-changed { background: #fffdf5; }
.row-added { background: #f8fdfa; }
.row-removed { background: #fff8f7; }
.row-removed td:not(:first-child) {
  text-decoration: line-through;
  color: #ef4444;
}
.change-tag {
  display: inline-block;
  padding: 1px 6px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 600;
  text-align: center;
}
.tag-changed { background: #fef3c7; color: #92400e; }
.tag-added { background: #dcfce7; color: #166534; }
.tag-removed { background: #fee2e2; color: #991b1b; }
.tag-same { background: #f1f5f9; color: #64748b; }
.row-same-toggle td {
  background: #fafbfc;
  padding: 10px;
}
.fold-bar {
  margin-top: 8px;
  text-align: right;
  font-size: 12px;
}

/* 工艺路线 */
.routing-box {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 10px 14px;
  display: flex;
  gap: 12px;
  align-items: center;
  font-size: 13px;
}
.routing-path {
  color: #475569;
}

/* 侧边校验与操作卡片 */
.check-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-bottom: 12px;
}
.check-item {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: #334155;
}
.check-ok { color: #008b4b; font-size: 16px; }
.check-fail { color: #ef4444; font-size: 16px; }
.check-warn { color: #f59e0b; font-size: 16px; }
.release-remark {
  margin-bottom: 12px;
}
.btn-release {
  width: 100%;
  font-weight: 600;
  background: #008b4b !important;
  border-color: #008b4b !important;
}
.btn-release:hover {
  background: #00763f !important;
  border-color: #00763f !important;
}
.tip-text {
  font-size: 12px;
  color: #64748b;
  line-height: 1.6;
  margin-top: 10px;
}
.released-info {
  display: flex;
  align-items: flex-start;
  gap: 6px;
  font-size: 12px;
  color: #008b4b;
  line-height: 1.6;
}

/* 日志卡片 */
.log-item {
  padding: 6px 0;
  border-bottom: 1px solid #f1f5f9;
  font-size: 12px;
  line-height: 1.5;
  color: #475569;
  display: grid;
  grid-template-columns: 55px 55px 1fr;
  gap: 6px;
}
.log-date { color: #94a3b8; }
.log-operator { color: #334155; font-weight: 500; }
.empty-tip {
  color: #94a3b8;
  font-size: 12px;
  text-align: center;
  padding: 16px 0;
}
.flow-tip-card {
  background: #f0fdf4;
  border-color: #bbf7d0;
}
.flow-tip-text {
  margin: 0;
  font-size: 12px;
  color: #00763f;
  line-height: 1.7;
}

/* 弹窗样式 */
.dialog-notice {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 8px 12px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 6px;
  color: #00763f;
  font-size: 12px;
  margin-bottom: 12px;
}
.dialog-table-wrap {
  max-height: 60vh;
  overflow-y: auto;
}
.bom-table.edit-mode td {
  vertical-align: middle;
  padding: 6px 8px;
}
.num-input {
  width: 100%;
}
.sub-indicator {
  font-size: 11px;
  margin-top: 2px;
}
.add-line-btn {
  margin-top: 10px;
}

@media (max-width: 1100px) {
  .layout-grid { grid-template-columns: 1fr; }
  .drawing-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 767px) {
  .drawing-grid { grid-template-columns: 1fr; }
  .page-head { padding: 12px 14px; }
  .title-row { flex-direction: column; align-items: flex-start; }
}
</style>
