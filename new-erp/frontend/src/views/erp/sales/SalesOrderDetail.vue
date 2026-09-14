<!--
Design reference: D:\codex-introduce\new_erp\docs\ui-reference\phase6\phase6-order-detail-one-page-design.png
Design status: Approved (Optimized for modern ERP layout & responsive UX)
-->
<template>
  <section class="sales-detail-page">
    <!-- 顶部主控导航与操作栏 -->
    <div class="detail-header-card">
      <div class="header-main">
        <div class="crumb">销售管理 / 销售订单 / 订单详情</div>
        <div class="title-row">
          <h1 class="order-no-title">{{ order.sales_order_no || '-' }}</h1>
          <div class="header-tags">
            <el-tag size="small" :type="statusTag(order.order_status)" effect="light">{{ statusText(order.order_status) }}</el-tag>
            <el-tag size="small" :type="fulfillmentStatusTag(order.fulfillment_status)" effect="plain">
              {{ fulfillmentStatusText(order.fulfillment_status) }}
            </el-tag>
            <span v-if="order.fulfillment_composition_label" class="header-pill header-pill--composition">
              {{ order.fulfillment_composition_label }}
            </span>
            <el-tag size="small" :type="statusTag(order.production_confirm_status)" effect="plain">
              生产确认: {{ statusText(order.production_confirm_status) }}
            </el-tag>
            <span class="header-pill header-pill--version">V{{ currentVersion }}</span>
          </div>
        </div>
      </div>
      <div class="header-actions">
        <el-button size="small" icon="el-icon-back" @click="$router.push('/sales/orders')">返回列表</el-button>
        <el-button v-if="order.allowed_actions && order.allowed_actions.edit" size="small" icon="el-icon-edit" @click="$router.push(`/sales/orders/${order.id}/edit`)">编辑订单</el-button>
        <el-button v-if="order.allowed_actions && order.allowed_actions.submit_confirmation" size="small" type="success" icon="el-icon-check" @click="doConfirm">确认前检查</el-button>
        <el-button v-if="order.allowed_actions && order.allowed_actions.formal_confirm" size="small" type="success" icon="el-icon-circle-check" @click="doFormalConfirm">正式确认</el-button>
        <el-button v-if="order.allowed_actions && order.allowed_actions.lock_inventory" class="inventory-lock-button" size="small" type="danger" icon="el-icon-lock" @click="openInventoryLock">锁库存</el-button>
        <el-button v-if="order.allowed_actions && order.allowed_actions.production_confirmation" size="small" type="primary" icon="el-icon-s-operation" @click="$router.push(`/sales/orders/${order.id}/production-confirmation`)">订单生产确认</el-button>
        <el-button v-if="order.allowed_actions && order.allowed_actions.delete_draft" size="small" type="danger" plain icon="el-icon-delete" @click="deleteDraft">删除草稿</el-button>
      </div>
    </div>

    <!-- 状态与预警提示 -->
    <el-alert v-if="order.order_status === 'confirmed' && order.change_eligibility && !order.change_eligibility.allowed" class="change-block-alert" type="warning" :closable="false" show-icon :title="`当前订单不能原地变更：${order.change_eligibility.reason}`" />
    <el-alert v-if="$route.query.changed" class="change-success-alert" type="success" :closable="false" show-icon title="订单已变更，请重新安排备货或进行订单生产确认。" />
    <el-alert v-if="order.pending_change_candidate" class="change-success-alert" type="warning" :closable="false" show-icon :title="`存在待审核 Candidate V${order.pending_change_candidate.candidate_version}：正式订单、库存预留以及生产和交付安排尚未变化。`" />

    <!-- 锁定库存弹窗 -->
    <el-dialog title="确认锁定库存" :visible.sync="inventoryLockVisible" width="430px" :close-on-click-modal="false">
      <el-alert type="info" :closable="false" show-icon title="系统将按当前可用库存、仓库、库位和批次进行正式占用，不会重复锁定。" />
      <div class="inventory-lock-summary">
        <div><span>当前已锁</span><b class="green">{{ numberText(lockTotals.locked_inventory_qty) }}</b></div>
        <div><span>生产缺口</span><b class="orange">{{ numberText(lockTotals.pending_production_qty) }}</b></div>
      </div>
      <el-table :data="lockLines" border size="mini" max-height="260">
        <el-table-column prop="line_no" label="行" width="48" align="center" />
        <el-table-column label="订单数量" min-width="86" align="right"><template slot-scope="{row}">{{ numberText(row.order_qty) }}</template></el-table-column>
        <el-table-column label="已锁库存" min-width="86" align="right"><template slot-scope="{row}"><b class="green">{{ numberText(row.locked_inventory_qty) }}</b></template></el-table-column>
        <el-table-column label="生产缺口" min-width="86" align="right"><template slot-scope="{row}"><b class="orange">{{ numberText(row.pending_production_qty) }}</b></template></el-table-column>
      </el-table>
      <span slot="footer"><el-button @click="inventoryLockVisible=false">取消</el-button><el-button type="danger" :loading="inventoryLockBusy" @click="confirmInventoryLock">确认锁库存</el-button></span>
    </el-dialog>

    <!-- 核心业务指标看板 (KPI Strip) -->
    <div class="kpi-metric-strip">
      <div class="kpi-card kpi-card--amount">
        <div class="kpi-label"><i class="el-icon-wallet" /> 订单金额</div>
        <div class="kpi-value amount">¥{{ money(order.total_amount) }}</div>
        <div class="kpi-sub">共 {{ totalLineCount }} 项明细 · 总计 {{ totalOrderQty }} 件</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label"><i class="el-icon-user" /> 客户信息</div>
        <div class="kpi-value primary" :title="order.customer_name">{{ order.customer_name || '-' }}</div>
        <div class="kpi-sub">{{ order.customer_phone || order.contact_phone || '暂无联系电话' }}</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label"><i class="el-icon-date" /> 单据时间</div>
        <div class="kpi-value">{{ dateOnly(order.order_time) }}</div>
        <div class="kpi-sub">下单：{{ date(order.order_time) }}</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label"><i class="el-icon-time" /> 要求交期</div>
        <div class="kpi-value" :class="{ 'text-muted': !order.required_delivery_date }">{{ dateOnly(order.required_delivery_date) }}</div>
        <div class="kpi-sub">备货安排：{{ fulfillmentStatusText(order.fulfillment_status) }}</div>
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
            <span class="kv-value font-medium">{{ order.created_by || '-' }}</span>
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
            <span class="kv-label">当前版本</span>
            <span class="kv-value font-semibold">V{{ currentVersion }}</span>
          </div>
          <div class="kv-item">
            <span class="kv-label">原始单号</span>
            <span class="kv-value font-mono">{{ order.origin_order_no || '-' }}</span>
          </div>
          <div class="kv-item">
            <span class="kv-label">生产确认状态</span>
            <span class="kv-value"><el-tag size="mini" :type="statusTag(order.production_confirm_status)">{{ statusText(order.production_confirm_status) }}</el-tag></span>
          </div>
        </div>
        <!-- 订单长备注独立块 -->
        <div v-if="order.remark" class="order-remark-box">
          <div class="remark-title"><i class="el-icon-chat-dot-round" /> 订单备注</div>
          <div class="remark-content">{{ order.remark }}</div>
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
            <span class="kv-label">联系电话</span>
            <span class="kv-value font-mono">{{ order.customer_phone || order.contact_phone || '-' }}</span>
          </div>
          <div class="kv-item">
            <span class="kv-label">承运物流</span>
            <span class="kv-value">{{ shippingCarrierName }}</span>
          </div>
          <div class="kv-item">
            <span class="kv-label">预估运费</span>
            <span class="kv-value font-medium font-mono">¥{{ money(order.carrier_fee) }}</span>
          </div>
          <div class="kv-item">
            <span class="kv-label">物流单号</span>
            <span class="kv-value font-mono">{{ (order.logistics_snapshot && order.logistics_snapshot.express_no) || '-' }}</span>
          </div>
          <div v-if="order.customer_snapshot && order.customer_snapshot.name && order.customer_snapshot.name !== order.customer_name" class="kv-item">
            <span class="kv-label">客户历史快照</span>
            <span class="kv-value text-muted">{{ order.customer_snapshot.name }}</span>
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
      </section>
    </div>

    <!-- 订单行商品明细表格 -->
    <section class="detail-card order-lines-card">
      <div class="card-head">
        <div class="card-title">
          <i class="el-icon-goods" /> 订单商品明细
          <span class="title-sub-count">共 {{ (order.lines || []).length }} 项商品</span>
        </div>
      </div>
      <el-table :data="order.lines || []" border size="mini" class="clean-order-table" highlight-current-row>
        <el-table-column label="行号" width="55" align="center">
          <template slot-scope="{$index}"><span class="line-index">{{ $index + 1 }}</span></template>
        </el-table-column>
        <el-table-column prop="product_name" label="产品名称" min-width="140" show-overflow-tooltip>
          <template slot-scope="{row}">
            <span class="product-title">{{ row.product_name }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="sku_name" label="SKU规格" min-width="140" show-overflow-tooltip>
          <template slot-scope="{row}">
            <span class="sku-subtitle">{{ row.sku_name }}</span>
          </template>
        </el-table-column>
        <el-table-column label="系统Item编码" min-width="120" show-overflow-tooltip>
          <template slot-scope="{row}">
            <span v-if="lineItem(row).status === 'matched'" class="font-mono item-code-text">{{ lineItem(row).item_code }}</span>
            <el-tag v-else-if="lineItem(row).status === 'not_required'" size="mini" type="info">无需 Item</el-tag>
            <el-tag v-else size="mini" type="danger">Item 异常</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="系统Item名称" min-width="130" show-overflow-tooltip>
          <template slot-scope="{row}">
            <span v-if="lineItem(row).status === 'matched'">{{ lineItem(row).item_name }}</span>
            <span v-else-if="lineItem(row).status === 'not_required'" class="text-muted">—</span>
            <span v-else class="item-abnormal">{{ lineItem(row).message }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="order_qty" label="订单数量" width="85" align="right">
          <template slot-scope="{row}">
            <b class="font-mono">{{ row.order_qty }}</b>
          </template>
        </el-table-column>
        <el-table-column label="销售单价" width="100" align="right">
          <template slot-scope="{row}">
            <span class="font-mono price-cell">¥{{ money(row.unit_price) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="小计金额" width="115" align="right">
          <template slot-scope="{row}">
            <b class="font-mono amount-cell">¥{{ money(Number(row.order_qty || 0) * Number(row.unit_price || 0)) }}</b>
          </template>
        </el-table-column>
        <el-table-column label="配置摘要" min-width="120">
          <template slot-scope="{row}">
            <div class="config-tags-cell">
              <el-tag v-if="row.is_customized" size="mini" effect="plain">定制</el-tag>
              <el-tag v-if="row.is_special_customized" size="mini" type="danger" effect="plain">特殊定制</el-tag>
              <el-tag v-if="row.electric" size="mini" type="info" effect="plain">{{ row.electric }}</el-tag>
              <el-tag v-if="row.need_pump === true" size="mini" type="success" effect="plain">原水泵: 需要</el-tag>
              <el-tag v-else-if="row.need_pump === false" size="mini" type="info" effect="plain">原水泵: 不需要</el-tag>
              <span v-if="!row.is_customized && !row.is_special_customized && !row.electric && row.need_pump === null" class="text-muted">标准配置</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="图纸数" width="70" align="center">
          <template slot-scope="{row}">
            <span class="drawing-count-badge" :class="{ 'has-files': lineFiles(row).length > 0 }">
              {{ lineFiles(row).length }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="备货方式" width="92" align="center">
          <template slot-scope="{row}">
            <el-tag size="mini" :type="statusTag(row.fulfillment_type)">{{ statusText(row.fulfillment_type) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="处理状态" width="100" align="center">
          <template slot-scope="{row}">
            <el-tag size="mini" type="info" effect="plain">{{ statusText(row.line_status) }}</el-tag>
          </template>
        </el-table-column>
      </el-table>
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

    <!-- 下方业务追踪与审计中心 (Tab 控制台整合散落与空态卡片) -->
    <div ref="tabSection" class="detail-card tracking-console-card">
      <el-tabs v-model="activeTab" class="tracking-tabs">
        <!-- Tab 1: 工单与工序跟踪 -->
        <el-tab-pane name="work_orders">
          <span slot="label">
            <i class="el-icon-c-scale-to-original" /> 工单执行跟踪
            <span v-if="(order.work_order_tracking || []).length" class="tab-badge-num">{{ (order.work_order_tracking || []).length }}</span>
          </span>
          <div class="tab-pane-content">
            <div class="tab-pane-header">
              <span class="pane-subtitle">工单只读跟踪；不在销售订单草稿阶段创建工单。</span>
            </div>
            <div v-if="(order.work_order_tracking || []).length" class="work-order-grid">
              <div v-for="work in order.work_order_tracking" :key="work.work_order_no" class="work-order-card-item">
                <div class="wo-head">
                  <span class="wo-no font-mono font-semibold">{{ work.work_order_no }}</span>
                  <el-tag size="mini" type="info">订单行 {{ work.line_no }}</el-tag>
                </div>
                <div class="wo-body">
                  <div class="wo-field">
                    <span class="label">当前工序</span>
                    <b class="value">{{ work.current_process_name || '-' }}</b>
                  </div>
                  <div class="wo-field">
                    <span class="label">工单进度</span>
                    <el-tag size="mini" type="success" effect="plain">{{ work.progress_text || '-' }}</el-tag>
                  </div>
                </div>
              </div>
            </div>
            <el-empty v-else :image-size="48" description="当前订单尚未生成生产工单" />
          </div>
        </el-tab-pane>

        <!-- Tab 2: 合同附件与图纸技术资料 -->
        <el-tab-pane name="attachments">
          <span slot="label">
            <i class="el-icon-paperclip" /> 附件与资料
            <span v-if="totalAttachmentsCount" class="tab-badge-num">{{ totalAttachmentsCount }}</span>
          </span>
          <div class="tab-pane-content attachments-tab-body">
            <!-- 合同附件子区 -->
            <div class="sub-section">
              <div class="sub-section-title">
                <h4><i class="el-icon-document-checked" /> 合同附件 <small class="text-muted">（共 {{ contractFiles.length }} 个文件）</small></h4>
              </div>
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
              <el-empty v-else :image-size="40" description="暂无合同附件" />
            </div>

            <!-- 设计图纸与技术资料子区 -->
            <div class="sub-section drawing-sub-section">
              <div class="sub-section-title">
                <h4><i class="el-icon-picture-outline" /> 订单行设计图纸与技术附件</h4>
              </div>
              <div v-for="line in order.lines || []" :key="line.id" class="line-file-block">
                <div class="line-file-title">
                  <span class="line-badge">行 {{ line.line_no }}</span>
                  <span class="line-prod">{{ line.product_name }}</span>
                  <span class="line-sku text-muted">/ {{ line.sku_name }}</span>
                </div>
                <el-table :data="lineFiles(line)" border size="mini" empty-text="暂无图纸或技术附件">
                  <el-table-column prop="file_name" label="文件名称" min-width="140" show-overflow-tooltip />
                  <el-table-column label="版本" width="60" align="center"><template slot-scope="{row}">V{{ row.version_no || 1 }}</template></el-table-column>
                  <el-table-column label="说明" min-width="120" show-overflow-tooltip>
                    <template slot-scope="{row}">{{ row.remark || statusText(row.file_type) }}</template>
                  </el-table-column>
                  <el-table-column label="上传信息" min-width="130" show-overflow-tooltip>
                    <template slot-scope="{row}">{{ row.uploaded_by || '-' }} / {{ date(row.uploaded_at) }}</template>
                  </el-table-column>
                  <el-table-column label="操作" width="130" align="center">
                    <template slot-scope="{row}">
                      <el-button v-if="row.attachment_id && previewable(row)" type="text" size="mini" @click="previewAttachment(row)">预览</el-button>
                      <el-button v-if="row.attachment_id && row.can_download !== false" type="text" size="mini" @click="downloadAttachment(row)">下载</el-button>
                      <el-button v-if="canDeleteAttachment(row)" type="text" size="mini" class="danger-link" @click="deleteAttachment(row)">删除</el-button>
                      <span v-if="!row.attachment_id" class="text-muted">-</span>
                    </template>
                  </el-table-column>
                </el-table>
              </div>
            </div>
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
                    <el-timeline-item v-for="log in order.logs || []" :key="log.id" :timestamp="date(log.created_at)">
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
                  <el-table :data="order.versions || []" border size="mini" :max-height="320">
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
import { confirmSalesOrder, formalConfirmSalesOrder, deleteSalesOrderAttachment, deleteSalesOrderDraft, downloadSalesOrderAttachment, getSalesOrder, listSalesOrderChanges, lockSalesOrderInventory } from '@/api/erp/sales'
import SalesOrderAttachmentPreviewDialog from '@/components/sales/SalesOrderAttachmentPreviewDialog.vue'
import { statusTag, statusText } from '@/utils/erpStatus'

export default {
  components: { SalesOrderAttachmentPreviewDialog },
  data: () => ({
    activeTab: 'work_orders',
    order: {},
    previewVisible: false,
    previewFile: null,
    changeLoading: false,
    changes: { rows: [], total: 0, page: 1, per_page: 10 },
    selectedChange: null,
    changeDetailVisible: false,
    selectedCandidate: null,
    candidateDetailVisible: false,
    inventoryLockVisible: false,
    inventoryLockBusy: false
  }),
  computed: {
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
      const total = (this.order.lines || []).reduce((sum, line) => sum + Number(line.order_qty || 0), 0)
      return this.numberText(total)
    },
    totalAttachmentsCount() {
      let count = this.contractFiles.length
      ;(this.order.lines || []).forEach(line => {
        count += this.lineFiles(line).length
      })
      return count
    },
    contractFiles() {
      return this.mergeAttachmentFiles(
        this.parseContractAttachments(this.order.contract_attachments),
        (this.order.attachments || []).map(this.mapAttachment)
      )
    },
    currentVersion() { return Math.max(1, ...(this.order.versions || []).map(item => Number(item.version_no || 0))) },
    orderApprovalSteps() { const task=this.order.approval_task||{}; const steps=[{key:'submit',name:'发起提交',state:'done'},...(task.nodes||[]).map(n=>({key:n.node_key,name:n.node_name,state:n.node_status==='APPROVED'?'done':n.node_status==='PENDING'?'active':n.node_status==='REJECTED'?'failed':'wait'})),{key:'finish',name:'审核完成',state:task.task_status==='APPROVED'?'done':task.task_status==='REJECTED'?'failed':'wait'}]; return steps },
    orderApprovalCurrent() { const task=this.order.approval_task||{}; const node=(task.nodes||[]).find(n=>n.node_status==='PENDING'); return node ? node.node_name : (task.task_status==='APPROVED'?'审核完成':task.task_status==='REJECTED'?'已驳回':'—') },
    candidateHistory() { return [...(this.order.change_candidates || [])].sort((a, b) => Number(b.candidate_version || 0) - Number(a.candidate_version || 0)) },
    fulfillmentQuantities() { return this.order.fulfillment_quantities || { totals: {}, lines: [] } },
    lockTotals() { return this.fulfillmentQuantities.totals || {} },
    lockLines() { return this.fulfillmentQuantities.lines || [] }
  },
  async created() {
    await this.load()
  },
  methods: {
    async load() {
      const { data } = await getSalesOrder(this.$route.params.id)
      this.order = data
      await this.loadChanges(1)
      if (this.$route.query.tab === 'logs') {
        this.activeTab = 'logs'
        this.scrollToLogs()
      } else if (this.$route.query.tab === 'changes') {
        this.activeTab = 'changes'
        this.scrollToChanges()
      }
    },
    async doConfirm() {
      const response = await confirmSalesOrder(this.order.id)
      const result = response.data && response.data.data ? response.data.data : response.data
      const content = (result.checks || []).map(item => `<li class="${item.status}"><b>${item.status === 'passed' ? '通过' : '阻塞'}</b> ${item.message}</li>`).join('')
      const fallback = '<li class="blocked"><b>阻塞</b> 服务端未返回逐项检查结果，请勿进入下一阶段。</li>'
      const blocked = (result.checks || []).find(item => item.status === 'blocked')
      const passed = Boolean(result.passed) && !blocked
      try {
        await this.$alert(`<p>${result.message}</p><ul class="sales-precheck-list">${content || fallback}</ul>`, '确认前检查结果', {
          dangerouslyUseHTMLString: true,
          confirmButtonText: passed ? '关闭' : '返回编辑'
        })
      } catch (error) {
        return
      }
      if (!passed) {
        this.$router.push({
          path: `/sales/orders/${this.order.id}/edit`,
          query: blocked && blocked.field ? { focus: blocked.field } : {}
        })
      }
      await this.load()
    },
    async doFormalConfirm() {
      await this.$confirm('正式确认后将锁定本次销售单位、默认库存物料、物料基本单位和单位换算比例，并进入订单生产确认。是否继续？', '正式确认订单', { type: 'warning' })
      await formalConfirmSalesOrder(this.order.id)
      this.$message.success('订单已正式确认，单位换算信息已锁定')
      await this.load()
    },
    openInventoryLock() { this.inventoryLockVisible = true },
    async confirmInventoryLock() {
      if (this.inventoryLockBusy) return
      this.inventoryLockBusy = true
      try {
        const commandId = `sales-inventory-lock-${this.order.id}-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
        const response = await lockSalesOrderInventory(this.order.id, {
          client_command_id: commandId,
          expected_version: Number(this.order.business_version)
        })
        const result = response.data && response.data.data ? response.data.data : response.data
        this.inventoryLockVisible = false
        this.$message.success(Number(result.created_fulfillment_count || 0) > 0 ? '库存已正式锁定，生产缺口已重新计算' : '没有新增可锁库存，未重复占用')
        await this.load()
      } finally { this.inventoryLockBusy = false }
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
    approvalTypeText(value) { return ({business:'业务审核',finance:'财务审核',fulfillment:'库存与交付复核'})[value] || value },
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
      return v ? String(v).replace('T', ' ').slice(0, 16) : '-'
    },
    dateOnly(v) {
      return v ? String(v).slice(0, 10) : '-'
    },
    snapshotName(v) {
      if (!v) return '未锁定'
      return v.name || v.bom_name || v.version || '已锁定'
    },
    statusText,
    statusTag,
    fulfillmentStatusText(value) { return ({ pending: '待交付', partial: '部分交付', fulfilled: '已交付', cancelled: '已取消' })[value] || value || '-' },
    fulfillmentStatusTag(value) { return ({ pending: 'warning', partial: 'warning', fulfilled: 'success', cancelled: 'danger' })[value] || 'info' },
    lineItem(line) {
      if (line.system_item) return line.system_item
      const lineType = line.line_type || (line.sku_snapshot && (line.sku_snapshot.order_line_type || line.sku_snapshot.fulfillment_type)) || 'physical'
      if (['service', 'no_delivery', 'fee', 'auxiliary'].includes(lineType)) {
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

.inventory-lock-button {
  background: #dc2626 !important;
  border-color: #dc2626 !important;
  color: #ffffff !important;
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

/* 订单备注卡片 */
.order-remark-box {
  margin-top: 14px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 10px 12px;
}

.remark-title {
  font-size: 11px;
  font-weight: 600;
  color: #475569;
  margin-bottom: 4px;
  display: flex;
  align-items: center;
  gap: 4px;
}

.remark-content {
  font-size: 12px;
  color: #334155;
  line-height: 1.5;
  word-break: break-word;
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
  display: inline-block;
  padding: 1px 8px;
  border-radius: 10px;
  font-size: 11px;
  color: #94a3b8;
  background: #f1f5f9;
}

.drawing-count-badge.has-files {
  color: #0284c7;
  background: #e0f2fe;
  font-weight: 600;
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
.tracking-console-card {
  padding: 6px 16px 16px;
  scroll-margin-top: 12px;
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
</style>
