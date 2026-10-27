<template>
  <section class="production-page operation-page">
    <!-- 顶部统一标头 (.page-head) -->
    <header class="page-head">
      <div class="head-left">
        <span class="head-icon"><i class="el-icon-set-up" /></span>
        <div class="head-title-wrap">
          <div class="title-row">
            <h1 class="page-title">工序管理</h1>
            <el-tag size="small" type="success" effect="plain" class="head-tag">
              共 {{ total }} 项
            </el-tag>
          </div>
          <div class="sub-row">
            维护工艺路线引用的标准工序主数据、作业准入人员资质及自动化派工规则
          </div>
        </div>
      </div>
      <div class="head-actions">
        <el-button size="small" icon="el-icon-refresh" class="btn-refresh" :loading="loading" @click="load">
          刷新
        </el-button>
        <el-button v-if="$can('production.operation.create')" size="small" type="success" icon="el-icon-plus" class="btn-theme-create" @click="openCreate">
          新增工序
        </el-button>
      </div>
    </header>

    <div class="operation-layout">
      <main class="operation-main" v-loading="loading">
        <!-- 筛选工具栏 -->
        <section class="filter-card operation-filters">
          <label>
            <span>工序编码 / 名称</span>
            <el-input v-model.trim="filters.keyword" size="small" clearable prefix-icon="el-icon-search" placeholder="请输入工序编码或名称" @keyup.enter.native="search" />
          </label>
          <label>
            <span>状态</span>
            <el-select v-model="filters.status" size="small" clearable placeholder="全部状态">
              <el-option label="已启用" value="enabled" />
              <el-option label="已停用" value="disabled" />
            </el-select>
          </label>
          <label>
            <span>引用状态</span>
            <el-select v-model="filters.reference_status" size="small" clearable placeholder="全部引用状态">
              <el-option label="被生效路线引用" value="referenced" />
              <el-option label="未被生效路线引用" value="unreferenced" />
            </el-select>
          </label>
          <label>
            <span>工序属性</span>
            <el-select v-model="filters.is_public" size="small" clearable placeholder="全部属性">
              <el-option label="公共工序" value="1" />
              <el-option label="专用工序" value="0" />
            </el-select>
          </label>
          <div class="filter-actions">
            <el-button size="small" type="success" icon="el-icon-search" class="btn-theme-search" @click="search">查询</el-button>
            <el-button size="small" icon="el-icon-refresh-right" class="btn-theme-reset" @click="reset">重置</el-button>
          </div>
        </section>

        <!-- 数据表格卡片 -->
        <section class="table-card operation-table-card">
          <el-table :data="rows" border highlight-current-row :row-class-name="rowClass" @row-click="selectRow">
            <el-table-column prop="operation_no" label="工序编码" min-width="120">
              <template slot-scope="s">
                <span class="mono-code">{{ s.row.operation_no }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="operation_name" label="工序名称" min-width="130">
              <template slot-scope="s">
                <span class="operation-name">{{ s.row.operation_name }}</span>
                <el-tag v-if="booleanFlag(s.row.is_public)" size="mini" type="success" effect="plain" class="public-operation-tag">公共工序</el-tag>
              </template>
            </el-table-column>
            <el-table-column label="状态" width="75" align="center">
              <template slot-scope="s">
                <el-tag size="mini" :type="s.row.status==='enabled'?'success':'info'">{{ s.row.status==='enabled'?'启用':'停用' }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column prop="sort" label="排序" width="55" align="center" />
            <el-table-column label="生效路线引用" width="115" align="center">
              <template slot-scope="s">
                <button class="reference-count" :class="{ active:Number(s.row.active_routing_count)>0 }" @click.stop="selectRow(s.row)">
                  {{ Number(s.row.active_routing_count || 0) }} 条
                </button>
              </template>
            </el-table-column>
            <el-table-column prop="description" label="工序说明" min-width="150">
              <template slot-scope="s">
                <span class="description-text">{{ s.row.description || '-' }}</span>
              </template>
            </el-table-column>
            <el-table-column label="更新时间" width="135">
              <template slot-scope="s">
                <span class="date-text">{{ time(s.row.updated_at) }}</span>
              </template>
            </el-table-column>
            <el-table-column label="操作" width="140" align="center" fixed="right">
              <template slot-scope="s">
                <span class="row-actions" @click.stop>
                  <el-button type="text" @click="selectRow(s.row)">查看</el-button>
                  <el-button v-if="$can('production.operation.edit')" type="text" @click="openEdit(s.row)">编辑</el-button>
                  <el-button v-if="$can('production.operation.toggle')" type="text" :class="s.row.status==='enabled'?'danger-link':'success-link'" @click="toggle(s.row)">
                    {{ s.row.status==='enabled'?'停用':'启用' }}
                  </el-button>
                </span>
              </template>
            </el-table-column>
          </el-table>
          <div class="table-footer">
            <span>共 {{ total }} 条</span>
            <el-pagination background layout="sizes, prev, pager, next" :current-page="page" :page-size="perPage" :page-sizes="[10,15,20]" :total="total" @current-change="changePage" @size-change="changePageSize" />
          </div>
        </section>
      </main>

      <!-- 右侧工序详情检查器 -->
      <aside class="operation-inspector" v-loading="detailLoading">
        <template v-if="selected">
          <header class="inspector-header">
            <div class="card-title">工序详情</div>
            <el-button type="text" icon="el-icon-close" class="close-btn" aria-label="关闭工序详情" @click="selected=null" />
          </header>
          <dl class="operation-facts">
            <div><dt>工序编码</dt><dd class="mono-code">{{ selected.operation_no }}</dd></div>
            <div><dt>工序名称</dt><dd><b>{{ selected.operation_name }}</b></dd></div>
            <div><dt>状态</dt><dd><el-tag size="mini" :type="selected.status==='enabled'?'success':'info'">{{ selected.status==='enabled'?'启用':'停用' }}</el-tag></dd></div>
            <div><dt>排序</dt><dd>{{ selected.sort }}</dd></div>
            <div><dt>工序属性</dt><dd><el-tag size="mini" :type="booleanFlag(selected.is_public)?'success':'info'">{{ booleanFlag(selected.is_public)?'公共工序':'专用工序' }}</el-tag></dd></div>
            <div><dt>效率优先派工</dt><dd>{{ booleanFlag(selected.auto_assignment_enabled)?'已启用':'未启用' }}</dd></div>
            <div><dt>生效路线引用</dt><dd>{{ selected.active_routing_count || 0 }} 条</dd></div>
            <div><dt>更新人</dt><dd>{{ selected.updated_by_legacy_id ? `用户 #${selected.updated_by_legacy_id}` : '-' }}</dd></div>
            <div class="wide"><dt>说明</dt><dd>{{ selected.description || '-' }}</dd></div>
            <div class="wide"><dt>更新时间</dt><dd>{{ time(selected.updated_at) }}</dd></div>
          </dl>

          <section class="routing-references">
            <div class="card-title sub">已生效路线引用</div>
            <p class="section-desc">当前工序已被下列生效工艺路线所引用：</p>
            <el-table :data="selected.active_routings || []" size="mini" border empty-text="当前没有已生效路线引用">
              <el-table-column prop="routing_no" label="路线编码" width="96" />
              <el-table-column prop="routing_name" label="路线名称" min-width="90" />
              <el-table-column label="版本" width="50" align="center"><template slot-scope="s">V{{ s.row.version }}</template></el-table-column>
              <el-table-column label="适用物料 / 类目" min-width="100"><template slot-scope="s">{{ (s.row.applicabilities || []).map(x => x.item ? x.item.item_name : (x.category ? x.category.category_name + '（类目）' : '')).filter(Boolean).join('、') || '-' }}</template></el-table-column>
            </el-table>
          </section>

          <section class="routing-references worker-section" v-loading="workersLoading">
            <div class="card-title sub">工序准入人员资质</div>
            <p class="section-desc">仅具备本工序资质的人员可在移动端接单及报工；未配置时，具备车间作业权限的人员均可作业。</p>
            <div class="worker-tags">
              <el-tag v-for="w in workers" :key="w.id" size="small" type="success" effect="plain">{{ w.name }}</el-tag>
              <span v-if="!workers.length" class="field-help">未配置准入人员（全部报工权限人员可作业）</span>
            </div>
            <el-button v-if="$can('production.operation.edit')" size="small" icon="el-icon-user" class="btn-workers" @click="openWorkers">配置准入人员</el-button>
          </section>

          <el-alert v-if="Number(selected.active_routing_count)>0 && selected.status==='enabled'" title="该工序正被已生效路线引用，暂不可停用" type="warning" :closable="false" show-icon />

          <div class="inspector-actions">
            <el-button size="small" @click="$router.push(`/production/operations/${selected.id}`)">查看详情</el-button>
            <el-button v-if="$can('production.operation.edit')" size="small" type="success" plain @click="openEdit(selected)">编辑</el-button>
            <el-button v-if="$can('production.operation.toggle')" size="small" type="danger" plain :disabled="selected.status!=='enabled' || Number(selected.active_routing_count)>0" @click="toggle(selected)">停用</el-button>
            <el-button v-if="$can('production.operation.toggle')" size="small" type="success" plain :disabled="selected.status==='enabled'" @click="toggle(selected)">启用</el-button>
          </div>
        </template>
        <el-empty v-else description="选择左侧工序查看详情" :image-size="72" />
      </aside>
    </div>

    <!-- 新增 / 编辑工序弹窗 -->
    <el-dialog :title="dialogMode==='edit'?'编辑工序':'新增工序'" :visible.sync="createDialog" width="540px" custom-class="operation-create-dialog" :close-on-click-modal="false" :close-on-press-escape="!createSaving" :show-close="!createSaving" @close="closeCreate">
      <div v-loading="createLoading || createSaving" class="operation-create-body">
        <el-form :key="createFormKey" ref="createForm" :model="createForm" :rules="createRules" label-position="top" size="small">
          <div class="operation-create-grid">
            <el-form-item label="工序编码"><el-input v-model="createForm.operation_no" disabled class="erp-system-number" /></el-form-item>
            <el-form-item label="工序名称" prop="operation_name"><el-input v-model.trim="createForm.operation_name" maxlength="160" placeholder="请输入工序名称" /></el-form-item>
            <el-form-item label="排序" prop="sort"><el-input-number v-model="createForm.sort" controls-position="right" :min="0" :max="999999" /><p class="field-help">数值越小排在越前</p></el-form-item>
            <el-form-item label="状态" prop="status"><el-radio-group v-model="createForm.status" :disabled="statusRadioDisabled"><el-radio label="enabled">启用</el-radio><el-radio label="disabled">停用</el-radio></el-radio-group><p v-if="statusLockReason" class="field-help status-lock-help"><i class="el-icon-warning"/> {{ statusLockReason }}</p></el-form-item>
            <el-form-item label="工序属性"><el-switch v-model="createForm.is_public" active-color="#008b4b" active-text="公共工序" inactive-text="专用工序" /><p class="field-help">公共工序可被多个产品的工艺路线共享引用</p></el-form-item>
            <el-form-item label="效率优先派工"><el-switch v-model="createForm.auto_assignment_enabled" active-color="#008b4b" active-text="启用" inactive-text="关闭" /><p class="field-help">按相同产品、工艺合格记录智能推荐并派工</p></el-form-item>
          </div>
          <el-form-item label="工序说明"><el-input v-model="createForm.description" type="textarea" :rows="3" maxlength="2000" show-word-limit placeholder="请输入工序说明（选填）" /></el-form-item>
        </el-form>
      </div>
      <template slot="footer">
        <el-button :disabled="createSaving" @click="createDialog=false">取消</el-button>
        <el-button type="success" :loading="createSaving" :disabled="createLoading" @click="saveCreate">保存</el-button>
      </template>
    </el-dialog>

    <!-- 配置准入人员弹窗 -->
    <el-dialog :title="`工序准入人员资质 · ${selected ? selected.operation_name : ''}`" :visible.sync="workerDialog" width="480px" :close-on-click-modal="false" append-to-body>
      <p class="field-help worker-help">说明：未指定人员时，所有具备生产报工权限的员工均可作业；指定后仅名单内人员可在移动端申领及报工。</p>
      <el-select v-model="workerIds" multiple filterable remote :remote-method="searchWorkers" :loading="workerSearching" placeholder="输入姓名搜索添加人员" class="worker-select" @focus="searchWorkers('')">
        <el-option v-for="u in workerOptions" :key="u.id" :label="u.name" :value="u.id" />
      </el-select>
      <template slot="footer">
        <el-button @click="workerDialog=false">取消</el-button>
        <el-button type="success" :loading="workerSaving" @click="saveWorkers">保存</el-button>
      </template>
    </el-dialog>
  </section>
</template>

<script>
import { erpDateTime } from '../../../utils/dateTime'
import { listProductionOperations, getProductionOperation, createProductionOperation, updateProductionOperation, enableProductionOperation, disableProductionOperation, getOperationReferences, getOperationWorkers, saveOperationWorkers } from '../../../api/erp/production'
import { listUsers } from '../../../api/erp/rbac'
import { reserveForCreatePage, clearCreatePageReservation } from '../../../utils/documentNumberReservation'

const asBoolean = value => value === true || value === 1 || value === '1'
const blankCreateForm = () => ({ operation_no: '', operation_name: '', sort: 0, status: 'enabled', description: '', is_public: false, auto_assignment_enabled: false })

export default {
  name: 'ProductionOperationList',
  data: () => ({ loading: false, detailLoading: false, rows: [], selected: null, total: 0, page: 1, perPage: 10, filters: { keyword: '', status: '', reference_status: '', is_public: '' }, createDialog: false, createLoading: false, createSaving: false, editOriginalStatus: null, createReservation: null, createFormKey: 0, dialogMode: 'create', createForm: blankCreateForm(), createRules: { operation_name: [{ required: true, message: '请输入工序名称', trigger: 'blur' }] }, workers: [], workersLoading: false, workerDialog: false, workerIds: [], workerOptions: [], workerSearching: false, workerSaving: false }),
  computed: {
    statusRadioDisabled() { return this.dialogMode === 'edit' && !this.createForm.status_editable },
    statusLockReason() { return this.dialogMode === 'edit' && !this.createForm.status_editable ? this.createForm.status_lock_reason || '当前工序状态不可修改' : '' }
  },
  watch: {
    '$route.query.create'(value) { if (value === '1' && this.$can('production.operation.create')) this.openCreate() },
    '$route.query.edit'(value) { if (value && this.$can('production.operation.edit')) this.openEdit({ id: value }) }
  },
  created() { this.load(); if (this.$route.query.create === '1' && this.$can('production.operation.create')) this.$nextTick(this.openCreate); else if (this.$route.query.edit && this.$can('production.operation.edit')) this.$nextTick(() => this.openEdit({ id: this.$route.query.edit })) },
  methods: {
    async load() {
      this.loading = true
      try {
        const response = await listProductionOperations({ ...this.filters, page: this.page, per_page: this.perPage })
        this.rows = response.data.data || []
        this.total = response.data.total || 0
        if (this.selected) {
          const current = this.rows.find(row => row.id === this.selected.id)
          if (current) await this.selectRow(current)
          else this.selected = null
        }
      } catch (error) { this.$message.error(error.userMessage || '工序加载失败') } finally { this.loading = false }
    },
    async selectRow(row) {
      const requestedId = row.id
      this.selected = { ...row, active_routings: [] }
      this.detailLoading = true
      this.loadWorkers(requestedId)
      try {
        const response = await getProductionOperation(requestedId)
        if (this.selected && this.selected.id === requestedId) this.selected = response.data.data
      } catch (error) { this.$message.error(error.userMessage || '工序详情加载失败') } finally { if (this.selected && this.selected.id === requestedId) this.detailLoading = false }
    },
    async loadWorkers(id) {
      this.workers = []
      this.workersLoading = true
      try {
        const { data } = await getOperationWorkers(id)
        if (this.selected && this.selected.id === id) this.workers = data.data || []
      } catch (error) { this.workers = [] } finally { this.workersLoading = false }
    },
    openWorkers() {
      this.workerIds = this.workers.map(w => w.id)
      this.workerOptions = this.workers.slice()
      this.workerDialog = true
    },
    async searchWorkers(keyword) {
      this.workerSearching = true
      try {
        const { data } = await listUsers({ scope: 'production', capability: 'execute', status: 'normal', keyword: keyword || undefined, page: 1, per_page: 10 })
        const found = (data.data || []).map(u => ({ id: u.user_id, name: u.display_name }))
        const picked = this.workerOptions.filter(o => this.workerIds.includes(o.id))
        this.workerOptions = [...picked, ...found.filter(u => !picked.some(p => p.id === u.id))]
      } catch (error) { this.$message.error(error.userMessage || '人员加载失败') } finally { this.workerSearching = false }
    },
    async saveWorkers() {
      if (!this.selected) return
      this.workerSaving = true
      try {
        const { data } = await saveOperationWorkers(this.selected.id, { employee_legacy_ids: this.workerIds })
        this.workers = data.data || []
        this.workerDialog = false
        this.$message.success(data.message || '工序准入人员资质已保存')
      } catch (error) { this.$message.error(error.userMessage || '保存失败') } finally { this.workerSaving = false }
    },
    search() { this.page = 1; this.load() },
    reset() { this.filters = { keyword: '', status: '', reference_status: '', is_public: '' }; this.search() },
    changePage(value) { this.page = value; this.load() },
    changePageSize(value) { this.perPage = value; this.page = 1; this.load() },
    rowClass({ row }) { return this.selected && this.selected.id === row.id ? 'selected-operation-row' : '' },
    async openCreate() {
      if (this.createDialog) return
      this.dialogMode = 'create'
      this.createForm = blankCreateForm()
      this.createFormKey++
      this.createDialog = true
      this.createLoading = true
      this.$nextTick(() => this.$refs.createForm && this.$refs.createForm.clearValidate())
      try {
        this.createReservation = await reserveForCreatePage('operation', '/production/operations#create')
        this.createForm.operation_no = this.createReservation.document_no
      } catch (error) {
        this.createDialog = false
        this.$message.error(error.userMessage || '工序编号生成失败')
      } finally { this.createLoading = false }
    },
    async openEdit(row) {
      if (this.createDialog || !row || !row.id) return
      this.dialogMode = 'edit'
      this.createReservation = null
      this.createForm = blankCreateForm()
      this.createFormKey++
      this.createDialog = true
      this.createLoading = true
      this.$nextTick(() => this.$refs.createForm && this.$refs.createForm.clearValidate())
      try {
        const response = await getProductionOperation(row.id)
        this.editOriginalStatus = response.data.data.status
        this.createForm = { ...blankCreateForm(), ...response.data.data, is_public: asBoolean(response.data.data.is_public), auto_assignment_enabled: asBoolean(response.data.data.auto_assignment_enabled) }
      } catch (error) {
        this.createDialog = false
        this.$message.error(error.userMessage || '工序加载失败')
      } finally { this.createLoading = false }
    },
    closeCreate() {
      if (this.createSaving) return
      this.createDialog = false
      if (this.$route.query.create === '1' || this.$route.query.edit) this.$router.replace('/production/operations').catch(() => {})
    },
    saveCreate() {
      this.$refs.createForm.validate(async valid => {
        if (!valid || (this.dialogMode === 'create' && !this.createReservation)) return
        if (this.dialogMode === 'edit' && this.editOriginalStatus === 'enabled' && this.createForm.status === 'disabled'
          && !await this.confirmDisable(this.createForm.id, this.createForm.operation_name)) return
        this.createSaving = true
        try {
          const response = this.dialogMode === 'edit'
            ? await updateProductionOperation(this.createForm.id, {
              client_command_id: `operation-update-${this.createForm.id}-${this.createForm.business_version}`,
              expected_version: this.createForm.business_version,
              operation_name: this.createForm.operation_name,
              sort: this.createForm.sort,
              status: this.createForm.status,
              is_public: Boolean(this.createForm.is_public),
              auto_assignment_enabled: Boolean(this.createForm.auto_assignment_enabled),
              description: this.createForm.description || null
            })
            : await createProductionOperation({
              client_command_id: `operation-create-${this.createReservation.creation_session_id}`,
              creation_session_id: this.createReservation.creation_session_id,
              reservation_token: this.createReservation.reservation_token,
              operation_name: this.createForm.operation_name,
              sort: this.createForm.sort,
              status: this.createForm.status,
              is_public: Boolean(this.createForm.is_public),
              auto_assignment_enabled: Boolean(this.createForm.auto_assignment_enabled),
              description: this.createForm.description || null
            })
          const saved = response.data.data
          if (this.dialogMode === 'create') {
            clearCreatePageReservation(this.createReservation)
            this.createReservation = null
          }
          this.createDialog = false
          this.$message.success(this.dialogMode === 'edit' ? '工序已保存' : '工序已新增')
          await this.load()
          if (saved) await this.selectRow(saved)
        } catch (error) { this.$message.error(error.userMessage || '工序保存失败') } finally { this.createSaving = false }
      })
    },
    // 停用前列出引用：生效路线或生效 BOM 会被后端阻止；草稿 BOM 不阻止，但停用后会解除其用料工序绑定。
    async confirmDisable(id, name) {
      let refs = null
      try { refs = (await getOperationReferences(id)).data.data } catch (e) { refs = null }
      const boms = refs ? refs.boms : { active: [], draft: [] }
      const label = bom => [bom.bom_no, bom.bom_name, bom.version].filter(Boolean).join(' / ')
      const esc = text => String(text == null ? '' : text).replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch])
      const lines = [`确认停用工序“${name}”？`]
      if (boms.active.length) lines.push(`以下生效 BOM 仍绑定此工序，停用会被阻止：${boms.active.map(label).join('；')}`)
      if (boms.draft.length) lines.push(`以下草稿 BOM 绑定了此工序，停用后会解除这些行的用料工序，改按工艺路线规则用料${boms.draft.some(bom => bom.submitted) ? '（已提交审核的会撤回提交）' : ''}：${boms.draft.map(bom => `${label(bom)}（${bom.component_item_names.join('、')}）`).join('；')}`)
      if (!refs) lines.push('引用情况加载失败，后端仍会校验。')
      try {
        await this.$confirm(lines.map(esc).join('<br/><br/>'), '停用确认', { type: 'warning', dangerouslyUseHTMLString: true })
        return true
      } catch (e) { return false }
    },
    async toggle(row) {
      try {
        if (row.status === 'enabled') {
          if (!await this.confirmDisable(row.id, row.operation_name)) return
        } else {
          await this.$confirm(`确认启用工序“${row.operation_name}”？`, '状态确认', { type: 'warning' })
        }
        const request = row.status === 'enabled' ? disableProductionOperation : enableProductionOperation
        await request(row.id, { client_command_id: `operation-toggle-${row.id}-${Date.now()}`, expected_version: row.business_version })
        this.$message.success('工序状态已更新')
        await this.load()
      } catch (error) { if (error !== 'cancel' && error !== 'close') this.$message.error(error.userMessage || '操作失败') }
    },
    booleanFlag(value) { return asBoolean(value) },
    time(value) { return value ? erpDateTime(value, 16) : '-' }
  }
}
</script>

<style scoped src="./production-master.css"></style>
<style scoped>
.operation-page {
  padding: 16px 20px 30px;
  background: #f8fafc;
  min-height: calc(100vh - 52px);
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

/* 布局：左主表格 + 右详情检查器 */
.operation-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 380px;
  gap: 14px;
  align-items: stretch;
}
.operation-main,
.operation-inspector {
  min-width: 0;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
}
.operation-main {
  padding: 16px;
}

/* 筛选区 */
.operation-filters {
  display: grid;
  grid-template-columns: minmax(180px, 1.2fr) repeat(3, minmax(130px, 0.8fr)) auto;
  gap: 12px;
  align-items: end;
  padding: 14px 16px;
  margin-bottom: 14px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
}
.operation-filters label > span {
  display: block;
  margin-bottom: 6px;
  color: #475569;
  font-size: 12px;
  font-weight: 600;
}
.operation-filters .el-select {
  width: 100%;
}
.operation-filters .filter-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0;
  white-space: nowrap;
}
.btn-theme-search {
  background: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
}
.btn-theme-search:hover {
  background: #00763f !important;
  border-color: #00763f !important;
}
.btn-theme-reset {
  border-color: #cbd5e1 !important;
  color: #475569 !important;
}
.btn-theme-reset:hover {
  border-color: #008b4b !important;
  color: #008b4b !important;
}

/* 表格卡片 */
.operation-table-card {
  padding: 0;
  border-radius: 6px;
  overflow: hidden;
  border: 1px solid #e2e8f0;
}
.operation-table-card ::v-deep .el-table th {
  background: #f8fafc;
  color: #334155;
  font-weight: 600;
  height: 44px;
  padding: 6px 0;
}
.operation-table-card ::v-deep .el-table td {
  height: auto;
  padding: 9px 0;
}
.operation-table-card ::v-deep .el-table .cell {
  padding-left: 10px;
  padding-right: 10px;
  overflow-wrap: anywhere;
}
.operation-table-card ::v-deep .selected-operation-row td {
  background: #f0fdf4 !important;
}

.mono-code {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: 12px;
  color: #0f172a;
  font-weight: 600;
}
.operation-name {
  display: inline-block;
  font-weight: 600;
  color: #1e293b;
  overflow-wrap: anywhere;
}
.public-operation-tag {
  margin-left: 6px;
  vertical-align: middle;
}
.description-text,
.date-text {
  display: block;
  font-size: 12px;
  color: #64748b;
  white-space: normal;
  line-height: 1.45;
}
.reference-count {
  border: 1px solid #e2e8f0;
  background: #f8fafc;
  border-radius: 4px;
  padding: 3px 8px;
  font-size: 12px;
  color: #64748b;
  cursor: pointer;
  transition: all 0.15s;
}
.reference-count.active {
  color: #008b4b;
  border-color: #bbf7d0;
  background: #f0fdf4;
  font-weight: 600;
}
.reference-count:hover {
  border-color: #008b4b;
  color: #008b4b;
}

.row-actions {
  display: flex;
  align-items: center;
  justify-content: center;
  white-space: nowrap;
  gap: 8px;
}
.row-actions .el-button {
  padding: 0;
  font-size: 12px;
  color: #008b4b;
}
.row-actions .el-button:hover {
  color: #00763f;
}
.danger-link {
  color: #dc2626 !important;
}
.danger-link:hover {
  color: #b91c1c !important;
}
.success-link {
  color: #008b4b !important;
}
.table-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 16px;
  background: #ffffff;
  border-top: 1px solid #f1f5f9;
  color: #64748b;
  font-size: 13px;
}
.table-footer ::v-deep .el-pagination.is-background .el-pager li:not(.disabled).active {
  background-color: #008b4b !important;
  color: #ffffff;
}

/* 详情检查器 */
.operation-inspector {
  display: flex;
  flex-direction: column;
  padding: 16px;
  box-sizing: border-box;
}
.inspector-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-bottom: 12px;
  margin-bottom: 14px;
  border-bottom: 1px solid #e2e8f0;
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
.card-title.sub {
  font-size: 13px;
  margin-bottom: 4px;
}
.card-title.sub::before {
  height: 12px;
}
.close-btn {
  color: #94a3b8;
  padding: 0;
  font-size: 16px;
}
.close-btn:hover {
  color: #0f172a;
}

.operation-facts {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px 16px;
  margin: 0;
  padding-bottom: 16px;
  border-bottom: 1px solid #e2e8f0;
}
.operation-facts div {
  min-width: 0;
}
.operation-facts .wide {
  grid-column: 1 / -1;
}
.operation-facts dt {
  margin-bottom: 3px;
  color: #64748b;
  font-size: 12px;
}
.operation-facts dd {
  margin: 0;
  color: #1e293b;
  font-size: 13px;
  line-height: 1.5;
  overflow-wrap: anywhere;
}

.routing-references {
  margin-top: 16px;
}
.section-desc {
  margin: 0 0 10px;
  color: #64748b;
  font-size: 12px;
  line-height: 1.4;
}
.routing-references ::v-deep .el-table th {
  background: #f8fafc;
  color: #475569;
  font-weight: 600;
}
.routing-references ::v-deep .el-table .cell {
  padding-left: 6px;
  padding-right: 6px;
  white-space: normal;
  line-height: 1.35;
  overflow-wrap: anywhere;
}
.worker-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 10px;
}
.btn-workers {
  border-color: #cbd5e1 !important;
  color: #334155 !important;
}
.btn-workers:hover {
  border-color: #008b4b !important;
  color: #008b4b !important;
}
.operation-inspector .el-alert {
  margin-top: 14px;
}
.inspector-actions {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 8px;
  margin-top: auto;
  padding-top: 16px;
  border-top: 1px solid #f1f5f9;
}
.inspector-actions .el-button {
  min-width: 0;
  margin: 0;
  padding-left: 4px;
  padding-right: 4px;
}

/* 弹窗样式 */
.operation-create-body {
  min-height: 0;
}
.operation-create-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0 16px;
}
.operation-create-grid .el-input-number {
  width: 100%;
}
.field-help {
  margin: 4px 0 0;
  color: #64748b;
  font-size: 12px;
  line-height: 1.4;
}
.worker-help {
  margin-bottom: 12px;
}
.worker-select {
  width: 100%;
}

.operation-page ::v-deep .operation-create-dialog {
  top: 50%;
  margin: 0 auto !important;
  transform: translateY(-50%);
  border-radius: 8px;
  display: flex;
  flex-direction: column;
  max-height: 85vh;
}
.operation-page ::v-deep .operation-create-dialog .el-dialog__header {
  padding: 16px 22px 12px;
  border-bottom: 1px solid #e2e8f0;
}
.operation-page ::v-deep .operation-create-dialog .el-dialog__title {
  font-size: 16px;
  font-weight: 700;
  color: #0f172a;
}
.operation-page ::v-deep .operation-create-dialog .el-dialog__body {
  padding: 16px 22px 6px;
  min-height: 0;
  overflow-y: auto;
}
.operation-page ::v-deep .operation-create-dialog .el-dialog__footer {
  padding: 12px 22px 16px;
  border-top: 1px solid #f1f5f9;
}
.operation-page ::v-deep .operation-create-dialog .el-radio-group {
  display: flex;
  align-items: center;
  height: 32px;
}
.operation-page ::v-deep .operation-create-dialog .el-radio__input.is-checked .el-radio__inner {
  background: #008b4b;
  border-color: #008b4b;
}
.operation-page ::v-deep .operation-create-dialog .el-radio__input.is-checked + .el-radio__label {
  color: #008b4b;
}
.status-lock-help {
  color: #d97706;
}

@media (max-width: 1200px) {
  .operation-layout {
    grid-template-columns: 1fr;
  }
  .operation-inspector {
    min-height: 340px;
  }
  .operation-filters {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .operation-filters .filter-actions {
    grid-column: 1 / -1;
    justify-content: flex-end;
  }
}
@media (max-width: 767px) {
  .operation-page {
    padding: 12px;
  }
  .operation-filters {
    grid-template-columns: 1fr;
  }
  .operation-filters .filter-actions {
    grid-column: auto;
  }
  .operation-facts,
  .operation-create-grid {
    grid-template-columns: 1fr;
  }
  .operation-facts .wide {
    grid-column: auto;
  }
  .operation-page ::v-deep .operation-create-dialog {
    width: calc(100% - 24px) !important;
    top: 4vh;
    transform: none;
  }
}
</style>

