<template>
  <section class="number-rule-page">
    <header class="page-heading">
      <div>
        <h1>编号规则</h1>
        <p>统一配置各业务对象的编号前缀、日期和流水号规则。规则调整只影响后续新生成编号，不修改历史编号。</p>
      </div>
      <el-button v-if="$can('document_number_rule.manage')" type="success" icon="el-icon-plus" @click="openCreate">新增规则</el-button>
    </header>

    <section class="filter-card">
      <label><span>业务类型</span><el-select v-model="query.document_type" clearable placeholder="全部"><el-option v-for="type in types" :key="type.code" :label="type.label" :value="type.code" /></el-select></label>
      <label><span>规则名称</span><el-input v-model.trim="query.keyword" clearable placeholder="请输入" @keyup.enter.native="search" /></label>
      <label><span>状态</span><el-select v-model="query.enabled" clearable placeholder="全部"><el-option label="启用" value="1" /><el-option label="停用" value="0" /></el-select></label>
      <div class="filter-actions"><el-button type="success" @click="search">查询</el-button><el-button @click="reset">重置</el-button></div>
    </section>

    <section class="table-card">
      <el-table v-loading="loading" :data="rows" border height="560" empty-text="暂无编号规则">
        <el-table-column label="业务类型" min-width="130">
          <template slot-scope="{ row }"><strong class="type-label">{{ row.business_type_label }}</strong><small class="type-code">{{ row.document_type }}</small></template>
        </el-table-column>
        <el-table-column prop="name" label="规则名称" min-width="150" show-overflow-tooltip />
        <el-table-column prop="prefix" label="编号前缀" width="100" />
        <el-table-column label="日期格式" width="112"><template slot-scope="{ row }">{{ dateLabel(row.date_format) }}</template></el-table-column>
        <el-table-column label="流水位数" width="96"><template slot-scope="{ row }">{{ row.sequence_length }}位</template></el-table-column>
        <el-table-column label="重置周期" width="104"><template slot-scope="{ row }">{{ cycleLabel(row.reset_cycle) }}</template></el-table-column>
        <el-table-column prop="current_sequence" label="当前流水" width="98" />
        <el-table-column prop="format_example" label="格式示例" min-width="166" show-overflow-tooltip />
        <el-table-column label="状态" width="84" align="center"><template slot-scope="{ row }"><el-tag size="mini" :type="row.enabled ? 'success' : 'info'">{{ row.enabled ? '启用' : '停用' }}</el-tag></template></el-table-column>
        <el-table-column label="操作" width="130" fixed="right" align="center">
          <template slot-scope="{ row }">
            <el-button v-if="$can('document_number_rule.manage')" type="text" size="mini" @click="openEdit(row)">编辑</el-button>
            <el-button v-if="$can('document_number_rule.manage') && row.enabled" type="text" class="danger-link" size="mini" @click="openDisable(row)">停用</el-button>
            <el-button v-if="$can('document_number_rule.manage') && !row.enabled" type="text" class="enable-link" size="mini" @click="enable(row)">启用</el-button>
          </template>
        </el-table-column>
      </el-table>
      <footer class="pagination"><span>共 {{ total }} 条</span><el-pagination background layout="sizes, prev, pager, next" :total="total" :current-page.sync="page" :page-size.sync="perPage" :page-sizes="[10,20,50,100]" @current-change="load" @size-change="changeSize" /></footer>
    </section>

    <el-drawer :visible.sync="drawerVisible" :size="380" :with-header="false" append-to-body custom-class="number-rule-drawer" @closed="clearForm">
      <div class="drawer-shell">
        <header><h2>{{ editing ? '编辑编号规则' : '新增编号规则' }} <small v-if="editing">{{ form.document_type }}</small></h2><button type="button" @click="drawerVisible=false">×</button></header>
        <main>
          <el-alert title="规则修改只影响后续新生成编号，不修改历史编号和已预留编号。" type="info" :closable="false" show-icon />
          <el-form ref="ruleForm" :model="form" :rules="formRules" label-position="top" size="small">
            <el-form-item label="业务类型" prop="document_type"><el-select v-model="form.document_type" :disabled="editing" filterable placeholder="请选择业务类型"><el-option v-for="type in types" :key="type.code" :label="`${type.label}（${type.code}）`" :value="type.code" :disabled="!editing && type.configured" /></el-select><small v-if="!editing && types.length && types.every(type => type.configured)" class="helper">当前固定业务类型均已配置；新增业务类型需先纳入固定字典。</small></el-form-item>
            <el-form-item label="规则名称" prop="name"><el-input v-model.trim="form.name" maxlength="120" /></el-form-item>
            <el-form-item label="编号前缀" prop="prefix"><el-input v-model.trim="form.prefix" maxlength="20" @input="form.prefix = form.prefix.toUpperCase()" /></el-form-item>
            <el-form-item label="日期格式" prop="date_format"><el-select v-model="form.date_format" @change="syncCycle"><el-option label="无日期" value="none" /><el-option label="YYYY" value="YYYY" /><el-option label="YYYYMM" value="YYYYMM" /><el-option label="YYYYMMDD" value="YYYYMMDD" /></el-select><small class="helper">无日期 / YYYY / YYYYMM / YYYYMMDD</small></el-form-item>
            <el-form-item label="流水号长度" prop="sequence_length"><el-input-number v-model="form.sequence_length" :min="1" :max="12" controls-position="right" /><small class="helper">1–12位</small></el-form-item>
            <el-form-item label="重置周期" prop="reset_cycle"><el-select v-model="form.reset_cycle"><el-option label="不重置" value="none" /><el-option label="每年" value="yearly" /><el-option label="每月" value="monthly" /><el-option label="每日" value="daily" /></el-select><small class="helper">不重置 / 每年 / 每月 / 每日</small></el-form-item>
            <el-form-item label="启用状态"><el-radio-group v-model="form.enabled"><el-radio-button :label="true">启用</el-radio-button><el-radio-button :label="false">停用</el-radio-button></el-radio-group></el-form-item>
            <el-form-item label="格式示例"><el-input :value="formatExample" readonly /><small class="helper">仅用于预览编号格式，不代表下一实际编号；不占用编号，也不推进当前流水。</small></el-form-item>
            <el-form-item v-if="editing" label="修改原因" prop="change_reason" :class="{ 'is-error': reasonError }"><el-input v-model.trim="form.change_reason" type="textarea" :rows="2" maxlength="200" show-word-limit placeholder="请输入本次修改原因" @input="reasonError=''" /><small v-if="reasonError" class="reason-error">{{ reasonError }}</small><small class="helper">规则实际发生变更时必填，保存后写入公共操作日志。</small></el-form-item>
          </el-form>
          <el-alert title="停用后，对应业务新增页面将无法生成新编号；历史编号和已有预留不受影响。" type="warning" :closable="false" show-icon />
        </main>
        <footer><el-button @click="drawerVisible=false">取消</el-button><el-button type="success" :loading="saving" @click="save">保存规则</el-button></footer>
      </div>
    </el-drawer>

    <el-dialog :visible.sync="disableVisible" width="460px" custom-class="disable-dialog" :show-close="false" append-to-body>
      <div slot="title" class="disable-title"><i class="el-icon-warning" />确认停用编号规则？</div>
      <dl v-if="disableTarget"><dt>业务类型</dt><dd>{{ disableTarget.business_type_label }}（{{ disableTarget.document_type }}）</dd><dt>规则名称</dt><dd>{{ disableTarget.name }}</dd><dt>编号前缀</dt><dd>{{ disableTarget.prefix }}</dd></dl>
      <el-alert title="停用后，对应业务的新增页面将无法生成新编号。历史编号、已使用编号和已预留编号不受影响。" type="warning" :closable="false" show-icon />
      <span slot="footer"><el-button @click="disableVisible=false">取消</el-button><el-button type="danger" :loading="saving" @click="confirmDisable">确认停用</el-button></span>
    </el-dialog>
  </section>
</template>

<script>
import { createDocumentNumberRule, disableDocumentNumberRule, enableDocumentNumberRule, listDocumentNumberRules, listDocumentNumberRuleTypes, updateDocumentNumberRule } from '../../../api/erp/master'

const emptyForm = () => ({ document_type: '', name: '', prefix: '', date_format: 'YYYYMMDD', sequence_length: 5, reset_cycle: 'daily', enabled: true, change_reason: '' })
export default {
  data: () => ({
    loading: false, saving: false, rows: [], types: [], total: 0, page: 1, perPage: 20,
    query: { document_type: '', keyword: '', enabled: '' }, drawerVisible: false, editing: false,
    form: emptyForm(), original: null, disableVisible: false, disableTarget: null, reasonError: '',
    formRules: {
      document_type: [{ required: true, message: '请选择业务类型', trigger: 'change' }],
      name: [{ required: true, message: '请输入规则名称', trigger: 'blur' }],
      prefix: [{ required: true, message: '请输入编号前缀', trigger: 'blur' }, { pattern: /^[A-Z][A-Z0-9-]*$/, message: '只能使用大写字母、数字和短横线，且必须以字母开头', trigger: 'blur' }],
      date_format: [{ required: true, message: '请选择日期格式', trigger: 'change' }],
      sequence_length: [{ required: true, message: '请输入流水号长度', trigger: 'change' }],
      reset_cycle: [{ required: true, message: '请选择重置周期', trigger: 'change' }]
    }
  }),
  computed: {
    isDirty () { if (!this.editing || !this.original) return true; return ['name', 'prefix', 'date_format', 'sequence_length', 'reset_cycle', 'enabled'].some(key => this.form[key] !== this.original[key]) },
    formatExample () { const dates = { none: '', YYYY: this.today('year'), YYYYMM: this.today('month'), YYYYMMDD: this.today('day') }; return `${this.form.prefix || ''}${dates[this.form.date_format] || ''}${String(1).padStart(Number(this.form.sequence_length || 1), '0')}` }
  },
  created () { this.initialize() },
  methods: {
    async initialize () { try { const { data } = await listDocumentNumberRuleTypes(); this.types = data.data || [] } catch (e) { this.noticeError(e) } await this.load() },
    async load () { this.loading = true; try { const { data } = await listDocumentNumberRules({ ...this.query, page: this.page, per_page: this.perPage }); this.rows = data.data || []; this.total = Number(data.total || 0) } catch (e) { this.noticeError(e) } finally { this.loading = false } },
    search () { this.page = 1; this.load() },
    reset () { this.query = { document_type: '', keyword: '', enabled: '' }; this.search() },
    changeSize () { this.page = 1; this.load() },
    openCreate () { this.editing = false; this.form = emptyForm(); this.original = null; this.drawerVisible = true },
    openEdit (row) { this.editing = true; this.form = { document_type: row.document_type, name: row.name, prefix: row.prefix, date_format: row.date_format, sequence_length: row.sequence_length, reset_cycle: row.reset_cycle, enabled: row.enabled, change_reason: '' }; this.original = { ...this.form }; this.drawerVisible = true },
    clearForm () { this.form = emptyForm(); this.original = null; this.reasonError = ''; this.$refs.ruleForm?.clearValidate() },
    syncCycle (format) { this.form.reset_cycle = ({ none: 'none', YYYY: 'yearly', YYYYMM: 'monthly', YYYYMMDD: 'daily' })[format] },
    async save () { const valid = await this.$refs.ruleForm.validate().catch(() => false); if (!valid) return; if (this.editing && !this.isDirty) return this.$message.warning('编号规则没有发生任何变化'); if (this.editing && !String(this.form.change_reason || '').trim()) { this.reasonError = '规则实际发生变更时必须填写修改原因'; return } this.saving = true; try { const request = this.editing ? updateDocumentNumberRule(this.rows.find(row => row.document_type === this.form.document_type).id, this.form) : createDocumentNumberRule(this.form); const { data } = await request; this.$message.success(data.message); this.drawerVisible = false; await this.load() } catch (e) { this.noticeError(e) } finally { this.saving = false } },
    openDisable (row) { this.disableTarget = row; this.disableVisible = true },
    async confirmDisable () { this.saving = true; try { const { data } = await disableDocumentNumberRule(this.disableTarget.id); this.$message.success(data.message); this.disableVisible = false; await this.load() } catch (e) { this.noticeError(e) } finally { this.saving = false } },
    async enable (row) { this.saving = true; try { const { data } = await enableDocumentNumberRule(row.id); this.$message.success(data.message); await this.load() } catch (e) { this.noticeError(e) } finally { this.saving = false } },
    today (part) { const d = new Date(); const y = d.getFullYear(); const m = String(d.getMonth() + 1).padStart(2, '0'); const day = String(d.getDate()).padStart(2, '0'); return part === 'year' ? `${y}` : part === 'month' ? `${y}${m}` : `${y}${m}${day}` },
    dateLabel (value) { return value === 'none' ? '无日期' : value },
    cycleLabel (value) { return ({ none: '不重置', yearly: '每年', monthly: '每月', daily: '每日' })[value] || value },
    noticeError (error) { const errors = error.response?.data?.errors; const first = errors && Object.values(errors)[0]; this.$message.error((Array.isArray(first) ? first[0] : first) || error.userMessage || '操作失败') }
  }
}
</script>

<style scoped>
.number-rule-page{min-height:calc(100vh - 52px);padding:24px;background:#f7f9fb;color:#172033}.page-heading{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:24px}.page-heading h1{margin:0 0 8px;font-size:25px;line-height:1.15}.page-heading p{margin:0;color:#68758a;font-size:13px}.filter-card{display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:44px;align-items:end;margin-bottom:20px;padding:22px 18px;background:#fff;border:1px solid #e1e7ee;border-radius:5px}.filter-card label{display:grid;gap:9px;font-size:13px;font-weight:600}.filter-actions{display:flex;gap:10px}.filter-actions .el-button{min-width:84px}.table-card{overflow:hidden;background:#fff;border:1px solid #dfe6ed;border-radius:5px}.type-label,.type-code{display:block}.type-label{font-size:14px}.type-code{margin-top:3px;color:#61708a;font-size:11px}.danger-link{color:#f04444!important}.enable-link{color:#07883f!important}.pagination{height:76px;padding:0 18px;display:flex;align-items:center;justify-content:space-between;color:#445168}.pagination ::v-deep .el-pagination{padding:0}.table-card ::v-deep th.el-table__cell{height:48px;background:#fbfcfd;color:#26354a}.table-card ::v-deep td.el-table__cell{height:55px}.table-card ::v-deep .el-table__body-wrapper{overflow-y:auto}.number-rule-page ::v-deep .el-button--success{background:#07883f;border-color:#07883f}
</style>

<style>
.number-rule-drawer{width:380px!important}.number-rule-drawer .el-drawer__body{overflow:hidden}.drawer-shell{height:100%;display:grid;grid-template-rows:70px 1fr 68px;background:#fff}.drawer-shell>header{padding:0 22px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #e7ebf0}.drawer-shell>header h2{margin:0;font-size:19px}.drawer-shell>header small{margin-left:6px;color:#6c7788;font-size:12px;font-weight:400}.drawer-shell>header button{border:0;background:none;font-size:25px;color:#1c2b40;cursor:pointer}.drawer-shell>main{overflow-y:auto;padding:16px}.drawer-shell .el-alert{margin-bottom:14px;padding:9px 11px}.drawer-shell .el-alert__title{white-space:normal;line-height:1.5}.drawer-shell .el-form-item{margin-bottom:13px}.drawer-shell .el-form-item__label{padding:0 0 5px;font-weight:600;line-height:20px}.drawer-shell .el-select,.drawer-shell .el-input-number{width:100%}.drawer-shell .helper{display:block;margin-top:4px;color:#7c8798;font-size:10px;line-height:1.45;white-space:normal;overflow-wrap:anywhere}.drawer-shell .reason-error{display:block;margin-top:3px;color:#f56c6c;font-size:12px}.number-rule-drawer .el-radio-button__orig-radio:checked+.el-radio-button__inner{background:#07883f;border-color:#07883f;box-shadow:-1px 0 0 0 #07883f}.drawer-shell>footer{padding:13px 22px;text-align:right;border-top:1px solid #e7ebf0}.drawer-shell>footer .el-button{min-width:98px}.disable-dialog{border-radius:7px}.disable-title{font-size:17px;font-weight:600}.disable-title i{margin-right:10px;color:#f5a623}.disable-dialog dl{display:grid;grid-template-columns:92px 1fr;margin:0 0 18px;padding:4px 14px;border:1px solid #e5eaf0;border-radius:4px}.disable-dialog dt,.disable-dialog dd{margin:0;padding:12px 0;border-bottom:1px solid #edf0f3}.disable-dialog dt:nth-last-of-type(1),.disable-dialog dd:last-child{border-bottom:0}.disable-dialog dt{color:#6b7788}.disable-dialog dd{font-weight:600}.disable-dialog .el-alert{line-height:1.7}
</style>
