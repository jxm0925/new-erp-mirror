<template>
  <div class="crm-page workbench-page">
    <!-- 顶部页头 + 个人当月业绩条 -->
    <header class="page-head-card">
      <div class="head-left">
        <span class="crm-head-icon"><i class="el-icon-date" /></span>
        <div class="head-titles">
          <div class="title-row">
            <h1>我的工作台</h1>
            <span v-if="actor.name" class="user-pill">
              <i class="el-icon-user" />
              {{ actor.name }}{{ actor.department_name ? ' · ' + actor.department_name : '' }}
            </span>
            <span v-if="workbench.date" class="date-pill">
              <i class="el-icon-time" />
              {{ workbench.date }} {{ weekdayLabel }}
            </span>
          </div>
          <p class="head-sub">聚焦今日待联系、逾期提醒、重点商机推进与本人订单交付回款，一站式推进日常跟单</p>
        </div>
      </div>

      <!-- 个人当月业绩条 -->
      <div v-if="hasResultsPanel" class="perf-strip" v-loading="results.loading">
        <button
          v-if="resultAbilities.orders"
          type="button"
          class="perf-item is-clickable"
          title="查看本月成交订单"
          @click="jumpBusinessTab('month_orders')"
        >
          <span class="perf-lbl">本月订单</span>
          <strong class="perf-val">{{ displayCount(resultsData.order_count) }} <small>单</small></strong>
        </button>

        <template v-if="resultAbilities.amounts && activeCurrencySummary">
          <div class="perf-item">
            <span class="perf-lbl">
              成交{{ currencyTag }}
              <button
                v-if="currencyList.length > 1"
                type="button"
                class="currency-cycle-btn"
                title="切换币种"
                @click="cycleCurrency"
              >切换</button>
            </span>
            <strong class="perf-val">{{ formatMoney(activeCurrencySummary.deal_amount, activeCurrencySummary.currency) }}</strong>
          </div>
          <div class="perf-item">
            <span class="perf-lbl">已收{{ currencyTag }}</span>
            <strong class="perf-val is-green">{{ formatMoney(activeCurrencySummary.received_amount, activeCurrencySummary.currency) }}</strong>
          </div>
          <button
            type="button"
            class="perf-item is-clickable is-amber-box"
            title="查看待收尾款订单"
            @click="jumpBusinessTab('outstanding')"
          >
            <span class="perf-lbl">待收尾款{{ currencyTag }}</span>
            <strong class="perf-val is-amber">{{ formatMoney(activeCurrencySummary.outstanding_amount, activeCurrencySummary.currency) }}</strong>
          </button>
        </template>

        <div v-if="resultAbilities.followups" class="perf-item">
          <span class="perf-lbl">今日已跟进</span>
          <strong class="perf-val is-green">{{ displayCount(resultsData.today_followup_count) }} <small>次</small></strong>
        </div>

        <button
          v-if="resultAbilities.aftersales"
          type="button"
          class="perf-item is-clickable"
          title="查看处理中售后单"
          @click="jumpBusinessTab('aftersales')"
        >
          <span class="perf-lbl">处理中售后</span>
          <strong class="perf-val" :class="{ 'is-red': Number(resultsData.aftersales_count) > 0 }">
            {{ displayCount(resultsData.aftersales_count) }} <small>单</small>
          </strong>
        </button>
      </div>

      <div class="crm-actions">
        <el-button size="small" icon="el-icon-refresh" :loading="loading" @click="refresh">刷新</el-button>
        <el-button v-if="canCreateOpportunity" size="small" icon="el-icon-aim" @click="$refs.opportunityForm.open()">新建商机</el-button>
        <el-button v-if="canCreateLead" size="small" type="success" icon="el-icon-plus" @click="$refs.form.open()">新增线索</el-button>
      </div>
    </header>

    <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon>
      <el-button type="text" size="mini" @click="refresh">重试</el-button>
    </el-alert>

    <!-- 顶层待办队列指标卡（替代重复Tab） -->
    <section v-loading="loading" class="workbench-kpi-grid">
      <button
        v-for="card in taskQueueCards"
        :key="card.key"
        type="button"
        class="kpi-card"
        :class="[card.tone, { active: query.type === card.key }]"
        @click="selectType(card.key)"
      >
        <div class="kpi-top">
          <span class="kpi-label">{{ card.label }}</span>
          <i :class="[card.icon, 'kpi-icon']" />
        </div>
        <div class="kpi-val" :class="card.valCls">
          {{ workbench.summary && workbench.summary[card.key] !== undefined ? workbench.summary[card.key] : '—' }}
        </div>
        <div class="kpi-desc">{{ card.desc }}</div>
      </button>
    </section>

    <!-- 中间双栏主工作区：左 60% 今日工作清单 / 右 40% 重点商机 + 订单履约 -->
    <div class="workbench-main-grid">
      <!-- 左栏：今日工作清单 -->
      <section class="crm-card panel-card tasks-panel">
        <div class="panel-head">
          <div class="panel-title-wrap">
            <h2 class="crm-section-title">今日工作清单</h2>
            <span class="panel-sub-pill">{{ currentTaskLabel }}</span>
          </div>
          <div class="panel-head-actions">
            <el-button v-if="canViewLeads" type="text" size="mini" @click="openLeads({ tab: 'mine' })">
              进入线索中心 <i class="el-icon-arrow-right" />
            </el-button>
          </div>
        </div>

        <el-alert v-if="tasks.error" :title="tasks.error" type="error" :closable="false" show-icon class="panel-alert" />

        <el-table v-loading="tasks.loading" :data="tasks.rows" size="small" :row-class-name="taskRowClass" class="workbench-table">
          <el-table-column label="类型 / 客户与联系人" min-width="210">
            <template slot-scope="{ row }">
              <div class="cell-primary-line">
                <span class="target-badge" :class="'is-' + row.target_type">{{ targetTypeLabel(row.target_type) }}</span>
                <el-button type="text" size="mini" class="subject-link" @click="viewTask(row)">
                  {{ row.customer_name || '未命名客户' }}
                </el-button>
              </div>
              <div v-if="row.title && row.title !== row.customer_name" class="cell-sub-line text-dark">
                {{ row.title }}
              </div>
              <div class="cell-sub-line">
                <span v-if="row.contact_name"><i class="el-icon-user" /> {{ row.contact_name }}</span>
                <span v-if="row.contact_name && row.contact_method_label" class="sep">·</span>
                <span v-if="row.contact_method_label" class="method-pill">
                  <i :class="methodIcon(row.contact_method_label)" /> {{ row.contact_method_label }}
                </span>
              </div>
            </template>
          </el-table-column>

          <el-table-column label="最近沟通与本次计划" min-width="230">
            <template slot-scope="{ row }">
              <div v-if="row.next_plan" class="plan-line">
                <span class="ctx-lbl is-green">计划</span>
                <span>{{ row.next_plan }}</span>
              </div>
              <div v-if="row.last_contact_content" class="last-line">
                <span class="ctx-lbl">上次</span>
                <span>{{ row.last_contact_content }}</span>
              </div>
              <span v-if="!row.next_plan && !row.last_contact_content" class="crm-muted">暂无沟通摘要，可直接记录跟进</span>
            </template>
          </el-table-column>

          <el-table-column label="计划时间 / 待办标签" min-width="150">
            <template slot-scope="{ row }">
              <div class="time-line" :class="{ 'crm-overdue text-strong': row.is_overdue }">
                {{ date(row.planned_at) }}
              </div>
              <div class="tags-wrap">
                <template v-if="(row.task_labels || []).length">
                  <el-tag
                    v-for="badge in row.task_labels"
                    :key="badge.key"
                    size="mini"
                    :type="badge.type"
                    class="task-tag"
                  >{{ badge.label }}</el-tag>
                </template>
                <el-tag v-else size="mini" :type="row.is_overdue ? 'danger' : 'info'" class="task-tag">
                  {{ row.status_label || '待办' }}
                </el-tag>
              </div>
            </template>
          </el-table-column>

          <el-table-column label="快捷办理" min-width="165" fixed="right">
            <template slot-scope="{ row }">
              <div class="row-actions-wrap">
                <template v-if="rowActions(row).length">
                  <el-button
                    v-for="(act, idx) in rowActions(row).slice(0, 2)"
                    :key="act"
                    :type="idx === 0 ? 'success' : ''"
                    :plain="idx !== 0"
                    size="mini"
                    :disabled="acting"
                    @click="actTask(row, act)"
                  >{{ actionLabels[act] || act }}</el-button>
                </template>
                <el-button type="text" size="mini" @click="viewTask(row)">查看</el-button>
              </div>
            </template>
          </el-table-column>

          <template slot="empty">
            <div class="wb-empty">
              <i class="el-icon-circle-check" />
              <span>{{ tasks.error || '当前队列暂无待办事项' }}</span>
            </div>
          </template>
        </el-table>

        <footer class="crm-pager table-footer">
          <span class="crm-muted">当前队列：<b>{{ currentTaskLabel }}</b> · 共 {{ tasks.total }} 条</span>
          <el-pagination
            background
            layout="sizes, prev, pager, next"
            :total="tasks.total"
            :current-page.sync="query.page"
            :page-size.sync="query.per_page"
            :page-sizes="[10, 20, 50]"
            @size-change="searchTasks"
            @current-change="loadTasks"
          />
        </footer>
      </section>

      <!-- 右栏：重点商机推进 + 订单履约与尾款售后 -->
      <div class="workbench-side-stack">
        <!-- 右上：重点商机推进 -->
        <section v-if="canViewOpportunities" class="crm-card panel-card">
          <div class="panel-head">
            <h2 class="crm-section-title">重点商机推进</h2>
            <div class="seg-pills">
              <button
                v-for="tab in priorityTabs"
                :key="tab.key"
                type="button"
                class="seg-pill"
                :class="{ active: priorities.type === tab.key }"
                @click="selectPriorityType(tab.key)"
              >{{ tab.label }}</button>
            </div>
          </div>

          <el-alert v-if="priorities.error" :title="priorities.error" type="error" :closable="false" show-icon class="panel-alert" />

          <div v-loading="priorities.loading" class="side-list">
            <div v-for="item in priorities.rows" :key="item.id" class="side-list-item">
              <div class="side-item-main">
                <div class="side-item-top">
                  <el-button type="text" size="mini" class="subject-link" @click="$refs.opportunity.open(item.id)">
                    {{ item.customer_name }} · {{ item.name }}
                  </el-button>
                </div>
                <div class="side-item-meta">
                  <span class="meta-chip">{{ item.stage_label }}</span>
                  <span v-if="item.expected_amount !== null && item.expected_amount !== undefined" class="meta-chip is-amber">
                    {{ formatMoney(item.expected_amount, item.currency) }}
                  </span>
                  <span v-if="priorities.type === 'stalled' && item.stalled_days !== null" class="meta-chip is-red">
                    停滞 {{ item.stalled_days }} 天
                  </span>
                  <span v-else-if="item.expected_close_date" class="meta-text" :class="{ 'crm-overdue': item.is_close_overdue }">
                    预计 {{ item.expected_close_date }}{{ item.is_close_overdue ? '（已逾期）' : '' }}
                  </span>
                </div>
                <div v-if="item.latest_progress" class="side-item-sub">最新进展：{{ item.latest_progress }}</div>
              </div>
              <div class="side-item-ops">
                <el-button
                  v-if="(item.abilities || {}).follow"
                  type="success"
                  plain
                  size="mini"
                  @click="followOpportunity(item)"
                >跟进</el-button>
                <el-button type="text" size="mini" @click="$refs.opportunity.open(item.id)">查看</el-button>
              </div>
            </div>
            <div v-if="!priorities.loading && !priorities.rows.length" class="side-empty">
              <i class="el-icon-aim" />
              <span>{{ priorities.error || '当前分类暂无重点推进商机' }}</span>
            </div>
          </div>

          <footer class="crm-pager side-footer">
            <el-button type="text" size="mini" @click="$router.push('/sales/opportunities')">全部商机</el-button>
            <el-pagination
              v-if="priorities.total > 0"
              background
              small
              layout="total, prev, pager, next"
              :total="priorities.total"
              :current-page.sync="priorities.page"
              :page-size="priorities.per_page"
              @current-change="loadPriorities"
            />
          </footer>
        </section>

        <!-- 右下：订单履约与尾款售后 -->
        <section v-if="businessTabs.length" class="crm-card panel-card">
          <div class="panel-head">
            <h2 class="crm-section-title">订单履约与尾款售后</h2>
            <div class="seg-pills">
              <button
                v-for="tab in businessTabs"
                :key="tab.key"
                type="button"
                class="seg-pill"
                :class="{ active: business.type === tab.key }"
                @click="selectBusinessType(tab.key)"
              >{{ tab.label }}</button>
            </div>
          </div>

          <el-alert v-if="business.error" :title="business.error" type="error" :closable="false" show-icon class="panel-alert" />

          <div v-loading="business.loading" class="side-list">
            <template v-if="business.type === 'aftersales'">
              <div v-for="item in business.rows" :key="item.id" class="side-list-item">
                <div class="side-item-main">
                  <div class="side-item-top">
                    <el-button type="text" size="mini" class="subject-link" @click="openAftersales(item)">
                      {{ item.case_no }}
                    </el-button>
                    <el-tag size="mini" type="warning">{{ item.status_label }}</el-tag>
                  </div>
                  <div class="side-item-meta">
                    <el-button v-if="item.can_view_customer && item.customer_id" type="text" size="mini" class="cust-inline-link" @click="$refs.customer.open(item.customer_id)">
                      {{ item.customer_name || '—' }}
                    </el-button>
                    <span v-else class="meta-text">{{ item.customer_name || '—' }}</span>
                    <span v-if="item.sales_order_no" class="meta-text">· {{ item.sales_order_no }}</span>
                    <span v-if="item.reported_at" class="meta-text">· {{ shortDate(item.reported_at) }}</span>
                  </div>
                  <div v-if="item.description" class="side-item-sub">{{ item.description }}</div>
                </div>
                <div class="side-item-ops">
                  <el-button type="success" plain size="mini" @click="openAftersales(item)">查看售后</el-button>
                </div>
              </div>
            </template>

            <template v-else>
              <div v-for="item in business.rows" :key="item.id" class="side-list-item">
                <div class="side-item-main">
                  <div class="side-item-top">
                    <el-button type="text" size="mini" class="subject-link" @click="openOrder(item)">
                      {{ item.sales_order_no }}
                    </el-button>
                    <span class="meta-chip" :class="deliveryBadgeClass(item.delivery_status_label)">
                      {{ item.delivery_status_label }}
                    </span>
                    <el-button
                      v-if="item.can_view_customer && item.customer_id"
                      type="text"
                      size="mini"
                      class="cust-inline-link"
                      @click="$refs.customer.open(item.customer_id)"
                    >{{ item.customer_name }}</el-button>
                    <span v-else class="meta-text">{{ item.customer_name }}</span>
                  </div>
                  <div v-if="item.delivery_summary" class="side-item-sub">{{ item.delivery_summary }}</div>
                  <div v-if="item.amounts" class="side-item-meta">
                    <span class="meta-text">成交 {{ formatMoney(item.amounts.deal_amount, item.currency) }}</span>
                    <span class="meta-text">· 已收 {{ formatMoney(item.amounts.received_amount, item.currency) }}</span>
                    <span
                      v-if="Number(item.amounts.outstanding_amount || 0) > 0"
                      class="meta-chip is-amber"
                    >尾款 {{ formatMoney(item.amounts.outstanding_amount, item.currency) }}</span>
                  </div>
                </div>
                <div class="side-item-ops">
                  <el-button
                    v-if="item.can_view_customer && item.customer_id"
                    size="mini"
                    plain
                    @click="openDeliveryModal(item)"
                  >查看交付</el-button>
                  <el-button type="text" size="mini" @click="openOrder(item)">查看订单</el-button>
                </div>
              </div>
            </template>

            <div v-if="!business.loading && !business.rows.length" class="side-empty">
              <i class="el-icon-document" />
              <span>{{ business.error || '当前分类暂无待处理订单或售后单' }}</span>
            </div>
          </div>

          <footer class="crm-pager side-footer">
            <el-button
              type="text"
              size="mini"
              @click="$router.push(business.type === 'aftersales' ? '/sales/aftersales' : '/sales/orders')"
            >{{ business.type === 'aftersales' ? '全部售后' : '全部订单' }}</el-button>
            <el-pagination
              v-if="business.total > 0"
              background
              small
              layout="total, prev, pager, next"
              :total="business.total"
              :current-page.sync="business.page"
              :page-size="business.per_page"
              @current-change="loadBusiness"
            />
          </footer>
        </section>
      </div>
    </div>

    <!-- 底部通栏：线索池与标签快捷雷达 -->
    <section v-if="canViewLeads" class="crm-card radar-card">
      <div class="radar-head">
        <div class="radar-title-wrap">
          <h2 class="crm-section-title">线索池与标签快捷雷达</h2>
          <span class="crm-muted">点击任意标签直接带筛选条件进入线索中心</span>
        </div>
        <div class="seg-pills">
          <button
            v-for="group in radarGroups"
            :key="group.key"
            type="button"
            class="seg-pill"
            :class="{ active: radarTab === group.key }"
            @click="radarTab = group.key"
          >
            {{ group.label }}
            <span class="pill-count">{{ group.items.length }}</span>
          </button>
        </div>
      </div>

      <div class="radar-chips">
        <button
          v-for="item in activeRadarItems"
          :key="item.name"
          type="button"
          class="radar-chip"
          :class="{ 'is-zero': !item.count }"
          @click="openLeads(item.query)"
        >
          <span class="chip-name">{{ item.name }}</span>
          <span class="chip-num">{{ item.count }}</span>
        </button>
        <span v-if="!activeRadarItems.length" class="crm-muted">当前分类暂无标签数据</span>
      </div>
    </section>

    <!-- 订单生产交付详情弹窗 -->
    <el-dialog
      v-if="deliveryDialog.visible"
      :visible.sync="deliveryDialog.visible"
      :title="`生产交付进度 · ${deliveryDialog.salesOrderNo}`"
      width="960px"
      append-to-body
      class="crm-dialog"
    >
      <customer-order-delivery
        :key="deliveryDialog.orderId"
        :customer-id="deliveryDialog.customerId"
        :order-id="deliveryDialog.orderId"
        @navigate="deliveryDialog.visible = false"
      />
      <div slot="footer" class="crm-footer">
        <el-button size="small" @click="deliveryDialog.visible = false">关闭</el-button>
        <el-button size="small" type="success" @click="openOrderFromDelivery">查看订单详情</el-button>
      </div>
    </el-dialog>

    <crm-lead-form-dialog ref="form" @saved="refresh" />
    <crm-lead-detail-dialog ref="lead" @changed="refresh" />
    <crm-lead-action-dialog ref="action" @changed="refresh" />
    <crm-followup-dialog ref="follow" @saved="refresh" />
    <crm-identity-review-dialog ref="review" @saved="refresh" />
    <crm-opportunity-form-dialog ref="opportunityForm" @saved="refresh" />
    <crm-opportunity-detail-dialog ref="opportunity" @changed="refresh" />
    <customer-detail-dialog ref="customer" @changed="refresh" />
  </div>
</template>

<script>
import {
  getCrmWorkbench,
  listCrmWorkbenchTasks,
  listCrmWorkbenchPriorities,
  getCrmWorkbenchResults,
  listCrmWorkbenchBusiness,
  getCrmLeadMetadata,
  getCrmLead,
  crmDate,
  crmError
} from '@/api/erp/crm'
import { erpDateTime } from '@/utils/dateTime'
import CrmLeadFormDialog from '@/components/crm/CrmLeadFormDialog.vue'
import CrmLeadDetailDialog from '@/components/crm/CrmLeadDetailDialog.vue'
import CrmLeadActionDialog from '@/components/crm/CrmLeadActionDialog.vue'
import CrmFollowupDialog from '@/components/crm/CrmFollowupDialog.vue'
import CrmIdentityReviewDialog from '@/components/crm/CrmIdentityReviewDialog.vue'
import CrmOpportunityFormDialog from '@/components/crm/CrmOpportunityFormDialog.vue'
import CrmOpportunityDetailDialog from '@/components/crm/CrmOpportunityDetailDialog.vue'
import CustomerOrderDelivery from '@/components/sales/CustomerOrderDelivery.vue'
import '@/styles/crm.css'

const QUEUE_META = [
  { key: 'all', label: '全部待办', icon: 'el-icon-s-order', desc: '去重汇总当前待办', tone: 'is-green', valCls: '' },
  { key: 'today', label: '今日联系', icon: 'el-icon-phone-outline', desc: '今日计划跟进事项', tone: 'is-green', valCls: '' },
  { key: 'overdue', label: '逾期跟进', icon: 'el-icon-warning-outline', desc: '超计划时间未跟进', tone: 'is-red', valCls: 'is-danger' },
  { key: 'pending_receive', label: '待接收', icon: 'el-icon-bell', desc: '分配给我待确认接收', tone: 'is-amber', valCls: 'is-warn' },
  { key: 'pending_confirm', label: '待确认有效', icon: 'el-icon-circle-check', desc: '待确认为有效线索', tone: 'is-amber', valCls: '' },
  { key: 'pending_assign', label: '待分配', icon: 'el-icon-s-promotion', desc: '授权范围内待指派销售', tone: 'is-amber', valCls: '' },
  { key: 'pending_verify', label: '待核实', icon: 'el-icon-coordinate', desc: '身份冲突待核实归属', tone: 'is-red', valCls: 'is-danger' }
]

const PRIORITY_TABS = [
  { key: 'closing', label: '预计成交' },
  { key: 'confirmation', label: '待客户确认' },
  { key: 'stalled', label: '停滞商机' }
]

const ACTION_LABELS = {
  followup: '跟进',
  receive: '接收',
  confirm: '有效线索',
  assign: '分配',
  verify: '核实'
}

export default {
  name: 'CrmWorkbench',
  components: {
    CrmLeadFormDialog,
    CrmLeadDetailDialog,
    CrmLeadActionDialog,
    CrmFollowupDialog,
    CrmIdentityReviewDialog,
    CrmOpportunityFormDialog,
    CrmOpportunityDetailDialog,
    CustomerOrderDelivery,
    CustomerDetailDialog: () => import('@/components/sales/CustomerDetailDialog.vue')
  },
  data: () => ({
    workbench: {},
    metadata: {},
    query: { type: 'all', page: 1, per_page: 10 },
    tasks: { rows: [], total: 0, loading: false, error: '', sequence: 0 },
    priorities: { type: 'closing', page: 1, per_page: 10, rows: [], total: 0, loading: false, error: '', sequence: 0 },
    results: { data: null, loading: false, error: '', sequence: 0, currencyIdx: 0 },
    business: { type: 'delivery', page: 1, per_page: 10, rows: [], total: 0, loading: false, error: '', sequence: 0 },
    radarTab: 'customer_pools',
    deliveryDialog: { visible: false, orderId: null, customerId: null, salesOrderNo: '' },
    loading: false,
    error: '',
    sequence: 0,
    acting: false,
    actionLabels: ACTION_LABELS,
    priorityTabs: PRIORITY_TABS
  }),
  computed: {
    actor() {
      return this.workbench.current_user || {}
    },
    taskAbilities() {
      return this.workbench.task_abilities || {}
    },
    canViewLeads() {
      return !this.$can || this.$can('crm.lead.view')
    },
    canViewOpportunities() {
      return !this.$can || this.$can('crm.opportunity.view')
    },
    canCreateLead() {
      return !!(this.metadata.abilities || {}).create
    },
    canCreateOpportunity() {
      return this.canViewOpportunities && (!this.$can || this.$can('crm.opportunity.create'))
    },
    weekdayLabel() {
      if (!this.workbench.date) return ''
      const idx = new Date(`${this.workbench.date}T00:00:00`).getDay()
      return ['星期日', '星期一', '星期二', '星期三', '星期四', '星期五', '星期六'][idx] || ''
    },
    taskQueueCards() {
      return QUEUE_META.filter(card => card.key === 'all' || this.taskAbilities[card.key])
    },
    currentTaskLabel() {
      const found = QUEUE_META.find(c => c.key === this.query.type)
      return found ? found.label : '全部待办'
    },
    resultsData() {
      return this.results.data || {}
    },
    resultAbilities() {
      return this.resultsData.abilities || {}
    },
    hasResultsPanel() {
      const a = this.resultAbilities
      return !!(a.orders || a.amounts || a.followups || a.aftersales)
    },
    currencyList() {
      return (this.resultsData.amounts && this.resultsData.amounts.currencies) || []
    },
    activeCurrencySummary() {
      if (!this.currencyList.length) return null
      return this.currencyList[this.results.currencyIdx % this.currencyList.length] || this.currencyList[0]
    },
    currencyTag() {
      if (!this.activeCurrencySummary || !this.activeCurrencySummary.currency) return ''
      return this.currencyList.length > 1 || this.activeCurrencySummary.currency !== 'CNY'
        ? `(${this.activeCurrencySummary.currency})`
        : ''
    },
    businessTabs() {
      const a = this.resultAbilities
      const list = []
      if (a.delivery) list.push({ key: 'delivery', label: '待发货' })
      if (a.outstanding) list.push({ key: 'outstanding', label: '待收尾款' })
      if (a.aftersales) list.push({ key: 'aftersales', label: '处理中售后' })
      if (a.orders) list.push({ key: 'month_orders', label: '本月订单' })
      return list
    },
    radarGroups() {
      return [
        { key: 'customer_pools', label: '客户池', items: this.workbench.customer_pools || [] },
        { key: 'lead_statuses', label: '线索状态', items: this.workbench.lead_statuses || [] },
        { key: 'customer_tags', label: '客户标签', items: this.workbench.customer_tags || [] },
        { key: 'custom_tags', label: '我的标签', items: this.workbench.custom_tags || [] }
      ]
    },
    activeRadarItems() {
      const found = this.radarGroups.find(g => g.key === this.radarTab)
      return found ? found.items : []
    }
  },
  created() {
    this.refresh()
  },
  beforeDestroy() {
    this.sequence += 1
    this.tasks.sequence += 1
    this.priorities.sequence += 1
    this.results.sequence += 1
    this.business.sequence += 1
  },
  methods: {
    date: crmDate,
    shortDate(value) {
      return erpDateTime(value, 10) || '—'
    },
    displayCount(value) {
      return value === null || value === undefined ? '—' : value
    },
    formatMoney(value, currency) {
      if (value === null || value === undefined || value === '') return '待核实'
      const prefix = currency === 'CNY' || !currency ? '¥' : `${currency} `
      return `${prefix}${Number(value).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
    },
    targetTypeLabel(type) {
      return ({ lead: '线索', opportunity: '商机', customer: '客户' })[type] || '事项'
    },
    methodIcon(label) {
      if (!label) return 'el-icon-chat-dot-round'
      if (label.includes('微信')) return 'el-icon-chat-line-round'
      if (label.includes('邮')) return 'el-icon-message'
      return 'el-icon-phone-outline'
    },
    deliveryBadgeClass(label) {
      if (label === '部分发货') return 'is-green'
      if (label === '未发货' || label === '待核实') return 'is-amber'
      return ''
    },
    cycleCurrency() {
      if (this.currencyList.length <= 1) return
      this.results.currencyIdx = (this.results.currencyIdx + 1) % this.currencyList.length
    },
    async refresh() {
      await Promise.all([
        this.loadSummary(),
        this.loadTasks(),
        this.loadPriorities(),
        this.loadResultsAndBusiness()
      ])
    },
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
      } finally {
        if (sequence === this.sequence) this.loading = false
      }
    },
    async loadResultsAndBusiness() {
      const sequence = ++this.results.sequence
      this.results.loading = true
      this.results.error = ''
      try {
        const { data } = await getCrmWorkbenchResults()
        if (sequence !== this.results.sequence) return
        this.results.data = data.data || data
        if (this.businessTabs.length) {
          if (!this.businessTabs.some(t => t.key === this.business.type)) {
            this.business.type = this.businessTabs[0].key
            this.business.page = 1
          }
          await this.loadBusiness()
        } else {
          this.business.rows = []
          this.business.total = 0
        }
      } catch (error) {
        if (sequence !== this.results.sequence) return
        this.results.data = null
        this.results.error = crmError(error, '个人业绩加载失败')
      } finally {
        if (sequence === this.results.sequence) this.results.loading = false
      }
    },
    selectType(type) {
      if (this.query.type === type) return
      this.query.type = type
      this.searchTasks()
    },
    searchTasks() {
      this.query.page = 1
      this.loadTasks()
    },
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
      } finally {
        if (sequence === this.tasks.sequence) this.tasks.loading = false
      }
    },
    selectPriorityType(type) {
      if (this.priorities.type === type) return
      this.priorities.type = type
      this.priorities.page = 1
      this.loadPriorities()
    },
    async loadPriorities() {
      if (!this.canViewOpportunities) return
      const sequence = ++this.priorities.sequence
      this.priorities.loading = true
      this.priorities.error = ''
      try {
        const { data } = await listCrmWorkbenchPriorities({
          type: this.priorities.type,
          page: this.priorities.page,
          per_page: this.priorities.per_page
        })
        if (sequence !== this.priorities.sequence) return
        this.priorities.rows = data.data || []
        this.priorities.total = data.total || 0
      } catch (error) {
        if (sequence !== this.priorities.sequence) return
        this.priorities.rows = []
        this.priorities.total = 0
        this.priorities.error = crmError(error, '重点商机加载失败')
      } finally {
        if (sequence === this.priorities.sequence) this.priorities.loading = false
      }
    },
    jumpBusinessTab(type) {
      if (!this.businessTabs.some(t => t.key === type)) return
      this.selectBusinessType(type)
    },
    selectBusinessType(type) {
      if (this.business.type === type && this.business.rows.length) return
      this.business.type = type
      this.business.page = 1
      this.loadBusiness()
    },
    async loadBusiness() {
      if (!this.businessTabs.some(t => t.key === this.business.type)) return
      const sequence = ++this.business.sequence
      this.business.loading = true
      this.business.error = ''
      try {
        const { data } = await listCrmWorkbenchBusiness({
          type: this.business.type,
          page: this.business.page,
          per_page: this.business.per_page
        })
        if (sequence !== this.business.sequence) return
        this.business.rows = data.data || []
        this.business.total = data.total || 0
      } catch (error) {
        if (sequence !== this.business.sequence) return
        this.business.rows = []
        this.business.total = 0
        this.business.error = crmError(error, '订单与售后加载失败')
      } finally {
        if (sequence === this.business.sequence) this.business.loading = false
      }
    },
    openLeads(query) {
      this.$router.push({ path: '/sales/leads', query: { tab: 'all', ...query } })
    },
    taskRowClass({ row }) {
      return row.is_overdue ? 'workbench-overdue-row' : ''
    },
    rowActions(row) {
      const list = (row.actions || []).filter(act => act !== 'view' && this.actionLabels[act])
      if (list.length) return list
      return this.canAct(row) ? [row.action] : []
    },
    canAct(row) {
      const ability = ({ followup: 'follow', receive: 'accept' })[row.action] || row.action
      return !!((row.allowed || {})[ability] && this.actionLabels[row.action])
    },
    viewTask(row) {
      if (row.target_type === 'lead') this.$refs.lead.open(row.target_id)
      else if (row.target_type === 'opportunity') this.$refs.opportunity.open(row.target_id)
      else if (row.target_type === 'customer') this.$refs.customer.open(row.target_id)
    },
    async actTask(row, actionKey) {
      const action = actionKey || row.action
      if (this.acting || !this.actionLabels[action]) return
      if (action === 'followup') {
        this.$refs.follow.open({
          subject_type: row.target_type,
          subject_id: row.target_id,
          customer_id: row.customer_id,
          customer_name: row.customer_name,
          opportunity_name: row.target_type === 'opportunity' ? row.title : '',
          lead_no: row.lead_no,
          contact_name: row.contact_name
        })
      } else if (action === 'verify') {
        const sequence = this.sequence
        this.acting = true
        try {
          const { data } = await getCrmLead(row.target_id)
          if (sequence !== this.sequence) return
          const lead = data.data || data
          if (lead.review && lead.review.can_resolve) this.$refs.review.openReview(lead.review)
          else this.$message.warning('该线索状态或权限已变化，请刷新后重新选择')
        } catch (error) {
          if (sequence === this.sequence) this.$message.error(crmError(error, '线索加载失败'))
        } finally {
          this.acting = false
        }
      } else {
        this.$refs.action.open(action === 'receive' ? 'accept' : action, row.target_id)
      }
    },
    followOpportunity(item) {
      this.$refs.follow.open({
        subject_type: 'opportunity',
        subject_id: item.id,
        customer_id: item.customer_id,
        customer_name: item.customer_name,
        opportunity_name: item.name,
        contact_name: item.contact_name
      })
    },
    openOrder(item) {
      if (!item || !item.order_id) return
      this.$router.push(`/sales/orders/${item.order_id}/detail`)
    },
    openAftersales(item) {
      if (!item || !item.id) return
      this.$router.push(`/sales/aftersales/${item.id}/detail`)
    },
    openDeliveryModal(item) {
      if (!item || !item.order_id || !item.customer_id) return
      this.deliveryDialog = {
        visible: true,
        orderId: item.order_id,
        customerId: item.customer_id,
        salesOrderNo: item.sales_order_no || ''
      }
    },
    openOrderFromDelivery() {
      const orderId = this.deliveryDialog.orderId
      this.deliveryDialog.visible = false
      if (orderId) this.$router.push(`/sales/orders/${orderId}/detail`)
    }
  }
}
</script>

<style scoped>
.workbench-page {
  gap: 12px;
}

.page-head-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
  padding: 12px 16px;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  flex-wrap: wrap;
}
.head-left {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}
.head-titles {
  min-width: 0;
}
.title-row {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.title-row h1 {
  margin: 0;
  font-size: 19px;
  font-weight: 700;
  color: #1e293b;
}
.user-pill,
.date-pill {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 2px 9px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 999px;
  font-size: 12px;
  color: #475569;
}
.head-sub {
  margin: 4px 0 0;
  font-size: 12px;
  color: #64748b;
}

.perf-strip {
  display: flex;
  align-items: stretch;
  gap: 8px;
  flex-wrap: wrap;
}
.perf-item {
  display: flex;
  flex-direction: column;
  justify-content: center;
  padding: 6px 12px;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  background: #f8fafc;
  text-align: left;
  min-width: 92px;
}
.perf-item.is-clickable {
  cursor: pointer;
  transition: all 0.15s ease;
}
.perf-item.is-clickable:hover {
  border-color: #86efac;
  background: #f0fdf4;
}
.perf-item.is-amber-box {
  background: #fffbeb;
  border-color: #fde68a;
}
.perf-item.is-amber-box:hover {
  border-color: #f59e0b;
  background: #fef3c7;
}
.perf-lbl {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 11px;
  color: #64748b;
}
.perf-val {
  margin-top: 2px;
  font-size: 15px;
  font-weight: 700;
  color: #1e293b;
  white-space: nowrap;
}
.perf-val small {
  font-size: 11px;
  font-weight: 400;
  color: #64748b;
}
.perf-val.is-green {
  color: #008b4b;
}
.perf-val.is-amber {
  color: #d97706;
}
.perf-val.is-red {
  color: #dc2626;
}
.currency-cycle-btn {
  padding: 0 5px;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  background: #ffffff;
  color: #008b4b;
  font-size: 10px;
  line-height: 15px;
  cursor: pointer;
}
.currency-cycle-btn:hover {
  border-color: #008b4b;
}

.workbench-kpi-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
  gap: 10px;
  min-width: 0;
}
.kpi-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 10px 14px;
  text-align: left;
  cursor: pointer;
  transition: all 0.15s ease;
  min-width: 0;
}
.kpi-card:hover {
  border-color: #86efac;
  transform: translateY(-1px);
  box-shadow: 0 4px 10px rgba(0, 139, 75, 0.06);
}
.kpi-card.active {
  background: #f0fdf4;
  border-color: #008b4b;
  box-shadow: inset 0 0 0 1px #008b4b;
}
.kpi-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 6px;
}
.kpi-label {
  font-size: 12px;
  font-weight: 600;
  color: #475569;
}
.kpi-card.active .kpi-label {
  color: #00763f;
}
.kpi-icon {
  font-size: 15px;
  color: #94a3b8;
}
.kpi-card.active .kpi-icon,
.kpi-card.is-green .kpi-icon {
  color: #008b4b;
}
.kpi-card.is-amber .kpi-icon {
  color: #d97706;
}
.kpi-card.is-red .kpi-icon {
  color: #dc2626;
}
.kpi-val {
  margin-top: 4px;
  font-size: 20px;
  font-weight: 700;
  color: #1e293b;
  line-height: 1.2;
}
.kpi-card.active .kpi-val {
  color: #00763f;
}
.kpi-val.is-danger {
  color: #dc2626;
}
.kpi-val.is-warn {
  color: #d97706;
}
.kpi-desc {
  margin-top: 2px;
  font-size: 11px;
  color: #94a3b8;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.workbench-main-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.48fr) minmax(0, 1fr);
  gap: 12px;
  align-items: stretch;
}
.workbench-side-stack {
  display: flex;
  flex-direction: column;
  gap: 12px;
  min-width: 0;
  height: 100%;
}
.workbench-side-stack > .panel-card {
  flex: 1 1 0;
  min-height: 252px;
}

.panel-card {
  display: flex;
  flex-direction: column;
}
.tasks-panel {
  height: 100%;
  min-height: 516px;
}
.panel-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  min-height: 52px;
  padding: 10px 16px;
  border-bottom: 1px solid #eef2f7;
  flex-wrap: wrap;
  box-sizing: border-box;
}
.panel-head .crm-section-title {
  margin: 0;
}
.panel-title-wrap {
  display: flex;
  align-items: center;
  gap: 8px;
}
.panel-sub-pill {
  padding: 1px 8px;
  border-radius: 999px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  color: #00763f;
  font-size: 11px;
  font-weight: 600;
}
.panel-alert {
  margin: 10px 14px 0;
}

.seg-pills {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}
.seg-pill {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 4px 10px;
  border: 1px solid #e2e8f0;
  border-radius: 999px;
  background: #f8fafc;
  color: #475569;
  font-size: 12px;
  cursor: pointer;
  transition: all 0.15s ease;
}
.seg-pill:hover {
  border-color: #86efac;
  color: #00763f;
}
.seg-pill.active {
  background: #f0fdf4;
  border-color: #008b4b;
  color: #00763f;
  font-weight: 600;
}
.pill-count {
  padding: 0 5px;
  border-radius: 999px;
  background: rgba(15, 23, 42, 0.06);
  font-size: 11px;
}
.seg-pill.active .pill-count {
  background: #bbf7d0;
}

.workbench-table {
  flex: 1 1 auto;
  display: flex;
  flex-direction: column;
}
.workbench-table ::v-deep .el-table__header-wrapper {
  flex: 0 0 auto;
}
.workbench-table ::v-deep .el-table__body-wrapper {
  flex: 1 1 auto;
  min-height: 378px;
}
.workbench-table ::v-deep .el-table__empty-block {
  width: 100% !important;
  min-height: 378px;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
}
.workbench-table ::v-deep .el-table__empty-text {
  width: auto;
  line-height: normal;
}
.workbench-table ::v-deep .workbench-overdue-row td.el-table__cell {
  background: #fef2f2;
}
.cell-primary-line {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}
.cell-sub-line {
  display: flex;
  align-items: center;
  gap: 5px;
  flex-wrap: wrap;
  margin-top: 3px;
  font-size: 12px;
  color: #64748b;
}
.cell-sub-line.text-dark {
  color: #334155;
}
.target-badge {
  display: inline-block;
  padding: 0 6px;
  border-radius: 4px;
  font-size: 11px;
  line-height: 18px;
  font-weight: 600;
}
.target-badge.is-lead {
  background: #f0fdf4;
  color: #00763f;
  border: 1px solid #bbf7d0;
}
.target-badge.is-opportunity {
  background: #fffbeb;
  color: #b45309;
  border: 1px solid #fde68a;
}
.target-badge.is-customer {
  background: #f1f5f9;
  color: #475569;
  border: 1px solid #cbd5e1;
}
.subject-link {
  padding: 0;
  font-weight: 700;
  font-size: 13px;
  color: #008b4b;
  text-align: left;
  white-space: normal;
}
.subject-link:hover {
  color: #006334;
  text-decoration: underline;
}
.cust-inline-link {
  padding: 0;
  font-size: 12px;
  color: #008b4b;
}
.sep {
  color: #cbd5e1;
}
.method-pill {
  color: #475569;
}

.plan-line,
.last-line {
  display: flex;
  align-items: flex-start;
  gap: 5px;
  font-size: 12px;
  line-height: 1.45;
  color: #1e293b;
}
.last-line {
  margin-top: 4px;
  color: #64748b;
}
.ctx-lbl {
  flex: none;
  padding: 0 5px;
  border-radius: 3px;
  background: #f1f5f9;
  color: #475569;
  font-size: 11px;
  line-height: 17px;
}
.ctx-lbl.is-green {
  background: #f0fdf4;
  color: #00763f;
}

.time-line {
  font-size: 12px;
  color: #334155;
}
.text-strong {
  font-weight: 600;
}
.tags-wrap {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin-top: 4px;
}
.task-tag {
  margin: 0;
}

.row-actions-wrap {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}
.row-actions-wrap ::v-deep .el-button + .el-button {
  margin-left: 0;
}

.wb-empty {
  padding: 36px 12px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  color: #64748b;
}
.wb-empty i {
  font-size: 28px;
  color: #008b4b;
}

.side-list {
  padding: 4px 16px;
  flex: 1 1 auto;
  min-height: 150px;
  display: flex;
  flex-direction: column;
}
.side-list-item {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
  padding: 10px 0;
  border-bottom: 1px solid #f1f5f9;
}
.side-list-item:last-child {
  border-bottom: 0;
}
.side-item-main {
  min-width: 0;
  flex: 1;
}
.side-item-top {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}
.side-item-meta {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
  margin-top: 4px;
}
.side-item-sub {
  margin-top: 4px;
  font-size: 12px;
  color: #64748b;
  line-height: 1.45;
  word-break: break-word;
}
.side-item-ops {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-shrink: 0;
}
.side-item-ops ::v-deep .el-button + .el-button {
  margin-left: 0;
}
.meta-text {
  font-size: 12px;
  color: #64748b;
}
.meta-chip {
  display: inline-flex;
  align-items: center;
  padding: 0 6px;
  border-radius: 4px;
  font-size: 11px;
  line-height: 18px;
  background: #f8fafc;
  color: #475569;
  border: 1px solid #e2e8f0;
  white-space: nowrap;
}
.meta-chip.is-green {
  background: #f0fdf4;
  color: #00763f;
  border-color: #bbf7d0;
}
.meta-chip.is-amber {
  background: #fffbeb;
  color: #b45309;
  border-color: #fde68a;
}
.meta-chip.is-red {
  background: #fef2f2;
  color: #dc2626;
  border-color: #fecaca;
}
.side-empty {
  flex: 1 1 auto;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 24px 12px;
  text-align: center;
  font-size: 12px;
  color: #64748b;
}
.side-empty i {
  font-size: 26px;
  color: #008b4b;
}

.table-footer,
.side-footer {
  margin-top: auto;
  justify-content: space-between;
  min-height: 49px;
  border-top: 1px solid #eef2f7;
  padding: 10px 16px;
  box-sizing: border-box;
}

.radar-card {
  padding: 12px 16px;
}
.radar-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 10px;
}
.radar-title-wrap {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.radar-title-wrap .crm-section-title {
  margin: 0;
}
.radar-chips {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.radar-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 10px;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  background: #f8fafc;
  color: #1e293b;
  font-size: 12px;
  cursor: pointer;
  transition: all 0.15s ease;
}
.radar-chip:hover {
  border-color: #008b4b;
  background: #f0fdf4;
  color: #00763f;
}
.radar-chip.is-zero {
  opacity: 0.65;
}
.chip-num {
  padding: 0 6px;
  border-radius: 999px;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  color: #008b4b;
  font-weight: 600;
  font-size: 11px;
}

@media (max-width: 1260px) {
  .workbench-main-grid {
    grid-template-columns: minmax(0, 1fr);
    align-items: start;
  }
  .workbench-side-stack {
    height: auto;
  }
  .tasks-panel,
  .workbench-side-stack > .panel-card {
    height: auto;
    min-height: 0;
    flex: none;
  }
  .workbench-table ::v-deep .el-table__body-wrapper,
  .workbench-table ::v-deep .el-table__empty-block {
    min-height: 180px;
  }
}
@media (max-width: 780px) {
  .workbench-kpi-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
