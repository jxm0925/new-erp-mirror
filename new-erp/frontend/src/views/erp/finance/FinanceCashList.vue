<template>
  <div class="cash-list-container">
    <!-- 页面全局头部：图标、标题、统计标签与主要操作 (完全对齐主数据中心规范) -->
    <header class="page-head">
      <div class="head-left">
        <span class="head-icon"><i :class="direction === 'receipt' ? 'el-icon-bottom-left' : 'el-icon-wallet'" /></span>
        <div class="head-title-wrap">
          <div class="title-row">
            <h1 class="page-title">{{ title }}管理</h1>
            <el-tag size="small" type="success" effect="plain" class="head-tag total-tag">共 {{ total }} 笔{{ title }}单</el-tag>
            <el-tag v-if="hasSourceReference && sourceContext" size="small" type="warning" effect="plain" class="head-tag source-tag">
              来源冲抵中: {{ sourceContext.no }}
            </el-tag>
          </div>
        </div>
      </div>
      <div class="head-actions">
        <el-button size="small" icon="el-icon-refresh" class="btn-refresh" :loading="loading" @click="search">刷新</el-button>
        <el-button v-if="$can('finance.view')" size="small" icon="el-icon-data-analysis" class="btn-stat" @click="$router.push('/finance/statistics')">财务统计</el-button>
        <el-button v-if="$can(createPermission)" size="small" type="success" icon="el-icon-plus" class="btn-theme-create" :disabled="sourceLoading || !!sourceError" @click="create">
          新增{{ title }}
        </el-button>
      </div>
    </header>

    <!-- 全局统一页面提示条 (对齐主数据中心规范) -->
    <div class="erp-page-tip">
      <i class="el-icon-info" />
      <span>{{ pageTipText }}</span>
    </div>

    <!-- 来源应付单冲抵上下文提示条 (当从应付管理携带 source 跳转时展示) -->
    <section v-if="hasSourceReference" class="source-context-card" v-loading="sourceLoading">
      <div class="source-context-left">
        <span class="source-badge-icon"><i class="el-icon-link" /></span>
        <div class="source-info">
          <div class="source-title-line">
            <strong>冲抵目标：{{ sourceContext ? sourceContext.no : '解析中...' }}</strong>
            <span v-if="sourceContext" class="source-remaining-pill">待结算 {{ money(sourceContext.remainingAmount) }} {{ sourceContext.currency }}</span>
          </div>
          <span v-if="sourceContext" class="source-party-text">
            交易对手：{{ sourceContext.partyName }} · 正在为您筛选同一对手同币种且有可用预付款余额的已确认付款单
          </span>
          <span v-if="sourceError" class="source-error-text"><i class="el-icon-warning" /> {{ sourceError }}</span>
        </div>
      </div>
      <div class="source-actions">
        <el-button size="small" icon="el-icon-back" class="btn-return-payables" @click="returnToPayables">返回应付管理</el-button>
      </div>
    </section>

    <!-- 筛选工具栏与主表格卡片 (对齐主数据中心 table-container-card 规范) -->
    <section class="table-container-card">
      <div class="filter-toolbar">
        <div class="filter-fields">
          <div class="filter-item">
            <span class="filter-label">{{ title }}单号</span>
            <el-input v-model.trim="query.keyword" size="small" clearable :placeholder="title + '单号...'" @keyup.enter.native="search" />
          </div>
          <div class="filter-item">
            <span class="filter-label">交易对手</span>
            <el-input v-model.trim="query.party_keyword" size="small" clearable placeholder="请输入名称..." @keyup.enter.native="search" />
          </div>
          <div class="filter-item">
            <span class="filter-label">资金账户</span>
            <paged-record-select v-model="query.finance_account_id" size="small" clearable placeholder="全部账户" @change="search" :request="listAccounts" :columns="[{ prop: 'account_no', label: '账户编号' }, { prop: 'account_name', label: '账户名称' }, { prop: 'currency', label: '币种' }]" :params="{ status: 'enabled' }" :label="accountLabel" title="选择资金账户" />
          </div>
          <div class="filter-item">
            <span class="filter-label">单据状态</span>
            <el-select v-model="query.status" size="small" clearable placeholder="全部状态" @change="statusChanged">
              <el-option label="草稿" value="draft" />
              <el-option label="已确认" value="confirmed" />
              <el-option label="已作废" value="voided" />
            </el-select>
          </div>
          <div class="filter-item">
            <span class="filter-label">核销状态</span>
            <el-select v-model="query.allocation_status" size="small" clearable placeholder="全部" @change="allocationStatusChanged">
              <el-option label="待核销" value="pending" />
              <el-option label="已全部核销" value="settled" />
            </el-select>
          </div>
          <div class="filter-item date-filter-item">
            <span class="filter-label">{{ title }}日期</span>
            <el-date-picker v-model="query.date_range" size="small" type="daterange" value-format="yyyy-MM-dd" range-separator="至" start-placeholder="开始日期" end-placeholder="结束日期" @change="search" />
          </div>
        </div>
        <div class="filter-actions">
          <el-button size="small" type="success" icon="el-icon-search" class="btn-theme-search" :disabled="sourceLoading || !!sourceError" @click="search">查询</el-button>
          <el-button size="small" icon="el-icon-refresh-left" class="btn-theme-reset" @click="reset">重置</el-button>
        </div>
      </div>

      <!-- 表格区域 -->
      <div ref="cashTableCard" class="table-wrap">
        <el-table
          v-loading="loading"
          :data="rows"
          border
          stripe
          size="small"
          class="enterprise-table"
          empty-text="暂无单据记录"
        >
          <el-table-column prop="document_no" :label="title + '单号'" min-width="160">
            <template slot-scope="{ row }">
              <span class="doc-code-link font-tabular" @click="view(row)">
                <i :class="direction === 'receipt' ? 'el-icon-bottom-left' : 'el-icon-wallet'" />
                {{ row.document_no }}
              </span>
            </template>
          </el-table-column>
          <el-table-column :label="title + '日期'" width="110" align="center">
            <template slot-scope="{ row }">
              <span class="date-text font-tabular">{{ dateText(row.business_date) }}</span>
            </template>
          </el-table-column>
          <el-table-column prop="party_name_snapshot" label="交易对手" min-width="170" show-overflow-tooltip>
            <template slot-scope="{ row }">
              <span class="party-name-text">{{ row.party_name_snapshot || '—' }}</span>
            </template>
          </el-table-column>
          <el-table-column label="关联采购订单" min-width="180" show-overflow-tooltip>
            <template slot-scope="{ row }">
              <span class="po-summary-text">{{ purchaseOrderSummary(row) }}</span>
            </template>
          </el-table-column>
          <el-table-column prop="account.account_name" label="资金账户" min-width="140" show-overflow-tooltip>
            <template slot-scope="{ row }">
              <span class="account-name-badge">{{ row.account ? row.account.account_name : '—' }}</span>
            </template>
          </el-table-column>
          <el-table-column prop="currency" label="币种" width="75" align="center">
            <template slot-scope="{ row }">
              <el-tag size="mini" type="info" effect="plain" class="currency-cell-tag">{{ row.currency }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column :label="title + '金额'" width="125" align="right">
            <template slot-scope="{ row }">
              <strong class="money-text font-tabular">{{ money(row.amount) }}</strong>
            </template>
          </el-table-column>
          <el-table-column label="已核销" width="115" align="right">
            <template slot-scope="{ row }">
              <span class="allocated-text font-tabular">{{ money(row.allocated_amount) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="未核销" width="115" align="right">
            <template slot-scope="{ row }">
              <strong :class="['font-tabular', Number(row.unallocated_amount) > 0 ? 'unallocated-active' : 'unallocated-zero']">
                {{ money(row.unallocated_amount) }}
              </strong>
            </template>
          </el-table-column>
          <el-table-column :label="title + '方式'" min-width="110" align="center">
            <template slot-scope="{ row }">
              <span class="method-tag">{{ paymentMethodName(row) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="状态" width="110" align="center">
            <template slot-scope="{ row }">
              <el-tag :type="tagType(row.status)" size="mini" effect="plain" class="status-cell-tag">{{ statusLabel(row) }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="经办人" width="100" align="center">
            <template slot-scope="{ row }">
              <span class="operator-text">{{ row.operator_name_snapshot || row.operator_name || '—' }}</span>
            </template>
          </el-table-column>
          <el-table-column label="操作" :width="hasSourceReference ? 250 : 230" fixed="right" align="center">
            <template slot-scope="{ row }">
              <div class="row-actions">
                <el-button type="text" size="mini" class="btn-action-view" @click="view(row)">查看</el-button>
                <el-button v-if="row.status === 'draft' && $can(confirmPermission)" type="text" size="mini" class="btn-action-confirm" @click="confirm(row)">确认</el-button>
                <el-button v-if="row.status !== 'draft'" type="text" size="mini" :class="hasSourceReference ? 'btn-action-use-prepay' : 'btn-action-alloc'" :disabled="hasSourceReference && !canUseSource(row)" @click="openAllocation(row)">
                  {{ allocationLabel(row) }}
                </el-button>
                <!-- 次要操作收进“更多”：采购关联、作废（危险操作标红）。 -->
                <el-dropdown v-if="(row.party_type === 'supplier' && row.status !== 'draft') || (row.status === 'confirmed' && $can(voidPermission))" trigger="click" size="small" @command="command => command === 'void' ? voidDoc(row) : openPurchaseLinks(row)">
                  <el-button type="text" size="mini" class="btn-action-link">更多<i class="el-icon-arrow-down el-icon--right" /></el-button>
                  <el-dropdown-menu slot="dropdown">
                    <el-dropdown-item v-if="row.party_type === 'supplier' && row.status !== 'draft'" command="links" icon="el-icon-connection">采购关联</el-dropdown-item>
                    <el-dropdown-item v-if="row.status === 'confirmed' && $can(voidPermission)" command="void" icon="el-icon-delete" class="row-op-danger" divided>作废</el-dropdown-item>
                  </el-dropdown-menu>
                </el-dropdown>
              </div>
            </template>
          </el-table-column>
        </el-table>

        <div class="table-pagination-row">
          <el-pagination
            background
            small
            layout="total, prev, pager, next, sizes, jumper"
            :current-page="page"
            :page-size="perPage"
            :page-sizes="[10, 20, 50, 100]"
            :total="total"
            @current-change="changePage"
            @size-change="changePageSize"
          />
        </div>
      </div>
    </section>

    <!-- 核销弹窗 -->
    <el-dialog :title="title + '核销'" :visible.sync="allocationVisible" class="cash-list-allocation-dialog" width="1100px" top="5vh" append-to-body :close-on-click-modal="false" :destroy-on-close="true" @closed="allocationId = null">
      <FinanceAllocation v-if="allocationVisible && allocationId" :key="allocationId" :cash-id="allocationId" embedded :initial-source="allocationSource" @changed="allocationChanged" />
    </el-dialog>

    <!-- 采购付款关联弹窗 -->
    <purchase-payment-link-dialog :visible.sync="purchaseLinksVisible" :cash-id="purchaseLinksCashId" @changed="load" />

    <!-- 收付款单据详情居中弹窗 (对齐居中弹窗规范 el-dialog) -->
    <el-dialog
      :title="title + '单据详情'"
      :visible.sync="detailDialogVisible"
      width="880px"
      top="5vh"
      custom-class="cash-detail-dialog"
      :close-on-click-modal="true"
      append-to-body
    >
      <div v-if="detailDoc" v-loading="detailLoading" class="detail-dialog-body">
        <!-- 弹窗顶端横幅 -->
        <div class="modal-summary-banner">
          <div class="banner-icon">
            <i :class="direction === 'receipt' ? 'el-icon-bottom-left' : 'el-icon-wallet'" />
          </div>
          <div class="banner-main">
            <div class="banner-title-line">
              <h2 class="font-tabular">{{ detailDoc.document_no }}</h2>
              <el-tag size="mini" :type="tagType(detailDoc.status)" effect="plain">
                {{ statusLabel(detailDoc) }}
              </el-tag>
              <el-tag size="mini" type="info" effect="plain">
                {{ detailDoc.party_type === 'supplier' ? '供应商' : '客户' }}
              </el-tag>
              <el-tag v-if="detailDoc.status === 'confirmed'" size="mini" :type="Number(detailDoc.unallocated_amount) <= 0 ? 'success' : 'warning'" effect="light">
                {{ Number(detailDoc.unallocated_amount) <= 0 ? '已全部核销' : (Number(detailDoc.allocated_amount) > 0 ? '已部分核销' : '待核销') }}
              </el-tag>
            </div>
            <div class="banner-sub-meta">
              <span>交易对手：<strong class="party-name-strong">{{ detailDoc.party_name_snapshot || '—' }}</strong></span>
              <span>资金账户：<span class="account-name-badge">{{ detailDoc.account ? detailDoc.account.account_name : '—' }}</span></span>
              <span>结算方式：<strong>{{ paymentMethodName(detailDoc) }}</strong></span>
              <span>业务日期：<strong class="font-tabular">{{ dateText(detailDoc.business_date) }}</strong></span>
              <span>经办人：<strong>{{ detailDoc.operator_name_snapshot || detailDoc.operator_name || '—' }}</strong></span>
            </div>
          </div>
        </div>

        <!-- 4 项重点金额快报 KPI Cards -->
        <div class="detail-kpi-grid">
          <div class="kpi-mini-card is-primary">
            <span class="kpi-label">实{{ title }}金额</span>
            <strong class="kpi-val font-tabular">{{ money(detailDoc.amount) }} <span class="kpi-currency">{{ detailDoc.currency }}</span></strong>
          </div>
          <div class="kpi-mini-card is-success">
            <span class="kpi-label">已核销金额</span>
            <strong class="kpi-val font-tabular text-success">{{ money(detailDoc.allocated_amount) }} <span class="kpi-currency">{{ detailDoc.currency }}</span></strong>
          </div>
          <div class="kpi-mini-card" :class="Number(detailDoc.unallocated_amount) > 0 ? 'is-warning' : 'is-muted'">
            <span class="kpi-label">未核销余额</span>
            <strong :class="['kpi-val font-tabular', Number(detailDoc.unallocated_amount) > 0 ? 'text-amber' : 'text-muted']">
              {{ money(detailDoc.unallocated_amount) }} <span class="kpi-currency">{{ detailDoc.currency }}</span>
            </strong>
          </div>
          <div class="kpi-mini-card is-fee">
            <span class="kpi-label">手续费 ({{ feeTypeLabel(detailDoc.platform_fee_type) }})</span>
            <strong class="kpi-val font-tabular">{{ money(detailDoc.platform_fee_amount || 0) }} <span class="kpi-currency">{{ detailDoc.currency }}</span></strong>
          </div>
        </div>

        <!-- 卡片式分块详情 -->
        <div class="detail-cards-stack">
          <!-- 卡片 1：基础单据与结算要素 -->
          <div class="detail-card">
            <div class="card-title-bar">
              <i class="el-icon-document" />
              <span>基础单据与结算要素</span>
            </div>
            <div class="kv-grid">
              <div class="kv-item"><span class="kv-label">{{ title }}单号</span><span class="kv-val font-tabular code-mono font-bold">{{ detailDoc.document_no }}</span></div>
              <div class="kv-item"><span class="kv-label">业务日期</span><span class="kv-val font-tabular">{{ dateText(detailDoc.business_date) }}</span></div>
              <div class="kv-item"><span class="kv-label">交易对手</span><span class="kv-val font-bold">{{ detailDoc.party_name_snapshot || '—' }}</span></div>
              <div class="kv-item"><span class="kv-label">资金账户</span><span class="kv-val">{{ detailDoc.account ? `${detailDoc.account.account_name} (${detailDoc.account.currency})` : '—' }}</span></div>
              <div class="kv-item"><span class="kv-label">结算币种</span><span class="kv-val font-tabular">{{ detailDoc.currency }}</span></div>
              <div class="kv-item"><span class="kv-label">结算方式</span><span class="kv-val">{{ paymentMethodName(detailDoc) }}</span></div>
              <div class="kv-item"><span class="kv-label">外部流水号</span><span class="kv-val font-tabular code-mono">{{ detailDoc.external_reference_no || '—' }}</span></div>
              <div class="kv-item"><span class="kv-label">经办人</span><span class="kv-val">{{ detailDoc.operator_name_snapshot || detailDoc.operator_name || '—' }}</span></div>
              <div class="kv-item"><span class="kv-label">制单时间</span><span class="kv-val font-tabular">{{ formatDateTime(detailDoc.created_at) }}</span></div>
              <div class="kv-item full-width-kv"><span class="kv-label">业务备注</span><span class="kv-val remark-text">{{ detailDoc.remark || '—' }}</span></div>
            </div>
          </div>

          <!-- 卡片 2：采购订单用途关联 -->
          <div v-if="detailDoc.party_type === 'supplier' || (detailDoc.purchase_order_allocations && detailDoc.purchase_order_allocations.length)" class="detail-card">
            <div class="card-title-bar">
              <i class="el-icon-goods" />
              <span>采购订单用途关联</span>
              <el-tag size="mini" type="info" class="card-title-tag">
                {{ (detailDoc.purchase_order_allocations && detailDoc.purchase_order_allocations.length) ? `已关联 ${detailDoc.purchase_order_allocations.length} 笔订单` : '未指定具体采购用途' }}
              </el-tag>
            </div>
            <div v-if="detailDoc.purchase_order_allocations && detailDoc.purchase_order_allocations.length" class="table-wrap">
              <el-table :data="detailDoc.purchase_order_allocations" border stripe size="mini" class="enterprise-table">
                <el-table-column label="采购订单号" min-width="160">
                  <template slot-scope="{ row }">
                    <span class="font-tabular font-bold code-mono">{{ row.purchase_order_no || ('#' + row.purchase_order_id) }}</span>
                  </template>
                </el-table-column>
                <el-table-column label="款项期次 / 阶段" min-width="130" align="center">
                  <template slot-scope="{ row }">
                    <el-tag size="mini" effect="plain" type="info">{{ row.payment_plan_stage || '全款/自由款' }}</el-tag>
                  </template>
                </el-table-column>
                <el-table-column label="到期日期" width="115" align="center">
                  <template slot-scope="{ row }">
                    <span class="font-tabular">{{ row.payment_plan_due_date || '—' }}</span>
                  </template>
                </el-table-column>
                <el-table-column label="用途金额" width="130" align="right">
                  <template slot-scope="{ row }">
                    <strong class="font-tabular money-text">{{ money(row.amount) }} {{ detailDoc.currency }}</strong>
                  </template>
                </el-table-column>
              </el-table>
            </div>
            <div v-else class="empty-section-tip">
              <i class="el-icon-info" /> 本单据为通用款项，未绑定具体采购订单期次。
            </div>
            <div v-if="detailDoc.purchase_order_allocation_reason" class="purpose-reason-bar">
              <i class="el-icon-chat-dot-round" />
              <span>用途说明：{{ detailDoc.purchase_order_allocation_reason }}</span>
            </div>
          </div>

          <!-- 卡片 3：核销明细流水 -->
          <div class="detail-card">
            <div class="card-title-bar">
              <i class="el-icon-connection" />
              <span>核销明细流水</span>
              <el-tag size="mini" type="info" class="card-title-tag">
                {{ (detailDoc.allocations && detailDoc.allocations.length) ? `共 ${detailDoc.allocations.length} 笔核销` : '暂无对账冲减流水' }}
              </el-tag>
            </div>
            <div v-if="detailDoc.allocations && detailDoc.allocations.length" class="table-wrap">
              <el-table :data="detailDoc.allocations" border stripe size="mini" class="enterprise-table">
                <el-table-column label="来源类型" min-width="130">
                  <template slot-scope="{ row }">{{ sourceLabel(row.source_business_type) }}</template>
                </el-table-column>
                <el-table-column prop="source_document_no" label="业务单号" min-width="160">
                  <template slot-scope="{ row }">
                    <span class="font-tabular code-mono">{{ row.source_document_no }}</span>
                  </template>
                </el-table-column>
                <el-table-column label="核销金额" width="130" align="right">
                  <template slot-scope="{ row }">
                    <strong class="font-tabular money-text">{{ money(row.allocated_amount) }}</strong>
                  </template>
                </el-table-column>
                <el-table-column label="核销状态" width="95" align="center">
                  <template slot-scope="{ row }">
                    <el-tag :type="row.status === 'active' ? 'success' : 'info'" size="mini" effect="plain">
                      {{ row.status === 'active' ? '已核销' : '已撤销' }}
                    </el-tag>
                  </template>
                </el-table-column>
                <el-table-column label="核销时间" min-width="155" align="center">
                  <template slot-scope="{ row }">
                    <span class="font-tabular">{{ formatDateTime(row.allocated_at) }}</span>
                  </template>
                </el-table-column>
              </el-table>
            </div>
            <div v-else class="empty-section-tip">
              <i class="el-icon-info" /> 暂无核销记录。
            </div>
          </div>

          <!-- 卡片 4：单据附件 (若有附件) -->
          <div v-if="detailDoc.attachments && detailDoc.attachments.length" class="detail-card">
            <div class="card-title-bar">
              <i class="el-icon-paperclip" />
              <span>单据附件 ({{ detailDoc.attachments.length }})</span>
            </div>
            <div class="table-wrap">
              <el-table :data="detailDoc.attachments" border stripe size="mini" class="enterprise-table">
                <el-table-column prop="original_name" label="文件名" min-width="200" show-overflow-tooltip />
                <el-table-column label="大小" width="100" align="center">
                  <template slot-scope="{ row }">{{ fileSize(row.file_size) }}</template>
                </el-table-column>
                <el-table-column prop="uploaded_at" label="上传时间" min-width="150" align="center" />
              </el-table>
            </div>
          </div>

          <!-- 卡片 5：单据流转审计日志 (若有日志) -->
          <div v-if="detailDoc.logs && detailDoc.logs.length" class="detail-card">
            <div class="card-title-bar">
              <i class="el-icon-time" />
              <span>单据流转日志 ({{ detailDoc.logs.length }})</span>
            </div>
            <div class="table-wrap">
              <el-table :data="detailDoc.logs" border stripe size="mini" max-height="180" class="enterprise-table">
                <el-table-column label="操作时间" width="155" align="center">
                  <template slot-scope="{ row }">{{ formatDateTime(row.created_at) }}</template>
                </el-table-column>
                <el-table-column prop="operator_name" label="操作人" width="100" align="center">
                  <template slot-scope="{ row }">{{ row.operator_name || '系统' }}</template>
                </el-table-column>
                <el-table-column label="动作" width="110" align="center">
                  <template slot-scope="{ row }">
                    <el-tag size="mini" type="info" effect="plain">{{ logLabel(row.action) }}</el-tag>
                  </template>
                </el-table-column>
                <el-table-column prop="content" label="操作内容" min-width="240" show-overflow-tooltip />
                <el-table-column prop="remark" label="备注" min-width="120" show-overflow-tooltip />
              </el-table>
            </div>
          </div>
        </div>
      </div>

      <div slot="footer" class="dialog-footer">
        <el-button size="small" @click="detailDialogVisible = false">关闭</el-button>
        <el-button
          v-if="detailDoc && detailDoc.status === 'draft' && $can(createPermission)"
          size="small"
          type="primary"
          icon="el-icon-edit"
          class="btn-detail-edit"
          @click="editDraftFromDetail"
        >
          编辑草稿
        </el-button>
        <el-button
          v-if="detailDoc && detailDoc.status === 'draft' && $can(confirmPermission)"
          size="small"
          type="success"
          icon="el-icon-check"
          class="btn-detail-confirm"
          @click="confirmFromDetail"
        >
          确认{{ title }}
        </el-button>
        <el-button
          v-if="detailDoc && detailDoc.status !== 'draft'"
          size="small"
          icon="el-icon-connection"
          :class="hasSourceReference ? 'btn-detail-prepay' : 'btn-detail-alloc'"
          :disabled="hasSourceReference && !canUseSource(detailDoc)"
          @click="openAllocationFromDetail"
        >
          {{ allocationLabel(detailDoc) }}
        </el-button>
        <el-button
          v-if="detailDoc && detailDoc.party_type === 'supplier' && detailDoc.status !== 'draft'"
          size="small"
          icon="el-icon-goods"
          class="btn-detail-link"
          @click="openPurchaseLinksFromDetail"
        >
          采购关联
        </el-button>
        <el-button
          v-if="detailDoc && detailDoc.status === 'confirmed' && $can(voidPermission)"
          size="small"
          type="danger"
          icon="el-icon-delete"
          plain
          @click="voidFromDetail"
        >
          作废
        </el-button>
      </div>
    </el-dialog>
  </div>
</template>

<script>
import PagedRecordSelect from '@/components/common/PagedRecordSelect.vue'
import { listCashDocuments, confirmCashDocument, voidCashDocument, listFinanceAccounts, resolveFinanceSource, getCashDocument } from '../../../api/erp/finance'
import FinanceAllocation from './FinanceAllocation.vue'

const blankQuery = () => ({ keyword: '', party_keyword: '', finance_account_id: '', status: '', allocation_status: '', date_range: [] })
const positiveId = value => /^\d+$/.test(String(value || '')) && Number(value) > 0 ? Number(value) : null

export default {
  components: { PagedRecordSelect, FinanceAllocation, PurchasePaymentLinkDialog: () => import('../../../components/finance/PurchasePaymentLinkDialog.vue') },
  props: { direction: { type: String, required: true } },
  data: () => ({
    loading: false, rows: [], accounts: [], page: 1, perPage: 10, total: 0,
    query: blankQuery(), partyFilter: {}, sourceContext: null, sourceLoading: false, sourceError: '',
    allocationVisible: false, allocationId: null, contextRevision: 0, loadRevision: 0,
    purchaseLinksVisible: false, purchaseLinksCashId: 0,
    detailDialogVisible: false, detailLoading: false, detailDoc: null,
  }),
  computed: {
    title() { return this.direction === 'receipt' ? '收款' : '付款' },
    basePath() { return this.direction === 'receipt' ? '/finance/receipts' : '/finance/payments' },
    createPermission() { return 'finance.' + this.direction + '.create' },
    confirmPermission() { return 'finance.' + this.direction + '.confirm' },
    voidPermission() { return 'finance.' + this.direction + '.void' },
    routeContextKey() { return JSON.stringify([this.direction, this.$route.query]) },
    hasSourceReference() { return !!(this.$route.query.source_id || this.$route.query.source_type) },
    allocationSource() { return this.sourceContext ? { type: this.sourceContext.type, id: this.sourceContext.id } : null },
    pageTipText() {
      return this.direction === 'receipt'
        ? '维护企业收款现金单据与客户对账流水。支持销售结算冲减、应收对冲核销与各资金账户收款过账。'
        : '维护企业供应商与费用付款单据。支持采购订单预付款排期、到货应付对冲核销与资金出账对账。'
    }
  },
  watch: {
    routeContextKey: { immediate: true, handler() { this.initializeFromRoute() } },
  },
  beforeDestroy() { this.contextRevision++; this.loadRevision++ },
  methods: {
    listAccounts: listFinanceAccounts,
    accountLabel(row) { return row.account_name },
    money(value) { return Number(value || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 4 }) },
    dateText(value) { return String(value || '').slice(0, 10) || '—' },
    tagType(value) { return value === 'confirmed' ? 'success' : value === 'voided' ? 'danger' : 'warning' },
    statusLabel(row) {
      if (row.status === 'voided') return '已作废'
      if (row.status === 'draft') return '草稿'
      return Number(row.unallocated_amount) <= 0 ? '已全部核销' : Number(row.allocated_amount) > 0 ? '已部分核销' : '待核销'
    },
    paymentMethodName(row) { return row.payment_method_snapshot?.method_name || row.payment_method_snapshot?.name || row.payment_method || '—' },
    canAllocate(row) { return row.status === 'confirmed' && Number(row.unallocated_amount) > 0 && this.$can('finance.allocation.create') },
    canUseSource(row) {
      return !!this.sourceContext && !this.sourceError && this.canAllocate(row) &&
        row.party_type === this.sourceContext.partyType && Number(row.party_id) === Number(this.sourceContext.partyId) && row.currency === this.sourceContext.currency
    },
    allocationLabel(row) { return this.hasSourceReference ? '使用预付款' : this.canAllocate(row) ? '核销' : '核销记录' },
    async initializeFromRoute() {
      const revision = ++this.contextRevision
      this.loadRevision++
      this.allocationVisible = false
      this.allocationId = null
      this.purchaseLinksVisible = false
      this.purchaseLinksCashId = 0
      this.rows = []
      this.total = 0
      this.page = 1
      this.sourceContext = null
      this.sourceError = ''
      this.query = blankQuery()
      const routeQuery = this.$route.query
      this.query.allocation_status = ['pending', 'settled'].includes(routeQuery.allocation_status) ? routeQuery.allocation_status : ''
      this.partyFilter = {}
      if (['supplier', 'customer'].includes(routeQuery.party_type) && positiveId(routeQuery.party_id)) {
        this.partyFilter = { party_type: routeQuery.party_type, party_id: positiveId(routeQuery.party_id) }
      }
      if (this.hasSourceReference) {
        this.sourceLoading = true
        try {
          if (routeQuery.source_type !== 'purchase_settlement_source' || !positiveId(routeQuery.source_id) || this.direction !== 'payment') {
            throw new Error('应付来源无效，请返回应付管理重新选择。')
          }
          const response = await resolveFinanceSource({ type: routeQuery.source_type, id: positiveId(routeQuery.source_id) })
          if (revision !== this.contextRevision) return
          const source = response.data.data
          if (source.partyType !== 'supplier' || !source.partyId || !(Number(source.remainingAmount) > 0)) {
            throw new Error('这笔应付当前没有可结算余额，请返回应付管理刷新。')
          }
          this.sourceContext = source
          this.partyFilter = { party_type: source.partyType, party_id: source.partyId, currency: source.currency }
          this.query.allocation_status = 'pending'
        } catch (error) {
          if (revision !== this.contextRevision) return
          this.sourceError = error.userMessage || error.message || '应付来源加载失败，请重新选择。'
        } finally {
          if (revision === this.contextRevision) this.sourceLoading = false
        }
      } else {
        this.sourceLoading = false
      }
      if (revision === this.contextRevision) await this.load()
    },
    resetTableScroll() {
      this.$nextTick(() => {
        const body = this.$refs.cashTableCard?.querySelector('.el-table__body-wrapper')
        if (body) body.scrollLeft = 0
      })
    },
    async load() {
      const revision = ++this.loadRevision
      if (this.sourceLoading || this.sourceError) { this.rows = []; this.total = 0; this.loading = false; return }
      this.loading = true
      try {
        const { date_range, ...filters } = this.query
        const response = await listCashDocuments(this.direction, {
          ...filters, ...this.partyFilter,
          business_date_start: date_range?.[0] || '', business_date_end: date_range?.[1] || '',
          page: this.page, per_page: this.perPage,
        })
        if (revision !== this.loadRevision) return
        this.rows = response.data.data || []
        this.total = Number(response.data.total || 0)
        this.resetTableScroll()
      } catch (error) {
        if (revision !== this.loadRevision) return
        this.rows = []
        this.total = 0
        this.$message.error(error.userMessage || '列表加载失败')
      } finally { if (revision === this.loadRevision) this.loading = false }
    },
    search() { this.page = 1; return this.load() },
    reset() { this.query = blankQuery(); if (this.hasSourceReference) this.query.allocation_status = 'pending'; return this.search() },
    changePage(value) { this.page = value; return this.load() },
    changePageSize(value) { this.perPage = value; return this.search() },
    statusChanged() { if (this.query.status && this.query.status !== 'confirmed') this.query.allocation_status = ''; return this.search() },
    allocationStatusChanged() { if (this.query.allocation_status) this.query.status = 'confirmed'; return this.search() },
    create() { this.$router.push({ path: this.basePath + '/create', query: this.sourceContext ? { source_type: this.sourceContext.type, source_id: this.sourceContext.id } : {} }) },
    async view(row) {
      this.detailDoc = {
        ...row,
        allocations: row.allocations || [],
        purchase_order_allocations: row.purchase_order_allocations || [],
        attachments: row.attachments || [],
        logs: row.logs || [],
      }
      this.detailDialogVisible = true
      this.detailLoading = true
      try {
        const fetchFn = typeof getCashDocument === 'function' ? getCashDocument : null
        if (fetchFn && row.id) {
          const res = await fetchFn(row.id)
          const data = res?.data?.data || res?.data
          if (data) {
            this.detailDoc = {
              ...data,
              allocations: data.allocations || [],
              purchase_order_allocations: data.purchase_order_allocations || [],
              attachments: data.attachments || [],
              logs: data.logs || [],
            }
          }
        }
      } catch (error) {
        console.error('加载单据详情失败:', error)
      } finally {
        this.detailLoading = false
      }
    },
    editDraftFromDetail() {
      const id = this.detailDoc?.id
      this.detailDialogVisible = false
      if (id) this.$router.push(this.basePath + '/' + id)
    },
    confirmFromDetail() {
      const row = this.detailDoc
      this.detailDialogVisible = false
      if (row) this.confirm(row)
    },
    openAllocationFromDetail() {
      const row = this.detailDoc
      this.detailDialogVisible = false
      if (row) this.openAllocation(row)
    },
    openPurchaseLinksFromDetail() {
      const row = this.detailDoc
      this.detailDialogVisible = false
      if (row) this.openPurchaseLinks(row)
    },
    voidFromDetail() {
      const row = this.detailDoc
      this.detailDialogVisible = false
      if (row) this.voidDoc(row)
    },
    feeTypeLabel(type) {
      return { platform: '平台手续费', bank: '银行手续费', other: '其他费用' }[type] || '无'
    },
    formatDateTime(value) {
      if (!value) return '—'
      const d = new Date(value)
      if (isNaN(d.getTime())) return String(value)
      const pad = n => String(n).padStart(2, '0')
      return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`
    },
    fileSize(value) {
      const n = Number(value || 0)
      if (n <= 0) return '0 B'
      return n > 1048576 ? (n / 1048576).toFixed(2) + ' MB' : (n / 1024).toFixed(1) + ' KB'
    },
    sourceLabel(value) {
      return {
        sales_order: '销售订单',
        sales_order_refund: '销售订单退款',
        purchase_receipt: '采购到货',
        purchase_settlement_source: '采购结算来源',
        purchase_return_ap_offset: '采购退货冲应付',
        purchase_return_supplier_refund: '供应商退款',
      }[value] || value || '—'
    },
    logLabel(value) {
      return {
        create: '创建',
        update_draft: '修改草稿',
        confirm: '确认',
        allocate: '核销',
        reverse_allocation: '撤销核销',
        freeze_purchase_order_purposes: '确认采购用途',
        revise_purchase_order_purposes: '调整采购用途',
        void: '作废',
      }[value] || value || '—'
    },
    purchaseOrderSummary(row) {
      return [...new Set((row.purchase_order_allocations || []).map(link => link.purchase_order_no || `#${link.purchase_order_id}`))].join('、') || '—'
    },
    openPurchaseLinks(row) {
      if (row.party_type !== 'supplier' || row.status === 'draft') return
      this.purchaseLinksCashId = Number(row.id)
      this.purchaseLinksVisible = true
    },
    openAllocation(row) {
      if (this.hasSourceReference && !this.canUseSource(row)) return this.$message.warning('请选用同一供应商、同币种且有未核销余额的付款。')
      this.allocationId = row.id
      this.allocationVisible = true
    },
    allocationChanged() {
      if (this.sourceContext) { this.allocationVisible = false; return this.returnToPayables() }
      return this.load()
    },
    returnToPayables() {
      const sourceId = this.sourceContext?.id || positiveId(this.$route.query.source_id)
      this.$router.push({ path: '/finance/payables', query: { view: 'documents', ...(sourceId ? { source_id: sourceId } : {}) } })
    },
    async confirm(row) {
      // 草稿清单只表示待核销意向，列表不能跳过逐项核对直接把它当成预付款确认。
      if (row.draft_allocation_items?.length || row.purchase_order_allocations?.length) {
        return this.$router.push(this.basePath + '/' + row.id)
      }
      try {
        await this.$confirm(`确认${this.title}单 ${row.document_no}：${row.party_name_snapshot || ''} ${Number(row.amount || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${row.currency || ''}。确认后金额不可编辑，是否继续？`, '确认资金事实', { type: 'warning' })
        await confirmCashDocument(row.id)
        this.$message.success('已确认')
        await this.load()
      } catch (error) { if (!['cancel', 'close'].includes(error)) this.$message.error(error.userMessage || '确认失败') }
    },
    async voidDoc(row) {
      try {
        const { value } = await this.$prompt('作废后保留历史记录，请填写原因', '作废确认', { inputValidator: value => !!String(value || '').trim() || '原因必填' })
        await voidCashDocument(row.id, value)
        this.$message.success('已作废')
        await this.load()
      } catch (error) { if (!['cancel', 'close'].includes(error)) this.$message.error(error.userMessage || '作废失败') }
    },
  },
}
</script>

<style scoped>
/* 页面容器规范：完全对齐主数据中心页面结构 */
.cash-list-container {
  padding: 16px 20px;
  background: #f8fafc;
  min-height: calc(100vh - 90px);
  box-sizing: border-box;
}

/* 页面全局头部：完全对齐主数据中心规范 */
.page-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 16px 20px;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  margin-bottom: 14px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  box-sizing: border-box;
}

.head-left {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}

.head-icon {
  width: 38px;
  height: 38px;
  border-radius: 8px;
  background: #f0fdf4;
  color: #008b4b;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  flex-shrink: 0;
}

.head-title-wrap {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.title-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.page-title {
  margin: 0;
  font-size: 18px;
  font-weight: 700;
  color: #0f172a;
  letter-spacing: -0.01em;
}

.head-tag {
  border-radius: 4px;
  font-weight: 500;
}

.source-tag {
  border-radius: 4px;
  font-weight: 500;
}

.head-actions {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-shrink: 0;
}

.btn-refresh,
.btn-stat {
  border-color: #cbd5e1 !important;
  color: #475569 !important;
}

.btn-refresh:hover,
.btn-stat:hover {
  border-color: #008b4b !important;
  color: #008b4b !important;
}

.btn-theme-create {
  background: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
  font-weight: 500;
}

.btn-theme-create:hover {
  background: #00763f !important;
  border-color: #00763f !important;
}

/* 统一页面提示条 */
.erp-page-tip {
  margin-bottom: 14px;
}

/* 来源冲抵上下文提示卡片 */
.source-context-card {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
  padding: 12px 16px;
  margin-bottom: 14px;
  background: #eff6ff;
  border: 1px solid #bfdbfe;
  border-radius: 8px;
  box-sizing: border-box;
}

.source-context-left {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
  flex: 1;
}

.source-badge-icon {
  width: 34px;
  height: 34px;
  border-radius: 6px;
  background: #dbeafe;
  color: #2563eb;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  flex-shrink: 0;
}

.source-info {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.source-title-line {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.source-title-line strong {
  font-size: 14px;
  color: #1e3a8a;
  font-weight: 700;
}

.source-remaining-pill {
  font-size: 12px;
  background: #2563eb;
  color: #ffffff;
  padding: 1px 7px;
  border-radius: 4px;
  font-weight: 600;
}

.source-party-text {
  display: block;
  font-size: 12px;
  color: #475569;
  margin-top: 3px;
}

.source-error-text {
  display: block;
  font-size: 12px;
  color: #dc2626;
  margin-top: 3px;
  font-weight: 600;
}

.source-actions {
  flex-shrink: 0;
}

.btn-return-payables {
  border-color: #93c5fd !important;
  color: #1d4ed8 !important;
  background: #ffffff !important;
}

.btn-return-payables:hover {
  border-color: #2563eb !important;
  color: #1e40af !important;
}

/* 筛选工具栏与主卡片容器 (主数据中心规范) */
.table-container-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
  overflow: hidden;
  box-sizing: border-box;
}

.filter-toolbar {
  padding: 14px 18px;
  background: #fafbfc;
  border-bottom: 1px solid #e2e8f0;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.filter-fields {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  flex: 1;
  min-width: 0;
}

.filter-item {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}

.filter-label {
  font-size: 12px;
  color: #64748b;
  font-weight: 500;
  white-space: nowrap;
}

.filter-item .el-input {
  width: 155px;
}

.filter-item .el-select {
  width: 125px;
}

.date-filter-item .el-date-editor {
  width: 235px;
}

.filter-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
}

.btn-theme-search {
  background: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
  font-weight: 500;
}

.btn-theme-search:hover {
  background: #00763f !important;
  border-color: #00763f !important;
}

.btn-theme-reset {
  border-color: #cbd5e1 !important;
  color: #475569 !important;
}

.btn-theme-reset:hover {
  border-color: #008b4b !important;
  color: #008b4b !important;
}

/* 主表格区域 */
.table-wrap {
  width: 100%;
  overflow-x: auto;
  min-width: 0;
}

.enterprise-table >>> th {
  background: #f8fafc;
  color: #334155;
  font-weight: 600;
  font-size: 12px;
  padding: 10px 0;
}

.enterprise-table >>> td {
  padding: 9px 0;
  font-size: 13px;
  color: #1e293b;
}

.font-tabular {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
  font-variant-numeric: tabular-nums;
}

.doc-code-link {
  color: #008b4b;
  font-weight: 600;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.doc-code-link:hover {
  text-decoration: underline;
  color: #00763f;
}

.date-text {
  color: #475569;
  font-size: 12.5px;
}

.party-name-text {
  font-weight: 500;
  color: #0f172a;
}

.po-summary-text {
  color: #475569;
  font-size: 12px;
}

.account-name-badge {
  color: #334155;
  font-size: 12.5px;
}

.currency-cell-tag {
  border-radius: 4px;
  font-weight: 600;
}

.money-text {
  color: #0f172a;
  font-size: 13px;
}

.allocated-text {
  color: #64748b;
  font-size: 12.5px;
}

.unallocated-active {
  color: #d97706;
}

.unallocated-zero {
  color: #94a3b8;
}

.method-tag {
  display: inline-block;
  padding: 1px 6px;
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  font-size: 11px;
  color: #475569;
}

.status-cell-tag {
  border-radius: 4px;
}

.operator-text {
  color: #64748b;
  font-size: 12px;
}

/* 操作列排版：规整、对齐、无拥挤 */
.row-actions {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  white-space: nowrap;
}

.row-actions .el-button--text {
  padding: 3px 5px !important;
  font-size: 12.5px !important;
  font-weight: 500;
  margin-left: 0 !important;
}

.btn-action-view { color: #008b4b !important; }
.btn-action-view:hover { color: #00763f !important; }

.btn-action-confirm { color: #059669 !important; }
.btn-action-confirm:hover { color: #047857 !important; }

.btn-action-alloc { color: #2563eb !important; }
.btn-action-alloc:hover { color: #1d4ed8 !important; }

.btn-action-use-prepay { color: #d97706 !important; font-weight: 600 !important; }
.btn-action-use-prepay:hover { color: #b45309 !important; }

.btn-action-link { color: #0d9488 !important; }
.btn-action-link:hover { color: #0f766e !important; }

.btn-action-void.danger { color: #dc2626 !important; }
.btn-action-void.danger:hover { color: #b91c1c !important; }

/* 分页行 */
.table-pagination-row {
  padding: 12px 18px;
  background: #fafbfc;
  border-top: 1px solid #e2e8f0;
  display: flex;
  justify-content: flex-end;
}

/* 核销弹窗微调 */
.cash-list-allocation-dialog >>> .el-dialog {
  max-width: calc(100vw - 32px);
  margin-bottom: 5vh;
  border-radius: 8px;
  overflow: hidden;
}

.cash-list-allocation-dialog >>> .el-dialog__header {
  padding: 16px 20px;
  border-bottom: 1px solid #f1f5f9;
  background: #fafbfc;
}

.cash-list-allocation-dialog >>> .el-dialog__title {
  font-size: 16px;
  font-weight: 700;
  color: #0f172a;
}

.cash-list-allocation-dialog >>> .el-dialog__body {
  max-height: 72vh;
  overflow-y: auto;
  padding: 16px 20px;
}

/* 响应式自适应断点（遵照 2026-09-08 规则） */
@media (max-width: 1200px) {
  .filter-fields {
    gap: 10px;
  }
}

@media (max-width: 900px) {
  .filter-toolbar {
    flex-direction: column;
    align-items: stretch;
  }
  .filter-fields {
    width: 100%;
  }
  .filter-actions {
    justify-content: flex-end;
    width: 100%;
  }
  .source-context-card {
    flex-direction: column;
    align-items: stretch;
  }
  .source-actions {
    display: flex;
    justify-content: flex-end;
  }
}

@media (max-width: 768px) {
  .cash-list-container {
    padding: 12px;
  }
  .page-head {
    flex-direction: column;
    align-items: flex-start;
    gap: 12px;
    padding: 12px 14px;
  }
  .head-actions {
    width: 100%;
    justify-content: flex-end;
    flex-wrap: wrap;
  }
  .cash-list-allocation-dialog >>> .el-dialog__body {
    padding: 10px;
  }
}

@media (max-width: 520px) {
  .filter-fields {
    flex-direction: column;
    align-items: stretch;
    gap: 10px;
  }
  .filter-item {
    width: 100%;
    justify-content: space-between;
  }
  .filter-item .el-input,
  .filter-item .el-select,
  .date-filter-item .el-date-editor {
    flex: 1;
    width: 100% !important;
  }
  .filter-actions .el-button {
    flex: 1;
  }
  .table-pagination-row {
    justify-content: center;
  }
  .table-pagination-row >>> .el-pagination {
    white-space: normal;
    text-align: center;
  }
}

/* 详情居中弹窗样式 (完全对齐设计规范) */
::v-deep .cash-detail-dialog {
  border-radius: 8px;
  overflow: hidden;
  box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
}

::v-deep .cash-detail-dialog .el-dialog__header {
  padding: 16px 20px;
  border-bottom: 1px solid #f1f5f9;
}

::v-deep .cash-detail-dialog .el-dialog__title {
  font-size: 16px;
  font-weight: 700;
  color: #0f172a;
}

::v-deep .cash-detail-dialog .el-dialog__body {
  padding: 18px 20px;
  background: #f8fafc;
  max-height: 74vh;
  overflow-y: auto;
}

.modal-summary-banner {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 14px 16px;
  display: flex;
  align-items: center;
  gap: 14px;
  margin-bottom: 14px;
}

.banner-icon {
  width: 44px;
  height: 44px;
  border-radius: 10px;
  background: #eaf7ef;
  color: #008b4b;
  display: grid;
  place-items: center;
  font-size: 22px;
  flex-shrink: 0;
}

.banner-main {
  flex: 1;
  min-width: 0;
}

.banner-title-line {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 4px;
  flex-wrap: wrap;
}

.banner-title-line h2 {
  margin: 0;
  font-size: 16px;
  font-weight: 700;
  color: #0f172a;
}

.banner-sub-meta {
  display: flex;
  align-items: center;
  gap: 16px;
  font-size: 12px;
  color: #64748b;
  flex-wrap: wrap;
}

.banner-sub-meta strong {
  color: #1e293b;
}

.detail-kpi-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 10px;
  margin-bottom: 14px;
}

.kpi-mini-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 10px 12px;
  display: flex;
  flex-direction: column;
}

.kpi-mini-card.is-primary {
  border-color: #bbf7d0;
  background: #f0fdf4;
}

.kpi-mini-card.is-primary .kpi-val {
  color: #008b4b;
}

.kpi-mini-card.is-warning {
  border-color: #fde68a;
  background: #fffbeb;
}

.kpi-label {
  font-size: 12px;
  color: #64748b;
}

.kpi-val {
  font-size: 16px;
  font-weight: 700;
  color: #0f172a;
  margin-top: 2px;
}

.kpi-currency {
  font-size: 12px;
  font-weight: 500;
  color: #64748b;
  margin-left: 2px;
}

.detail-cards-stack {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.detail-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 14px 16px;
}

.card-title-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
  font-size: 13px;
  font-weight: 600;
  color: #1e293b;
}

.card-title-bar i {
  color: #008b4b;
}

.card-title-tag {
  margin-left: auto;
}

.kv-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 12px 16px;
}

.kv-item {
  display: flex;
  flex-direction: column;
  gap: 3px;
  min-width: 0;
}

.kv-item.full-width-kv {
  grid-column: 1 / -1;
}

.kv-label {
  font-size: 12px;
  color: #64748b;
}

.kv-val {
  font-size: 13px;
  color: #1e293b;
  overflow-wrap: anywhere;
}

.remark-text {
  color: #475569;
  line-height: 1.5;
}

.empty-section-tip {
  padding: 14px 16px;
  background: #f8fafc;
  border-radius: 6px;
  color: #94a3b8;
  font-size: 12px;
  text-align: center;
}

.purpose-reason-bar {
  margin-top: 10px;
  padding: 8px 12px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 6px;
  font-size: 12px;
  color: #166534;
  display: flex;
  align-items: center;
  gap: 6px;
}

.btn-detail-edit {
  background: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
}

.btn-detail-edit:hover {
  background: #00763f !important;
  border-color: #00763f !important;
}

.btn-detail-confirm {
  background: #10b981 !important;
  border-color: #10b981 !important;
  color: #ffffff !important;
}

.btn-detail-alloc {
  color: #008b4b !important;
  border-color: #bbf7d0 !important;
}

.btn-detail-alloc:hover {
  background: #eaf7ef !important;
}

.btn-detail-prepay {
  background: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
}

.btn-detail-link {
  color: #0284c7 !important;
  border-color: #bae6fd !important;
}

.btn-detail-link:hover {
  background: #f0f9ff !important;
}

@media (max-width: 768px) {
  .detail-kpi-grid {
    grid-template-columns: repeat(2, 1fr);
  }
  .kv-grid {
    grid-template-columns: 1fr;
  }
  ::v-deep .cash-detail-dialog {
    width: 95vw !important;
    margin: 0 auto;
  }
  ::v-deep .cash-detail-dialog .el-dialog__body {
    padding: 12px 14px;
  }
}

@media (max-width: 520px) {
  .detail-kpi-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 360px) {
  .cash-list-container {
    padding: 8px 6px;
  }
}
</style>
