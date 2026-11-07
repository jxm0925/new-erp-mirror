<!--
Design reference: D:\codex-introduce\new_erp\docs\ui-reference\phase6\phase6-order-detail-one-page-design.png
Progress design: 用户确认的订单产品树、单台整链路与工单聚合视图。
-->
<template>
  <section class="sales-detail-page">
    <sales-order-cancellation-dialog :visible.sync="cancelDialogVisible" :order-id="order.id" @changed="load" />
    <el-alert v-if="order.order_status === 'cancelled' && order.cancellation" :title="'订单已取消 · ' + (order.cancellation.finance_status === 'settled' ? '资金已结清' : '资金待处理') + (order.cancellation.production_pending ? ' · ' + order.cancellation.production_pending + '个编号待生产处理' : '')" type="warning" :closable="false" show-icon />
    <cancellation-dispositions v-if="order.id && $can('production.work_order.view')" :sales-order-id="order.id" @changed="load" />
    <aftersales-case-form v-if="$can('aftersales.view') || $can('aftersales.create')" ref="caseForm" :order="order" @saved="onAftersalesSaved" />
    <!-- 顶部主控导航与操作栏 -->
    <div class="detail-header-card">
      <div class="header-main">
        <div class="crumb">销售管理 / 销售订单 / 订单详情</div>
        <div class="title-row">
          <h1 class="order-no-title">{{ order.sales_order_no || '-' }}</h1>
          <div class="header-tags">
            <el-tag size="small" :type="orderStatusTag(order.order_status)" effect="light">{{ orderStatusText(order.order_status) }}</el-tag>
            <el-tag v-if="order.order_status !== 'cancelled'" size="small" :type="statusTag(order.shipment_status)" effect="plain">{{ shipmentStatusText(order.shipment_status) }}</el-tag>
            <el-tag v-if="order.aftersales_open_count > 0" size="small" type="warning" effect="plain">售后中 {{ order.aftersales_open_count }}</el-tag>
            <el-tag v-if="order.priority && order.priority !== 3" size="small" :type="priorityTagType(order.priority)" effect="plain">{{ order.priority_label }}</el-tag>
          </div>
        </div>
      </div>
      <div class="header-actions">
        <el-button size="small" icon="el-icon-back" @click="$router.push('/sales/orders')">返回列表</el-button>
        <el-button v-if="$can('sales_order.shipment.view')" size="small" icon="el-icon-truck" @click="$router.push({ path: '/sales/shipments', query: { sales_order_id: order.id } })">发货单</el-button>
        <el-button v-if="canCreateAftersales" size="small" type="success" plain icon="el-icon-service" @click="$refs.caseForm.open()">售后</el-button>
        <el-button v-if="order.allowed_actions && order.allowed_actions.edit" size="small" icon="el-icon-edit" @click="$router.push(`/sales/orders/${order.id}/edit`)">修改订单</el-button>
        <el-button v-if="order.allowed_actions && order.allowed_actions.lock_inventory" size="small" type="success" plain icon="el-icon-lock" @click="openInventoryLock">锁库存</el-button>
        <el-button v-if="order.allowed_actions && order.allowed_actions.submit_confirmation" size="small" type="success" icon="el-icon-check" @click="doConfirm">确认订单</el-button>
        <el-button v-if="order.allowed_actions && order.allowed_actions.production_confirmation" size="small" type="success" icon="el-icon-s-operation" @click="$router.push(`/sales/orders/${order.id}/production-confirmation`)">生产确认</el-button>
        <el-button v-if="canRequestInvoice" size="small" :type="hasStageAction ? '' : 'success'" icon="el-icon-tickets" @click="openInvoiceRequest">申请开票</el-button>
        <el-dropdown v-if="moreActions.length" trigger="click" @command="runMoreAction">
          <el-button size="small" :loading="shortClose.loading || cancelling">更多<i class="el-icon-arrow-down el-icon--right" /></el-button>
          <el-dropdown-menu slot="dropdown">
            <el-dropdown-item v-for="action in moreActions" :key="action.key" :command="action.key" :divided="action.divided" :class="{ 'more-danger': action.danger }">{{ action.label }}</el-dropdown-item>
          </el-dropdown-menu>
        </el-dropdown>
      </div>
    </div>

    <!-- 调整优先级 -->
    <el-dialog title="调整订单优先级" :visible.sync="priority.visible" width="min(460px, calc(100vw - 32px))" :close-on-click-modal="false">
      <el-alert type="info" :closable="false" show-icon title="没做完的工单（含半成品、补做工单）会一起改，车间按优先级排先后，同级先建的先做。" />
      <el-form label-width="72px" class="priority-form">
        <el-form-item label="优先级">
          <el-radio-group v-model="priority.value">
            <el-radio-button v-for="p in priorityOptions" :key="p.value" :label="p.value">{{ p.label }}</el-radio-button>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="原因">
          <el-input v-model="priority.reason" type="textarea" :rows="2" maxlength="500" show-word-limit placeholder="例如：客户催货，月底前要发" />
        </el-form-item>
      </el-form>
      <span slot="footer">
        <el-button @click="priority.visible = false">取消</el-button>
        <el-button type="success" :loading="priority.saving" @click="savePriority">保存</el-button>
      </span>
    </el-dialog>

    <!-- 锁定库存弹窗 -->
    <el-dialog title="确认锁定库存" :visible.sync="inventoryLockVisible" width="min(1180px, calc(100vw - 32px))" :close-on-click-modal="false">
      <el-alert type="info" :closable="false" show-icon title="系统将按当前可用库存、仓库、库位和批次进行正式占用，不会重复锁定。" />
      <el-alert v-if="inventoryLockError" type="error" :closable="false" show-icon :title="inventoryLockError" />
      <el-table v-else v-loading="inventoryLockLoading" :data="lockLines" border size="mini" max-height="360">
        <el-table-column prop="line_no" label="行" width="48" align="center" />
        <el-table-column label="商品" min-width="160"><template slot-scope="{row}"><b>{{ row.product_name || row.item_name || '-' }}</b><div v-if="row.inventory_pool_shared" class="text-muted">同物料行共用库存</div></template></el-table-column>
        <el-table-column label="规格" min-width="150"><template slot-scope="{row}">{{ row.spec_text || row.sku_name || '-' }}</template></el-table-column>
        <el-table-column prop="fulfillment_label" label="备货方式" min-width="96" />
        <el-table-column label="销售需求" min-width="96" align="right"><template slot-scope="{row}">{{ lockQuantity(row.order_qty, row.sales_unit_name) }}<div v-if="row.requires_delivery" class="text-muted">已发 {{ lockQuantity(row.shipped_qty, row.sales_unit_name) }}<br>待发 {{ lockQuantity(row.pending_fulfillment_qty, row.sales_unit_name) }}</div></template></el-table-column>
        <el-table-column label="订单库存需求" min-width="96" align="right"><template slot-scope="{row}">{{ row.requires_delivery ? lockQuantity(row.required_base_qty, row.base_unit_name) : '—' }}</template></el-table-column>
        <el-table-column label="库存余额" min-width="96" align="right"><template slot-scope="{row}">{{ row.requires_delivery ? lockQuantity(row.inventory_on_hand_qty, row.sales_unit_name) : '—' }}</template></el-table-column>
        <el-table-column label="可再锁库存" min-width="110" align="right"><template slot-scope="{row}">{{ row.requires_delivery ? lockQuantity(row.inventory_available_qty, row.sales_unit_name) : '—' }}<div v-if="row.quantity_notice" class="text-muted">{{ row.quantity_notice }}</div></template></el-table-column>
        <el-table-column label="本行已锁" min-width="96" align="right"><template slot-scope="{row}"><b class="green">{{ row.requires_delivery ? lockQuantity(row.locked_inventory_qty, row.sales_unit_name) : '—' }}</b></template></el-table-column>
        <el-table-column label="生产待补" min-width="96" align="right"><template slot-scope="{row}"><b class="orange">{{ row.fulfillment_strategy === 'production' || Number(row.pending_production_qty) > 0 ? lockQuantity(row.pending_production_qty, row.sales_unit_name) : '—' }}</b></template></el-table-column>
        <el-table-column label="外购待补" min-width="96" align="right"><template slot-scope="{row}"><b class="orange">{{ row.fulfillment_strategy === 'purchase' || Number(row.pending_purchase_qty) > 0 ? lockQuantity(row.pending_purchase_qty, row.sales_unit_name) : '—' }}</b></template></el-table-column>
      </el-table>
      <span slot="footer"><el-button @click="inventoryLockVisible=false">取消</el-button><el-button type="success" :loading="inventoryLockBusy" :disabled="inventoryLockLoading || !!inventoryLockError || !order.allowed_actions || !order.allowed_actions.lock_inventory" @click="confirmInventoryLock">确认锁库存</el-button></span>
    </el-dialog>

    <!-- 短交关闭弹窗：剩余未发不再发，应收改为实发金额 + 运费 -->
    <el-dialog title="短交关闭" :visible.sync="shortClose.visible" width="min(720px, calc(100vw - 32px))" :close-on-click-modal="false" append-to-body>
      <template v-if="shortClose.preview">
        <el-alert v-if="shortClose.preview.blockers && shortClose.preview.blockers.length" type="error" :closable="false" show-icon title="当前不能短交关闭">
          <div v-for="item in shortClose.preview.blockers" :key="item">{{ item }}</div>
        </el-alert>
        <el-alert v-else type="warning" :closable="false" show-icon title="关闭后剩余未发数量不再发货，未开工的关联工单会取消，已占用库存会释放。此操作不能撤销。" />
        <el-table class="short-close-table" :data="shortCloseLines" border size="mini" max-height="260">
          <el-table-column prop="line_no" label="行" width="48" align="center" />
          <el-table-column label="产品" min-width="150" show-overflow-tooltip><template slot-scope="{row}">{{ row.name || row.sku_name || '-' }}</template></el-table-column>
          <el-table-column label="订单数" width="80" align="right"><template slot-scope="{row}">{{ numberText(row.order_qty) }}</template></el-table-column>
          <el-table-column label="已发" width="80" align="right"><template slot-scope="{row}">{{ numberText(row.shipped_qty) }}</template></el-table-column>
          <el-table-column label="关闭数量" width="90" align="right"><template slot-scope="{row}"><b class="orange">{{ numberText(row.close_qty) }}</b></template></el-table-column>
          <el-table-column v-if="shortClose.preview.receivable_after !== undefined" label="实发金额" width="110" align="right"><template slot-scope="{row}">¥{{ money(row.shipped_amount) }}</template></el-table-column>
        </el-table>
        <div v-if="shortClose.preview.receivable_after !== undefined" class="short-close-summary">
          <div><span>原应收</span><b>¥{{ money(shortClose.preview.receivable_before) }}</b></div>
          <div><span>新应收</span><b class="green">¥{{ money(shortClose.preview.receivable_after) }}</b></div>
          <div><span>短交关闭金额</span><b class="orange">¥{{ money(shortClose.preview.short_closed_amount) }}</b></div>
          <div><span>已收净额</span><b>¥{{ money(shortClose.preview.net_received_amount) }}</b></div>
        </div>
        <el-alert v-if="shortCloseOverpaid" class="short-close-overpaid" type="error" :closable="false" show-icon
          :title="shortClose.preview.overpaid_amount !== undefined ? `客户多付 ¥${money(shortClose.preview.overpaid_amount)}，关闭后需财务办理退款，订单才会完成。` : '客户已付款超过新应收，关闭后需财务办理退款，订单才会完成。'" />
        <el-input v-if="shortClose.preview.can_close" v-model="shortClose.reason" class="short-close-reason" type="textarea" :rows="3" maxlength="500" show-word-limit placeholder="请填写短交原因，例如：客户确认剩余不再需要" />
      </template>
      <span slot="footer">
        <el-button @click="shortClose.visible = false">返回</el-button>
        <el-button type="warning" :disabled="!shortClose.preview || !shortClose.preview.can_close" :loading="shortClose.saving" @click="confirmShortClose">确认短交关闭</el-button>
      </span>
    </el-dialog>

    <div ref="tabSection" class="detail-card tracking-console-card" :class="{ 'tracking-console-card--production': activeTab === 'production' }">
      <el-tabs v-model="activeTab" class="tracking-tabs">
        <el-tab-pane name="info">
          <span slot="label"><i class="el-icon-document" /> 订单信息</span>
    <!-- 订单阶段提示：让销售知道现在能不能直接修改，以及下一步该做什么 -->
    <div v-if="stageTip" :class="['erp-page-tip', `tip-${stageTip.type}`]">
      <i :class="stageTip.icon" />
      <span>{{ stageTip.text }}</span>
    </div>

    <!-- 状态与预警提示 -->
    <el-alert v-if="order.order_status === 'confirmed' && order.change_eligibility && !order.change_eligibility.allowed" class="change-block-alert" type="warning" :closable="false" show-icon :title="`当前订单不能原地变更：${order.change_eligibility.reason}`" />
    <el-alert v-if="$route.query.changed" class="change-success-alert" type="success" :closable="false" show-icon title="订单已变更，请重新安排备货或进行订单生产确认。" />
    <el-alert v-if="order.pending_change_candidate" class="change-success-alert" type="warning" :closable="false" show-icon :title="`存在待审核 Candidate V${order.pending_change_candidate.candidate_version}：正式订单、库存预留以及生产和交付安排尚未变化。`" />

    <!-- 核心业务指标看板 (KPI Strip) -->
    <div class="kpi-metric-strip">
      <div class="kpi-card kpi-card--amount">
        <div class="kpi-label"><i class="el-icon-wallet" /> 订单金额<el-tag v-if="order.short_closed_at" class="short-closed-tag" size="mini" type="warning" effect="plain">已短交</el-tag></div>
        <div class="kpi-value amount">¥{{ money(order.total_amount) }}</div>
        <div v-if="order.short_closed_at && order.final_receivable_amount !== undefined" class="kpi-sub">短交关闭 ¥{{ money(order.short_closed_amount) }} · 应收 ¥{{ money(order.final_receivable_amount) }}</div>
        <div v-else-if="order.short_closed_at" class="kpi-sub">已短交关闭 · {{ date(order.short_closed_at) }}</div>
        <div v-else class="kpi-sub">共 {{ totalLineCount }} 项明细 · 总计 {{ totalOrderQty }} 件</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label"><i class="el-icon-coin" /> {{ order.channel_type_snapshot === 'online_platform' ? 'ERP 核销进度' : '收款进度' }}{{ order.funding_status && order.funding_status.policy_name ? ` · ${order.funding_status.policy_name}` : '' }}</div>
        <template v-if="order.funding_status && order.order_status !== 'cancelled'">
          <div class="kpi-value" :class="receiptProgress.done ? 'green' : 'orange'">¥{{ money(order.funding_status.net_received_amount) }}<small class="kpi-of">/ {{ money(receiptProgress.target) }}</small></div>
          <div class="kpi-bar"><i :style="{ width: receiptProgress.percent + '%' }" /></div>
          <div class="kpi-sub">{{ receiptProgress.text }}</div>
        </template>
        <div v-else class="kpi-value text-muted">-</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label"><i class="el-icon-truck" /> 发货进度</div>
        <template v-if="shipmentProgress.total > 0">
          <div class="kpi-value">{{ numberText(shipmentProgress.shipped) }}<small class="kpi-of">/ {{ numberText(shipmentProgress.total) }} 需发</small></div>
          <div class="kpi-bar"><i :style="{ width: shipmentProgress.percent + '%' }" /></div>
          <div class="kpi-sub">{{ shipmentProgress.text }}</div>
        </template>
        <template v-else>
          <div class="kpi-value text-muted">无需发货</div>
          <div class="kpi-sub">本订单没有实物商品</div>
        </template>
      </div>
      <div class="kpi-card">
        <div class="kpi-label"><i class="el-icon-time" /> 要求交期</div>
        <div class="kpi-value" :class="{ 'text-muted': !order.required_delivery_date }">{{ dateOnly(order.required_delivery_date) }}</div>
        <div class="kpi-sub">下单 {{ date(orderTime) }} · 优先级 {{ order.priority_label || '普通' }}</div>
      </div>
    </div>

    <!-- 核心基本信息两栏平衡卡片 (消除冗余与空态割裂) -->
    <div class="overview-layout">
      <!-- 订单基本信息 -->
      <section class="detail-card">
        <div class="card-head">
          <div class="card-title"><i class="el-icon-document" /> 订单基本信息</div>
          <el-button v-if="changes.total" type="text" size="mini" @click="scrollToChanges">查看变更历史 ({{ changes.total }})</el-button>
        </div>
        <div class="kv-grid">
          <div class="kv-item">
            <span class="kv-label">销售人员</span>
            <span class="kv-value font-medium">{{ (order.sales_user && order.sales_user.display_name) || (order.sales_user_legacy_id ? `账号 ${order.sales_user_legacy_id}` : '-') }}</span>
          </div>
          <div class="kv-item">
            <span class="kv-label">创建者</span>
            <span class="kv-value font-medium">{{ (order.creator && order.creator.display_name) || (order.created_by_legacy_id ? `账号 ${order.created_by_legacy_id}` : '-') }}</span>
          </div>
          <div class="kv-item">
            <span class="kv-label">订单来源</span>
            <span class="kv-value">{{ orderSourceText }}</span>
          </div>
          <div class="kv-item">
            <span class="kv-label">贸易类型</span>
            <span class="kv-value">{{ order.trade_type === 'foreign' ? '外贸出口' : '国内贸易' }}</span>
          </div>
          <div class="kv-item">
            <span class="kv-label">成交平台</span>
            <span class="kv-value">{{ order.platform_label || '-' }}</span>
          </div>
          <div class="kv-item">
            <span class="kv-label">当前版本</span>
            <span class="kv-value font-semibold">V{{ currentVersion }}</span>
          </div>
          <div class="kv-item">
            <span class="kv-label">原始单号</span>
            <span class="kv-value font-mono">{{ originalOrderNo || '-' }}</span>
          </div>
          <div class="kv-item">
            <span class="kv-label">优先级</span>
            <span class="kv-value">
              <el-tag size="mini" :type="priorityTagType(order.priority)">{{ order.priority_label || '普通' }}</el-tag>
              <small v-if="order.priority_adjusted_by_name" class="text-muted">{{ order.priority_adjusted_by_name }} 调整{{ order.priority_reason ? `：${order.priority_reason}` : '' }}</small>
              <el-button v-if="order.allowed_actions && order.allowed_actions.adjust_priority" type="text" size="mini" @click="openPriority">调整</el-button>
            </span>
          </div>
          <div class="kv-item">
            <span class="kv-label">生产确认状态</span>
            <span class="kv-value"><el-tag size="mini" :type="statusTag(order.production_confirm_status)">{{ order.order_status === 'cancelled' ? '-' : statusText(order.production_confirm_status) }}</el-tag></span>
          </div>
          <div class="kv-item">
            <span class="kv-label">付款方式</span>
            <span class="kv-value font-medium">{{ order.payment_method_name || (order.payment_method_snapshot && order.payment_method_snapshot.method_name) || order.pay_type || '-' }}</span>
          </div>
          <div class="kv-item">
            <span class="kv-label">付款条件</span>
            <span class="kv-value">{{ (order.funding_status && order.funding_status.policy_name) || '未配置' }}</span>
          </div>
          <div v-if="platformPayment || (order.funding_status && order.funding_status.platform_payment_eligible)" class="kv-item">
            <span class="kv-label">平台付款确认</span>
            <span class="kv-value">
              <el-tag size="mini" :type="platformPayment ? 'success' : 'info'">{{ platformPayment ? '已确认平台付款' : '未确认平台付款' }}</el-tag>
              <el-button v-if="order.allowed_actions && order.allowed_actions.platform_payment_confirm" type="text" size="mini" @click="$router.push({ path: `/sales/orders/${order.id}/edit`, query: { platform_payment: '1' } })">前往核对</el-button>
            </span>
            <small v-if="platformPayment" class="text-muted">{{ platformPayment.confirmed_by }} · {{ date(platformPayment.confirmed_at) }}</small>
          </div>
          <div class="kv-item">
            <span class="kv-label">备货组成</span>
            <span class="kv-value">{{ order.order_status === 'cancelled' ? '-' : (order.fulfillment_composition_label || '-') }}</span>
          </div>
          <div class="kv-item">
            <span class="kv-label">加急 / 延期</span>
            <span class="kv-value">
              <el-tag v-if="order.is_urgent" size="mini" type="danger">加急订单</el-tag>
              <el-tag v-if="order.is_delay" size="mini" type="warning">延期发货：{{ dateOnly(order.delay_date) }}</el-tag>
              <span v-if="!order.is_urgent && !order.is_delay" class="text-muted">正常</span>
            </span>
          </div>
          <div class="kv-item">
            <span class="kv-label">订单共享</span>
            <span class="kv-value">
              <template v-if="order.is_share">
                <span v-if="(order.share_users || []).length" class="share-users-tags">
                  <el-tag v-for="user in (order.share_users || [])" :key="user.user_id" size="mini" type="info">{{ user.display_name }}</el-tag>
                </span>
                <span v-else class="text-muted">已开启共享</span>
              </template>
              <span v-else class="text-muted">未开启共享</span>
            </span>
          </div>
          <div v-if="order.order_status !== 'cancelled' && order.funding_status && order.funding_status.agreed_deposit_supported" class="kv-item">
            <span class="kv-label">约定定金</span>
            <span class="kv-value">
              <template v-if="order.funding_status.production_required_amount !== undefined">
                ¥{{ Number(order.funding_status.production_required_amount || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}
                <small style="color: #909399">{{ order.funding_status.deposit_source === 'agreed' ? '（与客户约定）' : '（按付款条件默认）' }}</small>
              </template>
              <template v-else>{{ order.funding_status.deposit_source === 'agreed' ? '与客户约定' : '按付款条件默认' }}</template>
              <el-button v-if="order.allowed_actions && order.allowed_actions.edit_agreed_deposit" type="text" size="mini" :loading="depositSaving" @click="editAgreedDeposit">修改</el-button>
            </span>
          </div>
          <div class="kv-item kv-item--full">
            <span class="kv-label">订单备注</span>
            <span class="kv-value remark-text" :class="{ 'text-muted': !order.remark }">{{ order.remark || '无备注' }}</span>
          </div>
        </div>
      </section>

      <!-- 客户与收货物流 -->
      <section class="detail-card">
        <div class="card-head">
          <div class="card-title"><i class="el-icon-truck" /> 客户与收货物流</div>
        </div>
        <div class="kv-grid">
          <div class="kv-item">
            <span class="kv-label">客户名称</span>
            <span class="kv-value font-semibold">{{ order.customer_name || '-' }}</span>
          </div>
          <div class="kv-item">
            <span class="kv-label">客户类型</span>
            <span class="kv-value">{{ order.customer_kind === 'enterprise' ? '企业客户' : '个人客户' }}</span>
          </div>
          <div class="kv-item">
            <span class="kv-label">联系人</span>
            <span class="kv-value">{{ order.contact_name || (order.customer_snapshot && order.customer_snapshot.contact && order.customer_snapshot.contact.name) || '-' }}</span>
          </div>
          <div class="kv-item">
            <span class="kv-label">联系电话</span>
            <span class="kv-value font-mono">{{ order.customer_phone || order.contact_phone || '-' }}</span>
          </div>
          <div class="kv-item">
            <span class="kv-label">平台买家 ID</span>
            <span class="kv-value font-mono">{{ order.platform_buyer_id || (order.customer_snapshot && order.customer_snapshot.platform_buyer_id) || '-' }}</span>
          </div>
          <div class="kv-item">
            <span class="kv-label">配送方式</span>
            <span class="kv-value">
              <el-tag size="mini" :type="(order.shipping_snapshot && order.shipping_snapshot.is_self_pickup) ? 'warning' : 'info'">
                {{ (order.shipping_snapshot && order.shipping_snapshot.is_self_pickup) ? '客户自提' : '快递/物流承运' }}
              </el-tag>
            </span>
          </div>
          <div v-if="!(order.shipping_snapshot && order.shipping_snapshot.is_self_pickup)" class="kv-item">
            <span class="kv-label">客户指定物流</span>
            <span class="kv-value">{{ shippingCarrierName }}<small v-if="shippingCarrierName !== '-'" class="kv-hint">发货时预填，可改</small></span>
          </div>
          <div v-if="order.customer_snapshot && order.customer_snapshot.name && order.customer_snapshot.name !== order.customer_name" class="kv-item">
            <span class="kv-label">客户历史快照</span>
            <span class="kv-value text-muted">{{ order.customer_snapshot.name }}</span>
          </div>
          <div v-if="order.shipping_snapshot && order.shipping_snapshot.customer_logistics_note" class="kv-item kv-item--full">
            <span class="kv-label">客户物流备注</span>
            <span class="kv-value text-muted">{{ order.shipping_snapshot.customer_logistics_note }}</span>
          </div>
          <!-- 详细收货地址 (全宽卡片) -->
          <div class="kv-item kv-item--full address-box">
            <span class="kv-label"><i class="el-icon-location-outline" /> 收货地址</span>
            <span class="kv-value address-text">{{ order.full_address || order.address || '-' }}</span>
          </div>
          <!-- 外贸附加单据字段 -->
          <template v-if="order.trade_type === 'foreign'">
            <div class="kv-item kv-item--full foreign-box">
              <span class="kv-label">外贸物流参数</span>
              <span class="kv-value">
                件数: {{ (order.logistics_snapshot && order.logistics_snapshot.pcs) || '-' }} | 
                毛重: {{ (order.logistics_snapshot && order.logistics_snapshot.gw) || '-' }} | 
                体积: {{ (order.logistics_snapshot && order.logistics_snapshot.vol) || '-' }}
              </span>
            </div>
            <div class="kv-item kv-item--full foreign-box">
              <span class="kv-label">关键节点</span>
              <span class="kv-value">
                截单: {{ dateOnly(order.logistics_snapshot && order.logistics_snapshot.si_date) }} | 
                截关: {{ dateOnly(order.logistics_snapshot && order.logistics_snapshot.cy_date) }} | 
                货好: {{ dateOnly(order.logistics_snapshot && order.logistics_snapshot.cargo_ready_date) }}
              </span>
            </div>
          </template>
        </div>
        <div class="dispatch-records">
          <div class="dispatch-records-head">
            <b>发货记录</b>
            <el-button v-if="$can('sales_order.shipment.view') && dispatchRecords.length" type="text" size="mini" @click="$router.push({ path: '/sales/shipments', query: { sales_order_id: order.id } })">查看发货单</el-button>
          </div>
          <el-table v-if="dispatchRecords.length" :data="dispatchRecords" size="mini" border>
            <el-table-column label="发货单" min-width="140"><template slot-scope="{row}"><span class="font-mono">{{ row.dispatch_no }}</span></template></el-table-column>
            <el-table-column label="方式" width="60"><template slot-scope="{row}">{{ row.delivery_mode === 'pickup' ? '提货' : '发货' }}</template></el-table-column>
            <el-table-column label="快递公司" min-width="100"><template slot-scope="{row}"><span :class="{ 'text-muted': !row.carrier_name }">{{ row.carrier_name || (row.delivery_mode === 'pickup' ? '-' : '未填写') }}</span></template></el-table-column>
            <el-table-column label="运单号" min-width="130"><template slot-scope="{row}"><span :class="row.tracking_no ? 'font-mono' : 'text-muted'">{{ row.tracking_no || (row.delivery_mode === 'pickup' ? '-' : '未填写') }}</span></template></el-table-column>
            <el-table-column label="包裹" width="60" align="right"><template slot-scope="{row}">{{ row.package_count }} 个</template></el-table-column>
            <el-table-column label="发货时间" min-width="140"><template slot-scope="{row}">{{ date(row.dispatched_at) }}<span v-if="row.operator_name" class="text-muted"> · {{ row.operator_name }}</span></template></el-table-column>
          </el-table>
          <div v-else class="dispatch-empty text-muted">未发货。实际快递公司和运单号在每次发货时填写。</div>
          <div v-if="dispatchRecords.length > 1" class="dispatch-empty text-muted">共 {{ dispatchRecords.length }} 次发货 · {{ dispatchPackageCount }} 个包裹</div>
        </div>
      </section>
    </div>

    <!-- 订单行商品明细表格 -->
    <section class="detail-card order-lines-card">
      <div class="card-head">
        <div class="card-title">
          <i class="el-icon-goods" /> 订单商品明细
          <span class="title-sub-count">共 {{ (order.lines || []).length }} 项商品</span>
          <span v-if="totalLineFilesCount" class="title-sub-count title-sub-count--blue">图纸资料 {{ totalLineFilesCount }} 份</span>
        </div>
        <el-button
          v-if="linesWithFilesCount"
          type="text"
          size="mini"
          @click="toggleAllLineDrawings"
        >
          {{ allFileLinesExpanded ? '收起图纸明细' : `展开有图纸行 (${linesWithFilesCount})` }}
        </el-button>
      </div>
      <el-table
        ref="orderLinesTable"
        :data="order.lines || []"
        row-key="id"
        :expand-row-keys="expandedLineRowKeys"
        border
        size="mini"
        class="clean-order-table"
        highlight-current-row
        @expand-change="onLineExpandChange"
      >
        <el-table-column type="expand" width="42">
          <template slot-scope="{row, $index}">
            <div class="line-expand-box">
              <div class="line-expand-head">
                <span class="line-badge">行 {{ row.line_no || ($index + 1) }}</span>
                <span class="line-prod">{{ row.product_name }}</span>
                <span class="line-sku text-muted">/ {{ row.sku_name }}</span>
                <span class="line-expand-sub">订单行详细扩展信息</span>
              </div>

              <!-- 订单行详情：一块三列信息，数量进度已在表格列中直接显示 -->
              <div class="line-expand-compact">
                <div class="expand-kv"><span class="lbl">销售单位</span><span class="val">{{ row.unit_name_snapshot || '-' }}</span></div>
                <div class="expand-kv"><span class="lbl">库存物料</span><span class="val">{{ lineItem(row).item_name || '-' }} <small v-if="lineItem(row).item_code" class="text-muted font-mono">({{ lineItem(row).item_code }})</small></span></div>
                <div class="expand-kv"><span class="lbl">换算</span><span class="val font-mono">{{ row.item_base_unit_name_snapshot && row.fulfillment_factor_snapshot ? `1 ${row.unit_name_snapshot || ''} = ${Number(row.fulfillment_factor_snapshot)} ${row.item_base_unit_name_snapshot}` : '无需换算' }}</span></div>
                <div class="expand-kv"><span class="lbl">定制类型</span><span class="val">{{ row.is_special_customized ? '特殊定制' : (row.is_customized ? '普通定制' : '标准配置') }}</span></div>
                <div class="expand-kv"><span class="lbl">工作电压</span><span class="val">{{ row.electric || '未选' }}</span></div>
                <div class="expand-kv"><span class="lbl">原水泵</span><span class="val">{{ row.need_pump === true ? '需要' : (row.need_pump === false ? '不需要' : '未配置') }}</span></div>
                <div class="expand-kv"><span class="lbl">报价有效期</span><span class="val">{{ (row.configuration_snapshot && row.configuration_snapshot.valid_days) ? `${row.configuration_snapshot.valid_days} 天` : '未设定' }}</span></div>
                <div class="expand-kv"><span class="lbl">库存余额</span><span class="val">{{ lineLockQuantities(row).requires_delivery ? `${numberText(lineLockQuantities(row).inventory_on_hand_qty)} ${row.unit_name_snapshot || ''}（可再锁 ${numberText(lineLockQuantities(row).inventory_available_qty)}）` : '—' }}</span></div>
                <div class="expand-kv"><span class="lbl">行备注</span><span class="val" :class="{ 'text-muted': !row.remark }">{{ row.remark || '无' }}</span></div>
                <div v-if="(row.configuration_snapshot && row.configuration_snapshot.special_custom_description) || row.customization_description" class="expand-kv expand-kv--full"><span class="lbl">特殊定制说明</span><span class="val">{{ (row.configuration_snapshot && row.configuration_snapshot.special_custom_description) || row.customization_description }}</span></div>
              </div>

              <!-- 本行图纸与技术附件表格 -->
              <div class="line-expand-drawing-section">
                <div class="drawing-section-title">
                  <i class="el-icon-paperclip" /> 本行设计图纸与技术附件
                  <span class="title-sub-count">共 {{ lineFiles(row).length }} 份</span>
                </div>
                <el-table :data="lineFiles(row)" border size="mini" empty-text="暂无图纸或技术附件" class="line-inline-file-table">
                  <el-table-column prop="file_name" label="文件名称" min-width="180" show-overflow-tooltip>
                    <template slot-scope="scope">
                      <i class="el-icon-document text-muted" /> {{ scope.row.file_name }}
                    </template>
                  </el-table-column>
                  <el-table-column label="版本" width="68" align="center"><template slot-scope="scope">V{{ scope.row.version_no || 1 }}</template></el-table-column>
                  <el-table-column label="说明" min-width="130" show-overflow-tooltip>
                    <template slot-scope="scope">{{ scope.row.remark || statusText(scope.row.file_type) }}</template>
                  </el-table-column>
                  <el-table-column label="文件大小" width="92" align="right">
                    <template slot-scope="scope"><span class="font-mono text-muted">{{ fileSize(scope.row.file_size) }}</span></template>
                  </el-table-column>
                  <el-table-column label="上传信息" min-width="150" show-overflow-tooltip>
                    <template slot-scope="scope">{{ scope.row.uploaded_by || '-' }} / {{ date(scope.row.uploaded_at) }}</template>
                  </el-table-column>
                  <el-table-column label="操作" width="136" align="center">
                    <template slot-scope="scope">
                      <el-button v-if="scope.row.attachment_id && previewable(scope.row)" type="text" size="mini" @click="previewAttachment(scope.row)">预览</el-button>
                      <el-button v-if="scope.row.attachment_id && scope.row.can_download !== false" type="text" size="mini" @click="downloadAttachment(scope.row)">下载</el-button>
                      <el-button v-if="canDeleteAttachment(scope.row)" type="text" size="mini" class="danger-link" @click="deleteAttachment(scope.row)">删除</el-button>
                      <span v-if="!scope.row.attachment_id" class="text-muted">-</span>
                    </template>
                  </el-table-column>
                </el-table>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="行号" width="55" align="center">
          <template slot-scope="{row, $index}"><span class="line-index">{{ row.line_no || ($index + 1) }}</span></template>
        </el-table-column>
        <el-table-column prop="product_name" label="产品名称" width="155" show-overflow-tooltip>
          <template slot-scope="{row}">
            <span class="product-title">{{ row.product_name }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="sku_name" label="SKU规格" width="145" show-overflow-tooltip>
          <template slot-scope="{row}">
            <span class="sku-subtitle">{{ row.sku_name }}</span>
          </template>
        </el-table-column>
        <el-table-column label="系统物料编码" width="120" show-overflow-tooltip>
          <template slot-scope="{row}">
            <span v-if="lineItem(row).status === 'matched'" class="font-mono item-code-text">{{ lineItem(row).item_code }}</span>
            <el-tag v-else-if="lineItem(row).status === 'not_required'" size="mini" type="info">无需 Item</el-tag>
            <el-tag v-else size="mini" type="danger">Item 异常</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="系统物料名称" width="140" show-overflow-tooltip>
          <template slot-scope="{row}">
            <span v-if="lineItem(row).status === 'matched'">{{ lineItem(row).item_name }}</span>
            <span v-else-if="lineItem(row).status === 'not_required'" class="text-muted">—</span>
            <span v-else class="item-abnormal">{{ lineItem(row).message }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="order_qty" label="订单数量" width="88" align="left">
          <template slot-scope="{row}">
            <b class="font-mono">{{ row.order_qty }}</b>
          </template>
        </el-table-column>
        <el-table-column label="销售单价" width="100" align="left">
          <template slot-scope="{row}">
            <span class="font-mono price-cell">{{ Number(row.unit_price) < 0 ? '-' : '' }}¥{{ money(Math.abs(Number(row.unit_price || 0))) }}<small v-if="row.commercial_role === 'gift'" class="text-muted"> 赠送</small></span>
          </template>
        </el-table-column>
        <el-table-column label="小计金额" width="115" align="left">
          <template slot-scope="{row}">
            <b class="font-mono amount-cell" :style="lineAmount(row) < 0 ? 'color:#dc2626' : ''">{{ lineAmount(row) < 0 ? '-' : '' }}¥{{ money(Math.abs(lineAmount(row))) }}</b>
          </template>
        </el-table-column>
        <el-table-column label="已发" width="64" align="right">
          <template slot-scope="{row}"><span class="font-mono">{{ lineQty(row, 'shipped_qty') }}</span></template>
        </el-table-column>
        <el-table-column label="待发" width="64" align="right">
          <template slot-scope="{row}"><b class="font-mono">{{ lineQty(row, 'pending_fulfillment_qty') }}</b></template>
        </el-table-column>
        <el-table-column label="已锁" width="64" align="right">
          <template slot-scope="{row}"><span class="font-mono">{{ lineQty(row, 'locked_inventory_qty') }}</span></template>
        </el-table-column>
        <el-table-column label="待补" width="86" align="right">
          <template slot-scope="{row}"><span class="font-mono orange">{{ lineShortText(row) }}</span></template>
        </el-table-column>
        <el-table-column label="配置摘要" min-width="180">
          <template slot-scope="{row}">
            <div class="config-tags-cell">
              <el-tag v-if="row.is_customized" size="mini" effect="plain">定制</el-tag>
              <el-tag v-if="row.is_special_customized" size="mini" type="danger" effect="plain">特殊定制</el-tag>
              <el-tag v-if="row.electric" size="mini" type="info" effect="plain">{{ row.electric }}</el-tag>
              <el-tag v-if="row.need_pump === true" size="mini" type="success" effect="plain">原水泵: 需要</el-tag>
              <el-tag v-else-if="row.need_pump === false" size="mini" type="info" effect="plain">原水泵: 不需要</el-tag>
              <el-tag v-for="(cut,index) in ((row.configuration_snapshot || {}).cut_requirements || [])" :key="`${cut.component_item_id}-${index}`" size="mini" type="warning" effect="plain">{{ cut.component_item_name }} {{ Number(cut.cut_length_mm).toLocaleString('zh-CN') }}mm × {{ Number(cut.piece_qty).toLocaleString('zh-CN') }}段</el-tag>
              <span v-if="!row.is_customized && !row.is_special_customized && !row.electric && !row.need_pump && row.need_pump !== false && !((row.configuration_snapshot || {}).cut_requirements || []).length" class="text-muted">标准配置</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="图纸附件" width="84" align="center">
          <template slot-scope="{row}">
            <button
              type="button"
              class="drawing-count-badge is-clickable"
              :class="{ 'has-files': lineFiles(row).length > 0 }"
              title="点击展开/收起本行图纸与技术附件"
              @click.stop="toggleLineDrawingExpand(row)"
            >
              <i v-if="lineFiles(row).length > 0" class="el-icon-paperclip" />
              {{ lineFiles(row).length }}
              <i :class="isLineDrawingExpanded(row) ? 'el-icon-arrow-up' : 'el-icon-arrow-down'" class="drawing-badge-arrow" />
            </button>
          </template>
        </el-table-column>
        <el-table-column label="备货方式" width="92" align="center">
          <template slot-scope="{row}">
            <el-tag size="mini" :type="statusTag(row.fulfillment_type)">{{ row.is_purchase_fulfilled && row.fulfillment_type === 'mixed' ? '库存+外购' : statusText(row.fulfillment_type) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="remark" label="行备注" min-width="110" show-overflow-tooltip>
          <template slot-scope="{row}">
            <span :class="{ 'text-muted': !row.remark }">{{ row.remark || '-' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="处理状态" width="100" align="center">
          <template slot-scope="{row}">
            <el-tag size="mini" type="info" effect="plain" :title="row.line_status === 'fulfillment_blocked' ? `确认时状态：${statusText(row.line_status)}` : undefined">{{ processingStatusText(row) }}</el-tag>
          </template>
        </el-table-column>
      </el-table>
      <div class="order-lines-total">
        <span>运费 ¥{{ money(order.freight_amount) }}</span>
        <span>合计 <b>¥{{ money(order.total_amount) }}</b></span>
      </div>
    </section>

    <!-- 合同附件（公共整单附件，合入订单信息） -->
    <section ref="contractAttachmentSection" class="detail-card attachments-invoice-card">
      <div class="card-head is-clickable" @click="attachmentsOpen = !attachmentsOpen">
        <div class="card-title">
          <i class="el-icon-paperclip" /> 附件与开票
          <span class="title-sub-count">合同附件 {{ contractFiles.length }} 个</span>
          <span v-if="canViewInvoice" class="title-sub-count">开票申请 {{ invoiceRequestCount }} 笔</span>
          <span v-if="invoicePendingCount" class="title-sub-count title-sub-count--orange">{{ invoicePendingCount }} 笔待开票</span>
        </div>
        <el-button type="text" size="mini">{{ attachmentsOpen ? '收起' : '展开' }}<i :class="attachmentsOpen ? 'el-icon-arrow-up' : 'el-icon-arrow-down'" /></el-button>
      </div>
      <div v-show="attachmentsOpen" class="attachments-invoice-body">
      <h4 class="sub-section-title">合同附件</h4>
      <div v-if="contractFiles.length" class="compact-file-list">
        <div v-for="file in contractFiles" :key="file.attachment_id || file.file_hash || file.file_name" class="compact-file-row">
          <div class="compact-file-main">
            <b :title="file.file_name"><i class="el-icon-document" /> {{ file.file_name }}</b>
            <span>{{ statusText(file.file_type || 'other') }} · V{{ file.version_no || 1 }} · {{ fileSize(file.file_size) }} · {{ file.uploaded_by || '-' }} · {{ date(file.uploaded_at) }} · {{ statusText(file.status || 'active') }}</span>
          </div>
          <div class="compact-file-actions">
            <el-button v-if="file.attachment_id && previewable(file)" type="text" size="mini" @click="previewAttachment(file)">预览</el-button>
            <el-button v-if="file.attachment_id && file.can_download !== false" type="text" size="mini" @click="downloadAttachment(file)">下载</el-button>
            <el-button v-if="canDeleteAttachment(file)" type="text" size="mini" class="danger-link" @click="deleteAttachment(file)">删除</el-button>
            <span v-if="!file.attachment_id" class="text-muted">历史记录</span>
          </div>
        </div>
      </div>
      <div v-else class="contract-empty-inline text-muted">
        <i class="el-icon-folder-opened" /> 暂无合同附件
      </div>
      <div v-if="canViewInvoice" ref="invoiceSection" class="order-invoice-inline">
        <h4 class="sub-section-title">开票信息</h4>
        <sales-order-invoice-panel v-if="order.id" ref="invoicePanel" :order-id="order.id" @loaded="onInvoiceLoaded" />
      </div>
      </div>
    </section>

    <!-- 审核进度条 (存在审核任务时展开) -->
    <section v-if="order.approval_task" class="detail-card approval-progress-strip">
      <div class="approval-status-block">
        <h3><i class="el-icon-s-check" /> 审核状态与进度</h3>
        <div>
          <span>审核状态：<el-tag size="mini" :type="approvalStatusTag(order.approval_task.task_status)">{{ approvalStatusText(order.approval_task.task_status) }}</el-tag></span>
          <span>Candidate：<b>V{{ (order.approval_task.business_snapshot && order.approval_task.business_snapshot.candidate_version) || '-' }}</b></span>
          <span>审核结果：<b>{{ order.approval_task.task_status === 'APPROVED' ? '已通过' : order.approval_task.task_status === 'REJECTED' ? '已驳回' : '—' }}</b></span>
        </div>
        <p>本页仅展示审核状态、结果与进度，不在订单内执行审核。</p>
      </div>
      <div class="approval-node-progress">
        <div v-for="(node, index) in orderApprovalSteps" :key="node.key" class="approval-node" :class="node.state">
          <i v-if="index" class="connector" />
          <em><i v-if="node.state==='done'" class="el-icon-check" /><template v-else>{{ index+1 }}</template></em>
          <b>{{ node.name }}</b>
        </div>
      </div>
      <div class="approval-current">
        <span>当前等待：</span>
        <b>{{ orderApprovalCurrent }}</b>
        <el-button type="text" size="mini" @click="$router.push(`/approvals/tasks/${order.approval_task.id}`)">
          进入审核中心 <i class="el-icon-right" />
        </el-button>
      </div>
    </section>

        </el-tab-pane>
        <el-tab-pane v-if="$can('sales_order.view')" name="production">
          <span slot="label"><i class="el-icon-s-order" /> 生产与发货进度</span>
          <order-progress v-if="activeTab === 'production' && order.id" ref="orderProgress" :order-id="order.id" @show-work-orders="loadWorkOrders(loadSequence)">
            <template slot="work-orders">
            <el-table v-loading="workOrderLoading" :data="workOrders" size="small" border empty-text="这张订单没有生产工单（全部用库存、外购或服务）">
              <el-table-column v-if="$can('production.work_order.view')" label="工单号" min-width="150"><template slot-scope="{ row }"><el-button type="text" class="font-mono" :disabled="!$can('production.work_order.view')" @click="$router.push(`/production/work-orders/${row.id}`)">{{ row.work_order_no }}</el-button></template></el-table-column>
              <el-table-column label="行号" width="56" align="center"><template slot-scope="{ row }">{{ row.sales_order_line_no || '-' }}</template></el-table-column>
              <el-table-column label="产品" min-width="160"><template slot-scope="{ row }">{{ row.product_name || row.item_name }} × {{ Number(row.planned_qty) }} {{ row.unit_name || '' }}</template></el-table-column>
              <el-table-column label="状态" width="90"><template slot-scope="{ row }"><el-tag size="mini" :type="row.status === 'cancelled' ? 'info' : (row.status === 'paused' ? 'warning' : 'success')">{{ row.status_label }}</el-tag></template></el-table-column>
              <el-table-column label="每道工序完成几台" min-width="220"><template slot-scope="{ row }"><span v-for="(p, i) in row.progress" :key="i" class="wo-step">{{ p.operation_name }} {{ Number(p.completed_qty) }}/{{ Number(p.planned_qty) }}</span><span v-if="!row.progress.length">-</span></template></el-table-column>
              <el-table-column label="计划完工" width="100"><template slot-scope="{ row }">{{ row.planned_finish_date || '-' }}</template></el-table-column>
              <el-table-column label="卡点" min-width="150"><template slot-scope="{ row }"><span :class="row.stuck_reason ? 'wo-stuck' : ''">{{ row.stuck_reason || '正常' }}</span></template></el-table-column>
            </el-table>
            </template>
          </order-progress>
          <sales-order-change-labor v-if="activeTab === 'production' && order.id && $can('production.work_order.view')" ref="changeLabor" :order-id="order.id" />
        </el-tab-pane>
        <el-tab-pane v-if="$can('sales_order.view')" label="采购关联" name="finance">
          <div class="tab-pane-content"><sales-order-finance-panel v-if="activeTab === 'finance' && order.id" :order-id="order.id" /></div>
        </el-tab-pane>

        <!-- 售后：本订单售后单 + 可售后商品 -->
        <el-tab-pane v-if="$can('aftersales.view')" name="aftersales">
          <span slot="label"><i class="el-icon-service" /> 售后<template v-if="order.aftersales_open_count > 0"><em class="tab-badge-num">{{ order.aftersales_open_count }}</em></template></span>
          <div class="tab-pane-content">
            <template v-if="!hasShippedQty && !aftersales.rows.length">
              <div class="as-empty">订单尚未发货，暂无可售后商品。未发货的修改走订单变更。</div>
            </template>
            <template v-else>
              <div class="section-title-row" style="margin-bottom:10px">
                <div><h4>本订单售后</h4></div>
                <el-button v-if="canCreateAftersales" size="small" type="success" plain icon="el-icon-plus" @click="$refs.caseForm.open()">新建售后单</el-button>
              </div>
              <div v-if="!aftersales.loading && !aftersales.rows.length" class="as-empty">暂无售后记录</div>
              <el-table v-else v-loading="aftersales.loading" :data="aftersales.rows" border size="mini">
                <el-table-column prop="case_no" label="售后单号" width="150"><template slot-scope="{ row }"><span class="font-mono">{{ row.case_no }}</span></template></el-table-column>
                <el-table-column label="问题" min-width="140"><template slot-scope="{ row }">{{ asIssueLabel(row.issue_type) }}</template></el-table-column>
                <el-table-column label="问题描述" min-width="200"><template slot-scope="{ row }"><div style="white-space:pre-wrap;word-break:break-word">{{ row.description || '—' }}</div></template></el-table-column>
                <el-table-column prop="items_summary" label="涉及商品" min-width="160" show-overflow-tooltip />
                <el-table-column label="处理" width="100"><template slot-scope="{ row }">
                  <el-tag v-for="t in row.action_types" :key="t" size="mini" style="margin-right:4px;color:#7c3aed;border-color:#ddd6fe;background:#f5f3ff">{{ asActionLabel(t) }}</el-tag>
                  <span v-if="!(row.action_types || []).length" class="muted">未定</span>
                </template></el-table-column>
                <el-table-column label="状态" width="86"><template slot-scope="{ row }"><el-tag size="mini" :type="asStatusTag(row.status)">{{ asStatusLabel(row.status) }}</el-tag></template></el-table-column>
                <el-table-column prop="owner_name" label="负责人" width="80" />
                <el-table-column label="创建时间" width="140"><template slot-scope="{ row }">{{ date(row.created_at) }}</template></el-table-column>
                <el-table-column label="操作" width="70"><template slot-scope="{ row }"><el-button type="text" size="mini" @click="$router.push(`/sales/aftersales/${row.id}/detail`)">查看</el-button></template></el-table-column>
              </el-table>

              <div class="section-title-row" style="margin:18px 0 8px"><div><h4>可售后商品</h4><p>只列已发货的数量；已在进行中售后里的设备会标出来。</p></div></div>
              <el-table v-loading="eligible.loading" :data="eligible.rows" border size="mini" empty-text="暂无可售后商品">
                <el-table-column prop="line_no" label="行号" width="56" />
                <el-table-column label="商品 / SKU" min-width="170"><template slot-scope="{ row }">{{ row.product_name || row.item_name }}<span v-if="row.sku_name" class="muted"> / {{ row.sku_name }}</span></template></el-table-column>
                <el-table-column prop="shipped_qty" label="已发货" width="76" align="right" />
                <el-table-column prop="reshipped_qty" label="已补发" width="76" align="right" />
                <el-table-column prop="returned_qty" label="退货占用" width="86" align="right" />
                <el-table-column prop="in_aftersales_qty" label="售后中" width="76" align="right" />
                <el-table-column prop="completed_aftersales_qty" label="已完成售后" width="94" align="right" />
                <el-table-column prop="available_qty" label="可售后" width="76" align="right" />
                <el-table-column label="序列号" min-width="190"><template slot-scope="{ row }">
                  <template v-if="row.serial_tracked"><div v-for="s in row.serials" :key="s.inventory_serial_id" class="font-mono" style="line-height:20px">{{ s.serial_no }} <el-tag v-if="s.in_case" size="mini" type="warning">售后中</el-tag><el-tag v-else-if="s.completed_case" size="mini" type="info">已完成售后</el-tag></div></template>
                  <span v-else class="muted">不管理序列号</span>
                </template></el-table-column>
              </el-table>
            </template>
          </div>
        </el-tab-pane>

        <!-- Tab 3: 操作日志与版本记录 -->
        <el-tab-pane name="logs">
          <span slot="label">
            <i class="el-icon-time" /> 操作日志与版本
            <span v-if="(order.logs || []).length" class="tab-badge-num">{{ (order.logs || []).length }}</span>
          </span>
          <div ref="logsSection" class="tab-pane-content">
            <div class="log-grid">
              <section class="log-timeline-section">
                <div class="pane-sub-header"><h4>操作日志</h4><span class="text-muted">共 {{ (order.logs || []).length }} 条记录</span></div>
                <div class="sales-order-operation-log__body">
                  <el-timeline>
                    <el-timeline-item v-for="log in operationLogs" :key="log.id" :timestamp="date(log.created_at)">
                      <div class="timeline-log-title">
                        <b>{{ statusText(log.action) }}</b>
                        <span v-if="log.operator" class="log-operator-tag">{{ log.operator }}</span>
                      </div>
                      <p class="timeline-log-desc">{{ businessText(log.content) }}</p>
                    </el-timeline-item>
                  </el-timeline>
                </div>
              </section>
              <section class="version-table-section">
                <div class="pane-sub-header"><h4>版本快照记录</h4><span class="text-muted">共 {{ (order.versions || []).length }} 次版本</span></div>
                <div class="sales-order-version-list__body">
                  <el-table :data="orderVersions" border size="mini" :max-height="320">
                    <el-table-column prop="version_no" label="版本" width="60" align="center"><template slot-scope="{row}"><b class="font-mono">V{{ row.version_no }}</b></template></el-table-column>
                    <el-table-column label="变更类型" min-width="90" show-overflow-tooltip><template slot-scope="{row}">{{ statusText(row.change_type) }}</template></el-table-column>
                    <el-table-column prop="operator" label="操作人" width="75" show-overflow-tooltip />
                    <el-table-column label="时间" min-width="120" show-overflow-tooltip><template slot-scope="{row}">{{ date(row.created_at) }}</template></el-table-column>
                  </el-table>
                </div>
              </section>
            </div>
          </div>
        </el-tab-pane>

        <!-- Tab 4: 变更历史与审核 -->
        <el-tab-pane name="changes">
          <span slot="label">
            <i class="el-icon-refresh" /> 变更历史与审核
            <span v-if="changes.total || candidateHistory.length" class="tab-badge-num">{{ changes.total + candidateHistory.length }}</span>
          </span>
          <div ref="changeHistory" class="tab-pane-content">
            <div class="change-history-block">
              <div class="section-title-row">
                <div>
                  <h4>正式订单变更历史</h4>
                  <p>变更事实与操作日志分开留存；历史版本不会被后续变更覆盖。</p>
                </div>
              </div>
              <el-table v-loading="changeLoading" :data="changes.rows" border size="mini" empty-text="暂无订单变更记录">
                <el-table-column label="版本" width="76" align="center"><template slot-scope="{row}"><b class="font-mono">V{{ changeVersion(row) }}</b></template></el-table-column>
                <el-table-column prop="change_no" label="变更单号" min-width="150" />
                <el-table-column label="变更时间" min-width="154"><template slot-scope="{row}">{{ date(row.applied_at || row.created_at) }}</template></el-table-column>
                <el-table-column prop="operator" label="操作人" width="96" />
                <el-table-column prop="reason" label="变更原因" min-width="220" show-overflow-tooltip />
                <el-table-column label="金额变化" min-width="126" align="right"><template slot-scope="{row}">{{ changeAmountText(row) }}</template></el-table-column>
                <el-table-column label="状态" width="86" align="center"><template><el-tag size="mini" type="success">已生效</el-tag></template></el-table-column>
                <el-table-column label="操作" width="82" align="center"><template slot-scope="{row}"><el-button type="text" size="mini" @click="openChange(row)">查看详情</el-button></template></el-table-column>
              </el-table>
              <div v-if="changes.total" class="order-change-pagination">
                <span>共 {{ changes.total }} 条记录</span>
                <el-pagination background small layout="prev, pager, next" :current-page="changes.page" :page-size="changes.per_page" :total="changes.total" @current-change="loadChanges" />
              </div>
            </div>

            <div class="candidate-history-block">
              <div class="candidate-history-title">
                <h4>变更审核 Candidate 历史</h4>
                <span>Candidate 在审核通过前不会修改正式订单。</span>
              </div>
              <el-table :data="candidateHistory" border size="mini" empty-text="暂无变更审核记录">
                <el-table-column label="Candidate" width="126"><template slot-scope="{row}">V{{ row.candidate_version }} / {{ row.candidate_no }}</template></el-table-column>
                <el-table-column label="基准版本" width="86" align="center"><template slot-scope="{row}">V{{ row.base_version }}</template></el-table-column>
                <el-table-column prop="change_reason" label="变更原因" min-width="180" show-overflow-tooltip />
                <el-table-column prop="submitted_by" label="提交人" width="92" />
                <el-table-column label="提交时间" min-width="142"><template slot-scope="{row}">{{ date(row.submitted_at) }}</template></el-table-column>
                <el-table-column label="审核状态" width="96" align="center"><template slot-scope="{row}"><el-tag size="mini" :type="approvalStatusTag(row.candidate_status)">{{ approvalStatusText(row.candidate_status) }}</el-tag></template></el-table-column>
                <el-table-column label="审核进度" min-width="150" show-overflow-tooltip><template slot-scope="{row}">{{ candidateApprovalProgress(row) }}</template></el-table-column>
                <el-table-column label="操作" width="84" align="center"><template slot-scope="{row}"><el-button type="text" size="mini" @click="openCandidate(row)">查看详情</el-button></template></el-table-column>
              </el-table>
            </div>
          </div>
        </el-tab-pane>
      </el-tabs>
    </div>

    <!-- 附件预览弹窗组件 (保持测试契约) -->
    <sales-order-attachment-preview-dialog :visible.sync="previewVisible" :file="previewFile" />

    <!-- 订单变更详情弹窗 -->
    <el-dialog title="订单变更详情" :visible.sync="changeDetailVisible" width="900px" class="order-change-detail-dialog" append-to-body>
      <div v-if="selectedChange" class="change-detail-readonly">
        <div class="change-detail-meta">
          <span><b>变更单号</b>{{ selectedChange.change_no }}</span>
          <span><b>原版本</b>V{{ changeVersion(selectedChange) - 1 }}</span>
          <span><b>新版本</b>V{{ changeVersion(selectedChange) }}</span>
          <span><b>操作人</b>{{ selectedChange.operator || '-' }}</span>
        </div>
        <div class="change-detail-reason">
          <b>变更原因</b>
          <p>{{ selectedChange.reason }}</p>
        </div>
        <el-table :data="changeDiffRows(selectedChange)" border size="mini">
          <el-table-column prop="label" label="变更字段" min-width="130" />
          <el-table-column prop="before" label="修改前" min-width="150" show-overflow-tooltip />
          <el-table-column prop="after" label="修改后" min-width="150" show-overflow-tooltip />
          <el-table-column prop="business_impact_text" label="业务影响" min-width="150" show-overflow-tooltip />
          <el-table-column label="生效方式" width="92"><template slot-scope="{row}">{{ row.immediate_effect ? '立即生效' : '审核后生效' }}</template></el-table-column>
        </el-table>
        <el-alert type="warning" :closable="false" show-icon :title="changeEffectText(selectedChange)" />
      </div>
      <span slot="footer"><el-button @click="changeDetailVisible=false">关闭</el-button></span>
    </el-dialog>

    <!-- Candidate 审核详情弹窗 -->
    <el-dialog title="Candidate 审核详情" :visible.sync="candidateDetailVisible" width="900px" class="order-change-detail-dialog" append-to-body>
      <div v-if="selectedCandidate" class="change-detail-readonly">
        <div class="change-detail-meta">
          <span><b>Candidate</b>{{ selectedCandidate.candidate_no }}</span>
          <span><b>基准版本</b>V{{ selectedCandidate.base_version }}</span>
          <span><b>候选版本</b>V{{ selectedCandidate.candidate_version }}</span>
          <span><b>状态</b>{{ approvalStatusText(selectedCandidate.candidate_status) }}</span>
        </div>
        <div class="change-detail-reason">
          <b>变更原因</b>
          <p>{{ selectedCandidate.change_reason || '-' }}</p>
        </div>
        <el-table :data="selectedCandidate.structured_diffs || []" border size="mini">
          <el-table-column prop="label" label="变更字段" min-width="130" />
          <el-table-column prop="before" label="修改前" min-width="150" show-overflow-tooltip />
          <el-table-column prop="after" label="修改后" min-width="150" show-overflow-tooltip />
          <el-table-column prop="business_impact_text" label="业务影响" min-width="160" show-overflow-tooltip />
          <el-table-column label="审核要求" min-width="130"><template slot-scope="{row}">{{ (row.approval_requirements || []).map(approvalTypeText).join(' + ') || '无需审核' }}</template></el-table-column>
        </el-table>
        <el-alert v-if="selectedCandidate.conflict_reason" type="error" :closable="false" show-icon :title="selectedCandidate.conflict_reason" />
      </div>
      <span slot="footer"><el-button @click="candidateDetailVisible=false">关闭</el-button></span>
    </el-dialog>
  </section>
</template>

<script>
import { erpDateTime } from '../../../utils/dateTime'
import cachedPageRoute from '@/utils/cachedPageRoute'
import { confirmSalesOrder, formalConfirmSalesOrder, previewShortCloseSalesOrder, shortCloseSalesOrder, deleteSalesOrderAttachment, deleteSalesOrderDraft, downloadSalesOrderAttachment, getSalesOrder, listSalesOrderChanges, lockSalesOrderInventory, adjustSalesOrderPriority } from '@/api/erp/sales'
import SalesOrderCancellationDialog from '@/components/sales/SalesOrderCancellationDialog.vue'
import CancellationDispositions from '@/components/production/CancellationDispositions.vue'
import SalesOrderChangeLabor from '@/components/sales/SalesOrderChangeLabor.vue'
import SalesOrderAttachmentPreviewDialog from '@/components/sales/SalesOrderAttachmentPreviewDialog.vue'
import SalesOrderFinancePanel from '@/components/sales/SalesOrderFinancePanel.vue'
import SalesOrderInvoicePanel from '@/components/sales/SalesOrderInvoicePanel.vue'
import OrderProgress from './components/OrderProgress.vue'
import AftersalesCaseForm from '../aftersales/AftersalesCaseForm.vue'
import { listOrderAftersales, getAftersalesEligible } from '@/api/erp/aftersales'
import { promptAgreedDeposit } from '@/utils/salesAgreedDeposit'
import { salesOrderOriginalNo } from '@/utils/salesOrderOriginalNo'
import { statusTag, statusText, shipmentStatusText, orderStatusText, orderStatusTag } from '@/utils/erpStatus'
import { listSalesOrderWorkOrders } from '@/api/erp/production'

export default {
  mixins: [cachedPageRoute],
  components: { SalesOrderCancellationDialog, CancellationDispositions, SalesOrderChangeLabor, SalesOrderAttachmentPreviewDialog, SalesOrderFinancePanel, SalesOrderInvoicePanel, OrderProgress, AftersalesCaseForm },
  data: () => ({
    cancelDialogVisible: false,
    invoicePendingCount: 0,
    invoiceRequestCount: 0,
    attachmentsOpen: false,
    activeTab: 'info',
    order: {},
    workOrders: [],
    workOrderLoading: false,
    previewVisible: false,
    previewFile: null,
    changeLoading: false,
    changes: { rows: [], total: 0, page: 1, per_page: 10 },
    selectedChange: null,
    changeDetailVisible: false,
    selectedCandidate: null,
    candidateDetailVisible: false,
    inventoryLockVisible: false,
    inventoryLockBusy: false,
    inventoryLockLoading: false,
    inventoryLockError: '',
    inventoryLockSequence: 0,
    cancelling: false,
    shortClose: { visible: false, loading: false, saving: false, preview: null, reason: '' },
    depositSaving: false,
    priority: { visible: false, saving: false, value: 3, reason: '' },
    priorityOptions: [{ value: 1, label: '特急' }, { value: 2, label: '加急' }, { value: 3, label: '普通' }, { value: 4, label: '可延后' }],
    expandedLineRowKeys: [],
    inventoryLockPending: null,
    inventoryLockStorageKey: '',
    aftersales: { rows: [], loading: false, loaded: false },
    eligible: { rows: [], loading: false },
    loadSequence: 0
  }),
  computed: {
    canViewInvoice() { return this.$can('sales_order.view') },
    hasShippedQty() { return (this.order.lines || []).some(line => Number(line.shipped_qty || 0) > 0) },
    canCreateAftersales() { return this.$can('aftersales.create') && this.hasShippedQty && this.order.order_status !== 'cancelled' },
    canRequestInvoice() { return this.canViewInvoice && this.$can('sales_order.invoice_request') && ['confirmed', 'closed'].includes(this.order.order_status) && !!this.order.customer_id },
    canShortClose() {
      if (this.order.order_status !== 'confirmed' || this.order.short_closed_at || !this.$can('sales_order.short_close')) return false
      return (this.order.lines || []).some(line => Number(line.shipped_qty || 0) > 0)
    },
    hasStageAction() {
      const actions = this.order.allowed_actions || {}
      return !!(actions.submit_confirmation || actions.production_confirmation)
    },
    moreActions() {
      const actions = this.order.allowed_actions || {}
      const list = []
      if (actions.adjust_priority) list.push({ key: 'priority', label: '调整优先级' })
      if (this.canShortClose) list.push({ key: 'short_close', label: '短交关闭' })
      const danger = []
      if (actions.cancel) danger.push({ key: 'cancel', label: '取消订单', danger: true })
      if (actions.delete_draft) danger.push({ key: 'delete_draft', label: '删除草稿', danger: true })
      if (danger.length && list.length) danger[0].divided = true
      return [...list, ...danger]
    },
    originalOrderNo() {
      return salesOrderOriginalNo(this.order)
    },
    platformPayment() {
      return this.order.funding_status?.platform_payment || null
    },
    receiptProgress() {
      const f = this.order.funding_status || {}
      const target = Number(f.net_receivable_amount != null ? f.net_receivable_amount : (f.contract_amount || 0))
      const received = Number(f.net_received_amount || 0)
      const percent = target > 0 ? Math.min(100, Math.max(0, (received / target) * 100)) : 0
      let text = f.shipment_funds_satisfied ? '款项已结清' : `尾款未齐，暂不能发货（未收 ¥${this.money(f.outstanding_amount)}）`
      if (f.platform_payment) text = '平台已付，满足发货款项要求'
      if (f.platform_payment_required) text = f.shipment_block_message
      return { target, percent, text, done: received >= target }
    },
    shipmentProgress() {
      const lines = (this.fulfillmentQuantities.lines || []).filter(line => line.requires_delivery !== false && Number(line.order_qty || 0) > 0 && (line.pending_fulfillment_qty > 0 || line.shipped_qty > 0))
      const shipped = lines.reduce((sum, line) => sum + Number(line.shipped_qty || 0), 0)
      const pending = lines.reduce((sum, line) => sum + Number(line.pending_fulfillment_qty || 0), 0)
      const production = lines.reduce((sum, line) => sum + Number(line.pending_production_qty || 0), 0)
      const purchase = lines.reduce((sum, line) => sum + Number(line.pending_purchase_qty || 0), 0)
      const total = shipped + pending
      const parts = []
      if (production > 0) parts.push(`生产待补 ${this.numberText(production)}`)
      if (purchase > 0) parts.push(`外购待补 ${this.numberText(purchase)}`)
      return { shipped, total, percent: total > 0 ? Math.min(100, (shipped / total) * 100) : 0,
        text: pending <= 0 ? '已全部发出' : (parts.join(' · ') || `待发 ${this.numberText(pending)}，库存已备`) }
    },
    dispatchRecords() { return this.order.dispatch_records || [] },
    dispatchPackageCount() { return this.dispatchRecords.reduce((sum, row) => sum + Number(row.package_count || 0), 0) },
    shortCloseLines() {
      return ((this.shortClose.preview && this.shortClose.preview.lines) || []).filter(line => line.is_physical)
    },
    shortCloseOverpaid() {
      const preview = this.shortClose.preview || {}
      if (preview.overpaid_amount !== undefined) return Number(preview.overpaid_amount) > 0
      return Boolean(preview.has_overpaid)
    },
    shippingCarrierName() {
      const name = this.order.default_carrier_name_snapshot || (this.order.shipping_snapshot && (this.order.shipping_snapshot.default_carrier_name || this.order.shipping_snapshot.carrier_name))
      if (name) return name
      if (this.order.carrier_id) return `承运商 (ID: ${this.order.carrier_id})`
      return '-'
    },
    orderSourceText() {
      return statusText(this.order.order_source)
    },
    totalLineCount() {
      return (this.order.lines || []).length
    },
    totalOrderQty() {
      // 扣减项、费用不是商品件数，不计入总数量
      const total = (this.order.lines || []).filter(line => !['deduction', 'fee'].includes(line.line_type)).reduce((sum, line) => sum + Number(line.order_qty || 0), 0)
      return this.numberText(total)
    },
    totalAttachmentsCount() {
      let count = this.contractFiles.length
      ;(this.order.lines || []).forEach(line => {
        count += this.lineFiles(line).length
      })
      return count
    },
    totalLineFilesCount() {
      return (this.order.lines || []).reduce((sum, line) => sum + this.lineFiles(line).length, 0)
    },
    linesWithFilesCount() {
      return (this.order.lines || []).filter(line => this.lineFiles(line).length > 0).length
    },
    allFileLinesExpanded() {
      const fileLineKeys = (this.order.lines || [])
        .filter(line => this.lineFiles(line).length > 0 && line.id != null)
        .map(line => line.id)
      if (!fileLineKeys.length) return false
      return fileLineKeys.every(id => this.expandedLineRowKeys.some(key => String(key) === String(id)))
    },
    contractFiles() {
      return this.mergeAttachmentFiles(
        this.parseContractAttachments(this.order.contract_attachments),
        (this.order.attachments || []).map(this.mapAttachment)
      )
    },
    // 生产审核以受影响主子工单实际工时为界，财务仍按金额和结算影响判断。
    stageTip() {
      const actions = this.order.allowed_actions || {}
      if (this.order.order_status === 'draft') return { type: 'info', icon: 'el-icon-edit-outline', text: '草稿阶段：订单可随意修改。核对无误后点击“确认订单”，确认不会生成工单。' }
      if (this.order.order_status !== 'confirmed') return null
      if (actions.production_released) return { type: 'warning', icon: 'el-icon-lock', text: '可申请修改剩余未发部分，已发商品、配置、单价及退货事实保留。受影响主、子工单尚未开工免生产审核，已有工时须审核；金额及结算条件按财务规则审核。' }
      if (['pending', 'blocked'].includes(this.order.production_confirm_status)) return { type: 'success', icon: 'el-icon-s-flag', text: '核实期：订单已确认，尚未生成工单，金额及结算条件修改需财务审核，其他修改可直接保存。核实无误后，请点击“生产确认”生成待发布工单。' }
      if (this.order.production_confirm_status === 'confirmed') return { type: 'warning', icon: 'el-icon-lock', text: '生产确认完成：受影响主、子工单均未开工时免生产审核；已有实际工时的修改须审核，正式生效后由接单人处理原工单。金额及结算条件按财务规则审核。' }
      return null
    },
    orderTime() { return this.order.order_time || this.order.channel_ordered_at || this.order.order_date || this.order.created_at },
    operationLogs() { return [...(this.order.logs || [])].sort((a, b) => erpDateTime(b.created_at).localeCompare(erpDateTime(a.created_at)) || Number(b.id || 0) - Number(a.id || 0)) },
    orderVersions() { return [...(this.order.versions || [])].sort((a, b) => Number(b.version_no || 0) - Number(a.version_no || 0) || Number(b.id || 0) - Number(a.id || 0)) },
    currentVersion() { return Math.max(1, ...(this.order.versions || []).map(item => Number(item.version_no || 0))) },
    orderApprovalSteps() { const task=this.order.approval_task||{}; const steps=[{key:'submit',name:'发起提交',state:'done'},...(task.nodes||[]).map(n=>({key:n.node_key,name:n.node_name,state:n.node_status==='APPROVED'?'done':n.node_status==='PENDING'?'active':n.node_status==='REJECTED'?'failed':'wait'})),{key:'finish',name:'审核完成',state:task.task_status==='APPROVED'?'done':task.task_status==='REJECTED'?'failed':'wait'}]; return steps },
    orderApprovalCurrent() { const task=this.order.approval_task||{}; const node=(task.nodes||[]).find(n=>n.node_status==='PENDING'); return node ? node.node_name : (task.task_status==='APPROVED'?'审核完成':task.task_status==='REJECTED'?'已驳回':'—') },
    candidateHistory() { return [...(this.order.change_candidates || [])].sort((a, b) => Number(b.candidate_version || 0) - Number(a.candidate_version || 0)) },
    fulfillmentQuantities() { return this.order.fulfillment_quantities || { totals: {}, lines: [] } },
    lockLines() { return this.fulfillmentQuantities.lines || [] }
  },
  async created() {
    await this.load()
  },
  activated() { if (this.order.id) this.load() },
  watch: {
    'pageRoute.params.id'(next, previous) { if (next !== previous) this.load() },
    activeTab(tab) { if (tab === 'aftersales') this.loadAftersalesTab() }
  },
  methods: {
    async loadAftersalesTab() {
      if (!this.order.id || !this.$can('aftersales.view')) return
      this.aftersales.loading = true
      try {
        const { data } = await listOrderAftersales(this.order.id)
        this.aftersales.rows = data.data || []; this.aftersales.loaded = true
      } finally { this.aftersales.loading = false }
      this.eligible.loading = true
      try {
        const { data } = await getAftersalesEligible(this.order.id)
        this.eligible.rows = data.data || []
      } finally { this.eligible.loading = false }
    },
    onAftersalesSaved() { this.load(); if (this.activeTab === 'aftersales') this.loadAftersalesTab() },
    asIssueLabel: v => ({ quality: '质量故障', transport_damage: '运输破损', wrong_shipment: '错发漏发', installation: '安装问题', consultation: '使用咨询', other: '其他' })[v] || v || '—',
    asActionLabel: v => ({ refund: '退货退款', exchange: '换货', reship: '补发', compensation: '赔付记录', record: '仅记录' })[v] || v,
    asStatusLabel: v => ({ pending: '待受理', processing: '处理中', ready_to_close: '待关闭', closed: '已关闭', cancelled: '已取消' })[v] || v,
    asStatusTag: v => ({ pending: 'info', processing: 'warning', ready_to_close: '', closed: 'info', cancelled: 'info' })[v] || 'info',
    onInvoiceLoaded(info) {
      const requests = (info && info.requests) || []
      this.invoiceRequestCount = requests.length
      this.invoicePendingCount = requests.filter(row => row.status === 'pending').length
    },
    runMoreAction(key) {
      if (key === 'priority') this.openPriority()
      else if (key === 'short_close') this.openShortClose()
      else if (key === 'cancel') this.cancelOrder()
      else if (key === 'delete_draft') this.deleteDraft()
    },
    lineAmount(row) {
      const amount = row.amount_incl_tax != null ? row.amount_incl_tax : row.amount
      return amount != null ? Number(amount) : Number(row.order_qty || 0) * Number(row.unit_price || 0)
    },
    lineQty(row, key) {
      const q = this.lineLockQuantities(row)
      return q.requires_delivery === false || q[key] == null ? '—' : this.numberText(q[key])
    },
    lineShortText(row) {
      const q = this.lineLockQuantities(row)
      if (q.requires_delivery === false) return '—'
      const parts = []
      if (Number(q.pending_production_qty) > 0) parts.push(`生产 ${this.numberText(q.pending_production_qty)}`)
      if (Number(q.pending_purchase_qty) > 0) parts.push(`外购 ${this.numberText(q.pending_purchase_qty)}`)
      return parts.join(' / ') || '0'
    },
    openInvoiceRequest() {
      this.activeTab = 'info'
      this.attachmentsOpen = true
      this.$nextTick(() => {
        const panel = this.$refs.invoicePanel
        if (panel) panel.openCreate()
        const target = this.$refs.invoiceSection || this.$refs.tabSection
        if (target && target.scrollIntoView) target.scrollIntoView({ behavior: 'smooth', block: 'start' })
      })
    },
    isLineDrawingExpanded(row) {
      if (!row || row.id == null) return false
      return this.expandedLineRowKeys.some(key => String(key) === String(row.id))
    },
    lineLockQuantities(row) {
      if (!row || !row.id) return {}
      return (this.lockLines || []).find(item => item.sales_order_line_id === row.id) || {}
    },
    toggleLineDrawingExpand(row) {
      if (!row || row.id == null) return
      const exists = this.isLineDrawingExpanded(row)
      if (exists) {
        this.expandedLineRowKeys = this.expandedLineRowKeys.filter(key => String(key) !== String(row.id))
      } else {
        this.expandedLineRowKeys = [...this.expandedLineRowKeys, row.id]
      }
    },
    onLineExpandChange(row, expandedRows) {
      this.expandedLineRowKeys = (expandedRows || []).map(item => item.id).filter(id => id != null)
    },
    toggleAllLineDrawings() {
      if (this.allFileLinesExpanded) {
        this.expandedLineRowKeys = []
      } else {
        this.expandedLineRowKeys = (this.order.lines || [])
          .filter(line => this.lineFiles(line).length > 0 && line.id != null)
          .map(line => line.id)
      }
    },
    processingStatusText(row) {
      const original = statusText(row.line_status)
      if (row.line_status !== 'fulfillment_blocked') return original
      const quantities = this.lockLines.find(line => line.sales_order_line_id === row.id)
      if (!quantities || ['order_qty', 'shipped_qty'].some(key => quantities[key] == null || String(quantities[key]).trim() === '' || !Number.isFinite(Number(quantities[key])))) return original
      const effectiveQty = Number(quantities.order_qty)
      const shippedQty = Number(quantities.shipped_qty)
      if (effectiveQty <= 0 || shippedQty < 0) return original
      // 保留确认时标签，当前显示只采纳服务端实际数量。
      if (shippedQty >= effectiveQty) return '已发货'
      if (shippedQty > 0) return '部分发货'
      if (row.fulfillment_type !== 'production' || ['pending_production_qty', 'pending_purchase_qty', 'production_replenished_qty', 'locked_inventory_qty'].some(key => quantities[key] == null || String(quantities[key]).trim() === '' || !Number.isFinite(Number(quantities[key])))) return original
      if (Number(quantities.pending_production_qty) === 0 && Number(quantities.pending_purchase_qty) === 0 && Number(quantities.production_replenished_qty) >= effectiveQty && Number(quantities.locked_inventory_qty) >= effectiveQty) return '生产已回补'
      return original
    },
    async load() {
      const sequence = ++this.loadSequence
      const id = this.pageRoute.params.id
      const { data } = await getSalesOrder(id)
      if (sequence !== this.loadSequence) return
      this.order = data
      if (this.$refs.changeLabor) this.$refs.changeLabor.load()
      if (this.contractFiles.length) this.attachmentsOpen = true
      this.expandedLineRowKeys = (data.lines || [])
        .filter(line => this.lineFiles(line).length > 0 && line.id != null)
        .map(line => line.id)
      const progress = this.$refs.orderProgress
      if (progress && progress.orderId === data.id) progress.loadTree()
      let actor = 0
      try { actor = JSON.parse(localStorage.getItem('erp_user') || '{}').legacy_id || 0 } catch (_) { /* Local profile unavailable. */ }
      this.inventoryLockStorageKey = `erp_pending_sales_inventory_lock_${actor}_${id}`
      try { this.inventoryLockPending = JSON.parse(localStorage.getItem(this.inventoryLockStorageKey) || 'null') } catch (_) { this.inventoryLockPending = null }
      await this.loadChanges(1)
      if (this.$route.query.tab === 'production') {
        this.activeTab = 'production'
      } else if (this.$route.query.tab === 'logs') {
        this.activeTab = 'logs'
        this.scrollToLogs()
      } else if (this.$route.query.tab === 'changes') {
        this.activeTab = 'changes'
        this.scrollToChanges()
      }
    },
    async loadWorkOrders(sequence) {
      this.workOrders = []
      if (!this.$can('sales_order.view')) return
      this.workOrderLoading = true
      try {
        const { data } = await listSalesOrderWorkOrders(this.order.id)
        if (sequence === this.loadSequence) this.workOrders = data.data || []
      } catch (error) {
        if (sequence === this.loadSequence) this.workOrders = []
      } finally {
        if (sequence === this.loadSequence) this.workOrderLoading = false
      }
    },
    async doConfirm() {
      // 一步确认：服务端先做确认前检查，通过后立即正式确认；未通过不改变订单状态。
      try {
        await this.$confirm('确认后将锁定本次销售单位、默认库存物料、物料基本单位和单位换算比例，订单进入核实期：此时不会生成工单，金额及结算条件修改需财务审核，其他修改按实际影响处理。系统会先自动检查，是否继续？', '确认订单', { type: 'warning' })
      } catch (error) {
        return
      }
      const response = await confirmSalesOrder(this.order.id)
      const result = response.data && response.data.data ? response.data.data : response.data
      const blocked = (result.checks || []).find(item => item.status === 'blocked')
      const passed = Boolean(result.passed) && !blocked
      if (passed) {
        this.$message.success('订单已确认，请核实后执行“生产确认”')
        await this.load()
        return
      }
      const content = (result.checks || []).map(item => `<li class="${item.status}"><b>${item.status === 'passed' ? '通过' : '阻塞'}</b> ${item.message}</li>`).join('')
      const fallback = '<li class="blocked"><b>阻塞</b> 服务端未返回逐项检查结果，请勿进入下一阶段。</li>'
      try {
        await this.$alert(`<p>${result.message}</p><ul class="sales-precheck-list">${content || fallback}</ul>`, '订单暂不能确认', {
          dangerouslyUseHTMLString: true,
          confirmButtonText: '返回编辑'
        })
      } catch (error) {
        return
      }
      this.$router.push({
        path: `/sales/orders/${this.order.id}/edit`,
        query: blocked && blocked.field ? { focus: blocked.field } : {}
      })
    },
    async doFormalConfirm() {
      await this.$confirm('正式确认后将锁定本次销售单位、默认库存物料、物料基本单位和单位换算比例，并进入订单生产确认。是否继续？', '正式确认订单', { type: 'warning' })
      await formalConfirmSalesOrder(this.order.id)
      this.$message.success('订单已正式确认，单位换算信息已锁定')
      await this.load()
    },
    lockQuantity(value, unit) { return value == null ? '—' : `${this.numberText(value)} ${unit || ''}` },
    async openInventoryLock() {
      const sequence = ++this.inventoryLockSequence
      const orderId = this.order.id
      const token = localStorage.getItem('erp_token')
      this.inventoryLockVisible = true
      this.inventoryLockLoading = true
      this.inventoryLockError = ''
      try {
        const { data } = await getSalesOrder(orderId)
        if (this._isDestroyed || sequence !== this.inventoryLockSequence || !this.inventoryLockVisible || Number(this.order.id) !== Number(orderId) || Number(this.pageRoute.params.id) !== Number(orderId)) return
        if (localStorage.getItem('erp_token') !== token) {
          this.inventoryLockError = '登录账号已变化，请重新打开订单'
          return
        }
        this.order = data
      } catch (error) {
        if (sequence === this.inventoryLockSequence && Number(this.order.id) === Number(orderId) && Number(this.pageRoute.params.id) === Number(orderId)) this.inventoryLockError = error.userMessage || '库存数量加载失败，请关闭后重新打开'
      } finally {
        if (sequence === this.inventoryLockSequence) this.inventoryLockLoading = false
      }
    },
    async confirmInventoryLock() {
      if (this.inventoryLockBusy || this.inventoryLockLoading || this.inventoryLockError || !this.order.allowed_actions?.lock_inventory) return
      this.inventoryLockBusy = true
      const orderId = this.order.id
      const storageKey = this.inventoryLockStorageKey
      try {
        if (!this.inventoryLockPending) {
          const version = Number(this.order.business_version)
          if (!Number.isSafeInteger(version) || version < 1 || !this.inventoryLockStorageKey) throw new Error('订单版本无效，请刷新后重试')
          const payload = { client_command_id: `sales-inventory-lock-${this.order.id}-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`, expected_version: version }
          localStorage.setItem(this.inventoryLockStorageKey, JSON.stringify(payload))
          this.inventoryLockPending = payload
        }
        const response = await lockSalesOrderInventory(orderId, this.inventoryLockPending)
        const result = response.data && response.data.data ? response.data.data : response.data
        localStorage.removeItem(storageKey)
        if (Number(this.pageRoute.params.id) === Number(orderId)) {
          this.inventoryLockPending = null
          this.inventoryLockVisible = false
          this.$message.success(Number(result.created_fulfillment_count || 0) > 0 ? '库存已正式锁定，待外购及生产缺口已重新核对' : '没有新增可锁库存，未重复占用')
          await this.load()
        }
      } catch (error) {
        const status = Number(error.response?.status || 0)
        if (status >= 400 && status < 500 && !['command_processing', 'command_recovery_required', 'recovery_required'].includes(error.errorCode)) {
          localStorage.removeItem(storageKey)
          if (Number(this.pageRoute.params.id) === Number(orderId)) {
            this.inventoryLockPending = null
            if ([403, 404, 409].includes(status)) await this.load()
          }
        }
        if (Number(this.pageRoute.params.id) === Number(orderId)) this.$message.error(error.userMessage || error.message || '库存锁定失败，请重试原操作')
      } finally { this.inventoryLockBusy = false }
    },
    priorityTagType(p) {
      return { 1: 'danger', 2: 'warning', 4: 'info' }[p] || ''
    },
    openPriority() {
      this.priority = { visible: true, saving: false, value: this.order.priority || 3, reason: '' }
    },
    async savePriority() {
      if (this.priority.saving) return
      const orderId = this.order.id
      this.priority.saving = true
      try {
        const res = await adjustSalesOrderPriority(orderId, { priority: this.priority.value, reason: this.priority.reason.trim() })
        this.$message.success((res.data && res.data.message) || '优先级已调整')
        this.priority.visible = false
        if (this.isPageRouteActive && String(this.pageRoute.params.id) === String(orderId)) await this.load()
      } catch (error) {
        this.$message.error(error.userMessage || '优先级调整失败')
      } finally { this.priority.saving = false }
    },
    async editAgreedDeposit() {
      if (this.depositSaving) return
      const orderId = this.order.id
      this.depositSaving = true
      try {
        await promptAgreedDeposit(this, orderId, this.order.funding_status)
        if (this.isPageRouteActive && String(this.pageRoute.params.id) === String(orderId)) await this.load()
      } catch (error) {
        if (!['cancel', 'close'].includes(error)) this.$message.error(error.userMessage || '约定定金保存失败')
      } finally { this.depositSaving = false }
    },
    async cancelOrder() {
      if (this.order.allowed_actions?.cancel) this.cancelDialogVisible = true
    },
    async openShortClose() {
      if (this.shortClose.loading) return
      const orderId = this.order.id
      this.shortClose.loading = true
      try {
        const res = await previewShortCloseSalesOrder(orderId)
        if (!this.isPageRouteActive || String(this.pageRoute.params.id) !== String(orderId)) return
        this.shortClose.preview = res.data.data
        this.shortClose.reason = ''
        this.shortClose.visible = true
      } catch (error) {
        this.$message.error(error.userMessage || '短交关闭预览加载失败')
      } finally { this.shortClose.loading = false }
    },
    async confirmShortClose() {
      const reason = String(this.shortClose.reason || '').trim()
      if (!reason) return this.$message.warning('请填写短交原因')
      if (this.shortClose.saving) return
      const orderId = this.order.id
      let closed = false
      this.shortClose.saving = true
      try {
        const res = await shortCloseSalesOrder(orderId, { reason })
        closed = true
        this.shortClose.visible = false
        this.$message.success((res.data && res.data.message) || '订单已短交关闭')
        if (this.isPageRouteActive && String(this.pageRoute.params.id) === String(orderId)) await this.load()
      } catch (error) {
        this.$message.error(closed ? '订单已短交关闭，详情刷新失败，请重新打开本单核对' : (error.userMessage || '短交关闭失败'))
      } finally { this.shortClose.saving = false }
    },
    async deleteDraft() {
      await this.$confirm('删除后草稿及其订单行将不能恢复，附件绑定会保留审计记录。确认删除？', '删除销售订单草稿', { type: 'warning' })
      await deleteSalesOrderDraft(this.order.id)
      this.$message.success('销售订单草稿已删除')
      this.$router.push('/sales/orders')
    },
    async loadChanges(page = this.changes.page) {
      if (!this.order.id) return
      this.changeLoading = true
      try {
        const { data } = await listSalesOrderChanges(this.order.id, { page, per_page: this.changes.per_page })
        this.changes = { rows: data.data || [], total: Number(data.total || 0), page: Number(data.current_page || page), per_page: Number(data.per_page || this.changes.per_page) }
      } finally { this.changeLoading = false }
    },
    approvalStatusText(v) { return ({PENDING:'待审核',APPROVED:'已通过',REJECTED:'已驳回',CONFLICTED:'版本冲突',CANCELLED:'已取消'})[v]||v||'-' },
    approvalStatusTag(v) { return ({PENDING:'warning',APPROVED:'success',REJECTED:'danger',CONFLICTED:'info',CANCELLED:'info'})[v]||'info' },
    scrollToChanges() {
      this.activeTab = 'changes'
      this.$nextTick(() => {
        const el = this.$refs.changeHistory || this.$refs.tabSection
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' })
      })
    },
    scrollToLogs() {
      this.activeTab = 'logs'
      this.$nextTick(() => {
        const el = this.$refs.logsSection || this.$refs.tabSection
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' })
      })
    },
    changeVersion(row) {
      if (row && row.version_no) return Number(row.version_no)
      return Math.max(1, ...(this.order.versions || []).filter(item => String(item.remark || '').includes(row.change_no)).map(item => Number(item.version_no || 0)))
    },
    changeAmountText(row) { const before = this.changeSnapshotTotal(row.before_snapshot); const after = this.changeSnapshotTotal(row.after_snapshot); const diff = after - before; return `${diff >= 0 ? '+' : '-'}¥${this.money(Math.abs(diff))}` },
    changeSnapshotTotal(snapshot) { return ((snapshot && snapshot.lines) || []).reduce((sum, line) => sum + Number(line.amount_incl_tax || line.amount || 0), 0) },
    openChange(row) { this.selectedChange = row; this.changeDetailVisible = true },
    changeDiffRows(row) { return row && Array.isArray(row.structured_diffs) ? row.structured_diffs : [] },
    approvalTypeText(value) { return ({business:'业务审核',finance:'财务审核',fulfillment:'库存交付审核'})[value] || value },
    candidateApprovalProgress(row) { const task=row.approval_task||{}; const nodes=task.nodes||[]; if (!nodes.length) return (row.approvals||[]).map(item=>`${this.approvalTypeText(item.approval_type)}：${this.approvalStatusText(item.approval_status)}`).join('；') || '-'; return nodes.map(item=>`${item.node_name}：${this.approvalStatusText(item.node_status)}`).join('；') },
    openCandidate(row) { this.selectedCandidate = row; this.candidateDetailVisible = true },
    changeEffectText(row) {
      const before = (row && row.before_snapshot) || {}
      const after = (row && row.after_snapshot) || {}
      const beforeLines = (before.lines || []).map(line => [line.id, line.sku_id, line.item_id, Number(line.order_qty || 0), line.fulfillment_method || '', JSON.stringify(line.configuration_snapshot || {})])
      const afterLines = (after.lines || []).map(line => [line.id, line.sku_id, line.item_id, Number(line.order_qty || 0), line.fulfillment_method || '', JSON.stringify(line.configuration_snapshot || {})])
      const affectsFulfillment = JSON.stringify(beforeLines) !== JSON.stringify(afterLines)
        || String(before.required_delivery_date || '') !== String(after.required_delivery_date || '')
      return affectsFulfillment
        ? '该变更已生效：受影响的旧库存预留、生产和交付安排已废止，订单须按新版本重新规划。'
        : '该变更已生效：订单商业信息已更新，原库存预留、生产和交付安排保持不变。'
    },
    businessText(value) {
      return String(value || '')
        .replace(/履约换算快照/g, '单位换算信息')
        .replace(/履约需求/g, '备货与生产需求')
        .replace(/锁定履约/g, '锁定备货数量')
        .replace(/履约计划/g, '生产和交付安排')
        .replace(/履约/g, '交付')
    },
    percent(v) { return `${(Number(v || 0) * 100).toFixed(2)}%` },
    money(v) {
      return Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    },
    numberText(v) { return Number(v || 0).toLocaleString('zh-CN', { maximumFractionDigits: 8 }) },
    date(v) {
      return v ? erpDateTime(v, 16) : '-'
    },
    dateOnly(v) {
      return v ? erpDateTime(v, 10) : '-'
    },
    snapshotName(v) {
      if (!v) return '未锁定'
      return v.name || v.bom_name || v.version || '已锁定'
    },
    statusText,
    orderStatusText,
    orderStatusTag,
    statusTag,
    shipmentStatusText,
    fulfillmentStatusText(value) { return ({ pending: '待交付', partial: '部分交付', fulfilled: '已交付', cancelled: '已取消' })[value] || value || '-' },
    fulfillmentStatusTag(value) { return ({ pending: 'warning', partial: 'warning', fulfilled: 'success', cancelled: 'danger' })[value] || 'info' },
    lineItem(line) {
      if (line.system_item) return line.system_item
      const lineType = line.line_type || (line.sku_snapshot && (line.sku_snapshot.order_line_type || line.sku_snapshot.fulfillment_type)) || 'physical'
      if (['service', 'no_delivery', 'fee', 'auxiliary', 'deduction'].includes(lineType)) {
        return { status: 'not_required', item_code: null, item_name: null, message: '无需 Item' }
      }
      const snapshot = line.item_snapshot || {}
      const item = line.item || {}
      const itemCode = snapshot.item_code || item.item_code
      const itemName = snapshot.item_name || item.item_name
      if (line.item_match_status === 'matched' && itemCode && itemName) {
        return { status: 'matched', item_code: itemCode, item_name: itemName, message: null }
      }
      return {
        status: 'abnormal',
        item_code: itemCode || null,
        item_name: itemName || null,
        message: line.item_match_block_reason || '历史订单行缺少有效系统 Item 快照'
      }
    },
    lineFiles(line) {
      return this.mergeAttachmentFiles(
        (line.technical_attachment_snapshot && line.technical_attachment_snapshot.files) || [],
        (line.attachments || []).map(this.mapAttachment)
      )
    },
    mapAttachment(item) {
      return {
        attachment_id: item.attachment_id || item.id,
        file_name: item.file_name || item.original_name,
        file_type: item.file_type || item.attachment_type || '其他附件',
        url: item.url,
        uploaded_at: item.uploaded_at,
        file_hash: item.file_hash,
        version_no: item.version_no || '-',
        file_size: item.file_size,
        mime_type: item.mime_type,
        uploaded_by: item.uploaded_by || '-',
        status: item.status || 'active',
        temporary: Boolean(item.temporary),
        can_preview: item.can_preview === true,
        can_download: item.can_download !== false,
        can_delete: item.can_delete === true,
        is_main: Boolean(item.is_main),
        locked: Boolean(item.locked),
        remark: item.remark || ''
      }
    },
    mergeAttachmentFiles(snapshot, actual) {
      const result = []
      const actualFiles = actual || []
      const actualIds = new Set(
        actualFiles
          .map(file => file.attachment_id || file.id)
          .filter(id => id !== null && id !== undefined)
          .map(String)
      )
      const snapshotFiles = (snapshot || []).filter(file => {
        const id = file.attachment_id || file.id
        return id === null || id === undefined || actualIds.has(String(id))
      })
      ;[...snapshotFiles, ...actualFiles].forEach(file => {
        const normalized = file.attachment_id || file.id ? this.mapAttachment(file) : file
        const key = normalized.attachment_id || normalized.file_hash || `${normalized.file_name || ''}-${normalized.uploaded_at || ''}`
        const index = result.findIndex(item => (item.attachment_id || item.file_hash || `${item.file_name || ''}-${item.uploaded_at || ''}`) === key)
        if (index === -1) result.push(normalized)
        else result.splice(index, 1, { ...result[index], ...normalized })
      })
      return result
    },
    parseContractAttachments(value) {
      if (!value) return []
      if (Array.isArray(value)) return value
      try {
        const parsed = JSON.parse(value)
        return Array.isArray(parsed) ? parsed : []
      } catch (error) {
        return String(value).split(',').map((name, index) => ({
          uid: `legacy-contract-${index}`,
          file_name: name.trim(),
          file_type: '历史合同附件',
          uploaded_at: ''
        })).filter(item => item.file_name)
      }
    },
    previewable(file) {
      return Boolean(file && file.can_preview === true)
    },
    fileSize(size) {
      const bytes = Number(size || 0)
      if (!bytes) return '-'
      if (bytes < 1024) return `${bytes} B`
      if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
      return `${(bytes / 1024 / 1024).toFixed(1)} MB`
    },
    previewAttachment(file) {
      this.previewFile = file
      this.previewVisible = true
    },
    async downloadAttachment(file) {
      const { data } = await downloadSalesOrderAttachment(file.attachment_id)
      const url = URL.createObjectURL(new Blob([data], { type: file.mime_type || 'application/octet-stream' }))
      const link = document.createElement('a')
      link.href = url
      link.download = file.file_name || '附件'
      link.style.display = 'none'
      document.body.appendChild(link)
      link.click()
      link.remove()
      window.setTimeout(() => URL.revokeObjectURL(url), 30 * 1000)
      this.$message.success(`附件“${file.file_name}”已开始下载`)
    },
    canDeleteAttachment(file) {
      return Boolean(
        file.attachment_id &&
        this.order.allowed_actions &&
        this.order.allowed_actions.delete_attachment &&
        file.can_delete === true
      )
    },
    async deleteAttachment(file) {
      await this.$confirm(`确认删除附件“${file.file_name}”？删除后列表将保留审计状态。`, '删除附件', { type: 'warning' })
      await deleteSalesOrderAttachment(file.attachment_id)
      this.$message.success('附件已删除')
      await this.load()
    }
  }
}
</script>

<style scoped>
/* 全局容器与版心 */
.sales-detail-page {
  padding: 14px 16px 28px;
  min-height: calc(100vh - 52px);
  overflow-x: hidden;
  background: #f4f6f9;
  color: #1e293b;
  box-sizing: border-box;
}

/* 顶部主控导航卡片 */
.detail-header-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 14px 18px;
  margin-bottom: 12px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
}

/* 订单阶段提示条（基础档案风格） */
.erp-page-tip { display: flex; align-items: flex-start; gap: 8px; padding: 9px 14px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; color: #00763f; font-size: 13px; line-height: 1.5; margin-bottom: 14px; }
.erp-page-tip i { font-size: 15px; color: #008b4b; flex-shrink: 0; margin-top: 2px; }
.erp-page-tip.tip-warning { background: #fffbeb; border-color: #fde68a; color: #92400e; }
.erp-page-tip.tip-warning i { color: #d97706; }
.erp-page-tip.tip-info { background: #f8fafc; border-color: #e2e8f0; color: #475569; }
.erp-page-tip.tip-info i { color: #64748b; }

.crumb {
  color: #64748b;
  font-size: 12px;
  margin-bottom: 4px;
}

.title-row {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.order-no-title {
  margin: 0;
  font-size: 20px;
  font-weight: 700;
  color: #0f172a;
  letter-spacing: -0.2px;
}

.header-tags {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.header-pill {
  display: inline-flex;
  align-items: center;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 11px;
  line-height: 16px;
  font-weight: 500;
}

.header-pill--composition {
  background: #eef2f6;
  color: #475569;
  border: 1px solid #cbd5e1;
}

.header-pill--version {
  background: #f1f5f9;
  color: #0284c7;
  border: 1px solid #bae6fd;
  font-weight: 600;
}

.header-actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  justify-content: flex-end;
}

.kpi-of {
  margin-left: 4px;
  color: #94a3b8;
  font-size: 12px;
  font-weight: 400;
}
.kpi-value.green { color: #008b4b; }
.kpi-value.orange { color: #d97706; }
.kpi-bar {
  height: 6px;
  margin-top: 6px;
  overflow: hidden;
  border-radius: 3px;
  background: #f1f5f9;
}
.kpi-bar i {
  display: block;
  height: 100%;
  background: #10a65e;
}
.kv-hint {
  margin-left: 6px;
  color: #94a3b8;
  font-size: 11px;
  font-weight: 400;
}
.remark-text {
  white-space: pre-wrap;
}
.dispatch-records {
  margin-top: 12px;
  padding-top: 10px;
  border-top: 1px dashed #e2e8f0;
}
.dispatch-records-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 6px;
}
.dispatch-records-head b {
  font-size: 13px;
  color: #1e293b;
}
.dispatch-empty {
  padding: 4px 0;
  font-size: 12px;
}
.line-expand-compact {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 8px 24px;
  margin: 8px 0 12px;
}
.line-expand-compact .expand-kv {
  display: flex;
  gap: 8px;
  min-width: 0;
  font-size: 12px;
}
.line-expand-compact .lbl {
  flex: none;
  width: 84px;
  color: #64748b;
}
.line-expand-compact .val {
  min-width: 0;
  color: #1e293b;
  overflow-wrap: anywhere;
}
.line-expand-compact .expand-kv--full {
  grid-column: 1 / -1;
}
.order-lines-total {
  display: flex;
  justify-content: flex-end;
  gap: 20px;
  padding: 10px 4px 0;
  color: #64748b;
  font-size: 12px;
}
.order-lines-total b {
  color: #008b4b;
  font-size: 14px;
}
.card-head.is-clickable {
  cursor: pointer;
}
.attachments-invoice-body {
  padding-top: 4px;
}
.sub-section-title {
  margin: 10px 0 8px;
  color: #475569;
  font-size: 13px;
  font-weight: 600;
}
.more-danger {
  color: #ef4444 !important;
}
@media (max-width: 780px) {
  .line-expand-compact {
    grid-template-columns: minmax(0, 1fr);
  }
}

/* 预警与通知栏 */
.change-block-alert,
.change-success-alert {
  margin: 0 0 12px;
  border-radius: 6px;
}

/* 核心关键指标看板 (KPI Strip) */
.kpi-metric-strip {
  display: grid;
  grid-template-columns: 1.3fr 1.2fr 1fr 1fr;
  gap: 12px;
  margin-bottom: 12px;
}

.kpi-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 12px 16px;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);
  display: flex;
  flex-direction: column;
  justify-content: center;
  min-width: 0;
}

.short-close-table { margin-top: 12px; }
.short-close-summary { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; margin-top: 12px; }
.short-close-summary div { display: flex; flex-direction: column; gap: 4px; padding: 8px 10px; background: #f7f8fa; border-radius: 6px; }
.short-close-summary span { color: #909399; font-size: 12px; }
.short-close-overpaid, .short-close-reason { margin-top: 12px; }
.short-closed-tag { margin-left: 6px; }
.kpi-card--amount {
  border-left: 3px solid #008b4b;
}

.kpi-label {
  font-size: 12px;
  color: #64748b;
  display: flex;
  align-items: center;
  gap: 5px;
  font-weight: 500;
}

.kpi-value {
  margin: 4px 0 2px;
  font-size: 16px;
  font-weight: 700;
  color: #0f172a;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.kpi-value.amount {
  font-size: 20px;
  color: #008b4b;
  letter-spacing: -0.3px;
}

.kpi-value.primary {
  color: #1e293b;
}

.kpi-sub {
  font-size: 11px;
  color: #94a3b8;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* 通用详情卡片 */
.detail-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 16px 18px;
  margin-bottom: 12px;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);
  box-sizing: border-box;
}

.card-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 14px;
  padding-bottom: 10px;
  border-bottom: 1px solid #f1f5f9;
}

.card-title {
  font-size: 15px;
  font-weight: 600;
  color: #0f172a;
  display: flex;
  align-items: center;
  gap: 8px;
}

.card-title i {
  color: #008b4b;
  font-size: 16px;
}

.title-sub-count {
  font-size: 12px;
  font-weight: 400;
  color: #64748b;
  margin-left: 6px;
}

/* 两栏基本信息卡片网格 */
.overview-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  gap: 12px;
  margin-bottom: 12px;
}

.kv-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px 18px;
}

.kv-item {
  display: flex;
  flex-direction: column;
  gap: 3px;
  min-width: 0;
}

.kv-item--full {
  grid-column: 1 / -1;
}

.kv-label {
  font-size: 12px;
  color: #64748b;
  font-weight: 500;
}

.kv-value {
  font-size: 13px;
  color: #1e293b;
  line-height: 1.4;
  word-break: break-all;
}

.address-box {
  background: #f8fafc;
  border: 1px solid #edf2f7;
  border-radius: 6px;
  padding: 8px 10px;
  margin-top: 2px;
}

.address-text {
  color: #334155;
  font-weight: 500;
}

.foreign-box {
  background: #f8fafc;
  border: 1px solid #edf2f7;
  border-radius: 6px;
  padding: 6px 10px;
  font-size: 12px;
}

/* 订单行表格定制 */
.order-lines-card {
  padding-bottom: 12px;
}

.clean-order-table {
  border-radius: 6px;
  overflow: hidden;
}

.clean-order-table :deep(th) {
  background: #f8fafc !important;
  color: #334155;
  font-weight: 600;
  font-size: 12px;
}

.line-index {
  color: #64748b;
  font-weight: 600;
}

.product-title {
  font-weight: 600;
  color: #0f172a;
}

.sku-subtitle {
  color: #475569;
}

.item-code-text {
  color: #0369a1;
  font-size: 12px;
}

.price-cell {
  color: #334155;
}

.amount-cell {
  color: #008b4b;
  font-size: 13px;
}

.config-tags-cell {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}

.drawing-count-badge {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  padding: 2px 8px;
  border-radius: 10px;
  font-size: 11px;
  color: #64748b;
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
}

.drawing-count-badge.is-clickable {
  cursor: pointer;
  transition: all 0.15s ease;
}

.drawing-count-badge.is-clickable:hover {
  border-color: #94a3b8;
  background: #e2e8f0;
}

.drawing-count-badge.has-files {
  color: #0284c7;
  background: #e0f2fe;
  border-color: #bae6fd;
  font-weight: 600;
}

.drawing-count-badge.has-files.is-clickable:hover {
  background: #bae6fd;
  border-color: #38bdf8;
}

.drawing-badge-arrow {
  font-size: 10px;
  margin-left: 1px;
}

.title-sub-count--blue {
  background: #e0f2fe;
  color: #0284c7;
}

.title-sub-count--orange {
  background: #ffedd5;
  color: #ea580c;
}

.line-expand-box {
  margin: 8px 12px 10px 40px;
  padding: 12px 14px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-left: 3px solid #008b4b;
  border-radius: 6px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
}

.line-expand-head {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
  padding-bottom: 8px;
  border-bottom: 1px dashed #e2e8f0;
  font-size: 13px;
}

.line-expand-sub {
  margin-left: auto;
  font-size: 12px;
  color: #64748b;
  font-weight: 500;
}

.line-expand-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
  margin-bottom: 14px;
}

.expand-info-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 10px 12px;
}

.expand-card-title {
  font-size: 12px;
  font-weight: 600;
  color: #1e293b;
  margin-bottom: 8px;
  display: flex;
  align-items: center;
  gap: 5px;
  padding-bottom: 6px;
  border-bottom: 1px solid #f1f5f9;
}

.expand-card-title i {
  color: #008b4b;
}

.expand-kv-list {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.expand-kv {
  display: flex;
  align-items: flex-start;
  font-size: 12px;
  line-height: 1.4;
}

.expand-kv .lbl {
  color: #64748b;
  width: 76px;
  flex-shrink: 0;
}

.expand-kv .val {
  color: #334155;
  word-break: break-all;
  flex: 1;
}

.expand-kv--full {
  margin-top: 2px;
  padding-top: 6px;
  border-top: 1px dashed #f1f5f9;
}

.line-expand-drawing-section {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 10px 12px;
}

.drawing-section-title {
  font-size: 12px;
  font-weight: 600;
  color: #1e293b;
  margin-bottom: 8px;
  display: flex;
  align-items: center;
  gap: 5px;
}

.drawing-section-title i {
  color: #0284c7;
}

.share-users-tags {
  display: inline-flex;
  flex-wrap: wrap;
  gap: 4px;
}

.contract-empty-inline {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 10px 12px;
  background: #f8fafc;
  border: 1px dashed #cbd5e1;
  border-radius: 6px;
  font-size: 12px;
}

/* 审核进度条卡片 */
.approval-progress-strip {
  display: grid;
  grid-template-columns: 380px minmax(360px, 1fr) 180px;
  align-items: center;
  gap: 16px;
}

.approval-status-block h3 {
  margin: 0 0 6px;
  font-size: 14px;
  color: #0f172a;
}

.approval-status-block > div {
  display: flex;
  gap: 16px;
  font-size: 12px;
  margin-bottom: 4px;
}

.approval-status-block p {
  margin: 0;
  color: #94a3b8;
  font-size: 11px;
}

.approval-node-progress {
  display: flex;
  align-items: flex-start;
  padding-top: 2px;
}

.approval-node {
  position: relative;
  flex: 1;
  text-align: center;
}

.approval-node em {
  position: relative;
  z-index: 2;
  display: grid;
  place-items: center;
  width: 22px;
  height: 22px;
  margin: auto;
  border: 1px solid #cbd5e1;
  border-radius: 50%;
  background: #fff;
  font-size: 11px;
  font-style: normal;
  color: #64748b;
}

.approval-node b {
  display: block;
  margin-top: 6px;
  font-size: 11px;
  color: #475569;
}

.approval-node .connector {
  position: absolute;
  right: 50%;
  left: -50%;
  top: 10px;
  height: 2px;
  background: #e2e8f0;
}

.approval-node.done em {
  color: #fff;
  background: #10a65e;
  border-color: #10a65e;
}

.approval-node.done .connector,
.approval-node.active .connector {
  background: #10a65e;
}

.approval-node.active em {
  color: #0284c7;
  border-color: #0284c7;
  background: #f0f9ff;
}

.approval-node.failed em {
  color: #dc2626;
  border-color: #dc2626;
}

.approval-current {
  font-size: 11px;
  text-align: right;
}

.approval-current b {
  display: block;
  margin: 4px 0;
  color: #0284c7;
  font-size: 12px;
}

/* 追踪与审计控制台 (Tabs) */
.wo-children { margin-top: 10px; padding-top: 8px; border-top: 1px dashed #e4e7ed; }
.wo-children-title { font-size: 12px; color: #909399; margin-bottom: 4px; }
.wo-child-row { display: flex; align-items: center; gap: 8px; min-width: 0; padding: 2px 0; font-size: 12px; flex-wrap: wrap; }
.wo-child-row .el-button { padding: 0; }
.wo-child-no { font-size: 12px; }
.wo-child-item { flex: 1 1 120px; min-width: 0; color: #606266; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.wo-child-progress { color: #909399; white-space: nowrap; }
.tracking-console-card {
  padding: 6px 16px 16px;
  scroll-margin-top: 12px;
}

.tracking-console-card--production {
  background: transparent;
  padding: 0;
  border: 0;
  box-shadow: none;
}

.tracking-console-card--production > .tracking-tabs > :deep(.el-tabs__header) {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 5px;
  padding: 0 16px;
}

.tracking-tabs :deep(.el-tabs__header) {
  margin-bottom: 16px;
}

.tracking-tabs :deep(.el-tabs__item) {
  font-size: 13px;
  font-weight: 500;
  color: #475569;
  height: 44px;
  line-height: 44px;
}

.tracking-tabs :deep(.el-tabs__item.is-active) {
  color: #008b4b;
  font-weight: 600;
}

.tracking-tabs :deep(.el-tabs__active-bar) {
  background-color: #008b4b;
}

.tab-badge-num {
  display: inline-block;
  padding: 0 6px;
  height: 16px;
  line-height: 16px;
  border-radius: 8px;
  background: #e2e8f0;
  color: #334155;
  font-size: 10px;
  font-weight: 600;
  margin-left: 4px;
  vertical-align: middle;
}

.tracking-tabs :deep(.el-tabs__item.is-active) .tab-badge-num {
  background: #eaf7ef;
  color: #008b4b;
}

.tab-pane-content {
  min-height: 180px;
}

.tab-pane-header {
  margin-bottom: 12px;
}

.pane-subtitle {
  color: #64748b;
  font-size: 12px;
}

/* 工单列表卡片 */
.work-order-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 12px;
}

.work-order-card-item {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 12px 14px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.wo-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  border-bottom: 1px solid #edf2f7;
  padding-bottom: 8px;
}

.wo-no {
  color: #0f172a;
  font-size: 13px;
}

.wo-body {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
}

.wo-field {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.wo-field .label {
  font-size: 11px;
  color: #64748b;
}

.wo-field .value {
  font-size: 12px;
  color: #1e293b;
}

/* 附件与图纸区 */
.attachments-tab-body {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.sub-section-title {
  margin-bottom: 10px;
}

.sub-section-title h4 {
  margin: 0;
  font-size: 13px;
  font-weight: 600;
  color: #1e293b;
  display: flex;
  align-items: center;
  gap: 6px;
}

.compact-file-list {
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  overflow: hidden;
}

.compact-file-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 14px;
  border-bottom: 1px solid #f1f5f9;
  background: #ffffff;
}

.compact-file-row:last-child {
  border-bottom: 0;
}

.compact-file-row:hover {
  background: #f8fafc;
}

.compact-file-main {
  min-width: 0;
}

.compact-file-main b {
  display: block;
  font-size: 13px;
  color: #1e293b;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.compact-file-main span {
  display: block;
  margin-top: 4px;
  color: #64748b;
  font-size: 11px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.compact-file-actions {
  flex: none;
  white-space: nowrap;
  display: flex;
  align-items: center;
  gap: 8px;
}

.line-file-block {
  margin-bottom: 14px;
}

.line-file-block:last-child {
  margin-bottom: 0;
}

.line-file-title {
  margin-bottom: 8px;
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
}

.line-badge {
  background: #e2e8f0;
  color: #334155;
  font-size: 11px;
  font-weight: 600;
  padding: 1px 6px;
  border-radius: 4px;
}

.line-prod {
  font-weight: 600;
  color: #0f172a;
}

/* 操作日志与版本记录 */
.log-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.25fr) minmax(0, 0.95fr);
  gap: 20px;
}

.pane-sub-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
  padding-bottom: 6px;
  border-bottom: 1px solid #f1f5f9;
}

.pane-sub-header h4 {
  margin: 0;
  font-size: 13px;
  font-weight: 600;
  color: #1e293b;
}

/* 严格满足测试契约的 CSS 规则 */
.sales-order-operation-log__body{max-height:320px;overflow-y:auto;overflow-x:hidden;padding-right:8px}

.sales-order-version-list__body {
  max-width: 100%;
  overflow-x: auto;
  overflow-y: hidden;
}

.sales-order-version-list__body :deep(.el-table__header-wrapper) {
  position:sticky;top:0;z-index:2;background:#fff;
}

.timeline-log-title {
  display: flex;
  align-items: center;
  gap: 8px;
}

.timeline-log-title b {
  color: #0f172a;
  font-size: 13px;
}

.log-operator-tag {
  background: #f1f5f9;
  color: #475569;
  font-size: 10px;
  padding: 1px 6px;
  border-radius: 4px;
}

.timeline-log-desc {
  margin: 4px 0 0;
  color: #475569;
  font-size: 12px;
  line-height: 1.5;
  word-break: break-word;
}

/* 变更与审核历史 */
.change-history-block {
  margin-bottom: 24px;
}

.section-title-row p {
  margin: 2px 0 10px;
  color: #64748b;
  font-size: 12px;
}

.candidate-history-title {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 10px;
}

.candidate-history-title h4 {
  margin: 0;
  font-size: 13px;
  color: #1e293b;
}

.candidate-history-title span {
  color: #64748b;
  font-size: 11px;
}

.order-change-pagination {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-top: 10px;
  color: #64748b;
  font-size: 12px;
}

/* 弹窗及公共样式 */
.change-detail-readonly {
  display: grid;
  gap: 12px;
}

.change-detail-meta {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  overflow: hidden;
}

.change-detail-meta span {
  display: grid;
  gap: 4px;
  padding: 9px 10px;
  border-right: 1px solid #e2e8f0;
  font-size: 12px;
  color: #334155;
}

.change-detail-meta span:last-child {
  border-right: 0;
}

.change-detail-meta b {
  color: #64748b;
  font-weight: 500;
}

.change-detail-reason {
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 9px 10px;
  color: #334155;
  font-size: 12px;
}

.change-detail-reason b {
  color: #64748b;
}

.change-detail-reason p {
  margin: 5px 0 0;
  white-space: pre-wrap;
  line-height: 1.6;
}

.inventory-lock-summary {
  display: grid;
  grid-template-columns: 1fr 1fr;
  margin: 16px 0;
  border: 1px solid #edf0f4;
  border-radius: 6px;
}

.inventory-lock-summary > div {
  padding: 14px;
  text-align: center;
}

.inventory-lock-summary > div + div {
  border-left: 1px solid #edf0f4;
}

.inventory-lock-summary span {
  color: #64748b;
  font-size: 12px;
  display: block;
}

.inventory-lock-summary b {
  margin-top: 7px;
  font-size: 22px;
  display: block;
}

.green { color: #008b4b; }
.orange { color: #ea580c; }
.danger-link { color: #dc2626 !important; }
.text-muted { color: #94a3b8; }
.font-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; }
.font-medium { font-weight: 500; }
.font-semibold { font-weight: 600; }

.sales-detail-page :deep(.el-timeline) {
  padding-left: 6px;
}

.sales-detail-page :deep(.el-timeline-item) {
  padding-bottom: 14px;
}

.sales-detail-page :deep(.el-timeline-item__wrapper) {
  padding-left: 20px;
}

.sales-detail-page :deep(.el-timeline-item__timestamp) {
  font-size: 11px;
  color: #94a3b8;
}

.sales-detail-page :deep(.el-empty) {
  padding: 16px 0 12px;
}

.sales-detail-page :deep(.el-button--success) {
  background: #008b4b;
  border-color: #008b4b;
}

/* ==========================================================================
   全分辨率响应式断点 (自适应 1366px 笔记本、1200px 平板、768px 及 375px 移动端)
   ========================================================================== */
@media (max-width: 1280px) {
  .kpi-metric-strip {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .approval-progress-strip {
    grid-template-columns: 1fr;
    gap: 12px;
  }
  .approval-current {
    text-align: left;
  }
  .approval-node-progress {
    overflow-x: auto;
    padding-bottom: 8px;
  }
  .approval-node {
    min-width: 90px;
  }
}

@media (max-width: 992px) {
  .overview-layout {
    grid-template-columns: 1fr;
  }
  .log-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 768px) {
  .sales-detail-page {
    padding: 10px;
  }
  .detail-header-card {
    flex-direction: column;
    align-items: flex-start;
    padding: 12px;
  }
  .header-actions {
    width: 100%;
    justify-content: flex-start;
  }
  .kpi-metric-strip {
    grid-template-columns: 1fr;
  }
  .kv-grid {
    grid-template-columns: 1fr;
  }
  .change-detail-meta {
    grid-template-columns: 1fr;
  }
  .change-detail-meta span {
    border-right: 0;
    border-bottom: 1px solid #e2e8f0;
  }
  .order-change-pagination {
    flex-direction: column;
    align-items: flex-start;
    gap: 8px;
  }
}

@media (max-width: 480px) {
  .order-no-title {
    font-size: 18px;
  }
  .header-actions .el-button {
    margin-left: 0;
    margin-bottom: 4px;
  }
}
.wo-step { display: inline-block; margin: 0 6px 2px 0; padding: 1px 6px; border-radius: 4px; background: #f3f5f9; font-size: 12px; }
.wo-stuck { color: #d92d20; }
</style>
