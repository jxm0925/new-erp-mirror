<template>
  <div class="funding-page">
    <div class="page-title">
      <div><h1>付款条件</h1><p>销售订单选用的付款条件：收够多少钱才能开工；发货一律要求收齐全款。</p></div>
      <el-button v-if="canManage" class="primary" type="success" icon="el-icon-plus" @click="openCreate">新增付款条件</el-button>
    </div>
    <section class="filter-card">
      <label>编码 / 名称<el-input v-model.trim="query.keyword" clearable placeholder="请输入编码或名称" @keyup.enter.native="search" /></label>
      <label>状态<el-select v-model="query.status" clearable placeholder="全部状态"><el-option label="启用" value="enabled" /><el-option label="停用" value="disabled" /></el-select></label>
      <div class="filter-actions"><el-button class="primary" type="success" @click="search">查询</el-button><el-button @click="reset">重置</el-button></div>
    </section>
    <section class="table-card">
      <el-table v-loading="loading" :data="rows" border size="small" class="policy-table">
        <el-table-column prop="policy_code" label="编码" min-width="130" />
        <el-table-column prop="policy_name" label="名称" min-width="150" />
        <el-table-column label="类型" width="110"><template slot-scope="{ row }">{{ typeLabel(row.policy_type) }}</template></el-table-column>
        <el-table-column label="开工条件" min-width="170"><template slot-scope="{ row }">{{ startText(row) }}</template></el-table-column>
        <el-table-column label="发货条件" width="110"><template>收齐全款</template></el-table-column>
        <el-table-column label="状态" width="76" align="center"><template slot-scope="{ row }"><el-tag size="mini" :type="row.status === 'enabled' ? 'success' : 'info'">{{ row.status === 'enabled' ? '启用' : '停用' }}</el-tag></template></el-table-column>
        <el-table-column prop="remark" label="备注" min-width="160"><template slot-scope="{ row }">{{ row.remark || '--' }}</template></el-table-column>
        <el-table-column v-if="canManage" label="操作" width="120" align="center">
          <template slot-scope="{ row }">
            <el-button type="text" @click="openEdit(row)">编辑</el-button>
            <el-button type="text" :class="row.status === 'enabled' ? 'danger-text' : 'success-text'" @click="toggle(row)">{{ row.status === 'enabled' ? '停用' : '启用' }}</el-button>
          </template>
        </el-table-column>
      </el-table>
      <el-pagination background layout="total, prev, pager, next" :current-page="page" :page-size="10" :total="total" @current-change="p => { page = p; load() }" />
    </section>

    <el-dialog :title="form.id ? '编辑付款条件' : '新增付款条件'" :visible.sync="dialog" width="560px" custom-class="funding-dialog" :close-on-click-modal="false">
      <el-form label-width="96px" size="small">
        <el-form-item label="编码" required><el-input v-model.trim="form.policy_code" :disabled="!!form.id" maxlength="60" placeholder="字母、数字、下划线，例如 DEPOSIT_30" /></el-form-item>
        <el-form-item label="名称" required><el-input v-model.trim="form.policy_name" maxlength="120" placeholder="例如：定金 30% 开工" /></el-form-item>
        <el-form-item label="类型" required>
          <el-select v-model="form.policy_type" style="width:100%"><el-option v-for="t in types" :key="t.value" :label="t.label" :value="t.value" /></el-select>
        </el-form-item>
        <template v-if="form.policy_type !== 'full_prepay'">
          <el-form-item label="开工门槛" required>
            <el-radio-group v-model="form.production_threshold_type"><el-radio label="ratio">按比例</el-radio><el-radio label="amount">按金额</el-radio></el-radio-group>
          </el-form-item>
          <el-form-item :label="form.production_threshold_type === 'ratio' ? '收款比例' : '收款金额'" required>
            <el-input-number v-model="form.threshold" :min="0" :max="form.production_threshold_type === 'ratio' ? 100 : 999999999" :precision="2" controls-position="right" />
            <span class="unit">{{ form.production_threshold_type === 'ratio' ? '% 合同额' : '元' }}</span>
          </el-form-item>
        </template>
        <el-form-item v-else label="开工门槛"><span class="hint">收齐全款才能开工</span></el-form-item>
        <el-form-item label="发货条件"><el-checkbox :value="true" disabled>收齐全款才能发货</el-checkbox></el-form-item>
        <el-form-item label="备注"><el-input v-model.trim="form.remark" type="textarea" :rows="2" maxlength="1000" /></el-form-item>
      </el-form>
      <p class="hint">销售渠道可设置默认付款条件；改这里不影响已下的订单（订单保存时记下当时的条件）。</p>
      <span slot="footer"><el-button @click="dialog = false">取消</el-button><el-button class="primary" type="success" :loading="saving" @click="save">保存</el-button></span>
    </el-dialog>
  </div>
</template>

<script>
import { listFundingPolicies, createFundingPolicy, updateFundingPolicy, setFundingPolicyStatus } from '../../../api/erp/finance'

const TYPES = [
  { value: 'full_prepay', label: '全额预付' }, { value: 'deposit_production', label: '定金开工' },
  { value: 'installment_contract', label: '分期合同' }, { value: 'custom_threshold', label: '自定义门槛' }
]
const blank = () => ({ id: null, policy_code: '', policy_name: '', policy_type: 'deposit_production', production_threshold_type: 'ratio', threshold: 30, remark: '', business_version: 1 })

export default {
  name: 'FinanceFundingPolicyList',
  data: () => ({ loading: false, saving: false, rows: [], total: 0, page: 1, query: { keyword: '', status: '' }, dialog: false, form: blank(), types: TYPES }),
  computed: {
    canManage() { return this.$can('sales_order.funding_policy.manage') }
  },
  created() { this.load() },
  methods: {
    typeLabel(v) { return (TYPES.find(t => t.value === v) || {}).label || v },
    startText(row) {
      if (row.policy_type === 'full_prepay') return '收齐全款'
      const v = Number(row.production_threshold_value || 0)
      if (row.production_threshold_type === 'ratio') return v > 0 ? `收到合同额 ${Number((v * 100).toFixed(2))}%` : '不用收款即可开工'
      return v > 0 ? `收到 ¥${v.toLocaleString('zh-CN')}` : '不用收款即可开工'
    },
    async load() {
      this.loading = true
      try {
        const params = { ...this.query, page: this.page, per_page: 10 }
        Object.keys(params).forEach(k => { if (params[k] === '') delete params[k] })
        const { data } = await listFundingPolicies(params)
        this.rows = data.data || []
        this.total = data.total || 0
      } catch (e) {
        this.$message.error(e.userMessage || '付款条件加载失败')
      } finally {
        this.loading = false
      }
    },
    search() { this.page = 1; this.load() },
    reset() { this.query = { keyword: '', status: '' }; this.search() },
    openCreate() { this.form = blank(); this.dialog = true },
    openEdit(row) {
      const v = Number(row.production_threshold_value || 0)
      this.form = { id: row.id, policy_code: row.policy_code, policy_name: row.policy_name, policy_type: row.policy_type,
        production_threshold_type: row.production_threshold_type || 'ratio', threshold: row.production_threshold_type === 'ratio' ? Number((v * 100).toFixed(2)) : v,
        remark: row.remark || '', business_version: row.business_version || 1 }
      this.dialog = true
    },
    async save() {
      const f = this.form
      if (!f.id && !/^[A-Za-z0-9_-]+$/.test(f.policy_code)) return this.$message.warning('编码只能用字母、数字、下划线或短横线')
      if (!f.policy_name) return this.$message.warning('请填写名称')
      const full = f.policy_type === 'full_prepay'
      const payload = {
        policy_name: f.policy_name, policy_type: f.policy_type,
        production_threshold_type: full ? 'ratio' : f.production_threshold_type,
        production_threshold_value: full ? 1 : (f.production_threshold_type === 'ratio' ? Number((f.threshold / 100).toFixed(6)) : f.threshold),
        shipment_requires_full_payment: true, remark: f.remark || null
      }
      this.saving = true
      try {
        if (f.id) await updateFundingPolicy(f.id, { ...payload, expected_version: f.business_version })
        else await createFundingPolicy({ ...payload, policy_code: f.policy_code })
        this.$message.success('付款条件已保存')
        this.dialog = false
        this.load()
      } catch (e) {
        this.$message.error(e.userMessage || '保存失败')
      } finally {
        this.saving = false
      }
    },
    async toggle(row) {
      const next = row.status === 'enabled' ? 'disabled' : 'enabled'
      try {
        if (next === 'disabled') await this.$confirm('停用后新订单不能再选这个付款条件，已下的订单不受影响。', '停用付款条件', { type: 'warning' })
        await setFundingPolicyStatus(row.id, { status: next, expected_version: row.business_version || 1 })
        this.$message.success(next === 'enabled' ? '已启用' : '已停用')
        this.load()
      } catch (e) {
        if (e !== 'cancel' && e !== 'close') this.$message.error(e.userMessage || '操作失败')
      }
    }
  }
}
</script>

<style scoped>
.funding-page { padding: 26px 26px 40px; background: #f7f9fb; min-height: calc(100vh - 64px); box-sizing: border-box; color: #202938; }
.page-title { display: flex; align-items: flex-start; justify-content: space-between; gap: 14px; margin: 0 0 20px; }
.page-title h1 { margin: 0 0 7px; font-size: 27px; line-height: 1.25; color: #151d2b; }
.page-title p { margin: 0; font-size: 14px; color: #728095; }
.primary { background: #008c4a; border-color: #008c4a; }
.filter-card, .table-card { background: #fff; border: 1px solid #e0e6ee; border-radius: 6px; box-shadow: 0 2px 6px rgba(16, 24, 40, .04); }
.filter-card { display: grid; grid-template-columns: repeat(3, minmax(150px, 1fr)); gap: 18px 40px; padding: 20px 24px; margin-bottom: 10px; }
.filter-card label { display: block; font-size: 14px; font-weight: 600; color: #343d4a; }
.filter-card .el-input, .filter-card .el-select { width: 100%; display: block; margin-top: 7px; }
.filter-actions { display: flex; align-items: flex-end; gap: 12px; }
.table-card { padding: 14px 14px 16px; }
.policy-table { width: 100%; }
.el-pagination { margin: 18px 4px 0; text-align: right; }
.unit { margin-left: 8px; color: #728095; }
.hint { color: #728095; font-size: 12px; margin: 0; }
.danger-text { color: #f04438; }
.success-text { color: #079455; }
@media (max-width: 820px) {
  .funding-page { padding: 18px 14px; }
  .filter-card { grid-template-columns: 1fr; padding: 16px; }
  .table-card { overflow-x: auto; }
  .table-card .policy-table { min-width: 860px; }
  ::v-deep .funding-dialog { width: 94% !important; }
}
@media (max-width: 560px) {
  .page-title { flex-direction: column; }
}
</style>
