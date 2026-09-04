<!--
Design reference: D:\codex-introduce\new_erp\docs\ui-reference\phase6\phase6-order-detail-one-page-design.png
Design status: Approved
Do not change layout without approval.
-->
<template>
  <section class="sales-detail-page">
    <div class="detail-toolbar">
      <div>
        <div class="crumb">销售管理 / 销售订单 / 订单详情</div>
        <h1>{{ order.sales_order_no || '-' }}</h1>
      </div>
      <div>
        <el-button size="small" @click="$router.push('/sales/orders')">返回列表</el-button>
        <el-button v-if="order.allowed_actions && order.allowed_actions.edit" size="small" @click="$router.push(`/sales/orders/${order.id}/edit`)">编辑订单</el-button>
        <el-button v-if="order.allowed_actions && order.allowed_actions.submit_confirmation" size="small" type="success" @click="doConfirm">确认前检查</el-button>
        <el-button v-if="order.allowed_actions && order.allowed_actions.formal_confirm" size="small" type="success" @click="doFormalConfirm">正式确认</el-button>
        <el-button v-if="order.allowed_actions && order.allowed_actions.production_confirmation" size="small" type="primary" @click="$router.push(`/sales/orders/${order.id}/production-confirmation`)">订单生产确认</el-button>
        <el-button v-if="order.allowed_actions && order.allowed_actions.delete_draft" size="small" type="danger" plain @click="deleteDraft">删除草稿</el-button>
      </div>
    </div>

    <el-alert v-if="order.order_status === 'confirmed' && order.change_eligibility && !order.change_eligibility.allowed" class="change-block-alert" type="warning" :closable="false" show-icon :title="`当前订单不能原地变更：${order.change_eligibility.reason}`" />
    <el-alert v-if="$route.query.changed" class="change-success-alert" type="success" :closable="false" show-icon title="订单已变更，请重新执行履约规划或订单生产确认。" />
    <el-alert v-if="order.pending_change_candidate" class="change-success-alert" type="warning" :closable="false" show-icon :title="`存在待审核 Candidate V${order.pending_change_candidate.candidate_version}：正式订单、库存预留和履约事实尚未变化。`" />

    <div class="summary-strip">
      <div><span>订单号</span><b>{{ order.sales_order_no || '-' }}</b></div>
      <div><span>原始单号</span><b>{{ order.origin_order_no || '-' }}</b></div>
      <div><span>客户</span><b>{{ order.customer_name || '-' }}</b></div>
      <div><span>订单日期</span><b>{{ date(order.order_time) }}</b></div>
      <div><span>要求交期</span><b>{{ dateOnly(order.required_delivery_date) }}</b></div>
      <div><span>订单金额</span><b>¥{{ money(order.total_amount) }}</b></div>
      <div><span>订单状态</span><el-tag size="mini" :type="statusTag(order.order_status)">{{ statusText(order.order_status) }}</el-tag></div>
      <div><span>履约状态</span><el-tag size="mini" :type="fulfillmentStatusTag(order.fulfillment_status)">{{ fulfillmentStatusText(order.fulfillment_status) }}</el-tag><small v-if="order.fulfillment_composition_label">{{ order.fulfillment_composition_label }}</small></div>
      <div><span>生产确认</span><el-tag size="mini" :type="statusTag(order.production_confirm_status)">{{ statusText(order.production_confirm_status) }}</el-tag></div>
    </div>

    <div class="detail-one-page">
      <div class="overview-layout">
        <section class="detail-card">
          <h3>订单概览</h3>
          <div class="overview-grid overview-grid--order">
            <div><span>销售人员</span><b>{{ order.created_by || '-' }}</b></div>
            <div><span>下单时间</span><b>{{ date(order.order_time) }}</b></div>
            <div><span>订单来源</span><b>{{ statusText(order.order_source) }}</b></div>
            <div><span>贸易类型</span><b>{{ order.trade_type === 'foreign' ? '外贸' : '内贸' }}</b></div>
            <div><span>快递</span><b>{{ shippingCarrierName }}</b></div>
            <div><span>履约状态</span><el-tag size="mini" :type="fulfillmentStatusTag(order.fulfillment_status)">{{ fulfillmentStatusText(order.fulfillment_status) }}</el-tag><small v-if="order.fulfillment_composition_label">{{ order.fulfillment_composition_label }}</small></div>
            <div><span>生产确认</span><el-tag size="mini" :type="statusTag(order.production_confirm_status)">{{ statusText(order.production_confirm_status) }}</el-tag></div>
            <div><span>当前版本</span><b>V{{ currentVersion }}</b><el-button v-if="changes.total" type="text" size="mini" @click="scrollToChanges">查看变更历史</el-button></div>
            <div><span>订单备注</span><b>{{ order.remark || '-' }}</b></div>
          </div>
        </section>

        <section class="detail-card">
          <h3>客户与收货</h3>
          <div class="overview-grid overview-grid--customer">
            <div><span>客户</span><b>{{ order.customer_name || '-' }}</b></div>
            <div><span>联系电话</span><b>{{ order.customer_phone || order.contact_phone || '-' }}</b></div>
            <div><span>客户快照</span><b>{{ order.customer_snapshot && order.customer_snapshot.name || order.customer_name || '-' }}</b></div>
            <div><span>收货地址</span><b>{{ order.full_address || order.address || '-' }}</b></div>
          </div>
        </section>
      </div>

      <section class="detail-card">
        <h3>订单行</h3>
        <el-table :data="order.lines || []" border size="mini">
          <el-table-column label="行号" width="60" align="center"><template slot-scope="{$index}">{{ $index + 1 }}</template></el-table-column>
          <el-table-column prop="product_name" label="产品名称" min-width="106" show-overflow-tooltip />
          <el-table-column prop="sku_name" label="SKU名称" min-width="112" show-overflow-tooltip />
          <el-table-column label="系统Item编码" min-width="118" show-overflow-tooltip>
            <template slot-scope="{row}">
              <span v-if="lineItem(row).status === 'matched'">{{ lineItem(row).item_code }}</span>
              <el-tag v-else-if="lineItem(row).status === 'not_required'" size="mini" type="info">无需 Item</el-tag>
              <el-tag v-else size="mini" type="danger">Item 异常</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="系统Item名称" min-width="125" show-overflow-tooltip>
            <template slot-scope="{row}">
              <span v-if="lineItem(row).status === 'matched'">{{ lineItem(row).item_name }}</span>
              <span v-else-if="lineItem(row).status === 'not_required'">—</span>
              <span v-else class="item-abnormal">{{ lineItem(row).message }}</span>
            </template>
          </el-table-column>
          <el-table-column prop="order_qty" label="订单数量" width="82" align="right" />
          <el-table-column label="销售单价" width="94" align="right"><template slot-scope="{row}">¥{{ money(row.unit_price) }}</template></el-table-column>
          <el-table-column label="金额" width="104" align="right"><template slot-scope="{row}">¥{{ money(Number(row.order_qty || 0) * Number(row.unit_price || 0)) }}</template></el-table-column>
          <el-table-column label="配置摘要" min-width="112">
            <template slot-scope="{row}">
              <el-tag v-if="row.is_customized" size="mini">定制</el-tag>
              <el-tag v-if="row.is_special_customized" size="mini" type="danger">特殊定制</el-tag>
              <el-tag v-if="row.electric" size="mini">{{ row.electric }}</el-tag>
              <el-tag v-if="row.need_pump === true" size="mini" type="success">原水泵：需要</el-tag>
              <el-tag v-else-if="row.need_pump === false" size="mini" type="info">原水泵：不需要</el-tag>
              <span v-if="!row.is_customized && !row.is_special_customized && !row.electric && row.need_pump === null">标准</span>
            </template>
          </el-table-column>
          <el-table-column label="图纸数" width="70" align="center"><template slot-scope="{row}">{{ lineFiles(row).length }}</template></el-table-column>
          <el-table-column label="履约类型" width="88"><template slot-scope="{row}"><el-tag size="mini" :type="statusTag(row.fulfillment_type)">{{ statusText(row.fulfillment_type) }}</el-tag></template></el-table-column>
          <el-table-column label="履约状态" width="88"><template slot-scope="{row}">{{ statusText(row.line_status) }}</template></el-table-column>
        </el-table>
      </section>

      <div class="operation-layout">
        <section class="detail-card logistics-card">
          <h3>物流信息</h3>
          <div class="kv-list">
            <div><span>贸易类型</span><b>{{ order.trade_type === 'foreign' ? '外贸' : '内贸' }}</b></div>
            <div><span>快递</span><b>{{ shippingCarrierName }}</b></div>
            <div><span>预估快递费</span><b>¥{{ money(order.carrier_fee) }}</b></div>
            <div><span>快递单号</span><b>{{ order.logistics_snapshot && order.logistics_snapshot.express_no || '-' }}</b></div>
            <div v-if="order.trade_type === 'foreign'"><span>件数 / 毛重 / 体积</span><b>{{ order.logistics_snapshot && order.logistics_snapshot.pcs || '-' }} / {{ order.logistics_snapshot && order.logistics_snapshot.gw || '-' }} / {{ order.logistics_snapshot && order.logistics_snapshot.vol || '-' }}</b></div>
            <div v-if="order.trade_type === 'foreign'"><span>截单 / 截关 / 货好</span><b>{{ dateOnly(order.logistics_snapshot && order.logistics_snapshot.si_date) }} / {{ dateOnly(order.logistics_snapshot && order.logistics_snapshot.cy_date) }} / {{ dateOnly(order.logistics_snapshot && order.logistics_snapshot.cargo_ready_date) }}</b></div>
          </div>
        </section>

        <section class="detail-card contract-card">
          <h3>合同附件 <small>（共 {{ contractFiles.length }} 个文件）</small></h3>
          <div v-if="contractFiles.length" class="compact-file-list">
            <div v-for="file in contractFiles" :key="file.attachment_id || file.file_hash || file.file_name" class="compact-file-row">
              <div class="compact-file-main">
                <b :title="file.file_name">{{ file.file_name }}</b>
                <span>{{ statusText(file.file_type || 'other') }} · V{{ file.version_no || 1 }} · {{ fileSize(file.file_size) }} · {{ file.uploaded_by || '-' }} · {{ date(file.uploaded_at) }} · {{ statusText(file.status || 'active') }}</span>
              </div>
              <div class="compact-file-actions">
                <el-button v-if="file.attachment_id && previewable(file)" type="text" size="mini" @click="previewAttachment(file)">预览</el-button>
                <el-button v-if="file.attachment_id && file.can_download !== false" type="text" size="mini" @click="downloadAttachment(file)">下载</el-button>
                <el-button v-if="canDeleteAttachment(file)" type="text" size="mini" class="danger-link" @click="deleteAttachment(file)">删除</el-button>
                <span v-if="!file.attachment_id">历史记录</span>
              </div>
            </div>
          </div>
          <el-empty v-else :image-size="40" description="暂无合同附件" />
        </section>

        <section class="detail-card work-order-card">
          <div class="section-title-row">
            <div>
              <h3>工单与当前工序</h3>
              <p>只读跟踪，不在销售订单草稿阶段创建工单。</p>
            </div>
          </div>
          <div v-if="(order.work_order_tracking || []).length" class="work-order-list">
            <div v-for="work in order.work_order_tracking" :key="work.work_order_no" class="work-order-row">
              <div><b>{{ work.work_order_no }}</b><span>订单行 {{ work.line_no }}</span></div>
              <div><span>当前工序</span><b>{{ work.current_process_name || '-' }}</b></div>
              <div><span>进度</span><b>{{ work.progress_text || '-' }}</b></div>
            </div>
          </div>
          <el-empty v-else :image-size="40" description="当前订单尚未生成工单" />
        </section>
      </div>

      <div class="bottom-layout">
        <section class="detail-card drawing-card">
          <h3>设计图纸与技术资料</h3>
          <div v-for="line in order.lines || []" :key="line.id" class="line-file-block">
            <h4>行 {{ line.line_no }}：{{ line.product_name }} / {{ line.sku_name }}</h4>
            <el-table :data="lineFiles(line)" border size="mini" empty-text="暂无图纸或技术附件">
              <el-table-column prop="file_name" label="文件名称" min-width="130" show-overflow-tooltip />
              <el-table-column label="版本" width="58" align="center"><template slot-scope="{row}">V{{ row.version_no || 1 }}</template></el-table-column>
              <el-table-column label="说明" min-width="108" show-overflow-tooltip>
                <template slot-scope="{row}">{{ row.remark || statusText(row.file_type) }}</template>
              </el-table-column>
              <el-table-column label="上传信息" min-width="116" show-overflow-tooltip>
                <template slot-scope="{row}">{{ row.uploaded_by || '-' }} / {{ date(row.uploaded_at) }}</template>
              </el-table-column>
              <el-table-column label="操作" width="126">
                <template slot-scope="{row}">
                  <el-button v-if="row.attachment_id && previewable(row)" type="text" size="mini" @click="previewAttachment(row)">预览</el-button>
                  <el-button v-if="row.attachment_id && row.can_download !== false" type="text" size="mini" @click="downloadAttachment(row)">下载</el-button>
                  <el-button v-if="canDeleteAttachment(row)" type="text" size="mini" class="danger-link" @click="deleteAttachment(row)">删除</el-button>
                  <span v-if="!row.attachment_id">-</span>
                </template>
              </el-table-column>
            </el-table>
          </div>
        </section>

        <section ref="logsSection" class="detail-card audit-card">
          <h3>日志与版本</h3>
          <div class="log-grid">
            <section>
              <h4>操作日志</h4>
              <div class="sales-order-operation-log__body">
                <el-timeline>
                  <el-timeline-item v-for="log in order.logs || []" :key="log.id" :timestamp="date(log.created_at)">
                    <b>{{ statusText(log.action) }}</b>
                    <p>{{ log.content }}</p>
                  </el-timeline-item>
                </el-timeline>
              </div>
            </section>
            <section>
              <h4>版本记录</h4>
              <div class="sales-order-version-list__body">
                <el-table :data="order.versions || []" border size="mini" :max-height="320">
                  <el-table-column prop="version_no" label="版本" width="48" />
                  <el-table-column label="变更类型" min-width="76" show-overflow-tooltip><template slot-scope="{row}">{{ statusText(row.change_type) }}</template></el-table-column>
                  <el-table-column prop="operator" label="操作人" width="62" show-overflow-tooltip />
                  <el-table-column label="时间" min-width="104" show-overflow-tooltip><template slot-scope="{row}">{{ date(row.created_at) }}</template></el-table-column>
                </el-table>
              </div>
            </section>
          </div>
        </section>
      </div>

      <section v-if="order.approval_task" class="detail-card approval-progress-strip">
        <div class="approval-status-block"><h3>审核状态与进度</h3><div><span>审核状态：<el-tag size="mini" :type="approvalStatusTag(order.approval_task.task_status)">{{ approvalStatusText(order.approval_task.task_status) }}</el-tag></span><span>Candidate：<b>V{{ order.approval_task.business_snapshot && order.approval_task.business_snapshot.candidate_version || '-' }}</b></span><span>审核结果：<b>{{ order.approval_task.task_status === 'APPROVED' ? '已通过' : order.approval_task.task_status === 'REJECTED' ? '已驳回' : '—' }}</b></span></div><p>本页仅展示审核状态、结果与进度，不在订单内执行审核。</p></div>
        <div class="approval-node-progress"><div v-for="(node,index) in orderApprovalSteps" :key="node.key" class="approval-node" :class="node.state"><i v-if="index" class="connector"/><em><i v-if="node.state==='done'" class="el-icon-check"/><template v-else>{{ index+1 }}</template></em><b>{{ node.name }}</b></div></div>
        <div class="approval-current">当前等待：<b>{{ orderApprovalCurrent }}</b><el-button type="text" @click="$router.push(`/approvals/tasks/${order.approval_task.id}`)">进入审核中心 <i class="el-icon-right"/></el-button></div>
      </section>

      <section ref="changeHistory" class="detail-card order-change-history-card">
        <div class="section-title-row"><div><h3>订单变更历史</h3><p>变更事实与操作日志分开留存；历史版本不会被后续变更覆盖。</p></div></div>
        <el-table v-loading="changeLoading" :data="changes.rows" border size="mini" empty-text="暂无订单变更记录">
          <el-table-column label="版本" width="76" align="center"><template slot-scope="{row}">V{{ changeVersion(row) }}</template></el-table-column>
          <el-table-column prop="change_no" label="变更单号" min-width="150" />
          <el-table-column label="变更时间" min-width="154"><template slot-scope="{row}">{{ date(row.applied_at || row.created_at) }}</template></el-table-column>
          <el-table-column prop="operator" label="操作人" width="96" />
          <el-table-column prop="reason" label="变更原因" min-width="220" show-overflow-tooltip />
          <el-table-column label="金额变化" min-width="126" align="right"><template slot-scope="{row}">{{ changeAmountText(row) }}</template></el-table-column>
          <el-table-column label="状态" width="86" align="center"><template><el-tag size="mini" type="success">已生效</el-tag></template></el-table-column>
          <el-table-column label="操作" width="82" align="center"><template slot-scope="{row}"><el-button type="text" size="mini" @click="openChange(row)">查看详情</el-button></template></el-table-column>
        </el-table>
        <div v-if="changes.total" class="order-change-pagination"><span>共 {{ changes.total }} 条</span><el-pagination background small layout="prev, pager, next" :current-page="changes.page" :page-size="changes.per_page" :total="changes.total" @current-change="loadChanges" /></div>
        <div class="candidate-history-title"><h4>变更审核历史</h4><span>Candidate 在审核通过前不会修改正式订单。</span></div>
        <el-table :data="candidateHistory" border size="mini" empty-text="暂无变更审核记录">
          <el-table-column label="Candidate" width="116"><template slot-scope="{row}">V{{ row.candidate_version }} / {{ row.candidate_no }}</template></el-table-column>
          <el-table-column label="基准版本" width="86" align="center"><template slot-scope="{row}">V{{ row.base_version }}</template></el-table-column>
          <el-table-column prop="change_reason" label="变更原因" min-width="180" show-overflow-tooltip />
          <el-table-column prop="submitted_by" label="提交人" width="92" />
          <el-table-column label="提交时间" min-width="142"><template slot-scope="{row}">{{ date(row.submitted_at) }}</template></el-table-column>
          <el-table-column label="审核状态" width="96" align="center"><template slot-scope="{row}"><el-tag size="mini" :type="approvalStatusTag(row.candidate_status)">{{ approvalStatusText(row.candidate_status) }}</el-tag></template></el-table-column>
          <el-table-column label="审核进度" min-width="150" show-overflow-tooltip><template slot-scope="{row}">{{ candidateApprovalProgress(row) }}</template></el-table-column>
          <el-table-column label="操作" width="84" align="center"><template slot-scope="{row}"><el-button type="text" size="mini" @click="openCandidate(row)">查看详情</el-button></template></el-table-column>
        </el-table>
      </section>
    </div>

    <sales-order-attachment-preview-dialog :visible.sync="previewVisible" :file="previewFile" />
    <el-dialog title="订单变更详情" :visible.sync="changeDetailVisible" width="900px" class="order-change-detail-dialog" append-to-body>
      <div v-if="selectedChange" class="change-detail-readonly"><div class="change-detail-meta"><span><b>变更单号</b>{{ selectedChange.change_no }}</span><span><b>原版本</b>V{{ changeVersion(selectedChange) - 1 }}</span><span><b>新版本</b>V{{ changeVersion(selectedChange) }}</span><span><b>操作人</b>{{ selectedChange.operator || '-' }}</span></div><div class="change-detail-reason"><b>变更原因</b><p>{{ selectedChange.reason }}</p></div><el-table :data="changeDiffRows(selectedChange)" border size="mini"><el-table-column prop="label" label="变更字段" min-width="130" /><el-table-column prop="before" label="修改前" min-width="150" show-overflow-tooltip /><el-table-column prop="after" label="修改后" min-width="150" show-overflow-tooltip /><el-table-column prop="business_impact_text" label="业务影响" min-width="150" show-overflow-tooltip /><el-table-column label="生效方式" width="92"><template slot-scope="{row}">{{ row.immediate_effect ? '立即生效' : '审核后生效' }}</template></el-table-column></el-table><el-alert type="warning" :closable="false" show-icon :title="changeEffectText(selectedChange)" /></div>
      <span slot="footer"><el-button @click="changeDetailVisible=false">关闭</el-button></span>
    </el-dialog>
    <el-dialog title="Candidate 审核详情" :visible.sync="candidateDetailVisible" width="900px" class="order-change-detail-dialog" append-to-body>
      <div v-if="selectedCandidate" class="change-detail-readonly">
        <div class="change-detail-meta"><span><b>Candidate</b>{{ selectedCandidate.candidate_no }}</span><span><b>基准版本</b>V{{ selectedCandidate.base_version }}</span><span><b>候选版本</b>V{{ selectedCandidate.candidate_version }}</span><span><b>状态</b>{{ approvalStatusText(selectedCandidate.candidate_status) }}</span></div>
        <div class="change-detail-reason"><b>变更原因</b><p>{{ selectedCandidate.change_reason || '-' }}</p></div>
        <el-table :data="selectedCandidate.structured_diffs || []" border size="mini"><el-table-column prop="label" label="变更字段" min-width="130" /><el-table-column prop="before" label="修改前" min-width="150" show-overflow-tooltip /><el-table-column prop="after" label="修改后" min-width="150" show-overflow-tooltip /><el-table-column prop="business_impact_text" label="业务影响" min-width="160" show-overflow-tooltip /><el-table-column label="审核要求" min-width="130"><template slot-scope="{row}">{{ (row.approval_requirements || []).map(approvalTypeText).join(' + ') || '无需审核' }}</template></el-table-column></el-table>
        <el-alert v-if="selectedCandidate.conflict_reason" type="error" :closable="false" show-icon :title="selectedCandidate.conflict_reason" />
      </div>
      <span slot="footer"><el-button @click="candidateDetailVisible=false">关闭</el-button></span>
    </el-dialog>
  </section>
</template>

<script>
import { confirmSalesOrder, formalConfirmSalesOrder, deleteSalesOrderAttachment, deleteSalesOrderDraft, downloadSalesOrderAttachment, getSalesOrder, listSalesOrderChanges } from '@/api/erp/sales'
import SalesOrderAttachmentPreviewDialog from '@/components/sales/SalesOrderAttachmentPreviewDialog.vue'
import { statusTag, statusText } from '@/utils/erpStatus'

export default {
  components: { SalesOrderAttachmentPreviewDialog },
  data: () => ({
    order: {},
    previewVisible: false,
    previewFile: null,
    changeLoading: false,
    changes: { rows: [], total: 0, page: 1, per_page: 10 },
    selectedChange: null,
    changeDetailVisible: false,
    selectedCandidate: null,
    candidateDetailVisible: false
  }),
  computed: {
    shippingCarrierName() {
      return this.order.default_carrier_name_snapshot || (this.order.shipping_snapshot && (this.order.shipping_snapshot.default_carrier_name || this.order.shipping_snapshot.carrier_name)) || this.order.carrier_id || '-'
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
    candidateHistory() { return [...(this.order.change_candidates || [])].sort((a, b) => Number(b.candidate_version || 0) - Number(a.candidate_version || 0)) }
  },
  async created() {
    await this.load()
  },
  methods: {
    async load() {
      const { data } = await getSalesOrder(this.$route.params.id)
      this.order = data
      await this.loadChanges(1)
      if (this.$route.query.tab === 'logs') this.scrollToLogs()
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
      await this.$confirm('正式确认后将锁定本次销售单位、默认履约 Item、Item 基本单位和履约因子，并进入订单生产确认。是否继续？', '正式确认订单', { type: 'warning' })
      await formalConfirmSalesOrder(this.order.id)
      this.$message.success('订单已正式确认，履约换算快照已锁定')
      await this.load()
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
    scrollToChanges() { this.$nextTick(() => this.$refs.changeHistory && this.$refs.changeHistory.scrollIntoView({ behavior: 'smooth', block: 'start' })) },
    scrollToLogs() { this.$nextTick(() => this.$refs.logsSection && this.$refs.logsSection.scrollIntoView({ behavior: 'smooth', block: 'start' })) },
    changeVersion(row) {
      if (row && row.version_no) return Number(row.version_no)
      return Math.max(1, ...(this.order.versions || []).filter(item => String(item.remark || '').includes(row.change_no)).map(item => Number(item.version_no || 0)))
    },
    changeAmountText(row) { const before = this.changeSnapshotTotal(row.before_snapshot); const after = this.changeSnapshotTotal(row.after_snapshot); const diff = after - before; return `${diff >= 0 ? '+' : '-'}¥${this.money(Math.abs(diff))}` },
    changeSnapshotTotal(snapshot) { return ((snapshot && snapshot.lines) || []).reduce((sum, line) => sum + Number(line.amount_incl_tax || line.amount || 0), 0) },
    openChange(row) { this.selectedChange = row; this.changeDetailVisible = true },
    changeDiffRows(row) { return row && Array.isArray(row.structured_diffs) ? row.structured_diffs : [] },
    approvalTypeText(value) { return ({business:'业务审核',finance:'财务审核',fulfillment:'履约复核'})[value] || value },
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
        ? '该变更已生效：受影响的旧预留和旧履约计划已废止，订单须按新版本重新规划。'
        : '该变更已生效：订单商业信息已更新，原库存预留、履约计划与生产确认保持不变。'
    },
    percent(v) { return `${(Number(v || 0) * 100).toFixed(2)}%` },
    money(v) {
      return Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    },
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
    fulfillmentStatusText(value) { return ({ pending: '待履约', partial: '部分履约', fulfilled: '已履约', cancelled: '已取消' })[value] || value || '-' },
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
.sales-detail-page{padding:12px 14px 24px;min-height:calc(100vh - 52px);overflow-x:hidden;background:#f7f8fa;color:#172033}
.detail-toolbar{min-height:46px;display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;gap:12px}
.crumb{color:#607085;margin-bottom:2px;font-size:12px}
.detail-toolbar h1{margin:0;font-size:19px}
.detail-toolbar>div:last-child{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
.summary-strip{display:grid;grid-template-columns:1.12fr 1.05fr 1.45fr 1.05fr .95fr 1.05fr .9fr 1fr 1fr;background:#fff;border:1px solid #e4e9f0;border-radius:5px;margin-bottom:10px}
.summary-strip div{min-width:0;padding:10px 8px;border-right:1px solid #eef2f6}
.summary-strip div:last-child{border-right:0}
.summary-strip span{display:block;color:#64748b;font-size:12px}
.summary-strip b{display:block;overflow:hidden;margin-top:4px;font-size:13px;line-height:18px;text-overflow:ellipsis}
.detail-one-page{display:grid;gap:10px;min-width:0}
.overview-layout{display:grid;grid-template-columns:minmax(0,.9fr) minmax(0,1.35fr);gap:10px;min-width:0}
.operation-layout{display:grid;grid-template-columns:minmax(0,.78fr) minmax(0,1.3fr) minmax(0,1fr);gap:10px;min-width:0}
.bottom-layout{display:grid;grid-template-columns:minmax(0,1.05fr) minmax(0,1fr);gap:10px;min-width:0}
.detail-card{min-width:0;background:#fff;border:1px solid #e4e9f0;border-radius:5px;padding:11px 12px}
.detail-card h3{margin:0 0 9px;font-size:15px}
.detail-card h3 small{font-size:12px;font-weight:400;color:#7b8794}
.detail-card h4{margin:8px 0 7px;font-size:13px}
.overview-grid{display:grid;gap:0 18px}
.overview-grid--order{grid-template-columns:repeat(2,minmax(0,1fr))}
.overview-grid--customer{grid-template-columns:repeat(2,minmax(0,1fr))}
.overview-grid div{display:grid;grid-template-columns:88px minmax(0,1fr);align-items:center;min-height:33px;border-bottom:1px dashed #edf1f5}
.overview-grid div:nth-last-child(-n+2){border-bottom:0}
.overview-grid span,.kv-list span{color:#64748b;font-size:12px}
.overview-grid b,.kv-list b{min-width:0;overflow:hidden;font-size:12px;line-height:18px;text-overflow:ellipsis}
.kv-list{display:grid;gap:0}
.kv-list>div{display:grid;grid-template-columns:96px minmax(0,1fr);align-items:center;min-height:34px;border-bottom:1px dashed #edf1f5}
.kv-list>div:last-child{border-bottom:0}
.compact-file-list{border:1px solid #e4e9f0;border-radius:4px;overflow:hidden}
.compact-file-row{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px 9px;border-bottom:1px solid #eef2f6}
.compact-file-row:last-child{border-bottom:0}
.compact-file-main{min-width:0}
.compact-file-main b,.compact-file-main span{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.compact-file-main b{font-size:12px}
.compact-file-main span{margin-top:3px;color:#7b8794;font-size:11px}
.compact-file-actions{flex:none;white-space:nowrap}
.compact-file-actions>span{color:#94a3b8;font-size:11px}
.item-abnormal,.danger-link{color:#dc2626}
.work-order-list{border:1px solid #e4e9f0;border-radius:4px}
.work-order-row{display:grid;grid-template-columns:1.15fr 1fr .55fr;gap:8px;padding:8px;border-bottom:1px solid #eef2f6}
.work-order-row:last-child{border-bottom:0}
.work-order-row span,.work-order-row b{display:block;font-size:11px}
.work-order-row span{color:#7b8794}
.line-file-block{margin-bottom:10px}.line-file-block:last-child{margin-bottom:0}
.log-grid{display:grid;grid-template-columns:minmax(0,.8fr) minmax(0,1.2fr);gap:12px}
.sales-order-operation-log__body{max-height:320px;overflow-y:auto;overflow-x:hidden;padding-right:4px}
.sales-order-version-list__body{max-width:100%;overflow-x:auto;overflow-y:hidden}
.sales-order-version-list__body :deep(.el-table__header-wrapper){position:sticky;top:0;z-index:2;background:#fff}
.section-title-row p{margin:-5px 0 8px;color:#7b8794;font-size:12px}
.sales-detail-page :deep(.el-empty){padding:8px 0 4px}
.sales-detail-page :deep(.el-empty__description){margin-top:2px}
.sales-detail-page :deep(.el-empty__description p){font-size:12px}
.sales-detail-page :deep(.el-table){width:100%!important}
.sales-detail-page :deep(.el-table th){background:#f8fafc;color:#334155}
.sales-detail-page :deep(.el-button--success){background:#00984f;border-color:#00984f}
.sales-detail-page :deep(.el-timeline){padding-left:5px}
.sales-detail-page :deep(.el-timeline-item){padding-bottom:10px}
.sales-detail-page :deep(.el-timeline-item__wrapper){padding-left:18px}
.sales-detail-page :deep(.el-timeline-item__timestamp){font-size:11px}
.sales-detail-page :deep(.el-timeline-item p){margin:3px 0 0;font-size:12px}
.change-block-alert,.change-success-alert{margin:0 0 10px}
.change-block-alert :deep(.el-alert__title),.change-success-alert :deep(.el-alert__title){font-size:12px}
.order-change-history-card{scroll-margin-top:12px}
.approval-progress-strip{display:grid;grid-template-columns:420px minmax(420px,1fr) 190px;align-items:center;gap:18px;padding:13px 14px}.approval-status-block h3{margin-bottom:8px}.approval-status-block>div{display:flex;gap:24px;font-size:12px}.approval-status-block p{margin:8px 0 0;color:#7b8794;font-size:11px}.approval-node-progress{display:flex;align-items:flex-start;padding-top:2px}.approval-node{position:relative;flex:1;text-align:center}.approval-node em{position:relative;z-index:2;display:grid;place-items:center;width:22px;height:22px;margin:auto;border:1px solid #bdc7d2;border-radius:50%;background:#fff;font-size:11px;font-style:normal}.approval-node b{display:block;margin-top:7px;font-size:11px}.approval-node .connector{position:absolute;right:50%;left:-50%;top:10px;height:2px;background:#d5dce3}.approval-node.done em{color:#fff;background:#10a65e;border-color:#10a65e}.approval-node.done .connector,.approval-node.active .connector{background:#10a65e}.approval-node.active em{color:#0a68df;border-color:#0a68df;background:#eaf3ff}.approval-node.failed em{color:#e55353;border-color:#e55353}.approval-current{font-size:11px;text-align:right}.approval-current b{display:block;margin:5px 0;color:#176bd6}.approval-current .el-button{font-size:11px}
.order-change-pagination{display:flex;align-items:center;justify-content:space-between;padding-top:10px;color:#64748b;font-size:12px}
.candidate-history-title{display:flex;align-items:center;gap:12px;margin:16px 0 8px}.candidate-history-title h4{margin:0;font-size:13px}.candidate-history-title span{color:#7b8794;font-size:11px}
.change-detail-readonly{display:grid;gap:12px}
.change-detail-meta{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));border:1px solid #e4e9f0;border-radius:4px;overflow:hidden}
.change-detail-meta span{display:grid;gap:4px;padding:9px 10px;border-right:1px solid #e4e9f0;font-size:12px;color:#334155}
.change-detail-meta span:last-child{border-right:0}.change-detail-meta b{color:#64748b;font-weight:400}
.change-detail-reason{border:1px solid #e4e9f0;border-radius:4px;padding:9px 10px;color:#334155;font-size:12px}.change-detail-reason b{color:#64748b}.change-detail-reason p{margin:5px 0 0;white-space:pre-wrap;line-height:1.6}
@media(max-width:1180px){
  .summary-strip{grid-template-columns:repeat(3,minmax(0,1fr))}
  .summary-strip div:nth-child(3n){border-right:0}
  .overview-layout,.bottom-layout{grid-template-columns:1fr}
  .operation-layout{grid-template-columns:repeat(2,minmax(0,1fr))}
  .work-order-card{grid-column:1 / -1}
  .change-detail-meta{grid-template-columns:repeat(2,minmax(0,1fr))}.change-detail-meta span:nth-child(2){border-right:0}
  .approval-progress-strip{grid-template-columns:1fr}.approval-current{text-align:left}.approval-status-block>div{flex-wrap:wrap}.approval-node-progress{overflow-x:auto}.approval-node{min-width:110px}
}
@media(max-width:760px){
  .sales-detail-page{padding:10px}
  .detail-toolbar{align-items:flex-start}
  .summary-strip{grid-template-columns:repeat(2,minmax(0,1fr))}
  .summary-strip div:nth-child(3n){border-right:1px solid #eef2f6}
  .summary-strip div:nth-child(2n){border-right:0}
  .operation-layout,.log-grid{grid-template-columns:1fr}
  .overview-grid--order,.overview-grid--customer{grid-template-columns:1fr}
  .overview-grid div:nth-last-child(-n+2){border-bottom:1px dashed #edf1f5}
  .overview-grid div:last-child{border-bottom:0}
  .sales-order-operation-log__body{max-height:260px}
  .sales-order-version-list__body :deep(.el-table){max-height:260px!important}
  .order-change-pagination{gap:8px;align-items:flex-start;flex-direction:column}.change-detail-meta{grid-template-columns:1fr}.change-detail-meta span{border-right:0;border-bottom:1px solid #e4e9f0}.change-detail-meta span:last-child{border-bottom:0}
}
@media(max-width:480px){
  .summary-strip{grid-template-columns:1fr}
  .summary-strip div{border-right:0}
  .detail-toolbar{display:block}
  .detail-toolbar>div:last-child{margin-top:8px;justify-content:flex-start}
}
</style>
