<template>
  <section v-loading="loading" class="return-detail-page" :class="`return-detail-page--${kind}`">
    <p class="breadcrumb">{{ kind === 'purchase' ? '采购管理 / 采购退货 / 退货详情' : '销售管理 / 销售退货 / 退货详情' }}</p>
    <div class="page-head">
      <div class="title-line"><h1>{{ row.return_no || `${title}详情` }}</h1><el-tag size="small" :type="statusType(row.return_status)">{{ statusText(row.return_status) }}</el-tag><el-tag v-if="kind==='purchase'" size="small" :type="row.stock_post_status==='posted'?'success':'info'">{{ purchasePostText }}</el-tag></div>
      <div class="head-actions"><el-button size="small" @click="$router.push(basePath)">返回列表</el-button><el-button v-if="canCancel" size="small" type="danger" plain @click="cancelReturn">取消退货单</el-button><el-button v-if="canClose" size="small" plain @click="closeReturn">关闭</el-button><el-button v-if="kind==='purchase'&&!managementScopeIssue&&row.return_status==='draft'&&$can('purchase_return.submit')" size="small" type="success" @click="submitPurchase">提交审核</el-button><el-button v-if="kind==='purchase'&&!managementScopeIssue&&row.return_status==='submitted'&&$can('purchase_return.approve')" size="small" type="success" @click="approve">审核通过</el-button><el-button v-if="kind==='purchase'&&!managementScopeIssue&&row.return_status==='pending_outbound'&&$can('purchase_return.post')" size="small" type="success" @click="postPurchase">{{ isRejectedBeforePosting ? '确认已退回供应商' : '退货出库过账' }}</el-button><el-button v-if="canShowApOffset" size="small" type="success" :loading="saving" :disabled="saving||!apOffsetPreview.available" :title="apOffsetPreview.reason||''" @click="offsetPayable">退货抵扣应付</el-button><el-button v-if="kind==='sales'&&['pending_receipt','partial_received'].includes(row.return_status)&&$can('sales_return.receive')" size="small" type="success" icon="el-icon-plus" @click="openReceive">登记退货到货</el-button></div>
    </div>

    <el-alert v-if="managementScopeIssue" :title="managementScopeIssue" type="warning" :closable="false" show-icon />
    <div class="summary-grid">
      <div class="card summary-card">
        <h3>退货单信息</h3>
        <dl><dt>退货单号</dt><dd>{{ row.return_no || '-' }}</dd><template v-if="kind==='purchase'"><dt>管理类型</dt><dd><el-tag size="mini" :type="row.management_scope==='office'?'info':'success'">{{ scopeLabel(row.management_scope) }}</el-tag></dd><dt>退货范围</dt><dd>{{ purchaseScopeText }}</dd></template><template v-else><dt>退货类型</dt><dd>{{ returnTypeText }}</dd></template><dt>退货原因</dt><dd>{{ row.return_reason || '-' }}</dd><dt>{{ kind==='purchase'?'退货数量':'申请日期' }}</dt><dd>{{ kind==='purchase'?totalRequested():dateOnly(row.return_date) }}</dd><dt>{{ kind==='purchase'?'退货金额（含税）':'申请人' }}</dt><dd>{{ kind==='purchase'?(row.amount_incl_tax != null ? money(row.amount_incl_tax) + '（未税 ' + money(totalAmount()) + '）' : money(totalAmount())):(row.created_by_name||creatorName()) }}</dd><dt>备注</dt><dd>{{ row.remark || '-' }}</dd></dl>
      </div>
      <div class="card summary-card">
        <h3>{{ kind==='purchase'?'来源与供应商':'原销售订单与客户' }}</h3>
        <dl v-if="kind==='purchase'"><dt>到货单</dt><dd class="link-text">{{ row.receipt&&row.receipt.receipt_no||'-' }}</dd><dt>采购订单</dt><dd class="link-text">{{ row.receipt&&row.receipt.order&&row.receipt.order.purchase_order_no||'-' }}</dd><dt>供应商</dt><dd>{{ row.supplier&&row.supplier.supplier_name||'-' }}</dd><dt>结算币种</dt><dd>{{ row.receipt&&row.receipt.order&&row.receipt.order.currency||'CNY' }}</dd><dt>结算汇率</dt><dd>{{ row.receipt&&row.receipt.order&&row.receipt.order.exchange_rate||'1.000000' }}</dd><dt>付款条件</dt><dd>{{ row.receipt&&row.receipt.order&&row.receipt.order.payment_terms||'-' }}</dd></dl>
        <dl v-else><dt>原销售订单</dt><dd>{{ row.order&&row.order.sales_order_no||'-' }}</dd><dt>客户名称</dt><dd>{{ row.customer_name_snapshot||row.order&&row.order.customer_name||'-' }}</dd><dt>联系人</dt><dd>{{ row.order&&row.order.contact_name||'-' }}</dd><dt>联系电话</dt><dd>{{ row.customer_phone_snapshot||row.order&&row.order.customer_phone||'-' }}</dd><dt>销售人员</dt><dd>{{ row.order&&row.order.sales_user_name||'-' }}</dd><dt>原订单日期</dt><dd>{{ dateOnly(row.order&&row.order.order_date) }}</dd></dl>
      </div>
      <div class="card summary-card">
        <h3>{{ kind==='purchase'?'状态与时间':'状态进度' }}</h3>
        <template v-if="kind==='purchase'"><dl><dt>退货状态</dt><dd>{{ statusText(row.return_status) }}</dd><dt>过账状态</dt><dd>{{ purchasePostText }}</dd><dt>应付处理</dt><dd>{{ settlementEffectText }}</dd><dt>制单人</dt><dd>{{ row.created_by_name||creatorName() }}</dd><dt>制单时间</dt><dd>{{ fmt(row.created_at) }}</dd><dt>审核人</dt><dd>{{ row.approved_by_name||operatorName('approve') }}</dd><dt>审核时间</dt><dd>{{ fmt(row.approved_at) }}</dd></dl></template>
        <template v-else><div class="progress-track"><div v-for="(step,index) in progressSteps" :key="step" class="progress-node" :class="{done:index<=currentStep}"><i :class="index<currentStep?'el-icon-check':'el-icon-more'" /><span>{{ step }}</span></div></div><div class="progress-meta"><span>{{ dateOnly(row.return_date) }}<small>{{ row.created_by_name||creatorName() }}</small></span><span>{{ fmt(row.confirmed_at) }}<small>{{ row.received_by_name||'-' }}</small></span><span><small>待处理</small></span><span><small>待处理</small></span></div></template>
      </div>
    </div>

    <el-alert v-if="kind==='purchase'" class="rule-alert" :closable="false" type="warning" show-icon :title="purchaseRuleText" />

    <div class="card detail-card">
      <h3>退货明细</h3>
      <el-table :data="row.items||[]" border size="mini">
        <template v-if="kind==='purchase'">
          <el-table-column label="到货单" width="142"><template slot-scope="{row:item}">{{ row.receipt&&row.receipt.receipt_no||'-' }}</template></el-table-column>
          <el-table-column label="采购订单" width="142"><template slot-scope="{row:item}">{{ row.receipt&&row.receipt.order&&row.receipt.order.purchase_order_no||'-' }}</template></el-table-column>
          <el-table-column label="Item" min-width="185"><template slot-scope="{row:item}">{{ item.item&&item.item.item_code }} {{ item.item&&item.item.item_name }}</template></el-table-column>
          <el-table-column label="仓库" width="120"><template slot-scope="{row:item}">{{ item.warehouse&&item.warehouse.warehouse_name||'-' }}</template></el-table-column>
          <el-table-column label="库位" width="105"><template slot-scope="{row:item}">{{ item.location&&item.location.location_name||'-' }}</template></el-table-column>
          <el-table-column label="批次" width="135"><template slot-scope="{row:item}">{{ item.batch_no||'-' }}</template></el-table-column>
          <el-table-column :label="isRejectedBeforePosting?'到货拒收数':'原入库数'" width="105" align="right"><template slot-scope="{row:item}">{{ number(item.source_posted_base_qty||item.requested_base_qty) }}</template></el-table-column>
          <el-table-column label="已退数" width="90" align="right"><template slot-scope="{row:item}">{{ number(item.previous_returned_base_qty||0) }}</template></el-table-column>
          <el-table-column label="本次退货" width="130" align="right"><template slot-scope="{row:item}">{{ number(item.requested_return_qty||item.requested_base_qty) }} {{ item.return_unit_name_snapshot||(item.base_unit&&item.base_unit.unit_name)||'' }}</template></el-table-column>
          <el-table-column label="基本数量" width="110" align="right"><template slot-scope="{row:item}">{{ number(item.requested_base_qty) }} {{ (item.base_unit&&item.base_unit.unit_name)||'' }}</template></el-table-column>
          <el-table-column label="基本单位" width="90"><template slot-scope="{row:item}">{{ item.base_unit&&item.base_unit.unit_name||'-' }}</template></el-table-column>
          <el-table-column label="设备编号 / 序列号" min-width="210"><template slot-scope="{row:item}"><span v-if="!(item.serial_links||[]).length">-</span><template v-else><el-tag v-for="link in item.serial_links||[]" :key="link.id" size="mini" class="serial-tag">{{ link.serial_no }}</el-tag></template></template></el-table-column>
        </template>
        <template v-else>
          <el-table-column type="index" label="#" width="48" />
          <el-table-column label="Product" min-width="150"><template slot-scope="{row:item}">{{ item.order_line&&item.order_line.product_name||'-' }}</template></el-table-column>
          <el-table-column label="SKU" min-width="140"><template slot-scope="{row:item}">{{ item.order_line&&item.order_line.sku_name||'-' }}</template></el-table-column>
          <el-table-column label="原履约物料" min-width="180"><template slot-scope="{row:item}">{{ item.item&&item.item.item_code||item.sales_order_line_id }}</template></el-table-column>
          <el-table-column label="申请退货" width="110" align="right"><template slot-scope="{row:item}">{{ number(item.requested_sales_qty||item.requested_base_qty) }}</template></el-table-column>
          <el-table-column label="已收货" width="100" align="right"><template slot-scope="{row:item}">{{ number(item.received_base_qty||0) }}</template></el-table-column>
          <el-table-column label="剩余待收" width="105" align="right"><template slot-scope="{row:item}">{{ remaining(item) }}</template></el-table-column>
          <el-table-column label="基本单位" width="90"><template slot-scope="{row:item}">{{ item.base_unit&&item.base_unit.unit_name||'-' }}</template></el-table-column>
        </template>
        <template v-if="kind==='purchase'" slot="append"><div class="table-total"><strong>合计</strong><span>{{ isRejectedBeforePosting?'到货拒收':'原入库' }} {{ totalSourceQty() }}</span><span v-if="!isRejectedBeforePosting">已退 {{ totalPostedQty() }}</span><span>本次退货 {{ totalRequested() }}</span></div></template>
      </el-table>
    </div>

    <div v-if="kind==='sales'&&row.settlement" class="card settlement-card">
      <h3>退款与应收</h3>
      <dl class="settlement-grid"><dt>本单冲减应收</dt><dd>{{ money(row.settlement.credit_amount) }}</dd><dt>订单合同金额</dt><dd>{{ money(row.settlement.order_contract_amount) }}</dd><dt>订单累计退货冲减</dt><dd>{{ money(row.settlement.order_return_credit_amount) }}</dd><dt>订单净应收</dt><dd>{{ money(row.settlement.order_net_receivable_amount) }}</dd><dt>订单净收款</dt><dd>{{ money(row.settlement.order_net_received_amount) }}</dd><dt>已退款</dt><dd>{{ money(row.settlement.order_refunded_amount) }}</dd><dt>应退客户</dt><dd :class="{'refund-due':Number(row.settlement.refund_due_amount)>0}">{{ money(row.settlement.refund_due_amount) }}</dd><dt>仍未收</dt><dd>{{ money(row.settlement.outstanding_amount) }}</dd></dl>
      <p v-if="Number(row.settlement.refund_due_amount)>0" class="settlement-tip">退款请在财务付款单中核销“销售退款”来源（原销售订单 {{ row.order&&row.order.sales_order_no }}），核销后订单自动完结。</p>
    </div>

    <div v-if="kind==='sales'" class="card receipt-card">
      <h3>退货到货与质量处理</h3>
      <el-table :data="row.receipts||[]" border size="mini"><el-table-column type="index" label="#" width="48" /><el-table-column prop="receipt_no" label="到货单号" width="165" /><el-table-column label="到货数量" width="95" align="right"><template slot-scope="{row:r}">{{ sumReceipt(r,'received_base_qty') }}</template></el-table-column><el-table-column label="可重新入库" width="105" align="right"><template slot-scope="{row:r}">{{ sumReceipt(r,'restock_base_qty') }}</template></el-table-column><el-table-column label="待处理" width="90" align="right"><template slot-scope="{row:r}">{{ sumReceipt(r,'pending_base_qty') }}</template></el-table-column><el-table-column label="报废" width="80" align="right"><template slot-scope="{row:r}">{{ sumReceipt(r,'scrap_base_qty') }}</template></el-table-column><el-table-column label="退回客户" width="95" align="right"><template slot-scope="{row:r}">{{ sumReceipt(r,'rejected_base_qty') }}</template></el-table-column><el-table-column label="仓库 / 库位 / 批次" min-width="190"><template slot-scope="{row:r}">{{ receiptLocation(r) }}</template></el-table-column><el-table-column prop="stock_post_status" label="过账状态" width="100"><template slot-scope="{row:r}"><el-tag size="mini" :type="receiptPostType(r.stock_post_status)">{{ receiptPostText(r.stock_post_status) }}</el-tag></template></el-table-column><el-table-column label="操作" width="150"><template slot-scope="{row:r}"><el-button v-if="r.stock_post_status==='pending'&&Number(sumReceipt(r,'restock_base_qty'))>0&&$can('sales_return.post')" type="text" size="mini" @click="postReceipt(r)">入库过账</el-button><el-button v-if="Number(sumReceipt(r,'pending_base_qty'))>0&&['partial_received','received'].includes(row.return_status)&&$can('sales_return.receive')" type="text" size="mini" @click="openDispose(r)">处置待处理</el-button><span v-if="!(r.stock_post_status==='pending'&&Number(sumReceipt(r,'restock_base_qty'))>0)&&!(Number(sumReceipt(r,'pending_base_qty'))>0)">-</span></template></el-table-column></el-table>
    </div>

    <div v-if="kind==='purchase'" class="lower-grid">
      <div class="card"><h3>{{ isRejectedBeforePosting ? '审批与退回进度' : '审批与出库进度' }}</h3><ul class="timeline"><li v-for="(step,index) in purchaseTimeline" :key="step.label" :class="{done:index<=currentStep}"><i>{{ index+1 }}</i><strong>{{ step.label }}</strong><span>{{ step.value }}</span><time>{{ step.time }}</time></li></ul></div>
      <div class="card"><h3>单据追溯</h3><ul class="trace-list"><li><span>来源到货单</span><strong class="link-text">{{ row.receipt&&row.receipt.receipt_no||'-' }}</strong><time>{{ dateOnly(row.receipt&&row.receipt.receipt_date) }}</time></li><li><span>采购订单</span><strong class="link-text">{{ row.receipt&&row.receipt.order&&row.receipt.order.purchase_order_no||'-' }}</strong><time>{{ dateOnly(row.receipt&&row.receipt.order&&row.receipt.order.order_date) }}</time></li><li><span>{{ isRejectedBeforePosting ? '不合格品退回供应商' : '库存出库单（退货出库）' }}</span><strong>{{ isRejectedBeforePosting ? (row.return_status==='completed'?'已确认退回':'待确认') : (row.return_inventory_transaction_no||'-') }}</strong><time>{{ isRejectedBeforePosting ? '不产生库存出库' : (row.stock_post_status==='posted'?'已生成':'未生成') }}</time></li></ul></div>
    </div>

    <div class="card log-card"><h3>操作日志</h3><el-table :data="row.logs||[]" size="mini"><el-table-column prop="created_at" label="时间" width="170"><template slot-scope="{row:log}">{{ fmt(log.created_at) }}</template></el-table-column><el-table-column prop="operator_name" label="操作人" width="130" /><el-table-column label="操作" width="180"><template slot-scope="{row:log}">{{ actionText(log.action) }}</template></el-table-column><el-table-column label="变更前状态" width="160"><template slot-scope="{row:log}">{{ statusText(log.from_status) }}</template></el-table-column><el-table-column label="变更后状态" width="160"><template slot-scope="{row:log}">{{ statusText(log.to_status) }}</template></el-table-column><el-table-column prop="content" label="备注" min-width="180" /></el-table></div>

    <el-drawer title="登记退货到货" :visible.sync="receiveVisible" size="520px" append-to-body><div class="drawer-body"><div v-for="line in receiveItems" :key="line.sales_return_item_id" class="receipt-line"><h4>{{ line.item_label }}</h4><el-form label-width="100px" size="small"><el-form-item label="本次收到"><el-input-number v-model="line.received_base_qty" :min="0.01" :max="line.max_qty" :precision="4" /></el-form-item><el-form-item label="可重新入库"><el-input-number v-model="line.restock_base_qty" :min="0" :max="line.received_base_qty" :precision="4" /></el-form-item><el-form-item label="待处理"><el-input-number v-model="line.pending_base_qty" :min="0" :max="line.received_base_qty" :precision="4" /></el-form-item><el-form-item label="报废"><el-input-number v-model="line.scrap_base_qty" :min="0" :max="line.received_base_qty" :precision="4" /></el-form-item><el-form-item label="退回客户"><el-input-number v-model="line.rejected_base_qty" :min="0" :max="line.received_base_qty" :precision="4" /></el-form-item><template v-if="line.restock_base_qty>0"><el-form-item label="仓库" required><el-select v-model="line.warehouse_id" filterable remote :remote-method="searchWarehouses"><el-option v-for="item in warehouses" :key="item.id" :label="item.warehouse_name" :value="item.id" /></el-select></el-form-item><el-form-item label="库位" required><el-select v-model="line.location_id" filterable remote :remote-method="searchLocations"><el-option v-for="item in locations" :key="item.id" :label="item.location_name" :value="item.id" /></el-select></el-form-item><el-form-item label="批次" required><el-input v-model.trim="line.batch_no" /></el-form-item></template></el-form></div><div class="drawer-actions"><el-button @click="receiveVisible=false">取消</el-button><el-button type="success" :loading="saving" @click="saveReceipt">保存</el-button></div></div></el-drawer>
    <el-dialog title="处置待处理数量" :visible.sync="disposeVisible" width="480px" append-to-body><el-form label-width="100px" size="small"><el-form-item label="到货明细"><el-select v-model="dispose.receipt_item_id" style="width:100%"><el-option v-for="i in disposeLines" :key="i.id" :label="`${i.item&&i.item.item_code||''} 待处理 ${number(i.pending_base_qty)}`" :value="i.id" /></el-select></el-form-item><el-form-item label="处置方式"><el-radio-group v-model="dispose.disposition"><el-radio label="restock">转入库</el-radio><el-radio label="scrap">报废</el-radio><el-radio label="rejected">退回客户</el-radio></el-radio-group></el-form-item><el-form-item label="数量"><el-input-number v-model="dispose.base_qty" :min="0.0001" :max="disposeMax" :precision="4" /></el-form-item><template v-if="dispose.disposition==='restock'"><el-form-item label="仓库" required><el-select v-model="dispose.warehouse_id" filterable remote :remote-method="searchWarehouses" style="width:100%"><el-option v-for="item in warehouses" :key="item.id" :label="item.warehouse_name" :value="item.id" /></el-select></el-form-item><el-form-item label="库位" required><el-select v-model="dispose.location_id" filterable remote :remote-method="searchLocations" style="width:100%"><el-option v-for="item in locations" :key="item.id" :label="item.location_name" :value="item.id" /></el-select></el-form-item><el-form-item label="批次" required><el-input v-model.trim="dispose.batch_no" /></el-form-item></template><el-form-item label="备注"><el-input v-model.trim="dispose.remark" maxlength="200" /></el-form-item><p v-if="dispose.disposition==='rejected'" class="settlement-tip">退回客户的数量不冲减应收。</p><p v-if="dispose.disposition==='restock'" class="settlement-tip">转入库会生成处置到货单，需再点“入库过账”按原发运成本入库。</p></el-form><span slot="footer"><el-button size="small" @click="disposeVisible=false">取消</el-button><el-button size="small" type="success" :loading="saving" @click="saveDispose">确认处置</el-button></span></el-dialog>
  </section>
</template>

<script>
import { erpDateTime } from '../../../utils/dateTime'
import { purchaseScopeLabel, purchaseScopeIssue } from '@/utils/purchaseManagementScope.mjs'
import { approvePurchaseReturn, cancelPurchaseReturn, closePurchaseReturn, getPurchaseReturn, offsetPurchaseReturnPayable, postPurchaseReturn, submitPurchaseReturn } from '@/api/erp/purchase'
import { cancelSalesReturn, closeSalesReturn, disposeSalesReturn, getSalesReturn, postSalesReturnReceipt, receiveSalesReturn } from '@/api/erp/sales'
import { listEntity } from '@/api/erp/master'
import { allocationKey } from '@/utils/financeCashAllocation'
export default {
  props:{kind:{type:String,required:true}},
  data:()=>({loading:false,saving:false,row:{},apOffsetRequest:null,receiveVisible:false,receiveItems:[],warehouses:[],locations:[],disposeVisible:false,disposeLines:[],dispose:{}}),
  computed:{
    returnTypeText(){return(this.row.terms&&this.row.terms.return_type)==='exchange'?'换货（不冲减应收）':'退货退款（冲减应收）'},
    disposeMax(){const line=this.disposeLines.find(i=>i.id===this.dispose.receipt_item_id);return line?Number(line.pending_base_qty):0},
    managementScopeIssue(){return this.kind==='purchase'?purchaseScopeIssue(this.row):''},
    apOffsetPreview(){return this.row.ap_offset_preview||{}},
    canShowApOffset(){return this.kind==='purchase'&&!this.managementScopeIssue&&this.row.return_status==='completed'&&this.row.settlement_effect_type==='PENDING'&&this.$can('purchase_return.view')&&this.$can('finance.allocation.create')},
    settlementEffectText(){return({PENDING:'待处理',AP_OFFSET:'已抵扣应付',SUPPLIER_REFUND:'供应商退款',REFUSE_PAY:'不予付款'})[this.row.settlement_effect_type]||'待核对'},
    title(){return this.kind==='purchase'?'采购退货':'销售退货'},
    basePath(){return this.kind==='purchase'?'/purchase/returns':'/sales/returns'},
    isRejectedBeforePosting(){return this.kind==='purchase'&&this.row.return_scope==='rejected_before_posting'},
    purchaseScopeText(){return this.row.return_scope==='rejected_before_posting'?'到货拒收（未入库）':'已入库退货'},
    purchasePostText(){return this.row.stock_post_status==='posted'?'已过账':this.row.stock_post_status==='not_required'?'无需过账':'未过账'},
    purchaseRuleText(){return this.isRejectedBeforePosting?'规则说明：该不合格品从未入库；审核后只确认实物已退回供应商，不产生库存出库和库存流水。':'规则说明：已入库退货必须使用原到货单的仓库、库位和批次，确认后产生退货出库流水。'},
    progressSteps(){return this.kind==='purchase'?['制单','审核',this.isRejectedBeforePosting?'确认退回供应商':'退货出库过账']:['退货申请','部分收货','质量处理','退货完结']},
    currentStep(){const maps=this.kind==='purchase'?{draft:0,submitted:0,approved:1,pending_outbound:1,completed:2}:{draft:0,pending_receipt:0,partial_received:1,received:2,completed:3};return maps[this.row.return_status]||0},
    purchaseTimeline(){return[{label:'制单',value:this.row.created_by_name||this.creatorName(),time:this.fmt(this.row.created_at)},{label:'审核',value:this.row.approved_by_name||this.operatorName('approve'),time:this.fmt(this.row.approved_at)},{label:this.isRejectedBeforePosting?'确认退回供应商':'退货出库过账',value:this.row.return_status==='completed'?'已执行':'待执行',time:this.fmt(this.row.posted_at)}]},
    canCancel(){return this.kind==='purchase'?['draft','submitted'].includes(this.row.return_status)&&this.$can('purchase_return.cancel'):['draft','pending_receipt'].includes(this.row.return_status)&&this.$can('sales_return.cancel')&&!(this.row.items||[]).some(i=>Number(i.received_base_qty)>0)},
    canClose(){return this.kind==='purchase'?this.row.return_status==='pending_outbound'&&this.$can('purchase_return.close'):['pending_receipt','partial_received','received'].includes(this.row.return_status)&&this.$can('sales_return.close')}
  },
  created(){this.load()},
  methods:{
    scopeLabel(scope){return purchaseScopeLabel(scope)},
    async load(){this.loading=true;try{const{data}=await(this.kind==='purchase'?getPurchaseReturn(this.$route.params.id):getSalesReturn(this.$route.params.id));this.row=data.data||data;if(this.kind==='sales'&&this.$route.query.receive==='1')this.$nextTick(this.openReceive)}finally{this.loading=false}},
    statusText(v){if(v==='pending_outbound'&&this.isRejectedBeforePosting)return '待退回供应商';return({draft:'草稿',submitted:'待审核',approved:'已审核',pending_outbound:'待出库',pending_receipt:'待收货',partial_received:'部分收货',received:'已收货',completed:'已完成',cancelled:'已取消',closed:'已关闭'})[v]||v||'-'},
    statusType(v){return({completed:'success',approved:'success',received:'success',cancelled:'danger',closed:'info',submitted:'warning',pending_outbound:'warning',pending_receipt:'warning',partial_received:'warning'})[v]||''},
    actionText(v){return({create:'创建退货单',submit:'提交审核',approve:'审核通过',confirm:'确认',confirm_supplier_return:'确认已退回供应商',receive:'登记退货到货',post_receipt:'退货入库过账',post:'退货出库过账',apply_ap_offset:'退货抵扣应付',dispose:'处置待处理',cancel:'取消',close:'关闭'})[v]||v||'-'},
    creatorName(){const log=(this.row.logs||[]).find(item=>item.action==='create');return log&&log.operator_name||'-'},
    operatorName(action){const log=(this.row.logs||[]).find(item=>item.action===action);return log&&log.operator_name||'-'},
    receiptPostText(v){return({posted:'已过账',not_required:'无需过账',pending:'未过账'})[v]||v||'-'},
    receiptPostType(v){return v==='posted'?'success':v==='not_required'?'info':'warning'},
    fmt(v){return v?erpDateTime(v, 16):'-'}, dateOnly(v){return v?String(v).slice(0,10):'-'}, number(v){return Number(v||0).toFixed(2)}, money(v){return `¥ ${Number(v||0).toLocaleString('zh-CN',{minimumFractionDigits:2,maximumFractionDigits:2})}`},
    totalRequested(){return this.number((this.row.items||[]).reduce((s,i)=>s+Number(i.requested_base_qty||i.requested_sales_qty||0),0))},
    totalSourceQty(){return this.number((this.row.items||[]).reduce((s,i)=>s+Number(i.source_posted_base_qty||i.requested_base_qty||0),0))},
    totalPostedQty(){return this.number((this.row.items||[]).reduce((s,i)=>s+Number(i.previous_returned_base_qty||0),0))},
    totalAmount(){return (this.row.items||[]).reduce((s,i)=>s+Number(i.requested_base_qty||0)*Number(i.unit_cost_snapshot||0),0)},
    remaining(i){return this.number(Math.max(0,Number(i.requested_base_qty||i.requested_sales_qty||0)-Number(i.received_base_qty||0)))},
    sumReceipt(r,key){return this.number((r.items||[]).reduce((s,i)=>s+Number(i[key]||0),0))},
    receiptLocation(r){const i=(r.items||[])[0]||{};return `${i.warehouse&&i.warehouse.warehouse_name||'-'} / ${i.location&&i.location.location_name||'-'} / ${i.batch_no||'-'}`},
    async cancelReturn(){await this.$confirm('取消后不会产生库存流水，确认取消该退货单？','取消退货单',{type:'warning'});await(this.kind==='purchase'?cancelPurchaseReturn(this.row.id):cancelSalesReturn(this.row.id));this.$message.success('退货单已取消');this.load()},
    async closeReturn(){await this.$confirm('确认关闭剩余未执行数量？已发生的业务事实不会被删除。','关闭未完成数量',{type:'warning'});await(this.kind==='purchase'?closePurchaseReturn(this.row.id):closeSalesReturn(this.row.id));this.$message.success('退货单已关闭');this.load()},
    async submitPurchase(){if(this.managementScopeIssue)return this.$message.warning(this.managementScopeIssue);await this.$confirm('确认提交该采购退货单进入审核？','提交审核');await submitPurchaseReturn(this.row.id);this.$message.success('已提交审核');this.load()},
    async approve(){if(this.managementScopeIssue)return this.$message.warning(this.managementScopeIssue);await this.$confirm('确认审核通过该采购退货单？','审核确认');await approvePurchaseReturn(this.row.id);this.$message.success('审核通过');this.load()},
    async postPurchase(){if(this.managementScopeIssue)return this.$message.warning(this.managementScopeIssue);const rejected=this.isRejectedBeforePosting;await this.$confirm(rejected?'确认不合格实物已经退回供应商？本操作不产生库存出库。':'确认按原仓库、库位和批次执行退货出库？',rejected?'确认退回供应商':'出库过账',{type:'warning'});await postPurchaseReturn(this.row.id);this.$message.success(rejected?'已确认退回供应商':'出库过账完成');this.load()},
    async offsetPayable(){
      if(this.saving||!this.canShowApOffset||!this.apOffsetPreview.available)return
      const preview=this.apOffsetPreview
      await this.$confirm(`原到货：${preview.receipt_no}；抵扣金额：人民币 ${preview.amount} 元；说明：${preview.audit_reason}。确认将本退货整笔抵扣原到货应付？`,'退货抵扣应付',{type:'warning',confirmButtonText:'确认抵扣'})
      if(!this.apOffsetRequest||this.apOffsetRequest.expected_fingerprint!==preview.expected_fingerprint)this.apOffsetRequest={expected_fingerprint:preview.expected_fingerprint,idempotency_key:allocationKey()}
      this.saving=true
      let completed=false
      try{await offsetPurchaseReturnPayable(this.row.id,this.apOffsetRequest);completed=true;this.$message.success('退货已整笔抵扣原到货应付');await this.load();this.apOffsetRequest=null}
      catch(error){
        if(completed)this.$message.error('退货已整笔抵扣应付，详情刷新失败，请刷新核对。')
        else if(error.response&&error.response.status>=400&&error.response.status<500)this.$message.error(error.userMessage||'退货抵扣未完成，请刷新后核对。')
        else this.$message.error('抵扣结果尚未确认，请使用原请求重试核对。')
      }
      finally{this.saving=false}
    },
    async postReceipt(r){await this.$confirm('确认仅将检验合格的可重新入库数量过账？','入库过账');await postSalesReturnReceipt(this.row.id,r.id);this.$message.success('退货入库已过账');this.load()},
    openReceive(){this.receiveItems=(this.row.items||[]).map(item=>{const remain=Math.max(0,Number(item.requested_base_qty)-Number(item.received_base_qty||0));return{sales_return_item_id:item.id,item_label:`${item.item&&item.item.item_code||''} / ${item.item&&item.item.item_name||''}`,max_qty:remain,received_base_qty:remain,restock_base_qty:remain,pending_base_qty:0,scrap_base_qty:0,rejected_base_qty:0,warehouse_id:null,location_id:null,batch_no:''}}).filter(i=>i.max_qty>0);if(!this.receiveItems.length)return this.$message.info('没有剩余待收数量');this.receiveVisible=true;this.searchWarehouses('');this.searchLocations('')},
    async searchWarehouses(keyword){const{data}=await listEntity('warehouses',{keyword,status:'active',page:1,per_page:10});this.warehouses=data.data||[]}, async searchLocations(keyword){const{data}=await listEntity('locations',{keyword,status:'active',page:1,per_page:10});this.locations=data.data||[]},
    openDispose(r){this.disposeLines=(r.items||[]).filter(i=>Number(i.pending_base_qty)>0);const first=this.disposeLines[0]||{};this.dispose={receipt_item_id:first.id,disposition:'restock',base_qty:Number(first.pending_base_qty||0),warehouse_id:first.warehouse_id||null,location_id:first.location_id||null,batch_no:first.batch_no||'',remark:''};this.disposeVisible=true;this.searchWarehouses('');this.searchLocations('')},
    async saveDispose(){const d=this.dispose;if(!d.receipt_item_id||!(Number(d.base_qty)>0))return this.$message.error('请选择到货明细并填写数量');if(d.disposition==='restock'&&(!d.warehouse_id||!d.location_id||!d.batch_no))return this.$message.error('转入库须选择仓库、库位并填写批次');this.saving=true;try{await disposeSalesReturn(this.row.id,d);this.$message.success('待处理数量已处置');this.disposeVisible=false;this.load()}finally{this.saving=false}},
    async saveReceipt(){const invalid=this.receiveItems.some(i=>Math.abs(Number(i.received_base_qty)-Number(i.restock_base_qty)-Number(i.pending_base_qty)-Number(i.scrap_base_qty)-Number(i.rejected_base_qty))>0.000001||(i.restock_base_qty>0&&(!i.warehouse_id||!i.location_id||!i.batch_no)));if(invalid)return this.$message.error('请检查数量守恒；可重新入库时仓库、库位和批次必填');this.saving=true;try{await receiveSalesReturn(this.row.id,{items:this.receiveItems});this.$message.success('退货到货已登记');this.receiveVisible=false;this.load()}finally{this.saving=false}}
  }
}
</script>

<style scoped>
.return-detail-page{padding:16px 20px 24px;min-height:calc(100vh - 52px);background:#f7f9fb;color:#172033}.breadcrumb{margin:0 0 15px;color:#6d7888;font-size:13px}.page-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}.title-line,.head-actions{display:flex;align-items:center;gap:9px}.title-line h1{margin:0;font-size:24px}.summary-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.card{background:#fff;border:1px solid #dde4ec;border-radius:5px;padding:14px 16px;margin-bottom:14px;min-width:0}.card h3{margin:0 0 14px;font-size:15px}.summary-card dl{display:grid;grid-template-columns:95px minmax(0,1fr);gap:10px 8px;margin:0;font-size:13px}.summary-card dt{color:#667384}.summary-card dd{margin:0;overflow-wrap:anywhere}.link-text{color:#1677e8}.rule-alert{margin-bottom:14px}.detail-card{padding:0}.detail-card h3{padding:14px 16px;margin:0}.table-total{height:40px;display:flex;align-items:center;justify-content:flex-end;gap:45px;padding:0 18px;border-top:1px solid #ebeef5;font-size:13px}.table-total strong{margin-right:auto}.lower-grid{display:grid;grid-template-columns:.85fr 1.15fr;gap:14px}.timeline,.trace-list{list-style:none;padding:0;margin:0}.timeline li,.trace-list li{display:grid;grid-template-columns:28px 110px 1fr 150px;align-items:center;min-height:44px;font-size:13px}.timeline li i{width:20px;height:20px;border-radius:50%;background:#d8dee7;color:#637082;text-align:center;line-height:20px;font-style:normal}.timeline li.done i{background:#f08a14;color:#fff}.timeline time,.trace-list time{color:#647184}.trace-list li{grid-template-columns:170px 1fr 140px;border-bottom:1px solid #eef1f5}.trace-list li:last-child{border-bottom:0}.progress-track{display:flex;align-items:flex-start;justify-content:space-between;margin:22px 18px 8px}.progress-node{position:relative;flex:1;text-align:center}.progress-node:after{content:'';position:absolute;top:14px;left:55%;right:-45%;height:2px;background:#e0e5eb}.progress-node:last-child:after{display:none}.progress-node i{position:relative;z-index:1;width:28px;height:28px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;background:#e5e9ee;color:#fff}.progress-node.done i{background:#159a58}.progress-node span{display:block;margin-top:9px;font-weight:600;font-size:12px}.progress-meta{display:grid;grid-template-columns:repeat(4,1fr);text-align:center;color:#697587;font-size:11px}.progress-meta span{display:flex;flex-direction:column}.progress-meta small{margin-top:4px}.return-detail-page :deep(.el-table th){background:#f7f9fb;color:#344054;font-weight:600}.return-detail-page :deep(.el-button--success),.drawer-body :deep(.el-button--success){background:#008d48;border-color:#008d48}.drawer-body{padding:0 20px 20px}.receipt-line{padding:16px 0;border-bottom:1px solid #e7ebf0}.receipt-line h4{margin:0 0 14px}.receipt-line .el-select{width:100%}.drawer-actions{position:sticky;bottom:0;padding:14px 0;background:#fff;text-align:right}@media(max-width:1200px){.summary-grid{grid-template-columns:1fr 1fr}.summary-card:last-child{grid-column:span 2}.lower-grid{grid-template-columns:1fr}}@media(max-width:850px){.summary-grid{grid-template-columns:1fr}.summary-card:last-child{grid-column:auto}.page-head{align-items:flex-start;gap:12px}.head-actions{flex-wrap:wrap;justify-content:flex-end}}
.serial-tag{margin:2px 4px 2px 0}
.settlement-grid{display:grid;grid-template-columns:repeat(4,max-content minmax(0,1fr));gap:10px 12px;margin:0;font-size:13px}.settlement-grid dt{color:#667384}.settlement-grid dd{margin:0}.refund-due{color:#d4380d;font-weight:600}.settlement-tip{margin:10px 0 0;color:#8a5b12;font-size:12px}
@media (max-width: 850px){.settlement-grid{grid-template-columns:max-content minmax(0,1fr)}}

@media (max-width: 780px) {
  .page-head {
    flex-wrap: wrap;
  }
  .title-line {
    flex-wrap: wrap;
    min-width: 0;
  }
  .head-actions {
    justify-content: flex-start;
  }
}
</style>
