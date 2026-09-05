<template>
  <section class="production-page operation-page">
    <div class="operation-layout">
      <main class="operation-main" v-loading="loading">
        <div class="page-heading">
          <div><p class="eyebrow">生产管理 / 生产基础</p><h1>工序管理</h1><p>维护生产路线所引用的正式工序主数据</p></div>
          <el-button v-if="$can('production.operation.create')" type="success" icon="el-icon-plus" @click="openCreate">新增工序</el-button>
        </div>

        <section class="filter-card operation-filters">
          <label><span>工序编码 / 名称</span><el-input v-model.trim="filters.keyword" clearable prefix-icon="el-icon-search" placeholder="请输入工序编码或名称" @keyup.enter.native="search" /></label>
          <label><span>状态</span><el-select v-model="filters.status" clearable placeholder="全部"><el-option label="已启用" value="enabled"/><el-option label="已停用" value="disabled"/></el-select></label>
          <label><span>引用状态</span><el-select v-model="filters.reference_status" clearable placeholder="全部"><el-option label="被生效路线引用" value="referenced"/><el-option label="未被生效路线引用" value="unreferenced"/></el-select></label>
          <div class="filter-actions"><el-button type="success" @click="search">查询</el-button><el-button @click="reset">重置</el-button></div>
        </section>

        <section class="table-card operation-table-card">
          <el-table :data="rows" border highlight-current-row :row-class-name="rowClass" @row-click="selectRow">
            <el-table-column prop="operation_no" label="工序编码" width="110"/>
            <el-table-column prop="operation_name" label="工序名称" min-width="84"/>
            <el-table-column label="状态" width="60"><template slot-scope="s"><el-tag size="mini" :type="s.row.status==='enabled'?'success':'info'">{{ s.row.status==='enabled'?'启用':'停用' }}</el-tag></template></el-table-column>
            <el-table-column prop="sort" label="排序" width="48" align="center"/>
            <el-table-column label="使用中的路线数量" width="92" align="center"><template slot-scope="s"><button class="reference-count" :class="{ active:Number(s.row.active_routing_count)>0 }" @click.stop="selectRow(s.row)">{{ Number(s.row.active_routing_count || 0) }}</button></template></el-table-column>
            <el-table-column prop="description" label="说明" min-width="128"><template slot-scope="s"><span class="description-text">{{ s.row.description || '-' }}</span></template></el-table-column>
            <el-table-column label="更新时间" width="104"><template slot-scope="s"><span class="date-text">{{ time(s.row.updated_at) }}</span></template></el-table-column>
            <el-table-column label="操作" width="116"><template slot-scope="s"><span class="row-actions" @click.stop><el-button type="text" @click="selectRow(s.row)">查看</el-button><el-button v-if="$can('production.operation.edit')" type="text" @click="openEdit(s.row)">编辑</el-button><el-button v-if="$can('production.operation.toggle')" type="text" :class="s.row.status==='enabled'?'danger-link':'success-link'" @click="toggle(s.row)">{{ s.row.status==='enabled'?'停用':'启用' }}</el-button></span></template></el-table-column>
          </el-table>
          <div class="table-footer"><span>共 {{ total }} 条</span><el-pagination background layout="sizes, prev, pager, next" :current-page="page" :page-size="perPage" :page-sizes="[10,15,20]" :total="total" @current-change="changePage" @size-change="changePageSize"/></div>
        </section>
      </main>

      <aside class="operation-inspector" v-loading="detailLoading">
        <template v-if="selected">
          <header><h2>工序详情</h2><el-button type="text" icon="el-icon-close" aria-label="关闭工序详情" @click="selected=null"/></header>
          <dl class="operation-facts"><div><dt>工序编码</dt><dd>{{ selected.operation_no }}</dd></div><div><dt>工序名称</dt><dd>{{ selected.operation_name }}</dd></div><div><dt>状态</dt><dd><el-tag size="mini" :type="selected.status==='enabled'?'success':'info'">{{ selected.status==='enabled'?'启用':'停用' }}</el-tag></dd></div><div><dt>排序</dt><dd>{{ selected.sort }}</dd></div><div><dt>使用中的路线数量</dt><dd>{{ selected.active_routing_count || 0 }}</dd></div><div class="wide"><dt>说明</dt><dd>{{ selected.description || '-' }}</dd></div><div><dt>更新时间</dt><dd>{{ time(selected.updated_at) }}</dd></div><div><dt>更新人</dt><dd>{{ selected.updated_by_legacy_id ? `用户 #${selected.updated_by_legacy_id}` : '-' }}</dd></div></dl>
          <section class="routing-references"><h3>已生效路线引用</h3><p>该工序当前被以下已生效路线引用：</p><el-table :data="selected.active_routings || []" size="mini" border empty-text="当前没有已生效路线引用"><el-table-column prop="routing_no" label="路线编码" width="92"/><el-table-column prop="routing_name" label="路线名称" min-width="86"/><el-table-column label="版本" width="48"><template slot-scope="s">V{{ s.row.version }}</template></el-table-column><el-table-column label="产出物料" min-width="90"><template slot-scope="s">{{ s.row.output_item && (s.row.output_item.item_name || s.row.output_item.item_code) || '-' }}</template></el-table-column></el-table></section>
          <el-alert v-if="Number(selected.active_routing_count)>0 && selected.status==='enabled'" title="该工序正被已生效路线引用，暂不可停用" type="warning" :closable="false" show-icon/>
          <div class="inspector-actions"><el-button @click="$router.push(`/production/operations/${selected.id}`)">查看</el-button><el-button v-if="$can('production.operation.edit')" type="primary" plain @click="openEdit(selected)">编辑</el-button><el-button v-if="$can('production.operation.toggle')" type="danger" plain :disabled="selected.status!=='enabled' || Number(selected.active_routing_count)>0" @click="toggle(selected)">停用</el-button><el-button v-if="$can('production.operation.toggle')" type="success" plain :disabled="selected.status==='enabled'" @click="toggle(selected)">启用</el-button></div>
        </template>
        <el-empty v-else description="选择一条工序查看详情" :image-size="72"/>
      </aside>
    </div>

    <el-dialog :title="dialogMode==='edit'?'编辑工序':'新增工序'" :visible.sync="createDialog" width="540px" custom-class="operation-create-dialog" :close-on-click-modal="false" :close-on-press-escape="!createSaving" :show-close="!createSaving" @close="closeCreate">
      <div v-loading="createLoading || createSaving" class="operation-create-body">
        <el-form :key="createFormKey" ref="createForm" :model="createForm" :rules="createRules" label-position="top">
          <div class="operation-create-grid">
            <el-form-item label="工序编码"><el-input v-model="createForm.operation_no" disabled /></el-form-item>
            <el-form-item label="工序名称" prop="operation_name"><el-input v-model.trim="createForm.operation_name" maxlength="160" placeholder="请输入工序名称" /></el-form-item>
            <el-form-item label="排序" prop="sort"><el-input-number v-model="createForm.sort" controls-position="right" :min="0" :max="999999" /><p class="field-help">数值越小越靠前</p></el-form-item>
            <el-form-item label="状态" prop="status"><el-radio-group v-model="createForm.status" :disabled="statusRadioDisabled"><el-radio label="enabled">启用</el-radio><el-radio label="disabled">停用</el-radio></el-radio-group><p v-if="statusLockReason" class="field-help status-lock-help"><i class="el-icon-warning"/> {{ statusLockReason }}</p></el-form-item>
          </div>
          <el-form-item label="说明"><el-input v-model="createForm.description" type="textarea" :rows="3" maxlength="2000" show-word-limit placeholder="请输入说明（选填）" /></el-form-item>
        </el-form>
      </div>
      <template slot="footer"><el-button :disabled="createSaving" @click="createDialog=false">取消</el-button><el-button type="success" :loading="createSaving" :disabled="createLoading" @click="saveCreate">保存</el-button></template>
    </el-dialog>
  </section>
</template>

<script>
import { listProductionOperations, getProductionOperation, createProductionOperation, updateProductionOperation, enableProductionOperation, disableProductionOperation } from '../../../api/erp/production'
import { reserveForCreatePage, clearCreatePageReservation } from '../../../utils/documentNumberReservation'

const blankCreateForm = () => ({ operation_no: '', operation_name: '', sort: 0, status: 'enabled', description: '' })

export default {
  name: 'ProductionOperationList',
  data: () => ({ loading: false, detailLoading: false, rows: [], selected: null, total: 0, page: 1, perPage: 10, filters: { keyword: '', status: '', reference_status: '' }, createDialog: false, createLoading: false, createSaving: false, createReservation: null, createFormKey: 0, dialogMode: 'create', createForm: blankCreateForm(), createRules: { operation_name: [{ required: true, message: '请输入工序名称', trigger: 'blur' }] } }),
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
      try {
        const response = await getProductionOperation(requestedId)
        if (this.selected && this.selected.id === requestedId) this.selected = response.data.data
      } catch (error) { this.$message.error(error.userMessage || '工序详情加载失败') } finally { if (this.selected && this.selected.id === requestedId) this.detailLoading = false }
    },
    search() { this.page = 1; this.load() },
    reset() { this.filters = { keyword: '', status: '', reference_status: '' }; this.search() },
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
        this.createForm = { ...blankCreateForm(), ...response.data.data }
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
        this.createSaving = true
        try {
          const response = this.dialogMode === 'edit'
            ? await updateProductionOperation(this.createForm.id, {
              client_command_id: `operation-update-${this.createForm.id}-${this.createForm.business_version}`,
              expected_version: this.createForm.business_version,
              operation_name: this.createForm.operation_name,
              sort: this.createForm.sort,
              status: this.createForm.status,
              description: this.createForm.description || null
            })
            : await createProductionOperation({
              client_command_id: `operation-create-${this.createReservation.creation_session_id}`,
              creation_session_id: this.createReservation.creation_session_id,
              reservation_token: this.createReservation.reservation_token,
              operation_name: this.createForm.operation_name,
              sort: this.createForm.sort,
              status: this.createForm.status,
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
    async toggle(row) {
      try {
        await this.$confirm(`确认${row.status === 'enabled' ? '停用' : '启用'}工序“${row.operation_name}”？`, '状态确认', { type: 'warning' })
        const request = row.status === 'enabled' ? disableProductionOperation : enableProductionOperation
        await request(row.id, { client_command_id: `operation-toggle-${row.id}-${Date.now()}`, expected_version: row.business_version })
        this.$message.success('工序状态已更新')
        await this.load()
      } catch (error) { if (error !== 'cancel' && error !== 'close') this.$message.error(error.userMessage || '操作失败') }
    },
    time(value) { return value ? String(value).replace('T', ' ').replace(/\.\d+Z$/, '').slice(0, 16) : '-' }
  }
}
</script>

<style scoped src="./production-master.css"></style>
<style scoped>
.operation-page{padding:16px 18px}.operation-layout{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:10px;align-items:stretch}.operation-main,.operation-inspector{min-width:0;background:#fff;border:1px solid #e4e9ef;border-radius:5px}.operation-main{padding:17px 16px 12px}.operation-main .page-heading{align-items:center;margin-bottom:15px}.operation-main .page-heading h1{font-size:22px}.operation-filters{display:grid;grid-template-columns:minmax(190px,1.1fr) minmax(150px,.7fr) minmax(170px,.8fr) auto;gap:14px;align-items:end;padding:14px;margin-bottom:12px}.operation-filters label>span{display:block;margin-bottom:7px;color:#526276;font-size:12px;font-weight:600}.operation-filters .el-select{width:100%}.operation-filters .filter-actions{margin:0;white-space:nowrap}.operation-table-card{padding:0;border-radius:4px;overflow:hidden}.operation-table-card ::v-deep .el-table td{height:auto;padding:9px 0}.operation-table-card ::v-deep .el-table .cell{padding-left:8px;padding-right:8px;overflow-wrap:anywhere}.operation-table-card ::v-deep .selected-operation-row td{background:#f0faf4!important}.description-text,.date-text{display:block;white-space:normal;line-height:1.45}.reference-count{border:0;background:transparent;color:#607084;cursor:pointer}.reference-count.active{color:#008b4b;font-weight:700}.row-actions{display:flex;align-items:center;white-space:nowrap}.row-actions .el-button{margin-left:6px;padding-left:0;padding-right:0}.row-actions .el-button:first-child{margin-left:0}.danger-link{color:#e64b52}.success-link{color:#008b4b}.operation-table-card .table-footer{padding:12px 13px}.operation-inspector{display:flex;flex-direction:column;padding:0 16px 16px}.operation-inspector>header{display:flex;align-items:center;justify-content:space-between;height:56px;margin:0 -16px 12px;padding:0 16px;border-bottom:1px solid #e9edf1}.operation-inspector h2{margin:0;font-size:17px;color:#1d3048}.operation-facts{display:grid;grid-template-columns:1fr 1fr;gap:14px 20px;margin:0;padding:6px 0 18px;border-bottom:1px solid #e9edf1}.operation-facts div{min-width:0}.operation-facts .wide{grid-column:1/-1}.operation-facts dt{margin-bottom:5px;color:#8895a5;font-size:12px}.operation-facts dd{margin:0;color:#27384e;line-height:1.5;overflow-wrap:anywhere}.routing-references{margin-top:18px}.routing-references h3{margin:0 0 7px;font-size:14px;color:#1d3048}.routing-references>p{margin:0 0 10px;color:#7d8998;font-size:12px}.routing-references ::v-deep .el-table .cell{padding-left:7px;padding-right:7px;white-space:normal;line-height:1.35;overflow-wrap:anywhere}.operation-inspector .el-alert{margin-top:16px}.inspector-actions{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:auto;padding-top:18px}.inspector-actions .el-button{min-width:0;margin:0;padding-left:4px;padding-right:4px}
.operation-create-body{min-height:0}.operation-create-grid{display:grid;grid-template-columns:1fr 1fr;gap:0 16px}.operation-create-grid .el-input-number{width:100%}.field-help{margin:6px 0 0;color:#8a96a6;font-size:12px;line-height:1.3}.operation-page ::v-deep .operation-create-dialog{top:50%;margin:0 auto!important;transform:translateY(-50%);border-radius:5px}.operation-page ::v-deep .operation-create-dialog .el-dialog__header{padding:18px 26px 13px;border-bottom:1px solid #e9edf1}.operation-page ::v-deep .operation-create-dialog .el-dialog__title{font-size:18px;font-weight:600;color:#1d3048}.operation-page ::v-deep .operation-create-dialog .el-dialog__body{padding:16px 26px 6px}.operation-page ::v-deep .operation-create-dialog .el-dialog__footer{padding:13px 26px 18px;border-top:1px solid #edf0f3}.operation-page ::v-deep .operation-create-dialog .el-form-item{margin-bottom:15px}.operation-page ::v-deep .operation-create-dialog .el-form-item__label{padding-bottom:6px;color:#35465c;font-weight:600;line-height:20px}.operation-page ::v-deep .operation-create-dialog .el-textarea__inner{resize:none}
.operation-page ::v-deep .operation-create-dialog .el-radio-group{display:flex;align-items:center;height:40px}.status-lock-help{color:#d89020;white-space:normal}.status-lock-help i{margin-right:2px}
.operation-page ::v-deep .operation-create-dialog .el-radio__input.is-checked .el-radio__inner{background:#008b4b;border-color:#008b4b}.operation-page ::v-deep .operation-create-dialog .el-radio__input.is-checked+.el-radio__label{color:#008b4b}
@media(max-width:1200px){.operation-layout{grid-template-columns:1fr}.operation-inspector{min-height:340px}.operation-filters{grid-template-columns:repeat(3,minmax(0,1fr))}.operation-filters .filter-actions{grid-column:1/-1;justify-content:flex-end}.inspector-actions{margin-top:10px}}
@media(max-width:767px){.operation-page{padding:12px}.operation-main{padding:14px 12px}.operation-filters{grid-template-columns:1fr}.operation-filters .filter-actions{grid-column:auto}.operation-table-card{overflow-x:auto}.operation-table-card .el-table{min-width:1120px}.operation-table-card .table-footer{min-width:760px}.operation-inspector{padding-bottom:12px}.operation-facts,.operation-create-grid{grid-template-columns:1fr}.operation-facts .wide{grid-column:auto}.operation-page ::v-deep .operation-create-dialog{top:0;width:calc(100% - 24px)!important;margin-top:4vh!important;transform:none}.operation-create-body{min-height:510px}}
</style>
