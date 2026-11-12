<template>
  <div class="crm-page workbench-page">
    <header class="crm-head workbench-head">
      <span class="crm-head-icon"><i class="el-icon-date" /></span>
      <h1>我的工作台</h1>
      <div class="crm-actions">
        <span class="crm-muted">{{ actor.name }}{{ actor.department_name ? ' / ' + actor.department_name : '' }}</span>
        <span class="crm-muted">{{ workbench.date || '' }}</span>
        <el-dropdown v-if="canViewLeads" @command="openLeads">
          <el-button size="small">线索筛选<i class="el-icon-arrow-down el-icon--right" /></el-button>
          <el-dropdown-menu slot="dropdown" class="crm-select-popper">
            <el-dropdown-item :command="{ tab: 'all' }">全部可见线索</el-dropdown-item>
            <el-dropdown-item v-for="item in leadEntries" :key="item.key" :command="item.query">{{ item.label }}（{{ item.count }}）</el-dropdown-item>
          </el-dropdown-menu>
        </el-dropdown>
        <el-button size="small" icon="el-icon-refresh" :loading="loading" @click="refresh">刷新</el-button>
        <el-button v-if="(metadata.abilities || {}).create" size="small" type="success" icon="el-icon-plus" @click="$refs.form.open()">新增线索</el-button>
      </div>
    </header>

    <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
    <section class="workbench-cards" v-loading="loading">
      <button v-for="item in cards" :key="item.key" type="button" class="workbench-card" :class="[item.tone, { active: query.type === item.key }]" :disabled="!taskAbilities[item.key]" @click="selectType(item.key)">
        <i :class="item.icon" />
        <span><span class="card-label">{{ item.label }}</span><strong>{{ workbench.summary ? workbench.summary[item.key] : '—' }}</strong></span>
      </button>
    </section>

    <section>
      <h2 class="crm-section-title">今日工作</h2>
      <el-tabs v-model="query.type" @tab-click="searchTasks">
        <el-tab-pane v-for="item in taskTabs" :key="item.key" :name="item.key" :label="item.label + (workbench.summary ? ' ' + workbench.summary[item.key] : '')" />
      </el-tabs>
      <el-alert v-if="tasks.error" :title="tasks.error" type="error" :closable="false" show-icon />
      <div class="crm-card workbench-table">
        <el-table v-loading="tasks.loading" :data="tasks.rows" size="small" :row-class-name="taskRowClass">
          <el-table-column label="客户 / 商机" min-width="260"><template slot-scope="{row}"><el-button type="text" size="mini" @click="viewTask(row)">{{ row.customer_name || '—' }}</el-button><span v-if="row.title && row.title !== row.customer_name"> / {{ row.title }}</span></template></el-table-column>
          <el-table-column label="计划时间" min-width="155"><template slot-scope="{row}">{{ date(row.planned_at) }}</template></el-table-column>
          <el-table-column label="联系方式" min-width="110"><template slot-scope="{row}">{{ row.contact_method_label || '—' }}</template></el-table-column>
          <el-table-column label="状态" min-width="120"><template slot-scope="{row}"><el-tag size="mini" :type="row.is_overdue ? 'danger' : 'info'">{{ row.status_label || '—' }}</el-tag></template></el-table-column>
          <el-table-column label="操作" width="155"><template slot-scope="{row}"><div class="crm-inline"><el-button v-if="canAct(row)" type="success" size="mini" :disabled="acting" @click="actTask(row)">{{ actionLabels[row.action] }}</el-button><el-button v-else type="text" size="mini" @click="viewTask(row)">查看</el-button></div></template></el-table-column>
          <template slot="empty"><span class="crm-muted">{{ tasks.error || '当前没有待办工作' }}</span></template>
        </el-table>
        <footer class="crm-pager"><el-pagination background layout="total, sizes, prev, pager, next" :total="tasks.total" :current-page.sync="query.page" :page-size.sync="query.per_page" :page-sizes="[10,20,50]" @size-change="searchTasks" @current-change="loadTasks" /></footer>
      </div>
    </section>

    <section v-if="canViewOpportunities">
      <h2 class="crm-section-title">近期商机</h2>
      <el-alert v-if="opportunities.error" :title="opportunities.error" type="error" :closable="false" show-icon />
      <div class="crm-card">
        <el-table v-loading="opportunities.loading" :data="opportunities.rows" size="small">
          <el-table-column label="商机" min-width="260"><template slot-scope="{row}"><el-button type="text" size="mini" @click="$refs.opportunity.open(row.id)">{{ row.customer_name }} / {{ row.name }}</el-button></template></el-table-column>
          <el-table-column label="预计金额" min-width="150" align="right"><template slot-scope="{row}">{{ money(row.expected_amount, row.currency) }}</template></el-table-column>
          <el-table-column prop="stage_label" label="阶段" min-width="140" />
          <el-table-column prop="owner_name" label="负责人" min-width="110" />
          <el-table-column label="目标日期" min-width="130"><template slot-scope="{row}">{{ row.expected_close_date ? date(row.expected_close_date).slice(0, 10) : '—' }}</template></el-table-column>
          <template slot="empty"><span class="crm-muted">{{ opportunities.error || '暂无近期商机' }}</span></template>
        </el-table>
        <footer class="crm-pager"><el-pagination background layout="total, prev, pager, next" :total="opportunities.total" :current-page.sync="opportunities.page" :page-size="10" @current-change="loadOpportunities" /></footer>
      </div>
    </section>

    <crm-lead-form-dialog ref="form" @saved="refresh" />
    <crm-lead-detail-dialog ref="lead" @changed="refresh" />
    <crm-lead-action-dialog ref="action" @changed="refresh" />
    <crm-followup-dialog ref="follow" @saved="refresh" />
    <crm-identity-review-dialog ref="review" @saved="refresh" />
    <crm-opportunity-detail-dialog ref="opportunity" @changed="refresh" />
    <customer-detail-dialog ref="customer" @changed="refresh" />
  </div>
</template>

<script>
import { getCrmWorkbench, listCrmWorkbenchTasks, getCrmLeadMetadata, getCrmLead, listCrmOpportunities, crmDate, crmError } from '@/api/erp/crm'
import CrmLeadFormDialog from '@/components/crm/CrmLeadFormDialog.vue'
import CrmLeadDetailDialog from '@/components/crm/CrmLeadDetailDialog.vue'
import CrmLeadActionDialog from '@/components/crm/CrmLeadActionDialog.vue'
import CrmFollowupDialog from '@/components/crm/CrmFollowupDialog.vue'
import CrmIdentityReviewDialog from '@/components/crm/CrmIdentityReviewDialog.vue'
import CrmOpportunityDetailDialog from '@/components/crm/CrmOpportunityDetailDialog.vue'
import '@/styles/crm.css'

const cards = [
  { key: 'today', label: '今日联系', icon: 'el-icon-phone-outline', tone: 'today' },
  { key: 'overdue', label: '逾期跟进', icon: 'el-icon-document', tone: 'overdue' },
  { key: 'pending_receive', label: '待接收', icon: 'el-icon-box', tone: 'receive' }
]
const extraTabs = [{ key: 'pending_confirm', label: '待基础确认' }, { key: 'pending_assign', label: '待分配' }, { key: 'pending_verify', label: '待核实' }]

export default {
  name: 'CrmWorkbench',
  components: { CrmLeadFormDialog, CrmLeadDetailDialog, CrmLeadActionDialog, CrmFollowupDialog, CrmIdentityReviewDialog, CrmOpportunityDetailDialog, CustomerDetailDialog: () => import('@/components/sales/CustomerDetailDialog.vue') },
  data: () => ({ workbench: {}, metadata: {}, query: { type: 'all', page: 1, per_page: 10 }, tasks: { rows: [], total: 0, loading: false, error: '', sequence: 0 }, opportunities: { rows: [], total: 0, page: 1, loading: false, error: '', sequence: 0 }, loading: false, error: '', sequence: 0, acting: false, cards, actionLabels: { followup: '跟进', receive: '接收', confirm: '基础确认', assign: '分配', verify: '核实' } }),
  computed: {
    actor() { return this.workbench.current_user || {} },
    taskAbilities() { return this.workbench.task_abilities || {} },
    canViewLeads() { return !this.$can || this.$can('crm.lead.view') },
    canViewOpportunities() { return !this.$can || this.$can('crm.opportunity.view') },
    taskTabs() { return [{ key: 'all', label: '全部待办' }, ...cards, ...extraTabs].filter(item => item.key === 'all' || this.taskAbilities[item.key]) },
    leadEntries() {
      return [
        ...['customer_pools', 'customer_tags', 'custom_tags', 'lead_statuses'].flatMap(key => (this.workbench[key] || []).map(item => ({ key: key + item.name, label: item.name, count: item.count, query: item.query })))
      ]
    }
  },
  created() { this.refresh() },
  beforeDestroy() { this.sequence += 1; this.tasks.sequence += 1; this.opportunities.sequence += 1 },
  methods: {
    date: crmDate,
    money(value, currency) { return value === null || value === undefined || value === '' ? '—' : `${currency || 'CNY'} ${Number(value).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}` },
    async refresh() { await Promise.all([this.loadSummary(), this.loadTasks(), this.loadOpportunities()]) },
    async loadSummary() {
      const sequence = ++this.sequence
      this.loading = true
      this.error = ''
      try {
        const { data } = await getCrmWorkbench()
        if (sequence !== this.sequence) return
        this.workbench = data.data || data
        if (this.canViewLeads) {
          try {
            const { data: metadata } = await getCrmLeadMetadata()
            if (sequence === this.sequence) this.metadata = metadata.data || metadata
          } catch (error) {
            if (sequence === this.sequence) {
              this.metadata = {}
              this.$message.error(crmError(error, '线索权限加载失败'))
            }
          }
        }
      } catch (error) {
        if (sequence !== this.sequence) return
        this.workbench = {}
        this.metadata = {}
        this.error = crmError(error, '工作台加载失败')
      } finally { if (sequence === this.sequence) this.loading = false }
    },
    selectType(type) { this.query.type = type; this.searchTasks() },
    searchTasks() { this.query.page = 1; this.loadTasks() },
    async loadTasks() {
      const sequence = ++this.tasks.sequence
      this.tasks.loading = true
      this.tasks.error = ''
      try {
        const { data } = await listCrmWorkbenchTasks({ ...this.query })
        if (sequence !== this.tasks.sequence) return
        this.tasks.rows = data.data || []
        this.tasks.total = data.total || 0
      } catch (error) {
        if (sequence !== this.tasks.sequence) return
        this.tasks.rows = []
        this.tasks.total = 0
        this.tasks.error = crmError(error, '待办加载失败')
      } finally { if (sequence === this.tasks.sequence) this.tasks.loading = false }
    },
    async loadOpportunities() {
      if (!this.canViewOpportunities) return
      const sequence = ++this.opportunities.sequence
      this.opportunities.loading = true
      this.opportunities.error = ''
      try {
        const { data } = await listCrmOpportunities({ tab: 'mine', page: this.opportunities.page, per_page: 10 })
        if (sequence !== this.opportunities.sequence) return
        this.opportunities.rows = data.data || []
        this.opportunities.total = data.total || 0
      } catch (error) {
        if (sequence !== this.opportunities.sequence) return
        this.opportunities.rows = []
        this.opportunities.total = 0
        this.opportunities.error = crmError(error, '近期商机加载失败')
      } finally { if (sequence === this.opportunities.sequence) this.opportunities.loading = false }
    },
    openLeads(query) { this.$router.push({ path: '/sales/leads', query: { tab: 'all', ...query } }) },
    taskRowClass({ row }) { return row.is_overdue ? 'workbench-overdue-row' : '' },
    canAct(row) { return !!((row.allowed || {})[({ followup: 'follow', receive: 'accept' })[row.action] || row.action] && this.actionLabels[row.action]) },
    viewTask(row) {
      if (row.target_type === 'lead') this.$refs.lead.open(row.target_id)
      else if (row.target_type === 'opportunity') this.$refs.opportunity.open(row.target_id)
      else if (row.target_type === 'customer') this.$refs.customer.open(row.target_id)
    },
    async actTask(row) {
      if (this.acting || !this.canAct(row)) return
      if (row.action === 'followup') {
        this.$refs.follow.open({ subject_type: row.target_type, subject_id: row.target_id, customer_id: row.customer_id, customer_name: row.customer_name, opportunity_name: row.target_type === 'opportunity' ? row.title : '', lead_no: row.lead_no, contact_name: row.contact_name })
      } else if (row.action === 'verify') {
        const sequence = this.sequence
        this.acting = true
        try {
          const { data } = await getCrmLead(row.target_id)
          if (sequence !== this.sequence) return
          const lead = data.data || data
          if (lead.review && lead.review.can_resolve) this.$refs.review.openReview(lead.review)
          else this.$message.warning('该线索状态或权限已变化，请刷新后重新选择')
        } catch (error) { if (sequence === this.sequence) this.$message.error(crmError(error, '线索加载失败')) }
        finally { this.acting = false }
      } else this.$refs.action.open(row.action === 'receive' ? 'accept' : row.action, row.target_id)
    }
  }
}
</script>

<style scoped>
.workbench-page {
  background: #fff;
  gap: 20px;
}
.workbench-head {
  padding-bottom: 14px;
  border-bottom: 1px solid #e2e8f0;
}
.workbench-cards {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 16px;
}
.workbench-card {
  display: flex;
  align-items: center;
  gap: 24px;
  padding: 15px 22px;
  text-align: left;
  border: 1px solid transparent;
  border-radius: 8px;
  background: #f1f5f9;
  color: #1e293b;
  cursor: pointer;
  min-width: 0;
}
.workbench-card.today {
  background: #f0f9f5;
}
.workbench-card.overdue {
  background: #fdf3f4;
}
.workbench-card.active {
  border-color: #008b4b;
}
.workbench-card:disabled {
  cursor: default;
}
.workbench-card > i {
  display: grid;
  place-items: center;
  width: 48px;
  height: 48px;
  border-radius: 10px;
  background: #e2e8f0;
  color: #475569;
  font-size: 28px;
  flex-shrink: 0;
}
.workbench-card.today > i {
  background: #dcf5e7;
  color: #008b4b;
}
.workbench-card.overdue > i {
  background: #fce6e8;
  color: #dc2626;
}
.card-label {
  display: block;
  font-size: 14px;
  margin-bottom: 4px;
}
.workbench-card strong {
  font-size: 26px;
}
.workbench-table {
  margin-top: 12px;
}
@media (max-width: 780px) {
  .workbench-cards {
    grid-template-columns: minmax(0, 1fr);
  }
  .workbench-card {
    gap: 16px;
  }
}
</style>
