<template>
  <span class="crm-view-controls">
    <el-button size="small" icon="el-icon-setting" :loading="initializing" @click="open">视图设置</el-button>
    <el-dialog :title="title + '视图设置'" :visible.sync="visible" append-to-body width="min(1200px, calc(100vw - 32px))" top="5vh" custom-class="crm-list-view-dialog" :close-on-click-modal="false" :close-on-press-escape="!saving" :show-close="!saving" :before-close="close">
      <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
      <el-alert v-if="warnings.length" :title="warnings.join('；')" type="warning" :closable="false" show-icon />
      <el-tabs v-model="tab">
        <el-tab-pane label="显示字段" name="columns" />
        <el-tab-pane label="筛选与排序" name="filters" />
        <el-tab-pane label="分组" name="group" />
        <el-tab-pane label="共享范围" name="share" />
      </el-tabs>
      <div class="view-name-row">
        <label>视图名称<el-input v-model.trim="draft.name" size="small" maxlength="80" placeholder="请输入视图名称" /></label>
        <label class="view-scope-label">保存范围<el-select v-model="shareKey" size="small" :disabled="saving"><el-option label="仅自己" value="personal" /><el-option v-for="scope in (metadata.share_scopes || []).filter(item => item.scope !== 'personal')" :key="scope.scope + ':' + scope.id" :value="scope.scope + ':' + scope.id" :label="scope.label || scope.name" /></el-select></label>
        <el-button size="small" @click="openPicker">选择已有视图</el-button>
      </div>
      <div v-if="tab === 'columns'" class="view-column-grid">
        <nav class="view-categories"><button v-for="item in categories" :key="item" type="button" :class="{active: category === item}" @click="category = item">{{ item }}</button></nav>
        <section class="view-fields">
          <b>可选字段 · {{ category }}</b>
          <el-input v-model.trim="fieldSearch" size="small" clearable placeholder="搜索全部字段" />
          <div class="view-field-grid"><el-checkbox v-for="field in availableFields" :key="field.key" :value="draft.configuration.columns.includes(field.key)" :disabled="field.required" @change="toggleField(field, $event)">{{ field.label }}</el-checkbox></div>
        </section>
        <section class="view-order">
          <b>主表列（拖动排序）</b>
          <div v-for="(column, index) in orderedGroups" :key="column.key" class="view-order-item" draggable="true" @dragstart="dragIndex = index" @dragover.prevent @drop.prevent="moveGroup(dragIndex,index)">
            <i class="el-icon-rank" /><span>{{ column.label }}</span><el-button type="text" icon="el-icon-top" :disabled="index === 0" :aria-label="'上移' + column.label" @click="moveGroup(index,index - 1)" /><el-button type="text" icon="el-icon-bottom" :disabled="index === orderedGroups.length - 1" :aria-label="'下移' + column.label" @click="moveGroup(index,index + 1)" />
          </div>
          <template v-if="resource === 'customers'"><b class="view-expand-title">展开行显示</b><p>线索与商机按客户分别展开，独立分页显示。</p></template>
        </section>
      </div>
      <section v-else-if="tab === 'filters'" class="view-settings-section">
        <div class="view-section-head"><b>当前筛选快照</b><el-button size="mini" @click="captureFilters">使用当前列表筛选</el-button></div>
        <div class="view-filter-values"><el-tag v-for="(value,key) in activeFilters" :key="key" type="info" closable @close="$delete(draft.configuration.filters,key)">{{ filterLabel(key) }}：{{ filterValue(key,value) }}</el-tag><span v-if="!Object.keys(activeFilters).length" class="view-muted">无额外筛选</span></div>
        <div class="view-sort-row"><label>排序字段<el-select v-model="draft.configuration.sort.field" clearable size="small" placeholder="默认排序"><el-option v-for="field in metadata.sort_fields || []" :key="field.key" :value="field.key" :label="field.label" /></el-select></label><label>排序方向<el-select v-model="draft.configuration.sort.direction" size="small"><el-option label="升序" value="asc" /><el-option label="降序" value="desc" /></el-select></label></div>
      </section>
      <section v-else-if="tab === 'group'" class="view-settings-section"><label>分组字段<el-select v-model="draft.configuration.group_by" clearable size="small" placeholder="不分组"><el-option v-for="field in metadata.group_fields || []" :key="field.key" :label="field.label" :value="field.key" /></el-select></label></section>
      <section v-else class="view-settings-section"><p>{{ shareKey === 'personal' ? '仅自己可使用此视图。' : '共享成员使用此配置时，仍按本人权限读取业务数据。' }}</p><p v-if="privateFilters.length" class="view-private-filters">共享视图不能保存：{{ privateFilters.map(filterLabel).join('、') }}。<el-button type="text" @click="removePrivateFilters">移除这些条件</el-button></p><p v-if="active && !active.can_edit">保存时将创建自己的视图，原共享视图保留。</p></section>
      <p v-if="resource === 'customers'" class="view-footnote"><i class="el-icon-info" /> 一个客户可有多条线索和商机，关联记录只在展开行显示。隐藏字段不删除资料，共享视图按查看人权限取数。</p>
      <span slot="footer" class="view-footer"><el-button size="small" :disabled="saving" @click="restoreDefault">恢复默认</el-button><el-button v-if="active && active.can_edit" size="small" :loading="saving" @click="save(true)">另存视图</el-button><el-button size="small" :disabled="saving" @click="close()">取消</el-button><el-button size="small" type="success" :loading="saving" @click="save(false)">保存视图</el-button></span>
    </el-dialog>
    <el-dialog title="选择已有视图" :visible.sync="picker.visible" append-to-body width="min(780px, calc(100vw - 32px))" top="8vh" custom-class="crm-list-view-dialog" :before-close="closePicker" :close-on-click-modal="false">
      <div class="view-picker-search"><el-input v-model.trim="picker.keyword" size="small" clearable placeholder="视图名称" @keyup.enter.native="searchViews" /><el-button size="small" type="success" @click="searchViews">查询</el-button></div>
      <el-alert v-if="picker.error" :title="picker.error" type="error" :closable="false" show-icon />
      <el-table v-loading="picker.loading" :data="picker.rows" size="small"><el-table-column prop="name" label="视图名称" min-width="170" /><el-table-column label="范围" min-width="115"><template slot-scope="{row}">{{ row.scope === 'personal' ? '仅自己' : row.scope_name }}</template></el-table-column><el-table-column prop="owner_name" label="创建人" min-width="100" /><el-table-column label="操作" width="125"><template slot-scope="{row}"><el-button type="text" size="mini" :disabled="picker.loading" @click="selectView(row)">使用</el-button><el-button v-if="row.can_delete" type="text" size="mini" class="view-delete" :disabled="picker.loading" @click="removeView(row)">删除</el-button></template></el-table-column></el-table>
      <div class="view-picker-pager"><el-pagination background layout="total, prev, pager, next" :current-page.sync="picker.page" :page-size="10" :total="picker.total" @current-change="loadViews" /></div>
      <span slot="footer"><el-button size="small" :disabled="picker.loading" @click="closePicker()">关闭</el-button></span>
    </el-dialog>
  </span>
</template>

<script>
import { getCrmViewMetadata, listCrmViews, getCrmView, createCrmView, updateCrmView, deleteCrmView, rememberedCrmView, rememberCrmView } from '@/api/erp/crmViews'
import { crmError } from '@/api/erp/crm'
import { orderedColumnGroups } from './listViewColumns'

const clone = value => JSON.parse(JSON.stringify(value))
const emptyConfiguration = () => ({ columns: [], filters: {}, sort: { field: '', direction: 'desc' }, group_by: '' })
export default {
  name: 'CrmListViewControls',
  props: {
    resource: { type: String, required: true },
    title: { type: String, required: true },
    configuration: { type: Object, required: true },
    filters: { type: Object, required: true },
    columnGroups: { type: Array, required: true },
    filterOptions: { type: Object, default: () => ({}) }
  },
  data: () => ({ metadata: {}, active: null, visible: false, initializing: false, saving: false, session: 0, disposed: false, tab: 'columns', category: '', fieldSearch: '', shareKey: 'personal', error: '', warnings: [], dragIndex: -1, draft: { name: '', configuration: emptyConfiguration() }, picker: { visible: false, keyword: '', rows: [], total: 0, page: 1, loading: false, error: '', sequence: 0 } }),
  computed: {
    categories() { return [...new Set((this.metadata.fields || []).map(field => field.category || '显示字段'))] },
    availableFields() { return (this.metadata.fields || []).filter(field => this.fieldSearch ? field.label.includes(this.fieldSearch) : (field.category || '显示字段') === this.category) },
    orderedGroups() { return orderedColumnGroups(this.draft.configuration.columns, this.columnGroups) },
    activeFilters() { return Object.fromEntries(Object.entries(this.draft.configuration.filters).filter(([,value]) => value !== '' && value !== null && value !== undefined)) },
    privateFilters() {
      return this.shareKey === 'personal' ? [] : Object.keys(this.activeFilters).filter(key => !(this.metadata.shared_filter_keys || []).includes(key));
    }
  },
  beforeDestroy() { this.disposed = true; this.session += 1; this.picker.sequence += 1 },
  methods: {
    isCurrent(session, token) { return !this.disposed && session === this.session && localStorage.getItem('erp_token') === token },
    close(done) {
      if (this.saving || this.picker.loading) return
      this.session += 1
      this.visible = false
      if (typeof done === 'function') done()
    },
    closePicker(done) {
      if (this.picker.loading) return
      this.picker.sequence += 1
      this.picker.visible = false
      if (typeof done === 'function') done()
    },
    async initialize() {
      const session = ++this.session
      const token = localStorage.getItem('erp_token')
      this.initializing = true
      try {
        const { data } = await getCrmViewMetadata({ resource: this.resource })
        if (!this.isCurrent(session,token)) return null
        this.metadata = data.data || data
        this.category = this.categories[0] || ''
        const id = rememberedCrmView(this.resource)
        if (id) {
          try {
            const { data: response } = await getCrmView(id)
            if (!this.isCurrent(session,token)) return null
            const view = response.data || response
            if (view.resource !== this.resource) throw { response: { status: 422 } }
            this.active = view
            this.warnings = view.warnings || []
            if (this.warnings.length) this.$message.warning(this.warnings.join('；'))
            return clone(view.configuration)
          } catch (error) {
            if (!this.isCurrent(session,token)) return null
            const status = error.response && error.response.status
            if (![403,404,422].includes(status)) throw error
            rememberCrmView(this.resource, null)
            this.$message.warning('原视图已不可用，已恢复默认视图')
          }
        }
        return clone(this.metadata.default_configuration || emptyConfiguration())
      } catch (error) {
        if (!this.isCurrent(session,token)) return null
        this.error = crmError(error, '视图配置加载失败，请稍后重试')
        this.$message.error(this.error)
        return null
      } finally { if (this.isCurrent(session,token)) this.initializing = false }
    },
    async open() {
      if (!this.metadata.fields) {
        const configuration = await this.initialize()
        if (!configuration) return
        this.$emit('apply', configuration)
        await this.$nextTick()
        if (this.disposed) return
      }
      this.tab = 'columns'
      this.session += 1
      this.fieldSearch = ''
      this.error = ''
      const active = this.active
      this.draft = {
        name: active ? active.name + (active.can_edit ? '' : '（副本）') : '',
        configuration: clone(this.configuration)
      }
      this.draft.configuration.sort = { field: '', direction: 'desc', ...this.draft.configuration.sort }
      this.captureFilters()
      this.shareKey = active && active.can_edit && active.scope !== 'personal' ? `${active.scope}:${active.scope_id}` : 'personal'
      this.visible = true
    },
    captureFilters() {
      if (this.saving) return
      const allowed = this.metadata.filter_keys || []
      this.draft.configuration.filters = Object.fromEntries(Object.entries(this.filters).filter(([key]) => allowed.includes(key)))
    },
    removePrivateFilters() { this.privateFilters.forEach(key => this.$delete(this.draft.configuration.filters,key)) },
    filterLabel(key) {
      return ({ tab: '列表范围', keyword: '关键词', industry: '行业', customer_grade: '客户等级', owner_name: '负责销售', status: '状态', department_legacy_id: '部门', source_channel: '来源渠道', entry_method: '录入方式', recycle_reason: '回收原因', customer_pool: '客户池', customer_id: '客户', customer_tag: '客户标签', custom_tag: '我的标签', due: '跟进排期', business_line: '业务线', stage: '阶段', expected_close_from: '预计成交起日', expected_close_to: '预计成交止日' })[key] || key
    },
    filterValue(key, value) { return (this.filterOptions[key] || {})[value] || value },
    toggleField(field, checked) {
      if (this.saving) return
      if (checked) this.draft.configuration.columns.push(field.key)
      else this.draft.configuration.columns = this.draft.configuration.columns.filter(key => key !== field.key)
    },
    moveGroup(from, to) {
      if (this.saving) return
      if (from < 0 || to < 0 || to >= this.orderedGroups.length || from === to) return
      const groups = [...this.orderedGroups]
      groups.splice(to, 0, groups.splice(from, 1)[0])
      const selected = this.draft.configuration.columns
      this.draft.configuration.columns = groups.flatMap(group => group.fields.filter(field => selected.includes(field)))
      this.dragIndex = -1
    },
    restoreDefault() {
      if (this.saving) return
      this.active = null
      this.warnings = []
      rememberCrmView(this.resource, null)
      this.$emit('apply', clone(this.metadata.default_configuration || emptyConfiguration()))
      this.visible = false
      this.session += 1
    },
    async save(asNew) {
      if (this.saving) return
      if (!this.draft.name) {
        this.error = '请输入视图名称';
        return;
      }
      if (this.privateFilters.length) {
        this.error = '请先移除共享视图不能保存的筛选条件';
        this.tab = 'share';
        return;
      }
      const session = this.session
      const token = localStorage.getItem('erp_token')
      this.saving = true
      this.error = ''
      try {
        const [scope, id] = this.shareKey.split(':')
        const body = {
          resource: this.resource,
          name: this.draft.name,
          scope,
          scope_id: id ? Number(id) : null,
          configuration: clone(this.draft.configuration)
        }
        if (!body.configuration.sort.field) body.configuration.sort = {}
        else body.configuration.sort.direction = body.configuration.sort.direction || 'desc'
        const update = !asNew && this.active && this.active.can_edit
        if (update) body.expected_version = this.active.version
        const { data } = await (update ? updateCrmView(this.active.id, body) : createCrmView(body))
        if (!this.isCurrent(session,token)) return
        this.active = data.data || data
        this.warnings = this.active.warnings || []
        rememberCrmView(this.resource, this.active.id)
        this.$emit('apply', clone(this.active.configuration))
        this.visible = false
        this.$message.success('视图已保存')
      } catch (error) { if (this.isCurrent(session,token)) this.error = crmError(error, '视图保存失败') }
      finally {
        if (this.isCurrent(session,token)) this.saving = false;
      }
    },
    openPicker() { if (this.saving) return; this.picker.visible = true; this.picker.page = 1; this.loadViews() },
    searchViews() { this.picker.page = 1; this.loadViews() },
    async loadViews() {
      const session = this.session
      const token = localStorage.getItem('erp_token')
      const sequence = ++this.picker.sequence
      this.picker.loading = true
      this.picker.error = ''
      try {
        const { data } = await listCrmViews({ resource: this.resource, keyword: this.picker.keyword, page: this.picker.page, per_page: 10 })
        if (sequence !== this.picker.sequence || !this.isCurrent(session,token)) return
        this.picker.rows = data.data || []
        this.picker.total = data.total || 0
      } catch (error) { if (sequence === this.picker.sequence && this.isCurrent(session,token)) this.picker.error = crmError(error, '视图列表加载失败') }
      finally {
        if (sequence === this.picker.sequence && this.isCurrent(session,token)) this.picker.loading = false;
      }
    },
    async selectView(row) {
      if (this.picker.loading) return
      const session = this.session
      const token = localStorage.getItem('erp_token')
      this.picker.loading = true
      try {
        const { data } = await getCrmView(row.id)
        if (!this.isCurrent(session,token)) return
        const view = data.data || data
        this.active = view
        this.warnings = view.warnings || []
        rememberCrmView(this.resource, view.id)
        this.$emit('apply', clone(view.configuration))
        this.picker.visible = false
        this.visible = false
        if (this.warnings.length) this.$message.warning(this.warnings.join('；'))
      } catch (error) { if (this.isCurrent(session,token)) this.picker.error = crmError(error, '视图不可用，请刷新后重试') }
      finally {
        if (this.isCurrent(session,token)) this.picker.loading = false;
      }
    },
    async removeView(row) {
      const session = this.session
      const token = localStorage.getItem('erp_token')
      try {
        await this.$confirm(`删除视图“${row.name}”？`, '删除视图', { type: 'warning', confirmButtonText: '删除', cancelButtonText: '取消' })
        if (!this.isCurrent(session,token)) return
        await deleteCrmView(row.id, { expected_version: row.version })
        if (!this.isCurrent(session,token)) return
        if (this.active && this.active.id === row.id) {
          this.active = null
          rememberCrmView(this.resource, null)
          this.$emit('apply', clone(this.metadata.default_configuration || emptyConfiguration()))
        }
        if (this.picker.rows.length === 1 && this.picker.page > 1) this.picker.page -= 1
        await this.loadViews()
      } catch (error) { if (this.isCurrent(session,token) && error !== 'cancel' && error !== 'close') this.picker.error = crmError(error, '删除失败') }
    }
  }
}
</script>

<style scoped>
.crm-view-controls {
  display: inline-flex;
  min-width: 0;
}
.view-name-row {
  display: flex;
  align-items: center;
  gap: 12px;
  margin: 0 0 14px;
}
.view-name-row label {
  display: flex;
  align-items: center;
  gap: 8px;
  flex: 1;
  min-width: 0;
  white-space: nowrap;
}
.view-name-row .el-input {
  flex: 1;
  min-width: 0;
}
.view-name-row .view-scope-label {
  flex: 0 1 240px;
}
.view-name-row .el-select {
  min-width: 0;
}
.view-column-grid {
  display: grid;
  grid-template-columns: 170px minmax(0, 1fr) 300px;
  gap: 14px;
  min-height: 380px;
}
.view-column-grid > * {
  border: 1px solid #e2e8f0;
  border-radius: 7px;
  box-sizing: border-box;
  min-width: 0;
  padding: 14px;
}
.view-categories {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.view-categories button {
  border: 0;
  background: transparent;
  color: #334155;
  text-align: left;
  padding: 9px 4px;
  cursor: pointer;
  border-radius: 5px;
}
.view-categories button.active {
  color: #008b4b;
  background: #f0fdf4;
  font-weight: 600;
}
.view-fields > b, .view-order > b {
  display: block;
  color: #1e293b;
  margin-bottom: 12px;
}
.view-field-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 14px 12px;
  margin-top: 18px;
}
.view-field-grid .el-checkbox {
  margin: 0;
  min-width: 0;
  white-space: normal;
}
.view-order-item {
  display: flex;
  align-items: center;
  gap: 7px;
  min-height: 31px;
  cursor: grab;
  color: #334155;
}
.view-order-item > span {
  flex: 1;
  min-width: 0;
}
.view-order-item > i {
  color: #94a3b8;
}
.view-order-item .el-button {
  padding: 3px 0;
  margin: 0;
  color: #008b4b;
}
.view-order-item .el-button.is-disabled {
  color: #cbd5e1;
}
.view-expand-title {
  margin-top: 20px;
}
.view-order p, .view-muted {
  color: #64748b;
  line-height: 1.7;
}
.view-footnote {
  margin: 14px 0 0;
  padding: 10px 12px;
  border-radius: 5px;
  background: #f8fafc;
  color: #475569;
  font-size: 12px;
}
.view-settings-section {
  min-height: 310px;
  padding: 20px 4px;
  box-sizing: border-box;
}
.view-settings-section label {
  display: inline-flex;
  align-items: center;
  gap: 10px;
}
.view-section-head {
  display: flex;
  align-items: center;
  gap: 12px;
}
.view-filter-values {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  padding: 18px 0 28px;
}
.view-sort-row {
  display: flex;
  flex-wrap: wrap;
  gap: 22px;
}
.view-picker-search {
  display: flex;
  gap: 10px;
  margin-bottom: 14px;
}
.view-picker-pager {
  display: flex;
  justify-content: flex-end;
  padding-top: 14px;
}
.view-delete {
  color: #ef4444;
}
.view-private-filters {
  color: #b45309;
}
.view-footer {
  display: inline-flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 10px;
}
.view-footer .el-button {
  margin: 0;
}
@media (max-width: 1000px) {
  .view-column-grid {
    grid-template-columns: 135px minmax(0, 1fr);
  }
  .view-order {
    grid-column: 1 / -1;
  }
  .view-name-row {
    flex-wrap: wrap;
  }
}
@media (max-width: 600px) {
  .view-column-grid {
    grid-template-columns: minmax(0, 1fr);
  }
  .view-categories {
    flex-direction: row;
    flex-wrap: wrap;
  }
  .view-field-grid {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>

<style>
.crm-list-view-dialog {
  max-height: 90vh;
  display: flex;
  flex-direction: column;
  border-radius: 10px;
}
.crm-list-view-dialog .el-dialog__header {
  padding: 20px 22px 16px;
  border-bottom: 1px solid #eef2f7;
}
.crm-list-view-dialog .el-dialog__body {
  overflow-y: auto;
  min-height: 0;
  padding: 18px 22px;
}
.crm-list-view-dialog .el-dialog__footer {
  border-top: 1px solid #eef2f7;
  padding: 14px 22px;
}
.crm-list-view-dialog .el-tabs__item.is-active, .crm-list-view-dialog .el-tabs__item:hover {
  color: #008b4b;
}
.crm-list-view-dialog .el-tabs__active-bar {
  background: #008b4b;
}
.crm-list-view-dialog .el-pagination.is-background .el-pager li:not(.disabled).active {
  background: #008b4b;
}
</style>
