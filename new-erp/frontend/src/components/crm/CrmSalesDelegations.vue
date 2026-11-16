<template>
  <div class="sales-delegations">
    <div class="crm-inline">
      <span class="crm-muted">{{ selfService ? '我的销售代班' : '销售代班' }} · 共 {{ total }} 项</span>
      <el-button class="crm-actions" size="small" type="success" icon="el-icon-plus" @click="openCreate">新增代班</el-button>
    </div>
    <section class="crm-filters">
      <el-input v-model.trim="query.keyword" size="small" clearable :placeholder="selfService ? '代班人员姓名' : '原销售 / 代班人员姓名'" @keyup.enter.native="search" />
      <label>代班状态<el-select v-model="query.status" popper-class="crm-select-popper" size="small" clearable placeholder="全部" @change="search"><el-option v-for="(label, value) in statuses" :key="value" :label="label" :value="value" /></el-select></label>
      <el-button size="small" type="success" @click="search">查询</el-button>
      <el-button size="small" @click="reset">重置</el-button>
    </section>
    <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
    <section class="crm-card">
      <el-table v-loading="loading" :data="rows" size="small">
        <el-table-column prop="owner_name" label="原负责销售" min-width="120" />
        <el-table-column prop="delegate_name" label="代班人员" min-width="120" />
        <el-table-column label="有效日期" min-width="200"><template slot-scope="{row}">{{ shortDate(row.effective_from) }} 至 {{ shortDate(row.effective_to) }}</template></el-table-column>
        <el-table-column label="状态" min-width="100"><template slot-scope="{row}"><el-tag size="mini" :type="row.status === 'active' ? 'success' : 'info'">{{ statuses[row.status] || '—' }}</el-tag></template></el-table-column>
        <el-table-column label="原因" min-width="220"><template slot-scope="{row}">{{ row.reason || '—' }}<small v-if="row.end_reason" class="crm-sub">提前结束：{{ row.end_reason }}</small></template></el-table-column>
        <el-table-column label="经办人" min-width="130"><template slot-scope="{row}">{{ row.created_by_name || '—' }}<small v-if="row.ended_by_name" class="crm-sub">结束：{{ row.ended_by_name }}</small></template></el-table-column>
        <el-table-column label="操作" width="110" fixed="right"><template slot-scope="{row}"><el-button v-if="['scheduled', 'active'].includes(row.status)" type="text" size="mini" :loading="endingId === row.id" :disabled="!!endingId && endingId !== row.id" @click="endDelegation(row)">提前结束</el-button></template></el-table-column>
      </el-table>
      <footer class="crm-pager"><el-pagination background layout="total, sizes, prev, pager, next, jumper" :total="total" :current-page.sync="query.page" :page-size.sync="query.per_page" :page-sizes="[10,20,50,100]" @size-change="search" @current-change="load" /></footer>
    </section>

    <el-dialog class="crm-dialog" :title="selfService ? '安排我的销售代班' : '新增销售代班'" :visible.sync="editor.visible" width="780px" append-to-body :close-on-click-modal="false" :before-close="closeCreate">
      <el-form v-if="editor.visible" label-width="106px" size="small" :disabled="editor.saving">
        <div class="crm-form-grid">
          <el-form-item :label="selfService ? '请假销售' : '原负责销售'" required><el-input v-if="selfService" :value="staffLabel(currentUser)" disabled /><paged-record-select v-else :key="'owner-' + session" v-model="editor.form.owner_legacy_id" :request="listStaff" :category-request="staffCategories" :params="{ effective_only: 1 }" :columns="staffColumns" :label="staffLabel" title="选择原负责销售" placeholder="搜索在职销售" :disabled="editor.saving" /></el-form-item>
          <el-form-item label="代班人员" required><paged-record-select :key="'delegate-' + session" v-model="editor.form.delegate_legacy_id" :request="listStaff" :category-request="staffCategories" :params="selfService ? {} : { effective_only: 1 }" :columns="staffColumns" :label="staffLabel" title="选择代班人员" placeholder="搜索在职销售" :disabled="editor.saving" /></el-form-item>
          <el-form-item label="开始日期" required><el-date-picker v-model="editor.form.effective_from" popper-class="crm-date-popper" type="date" value-format="yyyy-MM-dd" placeholder="选择开始日期" /></el-form-item>
          <el-form-item label="结束日期" required><el-date-picker v-model="editor.form.effective_to" popper-class="crm-date-popper" type="date" value-format="yyyy-MM-dd" placeholder="选择结束日期（含当日）" /></el-form-item>
          <el-form-item label="代班原因" required class="crm-span-all"><el-input v-model.trim="editor.form.reason" type="textarea" :rows="3" maxlength="500" show-word-limit placeholder="填写请假等代班原因" /></el-form-item>
        </div>
      </el-form>
      <div class="crm-note">一次设置，在包含开始、结束当日的日期内统一代办原销售的线索、商机和订单；负责人、销售归属和历史业绩保持不变，办理仍按代班人员本身权限校验。到期或提前结束后停止代班。</div>
      <span slot="footer"><el-button size="small" :disabled="editor.saving" @click="closeCreate">取消</el-button><el-button size="small" type="success" :loading="editor.saving" @click="save">保存代班</el-button></span>
    </el-dialog>
  </div>
</template>

<script>
import PagedRecordSelect from '@/components/common/PagedRecordSelect.vue'
import { listCrmTeamStaff, listCrmSalesDelegations, createCrmSalesDelegation, endCrmSalesDelegation, listMyCrmSalesDelegations, createMyCrmSalesDelegation, endMyCrmSalesDelegation, listCrmSalesDelegationCandidates, crmError } from '@/api/erp/crm'
import { erpDateTime } from '@/utils/dateTime'

const blankQuery = (perPage = 10) => ({ keyword: '', status: '', page: 1, per_page: perPage })
const blankForm = () => ({ owner_legacy_id: null, delegate_legacy_id: null, effective_from: erpDateTime(new Date().toISOString(), 10), effective_to: '', reason: '' })

export default {
  name: 'CrmSalesDelegations',
  components: { PagedRecordSelect },
  props: {
    departments: { type: Array, default: () => [] },
    selfService: Boolean,
    currentUser: { type: Object, default: () => ({}) }
  },
  data: () => ({
    rows: [], total: 0, query: blankQuery(), loading: false, error: '', sequence: 0, session: 0, endingId: null,
    statuses: { scheduled: '未开始', active: '代班中', expired: '已到期', ended: '已提前结束' },
    staffColumns: [{ prop: 'name', label: '销售人员' }, { prop: 'department_name', label: '所属部门' }],
    editor: { visible: false, saving: false, form: blankForm() }
  }),
  created() { this.load() },
  beforeDestroy() { this.sequence += 1; this.session += 1 },
  methods: {
    listStaff({ category_id, ...params }) {
      const request = this.selfService ? listCrmSalesDelegationCandidates : listCrmTeamStaff
      return request({ ...params, ...(category_id ? { department_id: category_id } : {}) })
    },
    staffCategories() {
      const rows = this.departments.map(row => ({ ...row, category_name: row.name, children: [] }))
      const byId = new Map(rows.map(row => [Number(row.id), row]))
      const roots = []
      rows.forEach(row => { const parent = byId.get(Number(row.parent_id)); if (parent && parent !== row) parent.children.push(row); else roots.push(row) })
      return { data: { data: roots } }
    },
    staffLabel(row) { return [row.name, row.department_name].filter(Boolean).join(' / ') },
    shortDate(value) { return erpDateTime(value, 10) || '—' },
    search() { this.query.page = 1; this.load() },
    reset() { this.query = blankQuery(this.query.per_page); this.load() },
    async load() {
      const sequence = ++this.sequence
      this.loading = true
      this.error = ''
      try {
        const request = this.selfService ? listMyCrmSalesDelegations : listCrmSalesDelegations
        const { data } = await request({ ...this.query })
        if (sequence !== this.sequence) return
        this.rows = data.data || []
        this.total = data.total || 0
      } catch (error) {
        if (sequence !== this.sequence) return
        this.rows = []
        this.total = 0
        this.error = crmError(error, '销售代班列表加载失败')
      } finally {
        if (sequence === this.sequence) this.loading = false
      }
    },
    openCreate() {
      this.session += 1
      this.editor = { visible: true, saving: false, form: blankForm() }
    },
    closeCreate() {
      if (this.editor.saving) return
      this.session += 1
      this.editor.visible = false
    },
    async save() {
      if (this.editor.saving || !this.editor.visible) return
      const form = this.editor.form
      const ownerId = this.selfService ? this.currentUser.id : form.owner_legacy_id
      if (!ownerId || !form.delegate_legacy_id || !form.effective_from || !form.effective_to || !form.reason) return this.$message.warning('请填写原销售、代班人员、有效日期和原因')
      if (String(ownerId) === String(form.delegate_legacy_id)) return this.$message.warning('代班人员不能与原负责销售相同')
      if (form.effective_to < form.effective_from) return this.$message.warning('结束日期不能早于开始日期')
      const session = this.session
      this.editor.saving = true
      try {
        if (this.selfService) {
          const { owner_legacy_id, ...payload } = form
          await createMyCrmSalesDelegation(payload)
        } else await createCrmSalesDelegation({ ...form })
        if (session !== this.session) return
        this.editor.visible = false
        this.$message.success('销售代班已保存')
        this.search()
      } catch (error) {
        if (session === this.session) this.$message.error(crmError(error, '销售代班保存失败'))
      } finally {
        if (session === this.session) this.editor.saving = false
      }
    },
    async endDelegation(row) {
      if (this.endingId || !['scheduled', 'active'].includes(row.status)) return
      this.endingId = row.id
      try {
        const { value } = await this.$prompt(`提前结束${row.delegate_name}为${row.owner_name}办理的销售代班，请填写原因`, '提前结束代班', {
          inputType: 'textarea', confirmButtonText: '提前结束', cancelButtonText: '取消',
          inputValidator: value => value && value.trim() && value.trim().length <= 500 ? true : '请填写500字以内的结束原因'
        })
        if (this._isDestroyed) return
        const request = this.selfService ? endMyCrmSalesDelegation : endCrmSalesDelegation
        await request(row.id, { reason: value.trim() })
        if (this._isDestroyed) return
        this.$message.success('销售代班已提前结束')
        this.load()
      } catch (error) {
        if (!this._isDestroyed && error !== 'cancel' && error !== 'close') this.$message.error(crmError(error, '结束销售代班失败'))
      } finally {
        if (!this._isDestroyed) this.endingId = null
      }
    }
  }
}
</script>

<style scoped>
.sales-delegations {
  display: flex;
  flex-direction: column;
  gap: 12px;
  min-width: 0;
  box-sizing: border-box;
}
.sales-delegations .crm-filters label {
  white-space: nowrap;
}
</style>
