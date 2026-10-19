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
      :reason.sync="impactReason"
      :submitting="impactSubmitting"
      :submit-error="impactSubmitError"
      @back="impactDialogVisible = false"
      @submit="submitImpactPreview"
    />

    <header class="form-toolbar">
      <div class="toolbar-title">
        <button type="button" class="back-btn" title="返回列表" @click="$router.push('/sales/orders')"><i class="el-icon-back" /></button>
        <span class="main-title">{{ isEdit ? '编辑订单' : '新增订单' }}</span>
        <span v-if="form.sales_order_no" class="order-no-pill">{{ form.sales_order_no }}</span>
      </div>
      <div class="toolbar-summary">
        <span class="summary-item">共 <strong>{{ form.lines.length }}</strong> 行</span>
        <span class="summary-item">合计 <strong class="summary-amount">¥{{ money(totalAmount) }}</strong></span>
      </div>
      <div class="toolbar-actions">
        <el-button size="small" @click="$router.push('/sales/orders')">返回列表</el-button>
        <el-button v-if="isEdit ? ($can('sales_order.edit_draft') || $can('sales_order.change')) : $can('sales_order.create')" size="small" @click="save(false)">{{ isConfirmedEdit ? '保存修改' : '保存草稿' }}</el-button>
        <el-button v-if="!isConfirmedEdit && ($can('sales_order.submit_confirmation') || $can('sales_order.formal_confirm'))" size="small" type="primary" class="btn-emerald-primary" @click="save(true)">保存并确认</el-button>
      </div>
    </header>

    <div class="form-body">
      <!-- 订单阶段提示：工单发布前可直接修改，发布后需提交审核 -->
      <div v-if="editStageTip" :class="['stage-tip', `stage-tip--${editStageTip.type}`]">
        <i :class="editStageTip.icon" />
        <span>{{ editStageTip.text }}</span>
      </div>

      <div class="top-grid">
        <section ref="基本信息" class="panel order-basic-card">
          <div class="panel-header"><h3>订单信息</h3></div>
          <div class="field-grid">
            <div class="field">
              <label>订单号</label>
              <el-input :value="form.sales_order_no || '保存后系统生成'" size="small" disabled />
            </div>
            <div class="field">
              <label>原始单号</label>
              <el-input v-model="form.origin_order_no" size="small" placeholder="平台订单号（选填）" />
            </div>
            <div class="field">
              <label class="required">下单时间</label>
              <el-date-picker v-model="form.order_time" type="datetime" size="small" value-format="yyyy-MM-dd HH:mm:ss" placeholder="选择下单时间" />
            </div>
            <div class="field">
              <label class="required">销售人员</label>
              <el-input :value="selectedSalesUser && (selectedSalesUser.nickname || selectedSalesUser.username)" size="small" readonly placeholder="请选择销售" suffix-icon="el-icon-search" @focus="salesUserPickerVisible = true" @click.native="salesUserPickerVisible = true" />
            </div>
            <div class="field">
              <label class="required">订单来源</label>
              <el-select v-model="form.order_source" size="small">
                <el-option label="销售订单" value="manual" />
                <el-option label="历史迁移" value="legacy_sync" />
                <el-option label="线索转单" value="crm_clue" />
              </el-select>
            </div>
            <div class="field">
              <label class="required">付款方式</label>
              <el-select v-model="form.payment_method_id" size="small" placeholder="请选择付款方式" clearable>
                <el-option v-for="item in payTypeOptions" :key="item.id" :label="item.name" :value="String(item.id)" />
              </el-select>
            </div>
            <div class="field span-2">
              <label class="required">成交平台</label>
              <div class="inline-pair">
                <el-select v-model="form.platform" size="small" placeholder="主平台" clearable @change="handlePlatformChange">
                  <el-option v-for="item in platformOptions" :key="item.id" :label="item.name" :value="String(item.id)" />
                </el-select>
                <el-select v-if="platformChildrenOptions.length" v-model="form.platform2" size="small" placeholder="子平台" clearable>
                  <el-option v-for="item in platformChildrenOptions" :key="item.id" :label="item.name" :value="String(item.id)" />
                </el-select>
              </div>
            </div>
            <div class="field">
              <label>贸易类型</label>
              <el-radio-group v-model="form.trade_type" size="small">
                <el-radio-button label="domestic">内贸</el-radio-button>
                <el-radio-button label="foreign">外贸</el-radio-button>
              </el-radio-group>
            </div>
            <div class="field">
              <label>要求交期</label>
              <el-date-picker v-model="form.required_delivery_date" size="small" value-format="yyyy-MM-dd" placeholder="选择日期" />
            </div>
            <div class="field">
              <label>加急 / 延期</label>
              <div class="switch-pair">
                <span class="switch-item">加急 <el-switch v-model="form.is_urgent" /></span>
                <span class="switch-item">延期 <el-switch v-model="form.is_delay" /></span>
              </div>
            </div>
            <div class="field">
              <label>延期发货日期</label>
              <el-date-picker v-model="form.delay_date" size="small" value-format="yyyy-MM-dd" :disabled="!form.is_delay" placeholder="开启延期后选择" />
            </div>
            <div class="field span-full">
              <label>订单备注</label>
              <el-input v-model="form.remark" type="textarea" :rows="2" maxlength="200" show-word-limit placeholder="订单备注（选填）" />
            </div>
          </div>
        </section>

        <section ref="客户与收货" class="panel customer-card">
          <div class="panel-header"><h3>客户与收货</h3></div>
          <div class="field-grid">
            <div class="field span-2">
              <label class="required">客户</label>
              <div class="inline-pair">
                <el-input v-model="form.customer_name" size="small" placeholder="点击右侧按钮选择客户" readonly />
                <el-button size="small" plain type="success" @click="$refs.customerPicker.open()">选择客户</el-button>
              </div>
            </div>
            <div class="field">
              <label>客户类型</label>
              <el-radio-group v-model="form.customer_kind" size="small">
                <el-radio-button label="individual">个人</el-radio-button>
                <el-radio-button label="enterprise">企业</el-radio-button>
              </el-radio-group>
            </div>
            <div class="field">
              <label>联系人</label>
              <el-input v-model="form.contact_name" size="small" placeholder="企业客户可填写" />
            </div>
            <div class="field">
              <label>联系电话</label>
              <el-input v-model="form.customer_phone" size="small" placeholder="联系方式" />
            </div>
            <div class="field">
              <label>平台买家 ID</label>
              <el-input v-model.trim="form.platform_buyer_id" size="small" placeholder="选填，用于个人客户查重" />
            </div>
            <div class="field span-full">
              <label>收货地址</label>
              <el-input v-model="form.full_address" type="textarea" :rows="2" placeholder="省 / 市 / 区 / 详细地址" />
            </div>
            <div class="field span-full">
              <label>智能识别</label>
              <el-input v-model="customerRawText" type="textarea" :rows="2" placeholder="粘贴姓名、电话、地址，自动识别并回填" @input="recognizeCustomerInfo" />
            </div>
          </div>
        </section>
      </div>

      <section
        ref="订单行"
        class="panel order-lines"
        :class="{ 'precheck-focus': $route.query.focus === 'lines' || String($route.query.focus || '').startsWith('lines.') }"
      >
        <div class="panel-header">
          <h3>订单行明细 <span class="count-pill">{{ form.lines.length }} 行</span></h3>
          <div class="panel-actions">
            <el-button size="small" type="primary" class="btn-emerald-primary" icon="el-icon-plus" @click="addLine">添加订单行</el-button>
            <el-button size="small" icon="el-icon-document-copy" @click="copyLine">复制当前行</el-button>
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
          <el-table-column type="expand" width="40" align="center">
            <template slot-scope="{row, $index}">
              <div class="order-line-detail">
                <div class="line-detail-head">
                  <span class="row-badge">行 {{ $index + 1 }}</span>
                  <strong class="line-detail-title">{{ row.product_name || '未选产品' }} · {{ row.sku_name || '未选规格' }}</strong>
                  <span class="line-detail-amount">小计 ¥{{ money(lineAmount(row)) }}</span>
                  <el-button type="text" size="mini" icon="el-icon-arrow-up" @click="expandedLineKeys = []">收起</el-button>
                </div>

                <div class="line-detail-grid">
                  <div class="detail-block">
                    <div class="block-title">产品与物料</div>
                    <div class="product-brief">
                      <div class="product-img">
                        <img v-if="row.product_snapshot && row.product_snapshot.image" :src="legacyMediaUrl(row.product_snapshot.image)" alt="产品图片">
                        <i v-else class="el-icon-picture-outline" />
                      </div>
                      <div class="product-brief-meta">
                        <div><span class="code-badge">{{ row.product_code_snapshot || '未分配编码' }}</span></div>
                        <div class="meta-line"><span>匹配物料</span>{{ row.item_name || '待系统匹配' }}</div>
                        <div class="meta-line"><span>规格</span>{{ row.spec_text_snapshot || '—' }}</div>
                      </div>
                    </div>
                    <div class="block-actions">
                      <el-button size="mini" plain icon="el-icon-edit-outline" @click="openProductPicker(row)">更换产品</el-button>
                      <el-button size="mini" plain icon="el-icon-edit-outline" @click="openSkuPicker(row)">更换规格</el-button>
                    </div>
                  </div>

                  <div class="detail-block">
                    <div class="block-title">数量与价格</div>
                    <div class="field-grid compact">
                      <div class="field">
                        <label class="required">销售数量</label>
                        <div class="input-addon">
                          <el-input-number v-model="row.order_qty" size="small" :min="0.0001" :precision="4" :controls="false" @change="recalc" />
                          <span class="addon">{{ salesUnitName(row) }}</span>
                        </div>
                      </div>
                      <div class="field">
                        <label class="required">销售单价</label>
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
                        <div><el-tag size="small" :type="lineTypeTag(row.line_type)" effect="light">{{ lineTypeText(row) }}</el-tag></div>
                      </div>
                      <div class="field span-full">
                        <label>行备注</label>
                        <el-input v-model="row.remark" size="small" placeholder="本行特殊包装、备货或交付要求" />
                      </div>
                    </div>
                  </div>

                  <div class="detail-block">
                    <div class="block-title">定制与电气</div>
                    <div class="field-grid compact">
                      <div v-if="lineCapabilities(row).allow_customized" class="field">
                        <label>普通定制</label>
                        <el-switch v-model="row.is_customized" active-color="#008b4b" @change="syncHeaderFlags" />
                      </div>
                      <div v-if="lineCapabilities(row).allow_special_customized" class="field">
                        <label>特殊定制</label>
                        <el-switch v-model="row.is_special_customized" active-color="#008b4b" @change="syncHeaderFlags" />
                      </div>
                      <div v-if="lineSupportsElectric(row)" class="field">
                        <label :class="{ required: lineElectricRequired(row) }">工作电压</label>
                        <el-select v-model="row.electric" size="small" clearable placeholder="请选择电压">
                          <el-option v-for="option in lineElectricOptions(row)" :key="option" :label="option" :value="option" />
                        </el-select>
                      </div>
                      <div v-if="lineSupportsNeedPump(row)" class="field">
                        <label :class="{ required: lineNeedPumpRequired(row) }">原水泵控制</label>
                        <el-select v-model="row.need_pump" size="small" clearable placeholder="请选择">
                          <el-option label="需要控制水泵" :value="true" />
                          <el-option label="不需要控制水泵" :value="false" />
                        </el-select>
                      </div>
                      <div v-if="lineCapabilities(row).delivery_inspection_required" class="field">
                        <label>交付前检验</label>
                        <div><el-tag size="small" type="warning">需要交付前检验</el-tag></div>
                      </div>
                      <div v-if="row.is_special_customized && lineCapabilities(row).special_custom_description_required" class="field span-full">
                        <label class="required">特殊定制说明</label>
                        <el-input v-model="row.configuration_snapshot.special_custom_description" size="small" type="textarea" :rows="2" placeholder="请详细填写特殊定制配置说明（必填）" />
                      </div>
                      <div v-if="!lineCapabilities(row).allow_customized && !lineCapabilities(row).allow_special_customized && !lineSupportsElectric(row) && !lineSupportsNeedPump(row) && !lineCapabilities(row).delivery_inspection_required" class="field span-full">
                        <span class="muted-text">该规格无需定制或电气配置</span>
                      </div>
                    </div>
                  </div>
                </div>

                <el-collapse v-model="detailSections" class="detail-collapse">
                  <el-collapse-item name="attachments">
                    <template slot="title">
                      <span class="collapse-title"><i class="el-icon-folder" /> 设计图纸与技术资料 <span class="count-pill">{{ files(row).length }} 份</span></span>
                    </template>
                    <div class="upload-bar">
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
                      <span class="muted-text">支持 PDF、CAD 导出图、图片、技术协议；特殊定制必传技术协议或图纸</span>
                    </div>
                    <el-table :data="files(row)" size="mini" border class="file-table" empty-text="尚未上传本行图纸或技术附件">
                      <el-table-column label="文件名称" min-width="200">
                        <template slot-scope="scope">
                          <span class="file-name" :title="scope.row.file_name">{{ scope.row.file_name }}</span>
                          <span v-if="scope.row.is_main" class="main-file-pill">主图纸</span>
                        </template>
                      </el-table-column>
                      <el-table-column label="版本" width="70" align="center">
                        <template slot-scope="scope">V{{ scope.$index + 1 }}.0</template>
                      </el-table-column>
                      <el-table-column prop="file_type" label="分类" width="110" />
                      <el-table-column label="操作" width="210" align="center">
                        <template slot-scope="scope">
                          <el-button v-if="scope.row.can_preview === true" type="text" size="mini" @click.stop="previewAttachment(scope.row)">预览</el-button>
                          <el-button v-if="scope.row.can_download !== false" type="text" size="mini" @click.stop="downloadAttachment(scope.row)">下载</el-button>
                          <el-button type="text" size="mini" @click.stop="setMainFile(scope.$index)">设为主图</el-button>
                          <el-button v-if="scope.row.can_delete === true" type="text" size="mini" class="danger-link" @click.stop="removeLineFile(scope.$index)">删除</el-button>
                        </template>
                      </el-table-column>
                    </el-table>
                  </el-collapse-item>

                  <el-collapse-item v-if="lineNeedsItem(row)" name="units">
                    <template slot="title">
                      <span class="collapse-title"><i class="el-icon-refresh" /> 单位换算</span>
                    </template>
                    <div class="conversion-row">
                      <span><em>库存物料</em>{{ row.item_name || '待系统匹配' }}</span>
                      <span><em>基本单位</em>{{ itemBaseUnitName(row) }}</span>
                      <span><em>换算关系</em>1 {{ salesUnitName(row) }} = {{ fulfillmentFactor(row) }} {{ itemBaseUnitName(row) }}</span>
                      <span><em>基本需求量</em>{{ itemBaseRequiredQty(row) }} {{ itemBaseUnitName(row) }}</span>
                    </div>
                  </el-collapse-item>
                </el-collapse>
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

          <el-table-column label="匹配物料" min-width="110">
            <template slot-scope="{row}">
              <div class="match-cell" :title="row.item_name">
                <span class="match-dot" :class="row.item_match_status === 'matched' ? 'dot-green' : 'dot-gray'" />
                <span class="match-name">{{ row.item_name || '待系统匹配' }}</span>
              </div>
            </template>
          </el-table-column>

          <el-table-column label="数量" width="112">
            <template slot-scope="{row}">
              <div class="qty-cell">
                <el-input v-model.number="row.order_qty" size="mini" class="inline-input text-right" @input="recalc" />
                <span class="unit-text">{{ salesUnitName(row) }}</span>
              </div>
            </template>
          </el-table-column>

          <el-table-column label="单价" width="100">
            <template slot-scope="{row}">
              <div class="price-cell">
                <span class="unit-text">¥</span>
                <el-input v-model.number="row.unit_price" size="mini" class="inline-input" @input="recalc" />
              </div>
            </template>
          </el-table-column>

          <el-table-column label="小计" width="104" align="right">
            <template slot-scope="{row}">
              <span class="amount-cell">¥{{ money(lineAmount(row)) }}</span>
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

          <el-table-column label="BOM" width="76" align="center">
            <template slot-scope="{row}">
              <span v-if="!lineNeedsItem(row) || row.is_purchase_fulfilled" class="muted-text">无需</span>
              <el-tag v-else-if="row.bom_snapshot && row.bom_snapshot.name" size="mini" type="success" effect="plain">就绪</el-tag>
              <el-tag v-else size="mini" type="info" effect="plain">工单维护</el-tag>
            </template>
          </el-table-column>

          <el-table-column label="图纸" width="80" align="center">
            <template slot-scope="{row}">
              <el-upload class="line-file-upload" action="#" :auto-upload="false" :show-file-list="false" :on-change="file => addLineFileForRow(file, row)">
                <el-button size="mini" plain class="btn-file-count"><i class="el-icon-paperclip" /> {{ files(row).length }}</el-button>
              </el-upload>
            </template>
          </el-table-column>

          <el-table-column label="操作" width="104" align="center" fixed="right">
            <template slot-scope="{$index, row}">
              <el-button type="text" size="mini" @click="toggleLineDetail(row)">{{ expandedLineKeys.includes(row.line_uuid) ? '收起' : '配置' }}</el-button>
              <el-button type="text" size="mini" class="danger-link" @click="removeLine($index)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>

        <div class="line-total-bar">
          <div class="total-chips">
            <span class="total-chip">总数量 <strong>{{ totalQty }}</strong></span>
            <span class="total-chip">需生产 <strong>{{ productionLineCount }}</strong> 行</span>
            <span class="total-chip">外购备货 <strong>{{ purchaseLineCount }}</strong> 行</span>
            <span class="total-chip" :class="{ 'is-warning': form.is_customized }">{{ form.is_customized ? '包含定制' : '标准产品' }}</span>
            <span class="total-chip" :class="{ 'is-warning': missingDataCount }">待补资料 <strong>{{ missingDataCount }}</strong> 行</span>
          </div>
          <div class="total-amount">订单总额 <strong>¥{{ money(totalAmount) }}</strong></div>
        </div>
      </section>

      <div class="extra-grid">
        <section ref="外贸物流" class="panel logistics-card">
          <div class="panel-header"><h3>物流{{ form.trade_type === 'foreign' ? '与外贸' : '' }}</h3></div>
          <div class="field-grid compact">
            <div class="field span-2">
              <label :class="{ required: form.lines.some(line => lineNeedsItem(line)) }">快递选择</label>
              <div class="inline-pair">
                <el-input :value="selectedCarrier && selectedCarrier.name" size="small" readonly placeholder="实物发货时选择快递" suffix-icon="el-icon-search" @focus="carrierPickerVisible = true" @click.native="carrierPickerVisible = true" />
                <el-button v-if="form.carrier_id" size="small" type="text" @click="clearCarrier">清除</el-button>
              </div>
            </div>
            <div class="field">
              <label>是否自提</label>
              <el-switch v-model="form.shipping_snapshot.is_self_pickup" />
            </div>
            <div class="field">
              <label>快递单号</label>
              <el-input v-model="form.logistics_snapshot.express_no" size="small" placeholder="发货后填写" />
            </div>
            <div class="field span-full">
              <label>客户物流备注</label>
              <el-input v-model="form.shipping_snapshot.customer_logistics_note" size="small" placeholder="客户指定承运方式、运输注意事项" />
            </div>
            <template v-if="form.trade_type === 'foreign'">
              <div class="field"><label>件数（PCS）</label><el-input v-model="form.logistics_snapshot.pcs" size="small" placeholder="件数" /></div>
              <div class="field"><label>毛重（KG）</label><el-input v-model="form.logistics_snapshot.gw" size="small" placeholder="毛重" /></div>
              <div class="field"><label>体积（CBM）</label><el-input v-model="form.logistics_snapshot.vol" size="small" placeholder="体积" /></div>
              <div class="field"><label>截单时间（SI）</label><el-date-picker v-model="form.logistics_snapshot.si_date" size="small" value-format="yyyy-MM-dd" placeholder="选择日期" /></div>
              <div class="field"><label>截关时间（CY）</label><el-date-picker v-model="form.logistics_snapshot.cy_date" size="small" value-format="yyyy-MM-dd" placeholder="选择日期" /></div>
              <div class="field"><label>货好时间</label><el-date-picker v-model="form.logistics_snapshot.cargo_ready_date" size="small" value-format="yyyy-MM-dd" placeholder="Cargo Ready" /></div>
            </template>
          </div>
        </section>

        <section ref="提醒与共享" class="panel reminder-card">
          <div class="panel-header"><h3>提醒与共享</h3></div>
          <div class="field-grid compact">
            <div class="field">
              <label>到期提醒</label>
              <el-switch v-model="remind.enabled" @change="handleRemindToggle" />
            </div>
            <div v-if="remind.enabled" class="field">
              <label>提前天数</label>
              <div class="input-addon">
                <el-input-number v-model="remind.days" size="small" :min="0" :controls="false" />
                <span class="addon">天</span>
              </div>
            </div>
            <div v-if="remind.enabled" class="field span-full">
              <label>提醒内容</label>
              <el-input v-model="remind.content" type="textarea" :rows="2" maxlength="100" show-word-limit placeholder="提醒内容（选填）" />
            </div>
            <div class="field">
              <label>共享订单</label>
              <el-switch v-model="form.is_share" @change="handleShareToggle" />
            </div>
            <div v-if="form.is_share" class="field span-full">
              <label>共享人员</label>
              <div class="share-line">
                <el-button size="mini" plain icon="el-icon-plus" @click="openShareDialog">选择人员</el-button>
                <el-tag v-for="id in form.share_user" :key="id" size="mini" closable @close="removeShareUser(id)">{{ shareUserName(id) }}</el-tag>
              </div>
            </div>
          </div>
        </section>

        <section ref="合同附件" class="panel contract-card">
          <div class="panel-header">
            <h3>合同附件 <span class="count-pill">{{ contractFiles.length }} 个</span></h3>
            <el-button size="mini" plain icon="el-icon-folder-opened" @click="contractDialogVisible=true">查看清单</el-button>
          </div>
          <div class="contract-upload-list">
            <el-upload action="#" :auto-upload="false" :show-file-list="false" :on-change="file => addContractFile(file, '合同图片 / PDF')">
              <button type="button" class="upload-tile"><i class="el-icon-upload2" /><span>合同图片 / PDF</span><small>jpg / png / pdf</small></button>
            </el-upload>
            <el-upload action="#" :auto-upload="false" :show-file-list="false" :on-change="file => addContractFile(file, '客户技术协议')">
              <button type="button" class="upload-tile"><i class="el-icon-upload2" /><span>客户技术协议</span><small>技术协议文件</small></button>
            </el-upload>
            <el-upload action="#" :auto-upload="false" :show-file-list="false" :on-change="file => addContractFile(file, '订单附件')">
              <button type="button" class="upload-tile"><i class="el-icon-upload2" /><span>订单附件</span><small>报价单 / 沟通凭单</small></button>
            </el-upload>
          </div>
        </section>
      </div>
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
import { erpDateTime } from '../../../utils/dateTime'
import cachedPageRoute from '@/utils/cachedPageRoute'
import ProductSkuPicker from '@/components/sales/ProductSkuPicker.vue'
import SalesOrderAttachmentPreviewDialog from '@/components/sales/SalesOrderAttachmentPreviewDialog.vue'
import OrderEditImpactDialog from '@/components/sales/OrderEditImpactDialog.vue'
import { legacyMediaUrl } from '@/utils/legacyMedia'
import CustomerPicker from '@/components/sales/CustomerPicker.vue'
import WarehouseManagerPicker from '@/components/master/WarehouseManagerPicker.vue'
import SalesCarrierPicker from '@/components/sales/SalesCarrierPicker.vue'
import { getSalesOrder, saveSalesOrder, confirmSalesOrder, getSalesOrderOptions, uploadSalesOrderAttachment, deleteSalesOrderAttachment, downloadSalesOrderAttachment, previewSalesOrderEditImpact, submitSalesOrderEditImpact } from '@/api/erp/sales'
import { reserveForCreatePage, clearCreatePageReservation } from '@/utils/documentNumberReservation'

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
  components: { ProductSkuPicker, CustomerPicker, WarehouseManagerPicker, SalesCarrierPicker, SalesOrderAttachmentPreviewDialog, OrderEditImpactDialog },
  data: () => ({
    selectedLine: null,
    salesUserPickerVisible: false,
    selectedSalesUser: null,
    carrierPickerVisible: false,
    selectedCarrier: null,
    expandedLineKeys: [],
    detailSections: [],
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
    platformRawOptions: [],
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
    editStageTip() {
      if (!this.isConfirmedEdit) return null
      const actions = (this.editOrderMeta && this.editOrderMeta.allowed_actions) || {}
      if (actions.production_released) {
        return { type: 'warning', icon: 'el-icon-lock', text: '工单已发布：本次修改不会直接生效。点击“保存修改”后将列出变更内容，填写变更原因并提交，由相关负责人审核通过后才会生效。' }
      }
      return { type: 'success', icon: 'el-icon-s-flag', text: '核实期：工单尚未发布，可直接修改，保存后立即生效并留痕。若改动了数量、SKU、备货方式等影响生产的内容，已生成的待发布工单会被撤销，需要重新执行“生产确认”。' }
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
      return this.form.lines.filter(line => line.sku_id && this.lineNeedsItem(line) && !line.is_purchase_fulfilled).length
    },
    purchaseLineCount() {
      return this.form.lines.filter(line => line.is_purchase_fulfilled).length
    },
    missingDataCount() {
      return this.form.lines.filter(line => line.sku_id && (this.lineAttributeMessage(line) || this.lineCustomizationMessage(line) || (this.lineNeedsItem(line) && line.item_match_status !== 'matched'))).length
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
        this.form.payment_method_id &&
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
    if (process.env.NODE_ENV !== 'production' && this.pageRoute.query.impact_preview === 'master') this.impactDialogVisible = true
    this.$nextTick(() => window.setTimeout(() => this.focusRequestedField(), 300))
  },
  watch: {
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
          this.selectedLine = this.form.lines[0]
          await this.reserveSalesOrderNumber()
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
        this.platformRawOptions = data.platforms || []
        this.shareUserOptions = data.share_users || []
      } catch (error) {
        this.$message.warning('销售订单基础选项加载失败，请检查新系统基础数据配置')
      }
    },
    async load() {
      try {
      const { data: responseOrder } = await getSalesOrder(this.pageRoute.params.id)
      const data = withoutInternalFreight(responseOrder)
      this.editOrderMeta = data
      this.form = {
        ...withoutInternalFreight(this.form),
        ...data,
        trade_type: data.trade_type || 'domestic',
        platform: data.platform ? String(data.platform) : '',
        platform2: data.platform2 ? String(data.platform2) : '',
        platform_buyer_id: data.platform_buyer_id || '',
        customer_kind: data.customer_kind || (data.customer_snapshot && data.customer_snapshot.customer_kind) || 'individual',
        payment_method_id: data.payment_method_id ? String(data.payment_method_id) : '',
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
    },
    applySalesUser(user) {
      this.selectedSalesUser = user
      this.form.sales_user_legacy_id = String(user.id)
      this.form.created_by = user.nickname || user.username
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
      if (!this.isPageRouteActive) return
      const field = String(this.pageRoute.query.focus || '')
      if (!field) return
      let selector = '.order-basic-card'
      if (field === 'customer') selector = '.customer-card'
      else if (field === 'default_carrier_id') selector = '.logistics-card'
      else if (field === 'lines' || field.startsWith('lines.')) selector = '.order-lines'
      const lineMatch = field.match(/^lines\.(\d+)\./)
      if (lineMatch) {
        const line = this.form.lines[Number(lineMatch[1]) - 1]
        if (line) this.openLineDetail(line)
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
      this.openLineDetail(row, 'attachments')
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
      const approvalLabels = { business: '业务审核', finance: '财务审核', fulfillment: '库存与交付复核' }
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
      if (this.impactSubmitting) return
      this.impactSubmitError = ''
      if (!this.impactPayload) { this.impactDialogVisible = false; return }
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
    async save(andConfirm) {
      if (!this.form.customer_id) return this.$message.error('请通过客户选择框选择客户')
      if (!this.form.sales_user_legacy_id) return this.$message.error('请选择销售人员')
      if (!this.form.platform) return this.$message.error('请选择成交平台')
      if (!this.form.payment_method_id) return this.$message.error('请选择付款方式')
      if (this.form.lines.some(line => !line.sku_id)) return this.$message.error('订单行必须通过全局搜索选择SKU')
      if (this.form.lines.some(line => !line.product_id)) return this.$message.error('所选SKU缺少所属Product，请维护SKU主数据后重试')
      if (andConfirm && this.form.lines.some(line => this.lineNeedsItem(line)) && !this.form.carrier_id) return this.$message.error('实物订单提交确认前必须先选择快递')
      if (andConfirm && this.form.lines.some(line => Number(line.unit_price || 0) <= 0)) return this.$message.error('提交确认前，订单行销售单价必须大于 0')
      const invalidAttribute = this.form.lines.find(line => this.lineAttributeMessage(line))
      if (andConfirm && invalidAttribute) return this.showLineError(invalidAttribute, this.lineAttributeMessage(invalidAttribute))
      const invalidCustomization = this.form.lines.find(line => this.lineCustomizationMessage(line))
      if (andConfirm && invalidCustomization) return this.showLineError(invalidCustomization, this.lineCustomizationMessage(invalidCustomization))
      this.syncHeaderFlags()
      const carrier = this.selectedCarrier
      const payload = {
        ...withoutInternalFreight(this.form),
        deleted_line_ids: this.deletedLineIds,
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
      if (this.isConfirmedEdit) {
        const { data } = await previewSalesOrderEditImpact(this.pageRoute.params.id, payload)
        const impact = data.data
        if (!impact || Number(impact.change_count || 0) === 0) {
          this.impactPayload = null
          this.impactDialogVisible = false
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
          else if (blocked && blocked.field) {
            const lineMatch = blocked.field.match(/^lines\.(\d+)\./)
            const line = lineMatch && this.form.lines[Number(lineMatch[1]) - 1]
            if (line) this.openLineDetail(line)
          }
          return
        }
        this.$message.success('订单已确认，请核实后执行“生产确认”')
        this.$router.push(`/sales/orders/${id}/detail`)
      } else {
        this.$message.success('订单草稿已保存')
        const editPath = `/sales/orders/${id}/edit`
        if (this.pageRoute.path !== editPath) {
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
    lineTypeText(line) {
      if (line.is_purchase_fulfilled) return '外购备货'
      return ({ physical: '实物备货', service: '服务项目', no_delivery: '无需发货', auxiliary: '辅助录入', fee: '费用' })[line.line_type] || line.line_type
    },
    lineTypeTag(v) {
      return ({ physical: 'warning', service: 'info', no_delivery: 'success', auxiliary: '' })[v] || ''
    }
  }
}
</script>

<style scoped>
/* ==========================================================================
   销售订单新增 / 编辑：紧凑、自适应、结构清晰
   ========================================================================== */
.sales-form-page {
  position: relative;
  z-index: 5;
  min-height: 100vh;
  background: #f5f7fa;
  color: #0f172a;
  max-width: 100%;
  overflow-x: hidden;
}

/* 吸顶工具栏 */
.form-toolbar {
  position: sticky;
  top: 0;
  z-index: 20;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px 16px;
  min-height: 52px;
  padding: 8px 20px;
  background: rgba(255, 255, 255, 0.96);
  backdrop-filter: blur(8px);
  border-bottom: 1px solid #e2e8f0;
  box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
}
.toolbar-title {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
}
.back-btn {
  width: 30px;
  height: 30px;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  background: #fff;
  color: #475569;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
.back-btn:hover { color: #008b4b; border-color: #86efac; }
.main-title { font-size: 16px; font-weight: 700; white-space: nowrap; }
.order-no-pill {
  padding: 2px 8px;
  border-radius: 10px;
  background: #ecfdf5;
  color: #00763f;
  font-size: 12px;
  font-family: Menlo, Consolas, monospace;
}
.toolbar-summary {
  display: flex;
  align-items: center;
  gap: 14px;
  margin-left: auto;
  font-size: 13px;
  color: #64748b;
  white-space: nowrap;
}
.toolbar-summary strong { color: #0f172a; }
.summary-amount { color: #008b4b !important; font-size: 15px; }
.toolbar-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.toolbar-actions .el-button + .el-button { margin-left: 0; }

/* 主体 */
.form-body {
  padding: 14px 20px 24px;
  display: grid;
  gap: 12px;
}
.stage-tip { display: flex; align-items: flex-start; gap: 8px; padding: 8px 12px; border-radius: 6px; font-size: 13px; line-height: 1.5; background: #f0fdf4; border: 1px solid #bbf7d0; color: #00763f; }
.stage-tip i { font-size: 15px; color: #008b4b; flex-shrink: 0; margin-top: 2px; }
.stage-tip--warning { background: #fffbeb; border-color: #fde68a; color: #92400e; }
.stage-tip--warning i { color: #d97706; }

.top-grid {
  display: grid;
  grid-template-columns: minmax(0, 3fr) minmax(0, 2fr);
  gap: 12px;
  align-items: start;
}
.extra-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
  gap: 12px;
  align-items: start;
}

/* 卡片 */
.panel {
  min-width: 0;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 12px 14px 14px;
}
.panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 10px;
  padding-bottom: 8px;
  border-bottom: 1px solid #f1f5f9;
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
  width: 3px;
  height: 14px;
  border-radius: 2px;
  background: #008b4b;
}
.panel-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.panel-actions .el-button + .el-button { margin-left: 0; }
.count-pill {
  padding: 1px 8px;
  border-radius: 10px;
  background: #f1f5f9;
  color: #64748b;
  font-size: 12px;
  font-weight: 500;
}

/* 字段网格 */
.field-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
  gap: 10px 12px;
}
.field-grid.compact {
  grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
  gap: 8px 10px;
}
.field { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.field.span-2 { grid-column: span 2; }
.field.span-full { grid-column: 1 / -1; }
.field > label {
  font-size: 12px;
  color: #64748b;
  line-height: 1.4;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.field > label.required::before { content: '*'; color: #ef4444; margin-right: 3px; }
.field ::v-deep .el-select,
.field ::v-deep .el-date-editor.el-input,
.field ::v-deep .el-input-number { width: 100%; }
.field ::v-deep .el-input-number .el-input__inner { text-align: left; }
.inline-pair { display: flex; gap: 8px; min-width: 0; }
.inline-pair > * { flex: 1 1 0; min-width: 0; }
.inline-pair > .el-button { flex: 0 0 auto; }
.switch-pair { display: flex; align-items: center; gap: 16px; height: 32px; }
.switch-item { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: #334155; }
.input-addon { display: flex; align-items: stretch; min-width: 0; }
.input-addon ::v-deep .el-input-number { flex: 1; min-width: 0; }
.input-addon .addon {
  display: inline-flex;
  align-items: center;
  padding: 0 8px;
  background: #f8fafc;
  border: 1px solid #dcdfe6;
  color: #64748b;
  font-size: 12px;
  white-space: nowrap;
}
.input-addon .addon:first-child { border-right: 0; border-radius: 4px 0 0 4px; }
.input-addon .addon:last-child { border-left: 0; border-radius: 0 4px 4px 0; }
.input-addon .addon:first-child + ::v-deep .el-input-number .el-input__inner { border-radius: 0 4px 4px 0; }
.input-addon ::v-deep .el-input-number:first-child .el-input__inner { border-radius: 4px 0 0 4px; }
.muted-text { color: #94a3b8; font-size: 12px; }
.danger-link { color: #ef4444 !important; }
.danger-link:hover { color: #dc2626 !important; }

/* 按钮 */
.btn-emerald-primary {
  background: linear-gradient(135deg, #008b4b 0%, #00763f 100%) !important;
  border-color: #00763f !important;
  color: #fff !important;
  font-weight: 600;
}
.btn-emerald-primary:hover { background: linear-gradient(135deg, #009d55 0%, #008b4b 100%) !important; }

/* 订单行表格 */
.sales-order-table { width: 100% !important; border-color: #e2e8f0 !important; }
.sales-order-table ::v-deep th {
  background: #f8fafc !important;
  color: #475569 !important;
  font-size: 12px !important;
  font-weight: 700 !important;
  padding: 6px 0 !important;
}
.sales-order-table ::v-deep td { padding: 6px 0 !important; font-size: 12px !important; }
.sales-order-table ::v-deep .cell { padding-left: 8px; padding-right: 8px; }
.sales-order-table ::v-deep .el-table__row:hover > td { background-color: #f0fdf4 !important; }
.sales-order-table ::v-deep .current-row > td { background-color: #ecfdf5 !important; }
.sales-order-table ::v-deep .el-table__expanded-cell {
  padding: 0 !important;
  background: #f8fafc !important;
  border-bottom: 2px solid #d1fae5 !important;
}
.sales-order-table ::v-deep .el-table__expanded-cell:hover { background: #f8fafc !important; }
.row-index { font-size: 12px; font-weight: 600; color: #64748b; }
.cell-picker {
  padding: 3px 6px;
  border: 1px dashed #cbd5e1;
  border-radius: 4px;
  cursor: pointer;
  min-width: 0;
}
.cell-picker:hover { border-color: #008b4b; background: #f0fdf4; }
.cell-picker.has-value { border-style: solid; border-color: transparent; }
.cell-picker.has-value:hover { border-color: #86efac; }
.cell-main { font-weight: 600; color: #0f172a; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cell-picker:not(.has-value) .cell-main { color: #008b4b; font-weight: 500; }
.cell-sub { font-size: 11px; color: #94a3b8; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.match-cell { display: flex; align-items: center; gap: 6px; min-width: 0; }
.match-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
.dot-green { background: #10b981; }
.dot-gray { background: #cbd5e1; }
.match-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #475569; }
.qty-cell,
.price-cell { display: flex; align-items: center; gap: 4px; }
.inline-input { flex: 1; min-width: 0; }
.inline-input ::v-deep .el-input__inner { padding: 0 6px; height: 26px; line-height: 26px; }
.inline-input.text-right ::v-deep .el-input__inner { text-align: right; }
.unit-text { font-size: 11px; color: #94a3b8; white-space: nowrap; }
.amount-cell { font-weight: 700; color: #0f172a; font-variant-numeric: tabular-nums; }
.chip-list { display: flex; flex-wrap: wrap; gap: 4px; }
.chip { padding: 0 6px; border-radius: 3px; font-size: 11px; line-height: 18px; white-space: nowrap; }
.chip-amber { background: #fef3c7; color: #92400e; }
.chip-blue { background: #dbeafe; color: #1e40af; }
.chip-green { background: #d1fae5; color: #065f46; }
.chip-gray { background: #f1f5f9; color: #64748b; }
.btn-file-count { padding: 4px 8px !important; }

/* 订单行展开区 */
.order-line-detail { padding: 10px 14px 12px; display: grid; gap: 10px; }
.line-detail-head { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.row-badge {
  padding: 1px 8px;
  border-radius: 4px;
  background: #008b4b;
  color: #fff;
  font-size: 12px;
  font-weight: 600;
}
.line-detail-title { font-size: 13px; color: #0f172a; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.line-detail-amount { margin-left: auto; font-size: 13px; font-weight: 700; color: #008b4b; }
.line-detail-grid {
  display: grid;
  grid-template-columns: minmax(240px, 1fr) minmax(280px, 1.2fr) minmax(280px, 1.2fr);
  gap: 10px;
}
.detail-block {
  min-width: 0;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 10px 12px;
}
.block-title { font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 8px; }
.product-brief { display: flex; gap: 10px; min-width: 0; }
.product-img {
  width: 64px;
  height: 64px;
  flex-shrink: 0;
  border-radius: 6px;
  border: 1px solid #e2e8f0;
  background: #f8fafc;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  color: #cbd5e1;
  font-size: 22px;
}
.product-img img { width: 100%; height: 100%; object-fit: cover; }
.product-brief-meta { min-width: 0; display: grid; gap: 4px; font-size: 12px; color: #334155; }
.meta-line { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.meta-line span { color: #94a3b8; margin-right: 6px; }
.code-badge {
  display: inline-block;
  padding: 0 6px;
  border-radius: 3px;
  background: #f1f5f9;
  color: #475569;
  font-family: Menlo, Consolas, monospace;
  font-size: 11px;
}
.block-actions { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
.block-actions .el-button + .el-button { margin-left: 0; }

.detail-collapse { border: 1px solid #e2e8f0; border-radius: 6px; background: #fff; overflow: hidden; }
.detail-collapse ::v-deep .el-collapse-item__header {
  height: 38px;
  line-height: 38px;
  padding: 0 12px;
  font-size: 13px;
  background: #fff;
}
.detail-collapse ::v-deep .el-collapse-item__wrap { border-bottom: 0; }
.detail-collapse ::v-deep .el-collapse-item__content { padding: 0 12px 12px; }
.collapse-title { display: inline-flex; align-items: center; gap: 6px; font-weight: 600; color: #334155; }
.collapse-title i { color: #008b4b; }
.upload-bar { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 8px; }
.category-select { width: 140px; }
.file-table { width: 100%; }
.file-name { display: inline-block; max-width: calc(100% - 56px); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; vertical-align: middle; }
.main-file-pill {
  margin-left: 6px;
  padding: 0 6px;
  border-radius: 3px;
  background: #d1fae5;
  color: #065f46;
  font-size: 11px;
  vertical-align: middle;
}
.conversion-row { display: flex; flex-wrap: wrap; gap: 8px 24px; font-size: 12px; color: #0f172a; }
.conversion-row em { font-style: normal; color: #94a3b8; margin-right: 6px; }

/* 订单行汇总 */
.line-total-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 8px 16px;
  margin-top: 10px;
  padding: 8px 12px;
  border-radius: 6px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
}
.total-chips { display: flex; flex-wrap: wrap; gap: 6px; }
.total-chip {
  padding: 2px 10px;
  border-radius: 12px;
  background: #fff;
  border: 1px solid #e2e8f0;
  font-size: 12px;
  color: #64748b;
}
.total-chip strong { color: #0f172a; }
.total-chip.is-warning { background: #fffbeb; border-color: #fde68a; color: #92400e; }
.total-amount { font-size: 13px; color: #475569; }
.total-amount strong { margin-left: 6px; font-size: 18px; color: #008b4b; font-variant-numeric: tabular-nums; }

/* 提醒、共享、合同 */
.share-line { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
.share-line .el-tag { margin: 0; }
.contract-upload-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 8px; }
.contract-upload-list ::v-deep .el-upload { display: block; width: 100%; }
.upload-tile {
  width: 100%;
  padding: 12px 8px;
  border: 1px dashed #cbd5e1;
  border-radius: 6px;
  background: #f8fafc;
  cursor: pointer;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  color: #334155;
  font-size: 12px;
}
.upload-tile i { font-size: 20px; color: #008b4b; }
.upload-tile small { color: #94a3b8; font-size: 11px; }
.upload-tile:hover { border-color: #008b4b; background: #f0fdf4; }

/* 共享弹窗 */
.share-dialog-body { display: grid; gap: 12px; }
.share-user-list {
  max-height: 320px;
  overflow: auto;
  border: 1px solid #e4e9f0;
  border-radius: 6px;
  padding: 8px 10px;
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 6px 12px;
}
.share-user-list .el-checkbox { margin: 0; padding: 8px; border-radius: 4px; }
.share-user-list .el-checkbox:hover { background: #f8fafc; }
.share-user-list span,
.share-user-list small { display: block; }
.share-user-list small { margin-top: 2px; color: #94a3b8; font-size: 11px; }

/* 预检焦点高亮 */
.precheck-focus { outline: 2px solid #f59e0b; outline-offset: 2px; transition: outline-color 0.25s ease; }

/* 响应式 */
@media (max-width: 1400px) {
  .line-detail-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .line-detail-grid .detail-block:first-child { grid-column: 1 / -1; }
}
@media (max-width: 1100px) {
  .top-grid { grid-template-columns: 1fr; }
}
@media (max-width: 768px) {
  .form-toolbar { padding: 8px 12px; }
  .toolbar-summary { margin-left: 0; order: 3; width: 100%; }
  .toolbar-actions { margin-left: auto; }
  .form-body { padding: 10px 10px 20px; }
  .panel { padding: 10px; }
  .field-grid,
  .field-grid.compact { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .line-detail-grid { grid-template-columns: 1fr; }
  .extra-grid { grid-template-columns: 1fr; }
  .order-line-detail { padding: 8px; }
  .line-detail-amount { margin-left: 0; }
  .share-user-list { grid-template-columns: 1fr; }
}
@media (max-width: 480px) {
  .field-grid,
  .field-grid.compact { grid-template-columns: 1fr; }
  .field.span-2 { grid-column: 1 / -1; }
  .toolbar-actions { width: 100%; }
  .toolbar-actions .el-button { flex: 1; }
  .total-amount { width: 100%; text-align: right; }
}
</style>
