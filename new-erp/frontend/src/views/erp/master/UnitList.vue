<template>
  <section class="unit-page" :class="{ 'drawer-open': drawerVisible }">
    <main class="unit-workspace">
      <header class="page-head">
        <div class="crumb">主数据中心　/　基础档案　/　单位管理</div>
        <h1>单位管理</h1>
      </header>

      <div class="filter-row">
        <el-input v-model.trim="query.keyword" size="small" clearable prefix-icon="el-icon-search" placeholder="单位编码/名称/符号" @keyup.enter.native="search" />
        <el-select v-model="query.unit_type" size="small" clearable placeholder="单位类型　请选择">
          <el-option v-for="type in unitTypes" :key="type.value" :label="type.label" :value="type.value" />
        </el-select>
        <el-select v-model="query.status" size="small" clearable placeholder="状态　请选择">
          <el-option label="启用" value="enabled" />
          <el-option label="停用" value="disabled" />
        </el-select>
        <span class="filter-spacer" />
        <el-button size="small" icon="el-icon-refresh-left" @click="reset">重置</el-button>
        <el-button size="small" icon="el-icon-s-operation" @click="search">筛选</el-button>
        <el-button size="small" type="success" icon="el-icon-plus" @click="openCreate">新增单位</el-button>
      </div>

      <section class="table-card">
        <el-table v-loading="loading" :data="rows" border size="mini" row-key="id">
          <el-table-column prop="unit_code" label="单位编码" min-width="92" />
          <el-table-column prop="unit_name" label="单位名称" min-width="90" />
          <el-table-column prop="symbol" label="单位符号" min-width="88"><template slot-scope="{ row }">{{ row.symbol || row.unit_name || '-' }}</template></el-table-column>
          <el-table-column label="单位类型" min-width="88"><template slot-scope="{ row }">{{ unitTypeText(row.unit_type) }}</template></el-table-column>
          <el-table-column label="允许小数" width="82" align="center"><template slot-scope="{ row }">{{ row.allow_decimal ? '是' : '否' }}</template></el-table-column>
          <el-table-column prop="decimal_places" label="小数位数" width="82" align="center" />
          <el-table-column label="使用对象数" width="92" align="right"><template slot-scope="{ row }">{{ usageCount(row).toLocaleString() }}</template></el-table-column>
          <el-table-column label="状态" width="82"><template slot-scope="{ row }"><span class="status-dot" :class="row.status">{{ statusText(row.status) }}</span></template></el-table-column>
          <el-table-column prop="sort_order" label="排序" width="64" align="center" />
          <el-table-column label="更新时间" min-width="132"><template slot-scope="{ row }">{{ formatDate(row.updated_at) }}</template></el-table-column>
          <el-table-column label="操作" width="158" fixed="right">
            <template slot-scope="{ row }">
              <el-button type="text" size="mini" @click="openEdit(row)">编辑</el-button>
              <el-button v-if="row.status === 'enabled'" type="text" size="mini" class="danger-link" @click="toggleStatus(row)">禁用</el-button>
              <el-button v-else type="text" size="mini" class="success-link" @click="toggleStatus(row)">启用</el-button>
              <el-button v-if="row.status !== 'enabled'" type="text" size="mini" class="danger-link" @click="deleteUnit(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
        <footer class="pager-row">
          <span>共 {{ total }} 条</span>
          <el-pagination background small layout="prev, pager, next, sizes" :current-page.sync="query.page" :page-size.sync="query.per_page" :page-sizes="[10, 20, 50]" :total="total" @current-change="load" @size-change="sizeChange" />
        </footer>
      </section>
    </main>

    <aside v-if="drawerVisible" class="unit-drawer">
      <header><h2>{{ form.id ? '编辑单位' : '新增单位' }}</h2><button @click="closeDrawer"><i class="el-icon-close" /></button></header>
      <el-form ref="form" :model="form" :rules="rules" label-position="top" size="small" class="drawer-body">
        <el-form-item label="单位编码" prop="unit_code"><el-input v-model.trim="form.unit_code" :disabled="!!form.id" /><div v-if="form.id" class="field-help">创建后不可修改</div></el-form-item>
        <el-form-item label="单位名称" prop="unit_name"><el-input v-model.trim="form.unit_name" maxlength="50" show-word-limit /></el-form-item>
        <el-form-item label="单位符号" prop="symbol"><el-input v-model.trim="form.symbol" maxlength="20" show-word-limit /></el-form-item>
        <el-form-item label="单位类型" prop="unit_type"><el-select v-model="form.unit_type" class="full"><el-option v-for="type in unitTypes" :key="type.value" :label="type.label" :value="type.value" /></el-select></el-form-item>
        <el-form-item label="是否允许小数"><div class="switch-line"><el-switch v-model="form.allow_decimal" active-color="#07883f" @change="decimalToggle" /><span>{{ form.allow_decimal ? '允许输入小数时生效' : '仅允许整数' }}</span></div></el-form-item>
        <el-form-item label="小数位数" prop="decimal_places"><el-input-number v-model="form.decimal_places" :min="0" :max="6" controls-position="right" /><div class="field-help">取值范围 0-6；不允许小数时保存为 0</div></el-form-item>
        <el-form-item label="排序" prop="sort_order"><el-input-number v-model="form.sort_order" :min="0" :max="999999" controls-position="right" /><div class="field-help">数值越小排序越靠前</div></el-form-item>
        <el-form-item label="状态"><el-radio-group v-model="form.status"><el-radio label="enabled">启用</el-radio><el-radio label="disabled">停用</el-radio></el-radio-group></el-form-item>
        <el-form-item label="备注"><el-input v-model="form.remark" type="textarea" :rows="3" maxlength="200" show-word-limit placeholder="请输入备注（选填）" /></el-form-item>
      </el-form>
      <footer><el-button size="small" @click="closeDrawer">取消</el-button><el-button size="small" type="success" :loading="saving" @click="save">保存</el-button></footer>
    </aside>
  </section>
</template>

<script>
import { deleteEntity, disableEntity, enableEntity, listEntity, saveEntity } from '../../../api/erp/master'

const emptyForm = () => ({ id: null, unit_code: '', unit_name: '', symbol: '', unit_type: 'quantity', allow_decimal: false, decimal_places: 0, sort_order: 1, is_base: false, status: 'enabled', remark: '' })

export default {
  name: 'UnitList',
  data () {
    return {
      loading: false, saving: false, drawerVisible: false, rows: [], total: 0,
      query: { keyword: '', unit_type: '', status: '', page: 1, per_page: 20 },
      form: emptyForm(),
      unitTypes: [{ value: 'quantity', label: '数量' }, { value: 'weight', label: '重量' }, { value: 'length', label: '长度' }, { value: 'area', label: '面积' }, { value: 'volume', label: '体积' }, { value: 'time', label: '时间' }],
      rules: {
        unit_code: [{ required: true, message: '请输入单位编码', trigger: 'blur' }],
        unit_name: [{ required: true, message: '请输入单位名称', trigger: 'blur' }],
        symbol: [{ required: true, message: '请输入单位符号', trigger: 'blur' }],
        unit_type: [{ required: true, message: '请选择单位类型', trigger: 'change' }],
        decimal_places: [{ required: true, message: '请设置小数位数', trigger: 'change' }],
        sort_order: [{ required: true, message: '请设置排序', trigger: 'change' }]
      }
    }
  },
  created () { this.load() },
  methods: {
    async load () {
      this.loading = true
      try { const { data } = await listEntity('units', this.query); this.rows = data.data || []; this.total = Number(data.total || 0) } catch (e) { this.$message.error(e.userMessage || '单位列表加载失败') } finally { this.loading = false }
    },
    search () { this.query.page = 1; this.load() },
    reset () { this.query = { keyword: '', unit_type: '', status: '', page: 1, per_page: this.query.per_page }; this.load() },
    sizeChange () { this.query.page = 1; this.load() },
    openCreate () { this.form = emptyForm(); this.drawerVisible = true },
    openEdit (row) { this.form = { ...emptyForm(), ...row, allow_decimal: !!row.allow_decimal, sort_order: Number(row.sort_order || 0) }; this.drawerVisible = true },
    closeDrawer () { this.drawerVisible = false; this.$nextTick(() => this.$refs.form && this.$refs.form.clearValidate()) },
    decimalToggle (value) { if (!value) this.form.decimal_places = 0 },
    save () {
      this.$refs.form.validate(async valid => {
        if (!valid) return
        this.saving = true
        try { await saveEntity('units', { ...this.form, decimal_places: this.form.allow_decimal ? this.form.decimal_places : 0 }); this.$message.success('单位保存成功'); this.closeDrawer(); await this.load() } catch (e) { this.$message.error(e.userMessage || '单位保存失败') } finally { this.saving = false }
      })
    },
    async toggleStatus (row) {
      const enabling = row.status !== 'enabled'
      try { await this.$confirm(enabling ? '确认启用该单位？' : '被业务引用的单位不能停用，确认继续检查并停用？', enabling ? '启用单位' : '停用单位', { type: 'warning' }); await (enabling ? enableEntity : disableEntity)('units', row.id); this.$message.success(enabling ? '单位已启用' : '单位已停用'); await this.load() } catch (e) { if (e !== 'cancel') this.$message.error(e.userMessage || '操作失败') }
    },
    async deleteUnit (row) {
      try { await this.$confirm(`确认删除单位 ${row.unit_code} / ${row.unit_name}？被商品、SKU、物料、换算或业务单据引用的单位不能删除。`, '删除单位', { type: 'warning', confirmButtonText: '确认删除' }); await deleteEntity('units', row.id); this.$message.success('单位已删除'); await this.load() } catch (e) { if (e !== 'cancel' && e !== 'close') this.$message.error(e.userMessage || '单位删除失败') }
    },
    usageCount (row) { return Number(row.items_count || 0) + Number(row.skus_count || 0) + Number(row.products_count || 0) + Number(row.purchase_conversions_count || 0) },
    unitTypeText (value) { return (this.unitTypes.find(type => type.value === value) || {}).label || value || '-' },
    statusText (value) { return value === 'enabled' ? '启用' : '停用' },
    formatDate (value) { return value ? String(value).replace('T', ' ').slice(0, 16) : '-' }
  }
}
</script>

<style scoped>
.unit-page{position:relative;min-height:calc(100vh - 52px);background:#fff;color:#1e2935}.unit-workspace{padding:0 20px 28px;transition:padding-right .2s}.unit-page.drawer-open .unit-workspace{padding-right:374px}.page-head{height:82px;border-bottom:1px solid #dfe5e9;padding-top:13px}.crumb{height:32px;color:#34404b;font-size:12px}.page-head h1{margin:4px 0 14px;font-size:20px}.filter-row{height:72px;display:flex;align-items:center;gap:12px}.filter-row .el-input{width:222px}.filter-row .el-select{width:176px}.filter-spacer{flex:1}.table-card{border:1px solid #dfe5e9;border-radius:5px;overflow:hidden;background:#fff}.pager-row{height:58px;padding:0 14px;display:flex;align-items:center;justify-content:space-between}.status-dot{display:inline-flex;align-items:center;gap:7px}.status-dot:before{content:'';width:6px;height:6px;border-radius:50%;background:#07883f}.status-dot.disabled:before{background:#d94141}.danger-link{color:#e54848!important}.success-link{color:#07883f!important}.unit-drawer{position:fixed;z-index:30;top:52px;right:0;bottom:0;width:356px;background:#fff;border-left:1px solid #dfe5e9;box-shadow:-10px 0 24px rgba(26,45,60,.08)}.unit-drawer>header{height:52px;padding:0 18px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #e4e8eb}.unit-drawer h2{margin:0;font-size:17px}.unit-drawer header button{border:0;background:none;font-size:18px;cursor:pointer}.drawer-body{height:calc(100% - 116px);padding:16px 18px 82px;overflow:auto}.drawer-body .full{width:100%}.drawer-body .el-input-number{width:138px}.field-help{margin-top:4px;color:#8b959e;font-size:10px}.switch-line{display:flex;align-items:center;gap:10px;color:#66717c}.unit-drawer>footer{position:absolute;left:0;right:0;bottom:0;height:64px;padding:12px 16px;display:grid;grid-template-columns:1fr 1.25fr;gap:12px;border-top:1px solid #e2e7eb;background:#fff}::v-deep .el-table th{background:#f7f8fa;color:#303b45}::v-deep .el-button--success{background:#07883f;border-color:#07883f}@media(max-width:1150px){.unit-page.drawer-open .unit-workspace{padding-right:20px}.unit-drawer{width:356px}.filter-row{overflow-x:auto}}
</style>
