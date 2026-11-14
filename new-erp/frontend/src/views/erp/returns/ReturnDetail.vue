<template>
  <section v-loading="loading" class="return-detail-page" :class="`return-detail-page--${kind}`">
    <!-- 顶部白底页头卡片 -->
    <div class="page-head">
      <div class="head-left">
        <el-button circle size="small" icon="el-icon-arrow-left" @click="$router.push(basePath)" />
        <div class="head-titles">
          <div class="title-line">
            <h1 class="mono">{{ row.return_no || `${title}详情` }}</h1>
            <el-tag size="mini" :type="statusType(row.return_status)">{{ statusText(row.return_status) }}</el-tag>
            <el-tag v-if="kind === 'purchase' && row.management_scope" size="mini" :type="row.management_scope === 'office' ? 'info' : 'success'">
              {{ scopeLabel(row.management_scope) }}
            </el-tag>
            <el-tag v-if="kind === 'purchase'" size="mini" :type="row.stock_post_status === 'posted' ? 'success' : 'info'" effect="plain">
              {{ purchasePostText }}
            </el-tag>
            <el-tag v-if="row.aftersales && row.aftersales.case_no" size="mini" type="warning" effect="plain">
              售后单 <span class="link-text is-clickable" @click="$router.push(`/sales/aftersales/${row.aftersales.case_id}/detail`)">{{ row.aftersales.case_no }}</span>
            </el-tag>
          </div>
          <p class="subtitle">
            {{ kind === 'purchase' ? '采购退货单明细、审批进度、仓库批次过账及应付抵扣追溯' : '销售退货单详情、收货质量处置与退款冲减跟踪' }}
          </p>
        </div>
      </div>

      <div class="head-actions">
        <el-button size="small" plain icon="el-icon-refresh" @click="load">刷新</el-button>
        <el-button size="small" icon="el-icon-back" @click="$router.push(basePath)">返回列表</el-button>
        <el-button v-if="canCancel" size="small" type="danger" plain @click="cancelReturn">取消退货单</el-button>
        <el-button v-if="canClose" size="small" plain @click="closeReturn">关闭</el-button>
        <el-button v-if="kind === 'purchase' && !managementScopeIssue && row.return_status === 'draft' && $can('purchase_return.submit')" size="small" type="success" icon="el-icon-s-promotion" @click="submitPurchase">提交审核</el-button>
        <el-button v-if="kind === 'purchase' && !managementScopeIssue && row.return_status === 'submitted' && $can('purchase_return.approve')" size="small" type="success" icon="el-icon-check" @click="approve">审核通过</el-button>
        <el-button v-if="kind === 'purchase' && !managementScopeIssue && row.return_status === 'pending_outbound' && $can('purchase_return.post')" size="small" type="success" icon="el-icon-box" @click="postPurchase">
          {{ isRejectedBeforePosting ? '确认已退回供应商' : '退货出库过账' }}
        </el-button>
        <el-button
          v-if="canShowApOffset"
          size="small"
          type="success"
          :loading="saving"
          :disabled="saving || !apOffsetPreview.available"
          :title="apOffsetPreview.reason || ''"
          @click="offsetPayable"
        >
          退货抵扣应付
        </el-button>
        <el-button v-if="kind === 'sales' && ['pending_receipt', 'partial_received'].includes(row.return_status) && $can('sales_return.receive')" size="small" type="success" icon="el-icon-plus" @click="openReceive">登记退货到货</el-button>
      </div>
    </div>

    <el-alert v-if="managementScopeIssue" class="scope-alert" :title="managementScopeIssue" type="warning" :closable="false" show-icon />

    <div v-if="kind === 'purchase'" class="erp-page-tip">
      <i class="el-icon-info" />
      <span>{{ purchaseRuleText }}</span>
    </div>

    <!-- 三栏核心信息卡片 -->
    <div class="summary-grid">
      <div class="card summary-card">
        <div class="section-title-bar">
          <span class="bar-accent" />
          <h3>退货单信息</h3>
        </div>
        <dl>
          <dt>退货单号</dt><dd class="mono">{{ row.return_no || '-' }}</dd>
          <template v-if="kind === 'purchase'">
            <dt>管理类型</dt>
            <dd><el-tag size="mini" :type="row.management_scope === 'office' ? 'info' : 'success'">{{ scopeLabel(row.management_scope) }}</el-tag></dd>
            <dt>退货范围</dt><dd>{{ purchaseScopeText }}</dd>
          </template>
          <template v-else>
            <dt>退货类型</dt><dd>{{ returnTypeText }}</dd>
          </template>
          <dt>退货原因</dt><dd>{{ row.return_reason || '-' }}</dd>
          <dt>{{ kind === 'purchase' ? '退货数量' : '申请日期' }}</dt>
          <dd class="strong-num">{{ kind === 'purchase' ? totalRequested() : dateOnly(row.return_date) }}</dd>
          <dt>{{ kind === 'purchase' ? '退货金额（含税）' : '申请人' }}</dt>
          <dd class="strong-num">
            {{ kind === 'purchase' ? (row.amount_incl_tax != null ? money(row.amount_incl_tax) + '（未税 ' + money(totalAmount()) + '）' : money(totalAmount())) : (row.created_by_name || creatorName()) }}
          </dd>
          <dt>备注</dt><dd>{{ row.remark || '-' }}</dd>
        </dl>
      </div>

      <div class="card summary-card">
        <div class="section-title-bar">
          <span class="bar-accent" />
          <h3>{{ kind === 'purchase' ? '来源与供应商' : '原销售订单与客户' }}</h3>
        </div>
        <dl v-if="kind === 'purchase'">
          <dt>到货单</dt><dd class="link-text mono">{{ row.receipt && row.receipt.receipt_no || '-' }}</dd>
          <dt>采购订单</dt><dd class="link-text mono">{{ row.receipt && row.receipt.order && row.receipt.order.purchase_order_no || '-' }}</dd>
          <dt>供应商</dt><dd class="strong-text">{{ row.supplier && row.supplier.supplier_name || '-' }}</dd>
          <dt>结算币种</dt><dd>{{ row.receipt && row.receipt.order && row.receipt.order.currency || 'CNY' }}</dd>
          <dt>结算汇率</dt><dd class="mono">{{ row.receipt && row.receipt.order && row.receipt.order.exchange_rate || '1.000000' }}</dd>
          <dt>付款条件</dt><dd>{{ row.receipt && row.receipt.order && row.receipt.order.payment_terms || '-' }}</dd>
        </dl>
        <dl v-else>
          <dt>原销售订单</dt><dd>{{ row.order && row.order.sales_order_no || '-' }}</dd>
          <dt>客户名称</dt><dd>{{ row.customer_name_snapshot || row.order && row.order.customer_name || '-' }}</dd>
          <dt>联系人</dt><dd>{{ row.order && row.order.contact_name || '-' }}</dd>
          <dt>联系电话</dt><dd>{{ row.customer_phone_snapshot || row.order && row.order.customer_phone || '-' }}</dd>
          <dt>销售人员</dt><dd>{{ row.order && row.order.sales_user_name || '-' }}</dd>
          <dt>原订单日期</dt><dd>{{ dateOnly(row.order && row.order.order_date) }}</dd>
        </dl>
      </div>

      <div class="card summary-card">
        <div class="section-title-bar">
          <span class="bar-accent" />
          <h3>{{ kind === 'purchase' ? '状态与时间' : '状态进度' }}</h3>
        </div>
        <template v-if="kind === 'purchase'">
          <dl>
            <dt>退货状态</dt><dd><el-tag size="mini" :type="statusType(row.return_status)">{{ statusText(row.return_status) }}</el-tag></dd>
            <dt>过账状态</dt><dd>{{ purchasePostText }}</dd>
            <dt>应付处理</dt><dd>{{ settlementEffectText }}</dd>
            <dt>制单人</dt><dd>{{ row.created_by_name || creatorName() }}</dd>
            <dt>制单时间</dt><dd class="mono">{{ fmt(row.created_at) }}</dd>
            <dt>审核人</dt><dd>{{ row.approved_by_name || operatorName('approve') }}</dd>
            <dt>审核时间</dt><dd class="mono">{{ fmt(row.approved_at) }}</dd>
          </dl>
        </template>
        <template v-else>
          <div class="progress-track">
            <div v-for="(step, index) in progressSteps" :key="step" class="progress-node" :class="{ done: index <= currentStep }">
              <i :class="index < currentStep ? 'el-icon-check' : 'el-icon-more'" />
              <span>{{ step }}</span>
            </div>
          </div>
          <div class="progress-meta">
            <span>{{ dateOnly(row.return_date) }}<small>{{ row.created_by_name || creatorName() }}</small></span>
            <span>{{ fmt(row.confirmed_at) }}<small>{{ row.received_by_name || '-' }}</small></span>
            <span><small>待处理</small></span>
            <span><small>待处理</small></span>
          </div>
        </template>
      </div>
    </div>

    <!-- 退货明细 -->
    <div class="card detail-card">
      <div class="card-head-bar">
        <div class="section-title-bar">
          <span class="bar-accent" />
          <h3>退货明细</h3>
          <span class="section-badge">共 {{ (row.items || []).length }} 项</span>
        </div>
      </div>
      <el-table :data="row.items || []" border stripe size="mini">
        <template v-if="kind === 'purchase'">
          <el-table-column label="到货单" width="146"><template slot-scope="{row:item}"><span class="mono">{{ row.receipt && row.receipt.receipt_no || '-' }}</span></template></el-table-column>
          <el-table-column label="采购订单" width="146"><template slot-scope="{row:item}"><span class="mono">{{ row.receipt && row.receipt.order && row.receipt.order.purchase_order_no || '-' }}</span></template></el-table-column>
          <el-table-column label="物料信息" min-width="200">
            <template slot-scope="{row:item}">
              <span class="code-badge mono">{{ item.item && item.item.item_code }}</span>
              <span class="item-name-bold">{{ item.item && item.item.item_name }}</span>
            </template>
          </el-table-column>
          <el-table-column label="仓库" width="120"><template slot-scope="{row:item}">{{ item.warehouse && item.warehouse.warehouse_name || '-' }}</template></el-table-column>
          <el-table-column label="库位" width="105"><template slot-scope="{row:item}">{{ item.location && item.location.location_name || '-' }}</template></el-table-column>
          <el-table-column label="批次" width="140"><template slot-scope="{row:item}"><span class="mono">{{ item.batch_no || '-' }}</span></template></el-table-column>
          <el-table-column :label="isRejectedBeforePosting ? '到货拒收数' : '原入库数'" width="105" align="right"><template slot-scope="{row:item}">{{ number(item.source_posted_base_qty || item.requested_base_qty) }}</template></el-table-column>
          <el-table-column label="已退数" width="90" align="right"><template slot-scope="{row:item}">{{ number(item.previous_returned_base_qty || 0) }}</template></el-table-column>
          <el-table-column label="本次退货" width="135" align="right">
            <template slot-scope="{row:item}">
              <span class="qty-brand">{{ number(item.requested_return_qty || item.requested_base_qty) }} {{ item.return_unit_name_snapshot || (item.base_unit && item.base_unit.unit_name) || '' }}</span>
            </template>
          </el-table-column>
          <el-table-column label="基本数量" width="115" align="right"><template slot-scope="{row:item}">{{ number(item.requested_base_qty) }} {{ (item.base_unit && item.base_unit.unit_name) || '' }}</template></el-table-column>
          <el-table-column label="基本单位" width="90"><template slot-scope="{row:item}">{{ item.base_unit && item.base_unit.unit_name || '-' }}</template></el-table-column>
          <el-table-column label="设备编号 / 序列号" min-width="210">
            <template slot-scope="{row:item}">
              <span v-if="!(item.serial_links || []).length" class="muted-dash">-</span>
              <template v-else>
                <el-tag v-for="link in item.serial_links || []" :key="link.id" size="mini" type="success" effect="plain" class="serial-tag mono">{{ link.serial_no }}</el-tag>
              </template>
            </template>
          </el-table-column>
        </template>
        <template v-else>
          <el-table-column type="index" label="#" width="48" />
          <el-table-column label="Product" min-width="150"><template slot-scope="{row:item}">{{ item.order_line && item.order_line.product_name || '-' }}</template></el-table-column>
          <el-table-column label="SKU" min-width="140"><template slot-scope="{row:item}">{{ item.order_line && item.order_line.sku_name || '-' }}</template></el-table-column>
          <el-table-column label="原履约物料" min-width="180"><template slot-scope="{row:item}">{{ item.item && item.item.item_code || item.sales_order_line_id }}</template></el-table-column>
          <el-table-column label="申请退货" width="110" align="right"><template slot-scope="{row:item}">{{ number(item.requested_sales_qty || item.requested_base_qty) }}</template></el-table-column>
          <el-table-column label="已收货" width="100" align="right"><template slot-scope="{row:item}">{{ number(item.received_base_qty || 0) }}</template></el-table-column>
          <el-table-column label="剩余待收" width="105" align="right"><template slot-scope="{row:item}">{{ remaining(item) }}</template></el-table-column>
          <el-table-column label="基本单位" width="90"><template slot-scope="{row:item}">{{ item.base_unit && item.base_unit.unit_name || '-' }}</template></el-table-column>
        </template>
        <template v-if="kind === 'purchase'" slot="append">
          <div class="table-total">
            <strong>汇总合计</strong>
            <span>{{ isRejectedBeforePosting ? '到货拒收' : '原入库' }} <b>{{ totalSourceQty() }}</b></span>
            <span v-if="!isRejectedBeforePosting">已退 <b>{{ totalPostedQty() }}</b></span>
            <span>本次退货 <b class="qty-brand">{{ totalRequested() }}</b></span>
          </div>
        </template>
      </el-table>
    </div>

    <!-- 销售退货结算 -->
    <div v-if="kind === 'sales' && row.settlement" class="card settlement-card">
      <div class="section-title-bar">
        <span class="bar-accent" />
        <h3>退款与应收</h3>
      </div>
      <dl class="settlement-grid">
        <dt>本单冲减应收</dt><dd>{{ money(row.settlement.credit_amount) }}</dd>
        <dt>订单合同金额</dt><dd>{{ money(row.settlement.order_contract_amount) }}</dd>
        <dt>订单累计退货冲减</dt><dd>{{ money(row.settlement.order_return_credit_amount) }}</dd>
        <dt>订单净应收</dt><dd>{{ money(row.settlement.order_net_receivable_amount) }}</dd>
        <dt>订单净收款</dt><dd>{{ money(row.settlement.order_net_received_amount) }}</dd>
        <dt>已退款</dt><dd>{{ money(row.settlement.order_refunded_amount) }}</dd>
        <dt>应退客户</dt><dd :class="{ 'refund-due': Number(row.settlement.refund_due_amount) > 0 }">{{ money(row.settlement.refund_due_amount) }}</dd>
        <dt>仍未收</dt><dd>{{ money(row.settlement.outstanding_amount) }}</dd>
      </dl>
      <p v-if="Number(row.settlement.refund_due_amount) > 0" class="settlement-tip">退款请在财务付款单中核销“销售退款”来源（原销售订单 {{ row.order && row.order.sales_order_no }}），核销后订单自动完结。</p>
    </div>

    <!-- 销售退货收货记录 -->
    <div v-if="kind === 'sales'" class="card receipt-card">
      <div class="section-title-bar">
        <span class="bar-accent" />
        <h3>退货到货与质量处理</h3>
      </div>
      <el-table :data="row.receipts || []" border size="mini">
        <el-table-column type="index" label="#" width="48" />
        <el-table-column prop="receipt_no" label="到货单号" width="165" />
        <el-table-column label="到货数量" width="95" align="right"><template slot-scope="{row:r}">{{ sumReceipt(r, 'received_base_qty') }}</template></el-table-column>
        <el-table-column label="可重新入库" width="105" align="right"><template slot-scope="{row:r}">{{ sumReceipt(r, 'restock_base_qty') }}</template></el-table-column>
        <el-table-column label="待处理" width="90" align="right"><template slot-scope="{row:r}">{{ sumReceipt(r, 'pending_base_qty') }}</template></el-table-column>
        <el-table-column label="残次品" width="80" align="right"><template slot-scope="{row:r}">{{ sumReceipt(r, 'defective_base_qty') }}</template></el-table-column>
        <el-table-column label="报废" width="80" align="right"><template slot-scope="{row:r}">{{ sumReceipt(r, 'scrap_base_qty') }}</template></el-table-column>
        <el-table-column label="退回客户" width="95" align="right"><template slot-scope="{row:r}">{{ sumReceipt(r, 'rejected_base_qty') }}</template></el-table-column>
        <el-table-column label="仓库 / 库位 / 批次" min-width="190"><template slot-scope="{row:r}">{{ receiptLocation(r) }}</template></el-table-column>
        <el-table-column prop="stock_post_status" label="过账状态" width="100"><template slot-scope="{row:r}"><el-tag size="mini" :type="receiptPostType(r.stock_post_status)">{{ receiptPostText(r.stock_post_status) }}</el-tag></template></el-table-column>
        <el-table-column label="操作" width="200">
          <template slot-scope="{row:r}">
            <el-button v-if="r.stock_post_status === 'pending' && Number(sumReceipt(r, 'restock_base_qty')) + Number(sumReceipt(r, 'defective_base_qty')) > 0 && $can('sales_return.post')" type="text" size="mini" @click="postReceipt(r)">入库过账</el-button>
            <el-button v-if="Number(sumReceipt(r, 'pending_base_qty')) > 0 && ['partial_received', 'received'].includes(row.return_status) && $can('sales_return.receive')" type="text" size="mini" @click="openDispose(r)">处置待处理</el-button>
            <el-button v-if="receiptHasSerial(r)" type="text" size="mini" @click="openTrace(r)">序列号</el-button>
          </template>
        </el-table-column>
      </el-table>
    </div>

    <!-- 采购退货进度与单据追溯 -->
    <div v-if="kind === 'purchase'" class="lower-grid">
      <div class="card">
        <div class="section-title-bar">
          <span class="bar-accent" />
          <h3>{{ isRejectedBeforePosting ? '审批与退回进度' : '审批与出库进度' }}</h3>
        </div>
        <ul class="timeline">
          <li v-for="(step, index) in purchaseTimeline" :key="step.label" :class="{ done: index <= currentStep }">
            <i>{{ index + 1 }}</i>
            <strong>{{ step.label }}</strong>
            <span>{{ step.value }}</span>
            <time class="mono">{{ step.time }}</time>
          </li>
        </ul>
      </div>

      <div class="card">
        <div class="section-title-bar">
          <span class="bar-accent" />
          <h3>单据追溯</h3>
        </div>
        <ul class="trace-list">
          <li>
            <span>来源到货单</span>
            <strong class="link-text mono">{{ row.receipt && row.receipt.receipt_no || '-' }}</strong>
            <time class="mono">{{ dateOnly(row.receipt && row.receipt.receipt_date) }}</time>
          </li>
          <li>
            <span>采购订单</span>
            <strong class="link-text mono">{{ row.receipt && row.receipt.order && row.receipt.order.purchase_order_no || '-' }}</strong>
            <time class="mono">{{ dateOnly(row.receipt && row.receipt.order && row.receipt.order.order_date) }}</time>
          </li>
          <li>
            <span>{{ isRejectedBeforePosting ? '不合格品退回供应商' : '库存出库单（退货出库）' }}</span>
            <strong class="mono">{{ isRejectedBeforePosting ? (row.return_status === 'completed' ? '已确认退回' : '待确认') : (row.return_inventory_transaction_no || '-') }}</strong>
            <time>{{ isRejectedBeforePosting ? '不产生库存出库' : (row.stock_post_status === 'posted' ? '已生成' : '未生成') }}</time>
          </li>
        </ul>
      </div>
    </div>

    <!-- 操作日志 -->
    <div class="card log-card">
      <div class="section-title-bar">
        <span class="bar-accent" />
        <h3>操作日志</h3>
        <span class="section-badge">{{ (row.logs || []).length }} 条记录</span>
      </div>
      <el-table :data="row.logs || []" border stripe size="mini" empty-text="暂无操作日志">
        <el-table-column prop="created_at" label="时间" width="170"><template slot-scope="{row:log}"><span class="mono">{{ fmt(log.created_at) }}</span></template></el-table-column>
        <el-table-column prop="operator_name" label="操作人" width="130" />
        <el-table-column label="操作" width="180"><template slot-scope="{row:log}">{{ actionText(log.action) }}</template></el-table-column>
        <el-table-column label="变更前状态" width="160"><template slot-scope="{row:log}">{{ statusText(log.from_status) }}</template></el-table-column>
        <el-table-column label="变更后状态" width="160"><template slot-scope="{row:log}">{{ statusText(log.to_status) }}</template></el-table-column>
        <el-table-column prop="content" label="备注" min-width="180" />
      </el-table>
    </div>

    <!-- 销售退货抽屉与弹窗（保持全部功能） -->
    <el-drawer title="登记退货到货" :visible.sync="receiveVisible" size="520px" append-to-body>
      <div class="drawer-body">
        <div v-for="line in receiveItems" :key="line.sales_return_item_id" class="receipt-line">
          <h4>{{ line.item_label }}</h4>
          <el-form label-width="100px" size="small">
            <el-form-item v-if="line.serial_required" label="序列号" required>
              <el-button size="mini" :type="line.serial_picks.length ? 'success' : 'danger'" plain @click="openSerialPick(line)">{{ line.serial_picks.length ? `已选 ${line.serial_picks.length} 件，修改` : '逐件选择序列号' }}</el-button>
              <div class="serial-hint">序列商品按所选序列号及去向自动计算数量</div>
            </el-form-item>
            <el-form-item label="本次收到"><el-input-number v-model="line.received_base_qty" :min="0.01" :max="line.max_qty" :precision="4" :disabled="line.serial_required" /></el-form-item>
            <el-form-item label="可重新入库"><el-input-number v-model="line.restock_base_qty" :min="0" :max="line.received_base_qty" :precision="4" :disabled="line.serial_required" /></el-form-item>
            <el-form-item label="待处理"><el-input-number v-model="line.pending_base_qty" :min="0" :max="line.received_base_qty" :precision="4" :disabled="line.serial_required" /></el-form-item>
            <el-form-item label="报废"><el-input-number v-model="line.scrap_base_qty" :min="0" :max="line.received_base_qty" :precision="4" :disabled="line.serial_required" /></el-form-item>
            <el-form-item label="退回客户"><el-input-number v-model="line.rejected_base_qty" :min="0" :max="line.received_base_qty" :precision="4" :disabled="line.serial_required" /></el-form-item>
            <template v-if="line.restock_base_qty > 0">
              <el-form-item label="仓库" required><el-select v-model="line.warehouse_id" filterable remote :remote-method="searchWarehouses"><el-option v-for="item in warehouses" :key="item.id" :label="item.warehouse_name" :value="item.id" /></el-select></el-form-item>
              <el-form-item label="库位" required><el-select v-model="line.location_id" filterable remote :remote-method="searchLocations"><el-option v-for="item in locations" :key="item.id" :label="item.location_name" :value="item.id" /></el-select></el-form-item>
              <el-form-item label="批次" required><el-input v-model.trim="line.batch_no" :disabled="line.serial_required" /></el-form-item>
            </template>
          </el-form>
        </div>
        <div class="drawer-actions"><el-button @click="receiveVisible = false">取消</el-button><el-button type="success" :loading="saving" @click="saveReceipt">保存</el-button></div>
      </div>
    </el-drawer>

    <el-dialog title="处置待处理数量" :visible.sync="disposeVisible" width="480px" custom-class="master-style-dialog" append-to-body>
      <el-form label-width="100px" size="small">
        <el-form-item label="到货明细"><el-select v-model="dispose.receipt_item_id" style="width: 100%" @change="changeDisposeLine"><el-option v-for="i in disposeLines" :key="i.id" :label="`${i.item && i.item.item_code || ''} 待处理 ${number(i.pending_base_qty)}`" :value="i.id" /></el-select></el-form-item>
        <el-form-item v-if="row.aftersales && row.aftersales.planned_disposition" label="售后计划处置"><el-tag size="mini" type="warning" effect="plain">{{ dispositionText(row.aftersales.planned_disposition) }}</el-tag><span class="serial-hint"> 售后单里定的计划，仓库可按实物情况改</span></el-form-item>
        <el-form-item label="处置方式"><el-radio-group v-model="dispose.disposition"><el-radio label="defective">转残次品</el-radio><el-radio label="restock">转入库</el-radio><el-radio label="scrap">报废</el-radio><el-radio label="rejected">退回客户</el-radio></el-radio-group></el-form-item>
        <el-form-item v-if="disposeSerialRequired" label="序列号" required><paged-record-select v-if="disposeVisible" v-model="dispose.serial_ids" :request="listDisposeSerials" :params="disposeSerialParams" :columns="[{ prop: 'serial_no', label: '序列号' }, { prop: 'batch_no', label: '原发货批次' }, { prop: 'shipment_no', label: '原发货单' }]" :label="serialLabel" multiple title="选择待处理序列号" placeholder="搜索并选择待处理序列号" @change="dispose.base_qty = dispose.serial_ids.length" /></el-form-item>
        <el-form-item label="数量"><el-input-number v-model="dispose.base_qty" :min="disposeSerialRequired ? 0 : 0.0001" :max="disposeMax" :precision="4" :disabled="disposeSerialRequired" /></el-form-item>
        <template v-if="['restock', 'defective'].includes(dispose.disposition)">
          <el-form-item label="仓库" required><el-select v-model="dispose.warehouse_id" filterable remote :remote-method="searchWarehouses" style="width: 100%"><el-option v-for="item in warehouses" :key="item.id" :label="item.warehouse_name" :value="item.id" /></el-select></el-form-item>
          <el-form-item label="库位" required><el-select v-model="dispose.location_id" filterable remote :remote-method="searchLocations" style="width: 100%"><el-option v-for="item in locations" :key="item.id" :label="item.location_name" :value="item.id" /></el-select></el-form-item>
          <el-form-item label="批次" required><el-input v-model.trim="dispose.batch_no" :disabled="disposeSerialRequired" /></el-form-item>
        </template>
        <el-form-item label="备注"><el-input v-model.trim="dispose.remark" maxlength="200" /></el-form-item>
        <p v-if="dispose.disposition === 'rejected'" class="settlement-tip">退回客户的数量不冲减应收。</p>
        <p v-if="dispose.disposition === 'restock'" class="settlement-tip">转入库会生成处置到货单，需再点“入库过账”按原发货成本入库。</p>
      </el-form>
      <span slot="footer"><el-button size="small" @click="disposeVisible = false">取消</el-button><el-button size="small" type="success" :loading="saving" @click="saveDispose">确认处置</el-button></span>
    </el-dialog>

    <el-dialog title="逐件选择退货序列号" :visible.sync="serialPick.visible" width="720px" custom-class="master-style-dialog" append-to-body>
      <div class="serial-toolbar"><el-input v-model.trim="serialPick.keyword" size="small" clearable placeholder="搜索序列号" @keyup.enter.native="loadSerialCandidates(1)" @clear="loadSerialCandidates(1)" /><el-button size="small" @click="loadSerialCandidates(1)">查询</el-button><span>已选 {{ serialPick.picks.length }} / 最多 {{ serialPick.line && serialPick.line.max_qty }}</span></div>
      <el-table v-loading="serialPick.loading" :data="serialPick.rows" border size="mini"><el-table-column prop="serial_no" label="序列号" min-width="170" /><el-table-column prop="batch_no" label="原发货批次" width="120" /><el-table-column prop="shipment_no" label="原发货单" min-width="150" /><el-table-column label="处理去向" width="140"><template slot-scope="{row:s}"><el-select :value="pickDisposition(s.id)" size="mini" placeholder="不退" clearable @change="setPick(s, $event)"><el-option label="可重新入库" value="restock" /><el-option label="待处理" value="pending" /><el-option label="报废" value="scrap" /><el-option label="退回客户" value="rejected" /></el-select></template></el-table-column></el-table>
      <el-pagination small layout="total, prev, pager, next" :current-page="serialPick.page" :page-size="10" :total="serialPick.total" @current-change="loadSerialCandidates" />
      <span slot="footer"><el-button size="small" @click="serialPick.visible = false">取消</el-button><el-button size="small" type="success" @click="applySerialPick">确定</el-button></span>
    </el-dialog>

    <el-dialog title="到货序列号追溯" :visible.sync="trace.visible" width="760px" custom-class="master-style-dialog" append-to-body>
      <el-table v-loading="trace.loading" :data="trace.rows" border size="mini"><el-table-column prop="serial_no" label="序列号" min-width="160" /><el-table-column label="处理去向" width="110"><template slot-scope="{row:s}">{{ dispositionText(s.disposition) }}</template></el-table-column><el-table-column prop="batch_no" label="批次" width="110" /><el-table-column prop="shipment_no" label="原发货单" min-width="150" /><el-table-column label="入库" width="90"><template slot-scope="{row:s}">{{ s.inventory_transaction_item_id ? '已入库' : (s.disposition === 'restock' ? '待过账' : '-') }}</template></el-table-column><el-table-column label="到货时间" width="140"><template slot-scope="{row:s}">{{ fmt(s.received_at) }}</template></el-table-column></el-table>
      <el-pagination small layout="total, prev, pager, next" :current-page="trace.page" :page-size="10" :total="trace.total" @current-change="loadTrace" />
    </el-dialog>
  </section>
</template>

<script>
import { erpDateTime } from '../../../utils/dateTime'
import { purchaseScopeLabel, purchaseScopeIssue } from '@/utils/purchaseManagementScope.mjs'
import { approvePurchaseReturn, cancelPurchaseReturn, closePurchaseReturn, getPurchaseReturn, offsetPurchaseReturnPayable, postPurchaseReturn, submitPurchaseReturn } from '@/api/erp/purchase'
import { cancelSalesReturn, closeSalesReturn, disposeSalesReturn, getSalesReturn, listSalesReturnReceiptSerials, listSalesReturnSerialCandidates, postSalesReturnReceipt, receiveSalesReturn } from '@/api/erp/sales'
import { listEntity } from '@/api/erp/master'
import { allocationKey } from '@/utils/financeCashAllocation'
import PagedRecordSelect from '@/components/common/PagedRecordSelect.vue'

export default {
  components: { PagedRecordSelect },
  props: { kind: { type: String, required: true } },
  data: () => ({
    loading: false,
    saving: false,
    row: {},
    apOffsetRequest: null,
    receiveVisible: false,
    receiveItems: [],
    warehouses: [],
    locations: [],
    disposeVisible: false,
    disposeLines: [],
    dispose: {},
    serialPick: { visible: false, loading: false, line: null, rows: [], total: 0, page: 1, keyword: '', picks: [] },
    trace: { visible: false, loading: false, receipt: null, rows: [], total: 0, page: 1 }
  }),
  computed: {
    returnTypeText() { return (this.row.terms && this.row.terms.return_type) === 'exchange' ? '换货（不冲减应收）' : '退货退款（冲减应收）' },
    disposeSerialRequired() { return this.isSerialReturnItem(this.disposeLineItemId) },
    disposeLineItemId() { const line = this.disposeLines.find(i => i.id === this.dispose.receipt_item_id); return line && line.sales_return_item_id },
    disposeSerialParams() { const line = this.disposeLines.find(i => i.id === this.dispose.receipt_item_id); return { return_id: this.row.id, receipt_id: line && line.receipt_id, line_id: this.dispose.receipt_item_id, disposition: 'pending' } },
    disposeMax() { const line = this.disposeLines.find(i => i.id === this.dispose.receipt_item_id); return line ? Number(line.pending_base_qty) : 0 },
    managementScopeIssue() { return this.kind === 'purchase' ? purchaseScopeIssue(this.row) : '' },
    apOffsetPreview() { return this.row.ap_offset_preview || {} },
    canShowApOffset() { return this.kind === 'purchase' && !this.managementScopeIssue && this.row.return_status === 'completed' && this.row.settlement_effect_type === 'PENDING' && this.$can('purchase_return.view') && this.$can('finance.allocation.create') },
    settlementEffectText() { return ({ PENDING: '待处理', AP_OFFSET: '已抵扣应付', SUPPLIER_REFUND: '供应商退款', REFUSE_PAY: '不予付款' })[this.row.settlement_effect_type] || '待核对' },
    title() { return this.kind === 'purchase' ? '采购退货' : '销售退货' },
    basePath() { return this.kind === 'purchase' ? '/purchase/returns' : '/sales/returns' },
    isRejectedBeforePosting() { return this.kind === 'purchase' && this.row.return_scope === 'rejected_before_posting' },
    purchaseScopeText() { return this.row.return_scope === 'rejected_before_posting' ? '到货拒收（未入库）' : '已入库退货' },
    purchasePostText() { return this.row.stock_post_status === 'posted' ? '已过账' : this.row.stock_post_status === 'not_required' ? '无需过账' : '未过账' },
    purchaseRuleText() { return this.isRejectedBeforePosting ? '规则说明：该不合格品从未入库；审核后只确认实物已退回供应商，不产生库存出库和库存流水。' : '规则说明：已入库退货必须使用原到货单的仓库、库位和批次，确认后产生退货出库流水。' },
    progressSteps() { return this.kind === 'purchase' ? ['制单', '审核', this.isRejectedBeforePosting ? '确认退回供应商' : '退货出库过账'] : ['退货申请', '部分收货', '质量处理', '退货完结'] },
    currentStep() { const maps = this.kind === 'purchase' ? { draft: 0, submitted: 0, approved: 1, pending_outbound: 1, completed: 2 } : { draft: 0, pending_receipt: 0, partial_received: 1, received: 2, completed: 3 }; return maps[this.row.return_status] || 0 },
    purchaseTimeline() {
      return [
        { label: '制单', value: this.row.created_by_name || this.creatorName(), time: this.fmt(this.row.created_at) },
        { label: '审核', value: this.row.approved_by_name || this.operatorName('approve'), time: this.fmt(this.row.approved_at) },
        { label: this.isRejectedBeforePosting ? '确认退回供应商' : '退货出库过账', value: this.row.return_status === 'completed' ? '已执行' : '待执行', time: this.fmt(this.row.posted_at) }
      ]
    },
    canCancel() { return this.kind === 'purchase' ? ['draft', 'submitted'].includes(this.row.return_status) && this.$can('purchase_return.cancel') : ['draft', 'pending_receipt'].includes(this.row.return_status) && this.$can('sales_return.cancel') && !(this.row.items || []).some(i => Number(i.received_base_qty) > 0) },
    canClose() { return this.kind === 'purchase' ? this.row.return_status === 'pending_outbound' && this.$can('purchase_return.close') : ['pending_receipt', 'partial_received', 'received'].includes(this.row.return_status) && this.$can('sales_return.close') }
  },
  created() { this.load() },
  methods: {
    scopeLabel(scope) { return purchaseScopeLabel(scope) },
    async load() {
      this.loading = true
      try {
        const { data } = await (this.kind === 'purchase' ? getPurchaseReturn(this.$route.params.id) : getSalesReturn(this.$route.params.id))
        this.row = data.data || data
        if (this.kind === 'sales' && this.$route.query.receive === '1') this.$nextTick(this.openReceive)
      } finally { this.loading = false }
    },
    statusText(v) {
      if (v === 'pending_outbound' && this.isRejectedBeforePosting) return '待退回供应商'
      return ({ draft: '草稿', submitted: '待审核', approved: '已审核', pending_outbound: '待出库', pending_receipt: '待收货', partial_received: '部分收货', received: '已收货', completed: '已完成', cancelled: '已取消', closed: '已关闭' })[v] || v || '-'
    },
    statusType(v) { return ({ completed: 'success', approved: 'success', received: 'success', cancelled: 'danger', closed: 'info', submitted: 'warning', pending_outbound: 'warning', pending_receipt: 'warning', partial_received: 'warning' })[v] || 'info' },
    actionText(v) { return ({ create: '创建退货单', submit: '提交审核', approve: '审核通过', confirm: '确认', confirm_supplier_return: '确认已退回供应商', receive: '登记退货到货', post_receipt: '退货入库过账', post: '退货出库过账', apply_ap_offset: '退货抵扣应付', dispose: '处置待处理', cancel: '取消', close: '关闭' })[v] || v || '-' },
    creatorName() { const log = (this.row.logs || []).find(item => item.action === 'create'); return log && log.operator_name || '-' },
    operatorName(action) { const log = (this.row.logs || []).find(item => item.action === action); return log && log.operator_name || '-' },
    receiptPostText(v) { return ({ posted: '已过账', not_required: '无需过账', pending: '未过账' })[v] || v || '-' },
    receiptPostType(v) { return v === 'posted' ? 'success' : v === 'not_required' ? 'info' : 'warning' },
    fmt(v) { return v ? erpDateTime(v, 16) : '-' },
    dateOnly(v) { return v ? String(v).slice(0, 10) : '-' },
    number(v) { return Number(v || 0).toFixed(2) },
    money(v) { return `¥ ${Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}` },
    totalRequested() { return this.number((this.row.items || []).reduce((s, i) => s + Number(i.requested_base_qty || i.requested_sales_qty || 0), 0)) },
    totalSourceQty() { return this.number((this.row.items || []).reduce((s, i) => s + Number(i.source_posted_base_qty || i.requested_base_qty || 0), 0)) },
    totalPostedQty() { return this.number((this.row.items || []).reduce((s, i) => s + Number(i.previous_returned_base_qty || 0), 0)) },
    totalAmount() { return (this.row.items || []).reduce((s, i) => s + Number(i.requested_base_qty || 0) * Number(i.unit_cost_snapshot || 0), 0) },
    remaining(i) { return this.number(Math.max(0, Number(i.requested_base_qty || i.requested_sales_qty || 0) - Number(i.received_base_qty || 0))) },
    sumReceipt(r, key) { return this.number((r.items || []).reduce((s, i) => s + Number(i[key] || 0), 0)) },
    receiptLocation(r) { const i = (r.items || [])[0] || {}; return `${i.warehouse && i.warehouse.warehouse_name || '-'} / ${i.location && i.location.location_name || '-'} / ${i.batch_no || '-'}` },
    async cancelReturn() { await this.$confirm('取消后不会产生库存流水，确认取消该退货单？', '取消退货单', { type: 'warning' }); await (this.kind === 'purchase' ? cancelPurchaseReturn(this.row.id) : cancelSalesReturn(this.row.id)); this.$message.success('退货单已取消'); this.load() },
    async closeReturn() { await this.$confirm('确认关闭剩余未执行数量？已发生的业务事实不会被删除。', '关闭未完成数量', { type: 'warning' }); await (this.kind === 'purchase' ? closePurchaseReturn(this.row.id) : closeSalesReturn(this.row.id)); this.$message.success('退货单已关闭'); this.load() },
    async submitPurchase() { if (this.managementScopeIssue) return this.$message.warning(this.managementScopeIssue); await this.$confirm('确认提交该采购退货单进入审核？', '提交审核'); await submitPurchaseReturn(this.row.id); this.$message.success('已提交审核'); this.load() },
    async approve() { if (this.managementScopeIssue) return this.$message.warning(this.managementScopeIssue); await this.$confirm('确认审核通过该采购退货单？', '审核确认'); await approvePurchaseReturn(this.row.id); this.$message.success('审核通过'); this.load() },
    async postPurchase() {
      if (this.managementScopeIssue) return this.$message.warning(this.managementScopeIssue)
      const rejected = this.isRejectedBeforePosting
      await this.$confirm(rejected ? '确认不合格实物已经退回供应商？本操作不产生库存出库。' : '确认按原仓库、库位和批次执行退货出库？', rejected ? '确认退回供应商' : '出库过账', { type: 'warning' })
      await postPurchaseReturn(this.row.id)
      this.$message.success(rejected ? '已确认退回供应商' : '出库过账完成')
      this.load()
    },
    async offsetPayable() {
      if (this.saving || !this.canShowApOffset || !this.apOffsetPreview.available) return
      const preview = this.apOffsetPreview
      await this.$confirm(`原到货：${preview.receipt_no}；抵扣金额：人民币 ${preview.amount} 元；说明：${preview.audit_reason}。确认将本退货整笔抵扣原到货应付？`, '退货抵扣应付', { type: 'warning', confirmButtonText: '确认抵扣' })
      if (!this.apOffsetRequest || this.apOffsetRequest.expected_fingerprint !== preview.expected_fingerprint) this.apOffsetRequest = { expected_fingerprint: preview.expected_fingerprint, idempotency_key: allocationKey() }
      this.saving = true
      let completed = false
      try { await offsetPurchaseReturnPayable(this.row.id, this.apOffsetRequest); completed = true; this.$message.success('退货已整笔抵扣原到货应付'); await this.load(); this.apOffsetRequest = null }
      catch (error) {
        if (completed) this.$message.error('退货已整笔抵扣应付，详情刷新失败，请刷新核对。')
        else if (error.response && error.response.status >= 400 && error.response.status < 500) this.$message.error(error.userMessage || '退货抵扣未完成，请刷新后核对。')
        else this.$message.error('抵扣结果尚未确认，请使用原请求重试核对。')
      } finally { this.saving = false }
    },
    async postReceipt(r) { await this.$confirm('确认仅将检验合格的可重新入库数量过账？', '入库过账'); await postSalesReturnReceipt(this.row.id, r.id); this.$message.success('退货入库已过账'); this.load() },
    openReceive() {
      this.receiveItems = (this.row.items || []).map(item => {
        const remain = Math.max(0, Number(item.requested_base_qty) - Number(item.received_base_qty || 0))
        return { sales_return_item_id: item.id, item_label: `${item.item && item.item.item_code || ''} / ${item.item && item.item.item_name || ''}`, max_qty: remain, received_base_qty: remain, restock_base_qty: remain, pending_base_qty: 0, scrap_base_qty: 0, rejected_base_qty: 0, warehouse_id: null, location_id: null, batch_no: '', serial_required: !!item.serial_required, serial_picks: [] }
      }).filter(i => i.max_qty > 0)
      this.receiveItems.filter(i => i.serial_required).forEach(i => { i.received_base_qty = 0; i.restock_base_qty = 0 })
      if (!this.receiveItems.length) return this.$message.info('没有剩余待收数量')
      this.receiveVisible = true
      this.searchWarehouses('')
      this.searchLocations('')
    },
    async searchWarehouses(keyword) { const { data } = await listEntity('warehouses', { keyword, status: 'active', page: 1, per_page: 10 }); this.warehouses = data.data || [] },
    async searchLocations(keyword) { const { data } = await listEntity('locations', { keyword, status: 'active', page: 1, per_page: 10 }); this.locations = data.data || [] },
    openDispose(r) {
      this.disposeLines = (r.items || []).filter(i => Number(i.pending_base_qty) > 0)
      const first = this.disposeLines[0] || {}
      this.dispose = { receipt_item_id: first.id, disposition: this.row.aftersales && this.row.aftersales.planned_disposition || 'restock', base_qty: Number(first.pending_base_qty || 0), warehouse_id: first.warehouse_id || null, location_id: first.location_id || null, batch_no: first.batch_no || '', remark: '', serial_ids: [] }
      this.changeDisposeLine()
      this.disposeVisible = true
      this.searchWarehouses('')
      this.searchLocations('')
    },
    isSerialReturnItem(itemId) { const item = (this.row.items || []).find(i => i.id === itemId); return !!(item && item.serial_required) },
    receiptHasSerial(r) { return (r.items || []).some(i => this.isSerialReturnItem(i.sales_return_item_id)) },
    dispositionText(v) { return ({ restock: '可重新入库', defective: '转残次品', pending: '待处理', scrap: '报废', rejected: '退回客户' })[v] || v || '-' },
    changeDisposeLine() { const line = this.disposeLines.find(i => i.id === this.dispose.receipt_item_id) || {}; this.dispose.serial_ids = []; this.dispose.base_qty = this.disposeSerialRequired ? 0 : this.disposeMax; this.dispose.warehouse_id = line.warehouse_id || null; this.dispose.location_id = line.location_id || null; this.dispose.batch_no = line.batch_no || '' },
    listDisposeSerials({ return_id, receipt_id, ...params }) { return listSalesReturnReceiptSerials(return_id, receipt_id, params) },
    serialLabel(serial) { return serial.serial_no },
    openSerialPick(line) { this.serialPick = { visible: true, loading: false, line, rows: [], total: 0, page: 1, keyword: '', picks: line.serial_picks.map(p => ({ ...p })) }; this.loadSerialCandidates(1) },
    async loadSerialCandidates(page) { this.serialPick.page = page || 1; this.serialPick.loading = true; try { const { data } = await listSalesReturnSerialCandidates(this.row.id, { return_item_id: this.serialPick.line.sales_return_item_id, keyword: this.serialPick.keyword || undefined, page: this.serialPick.page, per_page: 10 }); this.serialPick.rows = data.data || []; this.serialPick.total = data.total || 0 } finally { this.serialPick.loading = false } },
    pickDisposition(id) { const p = this.serialPick.picks.find(x => x.id === id); return p ? p.disposition : '' },
    setPick(s, disposition) { const picks = this.serialPick.picks.filter(x => x.id !== s.id); if (disposition) { if (picks.length >= Number(this.serialPick.line.max_qty)) return this.$message.warning('已达到剩余待收数量'); if (picks.length && picks[0].batch_no !== s.batch_no) return this.$message.warning('同一次收货只能选择同一原发货批次，不同批次请分次收货'); picks.push({ id: s.id, serial_no: s.serial_no, batch_no: s.batch_no, disposition }) } this.serialPick.picks = picks },
    applySerialPick() { const line = this.serialPick.line; const picks = this.serialPick.picks; const count = k => picks.filter(p => p.disposition === k).length; line.serial_picks = picks; line.received_base_qty = picks.length; line.restock_base_qty = count('restock'); line.pending_base_qty = count('pending'); line.scrap_base_qty = count('scrap'); line.rejected_base_qty = count('rejected'); line.batch_no = picks.length ? picks[0].batch_no : ''; this.serialPick.visible = false },
    openTrace(r) { this.trace = { visible: true, loading: false, receipt: r, rows: [], total: 0, page: 1 }; this.loadTrace(1) },
    async loadTrace(page) { this.trace.page = page || 1; this.trace.loading = true; try { const { data } = await listSalesReturnReceiptSerials(this.row.id, this.trace.receipt.id, { page: this.trace.page, per_page: 10 }); this.trace.rows = data.data || []; this.trace.total = data.total || 0 } finally { this.trace.loading = false } },
    async saveDispose() { const d = this.dispose; if (this.disposeSerialRequired && !d.serial_ids.length) return this.$message.error('序列商品必须逐件选择要处置的序列号'); if (!d.receipt_item_id || !(Number(d.base_qty) > 0)) return this.$message.error('请选择到货明细并填写数量'); if (['restock', 'defective'].includes(d.disposition) && (!d.warehouse_id || !d.location_id || !d.batch_no)) return this.$message.error('入库/转残次品须选择仓库、库位并填写批次'); this.saving = true; try { await disposeSalesReturn(this.row.id, d); this.$message.success('待处理数量已处置'); this.disposeVisible = false; this.load() } finally { this.saving = false } },
    async saveReceipt() { if (this.receiveItems.some(i => i.serial_required && !i.serial_picks.length)) return this.$message.error('序列商品必须逐件选择序列号'); const invalid = this.receiveItems.some(i => Math.abs(Number(i.received_base_qty) - Number(i.restock_base_qty) - Number(i.pending_base_qty) - Number(i.scrap_base_qty) - Number(i.rejected_base_qty)) > 0.000001 || (i.restock_base_qty > 0 && (!i.warehouse_id || !i.location_id || !i.batch_no))); if (invalid) return this.$message.error('请检查数量守恒；可重新入库时仓库、库位和批次必填'); this.saving = true; try { await receiveSalesReturn(this.row.id, { items: this.receiveItems.map(({ serial_picks, serial_required, max_qty, item_label, ...rest }) => serial_required ? { ...rest, serial_dispositions: ['restock', 'pending', 'scrap', 'rejected'].reduce((m, k) => ({ ...m, [k]: serial_picks.filter(p => p.disposition === k).map(p => p.id) }), {}) } : rest) }); this.$message.success('退货到货已登记'); this.receiveVisible = false; this.load() } finally { this.saving = false } }
  }
}
</script>

<style scoped>
.return-detail-page {
  padding: 16px 20px 24px;
  min-height: calc(100vh - 52px);
  background: #f5f7f9;
  color: #0f172a;
  box-sizing: border-box;
}

.mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}

/* 顶部页头卡片 */
.page-head {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 14px 20px;
  margin-bottom: 14px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
}

.head-left {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}

.head-titles {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}

.title-line {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}

.title-line h1 {
  margin: 0;
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

.scope-alert {
  margin-bottom: 14px;
  border-radius: 8px;
}

/* 卡片与标题装饰 */
.summary-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 14px;
  margin-bottom: 14px;
}

.card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 16px 18px;
  margin-bottom: 14px;
  min-width: 0;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
}

.summary-grid .card {
  margin-bottom: 0;
}

.section-title-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 14px;
}

.bar-accent {
  width: 4px;
  height: 15px;
  border-radius: 2px;
  background: #008b4b;
  flex-shrink: 0;
}

.section-title-bar h3 {
  margin: 0;
  font-size: 15px;
  font-weight: 700;
  color: #0f172a;
}

.section-badge {
  padding: 1px 8px;
  border-radius: 999px;
  background: #f1f5f9;
  color: #475569;
  font-size: 11px;
  font-weight: 600;
}

.summary-card dl {
  display: grid;
  grid-template-columns: 108px minmax(0, 1fr);
  gap: 10px 8px;
  margin: 0;
  font-size: 13px;
}

.summary-card dt {
  color: #64748b;
}

.summary-card dd {
  margin: 0;
  overflow-wrap: anywhere;
  color: #0f172a;
}

.strong-num {
  font-weight: 700;
  color: #0f172a;
}

.strong-text {
  font-weight: 600;
}

.link-text {
  color: #008b4b;
  font-weight: 600;
}

.link-text.is-clickable {
  cursor: pointer;
}

.link-text.is-clickable:hover {
  color: #00763f;
  text-decoration: underline;
}

/* 明细卡片 */
.detail-card {
  padding: 0;
  overflow: hidden;
}

.card-head-bar {
  padding: 14px 18px 0;
}

.code-badge {
  display: inline-block;
  padding: 1px 6px;
  border-radius: 4px;
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  color: #334155;
  font-size: 11px;
  font-weight: 600;
  margin-right: 6px;
}

.item-name-bold {
  font-weight: 600;
  color: #0f172a;
}

.qty-brand {
  color: #008b4b;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}

.muted-dash {
  color: #94a3b8;
}

.table-total {
  height: 42px;
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 36px;
  padding: 0 18px;
  background: #f8fafc;
  border-top: 1px solid #e2e8f0;
  font-size: 13px;
  color: #475569;
}

.table-total strong {
  margin-right: auto;
  color: #0f172a;
}

.lower-grid {
  display: grid;
  grid-template-columns: 0.9fr 1.1fr;
  gap: 14px;
  margin-bottom: 14px;
}

.lower-grid .card {
  margin-bottom: 0;
}

.timeline,
.trace-list {
  list-style: none;
  padding: 0;
  margin: 0;
}

.timeline li,
.trace-list li {
  display: grid;
  grid-template-columns: 30px 115px 1fr 145px;
  align-items: center;
  min-height: 44px;
  font-size: 13px;
  border-bottom: 1px dashed #f1f5f9;
}

.timeline li:last-child,
.trace-list li:last-child {
  border-bottom: 0;
}

.timeline li i {
  width: 22px;
  height: 22px;
  border-radius: 50%;
  background: #e2e8f0;
  color: #64748b;
  text-align: center;
  line-height: 22px;
  font-style: normal;
  font-size: 12px;
  font-weight: 600;
}

.timeline li.done i {
  background: #008b4b;
  color: #ffffff;
}

.timeline time,
.trace-list time {
  color: #64748b;
  text-align: right;
}

.trace-list li {
  grid-template-columns: 180px 1fr 135px;
  border-bottom: 1px solid #f1f5f9;
}

.trace-list li span {
  color: #64748b;
}

/* 销售进度条保留 */
.progress-track {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  margin: 18px 14px 8px;
}

.progress-node {
  position: relative;
  flex: 1;
  text-align: center;
}

.progress-node:after {
  content: '';
  position: absolute;
  top: 14px;
  left: 55%;
  right: -45%;
  height: 2px;
  background: #e2e8f0;
}

.progress-node:last-child:after {
  display: none;
}

.progress-node i {
  position: relative;
  z-index: 1;
  width: 28px;
  height: 28px;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: #cbd5e1;
  color: #ffffff;
}

.progress-node.done i {
  background: #008b4b;
}

.progress-node span {
  display: block;
  margin-top: 8px;
  font-weight: 600;
  font-size: 12px;
}

.progress-meta {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  text-align: center;
  color: #64748b;
  font-size: 11px;
}

.progress-meta span {
  display: flex;
  flex-direction: column;
}

.progress-meta small {
  margin-top: 4px;
}

/* 抽屉与弹窗辅助样式 */
.drawer-body {
  padding: 0 20px 20px;
}

.receipt-line {
  padding: 16px 0;
  border-bottom: 1px solid #e2e8f0;
}

.receipt-line h4 {
  margin: 0 0 14px;
}

.receipt-line .el-select {
  width: 100%;
}

.drawer-actions {
  position: sticky;
  bottom: 0;
  padding: 14px 0;
  background: #fff;
  text-align: right;
}

.serial-tag {
  margin: 2px 4px 2px 0;
}

.settlement-grid {
  display: grid;
  grid-template-columns: repeat(4, max-content minmax(0, 1fr));
  gap: 10px 12px;
  margin: 0;
  font-size: 13px;
}

.settlement-grid dt {
  color: #64748b;
}

.settlement-grid dd {
  margin: 0;
  font-weight: 500;
}

.refund-due {
  color: #dc2626;
  font-weight: 700;
}

.settlement-tip {
  margin: 10px 0 0;
  color: #b45309;
  font-size: 12px;
}

.serial-hint {
  color: #64748b;
  font-size: 12px;
  line-height: 18px;
}

.serial-toolbar {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 10px;
}

.serial-toolbar .el-input {
  width: 260px;
}

.serial-toolbar span {
  margin-left: auto;
  color: #64748b;
  font-size: 12px;
}

/* 主题绿控件统一覆盖 */
.return-detail-page ::v-deep .el-table th {
  background: #f8fafc !important;
  color: #475569;
  font-weight: 600;
  font-size: 12px;
}

.return-detail-page ::v-deep .el-button--success,
.drawer-body ::v-deep .el-button--success {
  background-color: #008b4b;
  border-color: #008b4b;
}

.return-detail-page ::v-deep .el-button--success:hover,
.return-detail-page ::v-deep .el-button--success:focus,
.drawer-body ::v-deep .el-button--success:hover {
  background-color: #00763f;
  border-color: #00763f;
}

.return-detail-page ::v-deep .el-button--default:hover,
.return-detail-page ::v-deep .el-button--default:focus {
  color: #008b4b;
  border-color: #bbf7d0;
  background-color: #f0fdf4;
}

.return-detail-page ::v-deep .el-tag--success {
  background: #f0fdf4;
  color: #008b4b;
  border-color: #bbf7d0;
}

@media (max-width: 1200px) {
  .summary-grid {
    grid-template-columns: 1fr 1fr;
  }
  .summary-card:last-child {
    grid-column: span 2;
  }
  .lower-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 850px) {
  .summary-grid {
    grid-template-columns: 1fr;
  }
  .summary-card:last-child {
    grid-column: auto;
  }
  .settlement-grid {
    grid-template-columns: max-content minmax(0, 1fr);
  }
}

@media (max-width: 780px) {
  .return-detail-page {
    padding: 12px 10px 20px;
  }
  .page-head {
    align-items: flex-start;
  }
  .head-actions {
    justify-content: flex-start;
  }
  .timeline li {
    grid-template-columns: 28px 90px 1fr;
  }
  .timeline li time {
    grid-column: 2 / -1;
    text-align: left;
  }
}
</style>
