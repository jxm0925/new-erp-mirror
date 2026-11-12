<template>
  <div class="crm-page lead-page">
    <header class="page-head-card">
      <div class="head-left">
        <span class="crm-head-icon"><i class="el-icon-attract" /></span>
        <div class="head-titles">
          <div class="title-row">
            <h1>线索中心</h1>
            <span v-if="currentUser.name" class="user-pill">
              <i class="el-icon-user" />
              {{ currentUser.name }}{{ currentUser.department_name ? ' · ' + currentUser.department_name : '' }}
              <em v-if="currentUser.is_sales" class="role-badge">销售</em>
              <em v-else class="role-badge ops">运营/管理</em>
            </span>
          </div>
          <p class="head-sub">集中管理销售自录与运营代录线索，覆盖查重核实、分配接收、跟进推进与公海流转</p>
        </div>
      </div>
      <div class="crm-actions">
        <crm-list-view-controls ref="views" resource="leads" title="线索" :configuration="viewConfiguration" :filters="query" :column-groups="columnGroups" :filter-options="viewFilterOptions" @apply="applyView" />
        <el-button size="small" icon="el-icon-refresh" :loading="loading" @click="load">刷新</el-button>
        <el-button v-if="abilities.create" size="small" type="success" icon="el-icon-plus" @click="$refs.form.open()">新增线索</el-button>
      </div>
    </header>

    <section class="lead-kpi-grid">
      <div
        v-for="tab in visibleTabs"
        :key="`kpi-${tab.value}`"
        class="kpi-card"
        :class="[
          { active: query.tab === tab.value },
          tab.tone || ''
        ]"
        @click="switchTab(tab.value)"
      >
        <div class="kpi-top">
          <span class="kpi-label">{{ tab.label }}</span>
          <i :class="[tab.icon || 'el-icon-document', 'kpi-icon']" />
        </div>
        <div class="kpi-val">{{ counts[tab.value] === undefined ? '—' : counts[tab.value] }}</div>
        <div class="kpi-desc">{{ tab.desc }}</div>
      </div>
    </section>

    <div class="tabs-bar">
      <el-tabs v-model="query.tab" class="lead-tabs" @tab-click="handleTabClick">
        <el-tab-pane v-for="tab in visibleTabs" :key="tab.value" :name="tab.value">
          <span slot="label" class="tab-label-wrap">
            <span>{{ tab.label }}</span>
            <span
              v-if="counts[tab.value] !== undefined"
              class="tab-badge"
              :class="{
                'is-active': query.tab === tab.value,
                'is-alert': ['pending_acceptance', 'pending_assignment', 'pending_review'].includes(tab.value) && counts[tab.value] > 0
              }"
            >{{ counts[tab.value] }}</span>
          </span>
        </el-tab-pane>
      </el-tabs>

      <div v-if="query.tab === 'mine'" class="due-quick-group">
        <span class="due-label">跟进排期：</span>
        <el-button
          v-for="item in dueOptions"
          :key="item.value || 'all'"
          size="mini"
          :type="query.due === item.value ? 'success' : ''"
          :plain="query.due !== item.value"
          class="due-pill"
          @click="selectDue(item.value)"
        >
          <i v-if="item.icon" :class="item.icon" />
          {{ item.label }}
        </el-button>
      </div>
    </div>

    <div v-if="query.tab === 'public_pool'" class="context-banner" :class="abilities.claim ? 'is-green' : 'is-slate'">
      <i :class="abilities.claim ? 'el-icon-circle-check' : 'el-icon-info'" />
      <span>{{ abilities.claim ? '当前公海展示你 CRM 授权范围内的可跟进线索，领取后即转入“我负责的”由你跟进。' : '你当前没有公海领取权限，仅可查看授权范围内公海线索；如需跟进请联系有分配权限的人员分配。' }}</span>
      <span v-if="!metadata.automatic_recycle_enabled" class="banner-tag">人工回收模式</span>
    </div>
    <div v-else-if="query.tab === 'pending_review'" class="context-banner is-amber">
      <i class="el-icon-warning-outline" />
      <span>待核实线索因客户强身份冲突或跨部门疑似重复暂存，核实关联客户后自动进入“待分配”，退回则回到录入人草稿。</span>
    </div>
    <div v-else-if="query.tab === 'pending_acceptance' && counts.pending_acceptance > 0" class="context-banner is-amber">
      <i class="el-icon-bell" />
      <span>你有 <b>{{ counts.pending_acceptance }}</b> 条待接收线索，请及时确认接收并开展首次联系；如不属于负责范围可填写原因退回。</span>
    </div>

    <section class="crm-filters filter-card">
      <el-tag v-if="query.customer_id" size="small" type="info" closable @close="clearCustomerFilter">本客户线索</el-tag>
      <el-input
        v-model.trim="query.keyword"
        size="small"
        clearable
        prefix-icon="el-icon-search"
        placeholder="线索编号 / 客户名称 / 需求 / 电话"
        class="kw-input"
        @keyup.enter.native="search"
        @clear="search"
      />
      <label v-if="query.tab !== 'public_pool' && (metadata.departments || []).length > 1">
        <span>部门</span>
        <el-select popper-class="crm-select-popper" v-model="query.department_legacy_id" size="small" filterable clearable placeholder="全部授权部门" @change="search">
          <el-option v-for="item in metadata.departments || []" :key="item.id" :value="item.id" :label="item.name" />
        </el-select>
      </label>
      <label>
        <span>来源渠道</span>
        <el-select popper-class="crm-select-popper" v-model="query.source_channel" size="small" filterable clearable placeholder="全部渠道" @change="search">
          <el-option v-for="item in metadata.source_channels || []" :key="item" :value="item" :label="item" />
        </el-select>
      </label>
      <label v-if="query.tab !== 'public_pool'">
        <span>录入方式</span>
        <el-select popper-class="crm-select-popper" v-model="query.entry_method" size="small" clearable placeholder="全部方式" @change="search">
          <el-option v-for="(label, value) in metadata.entry_methods || { self: '销售自录', operations: '运营代录' }" :key="value" :value="value" :label="label" />
        </el-select>
      </label>
      <label v-if="showStatusFilter">
        <span>线索状态</span>
        <el-select popper-class="crm-select-popper" v-model="query.status" size="small" clearable placeholder="全部状态" @change="search">
          <el-option v-for="(label, value) in metadata.statuses || {}" :key="value" :value="value" :label="label" />
        </el-select>
      </label>
      <label v-if="showPoolFilter">
        <span>客户池</span>
        <el-select popper-class="crm-select-popper" v-model="query.customer_pool" size="small" filterable clearable placeholder="全部客户池" @change="search">
          <el-option v-for="item in metadata.customer_pools || []" :key="item" :value="item" :label="item" />
        </el-select>
      </label>
      <label>
        <span>客户标签</span>
        <el-select popper-class="crm-select-popper" v-model="query.customer_tag" size="small" filterable clearable placeholder="全部标签" @change="search">
          <el-option v-for="item in metadata.customer_tags || []" :key="item" :value="item" :label="item" />
        </el-select>
      </label>
      <label>
        <span>我的标签</span>
        <el-select popper-class="crm-select-popper" v-model="query.custom_tag" size="small" filterable clearable placeholder="全部标签" @change="search">
          <el-option v-for="item in metadata.custom_tags || []" :key="item" :value="item" :label="item" />
        </el-select>
      </label>
      <label v-if="query.tab === 'public_pool'">
        <span>回收原因</span>
        <el-select
          popper-class="crm-select-popper"
          v-model="query.recycle_reason"
          size="small"
          filterable
          allow-create
          default-first-option
          clearable
          placeholder="选择或输入原因"
          @change="search"
        >
          <el-option v-for="item in recycleReasonOptions" :key="item" :value="item" :label="item" />
        </el-select>
      </label>
      <div class="filter-btns">
        <el-button size="small" type="success" icon="el-icon-search" @click="search">查询</el-button>
        <el-button size="small" icon="el-icon-refresh" @click="reset">重置</el-button>
      </div>
    </section>

    <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />

    <section class="crm-card table-card">
      <el-table :key="viewTableKey" v-loading="loading" :span-method="viewGroupSpan" :data="rows" size="small" class="lead-table">
        <el-table-column v-if="viewConfiguration.group_by" prop="__view_group" :label="groupTitle" min-width="130"><template slot-scope="{row}">{{ groupValue(row) }}</template></el-table-column>
        <template v-for="column in viewColumns">
        <el-table-column v-if="column.key === 'identity'" :key="column.key" label="客户 / 线索编号" min-width="210">
          <template slot-scope="{row}">
            <div class="cell-primary-line">
              <el-button v-if="hasField('customer_name')" type="text" class="lead-name-link" @click="$refs.detail.open(row.id)">
                {{ row.customer_name || '未命名客户' }}
              </el-button>
              <span v-if="hasField('customer_kind')" class="kind-pill" :class="row.customer_kind === 'enterprise' ? 'is-ent' : 'is-ind'">
                {{ row.customer_kind === 'enterprise' ? '企业' : '个人' }}
              </span>
              <span v-if="row.is_new_inquiry" class="new-badge">新咨询</span>
            </div>
            <div v-if="hasField('lead_no')" class="cell-sub-line">
              <span class="mono-no">{{ row.lead_no }}</span>
              <i class="el-icon-document-copy crm-copy-btn" title="复制线索编号" @click.stop="copyText(row.lead_no, '线索编号')" />
              <template v-if="row.customer && row.customer.customer_code">
                <span class="sep">·</span>
                <el-button
                  v-if="row.customer.visible !== false && (!$can || $can('sales.customer'))"
                  type="text"
                  size="mini"
                  class="cust-code-link"
                  @click.stop="openCustomer(row.customer_id)"
                >{{ row.customer.customer_code }}</el-button>
                <span v-else class="crm-muted">{{ row.customer.customer_code }}</span>
              </template>
            </div>
          </template>
        </el-table-column>

        <el-table-column v-if="column.key === 'contact'" :key="column.key" label="联系人 / 地区" min-width="155">
          <template slot-scope="{row}">
            <div class="cell-primary-line">
              <span v-if="hasField('contact_name')" class="text-strong">{{ row.contact_name || row.customer_name || '—' }}</span>
              <span v-if="hasField('contact_name') && row.contact_role" class="role-text">（{{ row.contact_role }}）</span>
            </div>
            <div v-if="hasField('mobile')" class="cell-sub-line">
              <span v-if="row.mobile || row.contact_value2">{{ row.mobile || row.contact_value2 }}</span>
              <span v-else-if="row.wechat">微信：{{ row.wechat }}</span>
              <span v-else>未填联系方式</span>
            </div>
            <div v-if="hasField('region') && regionText(row)" class="cell-sub-line muted-addr">
              <i class="el-icon-location-outline" /> {{ regionText(row) }}
            </div>
          </template>
        </el-table-column>

        <el-table-column v-if="column.key === 'demand'" :key="column.key" label="需求摘要 / 业务" min-width="215">
          <template slot-scope="{row}">
            <div v-if="hasField('demand_summary')" class="demand-text">{{ row.demand_summary || row.content || '暂未填写需求描述' }}</div>
            <div class="cell-tags-line">
              <span v-if="hasField('expected_amount') && row.expected_amount !== null && row.expected_amount !== undefined" class="amount-pill">
                {{ formatMoney(row.expected_amount, row.currency) }}
              </span>
              <span v-if="hasField('trade_type') && row.trade_type" class="meta-chip">{{ row.trade_type }}</span>
              <span v-if="hasField('trade_type') && row.lead_type && row.lead_type !== '普通咨询'" class="meta-chip">{{ row.lead_type }}</span>
              <span v-for="line in hasField('business_lines') ? (row.business_lines || []).slice(0, 2) : []" :key="line" class="meta-chip is-green">{{ line }}</span>
              <span v-if="hasField('business_lines') && (row.business_lines || []).length > 2" class="meta-chip">+{{ row.business_lines.length - 2 }}</span>
            </div>
          </template>
        </el-table-column>

        <el-table-column v-if="column.key === 'source'" :key="column.key" label="来源渠道 / 录入" min-width="155">
          <template slot-scope="{row}">
            <div class="cell-primary-line">
              <span v-if="hasField('source_channel')" class="text-strong">{{ row.source_channel || '—' }}</span>
              <span v-if="hasField('entry_method')" class="entry-pill" :class="row.entry_method === 'self' ? 'is-self' : 'is-ops'">
                {{ row.entry_method === 'self' ? '销售自录' : '运营代录' }}
              </span>
            </div>
            <div v-if="hasField('platform_name') && (row.platform_name || row.platform_buyer_id)" class="cell-sub-line">
              <span>{{ row.platform_name || '线上店铺' }}</span>
              <span v-if="row.platform_buyer_id" class="sep">·</span>
              <span v-if="row.platform_buyer_id">ID: {{ row.platform_buyer_id }}</span>
            </div>
            <div v-if="hasField('entry_method')" class="cell-sub-line">
              <span>{{ row.created_by_name || '—' }}</span>
              <span v-if="row.created_at" class="sep">·</span>
              <span v-if="row.created_at">{{ shortDate(row.created_at) }}</span>
            </div>
          </template>
        </el-table-column>

        <el-table-column v-if="column.key === 'owner' && query.tab !== 'public_pool'" :key="column.key" label="负责人 / 部门" min-width="140">
          <template slot-scope="{row}">
            <div class="cell-primary-line">
              <template v-if="hasField('owner_name') && row.pending_user_name">
                <span class="text-strong">{{ row.pending_user_name }}</span>
                <span class="pending-pill">待接收</span>
              </template>
              <template v-else-if="hasField('owner_name') && row.owner_name">
                <span class="text-strong">{{ row.owner_name }}</span>
              </template>
              <span v-else-if="hasField('owner_name')" class="crm-muted">待分配</span>
            </div>
            <div class="cell-sub-line">
              <span v-if="hasField('department_name')">{{ row.department_name || '未指定部门' }}</span>
              <span v-if="hasField('owner_name') && row.assigned_by_name && (row.pending_user_name || row.owner_name)" class="sep">·</span>
              <span v-if="hasField('owner_name') && row.assigned_by_name && (row.pending_user_name || row.owner_name)">{{ row.assigned_by_name }}分配</span>
            </div>
          </template>
        </el-table-column>

        <el-table-column v-if="column.key === 'state' && query.tab !== 'public_pool'" :key="column.key" label="状态 / 客户池" min-width="145">
          <template slot-scope="{row}">
            <div class="cell-primary-line">
              <el-tag v-if="hasField('status')" size="mini" :type="statusType(row.status)" class="status-tag">
                {{ row.status_label || (metadata.statuses || {})[row.status] || row.status }}
              </el-tag>
              <span v-if="hasField('status') && row.confirmed_at" class="confirmed-pill" title="已完成基础线索确认">
                <i class="el-icon-check" /> 已确认
              </span>
            </div>
            <div v-if="hasField('customer_pool') && row.customer_pool" class="cell-sub-line">
              <span class="pool-badge">{{ row.customer_pool }}</span>
            </div>
            <div v-else-if="hasField('status') && row.status === 'invalid' && row.invalid_reason" class="cell-sub-line crm-overdue">
              原因：{{ row.invalid_reason }}
            </div>
            <div v-else-if="hasField('customer_pool')" class="cell-sub-line">
              <span class="crm-muted">未入客户池</span>
            </div>
          </template>
        </el-table-column>

        <el-table-column v-if="column.key === 'schedule' && query.tab !== 'public_pool'" :key="column.key" label="最近联系 / 下次计划" min-width="175">
          <template slot-scope="{row}">
            <div v-if="hasField('last_contact_at')" class="cell-sub-line">
              <span class="schedule-lbl">最近：</span>
              <span class="text-strong">{{ date(row.last_contact_at) }}</span>
            </div>
            <div v-if="hasField('next_contact_at')" class="cell-sub-line">
              <span class="schedule-lbl">下次：</span>
              <span :class="{ 'crm-overdue text-strong': isOverdue(row), 'due-today text-strong': isDueToday(row) }">
                {{ date(row.next_contact_at) }}
              </span>
              <span v-if="isOverdue(row)" class="overdue-pill">已逾期</span>
              <span v-else-if="isDueToday(row)" class="today-pill">今日</span>
            </div>
            <div v-if="hasField('next_plan') && row.next_plan" class="cell-sub-line plan-summary">
              计划：{{ row.next_plan }}
            </div>
          </template>
        </el-table-column>

        <el-table-column v-if="column.key === 'owner' && query.tab === 'public_pool' && hasField('department_name')" :key="column.key" prop="department_name" label="所属部门" min-width="105" />
        <el-table-column v-if="column.key === 'recycle' && query.tab === 'public_pool'" :key="column.key" label="回收时间 / 原因" min-width="175">
          <template slot-scope="{row}">
            <div class="cell-primary-line">
              <span v-if="hasField('recycle_reason')" class="pool-badge is-amber">{{ row.recycle_reason || '人工回收' }}</span>
            </div>
            <div v-if="hasField('recycled_at')" class="cell-sub-line">{{ date(row.recycled_at) }}</div>
          </template>
        </el-table-column>
        <el-table-column v-if="column.key === 'recycle_owner' && query.tab === 'public_pool'" :key="column.key" label="原负责人 / 回收人" min-width="145">
          <template slot-scope="{row}">
            <div v-if="hasField('previous_owner_name')" class="cell-sub-line">原负责：<span class="text-strong">{{ row.previous_owner_name || '—' }}</span></div>
            <div v-if="hasField('recycled_by_name')" class="cell-sub-line">回收人：{{ row.recycled_by_name || '—' }}</div>
          </template>
        </el-table-column>

        </template>
        <el-table-column label="操作" :min-width="query.tab === 'public_pool' ? 140 : 225" fixed="right">
          <template slot-scope="{row}">
            <div class="crm-inline row-ops">
              <el-button type="text" size="mini" icon="el-icon-view" @click="$refs.detail.open(row.id)">查看</el-button>
              <el-button
                v-for="action in rowActions(row).slice(0, 3)"
                :key="action.key"
                type="text"
                size="mini"
                :icon="action.icon"
                :class="action.cls"
                @click="handleAction(row, action.key)"
              >{{ action.label }}</el-button>
              <el-dropdown v-if="rowActions(row).length > 3" @command="handleAction(row, $event)">
                <el-button type="text" size="mini">更多<i class="el-icon-arrow-down el-icon--right" /></el-button>
                <el-dropdown-menu slot="dropdown" class="crm-select-popper">
                  <el-dropdown-item
                    v-for="action in rowActions(row).slice(3)"
                    :key="action.key"
                    :command="action.key"
                    :class="action.cls"
                  >
                    <i v-if="action.icon" :class="action.icon" /> {{ action.label }}
                  </el-dropdown-item>
                </el-dropdown-menu>
              </el-dropdown>
            </div>
          </template>
        </el-table-column>

        <template slot="empty">
          <div class="lead-empty">
            <i class="el-icon-attract" />
            <b>{{ emptyTitle }}</b>
            <p class="crm-muted">{{ emptySub }}</p>
            <div class="empty-actions">
              <el-button v-if="hasActiveFilters" size="mini" icon="el-icon-refresh" @click="reset">清空筛选条件</el-button>
              <el-button v-if="abilities.create && ['mine', 'all', 'draft'].includes(query.tab)" size="mini" type="success" icon="el-icon-plus" @click="$refs.form.open()">新增线索</el-button>
            </div>
          </div>
        </template>
      </el-table>

      <footer class="crm-pager table-footer">
        <div class="pager-summary">
          <span>当前页签：<b>{{ currentTabLabel }}</b></span>
          <span class="sep">·</span>
          <span>共 <b>{{ total }}</b> 条线索</span>
          <span v-if="query.due" class="due-active-hint">
            （已筛选：{{ (dueOptions.find(d => d.value === query.due) || {}).label }}）
          </span>
        </div>
        <el-pagination
          background
          layout="total, sizes, prev, pager, next, jumper"
          :total="total"
          :current-page.sync="query.page"
          :page-size.sync="query.per_page"
          :page-sizes="[10, 20, 50, 100]"
          @size-change="search"
          @current-change="load"
        />
      </footer>
    </section>

    <crm-lead-form-dialog ref="form" @saved="load" />
    <crm-lead-detail-dialog ref="detail" @changed="load" />
    <crm-lead-action-dialog ref="action" @changed="load" />
    <crm-followup-dialog ref="follow" @saved="load" />
    <crm-identity-review-dialog ref="review" @saved="load" />
    <crm-opportunity-form-dialog ref="opportunityForm" @saved="opportunitySaved" />
    <crm-opportunity-detail-dialog ref="opportunityDetail" @changed="load" />
    <customer-detail-dialog ref="customer" @changed="load" />
  </div>
</template>

<script>
import { getCrmLeadMetadata, listCrmLeads, crmError, crmDate } from '@/api/erp/crm'
import CrmLeadFormDialog from '@/components/crm/CrmLeadFormDialog.vue'
import CrmLeadDetailDialog from '@/components/crm/CrmLeadDetailDialog.vue'
import CrmLeadActionDialog from '@/components/crm/CrmLeadActionDialog.vue'
import CrmFollowupDialog from '@/components/crm/CrmFollowupDialog.vue'
import CrmIdentityReviewDialog from '@/components/crm/CrmIdentityReviewDialog.vue'
import CrmOpportunityFormDialog from '@/components/crm/CrmOpportunityFormDialog.vue'
import CrmOpportunityDetailDialog from '@/components/crm/CrmOpportunityDetailDialog.vue'
import CrmListViewControls from '@/components/crm/CrmListViewControls.vue'
import { leadColumnGroups, orderedColumnGroups, listViewQuery, listViewGroupSpan } from '@/components/crm/listViewColumns'
import '@/styles/crm.css'

const blankQuery = (perPage = 10, tab = 'mine') => ({
  tab,
  keyword: '',
  department_legacy_id: null,
  source_channel: '',
  entry_method: '',
  recycle_reason: '',
  status: '',
  customer_pool: '',
  customer_id: '',
  customer_tag: '',
  custom_tag: '',
  due: '',
  page: 1,
  per_page: perPage
})

const ACTION_META = [
  { key: 'opportunity', label: '查看商机', icon: 'el-icon-connection', cls: '' },
  { key: 'convert', label: '转商机', icon: 'el-icon-aim', cls: '' },
  { key: 'follow', label: '跟进', icon: 'el-icon-chat-line-round', cls: '' },
  { key: 'accept', label: '接收', icon: 'el-icon-check', cls: '' },
  { key: 'assign', label: '分配', icon: 'el-icon-s-promotion', cls: '' },
  { key: 'claim', label: '领取', icon: 'el-icon-thumb', cls: '' },
  { key: 'review', label: '核实', icon: 'el-icon-coordinate', cls: 'crm-warn-link' },
  { key: 'edit', label: '编辑', icon: 'el-icon-edit', cls: '' },
  { key: 'confirm', label: '基础确认', icon: 'el-icon-circle-check', cls: '' },
  { key: 'pool', label: '客户池', icon: 'el-icon-collection-tag', cls: '' },
  { key: 'transfer', label: '转交', icon: 'el-icon-sort', cls: '' },
  { key: 'return', label: '退回', icon: 'el-icon-refresh-left', cls: 'crm-danger-link' },
  { key: 'recycle', label: '回收', icon: 'el-icon-box', cls: 'crm-danger-link' },
  { key: 'invalidate', label: '无效', icon: 'el-icon-close', cls: 'crm-danger-link' }
]

export default {
  name: 'CrmLeadList',
  components: {
    CrmListViewControls,
    CrmLeadFormDialog,
    CrmLeadDetailDialog,
    CrmLeadActionDialog,
    CrmFollowupDialog,
    CrmIdentityReviewDialog,
    CrmOpportunityFormDialog,
    CrmOpportunityDetailDialog,
    CustomerDetailDialog: () => import('@/components/sales/CustomerDetailDialog.vue')
  },
  data: () => ({
    columnGroups: leadColumnGroups,
    viewConfiguration: { columns: leadColumnGroups.flatMap(group => group.fields), filters: {}, sort: { field: '', direction: 'desc' }, group_by: '' },
    metadata: {},
    rows: [],
    total: 0,
    counts: {},
    query: blankQuery(),
    loading: false,
    sequence: 0,
    error: '',
    metadataReady: false,
    recycleReasonOptions: ['超期未跟进', '暂缓采购', '原负责人停用', '客户无需求', '其他'],
    dueOptions: [
      { value: '', label: '全部' },
      { value: 'today', label: '今日待联', icon: 'el-icon-time' },
      { value: 'overdue', label: '逾期未联', icon: 'el-icon-warning-outline' },
      { value: 'pending', label: '已排期', icon: 'el-icon-date' }
    ]
  }),
  computed: {
    viewColumns() { return orderedColumnGroups(this.viewConfiguration.columns,this.columnGroups) },
    viewTableKey() { return this.viewConfiguration.columns.join(',') + ':' + this.viewConfiguration.group_by },
    groupTitle() { const field = ((this.$refs.views && this.$refs.views.metadata.group_fields) || []).find(field => field.key === this.viewConfiguration.group_by); return field ? field.label : '分组' },
    viewFilterOptions() { return { tab: Object.fromEntries(this.visibleTabs.map(item => [item.value,item.label])), status: this.metadata.statuses || {}, department_legacy_id: Object.fromEntries((this.metadata.departments || []).map(item => [item.id,item.name])), entry_method: this.metadata.entry_methods || {}, due: Object.fromEntries(this.dueOptions.map(item => [item.value,item.label])) } },
    currentUser() { return this.metadata.current_user || {} },
    abilities() { return this.metadata.abilities || {} },
    visibleTabs() {
      return [
        { value: 'mine', label: '我负责的', icon: 'el-icon-user-solid', desc: '本人跟进中的线索', tone: 'is-green' },
        { value: 'collaborating', label: '我协作的', icon: 'el-icon-connection', desc: '跨部门协作客户线索', tone: '' },
        { value: 'all', label: '授权范围', icon: 'el-icon-Menu', desc: '授权范围内全部可见线索', tone: '' },
        ...(this.abilities.assign || this.abilities.review
          ? [
            { value: 'pending_assignment', label: '待分配', icon: 'el-icon-s-promotion', desc: '等待指派负责销售', tone: 'is-amber' }
          ]
          : []),
        { value: 'pending_acceptance', label: '待接收', icon: 'el-icon-bell', desc: '分配给我待确认接收', tone: 'is-amber' },
        { value: 'pending_review', label: '待核实', icon: 'el-icon-warning-outline', desc: '身份冲突或疑似客户核实', tone: 'is-red' },
        { value: 'public_pool', label: '公海', icon: 'el-icon-receiving', desc: '回收沉淀可重新领取分配', tone: '' },
        { value: 'draft', label: '草稿', icon: 'el-icon-edit-outline', desc: '本人暂存待提交草稿', tone: '' }
      ]
    },
    currentTabLabel() {
      const found = this.visibleTabs.find(item => item.value === this.query.tab)
      return found ? found.label : '线索列表'
    },
    showStatusFilter() {
      return ['mine', 'collaborating', 'all'].includes(this.query.tab)
    },
    showPoolFilter() {
      return !['public_pool', 'draft', 'pending_review'].includes(this.query.tab)
    },
    hasActiveFilters() {
      return !!(
        this.query.keyword ||
        this.query.department_legacy_id ||
        this.query.source_channel ||
        this.query.entry_method ||
        this.query.recycle_reason ||
        this.query.status ||
        this.query.customer_pool ||
        this.query.customer_id ||
        this.query.customer_tag ||
        this.query.custom_tag ||
        this.query.due
      )
    },
    emptyTitle() {
      const map = {
        mine: '暂无你负责的线索',
        collaborating: '暂无你参与协作的客户线索',
        all: '授权范围内暂无线索记录',
        pending_assignment: '当前没有待分配的线索',
        pending_acceptance: '当前没有待你接收的线索',
        pending_review: '当前没有待核实身份的线索',
        public_pool: '公海池中暂无可查看的线索',
        draft: '暂无保存的线索草稿'
      }
      return map[this.query.tab] || '暂无线索数据'
    },
    emptySub() {
      if (this.hasActiveFilters) return '当前筛选条件下未找到匹配记录，可尝试清空筛选条件后重新查询'
      if (this.query.tab === 'mine') return '可通过右上角“新增线索”录入咨询，或前往“公海”领取线索'
      if (this.query.tab === 'public_pool') return '被人工回收的线索将在此沉淀，供授权销售重新领取跟进'
      return '数据随线索录入、分配和跟进状态实时更新'
    }
  },
  async created() {
    try {
      const { data } = await getCrmLeadMetadata()
      this.metadata = data.data || data
      this.metadataReady = true
      await this.$nextTick()
      const configuration = await this.$refs.views.initialize()
      if (configuration) this.applyView(configuration, false)
      this.readRouteQuery(true)
      await this.load()
    } catch (error) {
      this.error = crmError(error, '线索中心加载失败')
    }
  },
  beforeDestroy() { this.sequence += 1 },
  watch: {
    '$route.query'() {
      if (!this.metadataReady || this.$route.path !== '/sales/leads') return
      const previous = this.query
      this.readRouteQuery()
      if (Object.keys(this.query).every(key => String(previous[key] || '') === String(this.query[key] || ''))) return
      this.load()
    }
  },
  methods: {
    viewGroupSpan: listViewGroupSpan,
    hasField(key) { return this.viewConfiguration.columns.includes(key) },
    applyView(configuration, reload = true) {
      this.viewConfiguration = configuration
      this.query = { ...blankQuery(this.query.per_page,this.abilities.assign && !this.currentUser.is_sales ? 'all' : 'mine'), ...configuration.filters, page: 1 }
      if (!this.visibleTabs.some(item => item.value === this.query.tab)) this.query.tab = 'all'
      if (reload) this.load()
    },
    groupValue(row) { return this.viewConfiguration.group_by === 'status' ? (row.status_label || (this.metadata.statuses || {})[row.status] || '—') : (row[this.viewConfiguration.group_by] || '未填写') },
    clearCustomerFilter() { this.query.customer_id = ''; this.search() },
    readRouteQuery(preserveCurrent = false) {
      const route = this.$route.query
      const query = preserveCurrent ? { ...this.query } : blankQuery(10, this.abilities.assign && !this.currentUser.is_sales ? 'all' : 'mine')
      Object.keys(query).forEach(key => {
        if (typeof route[key] === 'string') query[key] = route[key]
      })
      query.page = Math.max(1, Number.parseInt(query.page, 10) || 1)
      query.per_page = [10, 20, 50, 100].includes(Number(query.per_page)) ? Number(query.per_page) : 10
      if (!this.visibleTabs.some(item => item.value === query.tab)) query.tab = 'all'
      this.query = query
    },
    syncRouteQuery() {
      const query = {}
      Object.entries(this.query).forEach(([key, value]) => {
        if (value !== '' && value !== null && value !== undefined) query[key] = String(value)
      })
      const current = this.$route.query
      if (Object.keys(query).length === Object.keys(current).length && Object.keys(query).every(key => current[key] === query[key])) return
      this.$router.replace({ path: '/sales/leads', query }).catch(() => {})
    },
    date: crmDate,
    shortDate(val) {
      const formatted = crmDate(val)
      return formatted && formatted !== '—' ? formatted.slice(0, 10) : '—'
    },
    regionText(row) {
      return [row.province, row.city, row.district].filter(Boolean).join(' / ')
    },
    formatMoney(value, currency) {
      if (value === null || value === undefined || value === '') return ''
      return `${currency || 'CNY'} ${Number(value).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
    },
    isOverdue(row) {
      if (!row.next_contact_at || row.status !== 'active') return false
      return new Date(row.next_contact_at).getTime() < Date.now()
    },
    isDueToday(row) {
      if (!row.next_contact_at || row.status !== 'active' || this.isOverdue(row)) return false
      const d = new Date(row.next_contact_at)
      const now = new Date()
      return d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth() && d.getDate() === now.getDate()
    },
    statusType(status) {
      if (['draft', 'pending_assignment', 'pending_acceptance'].includes(status)) return 'warning'
      if (status === 'pending_review' || status === 'invalid') return 'danger'
      if (status === 'public_pool') return 'info'
      return 'success'
    },
    rowActions(row) {
      const allowed = row.abilities || {}
      return ACTION_META.filter(action => action.key === 'opportunity' ? !!row.opportunity_id : (action.key === 'review' ? row.review && row.review.can_resolve : allowed[action.key]))
    },
    handleAction(row, action) {
      if (action === 'opportunity') this.$refs.opportunityDetail.open(row.opportunity_id)
      else if (action === 'convert') this.$refs.opportunityForm.open({ lead: row })
      else if (action === 'edit') this.$refs.form.open(row.id)
      else if (action === 'review') this.$refs.review.openReview(row.review)
      else if (action === 'follow') {
        this.$refs.follow.open({
          subject_type: 'lead',
          subject_id: row.id,
          customer_id: row.customer_id,
          customer_name: row.customer_name,
          lead_no: row.lead_no,
          contact_name: row.contact_name
        })
      } else {
        this.$refs.action.open(action, row)
      }
    },
    openCustomer(customerId) {
      if (customerId && this.$refs.customer) {
        this.$refs.customer.open(customerId)
      }
    },
    opportunitySaved(row) {
      this.load()
      if (row && row.id) this.$refs.opportunityDetail.open(row.id)
    },
    copyText(text, label = '内容') {
      if (!text) return
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(() => {
          this.$message.success(`${label}已复制：${text}`)
        }).catch(() => this.fallbackCopy(text, label))
      } else {
        this.fallbackCopy(text, label)
      }
    },
    fallbackCopy(text, label) {
      const input = document.createElement('input')
      input.value = text
      document.body.appendChild(input)
      input.select()
      document.execCommand('copy')
      document.body.removeChild(input)
      this.$message.success(`${label}已复制：${text}`)
    },
    switchTab(tabValue) {
      if (this.query.tab === tabValue) return
      this.query.tab = tabValue
      this.handleTabClick()
    },
    handleTabClick() {
      if (this.query.tab !== 'mine') this.query.due = ''
      if (!this.showStatusFilter) this.query.status = ''
      if (!this.showPoolFilter) this.query.customer_pool = ''
      if (this.query.tab !== 'public_pool') this.query.recycle_reason = ''
      this.search()
    },
    selectDue(dueVal) {
      this.query.due = this.query.due === dueVal ? '' : dueVal
      this.search()
    },
    async load() {
      this.syncRouteQuery()
      const sequence = ++this.sequence
      this.loading = true
      this.error = ''
      try {
        const { data } = await listCrmLeads({ ...this.query, ...listViewQuery(this.viewConfiguration) })
        if (sequence !== this.sequence) return
        this.rows = data.data || []
        this.total = data.total || 0
        this.counts = data.tabs || {}
      } catch (error) {
        if (sequence === this.sequence) {
          this.rows = []
          this.total = 0
          this.counts = {}
          this.error = crmError(error, '线索列表加载失败')
        }
      } finally {
        if (sequence === this.sequence) this.loading = false
      }
    },
    search() {
      this.query.page = 1
      this.load()
    },
    reset() {
      this.query = blankQuery(this.query.per_page, this.query.tab)
      this.load()
    }
  }
}
</script>

<style scoped>
.page-head-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 14px 18px;
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
  gap: 10px;
  flex-wrap: wrap;
}
.title-row h1 {
  margin: 0;
  font-size: 20px;
  font-weight: 700;
  color: #1e293b;
}
.user-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 2px 10px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 999px;
  font-size: 12px;
  color: #475569;
}
.role-badge {
  font-style: normal;
  padding: 0 6px;
  border-radius: 4px;
  background: #f0fdf4;
  color: #00763f;
  border: 1px solid #bbf7d0;
  font-size: 11px;
  line-height: 18px;
}
.role-badge.ops {
  background: #fffbeb;
  color: #b45309;
  border-color: #fde68a;
}
.head-sub {
  margin: 4px 0 0;
  font-size: 12px;
  color: #64748b;
}

.lead-kpi-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 10px;
  min-width: 0;
}
.kpi-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 10px 14px;
  cursor: pointer;
  transition: all 0.16s ease;
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
.kpi-desc {
  margin-top: 2px;
  font-size: 11px;
  color: #94a3b8;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.tabs-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  background: #ffffff;
  padding: 0 16px;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
}
.lead-tabs {
  flex: 1;
  min-width: 0;
}
.lead-tabs :deep(.el-tabs__nav-wrap::after) {
  display: none;
}
.tab-label-wrap {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.tab-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 20px;
  height: 18px;
  padding: 0 6px;
  border-radius: 999px;
  background: #f1f5f9;
  color: #64748b;
  font-size: 11px;
  font-weight: 600;
  line-height: 1;
}
.tab-badge.is-active {
  background: #dcfce7;
  color: #00763f;
}
.tab-badge.is-alert {
  background: #fef3c7;
  color: #b45309;
}

.due-quick-group {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
  padding: 6px 0;
}
.due-label {
  font-size: 12px;
  color: #64748b;
}
.due-pill {
  border-radius: 999px;
}

.context-banner {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 9px 14px;
  border-radius: 8px;
  font-size: 12px;
  line-height: 1.5;
  border: 1px solid #e2e8f0;
  background: #ffffff;
  color: #475569;
}
.context-banner.is-green {
  background: #f0fdf4;
  border-color: #bbf7d0;
  color: #166534;
}
.context-banner.is-amber {
  background: #fffbeb;
  border-color: #fde68a;
  color: #92400e;
}
.context-banner.is-slate {
  background: #f8fafc;
  border-color: #e2e8f0;
  color: #475569;
}
.banner-tag {
  margin-left: auto;
  padding: 1px 8px;
  border-radius: 4px;
  background: rgba(255, 255, 255, 0.8);
  border: 1px solid currentColor;
  font-size: 11px;
  white-space: nowrap;
}

.filter-card {
  gap: 12px;
}
.filter-card .kw-input {
  width: 260px;
}
.filter-btns {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-left: auto;
}

.cell-primary-line {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
  line-height: 1.45;
}
.cell-sub-line {
  display: flex;
  align-items: center;
  gap: 4px;
  flex-wrap: wrap;
  margin-top: 3px;
  font-size: 12px;
  color: #64748b;
  line-height: 1.45;
}
.lead-name-link {
  padding: 0;
  font-weight: 700;
  font-size: 13px;
  color: #008b4b;
  text-align: left;
  white-space: normal;
}
.lead-name-link:hover {
  color: #006334;
  text-decoration: underline;
}
.cust-code-link {
  padding: 0;
  font-size: 12px;
  color: #008b4b;
}
.mono-no {
  font-family: Consolas, Monaco, monospace;
  color: #64748b;
}
.sep {
  color: #cbd5e1;
}
.text-strong {
  color: #1e293b;
  font-weight: 600;
}
.role-text {
  color: #64748b;
  font-size: 12px;
}
.muted-addr {
  color: #94a3b8;
}

.kind-pill,
.new-badge,
.entry-pill,
.pending-pill,
.confirmed-pill,
.pool-badge,
.overdue-pill,
.today-pill,
.amount-pill,
.meta-chip {
  display: inline-flex;
  align-items: center;
  gap: 2px;
  padding: 0 6px;
  border-radius: 4px;
  font-size: 11px;
  line-height: 18px;
  white-space: nowrap;
}
.kind-pill.is-ent {
  background: #f0fdf4;
  color: #00763f;
  border: 1px solid #bbf7d0;
}
.kind-pill.is-ind {
  background: #f8fafc;
  color: #475569;
  border: 1px solid #e2e8f0;
}
.new-badge {
  background: #fffbeb;
  color: #b45309;
  border: 1px solid #fde68a;
}
.entry-pill.is-self {
  background: #f0fdf4;
  color: #00763f;
  border: 1px solid #bbf7d0;
}
.entry-pill.is-ops {
  background: #f1f5f9;
  color: #475569;
  border: 1px solid #e2e8f0;
}
.pending-pill {
  background: #fffbeb;
  color: #b45309;
  border: 1px solid #fde68a;
}
.confirmed-pill {
  background: #f0fdf4;
  color: #00763f;
  border: 1px solid #bbf7d0;
}
.pool-badge {
  background: #f0fdf4;
  color: #00763f;
  border: 1px solid #dcfce7;
}
.pool-badge.is-amber {
  background: #fffbeb;
  color: #b45309;
  border-color: #fde68a;
}
.overdue-pill {
  background: #fef2f2;
  color: #dc2626;
  border: 1px solid #fecaca;
}
.today-pill {
  background: #fffbeb;
  color: #b45309;
  border: 1px solid #fde68a;
}
.due-today {
  color: #d97706;
}

.demand-text {
  color: #1e293b;
  font-weight: 500;
  line-height: 1.5;
  word-break: break-word;
}
.cell-tags-line {
  display: flex;
  align-items: center;
  gap: 4px;
  flex-wrap: wrap;
  margin-top: 4px;
}
.amount-pill {
  background: #fffbeb;
  color: #b45309;
  border: 1px solid #fde68a;
  font-weight: 600;
}
.meta-chip {
  background: #f8fafc;
  color: #475569;
  border: 1px solid #e2e8f0;
}
.meta-chip.is-green {
  background: #f0fdf4;
  color: #00763f;
  border-color: #bbf7d0;
}

.schedule-lbl {
  color: #94a3b8;
}
.plan-summary {
  color: #475569;
  background: #f8fafc;
  padding: 2px 6px;
  border-radius: 4px;
  margin-top: 4px;
}

.row-ops {
  gap: 10px;
}

.lead-empty {
  padding: 44px 16px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  color: #64748b;
}
.lead-empty > i {
  font-size: 34px;
  color: #008b4b;
  background: #f0fdf4;
  width: 56px;
  height: 56px;
  border-radius: 50%;
  display: grid;
  place-items: center;
  margin-bottom: 4px;
}
.lead-empty > b {
  font-size: 14px;
  color: #1e293b;
}
.lead-empty > p {
  margin: 0;
}
.empty-actions {
  margin-top: 8px;
  display: flex;
  gap: 8px;
}

.table-footer {
  justify-content: space-between;
  border-top: 1px solid #eef2f7;
}
.pager-summary {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: #64748b;
  flex-wrap: wrap;
}
.pager-summary b {
  color: #1e293b;
}
.due-active-hint {
  color: #008b4b;
  font-weight: 600;
}

@media (max-width: 1180px) {
  .lead-kpi-grid {
    grid-template-columns: repeat(auto-fit, minmax(135px, 1fr));
  }
}
@media (max-width: 780px) {
  .filter-card .kw-input {
    width: 100%;
  }
  .filter-btns {
    width: 100%;
    justify-content: flex-end;
  }
  .table-footer {
    justify-content: center;
  }
}
</style>
