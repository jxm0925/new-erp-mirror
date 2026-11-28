<template>
  <section class="process-catalog-panel">
    <div v-if="launchers" class="catalog-launchers">
      <el-button type="success" plain size="small" @click="open('stages',{mode:'manage'})">维护生产阶段</el-button>
      <el-button type="success" plain size="small" @click="open('packaging-schemes',{mode:'manage'})">维护包装方案</el-button>
    </div>
    <el-dialog :title="title" :visible.sync="visible" width="780px" append-to-body custom-class="production-process-dialog" :close-on-click-modal="false" @closed="close">
      <div class="catalog-toolbar">
        <el-input v-model="keyword" clearable size="small" prefix-icon="el-icon-search" placeholder="搜索编码或名称" @keyup.enter.native="search" @clear="search" />
        <el-select v-if="mode==='manage'" v-model="status" size="small" clearable placeholder="全部状态" @change="search"><el-option label="启用" value="enabled" /><el-option label="停用" value="disabled" /></el-select>
        <el-button type="success" plain size="small" @click="search">查询</el-button>
        <el-button v-if="canManage" type="success" size="small" @click="edit(null)">新增</el-button>
      </div>
      <el-table :data="rows" border size="small" v-loading="loading" highlight-current-row @row-click="selectRow">
        <el-table-column v-if="mode==='select'" label="选择" width="60"><template slot-scope="scope"><el-radio :value="selected && selected.id" :label="scope.row.id" @change="selectRow(scope.row)">&nbsp;</el-radio></template></el-table-column>
        <el-table-column prop="code" label="编码" min-width="140" />
        <el-table-column prop="name" label="名称" min-width="140" />
        <el-table-column v-if="catalogType==='packaging-schemes'" label="耗材项数" width="90" align="right"><template slot-scope="scope">{{ scope.row.items_count !== undefined ? scope.row.items_count : (scope.row.items || []).length }}</template></el-table-column>
        <el-table-column label="状态" width="80"><template slot-scope="scope"><el-tag size="mini" :type="scope.row.status==='enabled'?'success':'info'">{{ scope.row.status==='enabled'?'启用':'停用' }}</el-tag></template></el-table-column>
        <el-table-column prop="sort" label="排序" width="75" />
        <el-table-column v-if="canManage" label="维护" width="110"><template slot-scope="scope"><el-button type="text" @click.stop="edit(scope.row)">编辑</el-button><el-button type="text" class="catalog-delete" @click.stop="remove(scope.row)">删除</el-button></template></el-table-column>
      </el-table>
      <div class="catalog-footer">
        <el-pagination layout="prev, pager, next, total" :current-page="page" :page-size="10" :pager-count="5" :total="total" @current-change="changePage" />
        <span v-if="mode==='select' && selected">已选：{{ selected.name }}</span>
      </div>
      <span slot="footer"><el-button @click="visible=false">{{ mode==='select'?'取消':'关闭' }}</el-button><el-button v-if="mode==='select'" type="success" :loading="confirming" :disabled="loading || confirming || !selected || selected.status==='disabled'" @click="confirm">确认选择</el-button></span>
    </el-dialog>
    <el-dialog :title="editForm.id?'编辑'+noun:'新增'+noun" :visible.sync="editorVisible" :width="catalogType==='packaging-schemes'?'960px':'540px'" append-to-body custom-class="production-process-dialog" :close-on-click-modal="false" :before-close="closeEditor">
      <el-form ref="catalogForm" :model="editForm" :rules="rules" label-position="top" size="small">
        <div class="catalog-form-grid">
          <el-form-item label="编码" prop="code"><el-input v-model.trim="editForm.code" maxlength="60" placeholder="字母、数字、下划线或连字符" /></el-form-item>
          <el-form-item label="名称" prop="name"><el-input v-model.trim="editForm.name" maxlength="120" /></el-form-item>
          <el-form-item label="排序"><el-input-number v-model="editForm.sort" :min="0" :max="999999" :precision="0" /></el-form-item>
          <el-form-item label="状态"><el-select v-model="editForm.status"><el-option label="启用" value="enabled" /><el-option label="停用" value="disabled" /></el-select></el-form-item>
        </div>
        <el-form-item label="说明"><el-input v-model="editForm.description" type="textarea" :rows="3" maxlength="2000" show-word-limit /></el-form-item>
        <section v-if="catalogType==='packaging-schemes'" class="packaging-standard-items">
          <div class="packaging-items-head">
            <h3>标准包装材料清单（选路线包装方案时自动带出）</h3>
            <el-button type="success" plain size="small" icon="el-icon-plus" @click="addPackagingItem">新增材料</el-button>
          </div>
          <el-table :data="editForm.items" size="small" border empty-text="暂无标准包装材料">
            <el-table-column label="包装材料" min-width="250"><template slot-scope="scope">
              <paged-record-select :value="scope.row.item_id" entity="items" category-type="item" management-scope="factory" :params="{management_scope:'factory',status:'enabled'}" :selected-rows="scope.row.item?[scope.row.item]:[]" title="选择标准包装材料" placeholder="选择启用的工厂物料" :disabled="saving" @select="selectPackagingItem(scope.row,$event)" />
            </template></el-table-column>
            <el-table-column label="单台用量" min-width="130"><template slot-scope="scope"><el-input v-model.trim="scope.row.qty_per_unit" :disabled="saving" placeholder="大于 0" /></template></el-table-column>
            <el-table-column label="基本单位" min-width="90"><template slot-scope="scope">{{ itemUnit(scope.row.item) }}</template></el-table-column>
            <el-table-column label="备注" min-width="170"><template slot-scope="scope"><el-input v-model="scope.row.remark" :disabled="saving" maxlength="255" /></template></el-table-column>
            <el-table-column label="操作" width="65"><template slot-scope="scope"><el-button type="text" class="catalog-delete" :disabled="saving" @click="editForm.items.splice(scope.$index,1)">删除</el-button></template></el-table-column>
          </el-table>
        </section>
      </el-form>
      <span slot="footer"><el-button :disabled="saving" @click="closeEditor()">取消</el-button><el-button type="success" :loading="saving" @click="save">保存</el-button></span>
    </el-dialog>
  </section>
</template>

<script>
import { listProcessCatalog, getProcessCatalog, saveProcessCatalog, deleteProcessCatalog } from '../../../api/erp/production-process'
import PagedRecordSelect from '@/components/common/PagedRecordSelect.vue'

const freshForm = () => ({ id: null, code: '', name: '', status: 'enabled', sort: 0, description: '', business_version: 1, items: [] })
const newCreationId = () => 'catalog-new-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10)

export default {
  name: 'ProductionProcessCatalogPanel',
  components: { PagedRecordSelect },
  props: { launchers: { type: Boolean, default: true } },
  data: () => ({ visible: false, loading: false, confirming: false, saving: false, editorVisible: false, catalogType: 'stages', mode: 'manage',
    rows: [], selected: null, keyword: '', status: '', page: 1, total: 0, editForm: freshForm(), creationId: '', requestSequence: 0,
    rules: { code: [{ required: true, message: '请输入档案编码', trigger: 'blur' }, { pattern: /^[A-Za-z0-9_-]+$/, message: '编码仅允许字母、数字、下划线和连字符', trigger: 'blur' }],
      name: [{ required: true, message: '请输入名称', trigger: 'blur' }] } }),
  computed: {
    noun() { return this.catalogType === 'stages' ? '生产阶段' : '包装方案' },
    title() { return (this.mode === 'select' ? '选择' : '维护') + this.noun },
    canManage() { return this.$can('production.routing.create') || this.$can('production.routing.edit') }
  },
  methods: {
    open(type, options = {}) {
      this.close()
      this.catalogType = type === 'packaging-schemes' ? type : 'stages'
      this.mode = options.mode || 'select'
      this.selected = options.selected || null
      this.status = this.mode === 'select' ? 'enabled' : ''
      this.visible = true
      return this.load()
    },
    close() {
      this.requestSequence++
      this.visible = false; this.rows = []; this.keyword = ''; this.page = 1; this.total = 0; this.status = ''; this.selected = null
      this.loading = false
      if (!this.saving) { this.editorVisible = false; this.editForm = freshForm(); this.creationId = '' }
    },
    async load() {
      const sequence = ++this.requestSequence
      this.loading = true
      try {
        const response = await listProcessCatalog(this.catalogType, { page: this.page, per_page: 10, keyword: this.keyword.trim(), status: this.status || undefined })
        if (sequence !== this.requestSequence || !this.visible) return
        this.rows = response.data.data || []; this.total = Number(response.data.total || 0)
        if (this.selected) { const current = this.rows.find(row => Number(row.id) === Number(this.selected.id)); if (current) this.selected = current }
      } catch (error) { if (sequence === this.requestSequence) this.$message.error(error.userMessage || '工艺档案加载失败') }
      finally { if (sequence === this.requestSequence) this.loading = false }
    },
    search() { this.page = 1; return this.load() },
    changePage(page) { this.page = page; return this.load() },
    selectRow(row) { if (this.mode === 'select' && row.status === 'enabled') this.selected = row },
    async confirm() {
      if (this.loading || this.confirming || !this.selected || this.selected.status === 'disabled') return
      const type = this.catalogType; const id = this.selected.id; const sequence = this.requestSequence
      this.confirming = true
      try {
        const needsDetail = !this.selected.status || (type === 'packaging-schemes' && !Array.isArray(this.selected.items))
        const row = needsDetail ? (await getProcessCatalog(type, id)).data.data : this.selected
        if (!this.visible || sequence !== this.requestSequence || this.catalogType !== type || Number(this.selected?.id) !== Number(id)) return
        if (row.status !== 'enabled') return this.$message.warning('所选档案已停用，请刷新后重选')
        this.$emit('select', { type, row }); this.visible = false
      } catch (error) { this.$message.error(error.userMessage || '包装方案材料加载失败，请重试') }
      finally { this.confirming = false }
    },
    edit(row) {
      if (!this.canManage) return
      this.editForm = row ? { ...freshForm(), ...row, code: row.code || '', name: row.name || '', description: row.description || '', sort: Number(row.sort || 0), status: row.status || 'enabled',
        items: (row.items || []).map(line => ({ item_id: line.item_id, qty_per_unit: line.qty_per_unit, remark: line.remark || '', item: line.item })) } : freshForm()
      this.creationId = newCreationId(); this.editorVisible = true
      this.$nextTick(() => { if (this.$refs.catalogForm) this.$refs.catalogForm.clearValidate() })
    },
    closeEditor(done) {
      if (this.saving) return
      this.editorVisible = false; this.editForm = freshForm(); this.creationId = ''
      if (typeof done === 'function') done()
    },
    addPackagingItem() { if (!this.saving) this.editForm.items.push({ item_id: null, qty_per_unit: '', remark: '', item: null }) },
    selectPackagingItem(line, item) {
      if (this.saving) return
      if (item && this.editForm.items.some(other => other !== line && Number(other.item_id) === Number(item.id))) return this.$message.warning('标准包装材料不能重复')
      line.item_id = item ? item.id : null; line.item = item || null
    },
    itemUnit(item) {
      const unit = item && (item.unit?.standard_unit || item.unit?.standardUnit || item.unit)
      return unit?.symbol || unit?.unit_name || unit?.unit_code || item?.unit_name || '—'
    },
    async save() {
      if (this.saving || !this.canManage || !await this.$refs.catalogForm.validate().catch(() => false)) return
      const id = this.editForm.id; const type = this.catalogType
      const payload = { code: this.editForm.code.trim(), name: this.editForm.name.trim(), status: this.editForm.status, sort: this.editForm.sort,
        description: String(this.editForm.description || '').trim() || null }
      if (type === 'packaging-schemes') {
        if (this.editForm.items.some(line => !line.item_id || !/^\d{1,10}(?:\.\d{1,8})?$/.test(String(line.qty_per_unit)) || Number(line.qty_per_unit) <= 0)) return this.$message.warning('请选择每行包装材料，并填写大于 0 的单台用量（最多 8 位小数）')
        if (new Set(this.editForm.items.map(line => Number(line.item_id))).size !== this.editForm.items.length) return this.$message.warning('标准包装材料不能重复')
        payload.items = this.editForm.items.map(line => ({ item_id: line.item_id, qty_per_unit: line.qty_per_unit, remark: String(line.remark || '').trim() || null }))
      }
      if (id) payload.expected_version = this.editForm.business_version
      this.saving = true
      try {
        const response = await saveProcessCatalog(type, id, payload, this.creationId)
        const row = response.data.data
        if (this.selected && Number(this.selected.id) === Number(row.id)) this.selected = row
        this.$message.success(this.noun + '已保存'); this.editorVisible = false; this.editForm = freshForm(); this.creationId = ''
        this.$emit('updated', { type, row })
        await this.load()
      } catch (error) { this.$message.error(error.userMessage || '保存失败，请重试原操作') }
      finally { this.saving = false }
    },
    async remove(row) {
      if (!this.canManage) return
      try {
        await this.$confirm(`确定删除${this.noun}「${row.name}」？工艺路线用过的不能删，可以改为停用。`, '删除' + this.noun, { type: 'warning' })
      } catch (_) { return }
      try {
        await deleteProcessCatalog(this.catalogType, row.id)
        this.$message.success(this.noun + '已删除')
        if (this.selected && Number(this.selected.id) === Number(row.id)) this.selected = null
        this.$emit('updated', { type: this.catalogType, row: null, deletedId: row.id })
        if (this.rows.length === 1 && this.page > 1) this.page--
        await this.load()
      } catch (error) { this.$message.error(error.userMessage || '删除失败') }
    }
  }
}
</script>
<style scoped>
.packaging-standard-items {
  min-width: 0;
}
.packaging-items-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 12px;
}
.packaging-items-head h3 {
  margin: 0;
  color: #334155;
  font-size: 14px;
  line-height: 1.6;
}
.catalog-launchers,.catalog-toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.catalog-launchers .el-button{margin:0}.catalog-toolbar{margin-bottom:14px}.catalog-toolbar .el-input{flex:1;min-width:160px}.catalog-toolbar .el-select{width:120px}.catalog-delete{color:#ef4444}.catalog-footer{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:14px;flex-wrap:wrap;font-size:12px;color:#66758a}.catalog-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:0 16px}.catalog-form-grid .el-input-number,.catalog-form-grid .el-select{width:100%}.process-catalog-panel .el-button--success{background:#008b4b;border-color:#008b4b}.process-catalog-panel .el-button--success.is-plain{background:#fff;color:#008b4b}
@media(max-width:500px){.catalog-form-grid{grid-template-columns:minmax(0,1fr)}.catalog-toolbar .el-input{flex-basis:100%}.catalog-toolbar .el-button{margin:0}.catalog-footer .el-pagination{max-width:100%;white-space:normal;padding:0}}
</style>
<style>
.production-process-dialog {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  display: flex;
  flex-direction: column;
  max-width: calc(100vw - 48px) !important;
  max-height: calc(100vh - 48px);
  margin: 0 !important;
}
.production-process-dialog .el-dialog__body {
  min-height: 0;
  overflow: auto;
}
.production-process-dialog .el-dialog__header {
  padding-right: 44px;
}
.production-process-dialog .el-dialog__header,
.production-process-dialog .el-dialog__footer {
  flex-shrink: 0;
}
.production-process-dialog .el-button--success {
  background: #008b4b;
  border-color: #008b4b;
}
.production-process-dialog .el-button--success.is-plain {
  background: #fff;
  color: #008b4b;
}
.production-process-dialog .el-dialog__footer {
  white-space: normal;
}
</style>
