<template>
  <section class="as-page" v-loading="loading">
    <div class="page-head">
      <div>
        <p class="breadcrumb">销售管理&nbsp;&nbsp;/&nbsp;&nbsp;售后单&nbsp;&nbsp;/&nbsp;&nbsp;售后单详情</p>
        <h1>
          {{ row.case_no }}
          <el-tag v-if="row.status" size="mini" :type="statusTag(row.status)">{{ statusLabel(row.status) }}</el-tag>
          <el-tag v-for="t in actionTypes" :key="t" size="mini" class="as-tag">{{ actionLabel(t) }}</el-tag>
          <el-tag v-if="row.warranty_status" size="mini" type="info">{{ warrantyLabel(row.warranty_status) }}</el-tag>
        </h1>
      </div>
      <div class="head-actions">
        <el-button size="small" @click="$router.back()">返回</el-button>
        <el-button size="small" @click="goOrder">原订单</el-button>
        <el-button v-if="row.status === 'pending' && $can('aftersales.handle')" size="small" type="success" @click="accept">受理</el-button>
        <el-button v-if="canAct" size="small" type="success" plain icon="el-icon-plus" @click="actionDialog = true">添加处理</el-button>
        <el-button v-if="canEdit" size="small" @click="$refs.caseForm.open(row)">编辑</el-button>
        <el-button v-if="canClose" size="small" type="success" @click="openClose">关闭</el-button>
        <el-button v-if="canCancel" size="small" type="danger" plain @click="cancelDialog = true">取消售后单</el-button>
      </div>
    </div>

    <div class="as-two">
      <div>
        <div class="card"><h3>问题信息</h3>
          <div class="kv">
            <div><span>原订单</span><b class="mono">{{ row.order && row.order.sales_order_no }}</b></div>
            <div><span>客户</span><b>{{ customerText }}</b></div>
            <div><span>负责人</span><b>{{ row.owner_name || '—' }}</b></div>
            <div><span>问题类型</span><b>{{ issueLabel(row.issue_type) }}</b></div>
            <div><span>责任方</span><b>{{ responsibilityLabel(row.responsibility) }}</b></div>
            <div><span>反馈时间</span><b class="mono">{{ fmt(row.reported_at) }}</b></div>
            <div class="full"><span>问题描述</span><b>{{ row.description }}</b></div>
            <div v-if="(row.attachments || []).length" class="full"><span>图片 / 视频</span><div class="media-row">
              <div v-for="a in row.attachments" :key="a.id" class="media-item">
                <aftersales-attachment-media :attachment="a" />
                <div class="media-name" :title="a.original_name">{{ a.original_name }}</div>
              </div>
            </div></div>
          </div>
        </div>

        <div class="card"><h3>涉及商品</h3>
          <el-table :data="row.items || []" border size="mini">
            <el-table-column label="行号" width="55"><template slot-scope="{ $index }">{{ $index + 1 }}</template></el-table-column>
            <el-table-column label="商品 / SKU" min-width="180"><template slot-scope="{ row: r }">{{ r.product_name || r.item_name }}<span v-if="r.sku_name" class="muted"> / {{ r.sku_name }}</span></template></el-table-column>
            <el-table-column prop="serial_no" label="序列号" width="160"><template slot-scope="{ row: r }"><span class="mono">{{ r.serial_no || '—' }}</span></template></el-table-column>
            <el-table-column label="数量" width="70" align="right"><template slot-scope="{ row: r }">{{ +r.qty }}</template></el-table-column>
            <el-table-column prop="remark" label="备注" min-width="100" />
          </el-table>
        </div>

        <div class="card"><h3>处理<span class="muted sm">每个处理单独推进；全部完成后可关闭售后单</span></h3>
          <div v-if="!(row.actions || []).length" class="muted" style="padding:8px 0">暂无处理记录</div>
          <div v-for="a in activeActions" :key="a.id" class="act">
            <div class="act-h">
              <el-tag size="mini" class="as-tag">{{ actionLabel(a.action_type) }}</el-tag>
              <b>{{ actionTitle(a) }}</b>
              <span v-if="a.planned_disposition" class="muted sm">退回件计划处置：{{ dispositionLabel(a.planned_disposition) }}</span>
              <div class="r">
                <el-button v-if="a.status === 'processing' && $can('aftersales.handle')" type="text" size="mini" @click="cancelAction(a)">取消处理</el-button>
              </div>
            </div>
            <div class="act-status"><el-tag size="mini" :type="a.status === 'completed' ? 'success' : a.status === 'cancelled' ? 'info' : 'warning'">{{ actionStatus(a.status) }}</el-tag>
              <span v-if="a.completed_at" class="muted sm">{{ fmt(a.completed_at) }}</span>
              <span v-if="a.cancel_reason" class="muted sm">{{ a.cancel_reason }}</span>
            </div>
            <el-table v-if="a.sales_return" :data="[a.sales_return]" border size="mini" class="ret-table">
              <el-table-column prop="return_no" label="单据" width="160"><template slot-scope="{ row: r }"><span class="mono">{{ r.return_no }}</span></template></el-table-column>
              <el-table-column label="类型" width="140"><template>销售退货单（{{ a.action_type === 'exchange' ? '换货' : '退货退款' }}）</template></el-table-column>
              <el-table-column label="内容" min-width="180"><template>{{ a.action_type === 'exchange' ? '退回旧件' : '退货退款' }}{{ a.planned_disposition ? ` · 计划处置：${dispositionLabel(a.planned_disposition)}` : '' }}</template></el-table-column>
              <el-table-column label="状态" width="130"><template slot-scope="{ row: r }"><el-tag size="mini" :type="returnTag(r.return_status)">{{ returnLabel(r.return_status) }}</el-tag></template></el-table-column>
              <el-table-column label="操作" width="90"><template slot-scope="{ row: r }"><el-button type="text" size="mini" @click="$router.push(`/sales/returns/${r.id}/detail`)">查看</el-button></template></el-table-column>
            </el-table>
            <el-table v-for="r in (a.reships || [])" :key="`rs-${r.id}`" :data="[r]" border size="mini" class="ret-table">
              <el-table-column prop="reship_no" label="单据" width="160"><template slot-scope="{ row: s }"><span class="mono">{{ s.reship_no }}</span></template></el-table-column>
              <el-table-column label="类型" width="140"><template>售后补发单</template></el-table-column>
              <el-table-column label="内容" min-width="180"><template slot-scope="{ row: s }">{{ reshipContent(s) }}</template></el-table-column>
              <el-table-column label="状态" width="130"><template slot-scope="{ row: s }"><el-tag size="mini" :type="reshipTag(s.status)">{{ reshipLabel(s.status) }}</el-tag></template></el-table-column>
              <el-table-column label="操作" width="150"><template slot-scope="{ row: s }">
                <el-tooltip v-if="s.status === 'pending' && reshipBlockReason(a, s)" :content="reshipBlockReason(a, s)" placement="top"><span><el-button type="text" size="mini" disabled>出库</el-button></span></el-tooltip>
                <el-button v-else-if="s.status === 'pending' && $can('aftersales.handle')" type="text" size="mini" @click="openShip(s)">出库</el-button>
                <el-button type="text" size="mini" @click="shipDetail = s">查看</el-button>
              </template></el-table-column>
            </el-table>
            <div v-if="a.action_type === 'compensation'" class="rec-content">
              <template v-if="canViewAmount">¥ {{ (+a.compensation_amount).toFixed(2) }}（{{ compensationMethodLabel(a.compensation_method) }}） · </template>
              {{ fmt(a.compensated_at) }}{{ a.compensation_payee ? ` · 收款人 ${a.compensation_payee}` : '' }}
              <div v-if="a.record_content" class="muted sm" style="margin-top:2px">{{ a.record_content }}</div>
            </div>
            <div v-if="a.action_type === 'record' && a.record_content" class="rec-content">{{ a.record_content }}</div>
          </div>
        </div>
      </div>

      <div>
        <div v-if="canViewAmount" class="card"><h3>费用</h3>
          <div class="kv one"><div><span>运费</span><b class="mono">{{ freightText }}</b></div></div>
        </div>
        <div class="card"><h3>索赔<span class="r"><el-button v-if="$can('aftersales.handle') && row.status !== 'cancelled'" type="text" size="mini" @click="openClaim(null)">＋ 登记索赔</el-button></span></h3>
          <el-table :data="row.claims || []" border size="mini">
            <el-table-column label="对象" min-width="140"><template slot-scope="{ row: c }">{{ c.target_name }}<div class="muted sm">{{ targetTypeLabel(c.target_type) }}{{ c.related_doc_no ? ' · ' + c.related_doc_no : '' }}</div></template></el-table-column>
            <el-table-column v-if="canViewAmount" label="金额" width="100" align="right"><template slot-scope="{ row: c }">{{ fmtAmt(c.claim_amount) }}</template></el-table-column>
            <el-table-column label="状态" width="90"><template slot-scope="{ row: c }"><el-tag size="mini" :type="c.status === 'paid' ? 'success' : c.status === 'rejected' || c.status === 'abandoned' ? 'info' : 'warning'">{{ claimLabel(c.status) }}</el-tag></template></el-table-column>
            <el-table-column label="操作" width="70"><template slot-scope="{ row: c }"><el-button v-if="$can('aftersales.handle')" type="text" size="mini" @click="openClaim(c)">跟进</el-button></template></el-table-column>
          </el-table>
          <div v-if="!(row.claims || []).length" class="muted" style="padding:6px 0">暂无索赔</div>
        </div>
        <div class="card"><h3>记录</h3>
          <div class="timeline">
            <div v-for="l in row.logs || []" :key="l.id"><span>{{ fmtShort(l.created_at) }}</span>{{ l.operator_name || '系统' }} {{ l.content }}</div>
          </div>
        </div>
      </div>
    </div>

    <aftersales-case-form ref="caseForm" :order="row.order" @saved="load" />

    <el-dialog :visible.sync="actionDialog" :title="`添加处理 · ${row.case_no}`" width="640px" top="6vh" :close-on-click-modal="false" append-to-body>
      <div v-if="actionErrors.length" class="as-errors"><div v-for="(e, i) in actionErrors" :key="i" class="as-errors-line">{{ e }}</div></div>
      <div class="fi full" style="margin-bottom:12px"><label>处理方式<b>*</b></label>
        <div class="chips">
          <div class="chip" :class="{ on: actionForm.action_type === 'refund' }" @click="actionForm.action_type = 'refund'"><b>退货退款</b><span>生成销售退货单（退款）</span></div>
          <div class="chip" :class="{ on: actionForm.action_type === 'exchange' }" @click="actionForm.action_type = 'exchange'"><b>换货</b><span>退回旧件 + 补发新件</span></div>
          <div class="chip" :class="{ on: actionForm.action_type === 'reship' }" @click="actionForm.action_type = 'reship'"><b>补发</b><span>直接补发物料</span></div>
          <div class="chip" :class="{ on: actionForm.action_type === 'compensation' }" @click="actionForm.action_type = 'compensation'"><b>赔付记录</b><span>直接付给客户，只做记录</span></div>
          <div class="chip" :class="{ on: actionForm.action_type === 'record' }" @click="actionForm.action_type = 'record'"><b>仅记录</b><span>不生成单据</span></div>
        </div>
      </div>
      <div class="as-grid" v-if="actionForm.action_type === 'refund' || actionForm.action_type === 'exchange'">
        <div class="fi"><label>退回方式</label><el-select v-model="actionForm.return_method" size="small" clearable><el-option label="客户寄回" value="customer_ship" /><el-option label="上门取回" value="pickup" /><el-option label="随补发带回" value="with_reship" /></el-select></div>
        <div class="fi"><label>运费承担</label><el-select v-model="actionForm.freight_payer" size="small" clearable><el-option label="公司承担" value="company" /><el-option label="客户承担" value="customer" /><el-option label="承运商承担" value="carrier" /></el-select></div>
        <div v-if="canViewAmount" class="fi"><label>运费金额</label><el-input v-model="actionForm.freight_amount" size="small" /></div>
        <div class="fi"><label>退回件计划处置<b>*</b></label><el-select v-model="actionForm.planned_disposition" size="small"><el-option label="转残次品" value="defective" /><el-option label="转入库（可售）" value="restock" /><el-option label="报废" value="scrap" /></el-select><small>收货后由仓库最终确认</small></div>
        <template v-if="actionForm.action_type === 'exchange'">
          <div class="fi"><label>补发时机<b>*</b></label><el-radio-group v-model="actionForm.reship_timing" size="small"><el-radio label="return_first">先退后发</el-radio><el-radio label="ship_first">先发后退</el-radio></el-radio-group><small>先退后发需退货单收齐后才能出库</small></div>
          <div v-if="actionForm.reship_timing === 'ship_first'" class="fi"><label>先发后退原因<b>*</b></label><el-input v-model="actionForm.ship_first_reason" size="small" placeholder="必填" /></div>
          <div class="fi full"><label>补发商品</label><div class="muted" style="padding:4px 0">= 本次退回商品（同物料同数量），出库时选择库存来源</div></div>
        </template>
      </div>
      <div v-if="actionForm.action_type === 'reship'" class="as-sec" style="margin-top:10px"><div class="as-sec-h">补发物料<span class="r"><el-button type="text" size="mini" @click="reshipItems.push({ item_id: null, qty: 1, case_item_id: null })">＋ 添加物料</el-button></span></div>
        <el-table :data="reshipItems" border size="mini">
          <el-table-column label="物料" min-width="200"><template slot-scope="{ row: r }"><paged-record-select v-model="r.item_id" entity="items" title="选择物料" placeholder="选择物料" /></template></el-table-column>
          <el-table-column label="数量" width="110" align="right"><template slot-scope="{ row: r }"><el-input-number v-model="r.qty" size="mini" :min="0.00000001" :controls="false" style="width:80px" /></template></el-table-column>
          <el-table-column label="关联售后商品" min-width="170"><template slot-scope="{ row: r }"><el-select v-model="r.case_item_id" size="mini" clearable placeholder="可不关联"><el-option v-for="ci in (row.items || [])" :key="ci.id" :label="`${ci.item_name}${ci.serial_no ? ' · ' + ci.serial_no : ''}`" :value="ci.id" /></el-select></template></el-table-column>
          <el-table-column label="" width="60"><template slot-scope="{ $index }"><el-button type="text" size="mini" style="color:#ef4444" @click="reshipItems.splice($index, 1)">删除</el-button></template></el-table-column>
        </el-table>
      </div>
      <template v-if="actionForm.action_type === 'compensation'">
        <div class="as-grid">
          <div class="fi"><label>赔付金额<b>*</b></label><el-input v-model="actionForm.compensation_amount" size="small" :disabled="!canViewAmount" /><small v-if="!canViewAmount">无销售金额权限，仅记录说明</small></div>
          <div class="fi"><label>付款方式<b>*</b></label><el-select v-model="actionForm.compensation_method" size="small"><el-option label="现金" value="cash" /><el-option label="微信" value="wechat" /><el-option label="支付宝" value="alipay" /><el-option label="银行转账" value="bank_transfer" /><el-option label="其他" value="other" /></el-select></div>
          <div class="fi"><label>付款时间<b>*</b></label><el-date-picker v-model="actionForm.compensated_at" size="small" type="datetime" value-format="yyyy-MM-dd HH:mm:ss" style="width:100%" /></div>
          <div class="fi"><label>收款人</label><el-input v-model="actionForm.compensation_payee" size="small" placeholder="选填" /></div>
          <div class="fi full"><label>说明<b>*</b></label><el-input v-model="actionForm.record_content" type="textarea" :rows="3" /></div>
          <div class="fi full"><small class="muted">只做记录，不冲减应收、不进财务和统计。</small></div>
        </div>
      </template>
      <div v-if="actionForm.action_type === 'record'" class="fi full"><label>处理说明<b>*</b></label><el-input v-model="actionForm.record_content" type="textarea" :rows="3" /></div>
      <div v-if="actionForm.action_type === 'refund' || actionForm.action_type === 'exchange'" class="as-sec" style="margin-top:10px"><div class="as-sec-h">本次处理商品</div>
        <el-table :data="actionItems" border size="mini">
          <el-table-column label="商品 / SKU" min-width="170"><template slot-scope="{ row: r }">{{ r.product_name || r.item_name }}<span v-if="r.sku_name" class="muted"> / {{ r.sku_name }}</span></template></el-table-column>
          <el-table-column prop="serial_no" label="序列号" width="150"><template slot-scope="{ row: r }"><span class="mono">{{ r.serial_no || '—' }}</span></template></el-table-column>
          <el-table-column label="本次退回" width="110" align="right"><template slot-scope="{ row: r }">
            <el-input-number v-if="!r.inventory_serial_id" v-model="r.return_qty" size="mini" :min="0" :max="r.left" :controls="false" style="width:80px" />
            <el-checkbox v-else v-model="r.checked" :label="`退回 ${+r.left}`" />
          </template></el-table-column>
          <el-table-column label="可处理" width="80" align="right"><template slot-scope="{ row: r }">{{ +r.left }}</template></el-table-column>
        </el-table>
      </div>
      <div class="fi full" style="margin-top:10px"><label>备注</label><el-input v-model="actionForm.remark" size="small" /></div>
      <div slot="footer"><el-button size="small" @click="actionDialog = false">取消</el-button><el-button size="small" type="success" :loading="saving" @click="saveAction">保存处理</el-button></div>
    </el-dialog>

    <el-dialog :visible.sync="shipDialog" :title="`补发出库 · ${ship.reship_no || ''}`" width="960px" top="5vh" :close-on-click-modal="false" append-to-body>
      <div v-if="shipErrors.length" class="as-errors"><div v-for="(e, i) in shipErrors" :key="i" class="as-errors-line">{{ e }}</div></div>
      <div class="as-grid">
        <div class="fi"><label>来源售后单</label><div class="inp">{{ row.case_no }} · {{ actionLabel(shipAction?.action_type) }}</div></div>
        <div class="fi"><label>原订单</label><div class="inp mono">{{ row.order?.sales_order_no }}</div></div>
        <div class="fi"><label>出库类型</label><div class="inp">售后补发出库</div><small>不产生销售收入，成本计入售后费用</small></div>
        <div class="fi"><label>快递公司<b>*</b></label><paged-record-select v-model="shipForm.carrier_id" entity="carriers" title="选择承运商" placeholder="默认客户指定物流，可改" :selected-rows.sync="carrierRows" /></div>
        <div class="fi"><label>运单号<b>*</b></label><el-input v-model="shipForm.tracking_no" size="small" class="mono" /></div>
        <div class="fi"><label>出库时间<b>*</b></label><el-date-picker v-model="shipForm.shipped_at" size="small" type="datetime" value-format="yyyy-MM-dd HH:mm:ss" style="width:100%" /></div>
        <div class="fi"><label>收件人<b>*</b></label><el-input v-model="shipForm.receiver_name" size="small" /></div>
        <div class="fi"><label>电话<b>*</b></label><el-input v-model="shipForm.receiver_phone" size="small" /></div>
        <div class="fi full"><label>地址<b>*</b></label><el-input v-model="shipForm.receiver_address" size="small" placeholder="取自原订单，可改" /></div>
      </div>
      <div class="as-sec" style="margin-top:10px"><div class="as-sec-h">补发明细</div>
        <el-table :data="shipRows" border size="mini" :row-class-name="({ row }) => row.short ? 'row-short' : ''">
          <el-table-column label="物料" min-width="170"><template slot-scope="{ row: r }">{{ r.item_name }}<span v-if="r.sku_name" class="muted"> / {{ r.sku_name }}</span></template></el-table-column>
          <el-table-column label="仓库 / 库位 / 批次" min-width="200"><template slot-scope="{ row: r }">
            <paged-record-select v-model="r.inventory_balance_id" :request="r.balanceRequest" :params="{ item_id: r.item_id }" :selected-rows.sync="r.balances" :label="balanceLabel" :columns="balanceColumns" title="选择库存" placeholder="仓库 / 库位 / 批次" size="mini" @change="(value, rows) => pickBalance(r, rows)" />
          </template></el-table-column>
          <el-table-column v-if="shipRows.some(r => r.serial_mode !== 'none')" label="序列号" min-width="170"><template slot-scope="{ row: r }">
            <paged-record-select v-if="r.serial_mode !== 'none'" v-model="r.serial_ids" :request="r.serialRequest" :params="{ item_id: r.item_id, balance_id: r.inventory_balance_id }" :selected-rows.sync="r.serialRows" :label="serialLabel" :columns="serialColumns" title="选择序列号" :placeholder="`选 ${+r.qty} 台`" multiple size="mini" :disabled="!r.inventory_balance_id || r.short" />
            <span v-else class="muted">—</span>
          </template></el-table-column>
          <el-table-column label="数量" width="80" align="right"><template slot-scope="{ row: r }">{{ +r.qty }}</template></el-table-column>
        </el-table>
        <div v-if="shipRows.some(r => r.short)" class="as-tip danger" style="margin-top:8px">可用库存不足，请先备货或改为先退后发。</div>
      </div>
      <div class="as-grid" style="margin-top:10px">
        <div class="fi"><label>包装件数</label><el-input-number v-model="shipForm.package_count" size="small" :min="0" :controls="false" style="width:100%" /></div>
        <div class="fi"><label>包装方式</label><el-select v-model="shipForm.package_method" size="small" clearable><el-option label="木箱" value="wooden_box" /><el-option label="纸箱" value="carton" /><el-option label="裸装" value="bare" /><el-option label="其他" value="other" /></el-select><small>本期只记录件数和方式</small></div>
        <div class="fi full"><label>备注</label><el-input v-model="shipForm.remark" size="small" /></div>
      </div>
      <div slot="footer"><el-button size="small" @click="shipDialog = false">取消</el-button><el-button size="small" type="success" :loading="saving" :disabled="shipRows.some(r => r.short || !r.inventory_balance_id) || !shipForm.carrier_id || !shipForm.tracking_no" @click="saveShip">确认出库</el-button></div>
    </el-dialog>

    <el-dialog :visible="!!shipDetail" title="补发单明细" width="720px" top="8vh" append-to-body @update:visible="v => { if (!v) shipDetail = null }" @close="shipDetail = null">
      <div v-if="shipDetail">
        <div class="summary" style="margin-bottom:8px">
          <div><span>单号</span><b class="mono">{{ shipDetail.reship_no }}</b></div>
          <div><span>状态</span><b>{{ reshipLabel(shipDetail.status) }}</b></div>
          <div><span>承运</span><b>{{ shipDetail.carrier_name || '—' }}</b></div>
          <div><span>运单号</span><b class="mono">{{ shipDetail.tracking_no || '—' }}</b></div>
          <div><span>收货</span><b>{{ [shipDetail.receiver_name, shipDetail.receiver_phone, shipDetail.receiver_address].filter(Boolean).join(' · ') || '—' }}</b></div>
          <div><span>出库时间</span><b>{{ fmt(shipDetail.shipped_at) }}</b></div>
        </div>
        <el-table :data="shipDetail.items || []" border size="mini">
          <el-table-column label="物料" min-width="170"><template slot-scope="{ row: r }">{{ r.item_name }}<span v-if="r.sku_name" class="muted"> / {{ r.sku_name }}</span></template></el-table-column>
          <el-table-column prop="serial_no" label="序列号" width="150"><template slot-scope="{ row: r }"><span class="mono">{{ r.serial_no || '—' }}</span></template></el-table-column>
          <el-table-column label="数量" width="80" align="right"><template slot-scope="{ row: r }">{{ +r.qty }}</template></el-table-column>
          <el-table-column label="库存来源" min-width="160"><template slot-scope="{ row: r }">{{ [r.warehouse_name, r.batch_no].filter(Boolean).join(' / ') || '—' }}</template></el-table-column>
        </el-table>
      </div>
    </el-dialog>

    <el-dialog :visible.sync="claimDialog" :title="`${claimForm.id ? '跟进索赔' : '登记索赔'} · ${row.case_no}`" width="780px" top="6vh" :close-on-click-modal="false" append-to-body>
      <div v-if="claimErrors.length" class="as-errors"><div v-for="(e, i) in claimErrors" :key="i" class="as-errors-line">{{ e }}</div></div>
      <div class="as-grid">
        <div class="fi"><label>索赔对象类型<b>*</b></label><el-select v-model="claimForm.target_type" size="small" :disabled="!!claimForm.id" @change="claimForm.carrier_id = claimForm.supplier_id = null"><el-option label="承运商" value="carrier" /><el-option label="供应商" value="supplier" /><el-option label="其他" value="other" /></el-select></div>
        <div class="fi"><label>索赔对象<b>*</b></label>
          <paged-record-select v-if="claimForm.target_type === 'carrier'" v-model="claimForm.carrier_id" entity="carriers" title="选择承运商" placeholder="请选择承运商" :selected-rows.sync="carrierRows" />
          <paged-record-select v-else-if="claimForm.target_type === 'supplier'" v-model="claimForm.supplier_id" entity="suppliers" :params="{ status: 'enabled' }" title="选择供应商" placeholder="请选择供应商" :selected-rows.sync="supplierRows" />
          <el-input v-else v-model="claimForm.target_name" size="small" placeholder="手填名称" :disabled="!!claimForm.id" />
        </div>
        <div class="fi"><label>关联单据号</label><el-input v-model="claimForm.related_doc_no" size="small" placeholder="如原发货单号" /></div>
        <div v-if="canViewAmount" class="fi"><label>索赔金额<b>*</b></label><el-input v-model="claimForm.claim_amount" size="small" /></div>
        <div class="fi"><label>提出日期<b>*</b></label><el-date-picker v-model="claimForm.claimed_on" size="small" type="date" value-format="yyyy-MM-dd" style="width:100%" :disabled="!!claimForm.id" /></div>
        <div class="fi full"><label>索赔理由<b>*</b></label><el-input v-model="claimForm.reason" type="textarea" :rows="2" /></div>
        <div class="fi full"><label>凭证</label>
          <el-upload :show-file-list="false" :http-request="uploadClaimFile" accept="image/*,video/*" multiple>
            <el-button size="mini" icon="el-icon-upload" :loading="claimUploading">上传</el-button>
          </el-upload>
          <div class="media-row" style="margin-top:6px">
            <div v-for="a in claimAttachments" :key="a.id" class="media-item">
              <aftersales-attachment-media :attachment="a" />
              <div class="media-name" :title="a.original_name">{{ a.original_name }}</div>
              <i class="el-icon-close media-del" @click="removeClaimAttachment(a)" />
            </div>
          </div>
        </div>
        <div class="fi"><label>状态<b>*</b></label><el-select v-model="claimForm.status" size="small"><el-option v-for="s in claimStatuses" :key="s.value" :label="s.label" :value="s.value" /></el-select></div>
        <div v-if="canViewAmount && ['paid','partially_paid'].includes(claimForm.status)" class="fi"><label>实际赔付金额</label><el-input v-model="claimForm.paid_amount" size="small" /></div>
        <div v-if="['paid','partially_paid'].includes(claimForm.status)" class="fi"><label>赔付日期</label><el-date-picker v-model="claimForm.paid_on" size="small" type="date" value-format="yyyy-MM-dd" style="width:100%" /></div>
      </div>
      <div class="as-tip warn">本期索赔只做登记和跟进，不生成财务应收或收款单。</div>
      <div slot="footer"><el-button size="small" @click="claimDialog = false">取消</el-button><el-button size="small" type="success" :loading="saving" @click="saveClaim">保存</el-button></div>
    </el-dialog>

    <el-dialog :visible.sync="closeDialog" :title="`关闭售后单 · ${row.case_no}`" width="780px" top="8vh" :close-on-click-modal="false" append-to-body>
      <div v-if="openActions" class="as-tip danger">还有 {{ openActions }} 个处理未完成，请先完成或取消。</div>
      <div v-if="closeErrors.length" class="as-errors"><div v-for="(e, i) in closeErrors" :key="i" class="as-errors-line">{{ e }}</div></div>
      <div class="as-grid" style="margin-top:10px">
        <div class="fi"><label>处理结果<b>*</b></label><el-input v-model="closeForm.result" size="small" placeholder="如：已换新机 / 已退款" /></div>
        <div class="fi"><label>客户确认</label><el-select v-model="closeForm.customer_confirmed" size="small"><el-option label="客户已确认" :value="1" /><el-option label="未确认" :value="0" /></el-select></div>
        <div class="fi"><label>责任方<b>*</b></label><el-select v-model="closeForm.responsibility" size="small"><el-option label="工厂质量" value="factory_quality" /><el-option label="物流公司" value="carrier" /><el-option label="客户原因" value="customer" /><el-option label="错发漏发" value="wrong_shipment" /><el-option label="无责任" value="none" /></el-select></div>
        <div class="fi full"><label>关闭说明<b>*</b></label><el-input v-model="closeForm.close_remark" type="textarea" :rows="3" /></div>
        <div class="fi full"><small class="muted">索赔可以在售后单关闭后继续跟进，不影响关闭。</small></div>
      </div>
      <div slot="footer"><el-button size="small" @click="closeDialog = false">取消</el-button><el-button size="small" type="success" :loading="saving" :disabled="!!openActions" @click="saveClose">确认关闭</el-button></div>
    </el-dialog>

    <el-dialog :visible.sync="cancelDialog" :title="`取消售后单 · ${row.case_no}`" width="480px" top="12vh" :close-on-click-modal="false" append-to-body>
      <div v-if="cancelErrors.length" class="as-errors"><div v-for="(e, i) in cancelErrors" :key="i" class="as-errors-line">{{ e }}</div></div>
      <div class="fi full"><label>取消原因<b>*</b></label><el-input v-model="cancelForm.cancel_reason" type="textarea" :rows="2" /></div>
      <div slot="footer"><el-button size="small" @click="cancelDialog = false">返回</el-button><el-button size="small" type="danger" :loading="saving" @click="saveCancel">确认取消</el-button></div>
    </el-dialog>
  </section>
</template>

<script>
import AftersalesCaseForm from './AftersalesCaseForm.vue'
import AftersalesAttachmentMedia from './AftersalesAttachmentMedia.vue'
import PagedRecordSelect from '@/components/common/PagedRecordSelect.vue'
import { getAftersalesCase, acceptAftersalesCase, cancelAftersalesCase, closeAftersalesCase, addAftersalesAction, cancelAftersalesAction, createAftersalesClaim, updateAftersalesClaim, uploadAftersalesAttachment, deleteAftersalesAttachment, getReshipStockOptions, shipReship } from '@/api/erp/aftersales'
import { erpDateTime } from '@/utils/dateTime'

const BLANK_ACTION = () => ({ action_type: 'refund', return_method: 'customer_ship', freight_payer: 'company', freight_amount: null, planned_disposition: 'defective', reship_timing: 'return_first', ship_first_reason: '', compensation_amount: null, compensation_method: 'wechat', compensated_at: '', compensation_payee: '', record_content: '', remark: '', items: [] })
const ISSUE_TYPES = { quality: '质量故障', transport_damage: '运输破损', wrong_shipment: '错发漏发', installation: '安装问题', consultation: '使用咨询', other: '其他' }

export default {
  name: 'AftersalesDetail',
  components: { AftersalesCaseForm, AftersalesAttachmentMedia, PagedRecordSelect },
  data() {
    return {
      row: {}, loading: false, saving: false,
      actionDialog: false, actionForm: BLANK_ACTION(), actionItems: [], actionErrors: [], reshipItems: [],
      shipDialog: false, ship: {}, shipForm: {}, shipRows: [], shipErrors: [], shipDetail: null,
      balanceColumns: [{ prop: 'warehouse_name', label: '仓库' }, { prop: 'location_name', label: '库位' }, { prop: 'batch_no', label: '批次' }, { prop: 'available', label: '可用数量' }],
      serialColumns: [{ prop: 'serial_no', label: '序列号' }, { prop: 'batch_no', label: '批次' }],
      claimDialog: false, claimForm: {}, claimErrors: [], carrierRows: [], supplierRows: [],
      claimAttachments: [], claimUploading: false, claimDraftToken: '',
      closeDialog: false, closeForm: {}, closeErrors: [],
      cancelDialog: false, cancelForm: {}, cancelErrors: [],
      claimStatuses: [{ value: 'submitted', label: '已提出' }, { value: 'paid', label: '已赔付' }, { value: 'partially_paid', label: '部分赔付' }, { value: 'rejected', label: '已拒赔' }, { value: 'abandoned', label: '放弃' }]
    }
  },
  computed: {
    canViewAmount() { return this.$can('sales_order.amount.view') || this.$can('finance.view') },
    canEdit() { return this.$can('aftersales.create') && ['pending', 'processing'].includes(this.row.status) },
    canAct() { return this.$can('aftersales.handle') && ['processing', 'ready_to_close', 'pending'].includes(this.row.status) },
    canClose() { return this.$can('aftersales.close') && ['processing', 'ready_to_close'].includes(this.row.status) },
    canCancel() { return this.$can('aftersales.handle') && ['pending', 'processing', 'ready_to_close'].includes(this.row.status) },
    actionTypes() { return [...new Set((this.row.actions || []).filter(a => a.status !== 'cancelled').map(a => a.action_type))] },
    activeActions() { return (this.row.actions || []).filter(a => a.status !== 'cancelled' || a.cancel_reason) },
    openActions() { return (this.row.actions || []).filter(a => a.status === 'processing').length },
    customerText() { const s = this.row.customer_snapshot || {}; return [s.name || this.row.order?.customer_name, s.phone].filter(Boolean).join(' · ') },
    freightText() {
      const a = (this.row.actions || []).find(a => a.freight_amount != null)
      return a ? `¥ ${(+a.freight_amount).toFixed(2)}${{ company: ' · 公司承担', customer: ' · 客户承担', carrier: ' · 承运商承担' }[a.freight_payer] || ''}` : '—'
    },
    shipAction() { return (this.row.actions || []).find(a => a.id === this.ship.action_id) }
  },
  created() {
    this.load()
    const action = this.$route.query.action
    if (action) {
      this.$router.replace({ query: {} })
      this.$nextTick(() => { this.actionForm.action_type = action; this.actionDialog = true })
    }
  },
  watch: {
    actionDialog(v) {
      if (v) { this.actionErrors = []; this.actionForm = { ...BLANK_ACTION() }; this.buildActionItems(); this.reshipItems = [] }
    }
  },
  methods: {
    async load() {
      this.loading = true
      try { const { data } = await getAftersalesCase(this.$route.params.id); this.row = data.data || {} }
      finally { this.loading = false }
    },
    buildActionItems() {
      const used = {}
      for (const a of (this.row.actions || [])) {
        if (a.status === 'cancelled') continue
        for (const ai of a.items || []) used[ai.case_item_id] = (used[ai.case_item_id] || 0) + (+ai.return_qty || 0)
      }
      this.actionItems = (this.row.items || []).map(i => {
        const left = Math.max(0, +i.qty - (used[i.id] || 0))
        return { ...i, left, return_qty: i.inventory_serial_id ? 0 : left, checked: false, case_item_id: i.id }
      }).filter(r => r.left > 0)
    },
    actionPayloadItems() {
      return this.actionItems.filter(r => (r.inventory_serial_id ? r.checked : +r.return_qty > 0))
        .map(r => ({ case_item_id: r.case_item_id, return_qty: r.inventory_serial_id ? (r.checked ? r.left : 0) : +r.return_qty }))
    },
    async saveAction() {
      this.actionErrors = []
      const type = this.actionForm.action_type
      const items = ['refund', 'exchange'].includes(type) ? this.actionPayloadItems() : []
      if (['refund', 'exchange'].includes(type) && !items.length) { this.actionErrors = ['请至少选择一件退回商品']; return }
      const reshipPayload = type === 'reship' ? this.reshipItems.filter(r => r.item_id && +r.qty > 0).map(r => ({ item_id: r.item_id, qty: +r.qty, case_item_id: r.case_item_id || null })) : []
      if (type === 'reship' && !reshipPayload.length) { this.actionErrors = ['补发至少选择一条物料并填写数量']; return }
      if (['record', 'compensation'].includes(type) && !this.actionForm.record_content.trim()) { this.actionErrors = [type === 'compensation' ? '赔付记录必须填写说明' : '仅记录必须填写处理说明']; return }
      if (type === 'compensation') {
        if (!this.actionForm.compensation_amount || +this.actionForm.compensation_amount <= 0) { this.actionErrors = ['赔付金额必须大于 0']; return }
        if (!this.actionForm.compensated_at) { this.actionErrors = ['付款时间必填']; return }
      }
      this.saving = true
      try {
        await addAftersalesAction(this.row.id, { ...this.actionForm, items, reship_items: reshipPayload })
        this.$message.success('处理已保存'); this.actionDialog = false; this.load()
      } catch (e) { this.actionErrors = this.errorsOf(e) } finally { this.saving = false }
    },
    compensationMethodLabel: v => ({ cash: '现金', wechat: '微信', alipay: '支付宝', bank_transfer: '银行转账', other: '其他' })[v] || v || '—',
    reshipLabel: v => ({ pending: '待出库', shipped: '已出库', cancelled: '已取消' })[v] || v || '—',
    reshipTag: v => ({ pending: 'warning', shipped: 'success', cancelled: 'info' })[v] || 'info',
    reshipContent(r) {
      const items = (r.items || []).map(i => `${i.item_name}${i.serial_no ? ' ' + i.serial_no : ''}×${+i.qty}`).join('、')
      return items + (r.carrier_name || r.tracking_no ? ` · ${[r.carrier_name, r.tracking_no].filter(Boolean).join(' ')}` : '')
    },
    reshipBlockReason(a, r) {
      if (!a || a.action_type !== 'exchange' || a.reship_timing === 'ship_first') return ''
      const items = a.sales_return?.items || []
      if (!a.sales_return || items.some(i => +i.received_base_qty < +i.requested_base_qty)) return '先退后发：退货单尚未收齐，收齐后才能出库'
      return ''
    },
    openShip(r) {
      this.ship = r; this.shipErrors = []; this.shipDialog = true
      this.shipForm = { carrier_id: r.carrier_id, tracking_no: r.tracking_no || '', shipped_at: r.shipped_at || '',
        receiver_name: r.receiver_name || '', receiver_phone: r.receiver_phone || '', receiver_address: r.receiver_address || '',
        package_count: r.package_count, package_method: r.package_method, remark: '' }
      if (r.carrier_id && r.carrier_name) this.carrierRows = [{ id: r.carrier_id, name: r.carrier_name }]
      this.shipRows = (r.items || []).map(i => ({
        reship_item_id: i.id, item_id: i.item_id, item_name: i.item_name, sku_name: i.sku_name, qty: +i.qty,
        balances: [], inventory_balance_id: null, serialRows: [], serial_ids: [], serial_mode: 'none', short: false,
        balanceRequest: params => this.stockOptions(r.id, 'balances', params),
        serialRequest: params => this.stockOptions(r.id, 'serials', params)
      }))
    },
    async stockOptions(reshipId, type, params) {
      const { data } = await getReshipStockOptions(reshipId, { ...params, ...(type === 'serials' ? { serial_page: params.page } : {}) })
      return { data: data.data[type] }
    },
    balanceLabel(b) { return `${b.warehouse_name} / ${b.location_name} / ${b.batch_no || '无批次'}（可用 ${+b.available}）` },
    serialLabel(s) { return s.serial_no },
    pickBalance(r, rows) {
      r.serialRows = []; r.serial_ids = []
      const b = rows[0]
      r.serial_mode = b?.serial_mode || 'none'
      r.short = !!b && +b.available < r.qty
    },
    async saveShip() {
      this.shipErrors = []
      const f = this.shipForm
      const missing = this.shipRows.find(r => !r.inventory_balance_id || r.short || (r.serial_mode === 'required' && r.serial_ids.length !== +r.qty) || r.serial_ids.length > +r.qty)
      if (missing) { this.shipErrors = [!missing.inventory_balance_id ? `${missing.item_name}：必须选择库存来源` : missing.short ? `${missing.item_name}：所选库存不足` : `${missing.item_name}：请按补发数量选择 ${+missing.qty} 台序列号`]; return }
      if (!f.carrier_id) { this.shipErrors = ['快递公司必填']; return }
      if (!f.tracking_no) { this.shipErrors = ['运单号必填']; return }
      this.saving = true
      try {
        await shipReship(this.ship.id, { ...f, carrier_name: this.carrierRows[0]?.name || '', items: this.shipRows.map(r => ({ reship_item_id: r.reship_item_id, inventory_balance_id: r.inventory_balance_id, serial_ids: r.serial_ids })) })
        this.$message.success('补发出库成功'); this.shipDialog = false; this.load()
      } catch (e) { this.shipErrors = this.errorsOf(e) } finally { this.saving = false }
    },
    async cancelAction(a) {
      try {
        const { value } = await this.$prompt('请输入取消原因', `取消处理 · ${this.actionLabel(a.action_type)}`, { inputValidator: v => !!v && v.trim() !== '', inputErrorMessage: '取消原因必填' })
        await cancelAftersalesAction(this.row.id, a.id, { cancel_reason: value })
        this.$message.success('处理已取消'); this.load()
      } catch (e) { if (e !== 'cancel') this.$message.error(e.response?.data?.message || '取消失败') }
    },
    async accept() {
      try { await acceptAftersalesCase(this.row.id); this.$message.success('已受理'); this.load() }
      catch (e) { this.$message.error(e.response?.data?.message || '受理失败') }
    },
    openClaim(c) {
      this.claimErrors = []; this.carrierRows = []; this.supplierRows = []
      this.claimAttachments = c ? (this.row.attachments || []).filter(a => a.claim_id === c.id) : []
      this.claimDraftToken = c ? '' : `as-claim-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
      this.claimForm = c
        ? { id: c.id, target_type: c.target_type, carrier_id: c.carrier_id, supplier_id: c.supplier_id, target_name: c.target_name, related_doc_no: c.related_doc_no, claim_amount: c.claim_amount, claimed_on: c.claimed_on, reason: c.reason, status: c.status, paid_amount: c.paid_amount, paid_on: c.paid_on }
        : { id: null, target_type: 'carrier', carrier_id: null, supplier_id: null, target_name: '', related_doc_no: '', claim_amount: null, claimed_on: '', reason: '', status: 'submitted', paid_amount: null, paid_on: null }
      // 已选承运商/供应商先放进已选行：承运商列表接口不支持按 id 回查，避免回显报“已选记录不可用”。
      if (c && c.carrier_id) this.carrierRows = [{ id: c.carrier_id, name: c.target_name }]
      if (c && c.supplier_id) this.supplierRows = [{ id: c.supplier_id, supplier_name: c.target_name }]
      this.claimDialog = true
    },
    async saveClaim() {
      this.claimErrors = []
      const f = this.claimForm
      const payload = { ...f }
      if (f.target_type === 'carrier') payload.target_name = this.carrierRows[0]?.name
      if (f.target_type === 'supplier') payload.target_name = this.supplierRows[0]?.supplier_name
      if (!f.id) payload.attachment_draft_token = this.claimDraftToken
      this.saving = true
      try {
        if (f.id) await updateAftersalesClaim(this.row.id, f.id, payload)
        else await createAftersalesClaim(this.row.id, payload)
        this.$message.success('索赔已保存'); this.claimDialog = false; this.load()
      } catch (e) { this.claimErrors = this.errorsOf(e) } finally { this.saving = false }
    },
    openClose() {
      this.closeErrors = []
      this.closeForm = { result: '', responsibility: '', close_remark: '', customer_confirmed: 1 }
      this.closeDialog = true
    },
    async saveClose() {
      this.closeErrors = []; this.saving = true
      try { await closeAftersalesCase(this.row.id, this.closeForm); this.$message.success('售后单已关闭'); this.closeDialog = false; this.load() }
      catch (e) { this.closeErrors = this.errorsOf(e) } finally { this.saving = false }
    },
    async saveCancel() {
      this.cancelErrors = []
      if (!this.cancelForm.cancel_reason?.trim()) { this.cancelErrors = ['取消原因必填']; return }
      this.saving = true
      try { await cancelAftersalesCase(this.row.id, this.cancelForm); this.$message.success('售后单已取消'); this.cancelDialog = false; this.load() }
      catch (e) { this.cancelErrors = this.errorsOf(e) } finally { this.saving = false }
    },
    async uploadClaimFile({ file }) {
      this.claimUploading = true
      try {
        const { data } = await uploadAftersalesAttachment(file, {
          case_id: this.row.id,
          claim_id: this.claimForm.id || null,
          draft_token: this.claimForm.id ? null : this.claimDraftToken
        })
        this.claimAttachments.push(data.data)
      } catch (e) { this.$message.error(e.response?.data?.message || '上传失败') } finally { this.claimUploading = false }
    },
    async removeClaimAttachment(a) {
      try { await deleteAftersalesAttachment(a.id); this.claimAttachments = this.claimAttachments.filter(x => x.id !== a.id) }
      catch (e) { this.$message.error(e.response?.data?.message || '删除失败') }
    },
    errorsOf(e) {
      const errors = e.response?.data?.errors
      if (errors) return Object.values(errors).flat()
      return [e.response?.data?.message || '操作失败']
    },
    goOrder() { if (this.row.sales_order_id) this.$router.push(`/sales/orders/${this.row.sales_order_id}/detail`) },
    fmt: v => v ? erpDateTime(v) : '—',
    fmtShort: v => v ? String(v).slice(5, 16) : '',
    fmtAmt: v => v == null ? '—' : (+v).toLocaleString('zh-CN', { minimumFractionDigits: 2 }),
    issueLabel: v => ISSUE_TYPES[v] || v || '—',
    responsibilityLabel: v => ({ factory_quality: '工厂质量', carrier: '物流公司', customer: '客户原因', wrong_shipment: '错发漏发', none: '无责任' })[v] || v || '—',
    warrantyLabel: v => ({ in_warranty: '保内', out_of_warranty: '保外', pending_inspection: '待检测' })[v] || '—',
    statusLabel: v => ({ pending: '待受理', processing: '处理中', ready_to_close: '待关闭', closed: '已关闭', cancelled: '已取消' })[v] || v,
    statusTag: v => ({ pending: 'info', processing: 'warning', ready_to_close: '', closed: 'info', cancelled: 'info' })[v] || 'info',
    actionLabel: v => ({ refund: '退货退款', exchange: '换货', repair: '返修', reship: '补发', compensation: '赔付记录', record: '仅记录' })[v] || v,
    actionStatus: v => ({ processing: '进行中', completed: '已完成', cancelled: '已取消' })[v] || v,
    actionTitle(a) {
      if (a.action_type === 'record') return a.record_content || '仅记录'
      const n = (a.items || []).reduce((s, i) => s + (+i.return_qty || 0), 0)
      return `退回 ${+n} 件`
    },
    dispositionLabel: v => ({ defective: '转残次品', restock: '转入库（可售）', scrap: '报废' })[v] || v,
    returnLabel: v => ({ draft: '草稿', pending_receipt: '待收货', partial_received: '部分收货', received: '已收货', completed: '已完成', closed: '已关闭', cancelled: '已取消' })[v] || v,
    returnTag: v => ({ completed: 'success', closed: 'info', cancelled: 'info', pending_receipt: 'warning', partial_received: 'warning', received: 'warning', draft: 'info' })[v] || 'info',
    claimLabel: v => ({ submitted: '已提出', paid: '已赔付', partially_paid: '部分赔付', rejected: '已拒赔', abandoned: '放弃' })[v] || v,
    targetTypeLabel: v => ({ carrier: '承运商', supplier: '供应商', other: '其他' })[v] || v
  }
}
</script>

<style scoped>
.as-page { padding: 16px; }
.page-head { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 12px; }
.breadcrumb { color: #8a94a6; font-size: 12px; margin: 0 0 4px; }
h1 { font-size: 20px; margin: 0; color: #1f2a37; display: flex; align-items: center; gap: 8px; }
.as-two { display: grid; grid-template-columns: 1.6fr 1fr; gap: 12px; }
.card { background: #fff; border: 1px solid #e8ecf1; border-radius: 8px; padding: 12px; margin-bottom: 12px; }
.card h3 { font-size: 14px; margin: 0 0 10px; display: flex; justify-content: space-between; align-items: baseline; }
.card h3 .sm { font-weight: 400; }
.kv { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px 16px; }
.kv.one { grid-template-columns: 1fr; }
.kv > div { display: flex; flex-direction: column; gap: 2px; }
.kv > div.full { grid-column: 1 / -1; }
.kv span { font-size: 12px; color: #8a94a6; }
.kv b { font-size: 13px; color: #1f2a37; font-weight: 500; }
.mono { font-family: Consolas, monospace; }
.muted { color: #9aa3b2; }
.sm { font-size: 12px; }
.act { border-top: 1px solid #edf0f4; padding-top: 10px; margin-top: 10px; }
.act-h { display: flex; align-items: center; gap: 8px; }
.act-h .r { margin-left: auto; }
.act-status { margin: 6px 0; display: flex; gap: 8px; align-items: center; }
.as-tag { color: #7c3aed; border-color: #ddd6fe; background: #f5f3ff; }
.ret-table { margin-top: 6px; }
.rec-content { margin-top: 6px; color: #5b6472; font-size: 13px; }
.timeline > div { font-size: 12px; color: #5b6472; padding: 4px 0; border-bottom: 1px dashed #edf0f4; }
.timeline span { color: #9aa3b2; font-family: Consolas, monospace; margin-right: 8px; }
.chips { display: flex; gap: 8px; }
.chip { border: 1px solid #dcdfe6; border-radius: 6px; padding: 8px 12px; cursor: pointer; display: flex; flex-direction: column; gap: 2px; min-width: 130px; }
.chip b { font-size: 13px; }
.chip span { font-size: 11px; color: #8a94a6; }
.chip.on { border-color: #008b4b; background: rgba(0, 139, 75, .06); }
.fi { display: flex; flex-direction: column; gap: 4px; }
.fi label { font-size: 12px; color: #5b6472; }
.fi label b { color: #ef4444; font-weight: 400; }
.fi small { color: #9aa3b2; font-size: 11px; }
.fi.full { grid-column: 1 / -1; }
.as-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px 16px; }
.as-sec-h { font-weight: 600; font-size: 14px; margin-bottom: 8px; }
.as-errors { background: #fef0f0; border: 1px solid #fbc4c4; border-radius: 6px; padding: 8px 12px; margin-bottom: 10px; font-size: 12px; color: #cf3f3f; }
.as-errors-line { padding: 2px 0; }
.as-tip { border-radius: 6px; padding: 8px 12px; font-size: 12px; margin-top: 10px; }
.as-tip.warn { background: #fdf6ec; border: 1px solid #f5dab1; color: #b88230; }
.as-tip.danger { background: #fef0f0; border: 1px solid #fbc4c4; color: #cf3f3f; }
.media-row { display: flex; flex-wrap: wrap; gap: 8px; }
.media-item { position: relative; width: 72px; }
.media-thumb { width: 72px; height: 54px; object-fit: cover; border-radius: 4px; border: 1px solid #e4e7ed; cursor: pointer; display: flex; align-items: center; justify-content: center; background: #f5f7fa; color: #9aa3b2; font-size: 11px; flex-direction: column; gap: 2px; }
.video-thumb i { font-size: 18px; }
.media-name { font-size: 11px; color: #8a94a6; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.media-del { position: absolute; top: -6px; right: -6px; background: #ef4444; color: #fff; border-radius: 50%; width: 16px; height: 16px; font-size: 10px; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.inp { padding: 6px 10px; background: #f5f7fa; border: 1px solid #e4e7ed; border-radius: 4px; font-size: 13px; color: #303133; min-height: 30px; box-sizing: border-box; }
.summary { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px 16px; font-size: 13px; }
.summary > div span { color: #8a94a6; margin-right: 6px; }
.row-short { background: #fef0f0; }
</style>
