<!--
Design reference: D:\codex-introduce\new_erp\docs\product-design\phase6-order\phase6-order-add-edit.png
Design status: 已按用户要求重排（紧凑、自适应、结构清晰）：订单信息 / 客户与收货 / 订单行 / 物流·提醒·合同 四段式；去掉订单行“长度下料要求”。
Do not change layout without approval.
-->
<template>
  <section class="sales-form-page">
    <product-sku-picker ref="productSkuPicker" @select="applyPicker" />
    <customer-picker ref="customerPicker" @select="applyCustomer" />
    <warehouse-manager-picker :visible.sync="salesUserPickerVisible" title="选择销售人员" scope="sales" :current="selectedSalesUser" @selected="applySalesUser" />
    <sales-carrier-picker :visible.sync="carrierPickerVisible" :current="selectedCarrier" @selected="applyCarrier" />
    <order-edit-impact-dialog
      :visible.sync="impactDialogVisible"
      :changes="impactPreview.changes"
      :approvals="impactPreview.approvals"
      :approval-reasons="impactPreview.approvalReasons"
      :summary="impactPreview.summary"
      :level="impactPreview.level"
      :candidate-version="impactPreview.candidateVersion"
      :effective-version="impactPreview.effectiveVersion"
      :effect-summary="impactPreview.effectSummary"
      :reason.sync="impactReason"
      :submitting="impactSubmitting"
      :submit-error="impactSubmitError"
      :selection-groups="impactPreview.productionSelectionGroups || []"
      :selection-errors="impactPreview.productionSelectionErrors || []"
      :quantity-adjustments="impactPreview.quantityAdjustments || []"
      :can-submit="impactPreview.canSubmit !== false"
      :previewing="impactRechecking"
      @back="impactDialogVisible = false"
      @submit="submitImpactPreview"
      @choose-production-units="chooseProductionUnits"
    />
    <system-record-picker :visible.sync="productionUnitPicker.visible" :request="loadChangeProductionUnits" :params="productionUnitPicker.params" :columns="productionUnitColumns" :config="productionUnitPicker.config" :multiple="true" :required-count="productionUnitPicker.requiredCount" :max-count="productionUnitPicker.maxCount" :selectable="productionUnitSelectable" :required-selection="productionUnitRequired" :value="productionUnitPicker.value" :label="productionUnitLabel" @confirm="applyProductionUnitSelection" />

    <header class="form-toolbar hero-top-bar">
      <div class="toolbar-title">
        <button type="button" class="back-btn" :title="platformPaymentOnly ? '返回订单' : '返回列表'" @click="$router.push(returnPath)"><i class="el-icon-back" /></button>
        <div class="toolbar-title-meta">
          <div class="toolbar-crumb">销售管理 / 销售订单 / <span>{{ platformPaymentOnly ? '核对平台付款' : (isEdit ? '编辑订单' : '新增订单') }}</span></div>
          <div class="toolbar-heading-row">
            <span class="main-title">{{ platformPaymentOnly ? '核对平台付款' : (isEdit ? '编辑销售订单' : '新增销售订单') }}</span>
            <span v-if="form.sales_order_no" class="order-no-pill"><i class="pill-dot" />{{ form.sales_order_no }}</span>
            <span class="order-stage-pill" :class="{ 'is-confirmed': isConfirmedEdit }">
              {{ platformPaymentOnly ? '平台款核对' : (isConfirmedEdit ? `正式变更 · V${editOrderMeta.business_version || 1}` : (isEdit ? '草稿编辑中' : '草稿录制中')) }}
            </span>
          </div>
        </div>
      </div>
      <div v-if="!platformPaymentOnly" class="toolbar-kpi-capsule">
        <div class="kpi-mini-item">
          <span class="kpi-mini-icon"><i class="el-icon-s-order" /></span>
          <div class="kpi-mini-body">
            <span class="kpi-mini-label">订单明细</span>
            <span class="kpi-mini-val"><strong>{{ form.lines.length }}</strong> 行 · 共 <strong>{{ totalQty }}</strong> 件</span>
          </div>
        </div>
        <span class="kpi-mini-divider" />
        <div class="kpi-mini-item">
          <span class="kpi-mini-icon is-emerald"><i class="el-icon-box" /></span>
          <div class="kpi-mini-body">
            <span class="kpi-mini-label">备货组成</span>
            <span class="kpi-mini-val">需生产 <strong>{{ productionLineCount }}</strong> 行 · 外购 <strong>{{ purchaseLineCount }}</strong> 行</span>
          </div>
        </div>
        <span class="kpi-mini-divider" />
        <div class="kpi-mini-item">
          <span class="kpi-mini-icon is-emerald">¥</span>
          <div class="kpi-mini-body">
            <span class="kpi-mini-label">订单应收总额</span>
            <span class="kpi-mini-val summary-amount">¥{{ money(totalAmount) }}</span>
          </div>
        </div>
      </div>
      <div class="toolbar-actions">
        <el-button size="small" @click="$router.push(returnPath)">{{ platformPaymentOnly ? '返回订单' : '返回列表' }}</el-button>
        <el-button v-if="canEditOrder" size="small" class="btn-emerald-plain" :disabled="platformPaymentSaving" @click="save(false)">{{ isConfirmedEdit ? '保存修改' : '保存草稿' }}</el-button>
        <el-button v-if="canEditOrder && !isConfirmedEdit && ($can('sales_order.submit_confirmation') || $can('sales_order.formal_confirm'))" size="small" type="primary" icon="el-icon-check" class="btn-emerald-primary" :disabled="platformPaymentSaving" @click="save(true)">保存并确认</el-button>
      </div>
    </header>

    <div v-if="!isEdit || canEditOrder || platformPaymentOnly" class="form-body">
      <!-- 按受影响主子工单实际工时及财务影响判断审核 -->
      <div v-if="!platformPaymentOnly && editStageTip" :class="['stage-tip', `stage-tip--${editStageTip.type}`]">
        <i :class="editStageTip.icon" />
        <span>{{ editStageTip.text }}</span>
      </div>

      <div :class="{ 'top-grid': !platformPaymentOnly }">
        <section ref="基本信息" class="panel order-basic-card">
          <div class="panel-header">
            <div class="panel-title-wrap">
              <h3>订单核心信息</h3>
            </div>
          </div>

          <div class="basic-layers">
            <div class="layer-block">
              <div class="layer-heading">
                <span class="layer-dot is-emerald" />
                <span>单据归属与渠道信息</span>
              </div>
              <div class="field-grid grid-cols-4">
                <div class="field">
                  <label>订单号 <span v-if="form.sales_order_no" class="field-label-tag">已预占</span></label>
                  <el-input :value="form.sales_order_no || '保存后系统生成'" size="small" disabled class="erp-system-number" />
                </div>
                <div class="field">
                  <label for="sales-original-order-no" :class="{ required: isOnlinePlatform }">原始单号</label>
                  <el-input id="sales-original-order-no" v-model.trim="form.origin_order_no" size="small" maxlength="120" :placeholder="isOnlinePlatform ? '请填写原始单号' : '原始单号（选填）'" :required="isOnlinePlatform" :disabled="platformPaymentOnly" />
                </div>
                <div v-if="!platformPaymentOnly" class="field">
                  <label class="required">下单时间</label>
                  <el-date-picker v-model="form.order_time" type="datetime" size="small" value-format="yyyy-MM-dd HH:mm:ss" placeholder="选择下单时间" />
                </div>
                <div v-if="!platformPaymentOnly" class="field">
                  <label class="required">销售人员</label>
                  <el-input :value="selectedSalesUser && (selectedSalesUser.nickname || selectedSalesUser.username)" size="small" readonly placeholder="请选择销售" suffix-icon="el-icon-search" @focus="salesUserPickerVisible = true" @click.native="salesUserPickerVisible = true" />
                </div>
                <div v-if="!platformPaymentOnly" class="field">
                  <label class="required">订单来源</label>
                  <el-select v-model="form.order_source" size="small">
                    <el-option label="销售订单" value="manual" />
                    <el-option label="历史迁移" value="legacy_sync" />
                    <el-option label="线索转单" value="crm_clue" />
                  </el-select>
                </div>
                <div v-if="!platformPaymentOnly" class="field">
                  <label>贸易类型</label>
                  <el-radio-group v-model="form.trade_type" size="small" class="erp-segmented-radio">
                    <el-radio-button label="domestic">内贸订单</el-radio-button>
                    <el-radio-button label="foreign">外贸出口</el-radio-button>
                  </el-radio-group>
                </div>
                <div class="field span-2">
                  <label class="required">成交平台与子渠道</label>
                  <div class="platform-inline-row">
                    <el-input v-if="platformPaymentOnly" :value="editOrderMeta.platform_label" size="small" disabled class="platform-select-item" />
                    <template v-else>
                      <el-select v-model="form.platform" size="small" filterable :placeholder="form.trade_type === 'foreign' ? '国外交易平台' : '国内交易平台'" clearable class="platform-select-item" @change="handlePlatformChange">
                        <el-option v-for="item in platformOptions" :key="item.id" :label="item.name" :value="String(item.id)" />
                      </el-select>
                      <el-select v-if="platformChildrenOptions.length" v-model="form.platform2" size="small" filterable placeholder="请选择子平台（必选）" clearable class="platform-select-item" @change="resetPlatformPaymentCheck">
                        <el-option v-for="item in platformChildrenOptions" :key="item.id" :label="item.name" :value="String(item.id)" />
                      </el-select>
                    </template>
                    <div v-if="isOnlinePlatform || platformPaymentOnly" class="platform-payment-inline">
                      <el-checkbox v-model="platformPaymentChecked" :disabled="!canCheckPlatformPayment || platformPaymentSaving">已核对买家在平台付清</el-checkbox>
                      <span v-if="platformPaymentDisabledReason" class="platform-inline-meta" :title="platformPaymentDisabledReason">· {{ platformPaymentDisabledReason }}</span>
                      <span v-else class="platform-inline-meta">· 应收 <strong class="platform-amount">{{ money(platformPaymentAmount) }}</strong> {{ form.currency || 'CNY' }}</span>
                      <el-button v-if="standalonePlatformPayment && canCheckPlatformPayment" size="mini" type="success" class="btn-emerald-primary platform-save-btn" :loading="platformPaymentSaving" :disabled="platformPaymentChecked === savedPlatformPayment" @click="savePlatformPayment">保存核对</el-button>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div v-if="!platformPaymentOnly" class="layer-block layer-recessed">
              <div class="layer-heading">
                <span class="layer-dot is-amber" />
                <span>收款条款与交期控制</span>
              </div>
              <div class="field-grid grid-cols-4">
                <div class="field">
                  <label class="required">付款方式</label>
                  <el-select v-model="form.payment_method_id" size="small" placeholder="请选择付款方式" clearable>
                    <el-option v-for="item in payTypeOptions" :key="item.id" :label="item.name" :value="String(item.id)" />
                  </el-select>
                </div>
                <div class="field">
                  <label>付款条件</label>
                  <el-select v-model="form.funding_policy_id" size="small" placeholder="请选择付款条件">
                    <el-option v-for="item in fundingPolicyOptions" :key="item.id" :label="item.policy_code === 'FULL_PREPAY' ? '全额付款' : item.policy_name" :value="item.id" />
                  </el-select>
                </div>
                <div class="field">
                  <label>
                    <span>约定定金</span>
                    <span v-if="depositSupported && !depositEdited" class="field-label-tag">默认 {{ defaultDepositText }}</span>
                    <el-button v-if="depositSupported && depositEdited" type="text" size="mini" class="label-inline-btn" @click="resetDeposit">恢复默认</el-button>
                  </label>
                  <div v-if="depositSupported" class="input-addon">
                    <span class="addon">¥</span>
                    <el-input-number :value="depositInput" size="small" :min="0" :precision="2" :controls="false" placeholder="与客户约定的定金" @change="changeDeposit" />
                  </div>
                  <el-input v-else value="全额付款无需定金" size="small" disabled />
                </div>
                <div class="field">
                  <label>要求交期</label>
                  <el-date-picker v-model="form.required_delivery_date" size="small" value-format="yyyy-MM-dd" placeholder="选择日期" />
                </div>
                <div class="field span-2">
                  <label>加急 / 延期设置</label>
                  <div class="switch-card-pair">
                    <div class="switch-card" :class="{ 'is-urgent-on': form.is_urgent }">
                      <span class="switch-card-title">加急订单</span>
                      <el-switch v-model="form.is_urgent" active-color="#ef4444" />
                    </div>
                    <div class="switch-card" :class="{ 'is-delay-on': form.is_delay }">
                      <span class="switch-card-title">客户要求延期</span>
                      <el-switch v-model="form.is_delay" active-color="#008b4b" />
                    </div>
                  </div>
                </div>
                <div class="field span-2">
                  <label>延期发货日期</label>
                  <el-date-picker v-model="form.delay_date" size="small" value-format="yyyy-MM-dd" :disabled="!form.is_delay" placeholder="开启延期后选择" />
                </div>
                <div class="field span-full">
                  <label>订单备注</label>
                  <el-input v-model="form.remark" size="small" maxlength="200" show-word-limit placeholder="填写整单生产、包装或商务注意事项（选填）" />
                </div>
              </div>
            </div>
          </div>
        </section>

        <section v-if="!platformPaymentOnly" ref="客户与收货" class="panel customer-card">
          <div class="panel-header">
            <div class="panel-title-wrap">
              <h3>客户与收货档案</h3>
            </div>
            <el-button size="mini" plain type="success" icon="el-icon-user" class="btn-emerald-plain" @click="$refs.customerPicker.open()">选择客户</el-button>
          </div>
          <div class="customer-body">
            <div class="smart-parse-cabin">
              <div class="smart-parse-head">
                <span class="smart-sparkle">✦</span>
                <span class="smart-title">智能地址解析粘贴板</span>
              </div>
              <el-input v-model="customerRawText" type="textarea" :rows="2" placeholder="粘贴姓名、电话、地址，自动识别并回填" @input="recognizeCustomerInfo" />
            </div>
            <div class="field-grid grid-cols-2">
              <div class="field span-2">
                <label class="required">客户名称</label>
                <div class="inline-pair">
                  <el-input v-model="form.customer_name" size="small" placeholder="点击右侧按钮选择客户" readonly @click.native="$refs.customerPicker.open()" />
                  <el-button size="small" plain type="success" class="btn-emerald-plain" @click="$refs.customerPicker.open()">选择客户</el-button>
                </div>
              </div>
              <div class="field">
                <label>客户类型</label>
                <el-radio-group v-model="form.customer_kind" size="small" class="erp-segmented-radio">
                  <el-radio-button label="individual">个人买家</el-radio-button>
                  <el-radio-button label="enterprise">企业单位</el-radio-button>
                </el-radio-group>
              </div>
              <div class="field">
                <label>平台买家 ID</label>
                <el-input v-model.trim="form.platform_buyer_id" size="small" placeholder="选填，个人客户查重" />
              </div>
              <div class="field">
                <label>收货联系人</label>
                <el-input v-model="form.contact_name" size="small" placeholder="企业联系人或收件人" />
              </div>
              <div class="field">
                <label>联系电话</label>
                <el-input v-model="form.customer_phone" size="small" placeholder="手机或固话" />
              </div>
              <div class="field span-full">
                <label>收货详细地址</label>
                <el-input v-model="form.full_address" type="textarea" :rows="2" placeholder="省 / 市 / 区 / 详细地址" />
              </div>
            </div>
          </div>
        </section>
      </div>

      <section
        v-if="!platformPaymentOnly"
        ref="订单行"
        class="panel order-lines"
        :class="{ 'precheck-focus': $route.query.focus === 'lines' || String($route.query.focus || '').startsWith('lines.') }"
      >
        <div class="panel-header">
          <div class="panel-title-wrap">
            <h3>订单行明细 <span class="count-pill is-emerald">{{ form.lines.length }} 行</span></h3>
          </div>
          <div class="panel-actions">
            <el-button size="small" icon="el-icon-document-copy" @click="copyLine">复制当前行</el-button>
            <el-button size="small" type="primary" class="btn-emerald-primary" icon="el-icon-plus" @click="addLine">添加订单行</el-button>
          </div>
        </div>
        <el-table
          ref="lineTable"
          :data="form.lines"
          row-key="line_uuid"
          :expand-row-keys="expandedLineKeys"
          border
          size="small"
          class="sales-order-table"
          highlight-current-row
          @current-change="selectLine"
          @expand-change="handleLineExpand"
        >
          <el-table-column type="expand" width="42" align="center">
            <template slot-scope="{row, $index}">
              <div class="order-line-detail">
                <div class="line-detail-head">
                  <span class="row-badge">行 {{ $index + 1 }} 配置工作台</span>
                  <strong class="line-detail-title">{{ row.product_name || '未选产品' }} · {{ row.sku_name || '未选规格' }}</strong>
                  <span v-if="lineNeedsItem(row)" class="line-detail-conversion">
                    （库存换算：1 {{ salesUnitName(row) }} = {{ fulfillmentFactor(row) }} {{ itemBaseUnitName(row) }} · 基本需求量 <strong>{{ itemBaseRequiredQty(row) }}</strong> {{ itemBaseUnitName(row) }}）
                  </span>
                  <span class="line-detail-amount">本行小计：{{ lineAmountText(row) }}</span>
                  <el-button type="text" size="mini" icon="el-icon-arrow-up" class="collapse-up-btn" @click="expandedLineKeys = []">收起面板</el-button>
                </div>

                <div class="line-detail-grid">
                  <div class="detail-block">
                    <div class="block-title-bar">
                      <span class="block-title"><i class="block-dot" />产品与物料快照</span>
                      <span class="code-badge">{{ row.product_code_snapshot || '未分配编码' }}</span>
                    </div>
                    <div class="product-brief">
                      <div class="product-img">
                        <img v-if="row.product_snapshot && row.product_snapshot.image" :src="legacyMediaUrl(row.product_snapshot.image)" alt="产品图片">
                        <i v-else class="el-icon-box" />
                      </div>
                      <div class="product-brief-meta">
                        <div class="meta-line"><span>匹配物料</span><strong>{{ row.item_name || '待系统匹配' }}</strong></div>
                        <div class="meta-line"><span>规格参数</span>{{ row.spec_text_snapshot || '—' }}</div>
                        <div class="meta-line">
                          <span>BOM状态</span>
                          <em v-if="!lineNeedsItem(row) || row.is_purchase_fulfilled" class="meta-em is-muted">无需生产 BOM</em>
                          <em v-else-if="row.bom_snapshot && row.bom_snapshot.name" class="meta-em is-ok">标准生产 BOM 已就绪</em>
                          <em v-else class="meta-em is-info">后续由工单维护</em>
                        </div>
                      </div>
                    </div>
                    <div class="block-actions">
                      <el-button size="mini" plain icon="el-icon-edit-outline" @click="openProductPicker(row)">更换产品</el-button>
                      <el-button size="mini" plain icon="el-icon-edit-outline" @click="openSkuPicker(row)">更换规格 (SKU)</el-button>
                    </div>
                  </div>

                  <div class="detail-block">
                    <div class="block-title-bar">
                      <span class="block-title"><i class="block-dot" />数量、单价与行备注</span>
                      <span v-if="row.line_type === 'deduction'" class="muted-text">填正数自动减钱</span>
                    </div>
                    <div class="field-grid grid-cols-2 compact">
                      <div class="field">
                        <label class="required">销售数量</label>
                        <div class="input-addon">
                          <el-input-number v-model="row.order_qty" size="small" :min="Math.max(0.0001, Number(row.edit_protected_qty || 0))" :precision="4" :controls="false" @change="recalc" />
                          <span class="addon">{{ salesUnitName(row) }}</span>
                        </div>
                      </div>
                      <div class="field">
                        <label class="required">{{ row.line_type === 'deduction' ? '扣减金额' : '销售单价' }}</label>
                        <div class="input-addon">
                          <span class="addon">¥</span>
                          <el-input-number v-model="row.unit_price" size="small" :min="0" :precision="2" :controls="false" @change="recalc" />
                        </div>
                      </div>
                      <div class="field">
                        <label>报价有效期</label>
                        <el-input v-model="row.configuration_snapshot.valid_days" size="small" placeholder="如 60 天" />
                      </div>
                      <div class="field">
                        <label>履约类型</label>
                        <div class="tag-holder"><el-tag size="small" :type="lineTypeTag(row.line_type)" effect="light">{{ lineTypeText(row) }}</el-tag></div>
                      </div>
                      <div class="field span-full">
                        <label>行备注</label>
                        <el-input v-model="row.remark" size="small" placeholder="本行特殊包装、备货或交付要求" />
                      </div>
                    </div>
                  </div>

                  <div class="detail-block">
                    <div class="block-title-bar">
                      <span class="block-title"><i class="block-dot" />定制、电气与检验配置</span>
                      <el-tag v-if="lineCapabilities(row).delivery_inspection_required" size="mini" type="warning" effect="plain">需要交付前检验</el-tag>
                    </div>
                    <div class="field-grid grid-cols-2 compact">
                      <div class="field">
                        <div class="inline-switch-box">
                          <span>普通定制</span>
                          <el-switch v-model="row.is_customized" :disabled="!lineCapabilities(row).allow_customized" active-color="#008b4b" @change="syncHeaderFlags" />
                        </div>
                      </div>
                      <div class="field">
                        <div class="inline-switch-box">
                          <span>特殊定制</span>
                          <el-switch v-model="row.is_special_customized" :disabled="!lineCapabilities(row).allow_special_customized" active-color="#008b4b" @change="syncHeaderFlags" />
                        </div>
                      </div>
                      <div class="field">
                        <label :class="{ required: lineElectricRequired(row) }">工作电压</label>
                        <el-select v-model="row.electric" size="small" clearable :disabled="!lineSupportsElectric(row)" :placeholder="lineSupportsElectric(row) ? '请选择电压' : '无需配置'">
                          <el-option v-for="option in lineElectricOptions(row)" :key="option" :label="option" :value="option" />
                        </el-select>
                      </div>
                      <div class="field">
                        <label :class="{ required: lineNeedPumpRequired(row) }">原水泵控制</label>
                        <el-select v-model="row.need_pump" size="small" clearable :disabled="!lineSupportsNeedPump(row)" :placeholder="lineSupportsNeedPump(row) ? '请选择' : '无需配置'">
                          <el-option label="需要控制水泵" :value="true" />
                          <el-option label="不需要控制水泵" :value="false" />
                        </el-select>
                      </div>
                      <div v-if="row.is_special_customized && lineCapabilities(row).special_custom_description_required" class="field span-full">
                        <label class="required">特殊定制说明</label>
                        <el-input v-model="row.configuration_snapshot.special_custom_description" size="small" type="textarea" :rows="2" placeholder="请详细填写特殊定制配置说明（必填）" />
                      </div>
                    </div>
                  </div>
                </div>

                <div class="detail-block drawing-subcard">
                  <div class="drawing-subcard-head">
                    <div class="drawing-head-left">
                      <i class="el-icon-folder-opened drawing-head-icon" />
                      <span class="block-title">本行设计图纸与技术资料</span>
                      <span class="count-pill">{{ files(row).length }} 份</span>
                    </div>
                    <div class="drawing-head-actions">
                      <el-select v-model="fileCategory" size="small" class="category-select">
                        <el-option label="设计图纸" value="设计图纸" />
                        <el-option label="客户图纸" value="客户图纸" />
                        <el-option label="技术协议" value="技术协议" />
                        <el-option label="配置说明" value="配置说明" />
                        <el-option label="其他技术附件" value="其他技术附件" />
                      </el-select>
                      <el-upload action="#" :auto-upload="false" :show-file-list="false" :on-change="addLineFile">
                        <el-button size="small" type="primary" icon="el-icon-upload2" class="btn-emerald-primary">上传图纸 / 文件</el-button>
                      </el-upload>
                    </div>
                  </div>
                  <div v-if="files(row).length" class="drawing-file-grid">
                    <div v-for="(file, fileIdx) in files(row)" :key="file.uid || fileIdx" class="drawing-file-card">
                      <div class="drawing-file-info">
                        <span class="drawing-ext-tag">{{ file.file_type || '图纸' }}</span>
                        <span class="drawing-file-name" :title="file.file_name">{{ file.file_name }}</span>
                        <span v-if="file.is_main" class="main-file-pill">主图纸</span>
                        <span class="drawing-file-ver">V{{ fileIdx + 1 }}.0</span>
                      </div>
                      <div class="drawing-file-ops">
                        <el-button v-if="file.can_preview === true" type="text" size="mini" @click.stop="previewAttachment(file)">预览</el-button>
                        <el-button v-if="file.can_download !== false" type="text" size="mini" @click.stop="downloadAttachment(file)">下载</el-button>
                        <el-button v-if="!file.is_main" type="text" size="mini" @click.stop="setMainFile(fileIdx)">设为主图</el-button>
                        <el-button v-if="file.can_delete === true" type="text" size="mini" class="danger-link" @click.stop="removeLineFile(fileIdx)">删除</el-button>
                      </div>
                    </div>
                  </div>
                  <div v-else class="drawing-empty-bar">
                    <span>暂无本行图纸或技术附件</span>
                  </div>
                </div>
              </div>
            </template>
          </el-table-column>

          <el-table-column label="#" width="44" align="center">
            <template slot-scope="{$index}">
              <span class="row-index">{{ $index + 1 }}</span>
            </template>
          </el-table-column>

          <el-table-column label="产品" min-width="150">
            <template slot-scope="{row}">
              <div class="cell-picker" :class="{ 'has-value': !!row.product_name }" title="点击选择或更换产品" @click="openProductPicker(row)">
                <div class="cell-main">{{ row.product_name || '选择产品' }}</div>
                <div v-if="row.product_code_snapshot" class="cell-sub">{{ row.product_code_snapshot }}</div>
              </div>
            </template>
          </el-table-column>

          <el-table-column label="规格（SKU）" min-width="150">
            <template slot-scope="{row}">
              <div class="cell-picker" :class="{ 'has-value': !!row.sku_name }" title="点击选择或更换规格" @click="openSkuPicker(row)">
                <div class="cell-main">{{ row.sku_name || '选择规格' }}</div>
                <div v-if="row.spec_text_snapshot" class="cell-sub">{{ row.spec_text_snapshot }}</div>
              </div>
            </template>
          </el-table-column>

          <el-table-column label="匹配物料" min-width="120">
            <template slot-scope="{row}">
              <div class="match-cell" :class="{ 'is-matched': row.item_match_status === 'matched' }" :title="row.item_name">
                <span class="match-dot" :class="row.item_match_status === 'matched' ? 'dot-green' : 'dot-gray'" />
                <span class="match-name">{{ row.item_name || '待系统匹配' }}</span>
              </div>
            </template>
          </el-table-column>

          <el-table-column label="数量" width="114">
            <template slot-scope="{row}">
              <div class="qty-cell">
                <el-input v-model.number="row.order_qty" size="mini" class="inline-input text-right" @input="recalc" />
                <span class="unit-text">{{ salesUnitName(row) }}</span>
              </div>
            </template>
          </el-table-column>

          <el-table-column label="单价" width="110">
            <template slot-scope="{row}">
              <div class="price-cell">
                <span class="unit-text">¥</span>
                <el-input v-model.number="row.unit_price" size="mini" class="inline-input text-right" @input="recalc" />
              </div>
            </template>
          </el-table-column>

          <el-table-column label="小计" width="106" align="right">
            <template slot-scope="{row}">
              <span class="amount-cell" :class="{ 'amount-deduction': row.line_type === 'deduction' }">{{ lineAmountText(row) }}</span>
            </template>
          </el-table-column>

          <el-table-column label="配置" width="110">
            <template slot-scope="{row}">
              <div class="chip-list">
                <span v-if="row.is_customized" class="chip chip-amber">定制</span>
                <span v-if="row.electric" class="chip chip-blue">{{ row.electric }}</span>
                <span v-if="row.need_pump === true" class="chip chip-green">原水泵</span>
                <span v-else-if="row.need_pump === false" class="chip chip-gray">无水泵</span>
                <span v-if="!row.is_customized && !row.electric && row.need_pump === null" class="muted-text">—</span>
              </div>
            </template>
          </el-table-column>

          <el-table-column label="履约" width="84" align="center">
            <template slot-scope="{row}">
              <el-tag size="mini" :type="lineTypeTag(row.line_type)" effect="light">{{ lineTypeText(row) }}</el-tag>
            </template>
          </el-table-column>

          <el-table-column label="BOM" width="80" align="center">
            <template slot-scope="{row}">
              <span v-if="!lineNeedsItem(row) || row.is_purchase_fulfilled" class="muted-text">无需</span>
              <el-tag v-else-if="row.bom_snapshot && row.bom_snapshot.name" size="mini" type="success" effect="plain">就绪</el-tag>
              <el-tag v-else size="mini" type="info" effect="plain">工单维护</el-tag>
            </template>
          </el-table-column>

          <el-table-column label="图纸" width="70" align="center">
            <template slot-scope="{row}">
              <el-upload class="line-file-upload" action="#" :auto-upload="false" :show-file-list="false" :on-change="file => addLineFileForRow(file, row)">
                <el-button size="mini" plain class="btn-file-count" :class="{ 'has-files': files(row).length > 0 }">
                  <i class="el-icon-paperclip" /> {{ files(row).length }}
                </el-button>
              </el-upload>
            </template>
          </el-table-column>

          <el-table-column label="操作" width="96" align="center">
            <template slot-scope="{$index, row}">
              <el-button type="text" size="mini" @click="toggleLineDetail(row)">{{ expandedLineKeys.includes(row.line_uuid) ? '收起' : '配置' }}</el-button>
              <el-button type="text" size="mini" class="danger-link" @click="removeLine($index)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>

        <div class="line-total-bar">
          <div class="total-chips">
            <span class="total-chip">总数量 <strong>{{ totalQty }}</strong></span>
            <span class="total-chip">需生产 <strong class="emerald-num">{{ productionLineCount }}</strong> 行</span>
            <span class="total-chip">外购备货 <strong>{{ purchaseLineCount }}</strong> 行</span>
            <span class="total-chip" :class="{ 'is-warning': form.is_customized }">{{ form.is_customized ? '包含定制' : '标准产品' }}</span>
            <span class="total-chip" :class="missingDataCount ? 'is-warning' : 'is-ready'">待补资料 <strong>{{ missingDataCount }}</strong> 行</span>
          </div>
          <div class="total-amount">订单总额 <strong>¥{{ money(totalAmount) }}</strong></div>
        </div>
      </section>

      <div v-if="!platformPaymentOnly" class="extra-grid">
        <section ref="外贸物流" class="panel logistics-card">
          <div class="panel-header">
            <div class="panel-title-wrap">
              <h3>物流{{ form.trade_type === 'foreign' ? '与外贸船务' : '与发货安排' }}</h3>
            </div>
          </div>
          <div class="field-grid grid-cols-3 compact">
            <div class="field span-2">
              <label>客户指定物流</label>
              <div class="inline-pair">
                <el-input :value="selectedCarrier && selectedCarrier.name" size="small" readonly placeholder="选填，发货时预填快递公司" suffix-icon="el-icon-search" @focus="carrierPickerVisible = true" @click.native="carrierPickerVisible = true" />
                <el-button v-if="form.carrier_id" size="small" type="text" @click="clearCarrier">清除</el-button>
              </div>
            </div>
            <div class="field">
              <label>是否客户自提</label>
              <div class="inline-switch-box">
                <span>工厂自提</span>
                <el-switch v-model="form.shipping_snapshot.is_self_pickup" active-color="#008b4b" />
              </div>
            </div>
            <div class="field span-full">
              <label>客户物流备注</label>
              <el-input v-model="form.shipping_snapshot.customer_logistics_note" size="small" placeholder="客户指定承运方式、运输注意事项" />
            </div>
            <template v-if="form.trade_type === 'foreign'">
              <div class="field span-full foreign-divider">
                <span>🌐 外贸船务与关务参数</span>
              </div>
              <div class="field"><label>件数（PCS）</label><el-input v-model="form.logistics_snapshot.pcs" size="small" placeholder="件数" /></div>
              <div class="field"><label>毛重（KG）</label><el-input v-model="form.logistics_snapshot.gw" size="small" placeholder="毛重" /></div>
              <div class="field"><label>体积（CBM）</label><el-input v-model="form.logistics_snapshot.vol" size="small" placeholder="体积" /></div>
              <div class="field"><label>截单时间（SI）</label><el-date-picker v-model="form.logistics_snapshot.si_date" size="small" value-format="yyyy-MM-dd" placeholder="选择日期" /></div>
              <div class="field"><label>截关时间（CY）</label><el-date-picker v-model="form.logistics_snapshot.cy_date" size="small" value-format="yyyy-MM-dd" placeholder="选择日期" /></div>
              <div class="field"><label>货好时间</label><el-date-picker v-model="form.logistics_snapshot.cargo_ready_date" size="small" value-format="yyyy-MM-dd" placeholder="Cargo Ready" /></div>
            </template>
          </div>
        </section>

        <section ref="订单共享" class="panel reminder-card">
          <div class="panel-header">
            <div class="panel-title-wrap">
              <h3>订单协同共享</h3>
            </div>
            <el-switch v-model="form.is_share" active-color="#008b4b" @change="handleShareToggle" />
          </div>
          <div class="share-card-body">
            <div class="field">
              <label>已授权共享人员 ({{ form.share_user.length }} 人)</label>
              <div class="share-line">
                <el-tag v-for="id in form.share_user" :key="id" size="small" closable class="share-user-pill" @close="removeShareUser(id)">
                  <span class="share-avatar">{{ String(shareUserName(id) || '销').slice(0, 1) }}</span>
                  {{ shareUserName(id) }}
                </el-tag>
                <el-button size="mini" plain icon="el-icon-plus" class="btn-add-share" :disabled="!form.is_share" @click="openShareDialog">选择人员</el-button>
              </div>
            </div>
          </div>
        </section>

        <section ref="合同附件" class="panel contract-card">
          <div class="panel-header">
            <div class="panel-title-wrap">
              <h3>合同附件 <span class="count-pill is-emerald">{{ contractFiles.length }} 个</span></h3>
            </div>
            <el-button size="mini" plain icon="el-icon-folder-opened" @click="contractDialogVisible=true">查看清单 ({{ contractFiles.length }})</el-button>
          </div>
          <div class="contract-upload-list">
            <el-upload action="#" :auto-upload="false" :show-file-list="false" :on-change="file => addContractFile(file, '合同图片 / PDF')">
              <button type="button" class="upload-tile">
                <span class="tile-icon"><i class="el-icon-document" /></span>
                <span>合同图片 / PDF</span>
                <small>jpg / png / pdf</small>
              </button>
            </el-upload>
            <el-upload action="#" :auto-upload="false" :show-file-list="false" :on-change="file => addContractFile(file, '客户技术协议')">
              <button type="button" class="upload-tile">
                <span class="tile-icon"><i class="el-icon-tickets" /></span>
                <span>客户技术协议</span>
                <small>技术协议文件</small>
              </button>
            </el-upload>
            <el-upload action="#" :auto-upload="false" :show-file-list="false" :on-change="file => addContractFile(file, '订单附件')">
              <button type="button" class="upload-tile">
                <span class="tile-icon"><i class="el-icon-paperclip" /></span>
                <span>订单附件</span>
                <small>报价单 / 沟通凭单</small>
              </button>
            </el-upload>
          </div>
          <div v-if="contractFiles.length" class="contract-inline-list">
            <div v-for="(file, idx) in contractFiles.slice(0, 2)" :key="idx" class="contract-inline-item">
              <span class="contract-inline-name" :title="file.file_name"><i class="el-icon-document" /> {{ file.file_name }}</span>
              <el-button v-if="file.can_preview === true" type="text" size="mini" @click="previewAttachment(file)">预览</el-button>
            </div>
          </div>
        </section>
      </div>
    </div>

    <system-record-picker :visible.sync="shareDialogVisible" :request="loadShareUsers" :value="selectedShareUsers" :categories="shareDepartmentTree" :config="{ title: '选择共享销售人员', placeholder: '销售人员姓名 / 账号' }" :columns="[{ prop: 'nickname', label: '销售人员姓名' }, { prop: 'username', label: '账号' }]" @confirm="selectShareUsers" />

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
import { erpDateTime } from '../../../utils/dateTime'
import cachedPageRoute from '@/utils/cachedPageRoute'
import ProductSkuPicker from '@/components/sales/ProductSkuPicker.vue'
import SalesOrderAttachmentPreviewDialog from '@/components/sales/SalesOrderAttachmentPreviewDialog.vue'
import OrderEditImpactDialog from '@/components/sales/OrderEditImpactDialog.vue'
import { legacyMediaUrl } from '@/utils/legacyMedia'
import CustomerPicker from '@/components/sales/CustomerPicker.vue'
import WarehouseManagerPicker from '@/components/master/WarehouseManagerPicker.vue'
import SalesCarrierPicker from '@/components/sales/SalesCarrierPicker.vue'
import SystemRecordPicker from '@/components/system/SystemRecordPicker.vue'
import { listUsers } from '@/api/erp/rbac'
import { getSalesOrder, saveSalesOrder, confirmSalesOrder, getSalesOrderOptions, uploadSalesOrderAttachment, deleteSalesOrderAttachment, downloadSalesOrderAttachment, previewSalesOrderEditImpact, submitSalesOrderEditImpact, listSalesOrderChangeProductionUnits, updateSalesOrderAgreedDeposit, updateSalesOrderPlatformPayment } from '@/api/erp/sales'
import { reserveForCreatePage, clearCreatePageReservation } from '@/utils/documentNumberReservation'
import { salesOrderOriginalNo } from '@/utils/salesOrderOriginalNo'

const withoutInternalFreight = source => {
  const result = { ...(source || {}) }
  delete result.carrier_fee
  delete result.actual_freight
  delete result.actual_freight_amount
  for (const field of ['shipping_snapshot', 'logistics_snapshot']) {
    if (result[field] && typeof result[field] === 'object') result[field] = withoutInternalFreight(result[field])
  }
  return result
}

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
  is_purchase_fulfilled: false,
  fulfillment_factor_preview: null,
  item_base_unit_name_preview: null,
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
  mixins: [cachedPageRoute],
  components: { ProductSkuPicker, CustomerPicker, WarehouseManagerPicker, SalesCarrierPicker, SystemRecordPicker, SalesOrderAttachmentPreviewDialog, OrderEditImpactDialog },
  data: () => ({
    selectedLine: null,
    salesUserPickerVisible: false,
    selectedSalesUser: null,
    carrierPickerVisible: false,
    selectedCarrier: null,
    expandedLineKeys: [],
    detailSections: [],
    fileCategory: '设计图纸',
    customerRawText: '',
    shareDialogVisible: false,
    shareDepartments: [],
    contractDialogVisible: false,
    previewVisible: false,
    previewFile: null,
    impactDialogVisible: false,
    impactReason: '',
    impactRechecking: false,
    productionUnitPicker: { visible: false, group: null, params: {}, config: {}, requiredCount: null, maxCount: null, value: [] },
    productionUnitColumns: [{ prop: 'serial_no', label: '设备 / 子件编号', width: 160 }, { prop: 'unit_no', label: '序号', width: 70 }, { prop: 'status_label', label: '当前进度', width: 120 }, { prop: 'disabled_reason', label: '不可选择原因', width: 160 }],
    impactSubmitting: false,
    impactSubmitError: '',
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
    fundingPolicyOptions: [],
    // 约定定金：未手动修改时按付款条件默认值显示和计算；修改后按销售填写的金额保存。
    depositEdited: false,
    agreedDeposit: null,
    loadedAgreedDeposit: null,
    platformRawOptions: [],
    savedPlatformPaymentBasis: null,
    checkedPlatformPaymentBasis: null,
    platformPaymentSaving: false,
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
      payment_method_id: '',
      funding_policy_id: null,
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
      shipping_snapshot: { is_self_pickup: false, customer_logistics_note: '' },
      logistics_snapshot: { express_no: '', pcs: '', gw: '', vol: '', si_date: '', cy_date: '', cargo_ready_date: '' },
      contract_attachment_snapshot: { files: [] },
      remark: '',
      lines: [emptyLine()]
    }
  }),
  computed: {
    requiredOpenSections() {
      return this.selectedLine ? this.requiredDetailSections(this.selectedLine) : []
    },
    isEdit() {
      return Boolean(this.pageRoute.params.id)
    },
    isConfirmedEdit() {
      return this.isEdit && this.editOrderMeta && this.editOrderMeta.order_status === 'confirmed'
    },
    platformPaymentOnly() {
      return this.isEdit && this.pageRoute.query.platform_payment === '1' && Boolean(this.editOrderMeta?.allowed_actions?.platform_payment_confirm)
    },
    canEditOrder() {
      if (this.platformPaymentOnly) return false
      return this.isEdit ? Boolean(this.editOrderMeta?.allowed_actions?.edit) : this.$can('sales_order.create')
    },
    returnPath() {
      return this.platformPaymentOnly ? `/sales/orders/${this.editOrderMeta.id}/detail` : '/sales/orders'
    },
    editStageTip() {
      if (!this.isConfirmedEdit) return null
      const actions = (this.editOrderMeta && this.editOrderMeta.allowed_actions) || {}
      if ((this.editOrderMeta.lines || []).some(line => Number(line.shipped_qty || 0) > 0)) {
        return { type: 'warning', icon: 'el-icon-lock', text: '本次只调整剩余未发部分，行数量为含已发部分的合计；已发商品、配置、单价和退货记录保留。修改商品、配置或单价时，未发余额会另成一行；金额按财务规则审核，有实际工时的编号由接单人处置。' }
      }
      if (actions.production_released) {
        return { type: 'warning', icon: 'el-icon-lock', text: '受影响的主、子工单均未开工时免生产审核；已有实际工时须审核，正式生效后由接单人处理原工单。金额及结算条件按财务规则审核。' }
      }
      return { type: 'success', icon: 'el-icon-s-flag', text: '核实期：尚未生产确认，金额及结算条件修改需财务审核；影响生产的修改按受影响主、子工单实际工时判断是否审核。' }
    },
    currentIndex() {
      const index = this.form.lines.indexOf(this.selectedLine)
      return index >= 0 ? index : 0
    },
    currentLineName() {
      return this.selectedLine ? (this.selectedLine.product_name || '请选择订单行') : '请选择订单行'
    },
    totalQty() {
      // 扣减项、费用不是商品件数，不计入总数量
      return this.form.lines.filter(line => !['deduction', 'fee'].includes(line.line_type)).reduce((sum, line) => sum + Number(line.order_qty || 0), 0)
    },
    totalAmount() {
      return this.form.lines.reduce((sum, line) => sum + this.lineAmount(line), 0)
    },
    platformPairChanged() {
      const order = this.editOrderMeta || {}
      return this.form.platform !== String(order.platform || '') || this.form.platform2 !== String(order.platform2 || '')
    },
    isOnlinePlatform() {
      if (!this.form.platform) return false
      const parent = this.platformOptions.find(item => String(item.id) === this.form.platform)
      const child = this.platformChildrenOptions.find(item => String(item.id) === this.form.platform2)
      const mode = (!this.platformPairChanged && this.editOrderMeta?.platform_payment_mode) || child?.payment_mode || parent?.payment_mode
      return mode ? mode === 'online' : !this.platformPairChanged && this.editOrderMeta?.channel_type_snapshot === 'online_platform'
    },
    savedPlatformPayment() {
      return Boolean(this.editOrderMeta?.funding_status?.platform_payment)
    },
    standalonePlatformPayment() {
      return this.isConfirmedEdit || this.platformPaymentOnly
    },
    canRecordPlatformPayment() {
      const actions = this.editOrderMeta?.allowed_actions
      return !this.isEdit || Boolean(actions?.platform_payment_recorder ?? actions?.platform_payment_confirm)
    },
    platformPaymentDisabledReason() {
      if (!this.canRecordPlatformPayment) return '由录单人核对'
      if (!this.platformPaymentOnly) {
        if (!this.isOnlinePlatform) return '先选线上成交平台'
        if (this.platformChildrenOptions.length && !this.form.platform2) return '先选子平台'
        if (!this.form.origin_order_no) return '先填原始单号'
        if (this.isConfirmedEdit && this.platformPaymentBasisChanged) return '订单修改生效后核对'
      }
      if (this.standalonePlatformPayment && (!this.editOrderMeta?.allowed_actions?.platform_payment_confirm ||
        !this.editOrderMeta?.funding_status?.platform_payment_token)) return '当前订单无法核对'
      return ''
    },
    canCheckPlatformPayment() {
      return !this.platformPaymentDisabledReason
    },
    platformPaymentFormAmount() {
      // 草稿按含税价核对，已保存订单的应收以服务端结算为准。
      const round = value => Math.sign(value) * Math.round(Math.abs(value) * 10000) / 10000
      const lines = this.form.lines.reduce((sum, line) => {
        const amount = round(round(Number(line.order_qty || 0)) * round(Number(line.unit_price || 0)) * Number(line.discount_rate ?? 1))
        let taxRate = Number(line.tax_rate || 0)
        if (taxRate > 1) taxRate /= 100
        const taxed = line.price_tax_mode === 'tax_exclusive' ? amount + round(amount * taxRate) : amount
        return sum + (line.line_type === 'deduction' ? -Math.abs(taxed) : taxed)
      }, 0)
      return round(lines + Number(this.form.freight_amount || 0))
    },
    platformPaymentBasis() {
      return JSON.stringify([this.form.platform, this.form.platform2, this.form.origin_order_no,
        this.form.customer_id, this.form.currency || 'CNY', this.platformPaymentFormAmount])
    },
    platformPaymentBasisChanged() {
      return this.platformPaymentBasis !== this.savedPlatformPaymentBasis
    },
    platformPaymentAmount() {
      const saved = this.editOrderMeta?.funding_status?.net_receivable_amount
      return saved == null || (!this.platformPaymentOnly && this.platformPaymentBasisChanged) ? this.platformPaymentFormAmount : saved
    },
    platformPaymentChecked: {
      get() { return Boolean(this.checkedPlatformPaymentBasis) && this.checkedPlatformPaymentBasis === this.platformPaymentSelectionBasis },
      set(checked) { this.checkedPlatformPaymentBasis = checked ? this.platformPaymentSelectionBasis : null }
    },
    platformPaymentSelectionBasis() {
      return this.platformPaymentOnly ? this.savedPlatformPaymentBasis : this.platformPaymentBasis
    },
    defaultFundingPolicy() {
      const fullPaymentPolicies = this.fundingPolicyOptions.filter(item => item.policy_type === 'full_prepay')
      return fullPaymentPolicies.find(item => item.policy_code === 'FULL_PREPAY') || fullPaymentPolicies[0] || null
    },
    effectiveFundingPolicy() {
      const id = this.form.funding_policy_id
      if (id) return this.fundingPolicyOptions.find(item => Number(item.id) === Number(id)) || null
      return this.defaultFundingPolicy
    },
    depositSupported() {
      const policy = this.effectiveFundingPolicy
      return Boolean(policy && ['deposit_production', 'installment_contract', 'custom_threshold'].includes(policy.policy_type))
    },
    defaultDepositAmount() {
      const policy = this.effectiveFundingPolicy
      if (!policy) return 0
      const receivable = this.totalAmount + Number(this.form.freight_amount || 0)
      const value = Number(policy.production_threshold_value || 0)
      const amount = policy.production_threshold_type === 'ratio' ? receivable * value : Math.min(value, receivable)
      return Math.round(amount * 100) / 100
    },
    defaultDepositText() {
      const policy = this.effectiveFundingPolicy || {}
      const rule = policy.production_threshold_type === 'ratio' ? `合同额 ${Number((Number(policy.production_threshold_value || 0) * 100).toFixed(2))}%` : '固定金额'
      return `${rule}，¥${this.money(this.defaultDepositAmount)}`
    },
    depositInput() {
      return this.depositEdited ? this.agreedDeposit : this.defaultDepositAmount
    },
    agreedDepositPayload() {
      return this.depositSupported && this.depositEdited ? this.agreedDeposit : null
    },
    productionLineCount() {
      return this.form.lines.filter(line => line.sku_id && this.lineNeedsItem(line) && !line.is_purchase_fulfilled).length
    },
    purchaseLineCount() {
      return this.form.lines.filter(line => line.is_purchase_fulfilled).length
    },
    missingDataCount() {
      return this.form.lines.filter(line => line.sku_id && (this.lineAttributeMessage(line) || this.lineCustomizationMessage(line) || (this.lineNeedsItem(line) && line.item_match_status !== 'matched'))).length
    },
    // 订单原选的平台已停用时不在可选项里，补一条只读项，保证编辑时仍显示原名称。
    platformAllOptions() {
      const rows = [...this.platformRawOptions]
      const meta = this.editOrderMeta || {}
      const has = id => rows.some(item => String(item.id) === String(id))
      if (meta.platform && !has(meta.platform)) rows.push({ id: meta.platform, pid: 0, name: `${meta.platform_name || meta.platform}（已停用）`, trade_type: meta.trade_type || 'domestic', stale: true })
      if (meta.platform2 && !has(meta.platform2)) rows.push({ id: meta.platform2, pid: meta.platform, name: `${meta.platform2_name || meta.platform2}（已停用）`, stale: true })
      return rows
    },
    // 主平台按贸易类型筛选：domestic 国内交易、foreign 国外交易。
    platformOptions() {
      const trade = this.form.trade_type || 'domestic'
      return this.platformAllOptions.filter(item => Number(item.pid || 0) === 0 && (!item.trade_type || item.trade_type === trade))
    },
    // 主平台下有子平台时展开第二级（如“线下转款”下的各线索来源），没有就不显示。
    platformChildrenOptions() {
      if (!this.form.platform) return []
      return this.platformAllOptions.filter(item => String(item.pid || 0) === String(this.form.platform))
    },
    validationState() {
      const requiredOk = Boolean(
        this.form.customer_name &&
        this.form.sales_user_legacy_id &&
        this.form.platform &&
        this.form.payment_method_id &&
        this.form.lines.length &&
        this.form.lines.every(line => line.product_id && line.sku_id && !this.linePriceMessage(line))
      )
      const lineOk = !this.form.lines.some(line => this.lineAttributeMessage(line) || this.lineCustomizationMessage(line))
      return { requiredOk, lineOk }
    },
    selectedShareUsers() {
      return this.form.share_user.map(id => this.shareUserOptions.find(user => String(user.id) === String(id)) || { id, nickname: String(id) })
    },
    shareDepartmentTree() {
      const nodes = this.shareDepartments.map(department => ({ ...department, category_name: department.name, children: [] }))
      const byId = new Map(nodes.map(department => [Number(department.id), department]))
      const roots = []
      nodes.forEach(department => {
        const parent = byId.get(Number(department.parent_id))
        if (parent && parent !== department) parent.children.push(department)
        else roots.push(department)
      })
      return roots
    },
    contractFiles() {
      return (this.form.contract_attachment_snapshot && this.form.contract_attachment_snapshot.files) || []
    }
  },
  async created() {
    await this.loadOptions()
    if (this.isEdit) await this.load()
    else {
      this.form.funding_policy_id = this.defaultFundingPolicy ? this.defaultFundingPolicy.id : null
      await Promise.all([this.reserveSalesOrderNumber(), this.defaultSalesUser()])
    }
    if (!this.selectedLine) this.selectedLine = this.form.lines[0]
    if (!this.isEdit && this.form.lines[0]) this.expandedLineKeys = [this.form.lines[0].line_uuid]
    if (process.env.NODE_ENV !== 'production' && this.pageRoute.query.impact_preview === 'master') this.impactDialogVisible = true
    this.$nextTick(() => window.setTimeout(() => this.focusRequestedField(), 300))
  },
  watch: {
    platformPaymentBasis(basis) {
      if (!this.standalonePlatformPayment && this.checkedPlatformPaymentBasis !== basis) this.checkedPlatformPaymentBasis = null
    },
    'form.trade_type'() {
      if (this.form.platform && !this.platformOptions.some(item => String(item.id) === String(this.form.platform))) {
        this.form.platform = ''
        this.form.platform2 = ''
        this.resetPlatformPaymentCheck()
      }
    },
    selectedLine(row) {
      if (!row || !this.expandedLineKeys.includes(row.line_uuid)) this.expandedLineKeys = []
    },
    requiredOpenSections(sections) {
      if (this.expandedLineKeys.length) this.detailSections = [...new Set([...this.detailSections, ...sections])]
    },
    'pageRoute.params.id': {
      async handler() {
        if (this.isEdit) {
          await this.load()
        } else {
          this.form = this.emptyForm()
          this.savedPlatformPaymentBasis = null
          this.platformPaymentChecked = false
          this.selectedSalesUser = null
          this.resetDeposit()
          this.loadedAgreedDeposit = null
          this.selectedLine = this.form.lines[0]
          this.expandedLineKeys = this.form.lines[0] ? [this.form.lines[0].line_uuid] : []
          await Promise.all([this.reserveSalesOrderNumber(), this.defaultSalesUser()])
        }
      }
    },
    'pageRoute.query.focus'() {
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
        payment_method_id: '',
        funding_policy_id: this.defaultFundingPolicy ? this.defaultFundingPolicy.id : null,
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
        const { data } = await getSalesOrderOptions({ include_carriers: 0 })
        this.payTypeOptions = data.pay_types || []
        this.fundingPolicyOptions = data.funding_policies || []
        this.platformRawOptions = data.platforms || []
      } catch (error) {
        this.$message.warning('销售订单基础选项加载失败，请检查新系统基础数据配置')
      }
    },
    async defaultSalesUser() {
      const form = this.form
      const token = localStorage.getItem('erp_token')
      if (this.isEdit || form.sales_user_legacy_id || !token) return
      try {
        const actor = JSON.parse(localStorage.getItem('erp_user') || '{}')
        const actorId = Number(actor.legacy_id || actor.id || 0)
        if (!actorId) return
        const { data } = await listUsers({ scope: 'sales', ids: [actorId], page: 1, per_page: 10 })
        if (this._isDestroyed || this.isEdit || this.form !== form || form.sales_user_legacy_id || localStorage.getItem('erp_token') !== token) return
        const currentActor = JSON.parse(localStorage.getItem('erp_user') || '{}')
        if (Number(currentActor.legacy_id || currentActor.id || 0) !== actorId) return
        const user = (data.data || []).find(row => Number(row.id) === actorId)
        if (user) this.applySalesUser(user)
      } catch (error) {
        if (!this._isDestroyed && !this.isEdit && this.form === form && !form.sales_user_legacy_id && localStorage.getItem('erp_token') === token) {
          this.$message.warning(error.userMessage || '销售人员信息加载失败，请手动选择')
        }
      }
    },
    async load() {
      try {
      const { data: responseOrder } = await getSalesOrder(this.pageRoute.params.id)
      const data = withoutInternalFreight(responseOrder)
      this.editOrderMeta = data
      if (!this.canEditOrder && !this.platformPaymentOnly) {
        this.$message.warning('当前订单仅可查看')
        this.$router.replace(`/sales/orders/${data.id}/detail`)
        return false
      }
      this.shareUserOptions = (data.share_users || []).map(user => ({ id: String(user.user_id), nickname: user.display_name }))
      const formData = { ...data }
      delete formData.external_order_no
      this.form = {
        ...withoutInternalFreight(this.form),
        ...formData,
        origin_order_no: salesOrderOriginalNo(data),
        trade_type: data.trade_type || 'domestic',
        platform: data.platform ? String(data.platform) : '',
        platform2: data.platform2 ? String(data.platform2) : '',
        platform_buyer_id: data.platform_buyer_id || '',
        customer_kind: data.customer_kind || (data.customer_snapshot && data.customer_snapshot.customer_kind) || 'individual',
        payment_method_id: data.payment_method_id ? String(data.payment_method_id) : '',
        funding_policy_id: data.funding_policy_id ? Number(data.funding_policy_id) : null,
        sales_user_legacy_id: data.sales_user_legacy_id ? String(data.sales_user_legacy_id) : '',
        created_by_legacy_id: data.created_by_legacy_id ? String(data.created_by_legacy_id) : '',
        is_share: Boolean(data.is_share),
        share_user: Array.isArray(data.share_user) ? data.share_user.map(String) : String(data.share_user || '').split(',').filter(Boolean),
        carrier_id: (data.default_carrier_id || data.carrier_id) ? String(data.default_carrier_id || data.carrier_id) : '',
        customer_snapshot: { remark: '', delivery_note: '', ...(data.customer_snapshot || {}) },
        shipping_snapshot: { is_self_pickup: false, customer_logistics_note: '', ...(data.shipping_snapshot || {}) },
        logistics_snapshot: { express_no: '', pcs: '', gw: '', vol: '', si_date: '', cy_date: '', cargo_ready_date: '', ...(data.logistics_snapshot || {}) },
        contract_attachment_snapshot: { files: this.mergeAttachmentFiles(this.parseContractAttachments(data.contract_attachments), (data.attachments || []).map(this.mapAttachment)) },
        lines: (data.lines || []).map(line => ({
          ...emptyLine(),
          ...line,
          order_qty: Number(line.order_qty),
          unit_price: line.line_type === 'deduction' ? Math.abs(Number(line.unit_price)) : Number(line.unit_price),
          item_name: line.item_name || '待系统匹配',
          product_code_snapshot: line.product_snapshot && line.product_snapshot.product_code,
          sku_code_snapshot: line.sku_snapshot && line.sku_snapshot.sku_code,
          spec_text_snapshot: line.sku_snapshot && line.sku_snapshot.spec_text,
          configuration_snapshot: line.configuration_snapshot || {},
          drawing_snapshot: line.drawing_snapshot || { files: [] },
          technical_attachment_snapshot: { files: this.mergeAttachmentFiles((line.technical_attachment_snapshot && line.technical_attachment_snapshot.files) || [], (line.attachments || []).map(this.mapAttachment)) }
        }))
      }
      this.savedPlatformPaymentBasis = this.platformPaymentBasis
      this.platformPaymentChecked = this.savedPlatformPayment
      const funding = data.funding_status || {}
      const agreed = funding.agreed_deposit_amount !== undefined && funding.agreed_deposit_amount !== null
        ? funding.agreed_deposit_amount
        : ((data.funding_policy_snapshot || {}).agreed_deposit_amount ?? null)
      this.depositEdited = agreed !== null && agreed !== ''
      this.agreedDeposit = this.depositEdited ? Number(agreed) : null
      this.loadedAgreedDeposit = this.depositEdited ? Number(agreed) : null
      const salesUser = data.sales_user
      this.selectedSalesUser = salesUser ? { id: salesUser.user_id, nickname: salesUser.display_name, department_names: salesUser.department_name } : (data.sales_user_legacy_id ? { id: Number(data.sales_user_legacy_id), nickname: `账号 ${data.sales_user_legacy_id}` } : null)
      this.selectedCarrier = this.form.carrier_id ? { carrier_option_id: Number(this.form.carrier_id), name: data.default_carrier_name_snapshot || (data.shipping_snapshot || {}).default_carrier_name || (data.shipping_snapshot || {}).carrier_name || '' } : null
      this.selectedLine = this.form.lines[0] || null
      return true
      } catch (error) {
        if (!this.isPageRouteActive) return false
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
      if (this.isConfirmedEdit && Number(removed.edit_protected_qty || 0) > 0) {
        if (Number(removed.order_qty) <= Number(removed.edit_protected_qty) + 0.00000001) return this.$message.warning('该行已发或已取消部分须保留，新增需求请另加一行')
        this.$message.info('本次仅移除该行剩余未发需求，已发部分及关联记录保留')
      }
      if (removed.id) this.deletedLineIds.push(removed.id)
      this.form.lines.splice(index, 1)
      if (this.selectedLine === removed) this.selectedLine = this.form.lines[0]
      this.syncHeaderFlags()
    },
    selectLine(row) {
      if (row) this.selectedLine = row
    },
    requiredDetailSections(row) {
      const sections = []
      if (/协议|图纸/.test(this.lineCustomizationMessage(row))) sections.push('attachments')
      return sections
    },
    handleLineExpand(row, expandedRows) {
      if (expandedRows.includes(row)) this.openLineDetail(row)
      else this.expandedLineKeys = []
    },
    showLineError(row, message, section) {
      this.openLineDetail(row, section)
      this.$message.error(`订单行 ${this.form.lines.indexOf(row) + 1}：${message}`)
    },
    toggleLineDetail(row, section) {
      if (!row) return
      if (this.expandedLineKeys.includes(row.line_uuid)) {
        this.expandedLineKeys = []
      } else {
        this.openLineDetail(row, section)
      }
    },
    openLineDetail(row, section) {
      if (!row) return
      this.selectedLine = row
      this.expandedLineKeys = [row.line_uuid]
      this.detailSections = [...new Set([...this.requiredDetailSections(row), ...(section ? [section] : [])])]
      this.$nextTick(() => {
        const table = this.$refs.lineTable
        if (table && table.bodyWrapper) table.bodyWrapper.scrollLeft = 0
        const detail = table && table.$el.querySelector('.order-line-detail')
        if (detail) detail.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
      })
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
      this.resetPlatformPaymentCheck()
    },
    resetPlatformPaymentCheck() {
      this.platformPaymentChecked = !this.platformPaymentBasisChanged && this.savedPlatformPayment
    },
    applySalesUser(user) {
      this.selectedSalesUser = user
      this.form.sales_user_legacy_id = String(user.id)
    },
    applyCarrier(carrier) {
      this.selectedCarrier = carrier
      this.form.carrier_id = String(carrier.carrier_option_id)
      this.$set(this.form.shipping_snapshot, 'default_carrier_id', carrier.carrier_option_id)
      this.$set(this.form.shipping_snapshot, 'default_carrier_name', carrier.name)
    },
    clearCarrier() {
      this.selectedCarrier = null
      this.form.carrier_id = ''
      this.form.default_carrier_name_snapshot = null
      this.$set(this.form.shipping_snapshot, 'default_carrier_id', null)
      this.$set(this.form.shipping_snapshot, 'default_carrier_name', null)
      this.$set(this.form.shipping_snapshot, 'carrier_name', null)
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
        line.item_snapshot = null
        line.item_match_snapshot = null
        line.is_purchase_fulfilled = false
        line.fulfillment_factor_preview = null
        line.fulfillment_factor_snapshot = null
        line.item_base_unit_name_preview = null
        line.item_base_unit_name_snapshot = null
        line.available_stock_hint = null
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
      // SKU 更换后重新读取默认物料，旧 SKU 的物料和单位预览不能继续展示。
      line.item_name = '待系统匹配'
      line.item_match_status = 'pending'
      line.item_snapshot = null
      line.item_match_snapshot = null
      line.is_purchase_fulfilled = false
      line.bom_snapshot = null
      line.routing_snapshot = null
      line.fulfillment_factor_preview = null
      line.fulfillment_factor_snapshot = null
      line.item_base_unit_name_preview = null
      line.item_base_unit_name_snapshot = null
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
      const factor = Number(row.fulfillment_factor_preview)
      if (this.lineNeedsItem(line) && row.default_item_id && Number.isFinite(factor) && factor > 0) {
        line.item_name = row.default_item_name
        line.item_match_status = 'matched'
        line.fulfillment_factor_preview = factor
        line.item_base_unit_name_preview = row.item_base_unit_name_preview
        line.is_purchase_fulfilled = row.is_purchase_fulfilled === true
      } else if (!this.lineNeedsItem(line)) {
        line.item_name = '无需 Item'
        line.item_match_status = 'not_required'
      }
      line.electric = ''
      line.need_pump = null
      this.syncHeaderFlags()
      this.$nextTick(() => this.openLineDetail(line))
    },
    salesUnitName(line) {
      const snapshot = line && line.sku_snapshot
      return (snapshot && (snapshot.sales_unit_symbol || snapshot.sales_unit_name)) || line.unit_name_snapshot || '件'
    },
    lineNeedsItem(line) {
      return line && !['service', 'no_delivery', 'fee', 'auxiliary', 'deduction'].includes(line.line_type)
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
      if (!this.isPageRouteActive) return
      const field = String(this.pageRoute.query.focus || '')
      if (!field) return
      let selector = '.order-basic-card'
      if (field === 'customer') selector = '.customer-card'
      else if (field === 'default_carrier_id') selector = '.logistics-card'
      else if (field === 'origin_order_no') selector = '#sales-original-order-no'
      else if (field === 'lines' || field.startsWith('lines.')) selector = '.order-lines'
      const lineMatch = field.match(/^lines\.(\d+)\./)
      if (lineMatch) {
        const line = this.form.lines[Number(lineMatch[1]) - 1]
        if (line) this.openLineDetail(line)
      }
      const target = this.$el.querySelector(selector)
      if (target) {
        target.scrollIntoView({ behavior: 'smooth', block: 'center' })
        if (field === 'origin_order_no') target.focus({ preventScroll: true })
        target.classList.add('precheck-focus')
        window.setTimeout(() => target.classList.remove('precheck-focus'), 8000)
      }
      this.$message.warning('请处理确认前检查中的首个阻塞项')
    },
    handleShareToggle(value) {
      if (!value) {
        this.form.share_user = []
      }
    },
    openShareDialog() {
      this.shareDialogVisible = true
    },
    async loadShareUsers(params) {
      const { category_id: departmentId, ...filters } = params
      const response = await listUsers({ ...filters, scope: 'sales_share', department_id: departmentId || undefined, include_departments: this.shareDepartments.length ? undefined : 1 })
      if (response.data.departments) this.shareDepartments = response.data.departments
      return response
    },
    selectShareUsers(users) {
      this.shareUserOptions = users
      this.form.share_user = users.map(user => String(user.id))
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
      this.openLineDetail(row, 'attachments')
      return this.addLineFile(file)
    },
    async addLineFile(file) {
      const row = this.selectedLine
      const fileCategory = this.fileCategory || '设计图纸'
      if (!row) return
      if (!this.form.draft_token && !(this.isEdit && this.form.id)) {
        this.$message.warning('请先保存销售订单草稿，再上传订单行附件')
        return false
      }
      if (!row.line_uuid) this.$set(row, 'line_uuid', `line-${Date.now()}-${Math.random().toString(16).slice(2)}`)
      const form = new FormData()
      form.append('file', file.raw)
      form.append('attachment_scope', 'line')
      form.append('attachment_type', this.attachmentTypeValue(fileCategory))
      if (this.isEdit && row.id) {
        form.append('sales_order_id', this.form.id)
        form.append('sales_order_line_id', row.id)
      } else {
        form.append('draft_token', this.form.draft_token)
        form.append('line_uuid', row.line_uuid)
      }
      let data
      try {
        const response = await uploadSalesOrderAttachment(form)
        data = response.data
      } catch (error) {
        this.$message.error(error.userMessage || '附件上传失败，请检查文件类型、内容和大小')
        return false
      }
      const uploaded = data.data
      const files = this.files(row)
      files.push({
        attachment_id: uploaded.id,
        uid: uploaded.id,
        file_name: uploaded.original_name,
        file_type: fileCategory,
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
      row.technical_attachment_snapshot = { files }
      row.drawing_snapshot = { files: files.filter(item => item.file_type === '设计图纸').length, main_file: files.find(item => item.is_main) || null }
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
      return erpDateTime(value, 19)
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
      if (sku.special_custom_agreement_required && !files.some(file => file.file_type === '技术协议')) return '特殊定制缺少客户技术协议'
      if (sku.special_custom_description_required && !String((line.configuration_snapshot || {}).special_custom_description || '').trim()) return '请填写特殊定制配置说明'
      return ''
    },
    applyImpactPreview(impact) {
      const summary = impact.approval_summary || {}
      const approvalLabels = { business: '业务审核', finance: '财务审核', fulfillment: '库存交付审核' }
      this.impactPreview = {
        level: impact.overall_risk_level || 'low',
        candidateVersion: `V${impact.candidate_version || 1}`,
        effectiveVersion: `V${impact.base_version || 0}`,
        effectSummary: impact.candidate_effect_summary || '',
        requiresApproval: Boolean(impact.requires_approval),
        canSubmit: impact.can_submit !== false,
        productionSelectionGroups: impact.production_selection_groups || [],
        productionSelectionErrors: impact.production_selection_errors || [],
        quantityAdjustments: impact.quantity_adjustments || [],
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
      if (this.impactPayload && impact.production_selection_groups) this.impactPayload = { ...this.impactPayload, production_unit_selections: this.productionSelectionPayload(impact.production_selection_groups) }
    },
    productionUnitLabel(unit) { return unit.serial_no || `第${unit.unit_no || unit.id}个` },
    productionUnitSelectable(unit) { return unit.selectable !== false },
    productionUnitRequired(unit) { return Boolean(unit.required || (this.productionUnitPicker.group?.required_unit_ids || []).includes(unit.id)) },
    productionSelectionKey(group) { return `${group.line_id}-${group.work_order_id}-${group.parent_unit_id || 0}` },
    productionSelectionPayload(groups) { return groups.map(group => ({ line_id: group.line_id, work_order_id: group.work_order_id, parent_unit_id: group.parent_unit_id || null, unit_ids: group.selected_unit_ids || [], expected_work_order_version: group.expected_work_order_version, selection_revision: group.selection_revision })) },
    loadChangeProductionUnits(params) { return listSalesOrderChangeProductionUnits(this.pageRoute.params.id, params) },
    chooseProductionUnits(group) {
      const lineCount = group.line_min_numbered_count !== undefined && group.line_max_numbered_count !== undefined ? `${group.line_min_numbered_count} 至 ${group.line_max_numbered_count}` : group.line_required_count
      const name = group.item_name + (group.parent_unit_serial_no ? ` · 所属主机 ${group.parent_unit_serial_no}` : '')
      this.productionUnitPicker = {
        visible: true, group,
        params: { line_id: group.line_id, work_order_id: group.work_order_id, parent_unit_id: group.parent_unit_id || undefined, expected_work_order_version: group.expected_work_order_version, selection_revision: group.selection_revision },
        config: { title: group.allow_count_change ? `${name} · 本工单最多选 ${group.max_count} 个，本商品编号合计选 ${lineCount} 个` : `${name} · 本次应选 ${group.required_count} 个`, placeholder: '搜索设备编号 / 子件编号' },
        requiredCount: group.allow_count_change ? null : Number(group.required_count), maxCount: group.allow_count_change ? Number(group.max_count) : null, value: group.selected_units || []
      }
    },
    async applyProductionUnitSelection(rows) {
      const selectedGroup = this.productionUnitPicker.group
      if (!selectedGroup || !this.impactPayload || this.impactRechecking) return
      const key = this.productionSelectionKey(selectedGroup)
      const pickedIds = rows.map(row => row.id)
      const removedParents = (selectedGroup.selected_unit_ids || []).filter(id => !pickedIds.includes(id))
      let groups = (this.impactPreview.productionSelectionGroups || []).map(group => this.productionSelectionKey(group) === key ? { ...group, selected_unit_ids: pickedIds, selected_units: rows } : group)
      // 主设备换了编号时，旧主设备下的子件选择必须一起撤下，交回后端生成新主设备所需的选择组。
      while (removedParents.length) {
        const parentId = removedParents.pop()
        groups = groups.filter(group => { if (group.parent_unit_id !== parentId) return true; removedParents.push(...(group.selected_unit_ids || [])); return false })
      }
      this.impactPreview.productionSelectionGroups = groups
      this.impactPreview.canSubmit = false
      this.impactPayload = { ...this.impactPayload, production_unit_selections: this.productionSelectionPayload(groups) }
      this.impactRechecking = true; this.impactSubmitError = ''
      try { const { data } = await previewSalesOrderEditImpact(this.pageRoute.params.id, this.impactPayload); this.applyImpactPreview(data.data) }
      catch (error) { this.impactSubmitError = error.userMessage || '所选编号的修改影响读取失败，请重新选择后重试' }
      finally { this.impactRechecking = false }
    },
    async submitImpactPreview() {
      if (this.impactSubmitting || this.impactRechecking) return
      this.impactSubmitError = ''
      if (!this.impactPayload) { this.impactDialogVisible = false; return }
      if (this.impactPreview.canSubmit === false) return this.$message.error('请按本次应选数量选择具体设备或子件编号')
      if (this.impactPreview.requiresApproval && !String(this.impactReason || '').trim()) return this.$message.error('请填写本次修改的变更原因')
      this.impactSubmitting = true
      try {
        const { data } = await submitSalesOrderEditImpact(this.pageRoute.params.id, { ...this.impactPayload, change_reason: this.impactReason })
        this.$message.success(data.message || '订单修改已处理')
        this.impactDialogVisible = false
        this.$router.push(`/sales/orders/${this.pageRoute.params.id}/detail`)
      } catch (error) {
        this.impactSubmitError = error.userMessage || '订单修改提交失败，请检查后重试'
      } finally { this.impactSubmitting = false }
    },
    async savePlatformPayment() {
      if (!this.standalonePlatformPayment || !this.canCheckPlatformPayment || this.platformPaymentSaving || this.platformPaymentChecked === this.savedPlatformPayment) return
      const order = this.editOrderMeta
      const confirmed = this.platformPaymentChecked
      this.platformPaymentSaving = true
      try {
        const { data } = await updateSalesOrderPlatformPayment(order.id, {
          confirmed,
          confirmation_token: order.funding_status.platform_payment_token
        })
        if (this.editOrderMeta === order && this.isPageRouteActive) {
          this.$set(order, 'funding_status', data.funding_status)
          this.checkedPlatformPaymentBasis = this.savedPlatformPayment ? this.savedPlatformPaymentBasis : null
          this.$message.success(data.message)
        }
      } catch (error) {
        this.$message.error(error.userMessage || '平台付款核对保存失败')
      } finally {
        this.platformPaymentSaving = false
      }
    },
    async save(andConfirm) {
      if (!this.canEditOrder || this.impactRechecking || this.impactSubmitting || this.platformPaymentSaving) return
      if (!this.form.customer_id) return this.$message.error('请通过客户选择框选择客户')
      if (!this.form.sales_user_legacy_id) return this.$message.error('请选择销售人员')
      if (!this.form.platform) return this.$message.error('请选择成交平台')
      if (this.platformChildrenOptions.length && !this.form.platform2) return this.$message.error('该成交平台下有子平台，请选择子平台')
      const originalOrderNo = this.form.origin_order_no.trim()
      if (this.isOnlinePlatform && !originalOrderNo) return this.$message.error('线上成交平台必须填写原始单号')
      const originalOrderNoTooLong = [...originalOrderNo].length > 120
      const savedOrder = this.editOrderMeta
      // 旧平台单号可能超过原始单号的长度限制，原样保留时继续读取历史值。
      const keepLegacyOriginalOrderNo = originalOrderNoTooLong && this.isEdit && savedOrder && !this.platformPairChanged &&
        !String(savedOrder.origin_order_no ?? '').trim() && originalOrderNo === salesOrderOriginalNo(savedOrder)
      if (originalOrderNoTooLong && !keepLegacyOriginalOrderNo) return this.$message.error('原始单号最多 120 个字符')
      if (!this.form.payment_method_id) return this.$message.error('请选择付款方式')
      if (this.form.lines.some(line => !line.sku_id)) return this.$message.error('订单行必须通过全局搜索选择SKU')
      if (this.form.lines.some(line => !line.product_id)) return this.$message.error('所选SKU缺少所属Product，请维护SKU主数据后重试')
      const badPrice = this.form.lines.find(line => this.linePriceMessage(line))
      if (badPrice) return this.$message.error(this.linePriceMessage(badPrice))
      if (andConfirm && this.totalAmount + Number(this.form.freight_amount || 0) < 0) return this.$message.error('扣减项（红包、优惠）合计不能超过商品金额，订单金额不能小于 0')
      const invalidAttribute = this.form.lines.find(line => this.lineAttributeMessage(line))
      if (andConfirm && invalidAttribute) return this.showLineError(invalidAttribute, this.lineAttributeMessage(invalidAttribute))
      const invalidCustomization = this.form.lines.find(line => this.lineCustomizationMessage(line))
      if (andConfirm && invalidCustomization) return this.showLineError(invalidCustomization, this.lineCustomizationMessage(invalidCustomization))
      this.syncHeaderFlags()
      const carrier = this.selectedCarrier
      const payload = {
        ...withoutInternalFreight(this.form),
        origin_order_no: originalOrderNo || null,
        deleted_line_ids: this.deletedLineIds,
        funding_policy_id: this.form.funding_policy_id || null,
        agreed_deposit_amount: this.agreedDepositPayload,
        share_user: this.form.is_share ? this.form.share_user : [],
        default_carrier_id: this.form.carrier_id || null,
        order_remark: this.form.remark || null,
        logistics_requirement: (this.form.shipping_snapshot || {}).customer_logistics_note || null,
        customer_remark: (this.form.customer_snapshot || {}).remark || null,
        contract_attachments: JSON.stringify(this.contractFiles),
        shipping_snapshot: {
          ...withoutInternalFreight(this.form.shipping_snapshot),
          carrier_id: this.form.carrier_id || null,
          carrier_name: carrier ? carrier.name : null
        },
        logistics_snapshot: withoutInternalFreight(this.form.logistics_snapshot),
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
      delete payload.created_by
      delete payload.created_by_legacy_id
      delete payload.creator
      delete payload.sales_channel_id
      delete payload.transaction_mode
      if (keepLegacyOriginalOrderNo) delete payload.origin_order_no
      const paymentChoiceChanged = this.platformPaymentBasisChanged
        ? this.platformPaymentChecked
        : this.platformPaymentChecked !== this.savedPlatformPayment
      if (!this.isConfirmedEdit && this.isOnlinePlatform && this.canRecordPlatformPayment && (!this.isEdit || paymentChoiceChanged)) {
        payload.platform_payment_confirmed = this.platformPaymentChecked
      }
      if (this.isConfirmedEdit) {
        payload.expected_version = this.editOrderMeta.business_version
        // 生产确认后的定金修改和其他字段一起审核，不在预览前先写正式订单。
        const cents = value => (value === null || value === undefined || value === '' ? null : Math.round(Number(value) * 100))
        const depositChanged = this.depositSupported && cents(this.agreedDepositPayload) !== cents(this.loadedAgreedDeposit)
        if (depositChanged && !(this.editOrderMeta.allowed_actions || {}).production_released) {
          if (!(this.editOrderMeta.allowed_actions || {}).edit_agreed_deposit) return this.$message.error('没有修改约定定金的权限')
          await updateSalesOrderAgreedDeposit(this.pageRoute.params.id, { agreed_deposit_amount: payload.agreed_deposit_amount })
          this.loadedAgreedDeposit = payload.agreed_deposit_amount
          for (const field of ['funding_policy_snapshot', 'payment_terms_snapshot']) {
            const snapshot = { ...(this.form[field] || {}) }
            if (field === 'payment_terms_snapshot' && !Object.prototype.hasOwnProperty.call(snapshot, 'policy_type')) continue
            if (payload.agreed_deposit_amount === null) delete snapshot.agreed_deposit_amount
            else snapshot.agreed_deposit_amount = payload.agreed_deposit_amount
            this.$set(this.form, field, snapshot)
            payload[field] = snapshot
          }
        }
        if (!depositChanged || !(this.editOrderMeta.allowed_actions || {}).production_released) delete payload.agreed_deposit_amount
        const { data } = await previewSalesOrderEditImpact(this.pageRoute.params.id, payload)
        const impact = data.data
        if (!impact || Number(impact.change_count || 0) === 0) {
          this.impactPayload = null
          this.impactDialogVisible = false
          if (depositChanged) {
            this.$message.success('约定定金已更新')
            this.$router.push(`/sales/orders/${this.pageRoute.params.id}/detail`)
            return
          }
          this.$message.info('未检测到需要保存的订单修改')
          return
        }
        this.impactPayload = payload
        this.impactReason = ''
        this.impactSubmitError = ''
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
        const response = await confirmSalesOrder(id)
        const result = response.data.data || response.data
        const blocked = (result.checks || []).find(item => item.status === 'blocked')
        if (!result.passed || blocked) {
          this.$message.warning((blocked && blocked.message) || result.message || '确认前检查未通过，请补齐资料后重试')
          const editPath = `/sales/orders/${id}/edit`
          // 草稿已保存；进入同一单据继续补齐，不能留在新增页再次创建。
          if (this.pageRoute.path !== editPath) this.$router.push({ path: editPath, query: blocked && blocked.field ? { focus: blocked.field } : {} })
          else if (await this.load()) {
            const lineMatch = blocked?.field?.match(/^lines\.(\d+)\./)
            if (lineMatch) {
              const line = this.form.lines[Number(lineMatch[1]) - 1]
              if (line) this.openLineDetail(line)
            }
          }
          return
        }
        this.$message.success('订单已确认，请核实后执行“生产确认”')
        this.$router.push(`/sales/orders/${id}/detail`)
      } else {
        this.$message.success('订单草稿已保存')
        const editPath = `/sales/orders/${id}/edit`
        if (this.pageRoute.path !== editPath) this.$router.push(editPath)
        else await this.load()
      }
    },
    changeDeposit(value) {
      this.depositEdited = true
      this.agreedDeposit = value === undefined || value === null ? 0 : value
    },
    resetDeposit() {
      this.depositEdited = false
      this.agreedDeposit = null
    },
    lineAmount(line) {
      const amount = Number(line.order_qty || 0) * Math.abs(Number(line.unit_price || 0))
      return line.line_type === 'deduction' ? -amount : Number(line.order_qty || 0) * Number(line.unit_price || 0)
    },
    lineAmountText(line) {
      const amount = this.lineAmount(line)
      if (line.line_type !== 'deduction' && line.product_id && !Number(line.unit_price || 0)) return '¥0.00 赠送'
      return `${amount < 0 ? '-' : ''}¥${this.money(Math.abs(amount))}`
    },
    linePriceMessage(line) {
      const price = Number(line.unit_price || 0)
      if (line.line_type === 'deduction') return price > 0 ? '' : `${line.sku_name || '扣减项'}：请填写扣减金额`
      return price >= 0 ? '' : `${line.sku_name || '订单行'}：单价不能小于 0，要减钱请用扣减项（如红包、优惠）`
    },
    money(value) {
      return Number(value || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    },
    lineTypeText(line) {
      if (line.is_purchase_fulfilled) return '外购备货'
      return ({ physical: '实物备货', service: '服务项目', no_delivery: '无需发货', auxiliary: '辅助录入', fee: '费用', deduction: '扣减项' })[line.line_type] || line.line_type
    },
    lineTypeTag(v) {
      return ({ physical: 'warning', service: 'info', no_delivery: 'success', auxiliary: '', deduction: 'danger' })[v] || ''
    }
  }
}
</script>

<style scoped>
.amount-cell.amount-deduction { color: #dc2626; }
/* ==========================================================================
   销售订单新增 / 编辑：高质感工业森林绿 (#008b4b) 四段式工作台
   ========================================================================== */
.sales-form-page {
  position: relative;
  z-index: 5;
  min-height: 100vh;
  background: #f4f7f9;
  color: #0f172a;
  max-width: 100%;
  overflow-x: hidden;
  padding: 12px 16px 28px;
  box-sizing: border-box;
}

/* 1. 顶部主控导航与实时 KPI 看板 */
.form-toolbar.hero-top-bar {
  position: sticky;
  top: 0;
  z-index: 20;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 12px 18px;
  min-height: 64px;
  padding: 12px 20px;
  margin-bottom: 14px;
  background: rgba(255, 255, 255, 0.98);
  backdrop-filter: blur(8px);
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04), 0 4px 12px rgba(15, 23, 42, 0.02);
  overflow: hidden;
}
.form-toolbar.hero-top-bar::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 3px;
  background: linear-gradient(90deg, #008b4b 0%, #10b981 55%, #059669 100%);
}
.toolbar-title {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}
.back-btn {
  width: 36px;
  height: 36px;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  background: #f8fafc;
  color: #475569;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 15px;
  transition: all 0.15s ease;
  flex-shrink: 0;
}
.back-btn:hover {
  color: #008b4b;
  background: #ecfdf5;
  border-color: #86efac;
}
.toolbar-title-meta {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}
.toolbar-crumb {
  font-size: 12px;
  color: #94a3b8;
  line-height: 1.3;
}
.toolbar-crumb span {
  color: #475569;
  font-weight: 500;
}
.toolbar-heading-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.main-title {
  font-size: 18px;
  font-weight: 800;
  color: #0f172a;
  letter-spacing: -0.01em;
  white-space: nowrap;
}
.order-no-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 2px 10px;
  border-radius: 6px;
  background: #ecfdf5;
  border: 1px solid #bbf7d0;
  color: #00763f;
  font-size: 12px;
  font-weight: 600;
  font-family: Menlo, Consolas, monospace;
}
.order-no-pill .pill-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: #008b4b;
}
.order-stage-pill {
  padding: 2px 8px;
  border-radius: 5px;
  background: #fffbeb;
  border: 1px solid #fde68a;
  color: #92400e;
  font-size: 12px;
  font-weight: 600;
}
.order-stage-pill.is-confirmed {
  background: #f0fdf4;
  border-color: #bbf7d0;
  color: #00763f;
}

/* 顶部嵌入式 KPI 胶囊条 */
.toolbar-kpi-capsule {
  display: flex;
  align-items: center;
  gap: 16px;
  margin-left: auto;
  padding: 6px 14px;
  border-radius: 8px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
}
.kpi-mini-item {
  display: flex;
  align-items: center;
  gap: 8px;
}
.kpi-mini-icon {
  width: 28px;
  height: 28px;
  border-radius: 6px;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  color: #64748b;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  font-weight: 700;
  flex-shrink: 0;
}
.kpi-mini-icon.is-emerald {
  background: #ecfdf5;
  border-color: #bbf7d0;
  color: #008b4b;
}
.kpi-mini-body {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.kpi-mini-label {
  font-size: 11px;
  color: #94a3b8;
  line-height: 1;
}
.kpi-mini-val {
  font-size: 12px;
  color: #334155;
  font-weight: 600;
  line-height: 1.2;
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}
.kpi-mini-val strong {
  color: #0f172a;
  font-weight: 700;
}
.summary-amount {
  color: #008b4b !important;
  font-size: 15px !important;
  font-weight: 800 !important;
}
.kpi-mini-divider {
  width: 1px;
  height: 24px;
  background: #e2e8f0;
}
.toolbar-actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}
.toolbar-actions .el-button + .el-button {
  margin-left: 0;
}

/* 主体布局 */
.form-body {
  display: grid;
  gap: 14px;
}
.stage-tip {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  padding: 10px 14px;
  border-radius: 8px;
  font-size: 13px;
  line-height: 1.5;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  color: #00763f;
}
.stage-tip i {
  font-size: 15px;
  color: #008b4b;
  flex-shrink: 0;
  margin-top: 2px;
}
.stage-tip--warning {
  background: #fffbeb;
  border-color: #fde68a;
  color: #92400e;
}
.stage-tip--warning i {
  color: #d97706;
}

.top-grid {
  display: grid;
  grid-template-columns: minmax(0, 7fr) minmax(0, 5fr);
  gap: 14px;
  align-items: stretch;
}
.extra-grid {
  display: grid;
  grid-template-columns: minmax(0, 5fr) minmax(0, 3fr) minmax(0, 4fr);
  gap: 14px;
  align-items: stretch;
}

/* 卡片容器 */
.panel {
  min-width: 0;
  height: 100%;
  box-sizing: border-box;
  display: flex;
  flex-direction: column;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 0;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03), 0 4px 10px rgba(15, 23, 42, 0.015);
  transition: border-color 0.2s ease, box-shadow 0.2s ease;
  overflow: hidden;
}
.panel:hover {
  border-color: #cbd5e1;
  box-shadow: 0 2px 6px rgba(15, 23, 42, 0.04), 0 8px 18px rgba(15, 23, 42, 0.02);
}
.panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 8px 12px;
  padding: 12px 18px;
  border-bottom: 1px solid #f1f5f9;
  background: linear-gradient(180deg, #ffffff 0%, #fafbfc 100%);
}
.panel-title-wrap {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  min-width: 0;
}
.panel-header h3 {
  margin: 0;
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  font-weight: 700;
  color: #0f172a;
}
.panel-header h3::before {
  content: '';
  width: 4px;
  height: 16px;
  border-radius: 2px;
  background: linear-gradient(180deg, #008b4b 0%, #10b981 100%);
  box-shadow: 0 1px 3px rgba(0, 139, 75, 0.25);
}
.panel-subtitle {
  font-size: 12px;
  color: #94a3b8;
  font-weight: 400;
}
.panel-rule-pill {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 8px;
  border-radius: 5px;
  background: #ecfdf5;
  border: 1px solid #bbf7d0;
  color: #00763f;
  font-size: 12px;
  font-weight: 500;
}
.panel-actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}
.panel-actions .el-button + .el-button {
  margin-left: 0;
}
.count-pill {
  padding: 1px 8px;
  line-height: 18px;
  border-radius: 10px;
  background: #f1f5f9;
  color: #64748b;
  font-size: 12px;
  font-weight: 600;
}
.count-pill.is-emerald {
  background: #ecfdf5;
  border: 1px solid #bbf7d0;
  color: #00763f;
}

/* 订单核心信息：双层语义分区 */
.basic-layers {
  flex: 1;
  padding: 14px 18px 16px;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  gap: 14px;
}
.layer-block {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.layer-recessed {
  flex: 1;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  padding: 12px 14px 14px;
  border-radius: 8px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
}
.layer-heading {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  font-weight: 700;
  color: #475569;
}
.layer-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: #008b4b;
}
.layer-dot.is-emerald {
  background: #10b981;
}
.layer-dot.is-amber {
  background: #f59e0b;
}
.layer-sub-hint {
  margin-left: auto;
  font-size: 12px;
  font-weight: 400;
  color: #94a3b8;
}

/* 客户与收货档案：置顶智能解析舱 */
.customer-body {
  flex: 1;
  padding: 14px 18px 16px;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  gap: 12px;
}
.customer-body > .field-grid {
  flex: 1;
  align-content: space-between;
}
.smart-parse-cabin {
  padding: 10px 12px 12px;
  border-radius: 8px;
  background: linear-gradient(135deg, #f0fdf4 0%, #f8fafc 65%, #ffffff 100%);
  border: 1px dashed #86efac;
}
.smart-parse-head {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 6px;
  flex-wrap: wrap;
}
.smart-sparkle {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 18px;
  height: 18px;
  border-radius: 4px;
  background: #008b4b;
  color: #ffffff;
  font-size: 11px;
  font-weight: 700;
}
.smart-title {
  font-size: 12px;
  font-weight: 700;
  color: #00763f;
}
.smart-sub {
  font-size: 12px;
  color: #64748b;
}

/* 字段网格与控件精修 */
.field-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 10px 12px;
}
.field-grid.grid-cols-4 {
  grid-template-columns: repeat(4, minmax(0, 1fr));
}
.field-grid.grid-cols-3 {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}
.field-grid.grid-cols-2 {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}
.field-grid.compact {
  gap: 8px 10px;
}
.logistics-card > .field-grid {
  padding: 14px 18px 16px;
}
.field {
  display: flex;
  flex-direction: column;
  gap: 5px;
  min-width: 0;
}
.field.span-2 {
  grid-column: span 2;
}
.field.span-full {
  grid-column: 1 / -1;
}
.field > label {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 12px;
  font-weight: 600;
  color: #475569;
  line-height: 1.35;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.field > label.required::before {
  content: '*';
  color: #ef4444;
  font-weight: 700;
  margin-right: 1px;
}
.field-label-tag {
  margin-left: auto;
  font-size: 11px;
  font-weight: 500;
  color: #008b4b;
}
.label-inline-btn {
  margin-left: auto !important;
  padding: 0 !important;
  font-size: 12px !important;
  color: #008b4b !important;
}
.field ::v-deep .el-select,
.field ::v-deep .el-date-editor.el-input,
.field ::v-deep .el-input-number {
  width: 100%;
}
.field ::v-deep .el-input-number .el-input__inner {
  text-align: left;
}
.field ::v-deep .el-input__inner,
.field ::v-deep .el-textarea__inner {
  border-radius: 6px;
  border-color: #cbd5e1;
  color: #0f172a;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.field ::v-deep .el-input__inner:hover,
.field ::v-deep .el-textarea__inner:hover {
  border-color: #94a3b8;
}
.field ::v-deep .el-input__inner:focus,
.field ::v-deep .el-textarea__inner:focus {
  border-color: #008b4b !important;
  box-shadow: 0 0 0 3px rgba(0, 139, 75, 0.12);
}
.erp-system-number ::v-deep .el-input__inner {
  font-family: Menlo, Consolas, monospace;
  font-weight: 600;
  color: #475569 !important;
  background: #f8fafc !important;
}

/* 分段式单选按钮组（内贸/外贸、个人/企业） */
.erp-segmented-radio {
  display: inline-flex;
  width: 100%;
  padding: 2px;
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  border-radius: 7px;
}
.erp-segmented-radio ::v-deep .el-radio-button {
  flex: 1;
}
.erp-segmented-radio ::v-deep .el-radio-button__inner {
  width: 100%;
  border: 0 !important;
  border-radius: 5px !important;
  background: transparent;
  color: #475569;
  font-size: 12px;
  font-weight: 600;
  padding: 6px 8px;
  box-shadow: none !important;
}
.erp-segmented-radio ::v-deep .el-radio-button__orig-radio:checked + .el-radio-button__inner {
  background: #ffffff !important;
  color: #008b4b !important;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.08) !important;
}

/* 成交平台与线上担保核对（同行内联，不另起一行） */
.platform-inline-row {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}
.platform-select-item {
  flex: 1 1 0;
  min-width: 96px;
}
.platform-payment-inline {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 32px;
  padding: 0 10px;
  border-radius: 6px;
  background: #ecfdf5;
  border: 1px solid #bbf7d0;
  white-space: nowrap;
  flex-shrink: 0;
  max-width: 100%;
  overflow: hidden;
}
.platform-payment-inline .el-checkbox {
  margin-right: 0;
}
.platform-payment-inline ::v-deep .el-checkbox__label {
  padding-left: 6px;
  font-size: 12px;
  font-weight: 700;
  color: #00763f;
}
.platform-payment-inline ::v-deep .el-checkbox.is-disabled .el-checkbox__label {
  color: #64748b;
}
.platform-payment-inline ::v-deep .el-checkbox__input.is-checked .el-checkbox__inner {
  background-color: #008b4b;
  border-color: #008b4b;
}
.platform-inline-meta {
  font-size: 12px;
  color: #475569;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.platform-amount {
  color: #008b4b;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}
.platform-save-btn {
  padding: 4px 8px !important;
}

/* 加急与延期卡片式开关 */
.switch-card-pair {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
}
.switch-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  height: 32px;
  padding: 0 10px;
  border-radius: 6px;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  transition: border-color 0.15s ease, background 0.15s ease;
  box-sizing: border-box;
}
.switch-card.is-urgent-on {
  background: #fef2f2;
  border-color: #fecaca;
}
.switch-card.is-delay-on {
  background: #ecfdf5;
  border-color: #bbf7d0;
}
.switch-card-title {
  font-size: 12px;
  font-weight: 700;
  color: #1e293b;
  white-space: nowrap;
}

.inline-pair {
  display: flex;
  gap: 8px;
  min-width: 0;
}
.inline-pair > * {
  flex: 1 1 0;
  min-width: 0;
}
.inline-pair > .el-button {
  flex: 0 0 auto;
}
.deposit-hint {
  color: #64748b;
  font-size: 12px;
  line-height: 18px;
}
.deposit-hint .el-button {
  padding: 0;
  margin-left: 4px;
  font-size: 12px;
}
.inline-switch-box {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  height: 32px;
  padding: 0 10px;
  border-radius: 6px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  font-size: 12px;
  font-weight: 600;
  color: #334155;
}
.input-addon {
  display: flex;
  align-items: stretch;
  min-width: 0;
}
.input-addon ::v-deep .el-input-number {
  flex: 1;
  min-width: 0;
}
.input-addon .addon {
  display: inline-flex;
  align-items: center;
  padding: 0 9px;
  background: #f1f5f9;
  border: 1px solid #cbd5e1;
  color: #475569;
  font-size: 12px;
  font-weight: 600;
  white-space: nowrap;
}
.input-addon .addon:first-child {
  border-right: 0;
  border-radius: 6px 0 0 6px;
}
.input-addon .addon:last-child {
  border-left: 0;
  border-radius: 0 6px 6px 0;
}
.input-addon .addon:first-child + ::v-deep .el-input-number .el-input__inner {
  border-radius: 0 6px 6px 0;
}
.input-addon ::v-deep .el-input-number:first-child .el-input__inner {
  border-radius: 6px 0 0 6px;
}
.muted-text {
  color: #94a3b8;
  font-size: 12px;
}
.danger-link {
  color: #ef4444 !important;
}
.danger-link:hover {
  color: #dc2626 !important;
}

/* 主题绿按钮 */
.btn-emerald-primary {
  background: linear-gradient(135deg, #008b4b 0%, #059669 100%) !important;
  border-color: #00763f !important;
  color: #ffffff !important;
  font-weight: 600;
  box-shadow: 0 1px 2px rgba(0, 139, 75, 0.2);
}
.btn-emerald-primary:hover {
  background: linear-gradient(135deg, #00763f 0%, #047857 100%) !important;
}
.btn-emerald-plain {
  color: #00763f !important;
  background: #ecfdf5 !important;
  border-color: #86efac !important;
  font-weight: 600;
}
.btn-emerald-plain:hover {
  background: #d1fae5 !important;
  border-color: #008b4b !important;
  color: #006334 !important;
}

/* 3. 中区：订单行明细表格 */
.sales-order-table {
  width: 100% !important;
  border-left: 0 !important;
  border-right: 0 !important;
  border-color: #e2e8f0 !important;
}
.sales-order-table ::v-deep th {
  background: #f8fafc !important;
  color: #475569 !important;
  font-size: 12px !important;
  font-weight: 700 !important;
  padding: 8px 0 !important;
}
.sales-order-table ::v-deep td {
  padding: 8px 0 !important;
  font-size: 12px !important;
}
.sales-order-table ::v-deep .cell {
  padding-left: 8px;
  padding-right: 8px;
}
.sales-order-table ::v-deep .el-table__row:hover > td {
  background-color: #f0fdf4 !important;
}
.sales-order-table ::v-deep .current-row > td {
  background-color: #ecfdf5 !important;
}
.sales-order-table ::v-deep .el-table__expanded-cell {
  padding: 0 !important;
  background: #f8fafc !important;
  border-bottom: 2px solid #bbf7d0 !important;
}
.sales-order-table ::v-deep .el-table__expanded-cell:hover {
  background: #f8fafc !important;
}
.row-index {
  font-size: 12px;
  font-weight: 700;
  color: #64748b;
}
.cell-picker {
  padding: 5px 8px;
  border: 1px dashed #cbd5e1;
  border-radius: 6px;
  background: #f8fafc;
  cursor: pointer;
  min-width: 0;
  transition: all 0.15s ease;
}
.cell-picker:hover {
  border-color: #008b4b;
  background: #f0fdf4;
}
.cell-picker.has-value {
  border-style: solid;
  border-color: transparent;
  background: #f8fafc;
}
.cell-picker.has-value:hover {
  background: #ffffff;
  border-color: #86efac;
  box-shadow: 0 1px 3px rgba(0, 139, 75, 0.08);
}
.cell-main {
  font-weight: 700;
  color: #0f172a;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.cell-picker:not(.has-value) .cell-main {
  color: #008b4b;
  font-weight: 600;
}
.cell-sub {
  font-size: 11px;
  color: #94a3b8;
  font-family: Menlo, Consolas, monospace;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  margin-top: 1px;
}
.match-cell {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  max-width: 100%;
  padding: 3px 8px;
  border-radius: 5px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
}
.match-cell.is-matched {
  background: #ecfdf5;
  border-color: #bbf7d0;
}
.match-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  flex-shrink: 0;
}
.dot-green {
  background: #10b981;
}
.dot-gray {
  background: #cbd5e1;
}
.match-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: #334155;
  font-weight: 500;
}
.qty-cell,
.price-cell {
  display: flex;
  align-items: center;
  gap: 4px;
}
.inline-input {
  flex: 1;
  min-width: 0;
}
.inline-input ::v-deep .el-input__inner {
  padding: 0 6px;
  height: 28px;
  line-height: 28px;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  border-radius: 5px;
}
.inline-input.text-right ::v-deep .el-input__inner {
  text-align: right;
}
.unit-text {
  font-size: 12px;
  color: #64748b;
  font-weight: 600;
  white-space: nowrap;
}
.amount-cell {
  font-weight: 800;
  color: #0f172a;
  font-variant-numeric: tabular-nums;
}
.chip-list {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}
.chip {
  padding: 1px 6px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 600;
  line-height: 18px;
  white-space: nowrap;
}
.chip-amber {
  background: #fef3c7;
  color: #92400e;
}
.chip-blue {
  background: #e0f2fe;
  color: #0369a1;
}
.chip-green {
  background: #d1fae5;
  color: #065f46;
}
.chip-gray {
  background: #f1f5f9;
  color: #64748b;
}
.btn-file-count {
  padding: 4px 8px !important;
  border-radius: 999px !important;
}
.btn-file-count.has-files {
  background: #f0f9ff !important;
  border-color: #bae6fd !important;
  color: #0369a1 !important;
  font-weight: 600;
}

/* 订单行展开区：嵌入式配置工作台 */
.order-lines {
  container-type: inline-size;
}
.order-line-detail {
  position: sticky;
  left: 0;
  width: calc(100cqw - 2px);
  box-sizing: border-box;
  padding: 14px 18px 16px;
  background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
  border-left: 3px solid #008b4b;
  display: grid;
  gap: 12px;
}
.line-detail-head {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  padding-bottom: 8px;
  border-bottom: 1px solid #e2e8f0;
}
.row-badge {
  padding: 2px 8px;
  border-radius: 4px;
  background: #008b4b;
  color: #ffffff;
  font-size: 12px;
  font-weight: 700;
}
.line-detail-title {
  font-size: 13px;
  font-weight: 700;
  color: #0f172a;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.line-detail-conversion {
  font-size: 12px;
  color: #64748b;
}
.line-detail-conversion strong {
  color: #0f172a;
}
.line-detail-amount {
  margin-left: auto;
  font-size: 13px;
  font-weight: 800;
  color: #008b4b;
  font-variant-numeric: tabular-nums;
}
.collapse-up-btn {
  color: #64748b !important;
  font-weight: 600;
}
.collapse-up-btn:hover {
  color: #008b4b !important;
}
.line-detail-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
  align-items: stretch;
}
.detail-block {
  min-width: 0;
  height: 100%;
  box-sizing: border-box;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 12px 14px;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.02);
  display: flex;
  flex-direction: column;
  justify-content: space-between;
}
.detail-block > .field-grid {
  flex: 1;
  align-content: space-between;
}
.block-title-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 10px;
  padding-bottom: 6px;
  border-bottom: 1px solid #f1f5f9;
}
.block-title {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  font-weight: 700;
  color: #1e293b;
}
.block-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: #008b4b;
}
.product-brief {
  display: flex;
  gap: 12px;
  min-width: 0;
}
.product-img {
  width: 64px;
  height: 64px;
  flex-shrink: 0;
  border-radius: 8px;
  border: 1px solid #e2e8f0;
  background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  color: #008b4b;
  font-size: 24px;
}
.product-img img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.product-brief-meta {
  min-width: 0;
  display: grid;
  gap: 4px;
  font-size: 12px;
  color: #334155;
}
.meta-line {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.meta-line span {
  color: #94a3b8;
  margin-right: 6px;
}
.meta-em {
  font-style: normal;
  font-weight: 600;
}
.meta-em.is-ok {
  color: #00763f;
}
.meta-em.is-info {
  color: #0284c7;
}
.meta-em.is-muted {
  color: #94a3b8;
}
.code-badge {
  display: inline-block;
  padding: 1px 6px;
  border-radius: 4px;
  background: #f1f5f9;
  color: #475569;
  font-family: Menlo, Consolas, monospace;
  font-size: 11px;
  font-weight: 600;
}
.block-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 12px;
  padding-top: 10px;
  border-top: 1px solid #f1f5f9;
}
.block-actions .el-button {
  flex: 1;
}
.block-actions .el-button + .el-button {
  margin-left: 0;
}
.tag-holder {
  height: 32px;
  display: flex;
  align-items: center;
}

/* 工作台底栏：本行设计图纸与技术资料子卡 */
.drawing-subcard {
  gap: 10px;
}
.drawing-subcard-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 8px 12px;
}
.drawing-head-left {
  display: flex;
  align-items: center;
  gap: 8px;
}
.drawing-head-icon {
  color: #008b4b;
  font-size: 15px;
}
.drawing-head-actions {
  display: flex;
  align-items: center;
  gap: 8px;
}
.category-select {
  width: 132px;
}
.drawing-file-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
}
.drawing-file-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 7px 12px;
  border-radius: 6px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  min-width: 0;
}
.drawing-file-info {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
  flex: 1;
}
.drawing-ext-tag {
  padding: 1px 6px;
  border-radius: 4px;
  background: #ecfdf5;
  border: 1px solid #bbf7d0;
  color: #00763f;
  font-size: 11px;
  font-weight: 700;
  flex-shrink: 0;
}
.drawing-file-name {
  font-size: 12px;
  font-weight: 600;
  color: #1e293b;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.main-file-pill {
  padding: 0 6px;
  border-radius: 4px;
  background: #d1fae5;
  color: #065f46;
  font-size: 11px;
  font-weight: 600;
  flex-shrink: 0;
}
.drawing-file-ver {
  font-size: 11px;
  color: #94a3b8;
  font-family: Menlo, Consolas, monospace;
  flex-shrink: 0;
}
.drawing-file-ops {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
}
.drawing-file-ops .el-button + .el-button {
  margin-left: 0;
}
.drawing-empty-bar {
  padding: 8px 12px;
  border-radius: 6px;
  background: #f8fafc;
  border: 1px dashed #cbd5e1;
  color: #94a3b8;
  font-size: 12px;
  text-align: center;
}

/* 订单行底部汇总条 */
.line-total-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 8px 16px;
  padding: 12px 18px;
  background: #f8fafc;
  border-top: 1px solid #e2e8f0;
}
.total-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.total-chip {
  padding: 3px 12px;
  border-radius: 999px;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  font-size: 12px;
  color: #64748b;
}
.total-chip strong {
  color: #0f172a;
  font-variant-numeric: tabular-nums;
}
.total-chip .emerald-num {
  color: #008b4b;
}
.total-chip.is-warning {
  background: #fffbeb;
  border-color: #fde68a;
  color: #92400e;
  font-weight: 600;
}
.total-chip.is-ready {
  background: #ecfdf5;
  border-color: #bbf7d0;
  color: #00763f;
  font-weight: 600;
}
.total-amount {
  font-size: 13px;
  font-weight: 600;
  color: #475569;
}
.total-amount strong {
  margin-left: 6px;
  font-size: 20px;
  font-weight: 800;
  color: #008b4b;
  font-variant-numeric: tabular-nums;
}

/* 4. 下区：外贸物流、订单协同共享、合同附件 */
.logistics-card > .field-grid {
  flex: 1;
  align-content: space-between;
}
.foreign-divider {
  padding-top: 8px;
  border-top: 1px dashed #e2e8f0;
  font-size: 12px;
  font-weight: 700;
  color: #00763f;
}
.share-card-body {
  flex: 1;
  padding: 14px 18px 16px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.share-card-body > .field {
  flex: 1;
  display: flex;
  flex-direction: column;
}
.share-line {
  flex: 1;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  align-content: center;
  gap: 8px;
  padding: 10px 12px;
  border-radius: 8px;
  background: #f8fafc;
  border: 1px dashed #cbd5e1;
}
.share-line .el-tag {
  margin: 0;
}
.share-user-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 28px;
  line-height: 26px;
  padding: 0 10px 0 6px;
  border-radius: 999px;
  background: #ecfdf5;
  border-color: #bbf7d0;
  color: #00763f;
  font-weight: 600;
}
.share-avatar {
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: #008b4b;
  color: #ffffff;
  font-size: 11px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
.btn-add-share {
  border-radius: 999px !important;
  border-style: dashed !important;
  border-color: #34d399 !important;
  color: #008b4b !important;
}
.btn-add-share:hover {
  background: #ecfdf5 !important;
}

.contract-upload-list {
  flex: 1;
  padding: 14px 18px 16px;
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
  align-items: stretch;
}
.contract-upload-list ::v-deep .el-upload {
  display: flex;
  width: 100%;
  height: 100%;
}
.upload-tile {
  width: 100%;
  height: 100%;
  padding: 12px 8px;
  border: 1px dashed #cbd5e1;
  border-radius: 8px;
  background: #f8fafc;
  cursor: pointer;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
  color: #1e293b;
  font-size: 12px;
  font-weight: 700;
  transition: all 0.15s ease;
  box-sizing: border-box;
}
.tile-icon {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  background: #ecfdf5;
  color: #008b4b;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  margin-bottom: 2px;
  transition: all 0.15s ease;
}
.upload-tile small {
  color: #94a3b8;
  font-size: 11px;
  font-weight: 400;
}
.upload-tile:hover {
  border-color: #008b4b;
  background: #f0fdf4;
}
.upload-tile:hover .tile-icon {
  background: #008b4b;
  color: #ffffff;
}
.contract-inline-list {
  padding: 0 18px 14px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.contract-inline-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 6px 10px;
  border-radius: 6px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  font-size: 12px;
}
.contract-inline-name {
  color: #334155;
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.contract-inline-name i {
  color: #008b4b;
  margin-right: 4px;
}

/* 预检焦点高亮 */
.precheck-focus {
  outline: 2px solid #f59e0b;
  outline-offset: 2px;
  transition: outline-color 0.25s ease;
}

/* 响应式断点 */
@media (max-width: 1180px) {
  .top-grid,
  .extra-grid {
    grid-template-columns: 1fr;
  }
}
@media (max-width: 780px) {
  .sales-form-page {
    padding: 8px 10px 20px;
  }
  .form-toolbar.hero-top-bar {
    padding: 10px 12px;
  }
  .toolbar-kpi-capsule {
    margin-left: 0;
    order: 3;
    width: 100%;
    justify-content: space-between;
    overflow-x: auto;
  }
  .toolbar-actions {
    margin-left: auto;
  }
  .field-grid,
  .field-grid.grid-cols-4,
  .field-grid.grid-cols-3,
  .field-grid.grid-cols-2,
  .field-grid.compact,
  .drawing-file-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .platform-inline-row {
    flex-wrap: wrap;
  }
  .line-detail-grid {
    grid-template-columns: 1fr;
  }
  .order-line-detail {
    padding: 10px;
  }
  .line-detail-amount {
    margin-left: 0;
  }
}
@media (max-width: 480px) {
  .field-grid,
  .field-grid.grid-cols-4,
  .field-grid.grid-cols-3,
  .field-grid.grid-cols-2,
  .field-grid.compact,
  .switch-card-pair,
  .contract-upload-list,
  .drawing-file-grid {
    grid-template-columns: 1fr;
  }
  .field.span-2 {
    grid-column: 1 / -1;
  }
  .toolbar-actions {
    width: 100%;
  }
  .toolbar-actions .el-button {
    flex: 1;
  }
  .total-amount {
    width: 100%;
    text-align: right;
  }
}
</style>
