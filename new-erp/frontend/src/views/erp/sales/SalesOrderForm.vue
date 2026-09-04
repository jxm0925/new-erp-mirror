<!--
Design reference: D:\codex-introduce\new_erp\docs\product-design\phase6-order\phase6-order-add-edit.png
Design status: Approved
Do not change layout without approval.
-->
<template>
  <section class="sales-form-page">
    <product-sku-picker ref="productSkuPicker" @select="applyPicker" />
    <customer-picker ref="customerPicker" @select="applyCustomer" />
    <order-edit-impact-dialog
      :visible.sync="impactDialogVisible"
      :changes="impactPreview.changes"
      :approvals="impactPreview.approvals"
      :approval-reasons="impactPreview.approvalReasons"
      :summary="impactPreview.summary"
      :level="impactPreview.level"
      :candidate-version="impactPreview.candidateVersion"
      :effective-version="impactPreview.effectiveVersion"
      :reason.sync="impactReason"
      :submitting="impactSubmitting"
      @back="impactDialogVisible = false"
      @submit="submitImpactPreview"
    />

    <div class="form-toolbar">
      <div class="page-title">
        <button type="button" class="back-btn" @click="$router.push('/sales/orders')"><i class="el-icon-back" /></button>
        <span>销售订单 / {{ isEdit ? '编辑订单' : '新增订单' }}</span>
      </div>
      <div class="toolbar-actions">
        <el-button size="small" @click="$router.push('/sales/orders')">返回列表</el-button>
        <el-button v-if="isEdit ? ($can('sales_order.edit_draft') || $can('sales_order.change')) : $can('sales_order.create')" size="small" @click="save(false)">{{ isConfirmedEdit ? '保存修改' : '保存草稿' }}</el-button>
        <el-button v-if="!isConfirmedEdit && $can('sales_order.submit_confirmation')" size="small" type="success" @click="save(true)">提交确认</el-button>
      </div>
    </div>

    <el-alert class="top-tip" type="info" :closable="false" show-icon title="新增/编辑页只提供履约建议，不生成正式销售订单工单、不锁库存、不锁BOM；提交确认后进入订单生产确认，再执行库存履约确认、BOM/工艺路线/图纸锁定。" />

    <div class="form-layout">
      <main class="form-main">
        <div class="top-cards">
          <section ref="基本信息" class="panel order-basic-card">
            <h3>订单基本信息</h3>
            <div class="info-grid order-basic-grid">
              <label>订单号</label>
              <el-input :value="form.sales_order_no || '保存后系统生成'" size="small" disabled />
              <span></span>
              <label>原始单号</label>
              <el-input v-model="form.origin_order_no" size="small" placeholder="平台订单号（非必填）" />
              <el-button size="small" plain type="success">检查重复</el-button>
              <label class="required">下单时间</label>
              <el-date-picker v-model="form.order_time" type="datetime" size="small" value-format="yyyy-MM-dd HH:mm:ss" placeholder="选择下单时间" />
              <span></span>
              <label class="required">销售人员</label>
              <el-select v-model="form.sales_user_legacy_id" size="small" filterable placeholder="请选择销售" @change="handleSalesUserChange">
                <el-option v-for="item in shareUserOptions" :key="item.id" :label="item.nickname" :value="String(item.id)" />
              </el-select>
              <span></span>
              <label class="required">订单来源</label>
              <el-select v-model="form.order_source" size="small">
                <el-option label="销售订单" value="manual" />
                <el-option label="历史迁移" value="legacy_sync" />
                <el-option label="线索转单" value="crm_clue" />
              </el-select>
              <span></span>
              <label class="required">成交平台</label>
              <div class="inline-selects">
                <el-select v-model="form.platform" size="small" placeholder="主平台" clearable @change="handlePlatformChange">
                  <el-option v-for="item in platformOptions" :key="item.id" :label="item.name" :value="String(item.id)" />
                </el-select>
                <el-select v-if="platformChildrenOptions.length" v-model="form.platform2" size="small" placeholder="子平台" clearable>
                  <el-option v-for="item in platformChildrenOptions" :key="item.id" :label="item.name" :value="String(item.id)" />
                </el-select>
              </div>
              <span></span>
              <label class="required">付款方式</label>
              <el-select v-model="form.pay_type" size="small" placeholder="请选择付款方式" clearable>
                <el-option v-for="item in payTypeOptions" :key="item.id" :label="item.name" :value="String(item.id)" />
              </el-select>
              <span></span>
              <label>订单备注</label>
              <el-input v-model="form.remark" type="textarea" :rows="2" maxlength="200" show-word-limit placeholder="请输入订单备注（可选）" />
              <span></span>
            </div>
          </section>

          <section ref="客户与收货" class="panel customer-card">
            <h3>客户与收货</h3>
            <div class="info-grid two">
              <label class="required">客户</label>
              <div class="customer-select-field">
                <el-input v-model="form.customer_name" size="small" placeholder="请通过右侧按钮选择客户" readonly />
                <el-button size="small" plain type="success" @click="$refs.customerPicker.open()">选择客户</el-button>
              </div>
              <label>客户名称（快照）</label>
              <el-input v-model="form.customer_snapshot.name" size="small" placeholder="选择客户后自动锁定" readonly />
              <label>联系电话</label>
              <el-input v-model="form.customer_phone" size="small" placeholder="联系方式" />
              <label>联系人</label>
              <el-input v-model="form.contact_name" size="small" placeholder="企业客户可填写联系人" />
              <label>平台买家 ID</label>
              <el-input v-model.trim="form.platform_buyer_id" size="small" placeholder="选填，用于个人客户查重" />
              <label>客户类型</label>
              <el-radio-group v-model="form.customer_kind" size="small" class="customer-kind-radios">
                <el-radio-button label="individual">个人客户</el-radio-button>
                <el-radio-button label="enterprise">企业客户</el-radio-button>
              </el-radio-group>
              <label>订单标签（只读）</label>
              <el-input size="small" value="销售订单" disabled />
              <label>收货地址</label>
              <el-input v-model="form.full_address" type="textarea" :rows="2" placeholder="省 / 市 / 区 / 详细地址" />
              <label>自动识别</label>
              <el-input v-model="customerRawText" type="textarea" :rows="2" placeholder="粘贴客户姓名、电话、收货地址，系统自动识别并回填" @input="recognizeCustomerInfo" />
            </div>
          </section>

          <section class="panel delivery-card">
            <h3>生产与交付标识 <small>订单线汇总</small></h3>
            <div class="flag-grid">
              <label>是否加急</label><el-switch v-model="form.is_urgent" />
              <label>是否延期</label><el-switch v-model="form.is_delay" />
              <label>延期发货日期</label><el-date-picker v-model="form.delay_date" size="small" value-format="yyyy-MM-dd" :disabled="!form.is_delay" />
              <label>要求交期</label><el-date-picker v-model="form.required_delivery_date" size="small" value-format="yyyy-MM-dd" />
              <label>订单是否包含定制产品</label><el-tag size="small">{{ form.is_customized ? '是' : '否' }}</el-tag>
              <label>订单行数</label><strong>{{ form.lines.length }} 行</strong>
              <label>待补资料</label><el-tag size="small" :type="missingDataCount ? 'warning' : 'success'">{{ missingDataCount }} 行</el-tag>
            </div>
            <p class="panel-note">任意订单行定制为自动汇总，仅作汇总标识，不影响单行配置。</p>
          </section>
        </div>

        <section
          ref="订单行"
          class="panel order-lines"
          :class="{ 'precheck-focus': $route.query.focus === 'lines' || String($route.query.focus || '').startsWith('lines.') }"
        >
          <div class="section-title">
            <h3>订单行编辑 <small>仅提供履约建议，不生成任何正式业务单据</small></h3>
            <div>
              <el-button size="small" icon="el-icon-plus" @click="addLine">添加行</el-button>
              <el-button size="small" icon="el-icon-document-copy" @click="copyLine">复制行</el-button>
            </div>
          </div>
          <el-alert class="line-tip" type="info" :closable="false" title="正式说明：本页不锁库存，不能建生产工单，不锁BOM，不生成发货单。" />
          <el-table :data="form.lines" border size="mini" highlight-current-row @current-change="selectLine">
            <el-table-column label="行号" width="52" align="center">
              <template slot-scope="{$index}">{{ $index + 1 }}</template>
            </el-table-column>
            <el-table-column label="产品名称" width="105">
              <template slot-scope="{row}">
                <el-button class="select-cell" size="mini" plain @click="openProductPicker(row)">{{ row.product_name || '选择Product' }}</el-button>
              </template>
            </el-table-column>
            <el-table-column label="SKU名称" width="105">
              <template slot-scope="{row}">
                <el-button class="select-cell" size="mini" plain @click="openSkuPicker(row)">{{ row.sku_name || '选择SKU' }}</el-button>
              </template>
            </el-table-column>
            <el-table-column label="物料名称" width="90">
              <template slot-scope="{row}">
                <span class="readonly-match">{{ row.item_name || '待系统匹配' }}</span>
              </template>
            </el-table-column>
            <el-table-column label="数量及销售单位" width="118">
              <template slot-scope="{row}"><div class="qty-unit-cell"><el-input v-model.number="row.order_qty" size="mini" @input="recalc" /><b>{{ salesUnitName(row) }}</b></div></template>
            </el-table-column>
            <el-table-column label="销售单价" width="80">
              <template slot-scope="{row}"><el-input v-model.number="row.unit_price" size="mini" @input="recalc" /></template>
            </el-table-column>
            <el-table-column label="金额" width="80" align="right">
              <template slot-scope="{row}">¥{{ money(lineAmount(row)) }}</template>
            </el-table-column>
            <el-table-column label="配置角标" width="80">
              <template slot-scope="{row}">
                <el-tag v-if="row.is_customized" size="mini">定制</el-tag>
                <el-tag v-if="row.electric" size="mini">{{ row.electric }}</el-tag>
                <el-tag v-if="row.need_pump === true" size="mini" type="success">需要原水泵</el-tag>
                <el-tag v-else-if="row.need_pump === false" size="mini" type="info">不需要原水泵</el-tag>
                <span v-if="!row.is_customized && !row.electric && row.need_pump === null" class="dash">—</span>
              </template>
            </el-table-column>
            <el-table-column label="履约建议" width="78">
              <template slot-scope="{row}"><el-tag size="mini" :type="lineTypeTag(row.line_type)">{{ lineTypeText(row.line_type) }}</el-tag></template>
            </el-table-column>
            <el-table-column label="BOM预检" width="82">
              <template slot-scope="{row}">
                <span v-if="row.line_type === 'service' || row.line_type === 'no_delivery'" class="dash">无需BOM</span>
                <el-tag v-else-if="row.bom_snapshot && row.bom_snapshot.name" size="mini" type="success">就绪</el-tag>
                <el-tag v-else size="mini" type="warning">待补充</el-tag>
              </template>
            </el-table-column>
            <el-table-column label="设计图纸" width="96">
              <template slot-scope="{row}">
                <el-upload class="line-file-upload" action="#" :auto-upload="false" :show-file-list="false" :on-change="file => addLineFileForRow(file, row)">
                  <el-button size="mini" plain>上传/管理 {{ files(row).length }}</el-button>
                </el-upload>
              </template>
            </el-table-column>
            <el-table-column label="操作" width="92" align="center">
              <template slot-scope="{$index,row}">
                <div class="row-actions">
                  <el-button type="text" size="mini" @click="selectedLine = row">编辑</el-button>
                  <el-button type="text" size="mini" class="danger-link" @click="removeLine($index)">删除</el-button>
                </div>
              </template>
            </el-table-column>
          </el-table>
          <div class="line-total">
            <span>合计（行数：{{ form.lines.length }}）</span>
            <b>{{ totalQty }} 件</b>
            <b>¥{{ money(totalAmount) }}</b>
          </div>
        </section>

        <div class="bottom-grid">
          <section ref="提醒与共享" class="panel small-panel reminder-card">
            <h3>提醒与共享</h3>
            <div class="reminder-form">
              <div class="reminder-row">
                <div class="switch-field">
                  <span>是否提醒</span>
                  <el-switch v-model="remind.enabled" @change="handleRemindToggle" />
                  <em>{{ remind.enabled ? '是' : '否' }}</em>
                </div>
                <div v-if="remind.enabled" class="days-field">
                  <span>提前提醒天数</span>
                  <el-input-number v-model="remind.days" size="small" :min="0" controls-position="right" />
                  <em>天</em>
                </div>
              </div>
              <div v-if="remind.enabled" class="reminder-content">
                <span>提醒内容</span>
                <el-input v-model="remind.content" type="textarea" :rows="2" maxlength="100" show-word-limit placeholder="请输入提醒内容（可选）" />
              </div>
              <div class="switch-field share-switch-line">
                <span>是否共享</span>
                <el-switch v-model="form.is_share" @change="handleShareToggle" />
                <em>{{ form.is_share ? '是' : '否' }}</em>
              </div>
              <div v-if="form.is_share" class="share-user-line">
                <span>共享人员</span>
                <button type="button" class="select-share-btn" @click="openShareDialog"><i class="el-icon-plus" /> 选择人员</button>
                <div v-if="form.share_user.length" class="share-chip-list">
                  <el-tag v-for="id in form.share_user" :key="id" size="mini" closable @close="removeShareUser(id)">{{ shareUserName(id) }}</el-tag>
                </div>
              </div>
              <p v-if="!remind.enabled && !form.is_share" class="reminder-empty-hint">开启提醒后显示提醒天数和内容；开启共享后通过“+ 选择人员”添加共享人。</p>
            </div>
          </section>
          <section ref="外贸物流" class="panel small-panel logistics-card">
            <h3>外贸物流 <small>保留旧系统快递与费用字段</small></h3>
            <div class="logistics-grid">
              <div class="field-stack"><span>是否自提</span><el-switch v-model="form.shipping_snapshot.is_self_pickup" /></div>
              <div class="field-stack"><span>客户物流备注</span><el-input v-model="form.shipping_snapshot.customer_logistics_note" size="small" placeholder="客户指定承运方式、运输注意事项" /></div>
              <div class="field-stack trade-type-field"><span>贸易类型</span><el-radio-group v-model="form.trade_type" size="small"><el-radio-button label="domestic">内贸</el-radio-button><el-radio-button label="foreign">外贸</el-radio-button></el-radio-group></div>
              <div class="field-stack"><span class="required">快递选择</span><el-select v-model="form.carrier_id" size="small" placeholder="选择发货快递" clearable><el-option v-for="item in carrierOptions" :key="item.id" :label="item.name" :value="String(item.id)" /></el-select></div>
              <div class="field-stack"><span>预估快递费</span><el-input v-model.number="form.carrier_fee" size="small" placeholder="0.00" /></div>
              <div class="field-stack"><span>快递单号</span><el-input v-model="form.logistics_snapshot.express_no" size="small" placeholder="待发货后填写" /></div>
              <template v-if="form.trade_type === 'foreign'">
                <div class="field-stack"><span>件数（PCS）</span><el-input v-model="form.logistics_snapshot.pcs" size="small" placeholder="件数" /></div>
                <div class="field-stack"><span>毛重（KG）</span><el-input v-model="form.logistics_snapshot.gw" size="small" placeholder="毛重" /></div>
                <div class="field-stack"><span>体积（CBM）</span><el-input v-model="form.logistics_snapshot.vol" size="small" placeholder="体积" /></div>
                <div class="field-stack"><span>截单时间（SI）</span><el-date-picker v-model="form.logistics_snapshot.si_date" size="small" value-format="yyyy-MM-dd" placeholder="请选择日期" /></div>
                <div class="field-stack"><span>截关时间（CY）</span><el-date-picker v-model="form.logistics_snapshot.cy_date" size="small" value-format="yyyy-MM-dd" placeholder="请选择日期" /></div>
                <div class="field-stack"><span>货好时间</span><el-date-picker v-model="form.logistics_snapshot.cargo_ready_date" size="small" value-format="yyyy-MM-dd" placeholder="Cargo Ready" /></div>
              </template>
            </div>
          </section>
          <section ref="合同附件" class="panel small-panel contract-card">
            <h3>合同附件 <small>按类型归档</small></h3>
            <div class="contract-upload-grid">
              <el-upload action="#" :auto-upload="false" :show-file-list="false" :on-change="file => addContractFile(file, '合同图片 / PDF')">
                <button type="button" class="upload-card">
                  <i class="el-icon-upload2" />
                  <span>合同图片 / PDF</span>
                  <small>支持 jpg / png / pdf</small>
                </button>
              </el-upload>
              <el-upload action="#" :auto-upload="false" :show-file-list="false" :on-change="file => addContractFile(file, '客户技术协议')">
                <button type="button" class="upload-card">
                  <i class="el-icon-upload2" />
                  <span>客户技术协议</span>
                  <small>提交确认时上传</small>
                </button>
              </el-upload>
              <el-upload action="#" :auto-upload="false" :show-file-list="false" :on-change="file => addContractFile(file, '订单附件')">
                <button type="button" class="upload-card">
                  <i class="el-icon-upload2" />
                  <span>订单附件</span>
                  <small>报价单 / 聊天记录 / 其他</small>
                </button>
              </el-upload>
            </div>
            <div class="contract-meta">
              <span>已上传 {{ contractFiles.length }} 个附件，随订单归档并参与提交校验。</span>
              <el-button size="mini" plain icon="el-icon-folder-opened" @click="contractDialogVisible=true">查看附件清单</el-button>
            </div>
          </section>
        </div>

        <div class="final-grid">
          <section class="summary-bar">
            <div class="formula-cell">
              <span>金额汇总 <small>销售单价口径</small></span>
              <em><i class="el-icon-info" /> 订单金额 = Σ（数量 × 销售单价）</em>
            </div>
            <div><span>订单金额</span><b>¥{{ money(totalAmount) }}</b></div>
            <div><span>行数</span><b>{{ form.lines.length }} 行</b></div>
            <div><span>需要生产行</span><b>{{ productionLineCount }} 行</b></div>
            <div><span>库存直接履约行</span><b>{{ stockLineCount }} 行</b></div>
            <div><span>待补资料行</span><b>{{ missingDataCount }} 行</b></div>
          </section>
          <section class="submit-check-card">
            <h3><i class="el-icon-warning-outline" /> 提交确认校验 <small>必须 / 全量校验</small></h3>
            <p :class="{ok: validationState.requiredOk}">{{ validationState.requiredOk ? '基础必填项已完成' : '基础必填项未完成：客户 / 销售 / 成交平台 / 付款方式 / 订单行Product与SKU' }}</p>
            <p :class="{ok: validationState.lineOk}">{{ validationState.lineOk ? '订单行资料满足提交要求' : '当前订单行仍有待补资料或特殊定制附件未上传' }}</p>
          </section>
        </div>
      </main>

      <aside class="line-drawer">
        <div class="drawer-head">
          <h2>行 {{ currentIndex + 1 }} 详情（{{ currentLineName }}）</h2>
          <i class="el-icon-close" />
        </div>
        <template v-if="selectedLine">
          <section>
            <h3><b>1</b> 产品与SKU</h3>
            <div class="product-card">
              <div class="product-img"><img v-if="selectedLine.product_snapshot && selectedLine.product_snapshot.image" :src="legacyMediaUrl(selectedLine.product_snapshot.image)"><i v-else class="el-icon-picture-outline" /></div>
              <dl>
                <dt>Product</dt><dd><el-button class="select-cell" size="mini" plain @click="openProductPicker(selectedLine)">{{ selectedLine.product_name || '选择Product' }}</el-button></dd>
                <dt>SKU</dt><dd><el-button class="select-cell" size="mini" plain @click="openSkuPicker(selectedLine)">{{ selectedLine.sku_name || '选择SKU' }}</el-button></dd>
                <dt>规格</dt><dd>{{ selectedLine.spec_text_snapshot || '—' }}</dd>
                <dt>匹配Item</dt><dd><el-tag size="mini" type="info">{{ selectedLine.item_name || '待系统匹配' }}</el-tag></dd>
              </dl>
            </div>
            <el-link class="product-link" type="primary">查看产品档案 <i class="el-icon-arrow-right" /></el-link>
          </section>
          <section>
            <h3><b>2</b> 数量与价格</h3>
            <div class="drawer-grid">
              <label>数量</label><div class="number-with-unit"><el-input-number v-model="selectedLine.order_qty" size="mini" :min="0.0001" :precision="4" controls-position="right" /><b>{{ salesUnitName(selectedLine) }}</b></div>
              <label>销售单价</label><el-input-number v-model="selectedLine.unit_price" size="mini" :min="0" :precision="2" controls-position="right" />
              <label>有效期</label><el-input v-model="selectedLine.configuration_snapshot.valid_days" size="mini" placeholder="60 天" />
              <label>备注</label><el-input v-model="selectedLine.remark" size="mini" />
            </div>
          </section>
          <section>
            <h3><b>3</b> 履约换算信息 <small>（只读）</small></h3>
            <div v-if="lineNeedsItem(selectedLine)" class="fulfillment-conversion-box">
              <dl>
                <dt>默认履约Item</dt><dd>{{ selectedLine.item_name || '待系统匹配' }}</dd>
                <dt>Item基本单位</dt><dd>{{ itemBaseUnitName(selectedLine) }}</dd>
                <dt>履约换算</dt><dd>1{{ salesUnitName(selectedLine) }} = {{ fulfillmentFactor(selectedLine) }}{{ itemBaseUnitName(selectedLine) }}</dd>
                <dt>Item基本需求量</dt><dd>{{ itemBaseRequiredQty(selectedLine) }}{{ itemBaseUnitName(selectedLine) }}</dd>
              </dl>
            </div>
            <el-alert v-else type="info" :closable="false" title="无需Item换算" />
          </section>
          <section>
            <h3><b>4</b> 订单行属性 <small>仅记录本次客户要求，不匹配 SKU / Item / BOM / 工艺路线</small></h3>
            <div class="drawer-grid">
              <template v-if="lineCapabilities(selectedLine).allow_customized">
                <label>普通定制</label><el-switch v-model="selectedLine.is_customized" @change="syncHeaderFlags" />
              </template>
              <template v-if="lineCapabilities(selectedLine).allow_special_customized">
                <label>特殊定制</label><el-switch v-model="selectedLine.is_special_customized" @change="syncHeaderFlags" />
                <template v-if="selectedLine.is_special_customized && lineCapabilities(selectedLine).special_custom_description_required">
                  <label class="required">配置说明</label><el-input v-model="selectedLine.configuration_snapshot.special_custom_description" size="mini" placeholder="请填写特殊定制配置说明" />
                </template>
              </template>
              <template v-if="lineSupportsElectric(selectedLine)">
                <label :class="{ required: lineElectricRequired(selectedLine) }">电压</label>
                <el-select v-model="selectedLine.electric" size="mini" clearable placeholder="未填写">
                  <el-option v-for="option in lineElectricOptions(selectedLine)" :key="option" :label="option" :value="option" />
                </el-select>
              </template>
              <template v-if="lineSupportsNeedPump(selectedLine)">
                <label :class="{ required: lineNeedPumpRequired(selectedLine) }">原水泵控制</label>
                <el-select v-model="selectedLine.need_pump" size="mini" clearable placeholder="未填写">
                  <el-option label="需要" :value="true" />
                  <el-option label="不需要" :value="false" />
                </el-select>
              </template>
              <label>行类型</label><el-tag size="mini" :type="lineTypeTag(selectedLine.line_type)">{{ lineTypeText(selectedLine.line_type) }}</el-tag>
              <template v-if="lineCapabilities(selectedLine).delivery_inspection_required">
                <label>交付前检验</label><el-tag size="mini" type="warning">需要</el-tag>
              </template>
            </div>
          </section>
          <section>
            <h3><b>5</b> 设计图纸与技术资料</h3>
            <div class="upload-row">
              <el-select v-model="fileCategory" size="mini">
                <el-option label="设计图纸" value="设计图纸" />
                <el-option label="客户图纸" value="客户图纸" />
                <el-option label="技术协议" value="技术协议" />
                <el-option label="配置说明" value="配置说明" />
                <el-option label="其他技术附件" value="其他技术附件" />
              </el-select>
              <el-upload action="#" :auto-upload="false" :show-file-list="false" :on-change="addLineFile">
                <el-button size="mini" icon="el-icon-upload2">上传文件</el-button>
              </el-upload>
            </div>
            <div class="file-table">
              <strong>文件名称</strong><strong>版本</strong><strong>说明</strong><strong>操作</strong>
              <template v-if="files(selectedLine).length">
                <template v-for="(file,index) in files(selectedLine)">
                  <span :key="file.uid + '-name'">{{ file.file_name }} <em v-if="file.is_main">主图纸</em></span>
                  <span :key="file.uid + '-version'">V{{ index + 1 }}.0</span>
                  <span :key="file.uid + '-type'">{{ file.file_type }}</span>
                  <span :key="file.uid + '-actions'" class="file-actions">
                    <a v-if="file.can_preview === true" @click.stop="previewAttachment(file)">预览</a>
                    <a v-if="file.can_download !== false" @click.stop="downloadAttachment(file)">下载</a>
                    <a @click.stop="setMainFile(index)">设为主图</a>
                    <a v-if="file.can_delete === true" class="danger-link" @click.stop="removeLineFile(index)">删除</a>
                  </span>
                </template>
              </template>
              <template v-else>
                <span class="empty-file-row">尚未上传订单行图纸或技术附件</span><span>-</span><span>提交确认前按定制口径校验</span><span><a>待补</a></span>
              </template>
            </div>
            <div class="change-tip"><i class="el-icon-warning" /> 更换后的产品不需要电压配置，原配置将被清除。</div>
          </section>
          <section>
            <h3><b>6</b> 履约建议</h3>
            <div class="fulfill-box">
              <p>建议履约方式：<el-tag size="mini">{{ lineTypeText(selectedLine.line_type) }}</el-tag></p>
              <p>Item匹配：{{ selectedLine.item_name || '待系统匹配' }}，订单新增页只显示建议，不允许销售手工指定。</p>
              <p>库存履约数量：提交确认后由库存模块按 Item / 仓库 / 库位 / 批次 / 检验 / 冻结 / 占用状态计算。</p>
              <p>生产履约数量：仅不足库存的制造数量进入后续工单契约。</p>
            </div>
          </section>
        </template>
      </aside>
    </div>

    <el-dialog title="选择共享人" :visible.sync="shareDialogVisible" width="520px" append-to-body>
      <div class="share-dialog-body">
        <el-input v-model="shareKeyword" size="small" prefix-icon="el-icon-search" placeholder="搜索管理员 / 销售人员" clearable />
        <el-checkbox-group v-model="form.share_user" class="share-user-list">
          <el-checkbox v-for="item in filteredShareUserOptions" :key="item.id" :label="String(item.id)">
            <span>{{ item.nickname }}</span>
            <small>{{ item.department_name || item.role_name || '管理员' }}</small>
          </el-checkbox>
        </el-checkbox-group>
      </div>
      <span slot="footer">
        <el-button size="small" @click="shareDialogVisible=false">取消</el-button>
        <el-button size="small" type="success" @click="shareDialogVisible=false">确定</el-button>
      </span>
    </el-dialog>

    <el-dialog title="合同附件清单" :visible.sync="contractDialogVisible" width="640px" append-to-body>
      <el-table :data="contractFiles" border size="mini" empty-text="暂无合同附件">
        <el-table-column label="序号" width="44" align="center">
          <template slot-scope="{$index}">{{ $index + 1 }}</template>
        </el-table-column>
        <el-table-column prop="file_type" label="附件类型" width="100" show-overflow-tooltip />
        <el-table-column prop="file_name" label="文件名称" min-width="176" show-overflow-tooltip />
        <el-table-column label="上传时间" width="120">
          <template slot-scope="{row}">{{ formatFileTime(row.uploaded_at) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="150" align="center">
          <template slot-scope="{row,$index}">
            <el-button v-if="row.can_preview === true" type="text" size="mini" @click.stop="previewAttachment(row)">预览</el-button>
            <el-button v-if="row.can_download !== false" type="text" size="mini" @click.stop="downloadAttachment(row)">下载</el-button>
            <el-button v-if="row.can_delete === true" type="text" size="mini" class="danger-link" @click.stop="removeContractFile($index)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
      <span slot="footer">
        <el-button size="small" type="success" @click="contractDialogVisible=false">关闭</el-button>
      </span>
    </el-dialog>

    <sales-order-attachment-preview-dialog :visible.sync="previewVisible" :file="previewFile" />
  </section>
</template>

<script>
import ProductSkuPicker from '@/components/sales/ProductSkuPicker.vue'
import SalesOrderAttachmentPreviewDialog from '@/components/sales/SalesOrderAttachmentPreviewDialog.vue'
import OrderEditImpactDialog from '@/components/sales/OrderEditImpactDialog.vue'
import { legacyMediaUrl } from '@/utils/legacyMedia'
import CustomerPicker from '@/components/sales/CustomerPicker.vue'
import { getSalesOrder, saveSalesOrder, confirmSalesOrder, getSalesOrderOptions, uploadSalesOrderAttachment, deleteSalesOrderAttachment, downloadSalesOrderAttachment, previewSalesOrderEditImpact, submitSalesOrderEditImpact } from '@/api/erp/sales'
import { reserveForCreatePage, clearCreatePageReservation } from '@/utils/documentNumberReservation'

const emptyLine = () => ({
  line_uuid: `line-${Date.now()}-${Math.random().toString(16).slice(2)}`,
  product_id: null,
  sku_id: null,
  product_name: '',
  product_code_snapshot: '',
  sku_name: '',
  sku_code_snapshot: '',
  spec_text_snapshot: '',
  item_name: '待系统匹配',
  item_match_status: 'pending',
  line_type: 'physical',
  order_qty: 1,
  unit_price: 0,
  discount_rate: 1,
  tax_rate: 0,
  price_tax_mode: 'tax_inclusive',
  fulfillment_method: 'auto',
  need_pump: null,
  electric: '',
  is_customized: false,
  is_special_customized: false,
  configuration_snapshot: {},
  bom_snapshot: null,
  drawing_snapshot: { files: [] },
  technical_attachment_snapshot: { files: [] },
  inspection_snapshot: null,
  remark: ''
})

export default {
  components: { ProductSkuPicker, CustomerPicker, SalesOrderAttachmentPreviewDialog, OrderEditImpactDialog },
  data: () => ({
    selectedLine: null,
    fileCategory: '设计图纸',
    remind: { enabled: false, days: 3, content: '' },
    customerRawText: '',
    shareDialogVisible: false,
    shareKeyword: '',
    contractDialogVisible: false,
    previewVisible: false,
    previewFile: null,
    impactDialogVisible: false,
    impactReason: '',
    impactSubmitting: false,
    impactPayload: null,
    editOrderMeta: null,
    impactPreview: {
      level: 'low',
      candidateVersion: 'V1',
      effectiveVersion: 'V0',
      requiresApproval: false,
      approvals: { business: false, finance: false, fulfillment: false },
      approvalReasons: {},
      summary: { total: 0, none: 0, business: 0, finance: 0, fulfillment: 0 },
      changes: []
    },
    payTypeOptions: [],
    platformRawOptions: [],
    carrierOptions: [],
    shareUserOptions: [],
    pickerLine: null,
    deletedLineIds: [],
    numberReservation: null,
    form: {
      sales_order_no: '',
      origin_order_no: '',
      trade_type: 'domestic',
      order_source: 'manual',
      platform: '',
      platform2: '',
      platform_buyer_id: '',
      pay_type: '',
      sales_user_legacy_id: '',
      created_by_legacy_id: '',
      order_time: '',
      created_by: '',
      draft_token: `draft-${Date.now()}-${Math.random().toString(16).slice(2)}`,
      customer_name: '',
      customer_kind: 'individual',
      customer_phone: '',
      customer_snapshot: { remark: '', delivery_note: '' },
      full_address: '',
      required_delivery_date: '',
      is_urgent: false,
      is_delay: false,
      is_customized: false,
      delay_date: '',
      freight_amount: 0,
      is_share: false,
      share_user: [],
      carrier_id: '',
      carrier_fee: 0,
      shipping_snapshot: { is_self_pickup: false, customer_logistics_note: '' },
      logistics_snapshot: { express_no: '', pcs: '', gw: '', vol: '', si_date: '', cy_date: '', cargo_ready_date: '' },
      contract_attachment_snapshot: { files: [] },
      remark: '',
      lines: [emptyLine()]
    }
  }),
  computed: {
    isEdit() {
      return Boolean(this.$route.params.id)
    },
    isConfirmedEdit() {
      return this.isEdit && this.editOrderMeta && this.editOrderMeta.order_status === 'confirmed'
    },
    currentIndex() {
      const index = this.form.lines.indexOf(this.selectedLine)
      return index >= 0 ? index : 0
    },
    currentLineName() {
      return this.selectedLine ? (this.selectedLine.product_name || '请选择订单行') : '请选择订单行'
    },
    totalQty() {
      return this.form.lines.reduce((sum, line) => sum + Number(line.order_qty || 0), 0)
    },
    totalAmount() {
      return this.form.lines.reduce((sum, line) => sum + this.lineAmount(line), 0)
    },
    productionLineCount() {
      return this.form.lines.filter(line => line.line_type === 'physical').length
    },
    stockLineCount() {
      return this.form.lines.filter(line => line.line_type === 'no_delivery').length
    },
    missingDataCount() {
      return this.form.lines.filter(line => line.line_type === 'physical' && !line.bom_snapshot).length
    },
    platformOptions() {
      return this.platformRawOptions.filter(item => Number(item.pid || 0) === 0)
    },
    platformChildrenOptions() {
      if (!this.form.platform) return []
      return this.platformRawOptions.filter(item => String(item.pid || 0) === String(this.form.platform))
    },
    validationState() {
      const requiredOk = Boolean(
        this.form.customer_name &&
        this.form.sales_user_legacy_id &&
        this.form.platform &&
        this.form.pay_type &&
        this.form.lines.length &&
        this.form.lines.every(line => line.product_id && line.sku_id && Number(line.unit_price || 0) > 0)
      )
      const lineOk = !this.form.lines.some(line => this.lineAttributeMessage(line) || this.lineCustomizationMessage(line))
      return { requiredOk, lineOk }
    },
    filteredShareUserOptions() {
      const keyword = String(this.shareKeyword || '').trim().toLowerCase()
      if (!keyword) return this.shareUserOptions
      return this.shareUserOptions.filter(item => {
        return [item.nickname, item.username, item.department_name, item.role_name]
          .some(value => String(value || '').toLowerCase().includes(keyword))
      })
    },
    contractFiles() {
      return (this.form.contract_attachment_snapshot && this.form.contract_attachment_snapshot.files) || []
    }
  },
  async created() {
    await this.loadOptions()
    if (this.isEdit) await this.load()
    else await this.reserveSalesOrderNumber()
    if (!this.selectedLine) this.selectedLine = this.form.lines[0]
    if (process.env.NODE_ENV !== 'production' && this.$route.query.impact_preview === 'master') this.impactDialogVisible = true
    this.$nextTick(() => window.setTimeout(() => this.focusRequestedField(), 300))
  },
  watch: {
    '$route.params.id': {
      async handler() {
        if (this.isEdit) {
          await this.load()
        } else {
          this.form = this.emptyForm()
          this.selectedLine = this.form.lines[0]
          await this.reserveSalesOrderNumber()
        }
      }
    },
    '$route.query.focus'() {
      this.$nextTick(() => window.setTimeout(() => this.focusRequestedField(), 100))
    }
  },
  methods: {
    legacyMediaUrl,
    async reserveSalesOrderNumber() {
      try {
        this.numberReservation = await reserveForCreatePage('sales_order', '/sales/orders/create')
        this.form.sales_order_no = this.numberReservation.document_no
        this.form.reservation_token = this.numberReservation.reservation_token
        this.form.creation_session_id = this.numberReservation.creation_session_id
        this.form.draft_token = this.numberReservation.creation_session_id
      } catch (error) {
        this.$message.error(error.userMessage || '销售订单号预生成失败，请重新打开新增页面')
      }
    },
    emptyForm() {
      return {
        sales_order_no: '',
        origin_order_no: '',
        trade_type: 'domestic',
        order_source: 'manual',
        platform: '',
        platform2: '',
        platform_buyer_id: '',
        pay_type: '',
        sales_user_legacy_id: '',
        created_by_legacy_id: '',
        order_time: '',
        created_by: '',
        draft_token: `draft-${Date.now()}-${Math.random().toString(16).slice(2)}`,
        customer_id: null,
        customer_contact_id: null,
        customer_address_id: null,
        customer_name: '',
        customer_kind: 'individual',
        customer_phone: '',
        customer_snapshot: { remark: '', delivery_note: '' },
        full_address: '',
        required_delivery_date: '',
        is_urgent: false,
        is_delay: false,
        is_customized: false,
        delay_date: '',
        freight_amount: 0,
        is_share: false,
        share_user: [],
        carrier_id: '',
        carrier_fee: 0,
        shipping_snapshot: { is_self_pickup: false, customer_logistics_note: '' },
        logistics_snapshot: { express_no: '', pcs: '', gw: '', vol: '', si_date: '', cy_date: '', cargo_ready_date: '' },
        contract_attachment_snapshot: { files: [] },
        remark: '',
        deleted_line_ids: [],
        lines: [emptyLine()]
      }
    },
    async loadOptions() {
      try {
        const { data } = await getSalesOrderOptions()
        this.payTypeOptions = data.pay_types || []
        this.platformRawOptions = data.platforms || []
        this.carrierOptions = data.carriers || []
        this.shareUserOptions = data.share_users || []
      } catch (error) {
        this.$message.warning('销售订单基础选项加载失败，请检查新系统基础数据配置')
      }
    },
    async load() {
      try {
      const { data } = await getSalesOrder(this.$route.params.id)
      this.editOrderMeta = data
      this.form = {
        ...this.form,
        ...data,
        trade_type: data.trade_type || 'domestic',
        platform: data.platform ? String(data.platform) : '',
        platform2: data.platform2 ? String(data.platform2) : '',
        platform_buyer_id: data.platform_buyer_id || '',
        customer_kind: data.customer_kind || (data.customer_snapshot && data.customer_snapshot.customer_kind) || 'individual',
        pay_type: data.pay_type ? String(data.pay_type) : '',
        sales_user_legacy_id: data.sales_user_legacy_id ? String(data.sales_user_legacy_id) : '',
        created_by_legacy_id: data.created_by_legacy_id ? String(data.created_by_legacy_id) : '',
        is_share: Boolean(data.is_share),
        share_user: Array.isArray(data.share_user) ? data.share_user.map(String) : String(data.share_user || '').split(',').filter(Boolean),
        carrier_id: data.carrier_id ? String(data.carrier_id) : '',
        carrier_fee: Number(data.carrier_fee || 0),
        customer_snapshot: { remark: '', delivery_note: '', ...(data.customer_snapshot || {}) },
        shipping_snapshot: { is_self_pickup: false, customer_logistics_note: '', ...(data.shipping_snapshot || {}) },
        logistics_snapshot: { express_no: '', pcs: '', gw: '', vol: '', si_date: '', cy_date: '', cargo_ready_date: '', ...(data.logistics_snapshot || {}) },
        contract_attachment_snapshot: { files: this.mergeAttachmentFiles(this.parseContractAttachments(data.contract_attachments), (data.attachments || []).map(this.mapAttachment)) },
        lines: (data.lines || []).map(line => ({
          ...emptyLine(),
          ...line,
          order_qty: Number(line.order_qty),
          unit_price: Number(line.unit_price),
          item_name: line.item_name || '待系统匹配',
          product_code_snapshot: line.product_snapshot && line.product_snapshot.product_code,
          sku_code_snapshot: line.sku_snapshot && line.sku_snapshot.sku_code,
          spec_text_snapshot: line.sku_snapshot && line.sku_snapshot.spec_text,
          configuration_snapshot: line.configuration_snapshot || {},
          drawing_snapshot: line.drawing_snapshot || { files: [] },
          technical_attachment_snapshot: { files: this.mergeAttachmentFiles((line.technical_attachment_snapshot && line.technical_attachment_snapshot.files) || [], (line.attachments || []).map(this.mapAttachment)) }
        }))
      }
      this.selectedLine = this.form.lines[0] || null
      return true
      } catch (error) {
        const status = error && error.response && error.response.status
        this.$message.error(status === 404 ? '订单不存在或已被删除，已返回订单列表' : '订单加载失败，请稍后重试')
        this.$router.replace('/sales/orders')
        return false
      }
    },
    addLine() {
      const line = emptyLine()
      this.form.lines.push(line)
      this.selectedLine = line
    },
    copyLine() {
      const source = this.selectedLine || this.form.lines[0]
      const line = JSON.parse(JSON.stringify(source))
      delete line.id
      line.line_uuid = `line-${Date.now()}-${Math.random().toString(16).slice(2)}`
      this.form.lines.push(line)
      this.selectedLine = line
    },
    removeLine(index) {
      if (this.form.lines.length === 1) return this.$message.warning('至少保留一行订单明细')
      const removed = this.form.lines[index]
      if (removed.id) this.deletedLineIds.push(removed.id)
      this.form.lines.splice(index, 1)
      if (this.selectedLine === removed) this.selectedLine = this.form.lines[0]
      this.syncHeaderFlags()
    },
    selectLine(row) {
      this.selectedLine = row
    },
    applyCustomer(row) {
      const contact = (row.contacts || []).find(item => item.is_default) || (row.contacts || [])[0] || null
      const address = (row.addresses || []).find(item => item.is_default) || (row.addresses || [])[0] || null
      this.form.customer_id = row.id
      this.form.customer_contact_id = contact && contact.id
      this.form.customer_address_id = address && address.id
      this.form.customer_name = row.customer_name
      this.form.customer_kind = row.customer_kind || 'individual'
      this.form.platform_buyer_id = row.platform_buyer_id || ''
      this.form.customer_phone = (contact && (contact.mobile || contact.phone)) || row.contact_phone || ''
      this.form.contact_name = (contact && contact.contact_name) || row.contact_name || ''
      this.form.contact_phone = (contact && (contact.mobile || contact.phone)) || row.contact_phone || ''
      this.form.full_address = (address && address.full_address) || row.full_address || row.address || ''
      this.form.customer_snapshot = {
        id: row.id,
        legacy_customer_id: row.legacy_customer_id,
        name: row.customer_name,
        contact_name: (contact && contact.contact_name) || row.contact_name,
        contact_phone: (contact && (contact.mobile || contact.phone)) || row.contact_phone,
        full_address: (address && address.full_address) || row.full_address || row.address || ''
      }
    },
    onCustomerNameInput() {
      if (!this.form.customer_id) return
      this.form.customer_id = null
      this.form.customer_contact_id = null
      this.form.customer_address_id = null
    },
    handlePlatformChange() {
      if (!this.platformChildrenOptions.some(item => String(item.id) === String(this.form.platform2))) {
        this.form.platform2 = ''
      }
    },
    handleSalesUserChange(value) {
      const user = this.shareUserOptions.find(item => String(item.id) === String(value))
      this.form.created_by = user ? user.nickname : ''
    },
    openProductPicker(row) {
      this.selectedLine = row
      this.pickerLine = row
      this.$refs.productSkuPicker.openGlobalSku()
    },
    openSkuPicker(row) {
      this.selectedLine = row
      this.pickerLine = row
      this.$refs.productSkuPicker.openGlobalSku()
    },
    applyPicker({ mode, row }) {
      const line = this.pickerLine || this.selectedLine
      if (!line) return
      if (mode === 'product') {
        line.product_id = row.id
        line.product_name = row.product_name
        line.product_code_snapshot = row.product_code
        line.product_snapshot = {
          id: row.id,
          product_code: row.product_code,
          product_name: row.product_name,
          product_type: row.product_type,
          image: row.image,
          category_name: row.category && row.category.category_name,
          status: row.status
        }
        line.sku_id = null
        line.sku_name = ''
        line.sku_code_snapshot = ''
        line.spec_text_snapshot = ''
        line.sku_snapshot = null
        line.configuration_snapshot = {}
        line.bom_snapshot = null
        line.routing_snapshot = null
        line.item_name = '待系统匹配'
        line.item_match_status = 'pending'
        return
      }
      if (row.product) {
        line.product_id = row.product.id
        line.product_name = row.product.product_name
        line.product_code_snapshot = row.product.product_code
      }
      line.sku_id = row.id
      line.sku_name = row.sku_name
      line.sku_code_snapshot = row.sku_code
      line.spec_text_snapshot = row.spec_text
      line.unit_price = Number(row.default_price !== null && row.default_price !== undefined ? row.default_price : (row.sale_price !== null && row.sale_price !== undefined ? row.sale_price : 0))
      line.discount_rate = 1
      line.tax_rate = Number(row.default_tax_rate || 0)
      line.price_tax_mode = row.default_price_tax_mode || 'tax_inclusive'
      line.fulfillment_method = row.default_fulfillment_method || 'auto'
      line.unit_id = row.sales_unit_id || null
      line.unit_name_snapshot = row.sales_unit && row.sales_unit.unit_name
      line.unit_code_snapshot = row.sales_unit && row.sales_unit.unit_code
      line.available_stock_hint = row.available_stock
      line.line_type = row.line_type || (row.fulfillment_type === 'service' ? 'service' : (row.fulfillment_type === 'virtual' ? 'no_delivery' : 'physical'))
      line.is_customized = false
      line.is_special_customized = false
      line.sku_snapshot = {
        id: row.id,
        sku_code: row.sku_code,
        sku_name: row.sku_name,
        spec_text: row.spec_text,
        fulfillment_type: row.fulfillment_type,
        production_policy: row.production_policy,
        electric_mode: row.electric_mode,
        need_pump_mode: row.need_pump_mode,
        allow_customized: row.allow_customized,
        allow_special_customized: row.allow_special_customized,
        special_custom_drawing_required: row.special_custom_drawing_required,
        special_custom_agreement_required: row.special_custom_agreement_required,
        special_custom_description_required: row.special_custom_description_required,
        delivery_inspection_required: row.delivery_inspection_required,
        is_need_production: row.is_need_production,
        is_need_bom: row.is_need_bom,
        status: row.status,
        sales_unit_id: row.sales_unit_id,
        sales_unit_name: row.sales_unit && row.sales_unit.unit_name,
        sales_unit_symbol: row.sales_unit && row.sales_unit.unit_symbol
      }
      const defaultRelation = (row.item_relations || []).find(relation => relation.status === 'active' && relation.is_primary && relation.item && relation.item.status === 'enabled')
      if (line.line_type === 'physical' && defaultRelation) {
        line.item_name = defaultRelation.item.item_name
        line.item_match_status = 'matched'
        line.fulfillment_factor_preview = Number(defaultRelation.base_qty_per_sku_unit || 1)
        line.item_base_unit_name_preview = defaultRelation.item.unit && defaultRelation.item.unit.unit_name
      } else if (line.line_type === 'service' || line.line_type === 'no_delivery') {
        line.item_name = '无需 Item'
        line.item_match_status = 'not_required'
      }
      line.electric = ''
      line.need_pump = null
      this.syncHeaderFlags()
    },
    salesUnitName(line) {
      const snapshot = line && line.sku_snapshot
      return (snapshot && (snapshot.sales_unit_symbol || snapshot.sales_unit_name)) || line.unit_name_snapshot || '件'
    },
    lineNeedsItem(line) {
      return line && !['service', 'no_delivery', 'fee', 'auxiliary'].includes(line.line_type)
    },
    itemBaseUnitName(line) {
      return line.item_base_unit_name_preview || line.item_base_unit_name_snapshot || '-'
    },
    fulfillmentFactor(line) {
      return Number(line.fulfillment_factor_preview || line.fulfillment_factor_snapshot || 0).toLocaleString('zh-CN', { maximumFractionDigits: 6 })
    },
    itemBaseRequiredQty(line) {
      return (Number(line.order_qty || 0) * Number(line.fulfillment_factor_preview || line.fulfillment_factor_snapshot || 0)).toLocaleString('zh-CN', { maximumFractionDigits: 6 })
    },
    files(line) {
      return (line.technical_attachment_snapshot && line.technical_attachment_snapshot.files) || []
    },
    mapAttachment(item) {
      return {
        attachment_id: item.attachment_id || item.id,
        uid: item.attachment_id || item.id,
        file_name: item.file_name || item.original_name,
        file_type: item.file_type || item.attachment_type || '其他附件',
        url: item.url,
        file_hash: item.file_hash,
        uploaded_at: item.uploaded_at,
        file_size: item.file_size,
        mime_type: item.mime_type,
        uploaded_by: item.uploaded_by,
        version_no: item.version_no || 1,
        status: item.status || 'active',
        temporary: Boolean(item.temporary),
        can_preview: item.can_preview === true,
        can_download: item.can_download !== false,
        can_delete: item.can_delete === true,
        is_main: Boolean(item.is_main),
        remark: item.remark || '',
        locked: Boolean(item.locked)
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
    focusRequestedField() {
      const field = String(this.$route.query.focus || '')
      if (!field) return
      let selector = '.order-basic-card'
      if (field === 'customer') selector = '.customer-card'
      else if (field === 'default_carrier_id') selector = '.logistics-card'
      else if (field === 'lines' || field.startsWith('lines.')) selector = '.order-lines'
      const lineMatch = field.match(/^lines\.(\d+)\./)
      if (lineMatch) {
        const line = this.form.lines[Number(lineMatch[1]) - 1]
        if (line) this.selectedLine = line
      }
      const target = this.$el.querySelector(selector)
      if (target) {
        target.scrollIntoView({ behavior: 'smooth', block: 'center' })
        target.classList.add('precheck-focus')
        window.setTimeout(() => target.classList.remove('precheck-focus'), 8000)
      }
      this.$message.warning('请处理确认前检查中的首个阻塞项')
    },
    handleRemindToggle(value) {
      if (!value) {
        this.remind.content = ''
      }
    },
    handleShareToggle(value) {
      if (!value) {
        this.form.share_user = []
      }
    },
    openShareDialog() {
      this.shareDialogVisible = true
    },
    removeShareUser(id) {
      this.form.share_user = this.form.share_user.filter(item => String(item) !== String(id))
    },
    shareUserName(id) {
      const user = this.shareUserOptions.find(item => String(item.id) === String(id))
      return user ? user.nickname : id
    },
    recognizeCustomerInfo(value) {
      const raw = String(value || '').replace(/[，,]/g, ' ').replace(/\s+/g, ' ').trim()
      if (!raw) return
      const phoneMatch = raw.match(/1[3-9]\d{9}|(?:0\d{2,3}[-\s]?)?\d{7,8}/)
      const phone = phoneMatch ? phoneMatch[0].replace(/\s+/g, '') : ''
      const looksAddress = text => /(省|市|区|县|镇|乡|街道|路|街|号|村|小区|公司|园区|室|栋|单元|楼|座|巷|弄)/.test(text)
      let name = ''
      let address = ''
      if (phoneMatch) {
        const before = raw.slice(0, phoneMatch.index).trim()
        const after = raw.slice(phoneMatch.index + phoneMatch[0].length).trim()
        if (before && after) {
          if (before.length <= 8 && !looksAddress(before)) {
            name = before
            address = after
          } else if (after.length <= 8 && !looksAddress(after)) {
            name = after
            address = before
          } else {
            address = looksAddress(after) ? after : before
            name = (looksAddress(before) ? after : before).split(' ')[0]
          }
        } else {
          const remain = (before || after).trim()
          if (looksAddress(remain)) {
            const tokens = remain.split(' ').filter(Boolean)
            const shortToken = tokens.find(item => item.length >= 2 && item.length <= 5 && !looksAddress(item))
            name = shortToken || ''
            address = shortToken ? remain.replace(shortToken, '').trim() : remain
          } else {
            name = remain
          }
        }
      } else if (looksAddress(raw)) {
        address = raw
      } else {
        name = raw
      }
      if (phone) this.form.customer_phone = phone
      if (name) {
        this.onCustomerNameInput()
        this.form.customer_name = name
        if (this.form.customer_kind === 'individual') this.form.contact_name = name
        this.$set(this.form.customer_snapshot, 'name', name)
      }
      if (address) this.form.full_address = address
    },
    addLineFileForRow(file, row) {
      this.selectedLine = row
      return this.addLineFile(file)
    },
    async addLineFile(file) {
      if (!this.selectedLine) return
      if (!this.form.draft_token && !(this.isEdit && this.form.id)) {
        this.$message.warning('请先保存销售订单草稿，再上传订单行附件')
        return false
      }
      if (!this.selectedLine.line_uuid) this.$set(this.selectedLine, 'line_uuid', `line-${Date.now()}-${Math.random().toString(16).slice(2)}`)
      const form = new FormData()
      form.append('file', file.raw)
      form.append('attachment_scope', 'line')
      form.append('attachment_type', this.attachmentTypeValue(this.fileCategory))
      if (this.isEdit && this.selectedLine.id) {
        form.append('sales_order_id', this.form.id)
        form.append('sales_order_line_id', this.selectedLine.id)
      } else {
        form.append('draft_token', this.form.draft_token)
        form.append('line_uuid', this.selectedLine.line_uuid)
      }
      const { data } = await uploadSalesOrderAttachment(form)
      const uploaded = data.data
      const files = this.files(this.selectedLine)
      files.push({
        attachment_id: uploaded.id,
        uid: uploaded.id,
        file_name: uploaded.original_name,
        file_type: this.fileCategory || '设计图纸',
        file_hash: uploaded.file_hash,
        uploaded_at: uploaded.uploaded_at,
        file_size: uploaded.file_size,
        mime_type: uploaded.mime_type,
        uploaded_by: uploaded.uploaded_by,
        version_no: uploaded.version_no || 1,
        status: uploaded.status || 'active',
        temporary: Boolean(uploaded.temporary),
        can_preview: uploaded.can_preview === true,
        can_download: uploaded.can_download !== false,
        can_delete: uploaded.can_delete === true,
        is_main: files.length === 0,
        remark: '',
        locked: false
      })
      this.selectedLine.technical_attachment_snapshot = { files }
      this.selectedLine.drawing_snapshot = { files: files.filter(item => item.file_type === '设计图纸').length, main_file: files.find(item => item.is_main) || null }
      return false
    },
    async addContractFile(file, category) {
      if (!this.form.draft_token && !(this.isEdit && this.form.id)) {
        this.$message.warning('请先保存销售订单草稿，再上传合同附件')
        return false
      }
      const form = new FormData()
      form.append('file', file.raw)
      form.append('attachment_scope', 'order')
      form.append('attachment_type', this.contractTypeValue(category))
      if (this.isEdit && this.form.id) form.append('sales_order_id', this.form.id)
      else form.append('draft_token', this.form.draft_token)
      let data
      try {
        const response = await uploadSalesOrderAttachment(form)
        data = response.data
      } catch (error) {
        const responseData = error && error.response && error.response.data
        const fileError = responseData && responseData.errors && responseData.errors.file
        const message = (Array.isArray(fileError) && fileError[0]) ||
          (responseData && responseData.message) ||
          '附件上传失败，请检查文件类型、内容和大小'
        this.$message.error(message)
        return false
      }
      const uploaded = data.data
      const files = this.contractFiles
      files.push({
        attachment_id: uploaded.id,
        uid: uploaded.id,
        file_name: uploaded.original_name,
        file_type: category,
        remark: '',
        file_hash: uploaded.file_hash,
        uploaded_at: uploaded.uploaded_at,
        file_size: uploaded.file_size,
        mime_type: uploaded.mime_type,
        uploaded_by: uploaded.uploaded_by,
        version_no: uploaded.version_no || 1,
        status: uploaded.status || 'active',
        temporary: Boolean(uploaded.temporary),
        can_preview: uploaded.can_preview === true,
        can_download: uploaded.can_download !== false,
        can_delete: uploaded.can_delete === true
      })
      this.form.contract_attachment_snapshot = { files }
      this.$message.success(`${category} 已加入附件清单`)
      return false
    },
    attachmentTypeValue(label) {
      return ({ 设计图纸: 'design_drawing', 客户图纸: 'customer_drawing', 技术协议: 'technical_agreement', 配置说明: 'configuration_note' })[label] || 'other'
    },
    contractTypeValue(label) {
      return ({ '合同图片 / PDF': 'contract', 客户技术协议: 'customer_agreement', 订单附件: 'public_attachment' })[label] || 'other'
    },
    async removeContractFile(index) {
      const files = [...this.contractFiles]
      const target = files[index]
      if (target && target.attachment_id) await deleteSalesOrderAttachment(target.attachment_id)
      files.splice(index, 1)
      this.form.contract_attachment_snapshot = { files }
    },
    formatFileTime(value) {
      if (!value) return '-'
      return String(value).replace('T', ' ').slice(0, 19)
    },
    previewAttachment(file) {
      if (!file || file.can_preview !== true) return this.$message.info('该附件不支持页面内预览，请下载后查看')
      this.previewFile = file
      this.previewVisible = true
    },
    async downloadAttachment(file) {
      if (!file || !file.attachment_id || file.can_download === false) return
      try {
        const { data } = await downloadSalesOrderAttachment(file.attachment_id)
        const url = URL.createObjectURL(new Blob([data], { type: file.mime_type || 'application/octet-stream' }))
        const link = document.createElement('a')
        link.href = url
        link.download = file.file_name || '附件'
        link.style.display = 'none'
        document.body.appendChild(link)
        link.click()
        link.remove()
        window.setTimeout(() => URL.revokeObjectURL(url), 30000)
      } catch (error) {
        this.$message.error('附件下载失败，请稍后重试')
      }
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
    async removeLineFile(index) {
      if (!this.selectedLine) return
      const files = this.files(this.selectedLine)
      const target = files[index]
      if (target && target.attachment_id) await deleteSalesOrderAttachment(target.attachment_id)
      files.splice(index, 1)
      if (files.length && !files.some(item => item.is_main)) files[0].is_main = true
      this.selectedLine.technical_attachment_snapshot = { files }
      this.selectedLine.drawing_snapshot = { files: files.filter(item => item.file_type === '设计图纸').length, main_file: files.find(item => item.is_main) || null }
    },
    setMainFile(index) {
      const files = this.files(this.selectedLine)
      files.forEach((item, i) => { item.is_main = i === index })
      this.selectedLine.technical_attachment_snapshot = { files }
      this.selectedLine.drawing_snapshot = { files: files.filter(item => item.file_type === '设计图纸').length, main_file: files[index] || null }
    },
    recalc() {
      this.syncHeaderFlags()
    },
    syncHeaderFlags() {
      this.form.is_customized = this.form.lines.some(line => line.is_customized)
    },
    lineCapabilities(line) {
      return (line && (line.sku_snapshot || line.sku)) || {}
    },
    lineSupportsElectric(line) {
      return ['optional', 'required'].includes(this.lineCapabilities(line).electric_mode)
    },
    lineElectricRequired(line) {
      return this.lineCapabilities(line).electric_mode === 'required'
    },
    lineElectricOptions(line) {
      const options = this.lineCapabilities(line).electric_options
      return Array.isArray(options) && options.length ? options : ['220V', '380V', '其他']
    },
    lineSupportsNeedPump(line) {
      return ['optional', 'required'].includes(this.lineCapabilities(line).need_pump_mode)
    },
    lineNeedPumpRequired(line) {
      return this.lineCapabilities(line).need_pump_mode === 'required'
    },
    lineAttributeMessage(line) {
      if (this.lineElectricRequired(line) && !String(line.electric || '').trim()) return '请填写电压'
      if (this.lineNeedPumpRequired(line) && (line.need_pump === null || line.need_pump === undefined || line.need_pump === '')) return '请选择原水泵控制'
      return ''
    },
    lineCustomizationMessage(line) {
      if (!line.is_special_customized) return ''
      const sku = this.lineCapabilities(line)
      const files = this.files(line)
      if (sku.special_custom_drawing_required && !files.some(file => ['设计图纸', '客户图纸'].includes(file.file_type))) return '特殊定制缺少设计图纸'
      if (sku.special_custom_agreement_required && !files.some(file => file.file_type === '技术协议')) return '特殊定制缺少客户技术协议'
      if (sku.special_custom_description_required && !String((line.configuration_snapshot || {}).special_custom_description || '').trim()) return '请填写特殊定制配置说明'
      return ''
    },
    applyImpactPreview(impact) {
      const summary = impact.approval_summary || {}
      const approvalLabels = { business: '业务审核', finance: '财务审核', fulfillment: '履约复核' }
      this.impactPreview = {
        level: impact.overall_risk_level || 'low',
        candidateVersion: `V${impact.candidate_version || 1}`,
        effectiveVersion: `V${impact.base_version || 0}`,
        requiresApproval: Boolean(impact.requires_approval),
        approvals: {
          business: (impact.required_approval_types || []).includes('business'),
          finance: (impact.required_approval_types || []).includes('finance'),
          fulfillment: (impact.required_approval_types || []).includes('fulfillment')
        },
        approvalReasons: impact.approval_reasons || {},
        summary: { total: impact.change_count || 0, none: summary.none || 0, business: summary.business || 0, finance: summary.finance || 0, fulfillment: summary.fulfillment || 0 },
        changes: (impact.diffs || []).map(row => ({
          key: row.semantic_key,
          label: row.label,
          before: row.before || '—',
          after: row.after || '—',
          impact: row.business_impact_text,
          requirement: (row.approval_requirements || []).length
            ? (row.approval_requirements || []).map(type => approvalLabels[type] || type).join(' + ')
            : '直接保存'
        }))
      }
    },
    async submitImpactPreview() {
      if (!this.impactPayload) { this.impactDialogVisible = false; return }
      if (this.impactPreview.requiresApproval && !String(this.impactReason || '').trim()) return this.$message.error('请填写本次修改的变更原因')
      this.impactSubmitting = true
      try {
        const { data } = await submitSalesOrderEditImpact(this.$route.params.id, { ...this.impactPayload, change_reason: this.impactReason })
        this.$message.success(data.message || '订单修改已处理')
        this.impactDialogVisible = false
        this.$router.push(`/sales/orders/${this.$route.params.id}/detail`)
      } finally { this.impactSubmitting = false }
    },
    async save(andConfirm) {
      if (!this.form.customer_id) return this.$message.error('请通过客户选择框选择客户')
      if (!this.form.sales_user_legacy_id) return this.$message.error('请选择销售人员')
      if (!this.form.platform) return this.$message.error('请选择成交平台')
      if (!this.form.pay_type) return this.$message.error('请选择付款方式')
      if (this.form.lines.some(line => !line.sku_id)) return this.$message.error('订单行必须通过全局搜索选择SKU')
      if (this.form.lines.some(line => !line.product_id)) return this.$message.error('所选SKU缺少所属Product，请维护SKU主数据后重试')
      if (andConfirm && !this.form.carrier_id) return this.$message.error('提交确认前必须先选择快递')
      if (andConfirm && this.form.lines.some(line => Number(line.unit_price || 0) <= 0)) return this.$message.error('提交确认前，订单行销售单价必须大于 0')
      const invalidAttribute = this.form.lines.find(line => this.lineAttributeMessage(line))
      if (andConfirm && invalidAttribute) return this.$message.error(`订单行 ${this.form.lines.indexOf(invalidAttribute) + 1}：${this.lineAttributeMessage(invalidAttribute)}`)
      const invalidCustomization = this.form.lines.find(line => this.lineCustomizationMessage(line))
      if (andConfirm && invalidCustomization) return this.$message.error(`订单行 ${this.form.lines.indexOf(invalidCustomization) + 1}：${this.lineCustomizationMessage(invalidCustomization)}`)
      this.syncHeaderFlags()
      const carrier = this.carrierOptions.find(item => String(item.id) === String(this.form.carrier_id))
      const payload = {
        ...this.form,
        deleted_line_ids: this.deletedLineIds,
        share_user: this.form.is_share ? this.form.share_user : [],
        carrier_fee: Number(this.form.carrier_fee || 0),
        default_carrier_id: this.form.carrier_id || null,
        order_remark: this.form.remark || null,
        logistics_requirement: (this.form.shipping_snapshot || {}).customer_logistics_note || null,
        customer_remark: (this.form.customer_snapshot || {}).remark || null,
        contract_attachments: JSON.stringify(this.contractFiles),
        shipping_snapshot: {
          ...(this.form.shipping_snapshot || {}),
          carrier_id: this.form.carrier_id || null,
          carrier_name: carrier ? carrier.name : null,
          carrier_fee: Number(this.form.carrier_fee || 0)
        },
        lines: this.form.lines.map((line, index) => ({
          ...line,
          line_no: index + 1,
          amount: this.lineAmount(line),
          configuration_snapshot: {
            ...(line.configuration_snapshot || {}),
            need_pump: line.need_pump,
            electric: line.electric || null,
            is_customized: line.is_customized,
            is_special_customized: line.is_special_customized
          },
          item_id: null,
          item_name: null,
          product_snapshot: null,
          sku_snapshot: null,
          item_snapshot: null,
          bom_snapshot: null,
          routing_snapshot: null
        }))
      }
      if (this.isConfirmedEdit) {
        const { data } = await previewSalesOrderEditImpact(this.$route.params.id, payload)
        const impact = data.data
        if (!impact || Number(impact.change_count || 0) === 0) {
          this.impactPayload = null
          this.impactDialogVisible = false
          this.$message.info('未检测到需要保存的订单修改')
          return
        }
        this.impactPayload = payload
        this.impactReason = ''
        this.applyImpactPreview(impact)
        this.impactDialogVisible = true
        return
      }
      const { data } = await saveSalesOrder(payload)
      const id = data.data.id
      if (!this.isEdit) {
        clearCreatePageReservation(this.numberReservation)
        this.numberReservation = null
      }
      if (andConfirm) {
        await confirmSalesOrder(id)
        this.$message.success('确认前校验已通过，订单等待下一阶段正式确认')
        this.$router.push(`/sales/orders/${id}/detail`)
      } else {
        this.$message.success('订单草稿已保存')
        const editPath = `/sales/orders/${id}/edit`
        if (this.$route.path !== editPath) {
          this.$router.push(editPath)
        }
      }
    },
    lineAmount(line) {
      return Number(line.order_qty || 0) * Number(line.unit_price || 0)
    },
    money(value) {
      return Number(value || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    },
    lineTypeText(v) {
      return ({ physical: '制造/发货', service: '服务履约', no_delivery: '无需发货', auxiliary: '辅助录入', fee: '费用' })[v] || v
    },
    lineTypeTag(v) {
      return ({ physical: 'warning', service: 'info', no_delivery: 'success', auxiliary: '' })[v] || ''
    }
  }
}
</script>

<style scoped>
.sales-form-page{position:relative;z-index:5;margin-top:-52px;min-height:100vh;background:#f7f8fa;color:#172033}.form-toolbar{height:52px;padding:0 20px;display:flex;align-items:center;justify-content:space-between;background:#fff;border-bottom:1px solid #e5e9ef}.page-title{display:flex;align-items:center;gap:12px;font-size:18px;font-weight:700}.back-btn{border:0;background:transparent;font-size:20px;cursor:pointer}.toolbar-actions{display:flex;gap:10px}.top-tip{margin:10px 14px}.form-tabs{height:40px;padding:0 18px;display:flex;gap:28px;background:#fff;border-bottom:1px solid #e5e9ef}.form-tabs button{border:0;background:transparent;border-bottom:2px solid transparent;font-weight:600;color:#475569;cursor:pointer}.form-tabs button.active{color:#00984f;border-color:#00984f}.form-layout{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:12px;padding:12px}.top-cards{display:grid;grid-template-columns:1.05fr 1.05fr .95fr;gap:10px}.panel{min-width:0;background:#fff;border:1px solid #e4e9f0;border-radius:5px}.panel h3{margin:0;padding:14px 16px 10px;font-size:15px}.panel h3 small{font-size:12px;color:#8b96a5;font-weight:400}.info-grid{padding:0 16px 16px;display:grid;grid-template-columns:90px minmax(0,1fr) 82px;gap:10px;align-items:center}.info-grid.two{grid-template-columns:110px minmax(0,1fr)}.info-grid label,.flag-grid label{font-weight:600;color:#455466}.required::before{content:'*';color:#f5222d;margin-right:4px}.flag-grid{padding:0 16px 10px;display:grid;grid-template-columns:120px minmax(0,1fr);gap:12px;align-items:center}.panel-note{margin:0 16px 14px;color:#8b96a5}.order-lines{margin-top:10px}.section-title{padding:12px 16px;display:flex;align-items:center;justify-content:space-between}.section-title h3{padding:0}.line-tip{margin:0 16px 10px;width:auto}.line-total{height:42px;padding:0 14px;display:flex;align-items:center;gap:42px;border-top:1px solid #edf0f4}.line-total span{margin-right:auto}.dash{color:#94a3b8}.danger-link{color:#dc2626}.select-cell{width:100%;overflow:hidden;text-overflow:ellipsis}.readonly-match{color:#64748b}.bottom-grid{margin-top:10px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}.small-panel{min-height:116px}.inline-form{padding:0 16px 14px;display:grid;grid-template-columns:auto auto auto auto;gap:10px;align-items:center}.logistics-grid{padding:0 16px 14px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}.attachment-row{padding:0 16px 14px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}.attachment-row div{height:68px;border:1px dashed #d8e0ea;border-radius:5px;display:grid;place-items:center;text-align:center;color:#64748b}.attachment-row i{font-size:18px;color:#00984f}.attachment-row small{display:block;font-size:11px;color:#94a3b8}.summary-bar{margin-top:10px;height:70px;background:#fff;border:1px solid #e4e9f0;border-radius:5px;display:grid;grid-template-columns:1.5fr repeat(4,1fr)}.summary-bar div{padding:14px 18px;border-right:1px solid #edf0f4}.summary-bar div:last-child{border-right:0}.summary-bar span{display:block;color:#64748b}.summary-bar b{font-size:22px;color:#0f172a}.line-drawer{background:#fff;border:1px solid #e4e9f0;border-radius:5px;align-self:start;position:sticky;top:64px}.drawer-head{height:50px;padding:0 14px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #e4e9f0}.drawer-head h2{font-size:16px;margin:0}.line-drawer section{padding:14px;border-bottom:1px solid #edf0f4}.line-drawer h3{margin:0 0 12px;font-size:14px}.line-drawer h3 b{display:inline-grid;place-items:center;width:18px;height:18px;margin-right:6px;border-radius:4px;background:#00984f;color:#fff}.product-card{display:grid;grid-template-columns:86px 1fr;gap:12px}.product-img{height:86px;border:1px solid #e4e9f0;border-radius:5px;display:grid;place-items:center;color:#94a3b8;font-size:30px}.product-img img{max-width:100%;max-height:100%;object-fit:cover}.product-card dl,.drawer-grid{display:grid;grid-template-columns:74px 1fr;gap:9px;align-items:center}.product-card dt{color:#64748b}.product-card dd{margin:0}.fulfill-box{padding:10px;background:#f8fafc;border:1px solid #e4e9f0;border-radius:5px;color:#475569}.upload-row{display:grid;grid-template-columns:1fr auto;gap:8px;margin-bottom:10px}.file-table{display:grid;grid-template-columns:minmax(0,1fr) 34px 34px 58px 34px;gap:8px;align-items:center}.file-table span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.file-table small{display:block;color:#94a3b8}.file-table em{font-style:normal;margin-left:4px;color:#00984f}.file-table a{color:#2563eb;cursor:pointer}.empty-files{padding:10px;border:1px dashed #d8e0ea;border-radius:5px;color:#94a3b8;text-align:center}.sales-form-page :deep(.el-button--success){background:#00984f;border-color:#00984f}.precheck-focus{outline:2px solid #f59e0b;outline-offset:2px;transition:outline-color .25s ease}.sales-form-page :deep(.el-input-number--mini){width:86px}.sales-form-page :deep(.el-table th){background:#f8fafc;color:#334155}@media(max-width:1400px){.top-cards{grid-template-columns:1fr}.form-layout{grid-template-columns:1fr}.line-drawer{position:static}.bottom-grid{grid-template-columns:1fr}.summary-bar{grid-template-columns:1fr 1fr}}
.sales-form-page :deep(.el-input-number--mini){width:68px}.sales-form-page .line-drawer :deep(.el-input-number--mini){width:96px}.sales-form-page :deep(.el-table){font-size:12px}.sales-form-page :deep(.el-table .cell){padding-left:6px;padding-right:6px;line-height:18px}.sales-form-page :deep(.el-table--mini td),.sales-form-page :deep(.el-table--mini th){padding:6px 0}.sales-form-page :deep(.el-table .el-button--mini){padding:5px 6px;font-size:12px}.order-lines :deep(.el-input--mini .el-input__inner){height:28px;line-height:28px;padding:0 7px}
.inline-selects{display:grid;grid-template-columns:1fr 1fr;gap:8px}.inline-selects .el-select:only-child{grid-column:1 / -1}.inline-form{grid-template-columns:auto auto auto minmax(100px,1fr);grid-auto-rows:32px}.inline-form .el-select{min-width:180px}.logistics-grid{grid-template-columns:1fr 1fr 1fr;row-gap:8px}.field-stack{min-width:0;display:grid;gap:4px}.field-stack span{font-size:12px;font-weight:600;color:#64748b}.field-stack .el-select{width:100%}.small-panel{min-height:142px}
.final-grid{margin-top:10px;display:grid;grid-template-columns:minmax(0,1.55fr) minmax(430px,.95fr);gap:10px;align-items:stretch}.summary-bar{margin-top:0;min-height:78px;height:auto;grid-template-columns:1.1fr repeat(5,minmax(86px,1fr));overflow:hidden}.summary-bar .formula-cell{background:#fff}.formula-cell span small{margin-left:6px;color:#9aa3ac;font-weight:400}.formula-cell em{display:inline-flex;align-items:center;gap:5px;margin-top:7px;padding:4px 8px;border-radius:3px;background:#eaf7ef;color:#07883f;font-style:normal;white-space:normal}.summary-bar div{display:flex;flex-direction:column;justify-content:center;padding:10px 14px}.summary-bar b{font-size:20px;line-height:1.2}.submit-check-card{min-height:78px;height:auto;padding:12px 14px;background:#fff;border:1px solid #e4e9f0;border-radius:5px}.submit-check-card h3{display:flex;align-items:center;gap:6px;margin:0 0 7px;font-size:14px}.submit-check-card h3 i{color:#f59e0b}.submit-check-card h3 small{font-weight:400;color:#8b96a5}.submit-check-card p{margin:3px 0;color:#d97706}.submit-check-card p.ok{color:#07883f}.product-link{display:block;margin-top:10px;text-align:right}.upload-row{grid-template-columns:1fr auto auto}.file-table{display:grid;grid-template-columns:minmax(0,1.35fr) 42px 78px minmax(118px,auto);gap:0;border:1px solid #e4e9f0;border-bottom:0;font-size:11px}.file-table>*{min-height:28px;padding:6px 7px;border-right:1px solid #e4e9f0;border-bottom:1px solid #e4e9f0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.file-table>*:nth-child(4n){border-right:0}.file-table strong{background:#f8fafc;color:#526176;font-weight:600}.file-actions{display:flex;gap:6px}.empty-file-row{color:#94a3b8}.change-tip{margin-top:10px;padding:8px 10px;border:1px solid #f6d7a8;background:#fff7ed;color:#ad5b00;border-radius:4px}.change-tip i{margin-right:5px}.drawer-head i{cursor:pointer;color:#64748b}
@media(max-width:1400px){.final-grid{grid-template-columns:1fr}.summary-bar{grid-template-columns:1fr 1fr}.submit-check-card{height:auto}}

/* Phase 6 order add/edit image-to-code correction: remove fake tabs, tighten ERP density */
.sales-form-page{max-width:100%;overflow-x:hidden}
.form-main{min-width:0}
.form-layout{grid-template-columns:minmax(0,1fr) 365px;padding:10px 12px 14px;gap:10px;max-width:100%;overflow-x:hidden}
.top-cards{grid-template-columns:1.15fr 1fr .92fr;align-items:stretch;gap:10px}
.top-cards .panel{display:flex;flex-direction:column;min-height:248px}
.panel h3{padding:12px 14px 8px}
.order-basic-card .info-grid{grid-template-columns:86px minmax(0,1fr) 76px;gap:8px;padding:0 14px 12px}
.customer-card .info-grid.two{grid-template-columns:96px minmax(0,1fr);gap:8px;padding:0 14px 12px}
.customer-select-field{display:grid;grid-template-columns:minmax(0,1fr) 84px;gap:8px;align-items:center}
.customer-kind-radios{display:flex;min-width:0}.customer-kind-radios :deep(.el-radio-button__inner){padding:7px 10px;font-size:11px}
.customer-card :deep(.el-textarea__inner){min-height:52px!important}
.delivery-card .flag-grid{grid-template-columns:96px minmax(0,1fr);gap:8px;padding:0 14px 8px}
.delivery-card .panel-note{margin-top:auto;padding-top:6px;border-top:1px dashed #e5e9ef}
.info-grid label,.flag-grid label{font-size:12px;color:#475569}
.info-grid :deep(.el-input__inner),.flag-grid :deep(.el-input__inner),.logistics-grid :deep(.el-input__inner){height:31px;line-height:31px}
.order-lines{margin-top:10px}
.order-lines :deep(.el-table){width:100%!important}
.order-lines :deep(.el-table__body),.order-lines :deep(.el-table__header){width:100%!important}
.order-lines :deep(.el-table__empty-block){width:100%!important}
.section-title{padding:10px 14px}
.section-title>div{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
.line-tip{margin:0 14px 8px}
.bottom-grid{grid-template-columns:.95fr 1.2fr 1fr;gap:10px}
.small-panel{min-height:188px}
.reminder-grid{padding:0 14px 14px;display:grid;grid-template-columns:78px minmax(120px,1fr) 88px minmax(120px,1fr);gap:10px 12px;align-items:center}
.reminder-grid label{font-size:12px;font-weight:600;color:#475569}
.reminder-grid .el-textarea{grid-column:2 / 5}
.reminder-grid .el-select{grid-column:2 / 5}
.reminder-form{padding:0 14px 14px;display:grid;gap:10px}
.reminder-row{display:grid;grid-template-columns:minmax(150px,1fr) minmax(190px,1.15fr);gap:14px;align-items:center}
.switch-field,.days-field,.share-user-line{display:flex;align-items:center;gap:8px;min-width:0}
.switch-field span,.days-field span,.share-user-line>span,.reminder-content>span{font-size:12px;font-weight:600;color:#475569;white-space:nowrap}
.switch-field em,.days-field em{font-style:normal;color:#64748b;font-size:12px}
.days-field :deep(.el-input-number--small){width:92px}
.reminder-content{display:grid;grid-template-columns:76px minmax(0,1fr);gap:8px;align-items:start}
.share-switch-line{margin-top:2px}
.share-user-line{align-items:flex-start}
.select-share-btn{height:30px;padding:0 10px;border:1px solid #d8e0ea;border-radius:4px;background:#fff;color:#334155;cursor:pointer}
.select-share-btn:hover{border-color:#00984f;color:#00984f;background:#f2fbf6}
.select-share-btn i{color:#00984f}
.share-picker-inline{grid-column:2 / 5;display:flex;align-items:center;gap:8px;min-width:0}
.plus-share-btn{width:30px;height:30px;border:1px solid #cfe4d7;border-radius:4px;background:#f2fbf6;color:#07883f;cursor:pointer}
.plus-share-btn:hover{background:#e5f7ec;border-color:#07883f}
.share-chip-list{min-height:30px;flex:1;display:flex;align-items:center;gap:6px;flex-wrap:wrap;padding:3px 8px;border:1px solid #e2e8f0;border-radius:4px;background:#fff}
.share-chip-list span{color:#94a3b8}
.reminder-empty-hint{grid-column:1 / -1;margin:4px 0 0;padding:9px 10px;border:1px dashed #d8e0ea;border-radius:5px;background:#fbfdff;color:#8a96a6}
.share-dialog-body{display:grid;gap:12px}
.share-user-list{max-height:320px;overflow:auto;border:1px solid #e4e9f0;border-radius:4px;padding:8px 10px;display:grid;grid-template-columns:1fr 1fr;gap:6px 12px}
.share-user-list .el-checkbox{margin:0;padding:8px;border-radius:4px}
.share-user-list .el-checkbox:hover{background:#f8fafc}
.share-user-list span,.share-user-list small{display:block}
.share-user-list small{margin-top:2px;color:#94a3b8;font-size:11px}
.logistics-grid{padding:0 14px 14px;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}
.logistics-card .field-stack span{line-height:18px}
.logistics-card :deep(.el-date-editor.el-input){width:100%}
.contract-upload-grid{padding:0 14px 10px;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
.contract-upload-grid :deep(.el-upload){display:block;width:100%}
.contract-upload-grid .el-upload{display:block;width:100%}
.upload-card{height:78px;border:1px dashed #d4dde8;border-radius:6px;background:#fbfdff;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;color:#506070;cursor:pointer}
.upload-card{width:100%}
.upload-card:hover{border-color:#00984f;background:#f2fbf6}
.upload-card i{font-size:18px;color:#00984f}
.upload-card span{font-weight:600;font-size:12px}
.upload-card small{font-size:11px;color:#93a0af}
.contract-meta{margin:0 14px 14px;padding:8px 10px;border-radius:5px;background:#f8fafc;border:1px solid #e7ebf1;display:flex;align-items:center;justify-content:space-between;gap:8px;color:#64748b;font-size:12px}
.line-drawer{width:365px;min-width:0}
.line-drawer section{padding:11px 13px}
.drawer-head{height:46px}
.drawer-head h2{font-size:15px;max-width:310px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.product-card{grid-template-columns:76px 1fr;gap:10px}
.product-img{height:76px;font-size:26px}
.product-card dl,.drawer-grid{grid-template-columns:70px minmax(0,1fr);gap:7px}
.line-drawer h3{margin-bottom:9px}
.line-drawer .fulfill-box p{margin:6px 0;line-height:1.45}
.final-grid{grid-template-columns:minmax(0,1.45fr) minmax(440px,.95fr)}
.row-actions{display:flex;align-items:center;justify-content:center;gap:8px;white-space:nowrap}
.line-file-upload{display:block}
.line-file-upload :deep(.el-upload){width:100%}
.qty-unit-cell,.number-with-unit{display:flex;align-items:center;gap:6px}.qty-unit-cell .el-input{min-width:0}.qty-unit-cell b,.number-with-unit b{white-space:nowrap;color:#475569}.fulfillment-conversion-box{padding:10px;background:#f5f8fc;border:1px solid #e1e7ef;border-radius:5px}.fulfillment-conversion-box dl{margin:0;display:grid;grid-template-columns:104px 1fr;gap:9px}.fulfillment-conversion-box dt{color:#64748b}.fulfillment-conversion-box dd{margin:0;color:#26354d;font-weight:600}
@media(max-width:1500px){.top-cards{grid-template-columns:1fr}.top-cards .panel{min-height:auto}.bottom-grid{grid-template-columns:1fr}.form-layout{grid-template-columns:1fr}.line-drawer{position:static;width:auto}}
/* Keep the image-to-code desktop composition at common laptop/desktop widths. */
@media (min-width:1101px) and (max-width:1500px){
  .form-layout{grid-template-columns:minmax(0,1fr) 365px!important}
  .top-cards{grid-template-columns:1.15fr 1fr .92fr!important}
  .bottom-grid{grid-template-columns:.95fr 1.2fr 1fr!important}
  .line-drawer{position:sticky!important;width:365px!important}
  .final-grid{grid-template-columns:minmax(0,1.45fr) minmax(440px,.95fr)!important}
}
/* The order stores a default carrier and customer logistics preferences; actual tracking belongs to shipment records. */
.logistics-grid > .field-stack:nth-child(6){display:none}

/* Confirmed responsive correction: a 1500px browser still leaves only about 880px
   for the form when the ERP sidebar and line drawer are open.  Do not force the
   wide three-card desktop grid into that remaining space. */
@media (min-width:1101px) and (max-width:1650px){
  .form-layout{grid-template-columns:minmax(0,1fr) 365px!important}
  .top-cards{grid-template-columns:repeat(2,minmax(0,1fr))!important}
  .top-cards .delivery-card{grid-column:1 / -1}
  .bottom-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important}
  .bottom-grid .contract-card{grid-column:1 / -1}
  .small-panel{min-height:0}
  .reminder-card,.logistics-card{min-width:0}
  .reminder-row{grid-template-columns:1fr;gap:8px}
  .reminder-empty-hint{grid-column:auto;min-width:0;overflow-wrap:anywhere;word-break:break-word}
  .logistics-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
  .logistics-grid .trade-type-field{grid-column:1 / -1}
  .final-grid{grid-template-columns:1fr!important}
  .summary-bar{grid-template-columns:repeat(3,minmax(0,1fr))}
  .summary-bar .formula-cell{grid-column:1 / -1}
}
@media (max-width:1100px){
  .form-layout,.top-cards,.bottom-grid,.final-grid{grid-template-columns:1fr!important}
  .top-cards .delivery-card,.bottom-grid .contract-card{grid-column:auto}
  .line-drawer{position:static!important;width:auto!important}
  .logistics-grid,.contract-upload-grid{grid-template-columns:1fr}
  .summary-bar{grid-template-columns:1fr 1fr}
  .summary-bar .formula-cell{grid-column:1 / -1}
}
</style>
