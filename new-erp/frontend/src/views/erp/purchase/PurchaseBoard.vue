<template>
  <section class="purchase-page">
    <div class="purchase-main">
      <div class="page-head">
        <div class="head-left">
          <div class="title-row">
            <h1>{{ meta.title }}</h1>
            <el-tag size="mini" type="success">{{ meta.short }}管理</el-tag>
          </div>
          <p class="subtitle">{{ meta.subtitle }}</p>
        </div>
        <div class="head-actions">
          <el-button v-if="mode==='orders' && $can(['purchase.order.generate', 'purchase.order.create', 'purchase.order'])" size="small" type="success" icon="el-icon-download" @click="$router.push('/purchase/plans')">从计划生成订单</el-button>
          <el-button v-if="mode==='receipts' && $can(['purchase.receipt.create', 'purchase.receipt'])" size="small" type="success" icon="el-icon-truck" @click="$router.push('/purchase/orders')">从采购订单生成到货单</el-button>
          <el-button v-if="$can([`purchase.${mode.replace(/s$/, '')}.create`, `purchase.${mode.replace(/s$/, '')}`])" size="small" type="success" icon="el-icon-plus" @click="openEditor()">新增{{ meta.short }}</el-button>
          <el-button v-if="mode==='requests' && $can(['purchase.request.view', 'purchase.request'])" size="small" icon="el-icon-delete" @click="openDeletedDialog">已删除需求</el-button>
          <el-button v-if="mode==='receipts' && $can(['purchase.quality.view', 'purchase.defect.view', 'purchase.receipt'])" size="small" icon="el-icon-warning-outline" @click="$router.push('/purchase/defects')">不合格品处理</el-button>
          <el-button size="small" plain icon="el-icon-refresh" @click="load">刷新</el-button>
        </div>
      </div>

      <!-- 全局统一页面提示条 (对齐主数据中心规范) -->
      <div class="erp-page-tip">
        <i class="el-icon-info" />
        <span>{{ meta.tip }}</span>
      </div>

      <div class="filter-card">
        <div class="filter-inputs">
          <el-select v-model="filters.management_scope" size="small" clearable placeholder="全部管理类型" @change="changeScopeFilter"><el-option v-for="scope in scopeOptions" :key="scope.value" :label="scope.label" :value="scope.value" /></el-select>
          <el-input v-model="filters.keyword" size="small" :placeholder="`请输入${meta.short}单号 / 物料 / 供应商`" clearable @keyup.enter.native="load" prefix-icon="el-icon-search" />
          <el-select v-model="filters.status" size="small" clearable placeholder="请选择状态">
            <el-option v-for="s in meta.statuses" :key="s.value" :label="s.label" :value="s.value" />
          </el-select>
          <el-date-picker v-model="filters.dateRange" size="small" type="daterange" value-format="yyyy-MM-dd" start-placeholder="开始日期" end-placeholder="结束日期" />
        </div>
        <div class="filter-actions">
          <el-button size="small" icon="el-icon-refresh" @click="reset">重置</el-button>
          <el-button size="small" type="success" icon="el-icon-search" @click="load">查询</el-button>
        </div>
      </div>

      <div class="stat-row">
        <div v-for="card in statCards" :key="card.label" class="stat-card">
          <div class="stat-icon-wrapper">
            <i :class="card.icon" />
          </div>
          <div class="stat-info">
            <span class="stat-label">{{ card.label }}</span>
            <strong class="stat-val">{{ card.value }}</strong>
            <small class="stat-sub">{{ card.sub }}</small>
          </div>
        </div>
      </div>

      <div class="table-panel purchase-table">
        <el-table :data="rows" size="mini" highlight-current-row @row-click="selectRow" :row-class-name="rowClass" border stripe>
          <el-table-column label="管理类型" width="110"><template slot-scope="{row}"><el-tag size="mini" :type="row.management_scope === 'office' ? 'info' : row.management_scope === 'factory' ? 'success' : 'warning'">{{ scopeLabel(row.management_scope) }}</el-tag></template></el-table-column>
          <el-table-column v-for="col in columns" :key="`${col.prop}-${col.label}`" :label="col.label" :min-width="col.width || 90" show-overflow-tooltip>
            <template slot-scope="{row}">
              <el-tag v-if="col.tag" size="mini" :type="tagType(valueOf(row, col.prop), col.prop)">{{ labelOf(valueOf(row, col.prop), col.prop) }}</el-tag>
              <div v-else-if="col.prop === 'allocation_summary'">
                <el-tag size="mini" :type="planAllocationStatusTag(row)" effect="plain">
                  <i :class="planAllocationStatusIcon(row)" />
                  {{ planAllocationSummary(row) }}
                </el-tag>
              </div>
              <span v-else>{{ displayValue(row, col) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="操作" :width="mode==='requests' ? 400 : 310" fixed="right">
            <template slot-scope="{row}">
              <el-button v-if="$can([`purchase.${mode.replace(/s$/, '')}.view`, `purchase.${mode.replace(/s$/, '')}`])" class="action-link-theme" type="text" size="mini" icon="el-icon-view" @click.stop="openDetail(row)">详情</el-button>
              <el-button v-if="canEdit(row) && $can([`purchase.${mode.replace(/s$/, '')}.edit`, `purchase.${mode.replace(/s$/, '')}`])" class="action-link-theme" type="text" size="mini" icon="el-icon-edit" @click.stop="openEditor(row)">编辑</el-button>
              <el-button v-for="action in visibleRowActions(row)" :key="action.command" :class="actionBtnClass(action.command)" type="text" size="mini" :icon="actionIcon(action.command)" @click.stop="runAction(action.command,row)">{{ action.label }}</el-button>
              <el-button v-if="canDelete(row) && $can([`purchase.${mode.replace(/s$/, '')}.delete`, `purchase.${mode.replace(/s$/, '')}`])" class="danger-link" type="text" size="mini" icon="el-icon-delete" @click.stop="deleteDraft(row)">{{ mode==='requests' ? '软删除' : '删除' }}</el-button>
            </template>
          </el-table-column>
        </el-table>
        <el-pagination background :current-page="page" :page-size="perPage" :total="total" layout="total, prev, pager, next, sizes" @current-change="p => {page=p;load()}" @size-change="s => {perPage=s;load()}" />
      </div>
    </div>

    <!-- 详情居中弹窗：根据 2026-09-24 规则替代贴边侧页，四周留边按视口自适应，长内容内部滚动 -->
    <el-dialog
      :visible="!!selected"
      @close="selected=null"
      width="1180px"
      top="5vh"
      append-to-body
      custom-class="purchase-detail-dialog"
      :close-on-click-modal="false"
    >
      <div slot="title" class="dialog-header-custom" v-if="selected">
        <div class="dialog-title-left">
          <i class="el-icon-document-copy header-icon"></i>
          <span class="dialog-main-title">{{ detailNo(selected) }}</span>
          <span class="dialog-sub-title">{{ meta.short }}详情</span>
          <el-tag size="mini" :type="selected.management_scope === 'office' ? 'info' : 'success'">{{ scopeLabel(selected.management_scope) }}</el-tag>
          <el-tag size="mini" :type="tagType(mainStatus(selected))">{{ labelOf(mainStatus(selected)) }}</el-tag>
          <el-tag v-if="selected.deleted_at" size="mini" type="info">已删除</el-tag>
          <el-tag v-if="mode==='orders' && selected.audit_status" size="mini" :type="tagType(selected.audit_status)">{{ labelOf(selected.audit_status, 'audit_status') }}</el-tag>
        </div>
      </div>

      <div v-if="selected" class="detail-dialog-body">
        <el-alert v-if="scopeIssue(selected)" :title="scopeIssue(selected)" type="warning" :closable="false" show-icon />
        <div v-if="selected.deleted_at" class="erp-page-tip" style="margin-bottom: 14px;">
          <i class="el-icon-info" />
          <span>该需求已软删除（删除时间：{{ timeText(selected.deleted_at) }}，删除人：{{ selected.deleted_by || '-' }}）。保留原单据及明细仅供追溯查阅。</span>
        </div>
        <section class="dialog-section">
          <div class="section-title-bar">
            <span class="bar-accent"></span>
            <h4>基础信息</h4>
          </div>
          <div class="spec-grid">
            <div class="spec-item"><span class="spec-label">单据状态</span><span class="spec-value"><el-tag size="mini" :type="tagType(mainStatus(selected))">{{ labelOf(mainStatus(selected)) }}</el-tag></span></div>
            <div class="spec-item" v-if="['plans', 'orders'].includes(mode)"><span class="spec-label">审核状态</span><span class="spec-value"><el-tag size="mini" :type="tagType(selected.audit_status)">{{ labelOf(selected.audit_status, 'audit_status') }}</el-tag></span></div>
            <div class="spec-item" v-if="mode==='plans'"><span class="spec-label">订单生成</span><span class="spec-value"><el-tag size="mini" :type="tagType(selected.order_status)">{{ labelOf(selected.order_status) }}</el-tag></span></div>
            <div class="spec-item" v-if="mode==='orders'"><span class="spec-label">到货状态</span><span class="spec-value"><el-tag size="mini" :type="tagType(selected.receipt_status)">{{ labelOf(selected.receipt_status) }}</el-tag></span></div>
            <div class="spec-item"><span class="spec-label">物料/供应商</span><strong class="spec-value">{{ selectedTitle(selected) }}</strong></div>
            <div class="spec-item"><span class="spec-label">单据日期</span><span class="spec-value">{{ selected.required_date || selected.request_date || selected.plan_date || selected.order_date || selected.receipt_date || '--' }}</span></div>
            <div class="spec-item" v-if="mode==='plans'"><span class="spec-label">计划总量</span><strong class="spec-value highlight-qty">{{ quantityByUnit(selected.items || [], 'purchase_quantity', false) }}</strong></div>
            <div class="spec-item" v-if="mode==='plans'"><span class="spec-label">预计总额</span><strong class="spec-value grand-total">¥{{ money(selected.total_amount) }}</strong></div>
            <div class="spec-item" v-if="mode==='orders'"><span class="spec-label">预计到货</span><span class="spec-value">{{ selected.expected_arrival_date || '--' }}</span></div>
            <div class="spec-item"><span class="spec-label">单据来源</span><span class="spec-value">{{ sourceText(selected.source_type || selected.data_source) }}</span></div>
            <div class="spec-item" v-if="selected.source_no"><span class="spec-label">来源单号</span><span class="spec-value">{{ selected.source_no }}</span></div>
            <div class="spec-item spec-full"><span class="spec-label">备注说明</span><span class="spec-value text-muted">{{ selected.remark || '无备注' }}</span></div>
          </div>
        </section>

        <section v-if="mode==='orders'" class="dialog-section">
          <div class="section-title-bar">
            <span class="bar-accent"></span>
            <h4>供应商与商务条件</h4>
          </div>
          <div class="spec-grid">
            <div class="spec-item"><span class="spec-label">供应商</span><strong class="spec-value">{{ selected.supplier ? selected.supplier.supplier_name : '--' }}</strong></div>
            <div class="spec-item"><span class="spec-label">供应商编码</span><span class="spec-value">{{ selected.supplier ? selected.supplier.supplier_code : '--' }}</span></div>
            <div class="spec-item"><span class="spec-label">联系人</span><span class="spec-value">{{ supplierContact(selected.supplier) }}</span></div>
            <div class="spec-item"><span class="spec-label">联系方式</span><span class="spec-value">{{ supplierPhone(selected.supplier) }}</span></div>
            <div class="spec-item"><span class="spec-label">结算方式</span><span class="spec-value">{{ selected.settlement_method || '--' }}</span></div>
            <div class="spec-item"><span class="spec-label">交付方式</span><span class="spec-value">{{ selected.delivery_method || '--' }}</span></div>
            <div class="spec-item"><span class="spec-label">币种</span><span class="spec-value">{{ selected.currency || 'CNY' }}</span></div>
            <div class="spec-item"><span class="spec-label">税率口径</span><span class="spec-value">{{ selected.tax_mode === 'tax_excluded' ? '未税' : '含税' }}</span></div>
          </div>
        </section>

        <section class="dialog-section">
          <div class="section-title-bar">
            <span class="bar-accent"></span>
            <h4>{{ mode==='requests' ? '需求物料明细' : '明细信息' }} (共 {{ detailLines(selected).length }} 行)</h4>
          </div>
          <el-table :data="detailLines(selected)" size="mini" border stripe class="detail-dialog-table">
            <el-table-column type="index" label="#" width="40" align="center" />
            <el-table-column prop="item.item_code" label="物料编码" width="115">
              <template slot-scope="{row}">
                <span class="code-badge">{{ row.item ? row.item.item_code : (row.item_code || '--') }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="item.item_name" label="物料名称" min-width="130" show-overflow-tooltip>
              <template slot-scope="{row}">{{ row.item ? row.item.item_name : (row.item_name || '--') }}</template>
            </el-table-column>
            <el-table-column v-if="['requests', 'plans'].includes(mode)" label="规格型号" min-width="130" show-overflow-tooltip>
              <template slot-scope="{row}">{{ lineSpec(row) }}</template>
            </el-table-column>
            <el-table-column label="采购数量" width="95" align="right">
              <template slot-scope="{row}"><strong class="highlight-qty">{{ row.purchase_conversion_snapshot?.purchase_qty ?? lineQty(row) }}</strong> {{ row.purchase_conversion_snapshot?.purchase_unit_name_snapshot || lineUnit(row) }}</template>
            </el-table-column>
            <el-table-column v-if="['requests', 'plans'].includes(mode)" label="折合库存" width="105" align="right">
              <template slot-scope="{row}">
                <span>{{ lineStockQty(row) }} {{ lineStockUnit(row) }}</span>
                <el-tooltip v-if="isLineConverted(row)" :content="lineConversionTip(row)" placement="top">
                  <i class="el-icon-info" style="color: #008b4b; margin-left: 2px; cursor: pointer;" />
                </el-tooltip>
              </template>
            </el-table-column>
            <el-table-column v-if="mode==='plans'" label="供应商拆分及报价" min-width="260">
              <template slot-scope="{row}">
                <div v-if="hasItemAllocatedSupplier(row)" class="dialog-splits-container">
                  <div v-for="split in validItemSplits(row)" :key="split.id" class="dialog-split-chip">
                    <span class="supplier-name-bold">{{ split.supplier ? split.supplier.supplier_name : (split.supplier_id ? supplierName(split.supplier_id) : '-') }}</span>
                    <span class="split-amount-pill">
                      {{ number(split.purchase_conversion_snapshot?.purchase_qty ?? split.purchase_qty) }} {{ split.purchase_conversion_snapshot?.purchase_unit_name_snapshot || lineUnit(row) }} ·
                      ¥{{ money(split.purchase_conversion_snapshot?.purchase_unit_price ?? split.unit_price) }}
                    </span>
                    <el-tag v-if="split.order" size="mini" type="success">{{ split.order.purchase_order_no }}</el-tag>
                    <el-tag v-else size="mini" type="info">未生成订单</el-tag>
                  </div>
                </div>
                <el-tag v-else size="mini" type="danger" effect="plain"><i class="el-icon-warning" /> 未分配供应商</el-tag>
              </template>
            </el-table-column>
            <el-table-column v-if="mode==='plans'" label="来源需求" width="130" show-overflow-tooltip>
              <template slot-scope="{row}">
                <span v-if="row.request" class="code-badge-subtle">{{ row.request.request_no }}</span>
                <span v-else class="text-muted">--</span>
              </template>
            </el-table-column>
            <el-table-column v-if="mode==='requests'" prop="expected_date" label="期望到货" width="100" align="center">
              <template slot-scope="{row}">{{ row.expected_date || '--' }}</template>
            </el-table-column>
            <el-table-column v-if="mode==='requests'" label="目标仓库" width="105">
              <template slot-scope="{row}">{{ row.warehouse ? row.warehouse.warehouse_name : '未指定' }}</template>
            </el-table-column>
            <el-table-column v-if="mode==='requests'" label="优先级" width="70" align="center">
              <template slot-scope="{row}">
                <el-tag size="mini" :type="row.priority === 'high' ? 'danger' : row.priority === 'low' ? 'info' : 'warning'">
                  {{ row.priority === 'high' ? '高' : row.priority === 'low' ? '低' : '中' }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column v-if="mode==='requests'" label="状态" width="85" align="center">
              <template slot-scope="{row}">
                <el-tag size="mini" :type="row.line_status === 'planned' ? 'success' : (Number(row.converted_qty || row.planned_qty || 0) > 0 ? 'warning' : 'info')">
                  {{ row.line_status === 'planned' ? '已转计划' : (Number(row.converted_qty || row.planned_qty || 0) > 0 ? '部分转' : '待转计划') }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column prop="remark" label="行备注" min-width="90" show-overflow-tooltip />
          </el-table>

          <!-- 订单模式下的明细扩展快照 -->
          <div v-if="mode==='orders'" class="order-lines-snapshot-list">
            <div v-for="line in detailLines(selected)" :key="line.id || line.item_id" class="detail-line-card">
              <div class="line-card-header">
                <b>{{ line.item ? line.item.item_code : '--' }} · {{ line.item ? line.item.item_name : '--' }}</b>
                <span class="line-card-qty">{{ lineQty(line) }} {{ lineUnit(line) }}</span>
              </div>
              <div class="unit-snapshot-grid">
                <label>采购数量<strong>{{ number(line.purchase_qty || line.order_qty) }}</strong></label>
                <label>采购单位<strong>{{ line.purchase_unit_name_snapshot || lineUnit(line) }}</strong></label>
                <label>换算因子<strong>{{ number(line.conversion_factor_snapshot) }}</strong></label>
                <label>计划基本数量<strong>{{ number(line.planned_base_qty) }} {{ line.base_unit_name_snapshot || '-' }}</strong></label>
                <label v-if="line.purchase_conversion_snapshot">原需求 / 多采购<strong>{{ number(line.purchase_conversion_snapshot.required_base_qty) }} / {{ number(line.purchase_conversion_snapshot.excess_base_qty) }} {{ line.base_unit_name_snapshot }}</strong></label>
                <label>采购单价<strong>¥{{ money(line.purchase_unit_price || line.unit_price) }}/{{ line.purchase_unit_name_snapshot || lineUnit(line) }}</strong></label>
                <label>基本单价<strong>¥{{ money(line.base_unit_price) }}/{{ line.base_unit_name_snapshot || '-' }}</strong></label>
                <label>税率<strong>{{ number(line.tax_rate) }}%</strong></label>
                <label>行金额<strong>¥{{ money(line.amount) }}</strong></label>
                <label>预计到货<strong>{{ line.expected_arrival_date || selected.expected_arrival_date || '-' }}</strong></label>
                <label>已到/未到<strong>{{ number(line.received_qty) }} / {{ number(line.remaining_qty) }}</strong></label>
              </div>
            </div>
          </div>
          <!-- 到货模式下的明细扩展 -->
          <div v-if="mode==='receipts'" class="receipt-lines-extra-list">
            <div v-for="line in detailLines(selected)" :key="line.id || line.item_id" class="receipt-line-summary">
              <b>{{ line.item ? line.item.item_code : '--' }} · {{ line.item ? line.item.item_name : '--' }}</b>
              <small>合格 {{ line.qualified_qty || 0 }} / 不合格 {{ line.unqualified_qty || 0 }} / 质量待处理 {{ receiptUnresolvedQty(line) }} / 处理方式：{{ defectHandlingText(line) }}</small>
              <div v-if="(line.allocations || []).length" class="receipt-location-trace">
                <span>{{ receiptAllocationSummary(line) }}</span>
                <el-button type="text" size="mini" @click.stop="openReceiptAllocationTrace(line)">查看库位与编号</el-button>
              </div>
            </div>
          </div>
        </section>

        <!-- 订单金额与财务结算 -->
        <section v-if="mode==='orders'" class="dialog-section">
          <div class="section-title-bar"><span class="bar-accent"></span><h4>金额与财务结算</h4><el-button v-if="$can('purchase.order.view') || $can('finance.view')" type="text" icon="el-icon-date" @click="openPaymentPlan(selected)">付款安排</el-button></div>
          <div class="spec-grid">
            <div class="spec-item"><span class="spec-label">未税金额</span><span class="spec-value">¥{{ money(orderUntaxedAmount(selected)) }}</span></div>
            <div class="spec-item"><span class="spec-label">税额</span><span class="spec-value">¥{{ money(orderTaxAmount(selected)) }}</span></div>
            <div class="spec-item"><span class="spec-label">运费</span><span class="spec-value">¥{{ money(selected.freight_amount) }}</span></div>
            <div class="spec-item"><span class="spec-label">含税合计</span><strong class="spec-value grand-total">¥{{ money(orderGrandTotal(selected)) }}</strong></div>
            <div class="spec-item"><span class="spec-label">已确认到货</span><span class="spec-value">¥{{ money(selected.finance_summary?.confirmed_receipt_amount) }}</span></div>
            <div class="spec-item"><span class="spec-label">当前应付</span><strong class="spec-value green-money">¥{{ money(selected.finance_summary?.current_payable_amount) }}</strong></div>
            <div class="spec-item"><span class="spec-label">质量冻结</span><span class="spec-value orange-money">¥{{ money(selected.finance_summary?.quality_frozen_amount) }}</span></div>
            <div class="spec-item"><span class="spec-label">财务状态</span><span class="spec-value"><el-tag size="mini" :type="financeSummaryTag(selected.finance_summary?.financial_settlement_status)">{{ financeSummaryText(selected.finance_summary?.financial_settlement_status) }}</el-tag></span></div>
          </div>
        </section>

        <!-- 计划已生成订单 -->
        <section v-if="mode==='plans'" class="dialog-section">
          <div class="section-title-bar"><span class="bar-accent"></span><h4>{{ generatedOrders(selected).length ? '已生成采购订单' : '采购订单生成预览' }}</h4></div>
          <div v-if="generatedOrders(selected).length">
            <div v-for="order in generatedOrders(selected)" :key="order.id" class="linked-card">
              <b @click="goOrderDetail(order)">{{ order.purchase_order_no }}</b>
              <span>{{ order.supplier ? order.supplier.supplier_name : '-' }} / {{ (order.items || []).length }} 行</span>
              <em>金额 ¥{{ money(order.total_amount) }}　订单 {{ labelOf(order.purchase_status) }}　到货 {{ labelOf(order.receipt_status) }}</em>
              <el-button size="mini" type="text" @click="goOrderDetail(order)">查看订单</el-button>
            </div>
          </div>
          <div v-else-if="orderPreview.length">
            <div v-for="group in orderPreview" :key="group.supplier_id" class="preview-card">
              <b>供应商：{{ group.supplier_name || group.supplier_id }}</b>
              <span>预计生成 1 张采购订单，{{ group.line_count }} 行明细</span>
              <em>采购数量 {{ planPreviewQuantity(group) }}，预计金额 ¥{{ money(group.total_amount) }}</em>
            </div>
          </div>
          <el-empty v-else description="审核通过后可预览并生成采购订单" :image-size="70" />
        </section>

        <!-- 订单到货记录 -->
        <section v-if="mode==='orders'" class="dialog-section">
          <div class="section-title-bar"><span class="bar-accent"></span><h4>到货记录</h4></div>
          <el-progress :percentage="orderProgress(selected)" color="#008b4b" />
          <div v-if="receiptRecords(selected).length" style="margin-top: 10px;">
            <div v-for="receipt in receiptRecords(selected)" :key="receipt.id" class="linked-card">
              <b @click="goReceiptDetail(receipt)">{{ receipt.receipt_no }}</b>
              <span>{{ receipt.receipt_date || '-' }} / 到货 {{ receiptQuantitySummary(receipt) }}</span>
              <em>验收 {{ receipt.items && receipt.items.length > 1 ? `${receipt.items.length} 行` : `合格 ${receiptQty(receipt, 'qualified_qty')} / 不合格 ${receiptQty(receipt, 'unqualified_qty')}` }}　库存过账：{{ stockPostingText(receipt.stock_post_status) }}</em>
              <el-button size="mini" type="text" @click="goReceiptDetail(receipt)">查看到货单</el-button>
            </div>
          </div>
          <el-empty v-else description="暂无到货记录" :image-size="64" />
        </section>

        <purchase-attachment-panel v-if="mode==='orders'" compact title="附件资料" document-type="order" :document-id="selected.id" :initial-attachments="selected.attachments || []" :editable="false" />

        <!-- 操作日志 -->
        <section v-if="mode==='orders'" class="dialog-section">
          <div class="section-title-bar"><span class="bar-accent"></span><h4>操作日志</h4></div>
          <div v-if="(selected.logs || []).length" class="log-list">
            <div v-for="log in selected.logs" :key="log.id">
              <b>{{ log.action }}</b>
              <span>{{ log.content }}</span>
              <small>{{ log.operator || '系统' }} · {{ timeText(log.created_at) }}</small>
            </div>
          </div>
          <el-empty v-else description="暂无操作日志" :image-size="48" />
        </section>
      </div>

      <div slot="footer" class="dialog-footer-custom" v-if="selected">
        <el-button size="small" @click="selected=null">关闭</el-button>
        <el-button v-if="['requests', 'orders'].includes(mode) && !selected.deleted_at" size="small" icon="el-icon-document" @click="goStandaloneDetail(selected)">完整详情页</el-button>
        <el-button v-if="canEdit(selected) && $can([`purchase.${mode.replace(/s$/, '')}.edit`, `purchase.${mode.replace(/s$/, '')}`])" size="small" icon="el-icon-edit" @click="openEditor(selected)">编辑{{ meta.short }}</el-button>
        <el-button v-if="canDelete(selected) && $can([`purchase.${mode.replace(/s$/, '')}.delete`, `purchase.${mode.replace(/s$/, '')}`])" size="small" type="danger" plain icon="el-icon-delete" @click="deleteDraft(selected)">{{ mode==='requests' ? '软删除' : '删除草稿' }}</el-button>
        <el-button v-for="a in primaryActions(selected)" :key="a.command" size="small" type="success" :icon="actionIcon(a.command)" @click="runAction(a.command, selected)">{{ a.label }}</el-button>
      </div>
    </el-dialog>

    <!-- 已删除需求模态弹窗 -->
    <el-dialog
      title="已删除采购需求"
      :visible.sync="deletedDialog.visible"
      width="1180px"
      top="5vh"
      append-to-body
      custom-class="purchase-detail-dialog deleted-records-dialog"
      :close-on-click-modal="false"
    >
      <div slot="title" class="dialog-header-custom">
        <div class="dialog-title-left">
          <i class="el-icon-delete header-icon" style="color: #94a3b8; background: #f1f5f9;"></i>
          <span class="dialog-main-title">已删除采购需求</span>
          <span class="dialog-sub-title">历史作废与留档审计</span>
          <el-tag size="mini" type="info">共 {{ deletedDialog.total }} 条</el-tag>
        </div>
      </div>

      <div class="deleted-dialog-content">
        <div class="erp-page-tip" style="margin-bottom: 12px;">
          <i class="el-icon-info" />
          <span>已删除需求仅保留原单据编号、物料明细与删除留痕记录供查阅审计，不可再编辑或流转为采购计划。</span>
        </div>

        <!-- 弹窗内置搜索栏 -->
        <div class="deleted-filter-bar">
          <el-input
            v-model="deletedDialog.keyword"
            size="small"
            placeholder="搜索需求单号 / 物料编码 / 物料名称"
            prefix-icon="el-icon-search"
            clearable
            style="width: 320px;"
            @keyup.enter.native="searchDeleted"
            @clear="searchDeleted"
          />
          <el-button size="small" type="primary" class="btn-theme-search" icon="el-icon-search" @click="searchDeleted">查询</el-button>
          <el-button size="small" icon="el-icon-refresh" @click="resetDeletedSearch">重置</el-button>
        </div>

        <!-- 弹窗表格 -->
        <el-table
          v-loading="deletedDialog.loading"
          :data="deletedDialog.rows"
          size="mini"
          border
          stripe
          class="deleted-records-table"
          style="width: 100%; margin-top: 10px;"
        >
          <el-table-column type="index" label="#" width="45" align="center" />
          <el-table-column prop="request_no" label="需求单号" width="140" show-overflow-tooltip>
            <template slot-scope="{row}">
              <span class="code-mono font-medium">{{ row.request_no }}</span>
            </template>
          </el-table-column>
          <el-table-column label="首个物料编码" width="125" show-overflow-tooltip>
            <template slot-scope="{row}">
              <span>{{ requestSummary(row).item_code || '--' }}</span>
            </template>
          </el-table-column>
          <el-table-column label="首个物料名称" min-width="140" show-overflow-tooltip>
            <template slot-scope="{row}">
              <span>{{ requestSummary(row).item_name || '--' }}</span>
            </template>
          </el-table-column>
          <el-table-column label="明细行数" width="80" align="center">
            <template slot-scope="{row}">
              <el-tag size="mini" type="info">{{ requestSummary(row).line_count || 0 }} 行</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="需求总量" width="90" align="right">
            <template slot-scope="{row}">
              <span class="qty-highlight">{{ requestSummary(row).request_qty || 0 }}</span>
            </template>
          </el-table-column>
          <el-table-column label="剩余未转" width="90" align="right">
            <template slot-scope="{row}">
              <span>{{ requestSummary(row).remaining_qty || 0 }}</span>
            </template>
          </el-table-column>
          <el-table-column label="删除前状态" width="90" align="center">
            <template slot-scope="{row}">
              <el-tag size="mini" :type="tagType(row.request_status)">{{ labelOf(row.request_status) }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="删除时间" width="150" show-overflow-tooltip>
            <template slot-scope="{row}">
              <span>{{ timeText(row.deleted_at) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="删除人" width="95" show-overflow-tooltip>
            <template slot-scope="{row}">
              <span>{{ row.deleted_by || '--' }}</span>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="75" align="center" fixed="right">
            <template slot-scope="{row}">
              <el-button type="text" size="mini" icon="el-icon-view" class="action-link-theme" @click="viewDeletedDetail(row)">详情</el-button>
            </template>
          </el-table-column>
        </el-table>

        <!-- 弹窗分页 -->
        <div class="deleted-dialog-pagination">
          <el-pagination
            background
            size="small"
            layout="total, prev, pager, next, sizes"
            :current-page="deletedDialog.page"
            :page-size="deletedDialog.perPage"
            :page-sizes="[10, 20, 50]"
            :total="deletedDialog.total"
            @current-change="handleDeletedPageChange"
            @size-change="handleDeletedSizeChange"
          />
        </div>
      </div>

      <div slot="footer" class="dialog-footer-custom">
        <el-button size="small" @click="deletedDialog.visible = false">关 闭</el-button>
      </div>
    </el-dialog>


    <el-drawer :title="editorTitle" :visible.sync="drawer" size="420px" custom-class="purchase-drawer">
      <el-form label-width="92px" size="small" class="purchase-form">
        <template v-if="mode==='requests'">
          <el-form-item label="物料"><el-select v-model="form.item_id" filterable><el-option v-for="i in items" :key="i.id" :label="`${i.item_code} / ${i.item_name}`" :value="i.id" /></el-select></el-form-item>
          <el-form-item label="需求数量"><el-input-number v-model="form.request_qty" :min="1" /></el-form-item>
          <el-form-item label="期望日期"><el-date-picker v-model="form.required_date" value-format="yyyy-MM-dd" /></el-form-item>
          <el-form-item label="优先级"><el-select v-model="form.priority"><el-option label="高" value="high" /><el-option label="中" value="normal" /><el-option label="低" value="low" /></el-select></el-form-item>
        </template>
        <template v-else>
          <el-form-item v-if="mode!=='plans'" label="供应商"><el-select v-model="form.supplier_id" filterable><el-option v-for="s in suppliers" :key="s.id" :label="`${s.supplier_code} / ${s.supplier_name}`" :value="s.id" /></el-select></el-form-item>
          <el-form-item :label="meta.short + '日期'"><el-date-picker v-model="formDate" value-format="yyyy-MM-dd" /></el-form-item>
          <div v-if="mode==='receipts'" class="receipt-editor">
            <div class="line-head"><b>到货明细</b><span>采购单位快照与换算因子不可修改</span></div>
            <section v-for="(line,index) in form.items" :key="index" class="receipt-line-card">
              <div class="receipt-line-title"><b>{{ line.item_code || itemLabel(line.item_id) }}</b><span>{{ line.item_name || '' }}</span></div>
              <div class="receipt-fields">
                <label>采购单位<el-input :value="line.purchase_unit_name_snapshot || '-'" disabled /></label>
                <label>换算因子<el-input :value="number(line.conversion_factor_snapshot || 1)" disabled /></label>
                <label>到货采购数量<el-input v-model.number="line.qty" type="number" min="0" step="0.0001" /></label>
                <label>计划基本数量<el-input :value="`${number(plannedBaseQty(line))} ${line.base_unit_name_snapshot || ''}`" disabled /></label>
                <label>实际基本数量<el-input v-model.number="line.actual_base_qty" type="number" min="0" step="0.000001" /></label>
                <label>差异数量<el-input :class="{ 'difference-input': hasReceiptDifference(line) }" :value="`${number(receiptDifference(line))} ${line.base_unit_name_snapshot || ''}`" disabled /></label>
                <label>合格采购数量<el-input v-model.number="line.qualified_qty" type="number" min="0" step="0.0001" /></label>
                <label>不合格采购数量<el-input v-model.number="line.unqualified_qty" type="number" min="0" step="0.0001" /></label>
                <label>合格基本数量<el-input :value="`${number(qualifiedBaseQty(line))} ${line.base_unit_name_snapshot || ''}`" disabled /></label>
                <label>不合格基本数量<el-input :value="`${number(unqualifiedBaseQty(line))} ${line.base_unit_name_snapshot || ''}`" disabled /></label>
                <label>差异原因<el-input v-model="line.difference_reason" :disabled="!hasReceiptDifference(line)" :placeholder="hasReceiptDifference(line) ? '必填' : '无差异'" /></label>
                <label>批次号<el-input v-model="line.batch_no" /></label>
                <label>目标仓库<el-select v-model="line.warehouse_id" clearable><el-option v-for="w in warehouses" :key="w.id" :label="w.warehouse_name" :value="w.id" /></el-select></label>
                <label>目标库位<el-select v-model="line.location_id" clearable><el-option v-for="l in filteredLocations(line.warehouse_id)" :key="l.id" :label="l.location_name" :value="l.id" /></el-select></label>
                <label v-if="isSerialManaged(line)" class="receipt-serial-field">设备编号 / 序列号<div class="serial-entry-tools"><span>{{ serialTrackingMode(line)==='required' ? '必须逐件编号' : '按需逐件编号' }}</span><el-button size="mini" type="success" plain @click.stop="generateLineSerials(line)">{{ serialGenerationButtonText(line) }}</el-button></div><el-input v-model="line.serial_text" type="textarea" :rows="3" resize="vertical" placeholder="供应商SN可直接粘贴；每台一行" @input="markSupplierSerials(line)" /><div v-if="serialNumberList(line.serial_text).length" class="serial-number-panel"><div class="serial-number-summary"><span>已录入 {{ serialNumberList(line.serial_text).length }} 个</span><el-button type="text" size="mini" icon="el-icon-printer" @click.stop="printSerialLabels(line)">全部打印</el-button></div><div class="serial-number-list"><div v-for="serialNo in serialNumberList(line.serial_text)" :key="serialNo" class="serial-number-item"><span :title="serialNo">{{ serialNo }}</span><el-button type="text" size="mini" icon="el-icon-printer" @click.stop="printSerialLabels(line, serialNo)">打印</el-button></div></div></div></label>
              </div>
              <p :class="receiptLineValid(line) ? 'receipt-pass' : 'receipt-fail'">合格 + 不合格必须等于到货数量；合格基本量 + 不合格基本量必须等于实际基本量。</p>
            </section>
          </div>
          <div v-else class="line-editor">
            <div class="line-head"><b>明细</b><el-button type="text" icon="el-icon-plus" @click="addLine">添加行</el-button></div>
            <div v-for="(line,index) in form.items" :key="index" class="form-line">
              <el-select v-model="line.item_id" filterable placeholder="物料"><el-option v-for="i in items" :key="i.id" :label="`${i.item_code}/${i.item_name}`" :value="i.id" /></el-select>
              <el-select v-if="mode==='plans'" v-model="line.supplier_id" filterable placeholder="供应商"><el-option v-for="s in suppliers" :key="s.id" :label="s.supplier_name" :value="s.id" /></el-select>
              <el-input-number v-model="line.qty" :min="1" controls-position="right" />
              <el-input-number v-model="line.unit_price" :min="0" controls-position="right" />
              <el-input v-if="mode==='receipts'" v-model="line.batch_no" placeholder="批次号" />
              <el-button type="text" class="danger-link" @click="form.items.splice(index,1)">删除</el-button>
            </div>
          </div>
        </template>
        <el-form-item label="备注"><el-input v-model="form.remark" type="textarea" :rows="3" /></el-form-item>
      </el-form>
      <div class="drawer-actions">
        <el-button size="small" @click="drawer=false">取消</el-button>
        <el-button size="small" type="success" @click="save">保存</el-button>
      </div>
    </el-drawer>

    <el-dialog
      title="到货库位与编号核对"
      :visible.sync="allocationTrace.visible"
      width="min(920px, 94vw)"
      append-to-body
      custom-class="receipt-allocation-trace-dialog"
    >
      <div v-if="allocationTrace.line" class="allocation-trace-body">
        <div class="allocation-trace-head">
          <div><span>物料</span><strong>{{ allocationTrace.line.item ? `${allocationTrace.line.item.item_code} / ${allocationTrace.line.item.item_name}` : '-' }}</strong></div>
          <div><span>批次</span><strong>{{ allocationTrace.line.batch_no || '-' }}</strong></div>
          <div><span>合格基本量</span><strong>{{ number(allocationTrace.line.qualified_base_qty || allocationTrace.line.actual_base_qty || 0) }} {{ allocationTrace.line.base_unit_name_snapshot || '-' }}</strong></div>
          <div><span>分配结果</span><strong>{{ receiptAllocationSummary(allocationTrace.line) }}</strong></div>
        </div>
        <el-input v-model="allocationTrace.keyword" size="small" clearable prefix-icon="el-icon-search" placeholder="输入设备编号 / 序列号核对所在库位" />
        <el-table :data="traceAllocations()" size="mini" border class="allocation-trace-table" empty-text="当前物料尚未记录库位分配">
          <el-table-column label="仓库" min-width="120"><template slot-scope="{row}">{{ row.warehouse ? `${row.warehouse.warehouse_code || ''} ${row.warehouse.warehouse_name || ''}`.trim() : '-' }}</template></el-table-column>
          <el-table-column label="库位" min-width="130"><template slot-scope="{row}">{{ row.location ? `${row.location.location_code || ''} ${row.location.location_name || ''}`.trim() : '-' }}</template></el-table-column>
          <el-table-column prop="base_qty" label="基本数量" width="92" align="right" />
          <el-table-column label="设备编号 / 序列号" min-width="340">
            <template slot-scope="{row}">
              <div v-if="filteredTraceSerials(row).length" class="trace-serial-list">
                <el-tag v-for="serialNo in filteredTraceSerials(row)" :key="serialNo" size="mini" type="success">{{ serialNo }}</el-tag>
              </div>
              <span v-else class="muted-text">{{ allocationTrace.keyword ? '当前库位无匹配编号' : '该物料无需逐件编号或尚未录入' }}</span>
            </template>
          </el-table-column>
        </el-table>
      </div>
      <span slot="footer"><el-button size="small" @click="allocationTrace.visible=false">关闭</el-button></span>
    </el-dialog>
    <purchase-payment-plan-dialog :visible.sync="paymentPlanVisible" :order-id="paymentPlanOrderId" @changed="paymentPlanChanged" />
  </section>
</template>

<script>
import { listEntity } from '@/api/erp/master'
import {
  generateReceiptSerials, listPurchase, getPurchase, savePurchaseRequest, savePurchasePlan, savePurchaseOrder, savePurchaseReceipt,
  submitRequest, requestToPlan, submitPlan, approvePlan, rejectPlan, previewPlanOrders, generatePlanOrders,
  submitOrder, approveOrder, orderToReceipt, confirmReceipt,
  closeRequest, cancelRequest, rejectOrder, cancelOrder, closeOrder, deletePurchaseDraft
} from '@/api/erp/purchase'
import { purchaseScopes, purchaseScopeLabel, purchaseScopeIssue } from '@/utils/purchaseManagementScope.mjs'
import PurchaseConversionFacts from '@/components/purchase/PurchaseConversionFacts.vue'
import PurchaseAttachmentPanel from '@/components/purchase/PurchaseAttachmentPanel.vue'

const statusLabelMap = {
  draft: '草稿',
  confirmed: '已确认',
  partially_planned: '部分已计划',
  planned: '已计划',
  closed: '已关闭',
  cancelled: '已取消',
  submitted: '已提交',
  approved: '已审核',
  pending: '待库存过账',
  rejected: '已驳回',
  processing: '处理中',
  partially_received: '部分到货',
  received: '已到货',
  not_received: '未到货',
  partial: '部分到货',
  not_ordered: '未生成订单',
  partially_ordered: '部分生成订单',
  order_generated: '已生成订单',
  ordered: '已下单',
  high: '高',
  normal: '中',
  low: '低',
  urgent: '紧急'
}

const metaMap = {
  requests: {
    title: '采购需求',
    short: '需求',
    subtitle: '采购对象是 Item，确认需求不代表审批。',
    tip: '未转计划的需求可编辑或软删除；编辑保存后回到草稿，重新确认后可转采购计划。',
    statuses: [{ label: '草稿', value: 'draft' }, { label: '已确认', value: 'confirmed' }, { label: '部分计划', value: 'partially_planned' }, { label: '已计划', value: 'planned' }, { label: '已关闭', value: 'closed' }]
  },
  plans: {
    title: '采购计划',
    short: '计划',
    subtitle: '制定采购计划，拆分分配到供应商，生成采购订单。',
    tip: '采购计划可多供应商；审核通过后才能预览/生成采购订单，已生成后不可重复生成。',
    statuses: [{ label: '草稿', value: 'draft' }, { label: '已提交', value: 'submitted' }, { label: '已审核', value: 'approved' }, { label: '已生成订单', value: 'order_generated' }]
  },
  orders: {
    title: '采购订单',
    short: '订单',
    subtitle: '一张采购订单只能对应一个供应商。',
    tip: '采购订单审核通过后才能生成到货单；已到货、已关闭、已取消订单只读。',
    statuses: [{ label: '草稿', value: 'draft' }, { label: '已提交', value: 'submitted' }, { label: '处理中', value: 'processing' }, { label: '部分到货', value: 'partially_received' }, { label: '已到货', value: 'received' }]
  },
  receipts: {
    title: '采购到货',
    short: '到货单',
    subtitle: '确认到货只记录到货与验收结果。',
    tip: '当前阶段只记录到货与验收结果，暂不更新正式库存；确认后进入“待库存过账”。',
    statuses: [{ label: '待确认', value: 'draft' }, { label: '已确认', value: 'confirmed' }]
  }
}

export default {
  components: { PurchaseAttachmentPanel, PurchaseConversionFacts, PurchasePaymentPlanDialog: () => import('@/components/finance/PurchasePaymentPlanDialog.vue') },
  props: { mode: { type: String, required: true } },
  data: () => ({
    rows: [],
    total: 0,
    page: 1,
    perPage: 10,
    listRevision: 0,
    detailRevision: 0,
    filters: { management_scope: '', keyword: '', status: '', dateRange: [] },
    selected: null,
    paymentPlanVisible: false,
    paymentPlanOrderId: 0,
    drawer: false,
    form: {},
    items: [],
    suppliers: [],
    warehouses: [],
    locations: [],
    orderPreview: [],
    allocationTrace: { visible: false, line: null, keyword: '' },
    deletedDialog: {
      visible: false,
      loading: false,
      rows: [],
      total: 0,
      page: 1,
      perPage: 10,
      keyword: ''
    }
  }),
  computed: {
    scopeOptions() { return purchaseScopes },
    meta() { return metaMap[this.mode] },
    columns() {
      if (this.mode === 'requests') return [
        { label: '需求单号', prop: 'request_no', width: 140 },
        { label: '首个物料编码', prop: 'request_summary.item_code', width: 130 },
        { label: '首个物料名称', prop: 'request_summary.item_name', width: 160 },
        { label: '明细行数', prop: 'request_summary.line_count', width: 85 },
        { label: '需求总量', prop: 'request_summary.request_qty', width: 95 },
        { label: '剩余未转', prop: 'request_summary.remaining_qty', width: 95 },
        { label: '期望日期', prop: 'request_summary.expected_date', width: 110 },
        { label: '状态', prop: 'request_status', tag: true, width: 95 }
      ]
      if (this.mode === 'plans') return [
        { label: '计划单号', prop: 'plan_no', width: 126 },
        { label: '计划日期', prop: 'plan_date', width: 104 },
        { label: '物料行数', prop: 'items.length', width: 82 },
        { label: '供应商分配', prop: 'allocation_summary', width: 145 },
        { label: '预计金额', prop: 'total_amount', width: 106 },
        { label: '计划状态', prop: 'plan_status', tag: true, width: 88 },
        { label: '审核状态', prop: 'audit_status', tag: true, width: 88 },
        { label: '订单状态', prop: 'order_status', tag: true, width: 104 }
      ]
      if (this.mode === 'orders') return [
        { label: '采购订单号', prop: 'purchase_order_no', width: 132 },
        { label: '供应商', prop: 'supplier.supplier_name', width: 150 },
        { label: '订单日期', prop: 'order_date', width: 104 },
        { label: '预计金额', prop: 'total_amount', width: 106 },
        { label: '审核状态', prop: 'audit_status', tag: true, width: 88 },
        { label: '采购状态', prop: 'purchase_status', tag: true, width: 90 },
        { label: '到货状态', prop: 'receipt_status', tag: true, width: 98 }
      ]
      return [
        { label: '到货单号', prop: 'receipt_no', width: 132 },
        { label: '采购订单', prop: 'order.purchase_order_no', width: 132 },
        { label: '供应商', prop: 'supplier.supplier_name', width: 150 },
        { label: '到货日期', prop: 'receipt_date', width: 104 },
        { label: '到货汇总', prop: 'receipt_quantity_summary', width: 112 },
        { label: '确认状态', prop: 'confirm_status', tag: true, width: 90 }
      ]
    },
    statCards() {
      const sum = p => this.rows.reduce((n, r) => n + Number(this.valueOf(r, p) || 0), 0)
      return [
        { label: '单据总数', value: this.total, sub: `${this.rows.length} 条当前页`, icon: 'el-icon-document' },
        { label: this.mode === 'requests' ? '需求明细' : this.mode === 'receipts' ? '到货明细' : '采购明细', value: this.rows.reduce((total, row) => total + (this.mode === 'requests' ? this.requestSummary(row).line_count : (row.items || []).length), 0), sub: '当前页 / 行', icon: 'el-icon-box' },
        { label: '预计金额', value: `¥${this.money(this.mode === 'orders' || this.mode === 'plans' || this.mode === 'receipts' ? sum('total_amount') : 0)}`, sub: '含税参考', icon: 'el-icon-money' },
        {
          label: this.mode === 'plans' ? '未分配计划' : '待处理',
          value: this.mode === 'plans'
            ? this.rows.filter(r => this.planAllocationInfo(r).type === 'danger').length
            : this.rows.filter(r => ['draft', 'submitted', 'processing'].includes(this.mainStatus(r))).length,
          sub: this.mode === 'plans' ? '单待分配供应商' : '单',
          icon: 'el-icon-warning-outline'
        }
      ]
    },
    editorTitle() { return `${this.form.id ? '编辑' : '新增'}${this.meta.short}` },
    formDate: {
      get() { return this.form.plan_date || this.form.order_date || this.form.receipt_date },
      set(v) {
        if (this.mode === 'plans') this.form.plan_date = v
        if (this.mode === 'orders') this.form.order_date = v
        if (this.mode === 'receipts') this.form.receipt_date = v
      }
    }
  },
  mounted() { this.bootstrap() },
  watch: {
    mode() { this.listRevision++; this.detailRevision++; this.page = 1; this.selected = null; this.paymentPlanVisible = false; this.paymentPlanOrderId = 0; this.bootstrap() }
  },
  methods: {
    scopeLabel(scope) { return purchaseScopeLabel(scope) },
    scopeIssue(document) { return purchaseScopeIssue(document) },
    changeScopeFilter() { this.page = 1; this.detailRevision++; this.selected = null; this.orderPreview = []; this.load() },
    openPaymentPlan(row) {
      if (this.mode !== 'orders' || !row?.id || !(this.$can('purchase.order.view') || this.$can('finance.view'))) return
      this.paymentPlanOrderId = Number(row.id)
      this.paymentPlanVisible = true
    },
    async paymentPlanChanged(plan) {
      if (this.mode === 'orders' && Number(this.selected?.id) === Number(plan.purchase_order_id)) await this.reloadDetail(plan.purchase_order_id)
    },
    openDeletedDialog() {
      this.deletedDialog.visible = true
      this.deletedDialog.page = 1
      this.deletedDialog.keyword = ''
      this.loadDeleted()
    },
    async loadDeleted() {
      this.deletedDialog.loading = true
      try {
        const params = {
          deleted: 'only',
          management_scope: this.filters.management_scope || undefined,
          page: this.deletedDialog.page,
          per_page: this.deletedDialog.perPage
        }
        if (this.deletedDialog.keyword && this.deletedDialog.keyword.trim()) {
          params.keyword = this.deletedDialog.keyword.trim()
        }
        const res = await listPurchase('requests', params)
        this.deletedDialog.rows = res.data.data || []
        this.deletedDialog.total = Number(res.data.total ?? res.data.meta?.total ?? 0)
      } catch (e) {
        this.$message.error('加载已删除需求列表失败')
      } finally {
        this.deletedDialog.loading = false
      }
    },
    searchDeleted() {
      this.deletedDialog.page = 1
      this.loadDeleted()
    },
    resetDeletedSearch() {
      this.deletedDialog.keyword = ''
      this.deletedDialog.page = 1
      this.loadDeleted()
    },
    handleDeletedPageChange(page) {
      this.deletedDialog.page = page
      this.loadDeleted()
    },
    handleDeletedSizeChange(size) {
      this.deletedDialog.perPage = size
      this.deletedDialog.page = 1
      this.loadDeleted()
    },
    async viewDeletedDetail(row) {
      if (!row || !row.id) return
      try {
        const res = await getPurchase('requests', row.id, { deleted: 'only' })
        this.selected = res.data
      } catch (e) {
        this.$message.error('加载单据详情失败')
      }
    },
    goStandaloneDetail(row) {
      if (!row || !row.id) return
      this.$router.push(`/purchase/${this.mode}/${row.id}/detail`)
    },
    lineSpec(row) {
      return row.spec_model || (row.item ? (row.item.spec || row.item.spec_model || row.item.model) : '') || '--'
    },
    lineStockQty(row) {
      const qty = row.purchase_conversion_snapshot?.planned_base_qty ?? row.request_qty ?? 0
      return Number(qty).toLocaleString('zh-CN', { maximumFractionDigits: 4 })
    },
    lineStockUnit(row) {
      return row.purchase_conversion_snapshot?.base_unit_name_snapshot || this.lineUnit(row)
    },
    isLineConverted(row) {
      const snap = row.purchase_conversion_snapshot
      if (!snap) return false
      const factor = Number(snap.conversion_factor_snapshot ?? snap.conversion_factor ?? 1)
      const pUnit = snap.purchase_unit_id
      const bUnit = snap.base_unit_id
      return factor !== 1 || (pUnit && bUnit && Number(pUnit) !== Number(bUnit))
    },
    lineConversionTip(row) {
      const snap = row.purchase_conversion_snapshot
      if (!snap) return ''
      const pUnit = snap.purchase_unit_name_snapshot || this.lineUnit(row)
      const bUnit = snap.base_unit_name_snapshot || this.lineStockUnit(row)
      const factor = Number(snap.conversion_factor_snapshot || 1).toLocaleString('zh-CN', { maximumFractionDigits: 6 })
      return `换算规则：1 ${pUnit} = ${factor} ${bUnit}`
    },
    async bootstrap() {
      await Promise.all([this.load(), this.loadOptions()])
    },
    async loadOptions() {
      const [items, suppliers, warehouses, locations] = await Promise.all([
        listEntity('items', { per_page: 100 }),
        listEntity('suppliers', { per_page: 100 }),
        listEntity('warehouses', { per_page: 100 }),
        listEntity('locations', { per_page: 100 })
      ])
      this.items = items.data.data || []
      this.suppliers = suppliers.data.data || []
      this.warehouses = (warehouses.data.data || []).filter(row => ['active', 'enabled'].includes(row.status))
      this.locations = (locations.data.data || []).filter(row => ['active', 'enabled'].includes(row.status))
    },
    async load() {
      const revision = ++this.listRevision
      const params = { keyword: this.filters.keyword, status: this.filters.status, management_scope: this.filters.management_scope || undefined, page: this.page, per_page: this.perPage }
      if (this.filters.dateRange && this.filters.dateRange.length === 2) {
        params.start_date = this.filters.dateRange[0]
        params.end_date = this.filters.dateRange[1]
      }
      const res = await listPurchase(this.mode, params)
      if (revision !== this.listRevision) return
      this.rows = (res.data.data || []).map(row => ({ ...row, receipt_quantity_summary: this.receiptQuantitySummary(row) }))
      this.total = Number(res.data.total ?? res.data.meta?.total ?? 0)
      const currentId = this.selected && this.selected.id
      const next = currentId ? this.rows.find(r => Number(r.id) === Number(currentId)) : null
      if (next) await this.reloadDetail(next.id)
      else { this.selected = null; this.orderPreview = [] }
    },
    async reloadDetail(id) {
      if (!id) return
      const revision = ++this.detailRevision
      const mode = this.mode
      const isDeleted = Boolean(this.selected && this.selected.deleted_at)
      const res = await getPurchase(this.mode, id, isDeleted ? { deleted: 'only' } : undefined)
      if (revision !== this.detailRevision || mode !== this.mode) return
      this.selected = res.data
      if (this.mode === 'plans' && !this.scopeIssue(this.selected)) this.preview(this.selected)
    },
    async afterBusinessAction(id) {
      await this.load()
      if (id && this.rows.find(r => Number(r.id) === Number(id))) await this.reloadDetail(id)
    },
    reset() { this.filters = { management_scope: '', keyword: '', status: '', dateRange: [] }; this.page = 1; this.load() },
    hasItemAllocatedSupplier(row) {
      return (row?.splits || []).some(s => Boolean(s.supplier_id || s.supplier))
    },
    validItemSplits(row) {
      return (row?.splits || []).filter(s => Boolean(s.supplier_id || s.supplier))
    },
    supplierName(id) {
      const s = (this.suppliers || []).find(item => Number(item.id) === Number(id))
      return s ? s.supplier_name : `供应商#${id}`
    },
    planAllocationInfo(plan) {
      const items = plan?.items || []
      if (!items.length) {
        return { type: 'info', icon: 'el-icon-minus', label: '无物料明细' }
      }
      let allocatedLines = 0
      const supplierSet = new Set()
      items.forEach(item => {
        const splits = item.splits || []
        const hasSupplier = splits.some(s => Boolean(s.supplier_id || s.supplier))
        if (hasSupplier) {
          allocatedLines++
          splits.forEach(s => {
            const sid = s.supplier_id || s.supplier?.id
            if (sid) supplierSet.add(sid)
          })
        }
      })
      if (allocatedLines === 0) {
        return { type: 'danger', icon: 'el-icon-warning', label: '未分配供应商' }
      }
      if (allocatedLines < items.length) {
        return { type: 'warning', icon: 'el-icon-warning-outline', label: `部分已配 (${allocatedLines}/${items.length})` }
      }
      return { type: 'success', icon: 'el-icon-check', label: `已全部分配 (${supplierSet.size}家)` }
    },
    planAllocationSummary(plan) {
      return this.planAllocationInfo(plan).label
    },
    planAllocationStatusTag(plan) {
      return this.planAllocationInfo(plan).type
    },
    planAllocationStatusIcon(plan) {
      return this.planAllocationInfo(plan).icon
    },
    actionIcon(command) {
      const map = {
        submitRequest: 'el-icon-check',
        requestToPlan: 'el-icon-right',
        cancelRequest: 'el-icon-close',
        closeRequest: 'el-icon-circle-close',
        goPlan: 'el-icon-document',
        submitPlan: 'el-icon-upload2',
        approvePlan: 'el-icon-circle-check',
        rejectPlan: 'el-icon-circle-close',
        previewPlanOrders: 'el-icon-view',
        generatePlanOrders: 'el-icon-download',
        goOrders: 'el-icon-document',
        submitOrder: 'el-icon-upload2',
        cancelOrder: 'el-icon-close',
        viewApproval: 'el-icon-time',
        goOpenReceipt: 'el-icon-document-checked',
        orderToReceipt: 'el-icon-truck',
        closeOrder: 'el-icon-circle-close',
        confirmReceipt: 'el-icon-check'
      }
      return map[command] || 'el-icon-arrow-right'
    },
    actionBtnClass(command) {
      if (['cancelRequest', 'closeRequest', 'rejectPlan', 'cancelOrder', 'closeOrder'].includes(command)) {
        return 'danger-link'
      }
      return 'action-link-theme'
    },
    selectRow(row) { this.reloadDetail(row.id) },
    openEditor(row) {
      if (this.mode === 'requests') return this.$router.push(row ? `/purchase/requests/${row.id}/edit` : { path: '/purchase/requests/create', query: this.filters.management_scope ? { management_scope: this.filters.management_scope } : {} })
      if (this.mode === 'plans') return this.$router.push(row ? `/purchase/plans/${row.id}/edit` : { path: '/purchase/plans/create', query: this.filters.management_scope ? { management_scope: this.filters.management_scope } : {} })
      if (this.mode === 'orders') return this.$router.push(row ? `/purchase/orders/${row.id}/edit` : { path: '/purchase/orders/create', query: this.filters.management_scope ? { management_scope: this.filters.management_scope } : {} })
      if (this.mode === 'receipts') return this.$router.push(row ? `/purchase/receipts/${row.id}/edit` : { path: '/purchase/receipts/create', query: this.filters.management_scope ? { management_scope: this.filters.management_scope } : {} })
      const firstSupplier = this.suppliers[0] || {}
      this.form = row ? this.toForm(row) : { supplier_id: firstSupplier.id, items: [this.blankLine()] }
      this.drawer = true
    },
    openDetail(row) {
      this.selectRow(row)
    },
    toForm(row) {
      if (this.mode === 'requests') return { ...row }
      const items = (row.items || []).map(l => ({ ...l, qty: l.plan_qty || l.order_qty || l.receipt_qty, unit_price: Number(l.unit_price || 0) }))
      return { ...row, items }
    },
    blankLine() {
      return { item_id: this.items[0] && this.items[0].id, supplier_id: this.suppliers[0] && this.suppliers[0].id, qty: 100, unit_price: 10, tax_rate: 13, qualified_qty: 98, unqualified_qty: 2, batch_no: `B${new Date().toISOString().slice(2, 10).replace(/-/g, '')}001` }
    },
    addLine() { this.form.items.push(this.blankLine()) },
    itemLabel(id) { const item = this.items.find(row => Number(row.id) === Number(id)); return item ? `${item.item_code} / ${item.item_name}` : '-' },
    serialTrackingMode(line) { const item = line.item || this.items.find(row => Number(row.id) === Number(line.item_id)); return item ? (item.serial_tracking_mode || (item.is_serial_managed ? 'required' : 'none')) : 'none' },
    isSerialManaged(line) { return this.serialTrackingMode(line) !== 'none' },
    markSupplierSerials(line) { line.serial_number_source = 'supplier' },
    serialNumberList(value) { return String(value || '').split(/\r?\n|,|，/).map(row => row.trim()).filter(Boolean) },
    serialGenerationButtonText(line) { const quantity = this.qualifiedBaseQty(line); return Number.isInteger(quantity) && quantity > 0 ? `一次生成 ${quantity} 个` : '一次生成全部编号' },
    async generateLineSerials(line) { const quantity = this.qualifiedBaseQty(line); if (!Number.isInteger(quantity) || quantity <= 0) return this.$message.error('合格实际入库数量必须是大于 0 的整数后才能生成序列号'); try { const response = await generateReceiptSerials({ item_id: line.item_id, quantity }); this.$set(line, 'serial_text', (response.data.data || []).join('\n')); this.$set(line, 'serial_number_source', 'system_generated'); this.$message.success(`已生成 ${quantity} 个序列号，请核对后保存`) } catch (e) { this.$message.error(e.userMessage || '序列号生成失败') } },
    printSerialLabels(line, serialNo = '') {
      const serials = serialNo ? [serialNo] : this.serialNumberList(line.serial_text)
      if (!serials.length) return this.$message.warning('请先录入或生成设备编号')
      const item = line.item || this.items.find(row => Number(row.id) === Number(line.item_id)) || {}
      const receipt = this.selected || {}
      const escapeHtml = value => String(value == null ? '' : value).replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char]))
      const labels = serials.map(value => `<article><div class="title">设备编号 / 序列号</div><div class="serial">${escapeHtml(value)}</div><div class="meta">物料：${escapeHtml(item.item_code || '-')} / ${escapeHtml(item.item_name || '-')}</div><div class="meta">到货单：${escapeHtml(receipt.receipt_no || '-')}</div></article>`).join('')
      const popup = window.open('', '_blank', 'width=760,height=640')
      if (!popup) return this.$message.error('打印窗口被浏览器拦截，请允许弹出窗口后重试')
      popup.document.open()
      popup.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>设备编号标签</title><style>@page{size:70mm 40mm;margin:3mm}*{box-sizing:border-box}body{margin:0;font-family:Arial,"Microsoft YaHei",sans-serif;color:#111}article{width:64mm;height:34mm;padding:4mm;border:1px solid #222;page-break-after:always;display:flex;flex-direction:column;justify-content:center}.title{font-size:10pt}.serial{margin:2.5mm 0;font-size:16pt;font-weight:700;word-break:break-all}.meta{font-size:8.5pt;line-height:1.5}article:last-child{page-break-after:auto}@media screen{body{padding:16px;background:#eee}article{margin:0 auto 16px;background:#fff}}</style></head><body>${labels}</body></html>`)
      popup.document.close()
      popup.focus()
      window.setTimeout(() => popup.print(), 250)
    },
    filteredLocations(warehouseId) { return warehouseId ? this.locations.filter(row => Number(row.warehouse_id) === Number(warehouseId)) : [] },
    plannedBaseQty(line) { return Number(line.qty || line.receipt_qty || 0) * Number(line.conversion_factor_snapshot || 1) },
    receiptDifference(line) { return Number(line.actual_base_qty == null ? this.plannedBaseQty(line) : line.actual_base_qty) - this.plannedBaseQty(line) },
    hasReceiptDifference(line) { return Math.abs(this.receiptDifference(line)) > 0.00000001 },
    qualifiedBaseQty(line) { const received = Number(line.qty || line.receipt_qty || 0); if (!received) return 0; return Number(line.actual_base_qty == null ? this.plannedBaseQty(line) : line.actual_base_qty) * Number(line.qualified_qty || 0) / received },
    unqualifiedBaseQty(line) { return Number(line.actual_base_qty == null ? this.plannedBaseQty(line) : line.actual_base_qty) - this.qualifiedBaseQty(line) },
    receiptLineValid(line) { return Math.abs(Number(line.qualified_qty || 0) + Number(line.unqualified_qty || 0) - Number(line.qty || line.receipt_qty || 0)) < 0.00000001 && (!this.hasReceiptDifference(line) || !!String(line.difference_reason || '').trim()) },
    async save() {
      if (this.mode === 'receipts' && !(this.form.items || []).every(this.receiptLineValid)) {
        return this.$message.warning('请检查到货数量守恒关系，并为实际基本数量差异填写原因')
      }
      const payload = this.normalizePayload()
      const api = this.mode === 'requests' ? savePurchaseRequest : this.mode === 'plans' ? savePurchasePlan : this.mode === 'orders' ? savePurchaseOrder : savePurchaseReceipt
      await api(payload)
      this.$message.success('保存成功')
      this.drawer = false
      await this.load()
    },
    normalizePayload() {
      if (this.mode === 'requests') return this.form
      const items = (this.form.items || []).map(l => this.mode === 'plans'
        ? { ...l, plan_qty: l.qty || l.plan_qty }
        : this.mode === 'orders'
          ? { ...l, order_qty: l.qty || l.order_qty }
          : { ...l, receipt_qty: l.qty || l.receipt_qty, qualified_qty: l.qualified_qty == null ? l.qty : l.qualified_qty, unqualified_qty: l.unqualified_qty || 0, actual_base_qty: l.actual_base_qty == null ? this.plannedBaseQty(l) : l.actual_base_qty })
      return { ...this.form, items }
    },
    async runAction(command, row = this.selected) {
      if (!row) return
      if (['submitRequest', 'requestToPlan', 'submitPlan', 'approvePlan', 'generatePlanOrders', 'submitOrder', 'approveOrder', 'orderToReceipt', 'confirmReceipt'].includes(command) && this.scopeIssue(row)) return this.$message.warning(this.scopeIssue(row))
      const map = { submitRequest, requestToPlan, submitPlan, approvePlan, rejectPlan, generatePlanOrders, submitOrder, approveOrder, orderToReceipt, confirmReceipt, closeRequest, cancelRequest, rejectOrder, cancelOrder, closeOrder }
      if (command === 'previewPlanOrders') return this.preview(row)
      if (command === 'goPlan') return this.$router.push('/purchase/plans')
      if (command === 'goOrders') return this.$router.push('/purchase/orders')
      if (command === 'goOpenReceipt') return this.$router.push(`/purchase/receipts/${row.open_receipt_id}/edit`)
      if (command === 'viewApproval') return row.approval_task_id
        ? this.$router.push(`/approvals/tasks/${row.approval_task_id}`)
        : this.$router.push('/approvals/all')
      try {
        if (command === 'rejectPlan') {
          const result = await this.$prompt('请填写采购计划驳回原因。驳回后计划返回草稿，可修改后重新提交。', '驳回采购计划', { inputPattern: /\S+/, inputErrorMessage: '驳回原因不能为空', confirmButtonText: '确认驳回', cancelButtonText: '取消' })
          const res = await rejectPlan(row.id, { reason: result.value })
          this.$message.success(res.data.message || '采购计划已驳回')
          return this.afterBusinessAction(row.id)
        }
        await this.$confirm(this.confirmSummary(command, row), '业务确认', { type: 'warning', dangerouslyUseHTMLString: true, confirmButtonText: this.confirmButtonText(command) })
        const res = await map[command](row.id)
        this.$message.success(this.successMessage(command, res))
        await this.afterBusinessAction(row.id)
      } catch (error) {
        if (error === 'cancel' || error === 'close') return
        const errors = error && error.response && error.response.data && error.response.data.errors
        const firstError = errors && Object.values(errors).flat()[0]
        const message = firstError || (error && error.response && error.response.data && error.response.data.message) || (error && error.message) || '业务操作失败，请重试'
        this.$message.error(message)
      }
    },
    async preview(row) {
      if (!row || this.mode !== 'plans' || this.scopeIssue(row)) return
      const revision = this.detailRevision
      try { const res = await previewPlanOrders(row.id); if (revision === this.detailRevision && this.mode === 'plans' && Number(this.selected?.id) === Number(row.id)) this.orderPreview = res.data.data || [] } catch (e) { if (revision === this.detailRevision) this.orderPreview = [] }
    },
    rowActions(row) {
      if (!row || row.deleted_at) return []
      if (this.mode === 'requests') {
        if (row.request_status === 'draft') return [{ command: 'submitRequest', label: '确认需求' }, { command: 'cancelRequest', label: '取消需求' }]
        if (row.request_status === 'confirmed') return [{ command: 'requestToPlan', label: '转采购计划' }, { command: 'closeRequest', label: '关闭需求' }]
        if (row.request_status === 'partially_planned') return [{ command: 'requestToPlan', label: '继续转采购计划' }, { command: 'closeRequest', label: '关闭需求' }]
        if (row.request_status === 'planned') return [{ command: 'goPlan', label: '查看采购计划' }]
        return []
      }
      if (this.mode === 'plans') {
        if (row.plan_status === 'draft' || row.audit_status === 'rejected') return [{ command: 'submitPlan', label: '提交审核' }]
        if (row.plan_status === 'submitted' && row.audit_status === 'pending') return [{ command: 'approvePlan', label: '审核通过' }, { command: 'rejectPlan', label: '驳回' }]
        if (row.audit_status === 'approved' && ['not_ordered', 'partially_ordered'].includes(row.order_status) && !['closed', 'cancelled'].includes(row.plan_status)) return [{ command: 'previewPlanOrders', label: '预览订单' }, { command: 'generatePlanOrders', label: '生成采购订单' }]
        if (['order_generated', 'ordered'].includes(row.order_status)) return [{ command: 'goOrders', label: '查看采购订单' }]
        return []
      }
      if (this.mode === 'orders') {
        if (['closed', 'cancelled', 'received'].includes(row.purchase_status) || row.receipt_status === 'received') return []
        if (row.purchase_status === 'draft' || row.audit_status === 'rejected') return [{ command: 'submitOrder', label: '提交审核' }, { command: 'cancelOrder', label: '取消订单' }]
        if (row.purchase_status === 'submitted' && row.audit_status === 'pending') return [{ command: 'viewApproval', label: '查看审核进度' }, { command: 'cancelOrder', label: '取消订单' }]
        if (row.audit_status === 'approved' && ['not_received', 'partial'].includes(row.receipt_status) && !['closed', 'cancelled'].includes(row.purchase_status)) {
          const actions = []
          if (row.open_receipt_id) actions.push({ command: 'goOpenReceipt', label: '查看待确认到货单' })
          else if (row.can_generate_receipt === true) actions.push({ command: 'orderToReceipt', label: row.receipt_status === 'partial' ? '生成剩余到货单' : '生成到货单' })
          if (!row.open_receipt_id && Number(row.available_receipt_qty || 0) > 0) actions.push({ command: 'closeOrder', label: '关闭订单' })
          return actions
        }
        return []
      }
      if (this.mode === 'receipts' && row.confirm_status === 'draft') return [{ command: 'confirmReceipt', label: '确认到货' }]
      return []
    },
    visibleRowActions(row) {
      const permissions = { approvePlan: 'purchase.plan.approve', rejectPlan: 'purchase.plan.approve', approveOrder: 'purchase.order.approve', rejectOrder: 'purchase.order.approve', confirmReceipt: 'purchase.receipt.confirm' }
      const forward = ['submitRequest', 'requestToPlan', 'submitPlan', 'approvePlan', 'generatePlanOrders', 'submitOrder', 'approveOrder', 'orderToReceipt', 'confirmReceipt']
      return this.rowActions(row).filter(action => !(forward.includes(action.command) && this.scopeIssue(row)) && (!permissions[action.command] || this.$can(permissions[action.command])))
    },
    primaryActions(row) { return this.visibleRowActions(row).slice(0, 2) },
    canEdit(row) {
      if (!row || row.deleted_at) return false
      if (this.mode === 'requests') return row.can_edit === true
      if (this.mode === 'plans') return row.plan_status === 'draft' || row.audit_status === 'rejected'
      if (this.mode === 'orders') return row.purchase_status === 'draft' || row.audit_status === 'rejected'
      if (this.mode === 'receipts') return row.confirm_status === 'draft'
      return false
    },
    canDelete(row) {
      if (!row || row.deleted_at) return false
      if (this.mode === 'requests') return row.can_delete === true
      if (this.mode === 'plans') return row.plan_status === 'draft'
      if (this.mode === 'orders') return row.purchase_status === 'draft' && !row.open_receipt_id
      if (this.mode === 'receipts') return row.confirm_status === 'draft' && row.receipt_status === 'draft' && row.stock_post_status === 'pending'
      return false
    },
    async deleteDraft(row) {
      try {
        const no = this.detailNo(row)
        const message = this.mode === 'requests' ? `确定软删除 ${no}？删除后将移入“已删除”列表，原单据和明细会保留。已转计划的需求不能删除。` : `确定删除 ${no}？只允许删除没有审核、到货、质量、库存和结算痕迹的草稿；有关联占用时系统会同步释放。`
        await this.$confirm(message, this.mode === 'requests' ? '软删除采购需求' : '删除草稿', { type: 'warning', confirmButtonText: '确认删除', cancelButtonText: '取消', confirmButtonClass: this.mode === 'requests' ? 'el-button--danger' : '' })
        const response = await deletePurchaseDraft(this.mode, row.id)
        this.$message.success(response.data.message || '草稿已删除')
        this.selected = null
        await this.load()
      } catch (error) {
        if (error === 'cancel' || error === 'close') return
        const errors = error?.response?.data?.errors
        const message = errors ? Object.values(errors).flat()[0] : (error?.userMessage || error?.response?.data?.message || '草稿删除失败')
        this.$message.error(message)
      }
    },
    valueOf(row, path) {
      if (path.startsWith('request_summary.')) return this.requestSummary(row)[path.replace('request_summary.', '')]
      return path.split('.').reduce((o, k) => (o ? o[k] : ''), row)
    },
    displayValue(row, col) {
      const value = this.valueOf(row, col.prop)
      if (col.prop === 'deleted_at') return this.timeText(value)
      if (this.mode === 'receipts' && col.prop === 'order.purchase_order_no' && !value) {
        return row.settlement_mode === 'replacement_no_charge' ? '换货免费补发' : '手工到货（未关联订单）'
      }
      return value == null || value === '' ? '--' : value
    },
    sourceText(value) { return ({ manual: '手工创建', purchase_plan: '采购计划', system: '系统生成', import: '导入', api: '接口', legacy_sync: '旧系统一次性同步' })[value] || value || '手工创建' },
    requestSummary(row) {
      const lines = row.items || row.request_items || []
      const first = lines[0] || {}
      const item = first.item || {}
      return {
        item_code: item.item_code || '--',
        item_name: item.item_name || '--',
        line_count: lines.length,
        request_qty: this.quantityByUnit(lines, 'purchase_quantity', false),
        converted_qty: this.quantityByUnit(lines, line => line.converted_qty ?? line.planned_qty ?? 0, false),
        remaining_qty: this.quantityByUnit(lines, 'remaining_qty', false),
        expected_date: first.expected_date || row.required_date || '--',
        priority: first.priority || row.priority || '--'
      }
    },
    mainStatus(row) { return row.request_status || row.plan_status || row.purchase_status || row.confirm_status || row.receipt_status },
    labelOf(v, prop = '') {
      if (prop === 'management_scope') return this.scopeLabel(v)
      if (prop === 'audit_status' && v === 'pending') return '待审核'
      return statusLabelMap[v] || v || '--'
    },
    tagType(v) {
      return ['approved', 'received', 'confirmed', 'low'].includes(v) ? 'success' : ['cancelled', 'rejected', 'high'].includes(v) ? 'danger' : ['partial', 'partially_received', 'pending', 'normal', 'partially_planned', 'partially_ordered'].includes(v) ? 'warning' : 'info'
    },
    detailNo(row) { return row.request_no || row.plan_no || row.purchase_order_no || row.receipt_no },
    selectedTitle(row) {
      if (['requests', 'plans'].includes(this.mode)) {
        const lines = row.items || row.request_items || []
        const first = lines[0] || {}
        const item = first.item || {}
        return item.item_code ? `${item.item_code} / ${item.item_name}${lines.length > 1 ? ` 等 ${lines.length} 行物料` : ''}` : '--'
      }
      return row.item ? `${row.item.item_code} / ${row.item.item_name}` : row.supplier ? row.supplier.supplier_name : row.plan ? row.plan.plan_no : '--'
    },
    detailLines(row) { return this.mode === 'requests' ? (row.items || row.request_items || []) : (row.items || []) },
    lineQty(line) { return line.request_qty || line.plan_qty || line.order_qty || line.receipt_qty || line.qty || 0 },
    lineUnit(line) {
      if (['orders', 'receipts'].includes(this.mode) && line.purchase_unit_name_snapshot) return line.purchase_unit_name_snapshot
      const unit = line.unit || (line.item && line.item.unit) || null
      const canonical = unit && (unit.standard_unit || unit.standardUnit || unit)
      return (canonical && (canonical.symbol || canonical.unit_name || canonical.unit_code)) || '-'
    },
    confirmButtonText(command) {
      return ({ submitRequest: '确认需求', requestToPlan: '转采购计划', approvePlan: '审核通过', submitOrder: '提交审核', approveOrder: '审核通过', confirmReceipt: '确认到货' })[command] || '确认执行'
    },
    confirmSummary(command, row) {
      const no = this.detailNo(row)
      const lines = row.items || row.request_items || []
      const lineCount = lines.length || Number(row.line_count || 0)
      const amount = this.money(row.total_amount || 0)
      const summaries = {
        submitRequest: `<p>确认需求：${no}</p><p>明细 ${lineCount} 行，采购数量 ${this.quantityByUnit(lines, 'purchase_quantity')}。确认后需求会被锁定，可转采购计划。</p>`,
        requestToPlan: `<p>转采购计划：${no}</p><p>明细 ${lineCount} 行，采购数量 ${this.quantityByUnit(lines, 'purchase_quantity')}，本次转化库存数量 ${this.quantityByUnit(lines, line => line.remaining_qty ?? Math.max(0, Number(line.request_qty || 0) - Number(line.converted_qty ?? line.planned_qty ?? 0)))}。</p>`,
        approvePlan: `<p>审核采购计划：${no}</p><p>物料 ${lineCount} 行，供应商 ${this.planSupplierCount(row)} 个，预计金额 ¥${amount}。审核通过后可生成采购订单。</p>`,
        submitOrder: `<p>提交采购订单审核：${no}</p><p>供应商：${row.supplier ? row.supplier.supplier_name : '--'}；明细 ${lineCount} 行，采购数量 ${this.quantityByUnit(lines, line => line.purchase_qty ?? line.order_qty ?? line.qty ?? 0)}，金额 ¥${amount}。</p>`,
        approveOrder: `<p>审核采购订单：${no}</p><p>供应商：${row.supplier ? row.supplier.supplier_name : '--'}；审核通过后才可生成到货单。</p>`,
        confirmReceipt: `<p>确认到货：${no}</p><p>到货 ${this.quantityByUnit(lines, 'receipt_qty')}，合格 ${this.quantityByUnit(lines, 'qualified_qty')}，不合格 ${this.quantityByUnit(lines, 'unqualified_qty')}，质量待处理 ${this.quantityByUnit(lines, line => this.receiptUnresolvedQty(line))}。本次不更新正式库存。</p>`
      }
      return summaries[command] || `<p>确认执行 ${no} 的业务动作？</p>`
    },
    planPreviewQuantity(group) {
      const groups = new Map()
      ;(group.items || []).forEach(line => {
        const snapshot = line.conversion_snapshot || {}
        const unit = snapshot.purchase_unit_name_snapshot || '-'
        groups.set(unit, Number(groups.get(unit) || 0) + Number(snapshot.purchase_qty || 0))
      })
      return [...groups].map(([unit, qty]) => `${this.number(qty)} ${unit}`).join('；') || '0'
    },
    quantityByUnit(lines, quantity, html = true) {
      const groups = new Map()
      lines.forEach(line => {
        const snapshot = quantity === 'purchase_quantity' ? line.purchase_conversion_snapshot : null
        const unit = snapshot?.purchase_unit_name_snapshot || this.lineUnit(line)
        const value = Number(quantity === 'purchase_quantity' ? (snapshot?.purchase_qty ?? this.lineQty(line)) : typeof quantity === 'function' ? quantity(line) : line[quantity] || 0)
        groups.set(unit, (groups.get(unit) || 0) + value)
      })
      // 确认框使用 HTML；单位来自主数据，必须转义且不能把不同单位相加。
      const escape = value => String(value).replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]))
      return Array.from(groups, ([unit, value]) => `${this.number(value)} ${html ? escape(unit) : unit}`).join('；') || '0'
    },
    successMessage(command, res) {
      if (command === 'submitRequest') return '需求已确认，已锁定需求，可转采购计划'
      return res.data.message || '操作成功'
    },
    planSupplierCount(row) {
      return new Set((row.items || []).flatMap(i => (i.splits || []).map(s => s.supplier_id).filter(Boolean))).size
    },
    generatedOrders(row) {
      const direct = row.orders || []
      if (direct.length) return direct
      const fromSplits = (row.items || []).flatMap(item => item.splits || []).map(split => split.order).filter(Boolean)
      return Array.from(new Map(fromSplits.map(order => [order.id, order])).values())
    },
    receiptRecords(row) {
      return row.receipts || []
    },
    orderQty(order) {
      return (order.items || []).reduce((n, l) => n + Number(l.order_qty || l.qty || 0), 0)
    },
    receiptQty(row, key) { return (row.items || []).reduce((n, l) => n + Number(l[key] || 0), 0) },
    receiptQuantitySummary(row) {
      const lines = row.items || []
      if (!lines.length) return '-'
      if (lines.length > 1) return `${lines.length} 行 / 多单位`
      return `${this.number(lines[0].receipt_qty)} ${lines[0].purchase_unit_name_snapshot || lines[0].unit_name_snapshot || ''}`.trim()
    },
    stockPostingText(status) { return ({ pending: '待库存过账', posted: '已库存过账', failed: '过账失败', cancelled: '已取消' })[status] || '待库存过账' },
    financeSummaryText(status) { return ({ pending_fulfillment: '待履约', quality_frozen: '质量冻结', pending_payment: '待付款', pending_invoice: '待收票', pending_refund: '待退款', settled: '财务已结清' })[status] || '未结清' },
    financeSummaryTag(status) { return ({ quality_frozen: 'warning', pending_refund: 'danger', settled: 'success', pending_payment: 'warning', pending_invoice: 'warning' })[status] || 'info' },
    receiptPendingQty(row) {
      return (row.items || []).reduce((n, l) => n + this.pendingReceiptQty(l), 0)
    },
    pendingReceiptQty(line) {
      return Math.max(0, Number(line.receipt_qty || 0) - Number(line.qualified_qty || 0) - Number(line.unqualified_qty || 0))
    },
    receiptUnresolvedQty(rowOrLine) {
      const lines = Array.isArray(rowOrLine.items) ? rowOrLine.items : [rowOrLine]
      return lines.reduce((total, line) => {
        const handlings = line.defect_handlings || line.defectHandlings || []
        const occupied = handlings
          .filter(row => row.handling_status !== 'cancelled' && row.handling_method !== 'pending')
          .reduce((sum, row) => sum + Number(row.handling_qty || 0), 0)
        return total + Math.max(0, Number(line.unqualified_qty || 0) + this.pendingReceiptQty(line) - occupied)
      }, 0)
    },
    receiptAllocationSummary(line) {
      const allocations = line && line.allocations ? line.allocations : []
      const serialCount = allocations.reduce((sum, row) => sum + (Array.isArray(row.serial_nos) ? row.serial_nos.length : 0), 0)
      return `${allocations.length} 个库位 / ${serialCount} 个编号`
    },
    openReceiptAllocationTrace(line) {
      this.allocationTrace = { visible: true, line, keyword: '' }
    },
    traceAllocations() {
      const allocations = this.allocationTrace.line && this.allocationTrace.line.allocations ? this.allocationTrace.line.allocations : []
      const keyword = String(this.allocationTrace.keyword || '').trim().toLowerCase()
      if (!keyword) return allocations
      return allocations.filter(row => (row.serial_nos || []).some(serialNo => String(serialNo).toLowerCase().includes(keyword)))
    },
    filteredTraceSerials(row) {
      const keyword = String(this.allocationTrace.keyword || '').trim().toLowerCase()
      return (row.serial_nos || []).filter(serialNo => !keyword || String(serialNo).toLowerCase().includes(keyword))
    },
    defectHandlingText(line) {
      if (Number(line.unqualified_qty || 0) <= 0 && this.pendingReceiptQty(line) <= 0) return '无异常'
      if (line.settlement_status === 'rejected') return '已拒付 / 已退供应商'
      if (line.settlement_status === 'accepted') return '已接收并转应付'
      if (line.settlement_status === 'partially_rejected') return '部分拒付'
      if (Number(line.unqualified_qty || 0) > 0) return '待不合格品处理'
      return '待验收确认'
    },
    orderProgress(row) {
      const qty = Number(row.total_qty || 0)
      const rec = (row.items || []).reduce((n, l) => n + Number(l.received_qty || 0), 0)
      return qty ? Math.round(rec / qty * 100) : 0
    },
    supplierContact(supplier) { return supplier ? (supplier.contact_name || supplier.contact_person || supplier.contacts || '--') : '--' },
    supplierPhone(supplier) { return supplier ? (supplier.contact_phone || supplier.phone || supplier.mobile || '--') : '--' },
    orderLineAmount(order) { return (order.items || []).reduce((sum, line) => sum + Number(line.purchase_qty || line.order_qty || 0) * Number(line.purchase_unit_price || line.unit_price || 0), 0) },
    orderTaxAmount(order) {
      return (order.items || []).reduce((sum, line) => {
        const amount = Number(line.purchase_qty || line.order_qty || 0) * Number(line.purchase_unit_price || line.unit_price || 0)
        const rate = Number(line.tax_rate || 0)
        return sum + (order.tax_mode === 'tax_excluded' ? amount * rate / 100 : (rate ? amount * rate / (100 + rate) : 0))
      }, 0)
    },
    orderUntaxedAmount(order) { const subtotal = this.orderLineAmount(order); return order.tax_mode === 'tax_excluded' ? subtotal : subtotal - this.orderTaxAmount(order) },
    orderGrandTotal(order) { const subtotal = this.orderLineAmount(order); return subtotal + (order.tax_mode === 'tax_excluded' ? this.orderTaxAmount(order) : 0) + Number(order.freight_amount || 0) },
    timeText(value) { return value ? String(value).replace('T', ' ').slice(0, 19) : '-' },
    goOrderDetail(order) { this.$router.push(`/purchase/orders/${order.id}/detail`) },
    goReceiptDetail(receipt) {
      if (receipt && receipt.confirm_status === 'draft') return this.$router.push(`/purchase/receipts/${receipt.id}/edit`)
      this.$router.push({ path: '/purchase/receipts', query: { receipt_id: receipt && receipt.id } })
    },
    rowClass({ row }) { return row === this.selected ? 'current-purchase-row' : '' },
    money(v) { return Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) },
    number(v) { return Number(v || 0).toFixed(6).replace(/0+$/, '').replace(/\.$/, '') }
  }
}
</script>

<style scoped>
.purchase-page {
  box-sizing: border-box;
  min-width: 0;
  min-height: calc(100vh - 54px);
  background: #f8fafc;
  padding: 16px 20px;
}

.purchase-main {
  min-width: 0;
}

.page-head {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 16px 20px;
  margin-bottom: 14px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
}

.head-left {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.title-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}

.title-row h1 {
  margin: 0;
  flex-shrink: 0;
  font-size: 20px;
  font-weight: 700;
  color: #0f172a;
}

.subtitle {
  margin: 0;
  font-size: 12px;
  color: #64748b;
}

.head-actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}

.business-alert {
  border-radius: 8px;
  margin-bottom: 14px;
}

.filter-card {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 14px 18px;
  margin-bottom: 14px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
}

.filter-inputs {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  flex: 1;
}

.filter-inputs .el-input {
  width: 260px;
  max-width: 100%;
}

.filter-inputs .el-select {
  width: 150px;
}

.filter-inputs .el-date-editor {
  width: 260px;
}

.filter-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-left: auto;
}

.stat-row {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
  gap: 14px;
  margin-bottom: 14px;
}

.stat-card {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 14px 18px;
  display: flex;
  align-items: center;
  gap: 14px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  transition: transform 0.2s, box-shadow 0.2s;
}

.stat-card:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
}

.stat-icon-wrapper {
  width: 44px;
  height: 44px;
  border-radius: 10px;
  background: #f0fdf4;
  color: #008b4b;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  flex-shrink: 0;
}

.stat-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.stat-label {
  font-size: 12px;
  color: #64748b;
}

.stat-val {
  font-size: 20px;
  font-weight: 700;
  color: #0f172a;
}

.stat-sub {
  font-size: 11px;
  color: #94a3b8;
}

.table-panel {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 14px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  box-sizing: border-box;
}

.action-link-theme {
  color: #008b4b !important;
  font-weight: 500;
  font-size: 12px;
}

.action-link-theme:hover {
  color: #00763f !important;
  text-decoration: underline;
}

.danger-link {
  color: #ef4444 !important;
  font-weight: 500;
  font-size: 12px;
}

.danger-link:hover {
  color: #dc2626 !important;
  text-decoration: underline;
}

::v-deep .el-pagination.is-background .el-pager li:not(.disabled).active {
  background-color: #008b4b !important;
  color: #fff !important;
  border-color: #008b4b !important;
  border-radius: 4px;
}

::v-deep .el-pagination.is-background .el-pager li:not(.disabled):hover {
  color: #008b4b !important;
}

::v-deep .current-purchase-row td {
  background: #f0fdf4 !important;
}

/* 详情居中弹窗样式 */
::v-deep .purchase-detail-dialog {
  max-width: 95vw;
  max-width: calc(100vw - 32px);
  border-radius: 10px;
  overflow: hidden;
  box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
}

::v-deep .purchase-detail-dialog .el-dialog__header {
  padding: 14px 20px;
  background: #f8fafc;
  border-bottom: 1px solid #e2e8f0;
}

::v-deep .purchase-detail-dialog .el-dialog__body {
  padding: 16px 20px;
  max-height: 72vh;
  overflow-y: auto;
  background: #f8fafc;
}

::v-deep .purchase-detail-dialog .el-dialog__footer {
  padding: 12px 20px;
  background: #fff;
  border-top: 1px solid #e2e8f0;
}

.dialog-header-custom {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.dialog-title-left {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.header-icon {
  color: #008b4b;
  font-size: 18px;
}

.dialog-main-title {
  font-size: 16px;
  font-weight: 700;
  color: #0f172a;
}

.dialog-sub-title {
  font-size: 13px;
  color: #64748b;
  margin-right: 4px;
}

.dialog-section {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 14px 16px;
  margin-bottom: 12px;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02);
}

.section-title-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
}

.bar-accent {
  width: 3px;
  height: 14px;
  background: #008b4b;
  border-radius: 2px;
}

.section-title-bar h4 {
  margin: 0;
  font-size: 13px;
  font-weight: 600;
  color: #1e293b;
}

.spec-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
  gap: 10px;
  font-size: 13px;
}

.spec-full {
  grid-column: 1 / -1;
}

.spec-item {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.spec-label {
  color: #64748b;
  font-size: 11px;
}

.spec-value {
  color: #1e293b;
  font-size: 13px;
  font-weight: 500;
  word-break: break-word;
}

.text-muted {
  color: #64748b;
}

.highlight-qty {
  color: #008b4b;
  font-weight: 600;
}

.grand-total {
  color: #008b4b;
  font-size: 14px;
  font-weight: 700;
}

.green-money {
  color: #008b4b;
  font-weight: 600;
}

.orange-money {
  color: #f59e0b;
  font-weight: 600;
}

.red-money {
  color: #ef4444;
  font-weight: 600;
}

.detail-dialog-table {
  width: 100%;
}

.order-lines-snapshot-list {
  margin-top: 10px;
  display: grid;
  gap: 8px;
}

.detail-line-card {
  padding: 10px 12px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
}

.line-card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 8px;
}

.line-card-header b {
  color: #1e293b;
  font-size: 13px;
}

.line-card-qty {
  color: #008b4b;
  font-weight: 600;
  font-size: 13px;
}

.unit-snapshot-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 8px;
  padding-top: 8px;
  border-top: 1px dashed #e2e8f0;
}

.unit-snapshot-grid label {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 11px;
  color: #64748b;
}

.unit-snapshot-grid strong {
  color: #0f172a;
}

.receipt-lines-extra-list {
  margin-top: 10px;
  display: grid;
  gap: 8px;
}

.receipt-line-summary {
  padding: 10px 12px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.receipt-line-summary b {
  color: #1e293b;
  font-size: 13px;
}

.receipt-line-summary small {
  color: #64748b;
  font-size: 12px;
}

.preview-card,
.linked-card {
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 6px;
  padding: 10px 14px;
  display: grid;
  gap: 4px;
  margin-bottom: 8px;
}

.preview-card b,
.linked-card b {
  color: #008b4b;
  font-size: 13px;
  cursor: pointer;
}

.preview-card em,
.linked-card em {
  font-style: normal;
  color: #00763f;
  font-size: 12px;
}

.linked-card .el-button {
  justify-self: start;
  padding: 0;
  color: #008b4b;
}

.receipt-location-trace {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  margin-top: 2px;
  padding-top: 6px;
  border-top: 1px dashed #e2e8f0;
  color: #008b4b;
  font-size: 11px;
}

.log-list {
  display: grid;
  gap: 8px;
}

.log-list > div {
  display: grid;
  gap: 3px;
  padding-bottom: 8px;
  border-bottom: 1px solid #edf0f2;
}

.log-list b {
  font-size: 12px;
  color: #1e293b;
}

.log-list span {
  font-size: 12px;
  color: #475569;
}

.log-list small {
  font-size: 11px;
  color: #94a3b8;
}

.dialog-splits-container {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 2px 0;
}

.dialog-split-chip {
  display: inline-flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  padding: 3px 8px;
  font-size: 11px;
}

.dialog-split-chip .supplier-name-bold {
  font-weight: 600;
  color: #1e293b;
}

.dialog-split-chip .split-amount-pill {
  color: #008b4b;
  font-weight: 500;
  background: #f0fdf4;
  padding: 1px 6px;
  border-radius: 3px;
}

.dialog-footer-custom {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 8px;
}

/* 抽屉样式适配 */
.purchase-form {
  padding: 0 18px 70px;
}
.purchase-form .el-select,
.purchase-form .el-date-editor {
  width: 100%;
}
.drawer-actions {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  padding: 12px 18px;
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  background: #fff;
  border-top: 1px solid #e2e8f0;
}

@media (max-width: 760px) {
  .purchase-page {
    padding: 12px 10px;
  }
  .filter-card {
    flex-direction: column;
    align-items: stretch;
  }
  .filter-inputs {
    flex-direction: column;
    align-items: stretch;
  }
  .filter-inputs .el-input,
  .filter-inputs .el-select,
  .filter-inputs .el-date-editor {
    width: 100%;
  }
  .filter-actions {
    margin-left: 0;
    justify-content: flex-end;
  }
  .stat-row {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
