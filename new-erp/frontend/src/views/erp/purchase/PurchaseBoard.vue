<template>
  <section class="purchase-page" :class="{ 'has-detail': !!selected }">
    <div class="purchase-main">
      <div class="purchase-heading">
        <div>
          <h1>{{ meta.title }}</h1>
          <p>{{ meta.subtitle }}</p>
        </div>
        <div class="head-actions">
          <el-button v-if="mode==='orders' && $can(['purchase.order.generate', 'purchase.order.create', 'purchase.order'])" size="small" type="success" icon="el-icon-download" @click="$router.push('/purchase/plans')">从计划生成订单</el-button>
          <el-button v-if="mode==='receipts' && $can(['purchase.receipt.create', 'purchase.receipt'])" size="small" type="success" icon="el-icon-truck" @click="$router.push('/purchase/orders')">从采购订单生成到货单</el-button>
          <el-button v-if="$can([`purchase.${mode.replace(/s$/, '')}.create`, `purchase.${mode.replace(/s$/, '')}`])" size="small" type="success" icon="el-icon-plus" @click="openEditor()">新增{{ meta.short }}</el-button>
          <el-button v-if="mode==='receipts' && $can(['purchase.quality.view', 'purchase.defect.view', 'purchase.receipt'])" size="small" icon="el-icon-warning-outline" @click="$router.push('/purchase/defects')">不合格品处理</el-button>
          <el-button size="small" plain icon="el-icon-refresh" @click="load">刷新</el-button>
        </div>
      </div>

      <el-alert class="business-alert" :title="meta.tip" type="warning" :closable="false" show-icon />

      <div class="purchase-filter">
        <el-input v-model="filters.keyword" size="small" :placeholder="`请输入${meta.short}单号 / 物料 / 供应商`" clearable @keyup.enter.native="load" />
        <el-select v-model="filters.status" size="small" clearable placeholder="请选择状态">
          <el-option v-for="s in meta.statuses" :key="s.value" :label="s.label" :value="s.value" />
        </el-select>
        <el-date-picker size="small" type="daterange" start-placeholder="开始日期" end-placeholder="结束日期" />
        <div class="filter-actions">
          <el-button size="small" @click="reset">重置</el-button>
          <el-button size="small" type="success" icon="el-icon-search" @click="load">查询</el-button>
        </div>
      </div>

      <div class="stat-row">
        <div v-for="card in statCards" :key="card.label" class="stat-card">
          <i :class="card.icon" />
          <span>{{ card.label }}</span>
          <strong>{{ card.value }}</strong>
          <small>{{ card.sub }}</small>
        </div>
      </div>

      <div class="table-panel purchase-table">
        <el-table :data="rows" size="mini" highlight-current-row @row-click="selectRow" :row-class-name="rowClass">
          <el-table-column v-for="col in columns" :key="`${col.prop}-${col.label}`" :label="col.label" :min-width="col.width || 90" show-overflow-tooltip>
            <template slot-scope="{row}">
              <el-tag v-if="col.tag" size="mini" :type="tagType(valueOf(row, col.prop), col.prop)">{{ labelOf(valueOf(row, col.prop), col.prop) }}</el-tag>
              <span v-else>{{ displayValue(row, col) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="310" fixed="right">
            <template slot-scope="{row}">
              <el-button v-if="$can([`purchase.${mode.replace(/s$/, '')}.view`, `purchase.${mode.replace(/s$/, '')}`])" type="text" size="mini" @click.stop="openDetail(row)">详情</el-button>
              <el-button v-if="canEdit(row) && $can([`purchase.${mode.replace(/s$/, '')}.edit`, `purchase.${mode.replace(/s$/, '')}`])" type="text" size="mini" @click.stop="openEditor(row)">编辑</el-button>
              <el-button v-if="canDelete(row) && $can([`purchase.${mode.replace(/s$/, '')}.delete`, `purchase.${mode.replace(/s$/, '')}`])" class="danger-link" type="text" size="mini" @click.stop="deleteDraft(row)">删除</el-button>
              <el-button v-for="action in visibleRowActions(row)" :key="action.command" type="text" size="mini" @click.stop="runAction(action.command,row)">{{ action.label }}</el-button>
            </template>
          </el-table-column>
        </el-table>
        <el-pagination :current-page="page" :page-size="perPage" :total="total" layout="total, prev, pager, next, sizes" @current-change="p => {page=p;load()}" @size-change="s => {perPage=s;load()}" />
      </div>
    </div>

    <aside class="purchase-detail" v-if="selected">
      <header>
        <h2>{{ detailNo(selected) }} {{ meta.short }}详情</h2>
        <i class="el-icon-close" @click="selected=null" />
      </header>
      <section>
        <h3>基础信息</h3>
        <dl>
          <dt>单据状态</dt><dd><el-tag size="mini" :type="tagType(mainStatus(selected))">{{ labelOf(mainStatus(selected)) }}</el-tag></dd>
          <dt v-if="mode==='orders'">审核状态</dt><dd v-if="mode==='orders'"><el-tag size="mini" :type="tagType(selected.audit_status)">{{ labelOf(selected.audit_status, 'audit_status') }}</el-tag></dd>
          <dt v-if="mode==='orders'">到货状态</dt><dd v-if="mode==='orders'"><el-tag size="mini" :type="tagType(selected.receipt_status)">{{ labelOf(selected.receipt_status) }}</el-tag></dd>
          <dt>物料 / 供应商</dt><dd>{{ selectedTitle(selected) }}</dd>
          <dt>日期</dt><dd>{{ selected.required_date || selected.plan_date || selected.order_date || selected.receipt_date || '--' }}</dd>
          <dt v-if="mode==='orders'">预计到货</dt><dd v-if="mode==='orders'">{{ selected.expected_arrival_date || '--' }}</dd>
          <dt>来源</dt><dd>{{ sourceText(selected.source_type || selected.data_source) }}</dd>
          <dt v-if="selected.source_no">来源单号</dt><dd v-if="selected.source_no">{{ selected.source_no }}</dd>
          <dt>备注</dt><dd>{{ selected.remark || '--' }}</dd>
        </dl>
      </section>
      <section v-if="mode==='orders'">
        <h3>供应商与商务条件</h3>
        <dl>
          <dt>供应商</dt><dd>{{ selected.supplier ? selected.supplier.supplier_name : '--' }}</dd>
          <dt>供应商编码</dt><dd>{{ selected.supplier ? selected.supplier.supplier_code : '--' }}</dd>
          <dt>联系人</dt><dd>{{ supplierContact(selected.supplier) }}</dd>
          <dt>联系方式</dt><dd>{{ supplierPhone(selected.supplier) }}</dd>
          <dt>结算方式</dt><dd>{{ selected.settlement_method || '--' }}</dd>
          <dt>交付方式</dt><dd>{{ selected.delivery_method || '--' }}</dd>
          <dt>币种</dt><dd>{{ selected.currency || 'CNY' }}</dd>
          <dt>税率口径</dt><dd>{{ selected.tax_mode === 'tax_excluded' ? '未税' : '含税' }}</dd>
        </dl>
      </section>
      <section>
        <h3>{{ mode==='requests' ? '物料信息' : '明细信息' }}</h3>
        <div v-for="line in detailLines(selected)" :key="line.id || line.item_id" class="detail-line">
          <b>{{ line.item ? line.item.item_code : '--' }}</b>
          <span>{{ line.item ? line.item.item_name : '--' }}</span>
          <em>{{ lineQty(line) }} {{ lineUnit(line) }}</em>
          <div v-if="mode==='orders'" class="unit-snapshot-grid">
            <label>采购数量<strong>{{ number(line.purchase_qty || line.order_qty) }}</strong></label>
            <label>采购单位<strong>{{ line.purchase_unit_name_snapshot || lineUnit(line) }}</strong></label>
            <label>换算因子<strong>{{ number(line.conversion_factor_snapshot) }}</strong></label>
            <label>计划基本数量<strong>{{ number(line.planned_base_qty) }} {{ line.base_unit_name_snapshot || '-' }}</strong></label>
            <label>采购单价<strong>¥{{ money(line.purchase_unit_price || line.unit_price) }}/{{ line.purchase_unit_name_snapshot || lineUnit(line) }}</strong></label>
            <label>基本单价<strong>¥{{ money(line.base_unit_price) }}/{{ line.base_unit_name_snapshot || '-' }}</strong></label>
            <label>税率<strong>{{ number(line.tax_rate) }}%</strong></label>
            <label>行金额<strong>¥{{ money(line.amount) }}</strong></label>
            <label>预计到货<strong>{{ line.expected_arrival_date || selected.expected_arrival_date || '-' }}</strong></label>
            <label>已到/未到<strong>{{ number(line.received_qty) }} / {{ number(line.remaining_qty) }}</strong></label>
          </div>
          <small v-if="mode==='requests'">已转 {{ line.converted_qty || line.planned_qty || 0 }} / 剩余 {{ line.remaining_qty || 0 }} / {{ line.warehouse ? line.warehouse.warehouse_name : '未指定仓库' }}</small>
          <small v-if="mode==='receipts'">合格 {{ line.qualified_qty || 0 }} / 不合格 {{ line.unqualified_qty || 0 }} / 质量待处理 {{ receiptUnresolvedQty(line) }} / 处理方式：{{ defectHandlingText(line) }}</small>
          <div v-if="mode==='receipts' && (line.allocations || []).length" class="receipt-location-trace">
            <span>{{ receiptAllocationSummary(line) }}</span>
            <el-button type="text" size="mini" @click.stop="openReceiptAllocationTrace(line)">查看库位与编号</el-button>
          </div>
        </div>
      </section>
      <section v-if="mode==='orders'">
        <h3>金额合计</h3>
        <dl>
          <dt>采购明细</dt><dd>{{ (selected.items || []).length }} 行 / 按各行采购单位核对</dd>
          <dt>未税金额</dt><dd>¥{{ money(orderUntaxedAmount(selected)) }}</dd>
          <dt>税额</dt><dd>¥{{ money(orderTaxAmount(selected)) }}</dd>
          <dt>运费</dt><dd>¥{{ money(selected.freight_amount) }}</dd>
          <dt>含税合计</dt><dd><strong>¥{{ money(orderGrandTotal(selected)) }}</strong></dd>
        </dl>
      </section>
      <section v-if="mode==='orders'" class="order-finance-summary">
        <h3>财务结算</h3>
        <p class="amount-rule">仅展示已确认到货形成的结算事实；采购订单金额本身不会直接形成应付。</p>
        <dl>
          <dt>已确认到货</dt><dd>¥{{ money(selected.finance_summary?.confirmed_receipt_amount) }}</dd>
          <dt>当前应付</dt><dd class="green-money">¥{{ money(selected.finance_summary?.current_payable_amount) }}</dd>
          <dt>质量冻结</dt><dd class="orange-money">¥{{ money(selected.finance_summary?.quality_frozen_amount) }}</dd>
          <dt>退货抵扣</dt><dd>¥{{ money(selected.finance_summary?.return_offset_amount) }}</dd>
          <dt>已付款</dt><dd>¥{{ money(selected.finance_summary?.paid_amount) }}</dd>
          <dt>未付款</dt><dd class="orange-money">¥{{ money(selected.finance_summary?.unpaid_amount) }}</dd>
          <dt>预付款核销</dt><dd>¥{{ money(selected.finance_summary?.prepayment_applied_amount) }}</dd>
          <dt>已收发票</dt><dd>¥{{ money(selected.finance_summary?.net_received_invoice_amount) }}</dd>
          <dt>未收发票</dt><dd class="orange-money">¥{{ money(selected.finance_summary?.invoice_unmatched_amount) }}</dd>
          <dt>待供应商退款</dt><dd class="red-money">¥{{ money(selected.finance_summary?.pending_refund_amount) }}</dd>
          <dt>财务状态</dt><dd><el-tag size="mini" :type="financeSummaryTag(selected.finance_summary?.financial_settlement_status)">{{ financeSummaryText(selected.finance_summary?.financial_settlement_status) }}</el-tag></dd>
        </dl>
      </section>
      <section v-if="mode==='plans'">
        <h3>{{ generatedOrders(selected).length ? '已生成采购订单' : '采购订单生成' }}</h3>
        <div v-if="generatedOrders(selected).length">
          <div v-for="order in generatedOrders(selected)" :key="order.id" class="linked-card">
            <b @click="goOrderDetail(order)">{{ order.purchase_order_no }}</b>
            <span>{{ order.supplier ? order.supplier.supplier_name : '-' }} / {{ (order.items || []).length }} 行 / 分单位核对</span>
            <em>金额 ¥{{ money(order.total_amount) }}　订单 {{ labelOf(order.purchase_status) }}　到货 {{ labelOf(order.receipt_status) }}</em>
            <el-button size="mini" type="text" @click="goOrderDetail(order)">查看订单</el-button>
          </div>
        </div>
        <div v-else-if="orderPreview.length">
          <div v-for="group in orderPreview" :key="group.supplier_id" class="preview-card">
            <b>供应商：{{ group.supplier_name || group.supplier_id }}</b>
            <span>预计生成 1 张采购订单，{{ group.line_count }} 行明细</span>
            <em>采购数量 {{ group.total_qty }}，预计金额 ¥{{ money(group.total_amount) }}</em>
          </div>
        </div>
        <el-empty v-else description="审核通过后可预览并生成采购订单" :image-size="70" />
      </section>
      <section v-if="mode==='orders'">
        <h3>到货记录</h3>
        <el-progress :percentage="orderProgress(selected)" color="#07883f" />
        <p>到货状态：{{ labelOf(selected.receipt_status) }}</p>
        <el-alert v-if="selected.open_receipt_id" class="receipt-allocation-alert" type="warning" :closable="false" show-icon>
          <template slot="title">待确认到货单 {{ selected.open_receipt_no }} 已占用 {{ number(selected.pending_receipt_qty) }}，确认或调整前不能重复生成。</template>
        </el-alert>
        <dl class="receipt-allocation-summary">
          <dt>草稿占用数量</dt><dd>{{ number(selected.pending_receipt_qty) }}</dd>
          <dt>当前可生成数量</dt><dd>{{ number(selected.available_receipt_qty) }}</dd>
        </dl>
        <div v-if="receiptRecords(selected).length">
          <div v-for="receipt in receiptRecords(selected)" :key="receipt.id" class="linked-card">
            <b @click="goReceiptDetail(receipt)">{{ receipt.receipt_no }}</b>
            <span>{{ receipt.receipt_date || '-' }} / 到货 {{ receiptQuantitySummary(receipt) }}</span>
            <em>验收 {{ receipt.items && receipt.items.length > 1 ? `${receipt.items.length} 行 / 分单位核对` : `合格 ${receiptQty(receipt, 'qualified_qty')} / 不合格 ${receiptQty(receipt, 'unqualified_qty')}` }}　库存过账：{{ stockPostingText(receipt.stock_post_status) }}</em>
            <el-button size="mini" type="text" @click="goReceiptDetail(receipt)">查看到货单</el-button>
          </div>
        </div>
        <el-empty v-else description="暂无到货记录" :image-size="64" />
      </section>
      <purchase-attachment-panel v-if="mode==='orders'" compact title="附件资料" document-type="order" :document-id="selected.id" :initial-attachments="selected.attachments || []" :editable="false" />
      <section v-if="mode==='orders'">
        <h3>操作日志</h3>
        <div v-if="(selected.logs || []).length" class="log-list"><div v-for="log in selected.logs" :key="log.id"><b>{{ log.action }}</b><span>{{ log.content }}</span><small>{{ log.operator || '系统' }} · {{ timeText(log.created_at) }}</small></div></div>
        <el-empty v-else description="暂无操作日志" :image-size="48" />
      </section>
      <section v-if="mode==='receipts'">
        <h3>库存过账状态</h3>
        <el-alert title="当前阶段只记录到货与验收结果，暂不更新正式库存。确认后进入“待库存过账”，正式库存过账将在库存模块处理。" type="warning" :closable="false" />
        <dl class="settlement-summary">
          <dt>到货合同金额</dt><dd>¥{{ money(selected.total_amount) }}</dd>
          <dt>合格暂估应付</dt><dd class="green-money">¥{{ money(selected.qualified_payable_amount) }}</dd>
          <dt>质量冻结金额</dt><dd class="orange-money">¥{{ money(selected.quality_hold_amount) }}</dd>
          <dt>拒付 / 索赔金额</dt><dd class="red-money">¥{{ money(selected.rejected_claim_amount) }}</dd>
        </dl>
        <p class="amount-rule">订单金额保留合同口径；库存只接收合格数量及其成本，冻结金额待质量处理完成后再转应付或拒付。</p>
      </section>
      <section>
        <h3>同步追踪</h3>
        <dl>
          <dt>旧系统</dt><dd>{{ selected.legacy_system || '--' }}</dd>
          <dt>旧表 / 旧ID</dt><dd>{{ selected.legacy_table || '--' }} / {{ selected.legacy_id || '--' }}</dd>
          <dt>同步批次</dt><dd>{{ selected.sync_batch_no || '--' }}</dd>
        </dl>
      </section>
      <footer>
        <el-button v-if="canEdit(selected)" size="small" icon="el-icon-edit" @click="openEditor(selected)">编辑{{ meta.short }}</el-button>
        <el-button v-if="canDelete(selected)" size="small" type="danger" plain icon="el-icon-delete" @click="deleteDraft(selected)">删除草稿</el-button>
        <el-button v-for="a in primaryActions(selected)" :key="a.command" size="small" type="success" icon="el-icon-position" @click="runAction(a.command, selected)">{{ a.label }}</el-button>
      </footer>
    </aside>

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
    tip: '采购需求没有审核流程；确认需求 = 锁定需求，允许转采购计划。',
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
  components: { PurchaseAttachmentPanel },
  props: { mode: { type: String, required: true } },
  data: () => ({
    rows: [],
    total: 0,
    page: 1,
    perPage: 10,
    filters: { keyword: '', status: '' },
    selected: null,
    drawer: false,
    form: {},
    items: [],
    suppliers: [],
    warehouses: [],
    locations: [],
    orderPreview: [],
    allocationTrace: { visible: false, line: null, keyword: '' }
  }),
  computed: {
    meta() { return metaMap[this.mode] },
    columns() {
      if (this.mode === 'requests') return [
        { label: '需求单号', prop: 'request_no', width: 126 },
        { label: '首个物料编码', prop: 'request_summary.item_code', width: 120 },
        { label: '首个物料名称', prop: 'request_summary.item_name', width: 150 },
        { label: '行数', prop: 'request_summary.line_count', width: 70 },
        { label: '需求明细', prop: 'request_summary.line_count', width: 90 },
        { label: '期望日期', prop: 'request_summary.expected_date', width: 112 },
        { label: '状态', prop: 'request_status', tag: true, width: 90 }
      ]
      if (this.mode === 'plans') return [
        { label: '计划单号', prop: 'plan_no', width: 126 },
        { label: '计划日期', prop: 'plan_date', width: 104 },
        { label: '物料行数', prop: 'items.length', width: 82 },
        { label: '采购明细', prop: 'items.length', width: 90 },
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
        { label: this.mode === 'requests' ? '需求明细' : this.mode === 'receipts' ? '到货明细' : '采购明细', value: this.rows.reduce((total, row) => total + (this.mode === 'requests' ? Number(row.request_summary?.line_count || 0) : (row.items || []).length), 0), sub: '行 / 分单位核对', icon: 'el-icon-box' },
        { label: '预计金额', value: `¥${this.money(this.mode === 'orders' || this.mode === 'plans' || this.mode === 'receipts' ? sum('total_amount') : 0)}`, sub: '含税参考', icon: 'el-icon-money' },
        { label: '待处理', value: this.rows.filter(r => ['draft', 'submitted', 'processing'].includes(this.mainStatus(r))).length, sub: '单', icon: 'el-icon-warning-outline' }
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
  watch: { mode() { this.bootstrap() } },
  methods: {
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
      const res = await listPurchase(this.mode, { keyword: this.filters.keyword, status: this.filters.status, page: this.page, per_page: this.perPage })
      this.rows = (res.data.data || []).map(row => ({ ...row, receipt_quantity_summary: this.receiptQuantitySummary(row) }))
      const currentId = this.selected && this.selected.id
      const next = currentId ? this.rows.find(r => Number(r.id) === Number(currentId)) : null
      if (next) await this.reloadDetail(next.id)
      else { this.selected = null; this.orderPreview = [] }
    },
    async reloadDetail(id) {
      if (!id) return
      const res = await getPurchase(this.mode, id)
      this.selected = res.data
      if (this.mode === 'plans') this.preview(this.selected)
    },
    async afterBusinessAction(id) {
      await this.load()
      if (id && this.rows.find(r => Number(r.id) === Number(id))) await this.reloadDetail(id)
    },
    reset() { this.filters = { keyword: '', status: '' }; this.page = 1; this.load() },
    selectRow(row) { this.reloadDetail(row.id) },
    openEditor(row) {
      if (this.mode === 'requests') return this.$router.push(row ? `/purchase/requests/${row.id}/edit` : '/purchase/requests/create')
      if (this.mode === 'plans') return this.$router.push(row ? `/purchase/plans/${row.id}/edit` : '/purchase/plans/create')
      if (this.mode === 'orders') return this.$router.push(row ? `/purchase/orders/${row.id}/edit` : '/purchase/orders/create')
      if (this.mode === 'receipts') return this.$router.push(row ? `/purchase/receipts/${row.id}/edit` : '/purchase/receipts/create')
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
      if (!row || this.mode !== 'plans') return
      try { const res = await previewPlanOrders(row.id); this.orderPreview = res.data.data || [] } catch (e) { this.orderPreview = [] }
    },
    rowActions(row) {
      if (!row) return []
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
      return this.rowActions(row).filter(action => !permissions[action.command] || this.$can(permissions[action.command]))
    },
    primaryActions(row) { return this.visibleRowActions(row).slice(0, 2) },
    canEdit(row) {
      if (!row) return false
      if (this.mode === 'requests') return row.request_status === 'draft'
      if (this.mode === 'plans') return row.plan_status === 'draft' || row.audit_status === 'rejected'
      if (this.mode === 'orders') return row.purchase_status === 'draft' || row.audit_status === 'rejected'
      if (this.mode === 'receipts') return row.confirm_status === 'draft'
      return false
    },
    canDelete(row) {
      if (!row) return false
      if (this.mode === 'requests') return row.request_status === 'draft'
      if (this.mode === 'plans') return row.plan_status === 'draft'
      if (this.mode === 'orders') return row.purchase_status === 'draft' && !row.open_receipt_id
      if (this.mode === 'receipts') return row.confirm_status === 'draft' && row.receipt_status === 'draft' && row.stock_post_status === 'pending'
      return false
    },
    async deleteDraft(row) {
      try {
        const no = this.detailNo(row)
        await this.$confirm(`<b>确定删除 ${no}？</b><br>只允许删除没有审核、到货、质量、库存和结算痕迹的草稿；有关联占用时系统会同步释放。`, '删除草稿', { type: 'warning', dangerouslyUseHTMLString: true, confirmButtonText: '确认删除', cancelButtonText: '取消' })
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
      const sum = key => lines.reduce((n, l) => n + Number(l[key] || 0), 0)
      return {
        item_code: item.item_code || '--',
        item_name: item.item_name || '--',
        line_count: lines.length,
        request_qty: sum('request_qty'),
        converted_qty: sum('converted_qty') || sum('planned_qty'),
        remaining_qty: sum('remaining_qty'),
        expected_date: first.expected_date || row.required_date || '--',
        priority: first.priority || row.priority || '--'
      }
    },
    mainStatus(row) { return row.request_status || row.plan_status || row.purchase_status || row.confirm_status || row.receipt_status },
    labelOf(v, prop = '') {
      if (prop === 'audit_status' && v === 'pending') return '待审核'
      return statusLabelMap[v] || v || '--'
    },
    tagType(v) {
      return ['approved', 'received', 'confirmed', 'low'].includes(v) ? 'success' : ['cancelled', 'rejected', 'high'].includes(v) ? 'danger' : ['partial', 'partially_received', 'pending', 'normal', 'partially_planned', 'partially_ordered'].includes(v) ? 'warning' : 'info'
    },
    detailNo(row) { return row.request_no || row.plan_no || row.purchase_order_no || row.receipt_no },
    selectedTitle(row) {
      if (this.mode === 'requests') {
        const lines = row.items || row.request_items || []
        const first = lines[0] || {}
        const item = first.item || {}
        return item.item_code ? `${item.item_code} / ${item.item_name}${lines.length > 1 ? ` 等${lines.length} 行` : ''}` : '--'
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
      const qty = Number(this.mode === 'requests' ? this.requestSummary(row).request_qty : row.total_qty || row.total_receipt_qty || 0)
      const amount = this.money(row.total_amount || 0)
      const summaries = {
        submitRequest: `<p>确认需求：${no}</p><p>明细 ${lineCount} 行，需求数量 ${qty}。确认后需求会被锁定，可转采购计划。</p>`,
        requestToPlan: `<p>转采购计划：${no}</p><p>明细 ${lineCount} 行，总需求数量 ${qty}，预计转化数量 ${this.requestSummary(row).remaining_qty || qty}。</p>`,
        approvePlan: `<p>审核采购计划：${no}</p><p>物料 ${lineCount} 行，供应商 ${this.planSupplierCount(row)} 个，预计金额 ¥${amount}。审核通过后可生成采购订单。</p>`,
        submitOrder: `<p>提交采购订单审核：${no}</p><p>供应商：${row.supplier ? row.supplier.supplier_name : '--'}；明细 ${lineCount} 行，采购数量 ${row.total_qty || qty}，金额 ¥${amount}。</p>`,
        approveOrder: `<p>审核采购订单：${no}</p><p>供应商：${row.supplier ? row.supplier.supplier_name : '--'}；审核通过后才可生成到货单。</p>`,
        confirmReceipt: `<p>确认到货：${no}</p><p>到货 ${row.total_receipt_qty || qty}，合格 ${this.receiptQty(row, 'qualified_qty')}，不合格 ${this.receiptQty(row, 'unqualified_qty')}，质量待处理 ${this.receiptUnresolvedQty(row)}。本次不更新正式库存。</p>`
      }
      return summaries[command] || `<p>确认执行 ${no} 的业务动作？</p>`
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
  display: block;
  min-width: 0;
  min-height: calc(100vh - 54px);
  background: #f7f8f9;
  position: relative;
}

@media (min-width: 1440px) {
  .purchase-page.has-detail {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 460px;
  }
}

.purchase-main {
  padding: 16px;
  min-width: 0;
}

.purchase-heading {
  min-height: 46px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 12px;
}
.purchase-heading h1 {
  margin: 0;
  font-size: 18px;
  font-weight: 700;
  color: #111827;
}
.purchase-heading p {
  margin: 3px 0 0;
  color: #6b7280;
  font-size: 12px;
}
.head-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
}

.business-alert {
  margin: 8px 0 14px;
}

.purchase-filter {
  padding: 12px 14px;
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
  background: #fff;
  border: 1px solid #e2e6ea;
  border-radius: 4px;
}
.purchase-filter .el-input {
  width: 240px;
  max-width: 100%;
}
.purchase-filter .el-select {
  width: 150px;
}
.purchase-filter .el-date-editor {
  width: 260px;
}
.filter-actions {
  display: flex;
  gap: 8px;
  margin-left: auto;
}

.stat-row {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 12px;
  margin: 14px 0;
}
.stat-card {
  height: 76px;
  padding: 12px 14px;
  background: #fff;
  border: 1px solid #e2e6ea;
  border-radius: 4px;
  display: grid;
  grid-template-columns: 28px 1fr;
  grid-template-rows: 18px 24px 16px;
}
.stat-card i {
  grid-row: 1/4;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  display: grid;
  place-items: center;
  background: #eaf7ef;
  color: #07883f;
}
.stat-card span {
  color: #6b7480;
  font-size: 12px;
}
.stat-card strong {
  font-size: 18px;
  color: #111827;
}
.stat-card small {
  color: #7c8792;
  font-size: 11px;
}

.purchase-table {
  padding: 0 10px 10px;
  overflow: hidden;
}

/* 详情抽屉/侧栏：大屏内嵌，缩放或中屏时浮动抽屉，绝不压迫主表格 */
.purchase-detail {
  background: #fff;
  border-left: 1px solid #e2e6ea;
  height: calc(100vh - 54px);
  overflow-y: auto;
  min-width: 0;
  box-sizing: border-box;
}

@media (max-width: 1439px) {
  .purchase-detail {
    position: fixed;
    top: 54px;
    right: 0;
    z-index: 40;
    width: min(480px, 92vw);
    box-shadow: -8px 0 24px rgba(0, 0, 0, 0.12);
  }
}

@media (min-width: 1440px) {
  .purchase-detail {
    position: sticky;
    top: 54px;
  }
}

.purchase-detail header {
  height: 54px;
  padding: 0 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid #e2e6ea;
  background: #fafbfc;
}
.purchase-detail h2 {
  margin: 0;
  font-size: 15px;
  font-weight: 700;
  color: #111827;
}
.purchase-detail header i {
  cursor: pointer;
  font-size: 16px;
  color: #6b7280;
}
.purchase-detail header i:hover {
  color: #111827;
}

.purchase-detail section {
  padding: 14px 16px;
  border-bottom: 1px solid #e2e6ea;
}
.purchase-detail h3 {
  margin: 0 0 10px;
  font-size: 13px;
  font-weight: 600;
  color: #111827;
}
.purchase-detail dl {
  display: grid;
  grid-template-columns: 96px 1fr;
  gap: 8px;
  margin: 0;
  font-size: 13px;
}
.purchase-detail dt {
  color: #6b7280;
}
.purchase-detail dd {
  margin: 0;
  color: #1f2937;
  word-break: break-word;
}
.detail-line {
  display: grid;
  grid-template-columns: 88px minmax(0, 1fr) 88px;
  gap: 8px;
  padding: 8px;
  border: 1px solid #edf0f2;
  border-radius: 3px;
  margin-bottom: 7px;
  font-size: 12px;
}
.detail-line em {
  font-style: normal;
  text-align: right;
  white-space: nowrap;
}
.detail-line small {
  grid-column: 2/4;
  color: #7b8590;
}
.purchase-detail footer {
  position: sticky;
  bottom: 0;
  padding: 12px 16px;
  display: flex;
  gap: 8px;
  background: #fff;
  border-top: 1px solid #e2e6ea;
}
.preview-card, .linked-card {
  display: grid;
  gap: 5px;
  margin-bottom: 8px;
  padding: 10px;
  border: 1px solid #bcd8f5;
  background: #f8fbff;
}
.preview-card em, .linked-card em {
  font-style: normal;
  color: #07883f;
}
.linked-card b {
  color: #136f3a;
  cursor: pointer;
}
.linked-card .el-button {
  justify-self: start;
  padding: 0;
}
.purchase-form {
  padding: 0 18px 70px;
}
.purchase-form .el-select, .purchase-form .el-date-editor {
  width: 100%;
}
.line-editor {
  margin: 0 0 12px 0;
  padding: 12px;
  background: #f8faf9;
  border: 1px solid #e2e6ea;
}
.line-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.form-line {
  display: grid;
  grid-template-columns: 1fr 1fr 92px 92px;
  gap: 7px;
  margin-top: 8px;
  align-items: center;
}
.form-line .danger-link {
  grid-column: 4;
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
  border-top: 1px solid #e2e6ea;
}
::v-deep .current-purchase-row td {
  background: #eaf7ef !important;
}
.purchase-detail ::v-deep .purchase-attachment-panel {
  margin: 0;
  border: 0;
  border-bottom: 1px solid #e2e6ea;
  border-radius: 0;
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
  font-size: 11px;
}
.log-list span, .log-list small {
  color: #6f7a84;
  font-size: 10px;
}
.unit-snapshot-grid {
  grid-column: 1/4;
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 7px;
  margin-top: 5px;
  padding-top: 8px;
  border-top: 1px dashed #dfe5e9;
}
.unit-snapshot-grid label {
  display: flex;
  justify-content: space-between;
  gap: 6px;
  color: #7b8590;
  font-size: 11px;
}
.unit-snapshot-grid strong {
  color: #263442;
  font-weight: 500;
  text-align: right;
}
.receipt-allocation-alert {
  margin: 8px 0;
}
.receipt-allocation-summary {
  grid-template-columns: 105px minmax(0, 1fr) !important;
  margin-bottom: 10px !important;
  padding: 9px 10px;
  background: #f8faf9;
  border: 1px solid #e2e9e5;
  border-radius: 4px;
}
.receipt-location-trace {
  grid-column: 1/4;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  margin-top: 2px;
  padding-top: 7px;
  border-top: 1px dashed #e1e7e3;
  color: #587064;
  font-size: 11px;
}
.receipt-location-trace .el-button {
  padding: 0;
  flex: 0 0 auto;
}
.allocation-trace-body {
  display: grid;
  gap: 12px;
}
.allocation-trace-head {
  display: grid;
  grid-template-columns: 1.6fr 1fr 1fr 1fr;
  gap: 10px;
  padding: 12px;
  background: #f7faf8;
  border: 1px solid #dfe8e2;
  border-radius: 4px;
}
.allocation-trace-head > div {
  display: grid;
  gap: 4px;
}
.allocation-trace-head span {
  color: #78847d;
  font-size: 11px;
}
.allocation-trace-head strong {
  color: #24352c;
  font-size: 12px;
}
.trace-serial-list {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
  max-height: 160px;
  overflow: auto;
}
.trace-serial-list .el-tag {
  font-family: Consolas, monospace;
}
.muted-text {
  color: #99a19d;
}
.allocation-trace-table ::v-deep .cell {
  white-space: normal;
  word-break: break-word;
}
@media (max-width: 760px) {
  .allocation-trace-head {
    grid-template-columns: 1fr 1fr;
  }
}
</style>
<style scoped>
.receipt-editor{margin:0 0 12px;padding:12px;background:#f8faf9;border:1px solid #e2e6ea}.receipt-editor>.line-head span{color:#75808b;font-size:12px}.receipt-line-card{margin-top:12px;padding:12px;border:1px solid #dce4e9;border-radius:5px;background:#fff}.receipt-line-title{display:flex;gap:10px;margin-bottom:12px}.receipt-line-title span{color:#697580}.receipt-fields{display:grid;grid-template-columns:1fr 1fr;gap:11px}.receipt-fields label{display:grid;grid-template-columns:92px 1fr;align-items:center;gap:8px;font-size:12px}.receipt-fields .receipt-serial-field{grid-column:1/-1;align-items:start}.receipt-fields .el-input-number{width:100%}.receipt-pass,.receipt-fail{margin:10px 0 0;padding:8px;border-radius:4px;font-size:12px}.receipt-pass{background:#eef9f2;color:#07883f}.receipt-fail{background:#fff1f0;color:#d93025}.difference-input :deep(.el-input__inner){color:#d93025;background:#fff3f1}
.serial-entry-tools{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px;color:#68737d}.serial-entry-tools .el-button{flex:0 0 auto;padding:5px 8px}.serial-number-panel{margin-top:6px;border:1px solid #dce5df;border-radius:4px;background:#f8fbf9}.serial-number-summary{display:flex;align-items:center;justify-content:space-between;padding:2px 8px;border-bottom:1px solid #e5ebe7;color:#66736b;font-size:11px}.serial-number-list{max-height:132px;overflow-y:auto}.serial-number-item{display:flex;align-items:center;justify-content:space-between;gap:8px;min-height:28px;padding:2px 8px;border-bottom:1px solid #edf1ee}.serial-number-item:last-child{border-bottom:0}.serial-number-item span{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-family:Consolas,monospace;color:#25352c}.serial-number-item .el-button{flex:0 0 auto;padding:3px 0}
</style>
