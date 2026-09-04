<template>
  <section class="master-page">
    <div class="page-heading">
      <div><h1>{{ config.title }} <small>{{ total }}</small></h1><p>{{ config.description }}</p></div>
      <div><el-button v-if="config.importable" size="small" icon="el-icon-upload2" @click="$router.push('/master/imports')">导入{{ config.shortTitle }}</el-button><el-button size="small" type="success" icon="el-icon-plus" @click="openForm()">新增{{ config.shortTitle }}</el-button></div>
    </div>
    <div class="filter-bar">
      <el-input v-model="query.keyword" size="small" clearable prefix-icon="el-icon-search" :placeholder="`${config.shortTitle}编码/名称`" @keyup.enter.native="load" />
      <el-select v-model="query.status" size="small" clearable placeholder="状态"><el-option label="启用" value="enabled" /><el-option label="停用" value="disabled" /></el-select>
      <el-button size="small" type="success" @click="load">查询</el-button><el-button size="small" @click="reset">重置</el-button>
    </div>
    <div class="table-panel">
      <el-table v-loading="loading" :data="rows" height="calc(100vh - 238px)" highlight-current-row @row-click="showDetail">
        <el-table-column v-for="col in config.columns" :key="col.prop" :prop="col.prop" :label="col.label" :min-width="col.width || 100" show-overflow-tooltip>
          <template slot-scope="{ row }">
            <el-tag v-if="col.type === 'status'" size="mini" :type="row[col.prop] === 'enabled' ? 'success' : 'info'">{{ row[col.prop] === 'enabled' ? '启用' : '停用' }}</el-tag>
            <span v-else-if="col.type === 'boolean'">{{ row[col.prop] ? '是' : '否' }}</span>
            <span v-else>{{ displayValue(row, col) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="150" fixed="right">
          <template slot-scope="{ row }"><el-button type="text" @click.stop="showDetail(row)">详情</el-button><el-button type="text" @click.stop="openForm(row)">编辑</el-button><el-button v-if="row.status === 'enabled'" type="text" class="danger-link" @click.stop="disable(row)">禁用</el-button></template>
        </el-table-column>
        <template slot="empty"><div class="table-empty"><i class="el-icon-box" /><strong>暂无{{ config.shortTitle }}</strong><span>点击右上角新增第一条数据</span></div></template>
      </el-table>
      <el-pagination small background layout="total, prev, pager, next, sizes" :total="total" :current-page.sync="query.page" :page-size.sync="query.per_page" :page-sizes="[10,20,50]" @current-change="load" @size-change="load" />
    </div>

    <el-drawer :visible.sync="drawer" size="360px" :with-header="false">
      <div class="detail-drawer" v-if="selected">
        <div class="drawer-head"><div><small>{{ config.shortTitle }}详情</small><h2>{{ selected[config.nameKey] }}</h2><span>{{ selected[config.codeKey] }}</span></div><i class="el-icon-close" @click="drawer=false" /></div>
        <section><h3>基础信息</h3><dl><template v-for="col in config.columns"><dt :key="col.prop+'d'">{{ col.label }}</dt><dd :key="col.prop+'v'">{{ displayValue(selected, col) }}</dd></template></dl></section>
        <section v-if="config.detailHint"><h3>{{ config.detailHint.title }}</h3><p>{{ config.detailHint.text }}</p></section>
        <div class="drawer-actions"><el-button size="small" @click="drawer=false">关闭</el-button><el-button size="small" type="success" @click="openForm(selected)">编辑{{ config.shortTitle }}</el-button></div>
      </div>
    </el-drawer>

    <el-dialog :title="`${form.id ? '编辑' : '新增'}${config.shortTitle}`" :visible.sync="formVisible" width="560px" top="5vh" :close-on-click-modal="false">
      <el-form ref="form" :model="form" :rules="rules" label-width="110px" size="small" class="entity-form">
        <el-form-item v-for="field in config.fields" :key="field.prop" :label="field.label" :prop="field.prop">
          <el-input v-if="field.type === 'text' || !field.type" v-model="form[field.prop]" :disabled="field.immutable && !!form.id" :placeholder="field.placeholder || `请输入${field.label}`" />
          <el-input v-else-if="field.type === 'textarea'" v-model="form[field.prop]" type="textarea" :rows="3" />
          <el-input-number v-else-if="field.type === 'number'" v-model="form[field.prop]" :min="0" :precision="field.precision || 0" />
          <el-switch v-else-if="field.type === 'boolean'" v-model="form[field.prop]" />
          <el-select v-else-if="field.type === 'select'" v-model="form[field.prop]" filterable clearable style="width:100%"><el-option v-for="opt in field.options" :key="opt.value" :label="opt.label" :value="opt.value" /></el-select>
          <el-select v-else-if="field.type === 'remote'" v-model="form[field.prop]" filterable clearable style="width:100%"><el-option v-for="opt in options[field.source] || []" :key="opt.id" :label="`${opt[field.labelKey]} (${opt[field.codeKey]})`" :value="opt.id" /></el-select>
        </el-form-item>
      </el-form>
      <span slot="footer"><el-button size="small" @click="formVisible=false">取消</el-button><el-button size="small" type="success" :loading="saving" @click="save">保存</el-button></span>
    </el-dialog>
  </section>
</template>

<script>
import { listEntity, saveEntity, disableEntity } from '../../api/erp/master'

export default {
  props: { config: { type: Object, required: true } },
  data: () => ({ rows: [], total: 0, loading: false, saving: false, drawer: false, formVisible: false, selected: null, form: {}, options: {}, query: { keyword: '', status: '', page: 1, per_page: 20 } }),
  computed: {
    rules() {
      const result = {}
      this.config.fields.filter(f => f.required).forEach(f => { result[f.prop] = [{ required: true, message: `请填写${f.label}`, trigger: ['blur', 'change'] }] })
      return result
    }
  },
  created() { this.load(); this.loadOptions() },
  methods: {
    async load() {
      this.loading = true
      try { const { data } = await listEntity(this.config.entity, this.query); this.rows = data.data; this.total = data.total } catch (e) { this.$message.error(e.userMessage) } finally { this.loading = false }
    },
    async loadOptions() {
      const sources = [...new Set(this.config.fields.filter(f => f.type === 'remote').map(f => f.source))]
      await Promise.all(sources.map(async source => { const { data } = await listEntity(source, { per_page: 100 }); this.$set(this.options, source, data.data) }))
    },
    reset() { this.query = { keyword: '', status: '', page: 1, per_page: 20 }; this.load() },
    displayValue(row, col) {
      if (col.path) return col.path.split('.').reduce((obj, key) => obj && obj[key], row) ?? '—'
      if (col.type === 'status') return row[col.prop] === 'enabled' ? '启用' : '停用'
      if (col.type === 'boolean') return row[col.prop] ? '是' : '否'
      return row[col.prop] ?? '—'
    },
    showDetail(row) { this.selected = row; this.drawer = true },
    openForm(row) {
      this.drawer = false
      const defaults = {}
      this.config.fields.forEach(field => { defaults[field.prop] = field.default ?? (field.type === 'boolean' ? false : '') })
      this.form = row ? { ...defaults, ...row } : defaults
      this.formVisible = true
      this.$nextTick(() => this.$refs.form?.clearValidate())
    },
    save() {
      this.$refs.form.validate(async valid => {
        if (!valid) return
        this.saving = true
        try { const { data } = await saveEntity(this.config.entity, this.form); this.$message.success(data.message); this.formVisible = false; await this.load() } catch (e) { this.$message.error(e.userMessage) } finally { this.saving = false }
      })
    },
    async disable(row) {
      try { await this.$confirm(`确认禁用“${row[this.config.nameKey]}”？`, '禁用确认', { type: 'warning' }); const { data } = await disableEntity(this.config.entity, row.id); this.$message.success(data.message); this.load() } catch (e) { if (e !== 'cancel') this.$message.error(e.userMessage || '操作失败') }
    }
  }
}
</script>
