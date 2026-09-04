<template>
  <el-dialog
    title="维护估值汇率"
    :visible.sync="dialogVisible"
    width="950px"
    top="12vh"
    :close-on-click-modal="false"
    class="valuation-rate-dialog"
    @open="bootstrap"
  >
    <div class="rate-dialog-subtitle">统一方向：1 源币 = X 目标币；已被业务引用的历史版本不可覆盖。</div>
    <div class="rate-dialog-actions">
      <el-button v-if="$can('finance.exchange-rate.create')" type="success" class="green" @click="openCreate">新增估值汇率</el-button>
      <el-button @click="dialogVisible = false">关闭</el-button>
    </div>

    <section class="rate-filter-card">
      <el-form inline size="small" class="rate-filter">
        <el-form-item label="源币"><el-select v-model="query.source_currency" clearable filterable placeholder="请选择源币"><el-option v-for="c in currencies" :key="c.currency_code" :label="c.currency_code" :value="c.currency_code" /></el-select></el-form-item>
        <el-form-item label="目标币"><el-select v-model="query.target_currency" clearable filterable placeholder="请选择目标币"><el-option v-for="c in currencies" :key="c.currency_code" :label="c.currency_code" :value="c.currency_code" /></el-select></el-form-item>
        <el-form-item label="生效日期"><el-date-picker v-model="dateRange" type="daterange" value-format="yyyy-MM-dd" start-placeholder="开始日期" end-placeholder="结束日期" @change="syncRange" /></el-form-item>
        <el-form-item label="状态"><el-select v-model="query.status" clearable placeholder="请选择状态"><el-option label="启用" value="enabled" /><el-option label="停用" value="disabled" /><el-option label="已固化" value="frozen" /></el-select></el-form-item>
        <el-form-item class="filter-actions"><el-button type="success" class="green" @click="search">查询</el-button><el-button @click="reset">重置</el-button></el-form-item>
      </el-form>
    </section>

    <section class="rate-table-card">
      <el-table v-loading="loading" :data="rows" border size="small">
        <el-table-column label="源币" width="82" prop="source_currency" />
        <el-table-column label="目标币" width="82" prop="target_currency" />
        <el-table-column label="汇率方向说明" min-width="188"><template slot-scope="{row}">1 {{ row.source_currency }} = {{ number(row.rate) }} {{ row.target_currency }}</template></el-table-column>
        <el-table-column label="汇率类型" min-width="105"><template><el-tag type="success" size="mini">估值汇率</el-tag></template></el-table-column>
        <el-table-column label="生效时间" min-width="115"><template slot-scope="{row}">{{ date(row.effective_at) }}</template></el-table-column>
        <el-table-column label="来源" min-width="112"><template slot-scope="{row}">{{ sourceLabel(row) }}</template></el-table-column>
        <el-table-column label="状态" width="84"><template slot-scope="{row}"><el-tag size="mini" :type="row.status === 'enabled' ? 'success' : row.status === 'frozen' ? 'info' : 'danger'">{{ statusLabel(row.status) }}</el-tag></template></el-table-column>
        <el-table-column label="已被业务引用" min-width="108"><template slot-scope="{row}"><span :class="Number(row.business_reference_count || 0) > 0 ? 'used' : ''">{{ Number(row.business_reference_count || 0) > 0 ? `是（${row.business_reference_count}）` : '否（0）' }}</span></template></el-table-column>
        <el-table-column label="操作" width="112"><template slot-scope="{row}"><el-button type="text" @click="view(row)">查看</el-button><el-button v-if="row.status === 'enabled' && $can('finance.exchange-rate.create')" type="text" class="danger" @click="disable(row)">停用</el-button></template></el-table-column>
      </el-table>
      <el-pagination background layout="total, sizes, prev, pager, next, jumper" :total="total" :current-page="page" :page-size="perPage" :page-sizes="[10,20,50,100]" @current-change="changePage" @size-change="changeSize" />
    </section>
    <el-alert class="rate-dialog-note" type="info" :closable="false" show-icon title="估值使用最近有效的估值汇率；历史版本仅可新增或停用，不可覆盖。" />

    <el-dialog title="新增估值汇率" :visible.sync="createVisible" append-to-body width="560px" :close-on-click-modal="false">
      <el-alert title="已被正式业务引用的历史汇率不会被编辑覆盖；后续变化请新增版本。" type="info" :closable="false" show-icon />
      <el-form ref="rateForm" :model="form" :rules="rules" label-width="104px" class="create-rate-form">
        <el-form-item label="源币" prop="source_currency"><el-select v-model="form.source_currency" filterable><el-option v-for="c in currencies" :key="c.currency_code" :label="`${c.currency_code} · ${c.currency_name}`" :value="c.currency_code" /></el-select></el-form-item>
        <el-form-item label="目标币" prop="target_currency"><el-select v-model="form.target_currency" filterable><el-option v-for="c in currencies" :key="c.currency_code" :label="`${c.currency_code} · ${c.currency_name}`" :value="c.currency_code" /></el-select></el-form-item>
        <el-form-item label="1 源币 =" prop="rate"><el-input v-model.trim="form.rate"><template slot="append">目标币</template></el-input></el-form-item>
        <el-form-item label="生效日期" prop="effective_at"><el-date-picker v-model="form.effective_at" type="date" value-format="yyyy-MM-dd" /></el-form-item>
        <el-form-item label="备注"><el-input v-model.trim="form.remark" type="textarea" :rows="3" /></el-form-item>
      </el-form>
      <span slot="footer"><el-button @click="createVisible = false">取消</el-button><el-button type="success" class="green" :loading="saving" @click="save">保存新版本</el-button></span>
    </el-dialog>
  </el-dialog>
</template>

<script>
import { createExchangeRate, disableExchangeRate, listExchangeRateHistory, listFinanceCurrencies } from '../../../api/erp/finance'

const blank = () => ({ source_currency: 'USD', target_currency: 'CNY', rate_type: 'valuation', source: 'manual', rate: '', effective_at: new Date().toISOString().slice(0, 10), remark: '' })

export default {
  props: { visible: { type: Boolean, default: false } },
  data: () => ({ loading: false, saving: false, createVisible: false, rows: [], currencies: [], total: 0, page: 1, perPage: 10, dateRange: [], query: { source_currency: '', target_currency: '', status: '', effective_start: '', effective_end: '' }, form: blank(), rules: { source_currency: [{ required: true, message: '请选择源币', trigger: 'change' }], target_currency: [{ required: true, message: '请选择目标币', trigger: 'change' }], rate: [{ required: true, message: '请输入汇率', trigger: 'blur' }], effective_at: [{ required: true, message: '请选择日期', trigger: 'change' }] } }),
  computed: { dialogVisible: { get () { return this.visible }, set (value) { this.$emit('update:visible', value) } } },
  methods: {
    async bootstrap () { if (!this.currencies.length) { const r = await listFinanceCurrencies({ per_page: 100, status: 'enabled' }); this.currencies = r.data.data || [] } this.load() },
    async load () { this.loading = true; try { const r = await listExchangeRateHistory({ ...this.query, rate_type: 'valuation', page: this.page, per_page: this.perPage }); this.rows = r.data.data || []; this.total = Number(r.data.total || 0) } catch (e) { this.$message.error(e.userMessage || '汇率历史加载失败') } finally { this.loading = false } },
    syncRange () { this.query.effective_start = this.dateRange?.[0] || ''; this.query.effective_end = this.dateRange?.[1] || '' },
    search () { this.page = 1; this.load() }, reset () { this.dateRange = []; this.query = { source_currency: '', target_currency: '', status: '', effective_start: '', effective_end: '' }; this.search() }, changePage (page) { this.page = page; this.load() }, changeSize (size) { this.perPage = size; this.page = 1; this.load() },
    openCreate () { this.form = blank(); this.createVisible = true }, view () { this.$message.info('汇率历史为只读事实；已被业务引用的版本不可覆盖。') },
    async disable (row) { try { await this.$confirm('停用后新估值不能引用该版本，历史事实不受影响。', '确认停用', { type: 'warning' }); await disableExchangeRate(row.record_id); this.$message.success('已停用'); this.load(); this.$emit('changed') } catch (e) { if (e !== 'cancel') this.$message.error(e.userMessage || '停用失败') } },
    save () { this.$refs.rateForm.validate(async ok => { if (!ok) return; if (this.form.source_currency === this.form.target_currency) return this.$message.error('源币与目标币不能相同'); this.saving = true; try { await createExchangeRate(this.form); this.$message.success('已新增估值汇率版本'); this.createVisible = false; this.load(); this.$emit('changed') } catch (e) { this.$message.error(e.userMessage || '保存失败') } finally { this.saving = false } }) },
    number (value) { return value === null || value === undefined ? '—' : Number(value).toFixed(6) }, date (value) { return value ? String(value).slice(0, 10) : '—' }, sourceLabel (row) { return ({ manual: '财务部录入', bank: '银行', platform: '平台', external: '系统自动获取' })[row.source] || '手工维护' }, statusLabel (value) { return ({ enabled: '启用', disabled: '停用', frozen: '已固化' })[value] || value }
  }
}
</script>

<style scoped>
.rate-dialog-subtitle{color:#718099;font-size:14px;margin:-8px 0 16px}.rate-dialog-actions{position:absolute;right:58px;top:14px;display:flex;gap:10px}.green{background:#078948;border-color:#078948}.rate-filter-card,.rate-table-card{border:1px solid #e2e8f0;border-radius:6px;background:#fff}.rate-filter-card{padding:16px 18px;margin-bottom:12px}.rate-filter{display:grid;grid-template-columns:1fr 1fr 1.35fr 1fr;gap:12px;align-items:end}.rate-filter .el-form-item{margin:0;display:flex;flex-direction:column}.rate-filter .el-form-item__label{float:none;text-align:left;padding:0 0 7px;line-height:20px;color:#36445e}.rate-filter .el-select,.rate-filter .el-date-editor{width:100%}.filter-actions{grid-column:1 / -1;display:flex!important;flex-direction:row!important;gap:8px}.rate-table-card{padding:0;overflow:auto}.rate-table-card .el-table{min-width:900px;min-height:330px}.rate-table-card .el-pagination{padding:14px;text-align:right}.rate-table-card :deep(.el-table th){background:#f7f9fc;color:#34425b}.used{color:#e84b4b}.danger{color:#e84b4b}.rate-dialog-note{margin-top:12px}.create-rate-form{margin-top:20px}.create-rate-form .el-select,.create-rate-form .el-date-editor{width:100%}@media(max-width:1000px){.valuation-rate-dialog :deep(.el-dialog){width:calc(100vw - 48px)!important}.rate-filter{grid-template-columns:1fr 1fr}}@media(max-width:700px){.rate-dialog-actions{position:static;margin-bottom:12px}.rate-filter{grid-template-columns:1fr}.valuation-rate-dialog :deep(.el-dialog){width:calc(100vw - 24px)!important;margin-top:18px!important}}
</style>
