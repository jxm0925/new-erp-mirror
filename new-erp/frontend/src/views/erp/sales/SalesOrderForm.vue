<!--
Design reference: D:\codex-introduce\new_erp\docs\product-design\phase6-order\phase6-order-add-edit.png
Design status: Approved
Do not change layout without approval.
-->
<template>
  <section class="sales-form-page">
    <product-sku-picker ref="productSkuPicker" @select="applyPicker" />
    <customer-picker ref="customerPicker" @select="applyCustomer" />
    <purchase-item-picker ref="cutItemPicker" @select-multiple="applyCutItems" />
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
        <div class="title-meta-group">
          <span class="main-title">销售订单 / {{ isEdit ? '编辑订单' : '新增订单' }}</span>
          <span v-if="form.sales_order_no" class="order-no-pill">{{ form.sales_order_no }}</span>
        </div>
      </div>
      <div class="toolbar-actions">
        <el-button size="small" @click="$router.push('/sales/orders')">返回列表</el-button>
        <el-button v-if="isEdit ? ($can('sales_order.edit_draft') || $can('sales_order.change')) : $can('sales_order.create')" size="small" @click="save(false)">{{ isConfirmedEdit ? '保存修改' : '保存草稿' }}</el-button>
        <el-button v-if="!isConfirmedEdit && $can('sales_order.submit_confirmation')" size="small" type="primary" class="btn-emerald-primary" @click="save(true)">提交确认</el-button>
      </div>
    </div>

    <div class="form-layout">
      <main class="form-main">
        <div class="top-cards">
          <section ref="基本信息" class="panel order-basic-card">
            <div class="panel-header">
              <i class="el-icon-document text-emerald" />
              <h3>订单基本信息</h3>
            </div>
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
              <el-select v-model="form.payment_method_id" size="small" placeholder="请选择付款方式" clearable>
                <el-option v-for="item in payTypeOptions" :key="item.id" :label="item.name" :value="String(item.id)" />
              </el-select>
              <span></span>
              <label>订单备注</label>
              <el-input v-model="form.remark" type="textarea" :rows="2" maxlength="200" show-word-limit placeholder="请输入订单备注（可选）" />
              <span></span>
            </div>
          </section>

          <section ref="客户与收货" class="panel customer-card">
            <div class="panel-header">
              <i class="el-icon-user text-emerald" />
              <h3>客户与收货</h3>
            </div>
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
              <label>订单标签</label>
              <el-input size="small" value="销售订单" disabled />
              <label>收货地址</label>
              <el-input v-model="form.full_address" type="textarea" :rows="2" placeholder="省 / 市 / 区 / 详细地址" />
              <label>自动识别</label>
              <el-input v-model="customerRawText" type="textarea" :rows="2" placeholder="粘贴客户姓名、电话、收货地址，系统自动识别并回填" @input="recognizeCustomerInfo" />
            </div>
          </section>

          <section class="panel delivery-card">
            <div class="panel-header">
              <i class="el-icon-truck text-emerald" />
              <h3>生产与交付标识</h3>
            </div>
            <div class="flag-grid">
              <label>是否加急</label><el-switch v-model="form.is_urgent" />
              <label>是否延期</label><el-switch v-model="form.is_delay" />
              <label>延期发货日期</label><el-date-picker v-model="form.delay_date" size="small" value-format="yyyy-MM-dd" :disabled="!form.is_delay" />
              <label>要求交期</label><el-date-picker v-model="form.required_delivery_date" size="small" value-format="yyyy-MM-dd" />
              <label>包含定制</label><el-tag size="small" :type="form.is_customized ? 'warning' : 'info'">{{ form.is_customized ? '包含定制' : '标准产品' }}</el-tag>
              <label>订单行数</label><strong>{{ form.lines.length }} 行</strong>
              <label>待补资料</label><el-tag size="small" :type="missingDataCount ? 'warning' : 'success'">{{ missingDataCount }} 行</el-tag>
            </div>
          </section>
        </div>

        <section
          ref="订单行"
          class="panel order-lines"
          :class="{ 'precheck-focus': $route.query.focus === 'lines' || String($route.query.focus || '').startsWith('lines.') }"
        >
          <div class="section-title">
            <div class="title-meta">
              <div class="title-main">
                <i class="el-icon-s-order text-emerald" />
                <h3>订单行明细</h3>
                <span class="lines-count-pill">{{ form.lines.length }} 行</span>
              </div>
            </div>
            <div class="title-action-btns">
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
            <el-table-column type="expand" width="46" align="center">
              <template slot-scope="{row, $index}">
                <div class="order-line-detail">
                  <header class="studio-header">
                    <div class="studio-header-left">
                      <span class="studio-row-badge">行 {{ $index + 1 }}</span>
                      <div class="studio-title-block">
                        <strong class="studio-title">{{ row.product_name || '未选产品' }} · {{ row.sku_name || '未选规格' }}</strong>
                        <span class="studio-spec-text">{{ row.spec_text_snapshot || '暂无规格快照' }}</span>
                      </div>
                    </div>
                    <div class="studio-header-right">
                      <div class="studio-stat-chip">
                        <span class="stat-label">本行小计</span>
                        <strong class="stat-val">¥{{ money(lineAmount(row)) }}</strong>
                      </div>
                      <el-button type="text" size="small" class="studio-collapse-btn" @click="expandedLineKeys = []">
                        <i class="el-icon-arrow-up" /> 收起配置
                      </el-button>
                    </div>
                  </header>

                  <div class="studio-grid">
                    <!-- Card 1: 核心产品与规格档案 -->
                    <div class="studio-card product-card-section">
                      <div class="card-header-bar">
                        <div class="bar-title"><i class="el-icon-goods text-emerald" /> 核心产品与规格档案</div>
                        <el-link class="product-link" type="primary" :underline="false">
                          产品档案 <i class="el-icon-arrow-right" />
                        </el-link>
                      </div>
                      <div class="studio-card-body">
                        <div class="product-showcase-box">
                          <div class="product-img-wrap">
                            <img v-if="row.product_snapshot && row.product_snapshot.image" :src="legacyMediaUrl(row.product_snapshot.image)" alt="产品图片">
                            <div v-else class="img-empty-box"><i class="el-icon-picture-outline" /></div>
                          </div>
                          <div class="product-meta-stack">
                            <div class="product-code-row">
                              <span class="code-badge">{{ row.product_code_snapshot || '未分配产品编码' }}</span>
                              <el-tag size="mini" type="info" effect="plain">{{ (row.product_snapshot && row.product_snapshot.category_name) || '标准物料分类' }}</el-tag>
                            </div>
                            <div class="item-matching-banner">
                              <span class="label">匹配物料：</span>
                              <strong class="item-name">{{ row.item_name || '待系统匹配' }}</strong>
                            </div>
                          </div>
                        </div>

                        <div class="studio-form-grid">
                          <div class="form-field-group">
                            <label>Product (产品)</label>
                            <div class="studio-select-pill" @click="openProductPicker(row)">
                              <span class="pill-text" :class="{ 'text-muted': !row.product_name }">{{ row.product_name || '点击选择 Product' }}</span>
                              <i class="el-icon-edit-outline" />
                            </div>
                          </div>
                          <div class="form-field-group">
                            <label>SKU (规格)</label>
                            <div class="studio-select-pill" @click="openSkuPicker(row)">
                              <span class="pill-text" :class="{ 'text-muted': !row.sku_name }">{{ row.sku_name || '点击选择 SKU' }}</span>
                              <i class="el-icon-edit-outline" />
                            </div>
                          </div>
                          <div class="form-field-group full-span">
                            <label>规格参数描述</label>
                            <div class="spec-readout">{{ row.spec_text_snapshot || '—' }}</div>
                          </div>
                        </div>
                      </div>
                    </div>

                    <!-- Card 2: 数量、单价与约定 -->
                    <div class="studio-card commercial-card-section">
                      <div class="card-header-bar">
                        <div class="bar-title"><i class="el-icon-money text-emerald" /> 数量与价格条款</div>
                      </div>
                      <div class="studio-card-body">
                        <div class="commercial-grid">
                          <div class="form-field-group">
                            <label class="required">销售数量</label>
                            <div class="input-with-unit-group">
                              <el-input-number v-model="row.order_qty" size="small" :min="0.0001" :precision="4" controls-position="right" class="compact-input-number" @change="recalc" />
                              <span class="input-unit-addon">{{ salesUnitName(row) }}</span>
                            </div>
                          </div>
                          <div class="form-field-group">
                            <label class="required">销售单价</label>
                            <div class="input-with-addon-group">
                              <span class="addon-prefix">¥</span>
                              <el-input-number v-model="row.unit_price" size="small" :min="0" :precision="2" controls-position="right" class="compact-input-number" @change="recalc" />
                            </div>
                          </div>
                          <div class="form-field-group">
                            <label>报价有效期</label>
                            <el-input v-model="row.configuration_snapshot.valid_days" size="small" placeholder="如 60 天" />
                          </div>
                          <div class="form-field-group">
                            <label>行处理类型</label>
                            <div class="line-type-chip-wrap">
                              <el-tag size="small" :type="lineTypeTag(row.line_type)" effect="light">{{ lineTypeText(row.line_type) }}</el-tag>
                            </div>
                          </div>
                          <div class="form-field-group full-span">
                            <label>订单行备注</label>
                            <el-input v-model="row.remark" size="small" placeholder="填写针对该行的特殊包装、备货或交付嘱咐" />
                          </div>
                        </div>
                      </div>
                    </div>

                    <!-- Card 3: 客户定制与属性 -->
                    <div class="studio-card attributes-card-section">
                      <div class="card-header-bar">
                        <div class="bar-title"><i class="el-icon-set-up text-emerald" /> 订单行定制与电气配置</div>
                      </div>
                      <div class="studio-card-body">
                        <div class="toggles-cluster">
                          <div v-if="lineCapabilities(row).allow_customized" class="toggle-card">
                            <div class="toggle-info">
                              <span class="toggle-title">普通定制</span>
                              <small class="toggle-desc">客户要求常规规格微调或非标要求</small>
                            </div>
                            <el-switch v-model="row.is_customized" active-color="#008b4b" @change="syncHeaderFlags" />
                          </div>

                          <div v-if="lineCapabilities(row).allow_special_customized" class="toggle-card" :class="{ 'is-active': row.is_special_customized }">
                            <div class="toggle-info">
                              <span class="toggle-title">特殊定制</span>
                              <small class="toggle-desc">涉及结构变更或需专属技术协议</small>
                            </div>
                            <el-switch v-model="row.is_special_customized" active-color="#008b4b" @change="syncHeaderFlags" />
                          </div>
                        </div>

                        <div v-if="row.is_special_customized && lineCapabilities(row).special_custom_description_required" class="special-desc-box">
                          <label class="required">特殊定制配置说明</label>
                          <el-input v-model="row.configuration_snapshot.special_custom_description" size="small" type="textarea" :rows="2" placeholder="请详细填写特殊定制配置说明（必填）" />
                        </div>

                        <div class="dropdowns-grid">
                          <div v-if="lineSupportsElectric(row)" class="form-field-group">
                            <label :class="{ required: lineElectricRequired(row) }">工作电压</label>
                            <el-select v-model="row.electric" size="small" clearable placeholder="请选择电压">
                              <el-option v-for="option in lineElectricOptions(row)" :key="option" :label="option" :value="option" />
                            </el-select>
                          </div>

                          <div v-if="lineSupportsNeedPump(row)" class="form-field-group">
                            <label :class="{ required: lineNeedPumpRequired(row) }">原水泵控制</label>
                            <el-select v-model="row.need_pump" size="small" clearable placeholder="请选择">
                              <el-option label="需要控制水泵" :value="true" />
                              <el-option label="不需要控制水泵" :value="false" />
                            </el-select>
                          </div>

                          <div v-if="lineCapabilities(row).delivery_inspection_required" class="form-field-group">
                            <label>交付前检验</label>
                            <el-tag size="small" type="warning" effect="dark">需要交付前检验</el-tag>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- Studio Collapsible Extended Sections -->
                  <div class="studio-subsections">
                    <el-collapse v-model="detailSections" class="studio-collapse">
                      <!-- Cut requirements -->
                      <el-collapse-item name="cut">
                        <template slot="title">
                          <div class="collapse-title-inner">
                            <i class="el-icon-scissors" />
                            <span>生产长度下料要求</span>
                            <span class="count-tag">{{ cutRequirements(row).length }} 项</span>
                          </div>
                        </template>
                        <div class="sub-panel-body">
                          <div class="cut-actions-bar">
                            <el-button size="small" type="success" plain icon="el-icon-plus" @click="openCutItemPicker">选择长度下料 Item</el-button>
                            <span class="bar-note">仅方管、型材等长度下料物料可添加定长分段</span>
                          </div>
                          <div v-if="cutRequirements(row).length" class="cut-items-grid">
                            <div v-for="(cut, index) in cutRequirements(row)" :key="`${cut.component_item_id}-${index}`" class="cut-item-card">
                              <div class="cut-item-header">
                                <div class="cut-item-title">
                                  <span class="code">{{ cut.component_item_code || `Item #${cut.component_item_id}` }}</span>
                                  <span class="name">{{ cut.component_item_name || '长度下料物料' }}</span>
                                </div>
                                <el-button type="text" class="danger-link" icon="el-icon-delete" @click="removeCutRequirement(index)">删除</el-button>
                              </div>
                              <div class="cut-params-row">
                                <div class="cut-param-field">
                                  <label>每段长度</label>
                                  <div class="input-unit">
                                    <el-input-number v-model="cut.cut_length_mm" size="small" :min="0.01" :max="Number(cut.standard_stock_length_mm || 9999999999)" :precision="2" :controls="false" />
                                    <span>mm</span>
                                  </div>
                                </div>
                                <div class="cut-param-field">
                                  <label>分段数量</label>
                                  <div class="input-unit">
                                    <el-input-number v-model="cut.piece_qty" size="small" :min="1" :precision="0" :controls="false" />
                                    <span>段</span>
                                  </div>
                                </div>
                                <div class="cut-param-field remark-field">
                                  <label>工艺备注</label>
                                  <el-input v-model.trim="cut.remark" size="small" maxlength="500" placeholder="下料工艺特殊备注（可选）" />
                                </div>
                              </div>
                              <div class="cut-card-footer">
                                <el-button size="mini" type="text" icon="el-icon-document-copy" @click="duplicateCutRequirement(index)">增加同一物料不同长度分段</el-button>
                              </div>
                            </div>
                          </div>
                          <div v-else class="cut-empty-state">
                            <i class="el-icon-info text-muted" />
                            <span>当前订单行尚未配置长度下料明细。普通物料无需下料；只有定长方管、型材等物料才需添加。</span>
                          </div>
                        </div>
                      </el-collapse-item>

                      <!-- Attachments -->
                      <el-collapse-item name="attachments">
                        <template slot="title">
                          <div class="collapse-title-inner">
                            <i class="el-icon-folder" />
                            <span>设计图纸与技术资料</span>
                            <span class="count-tag">{{ files(row).length }} 份</span>
                          </div>
                        </template>
                        <div class="sub-panel-body">
                          <div class="upload-studio-bar">
                            <div class="upload-controls">
                              <span class="label">资料类别：</span>
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
                            <div class="upload-tip">支持 PDF、CAD 导出图、图片、技术协议；特殊定制必传技术协议或图纸</div>
                          </div>

                          <div class="studio-file-table-wrap">
                            <table class="studio-file-table">
                              <thead>
                                <tr>
                                  <th style="width: 40%">文件名称</th>
                                  <th style="width: 15%">版本</th>
                                  <th style="width: 25%">附件分类</th>
                                  <th style="width: 20%; text-align: center">操作</th>
                                </tr>
                              </thead>
                              <tbody>
                                <template v-if="files(row).length">
                                  <tr v-for="(file, index) in files(row)" :key="file.uid + '-' + index">
                                    <td>
                                      <div class="file-name-cell">
                                        <i class="el-icon-document file-icon" />
                                        <span class="file-text" :title="file.file_name">{{ file.file_name }}</span>
                                        <span v-if="file.is_main" class="main-file-pill">主图纸</span>
                                      </div>
                                    </td>
                                    <td><span class="version-tag">V{{ index + 1 }}.0</span></td>
                                    <td><span class="type-badge">{{ file.file_type }}</span></td>
                                    <td class="action-cell">
                                      <el-button v-if="file.can_preview === true" type="text" size="mini" @click.stop="previewAttachment(file)">预览</el-button>
                                      <el-button v-if="file.can_download !== false" type="text" size="mini" @click.stop="downloadAttachment(file)">下载</el-button>
                                      <el-button type="text" size="mini" @click.stop="setMainFile(index)">设为主图</el-button>
                                      <el-button v-if="file.can_delete === true" type="text" size="mini" class="danger-link" @click.stop="removeLineFile(index)">删除</el-button>
                                    </td>
                                  </tr>
                                </template>
                                <tr v-else>
                                  <td colspan="4" class="empty-table-cell">
                                    <i class="el-icon-document-remove text-muted" /> 尚未上传本行图纸或技术附件
                                  </td>
                                </tr>
                              </tbody>
                            </table>
                          </div>
                        </div>
                      </el-collapse-item>

                      <!-- Unit conversion -->
                      <el-collapse-item name="units">
                        <template slot="title">
                          <div class="collapse-title-inner">
                            <i class="el-icon-refresh" />
                            <span>单位换算与规格配比</span>
                          </div>
                        </template>
                        <div class="sub-panel-body">
                          <div v-if="lineNeedsItem(row)" class="conversion-cards-grid">
                            <div class="stat-card">
                              <span class="sc-label">默认库存物料</span>
                              <strong class="sc-value">{{ row.item_name || '待系统匹配' }}</strong>
                            </div>
                            <div class="stat-card">
                              <span class="sc-label">Item 基本单位</span>
                              <strong class="sc-value">{{ itemBaseUnitName(row) }}</strong>
                            </div>
                            <div class="stat-card">
                              <span class="sc-label">单位换算关系</span>
                              <strong class="sc-value text-emerald">1 {{ salesUnitName(row) }} = {{ fulfillmentFactor(row) }} {{ itemBaseUnitName(row) }}</strong>
                            </div>
                            <div class="stat-card">
                              <span class="sc-label">Item 基本需求量</span>
                              <strong class="sc-value text-amber">{{ itemBaseRequiredQty(row) }} {{ itemBaseUnitName(row) }}</strong>
                            </div>
                          </div>
                          <div v-else class="cut-empty-state">
                            <i class="el-icon-check text-emerald" /> <span>该产品/规格无需 Item 换算。</span>
                          </div>
                        </div>
                      </el-collapse-item>
                    </el-collapse>
                  </div>
                </div>
              </template>
            </el-table-column>

            <el-table-column label="行号" width="56" align="center">
              <template slot-scope="{$index}">
                <span class="table-row-index">{{ $index + 1 }}</span>
              </template>
            </el-table-column>

            <el-table-column label="产品名称" min-width="140">
              <template slot-scope="{row}">
                <div class="cell-selector-card" :class="{ 'has-value': !!row.product_name }" @click="openProductPicker(row)" title="点击选择或更换 Product">
                  <div class="cell-card-main">{{ row.product_name || '选择 Product' }}</div>
                  <div v-if="row.product_code_snapshot" class="cell-card-sub">{{ row.product_code_snapshot }}</div>
                </div>
              </template>
            </el-table-column>

            <el-table-column label="SKU 规格" min-width="150">
              <template slot-scope="{row}">
                <div class="cell-selector-card" :class="{ 'has-value': !!row.sku_name }" @click="openSkuPicker(row)" title="点击选择或更换 SKU">
                  <div class="cell-card-main">{{ row.sku_name || '选择 SKU' }}</div>
                  <div v-if="row.spec_text_snapshot" class="cell-card-sub">{{ row.spec_text_snapshot }}</div>
                </div>
              </template>
            </el-table-column>

            <el-table-column label="匹配物料" width="115">
              <template slot-scope="{row}">
                <div class="match-item-cell" :title="row.item_name">
                  <span class="match-dot" :class="row.item_match_status === 'matched' ? 'dot-green' : 'dot-gray'" />
                  <span class="match-name">{{ row.item_name || '待系统匹配' }}</span>
                </div>
              </template>
            </el-table-column>

            <el-table-column label="数量及单位" width="130">
              <template slot-scope="{row}">
                <div class="qty-unit-cell">
                  <el-input v-model.number="row.order_qty" size="mini" class="table-inline-input text-right" @input="recalc" />
                  <span class="table-unit-pill">{{ salesUnitName(row) }}</span>
                </div>
              </template>
            </el-table-column>

            <el-table-column label="销售单价" width="105">
              <template slot-scope="{row}">
                <div class="price-input-cell">
                  <span class="cell-currency">¥</span>
                  <el-input v-model.number="row.unit_price" size="mini" class="table-inline-input" @input="recalc" />
                </div>
              </template>
            </el-table-column>

            <el-table-column label="金额小计" width="115" align="right">
              <template slot-scope="{row}">
                <span class="table-amount-cell">¥{{ money(lineAmount(row)) }}</span>
              </template>
            </el-table-column>

            <el-table-column label="配置角标" width="115">
              <template slot-scope="{row}">
                <div class="table-badges-flex">
                  <span v-if="row.is_customized" class="badge-chip chip-amber">定制</span>
                  <span v-if="row.electric" class="badge-chip chip-blue">{{ row.electric }}</span>
                  <span v-if="row.need_pump === true" class="badge-chip chip-emerald">原水泵</span>
                  <span v-else-if="row.need_pump === false" class="badge-chip chip-slate">无水泵</span>
                  <span v-if="!row.is_customized && !row.electric && row.need_pump === null" class="badge-chip-dash">—</span>
                </div>
              </template>
            </el-table-column>

            <el-table-column label="履约类型" width="95" align="center">
              <template slot-scope="{row}">
                <el-tag size="mini" :type="lineTypeTag(row.line_type)" effect="light">{{ lineTypeText(row.line_type) }}</el-tag>
              </template>
            </el-table-column>

            <el-table-column label="BOM状态" width="85" align="center">
              <template slot-scope="{row}">
                <span v-if="row.line_type === 'service' || row.line_type === 'no_delivery'" class="dash-text">无需</span>
                <el-tag v-else-if="row.bom_snapshot && row.bom_snapshot.name" size="mini" type="success" effect="plain">就绪</el-tag>
                <el-tag v-else size="mini" type="warning" effect="plain">待补</el-tag>
              </template>
            </el-table-column>

            <el-table-column label="图纸资料" width="105" align="center">
              <template slot-scope="{row}">
                <el-upload class="line-file-upload" action="#" :auto-upload="false" :show-file-list="false" :on-change="file => addLineFileForRow(file, row)">
                  <el-button size="mini" plain class="btn-file-count">
                    <i class="el-icon-paperclip" /> {{ files(row).length }} 份
                  </el-button>
                </el-upload>
              </template>
            </el-table-column>

            <el-table-column label="操作" width="115" align="center" fixed="right">
              <template slot-scope="{$index, row}">
                <div class="row-actions-modern">
                  <el-button type="text" size="mini" class="btn-action-edit" @click="toggleLineDetail(row)">
                    <i :class="expandedLineKeys.includes(row.line_uuid) ? 'el-icon-arrow-up' : 'el-icon-edit'" />
                    {{ expandedLineKeys.includes(row.line_uuid) ? '收起' : '配置' }}
                  </el-button>
                  <el-button type="text" size="mini" class="btn-action-del" @click="removeLine($index)">删除</el-button>
                </div>
              </template>
            </el-table-column>
          </el-table>

          <div class="line-total-bar">
            <div class="total-meta">
              <span class="total-label">订单行明细汇总</span>
              <span class="total-count-tag">{{ form.lines.length }} 行</span>
            </div>
            <div class="total-stats">
              <div class="stat-item">
                <span class="label">产品总数量：</span>
                <strong class="val">{{ totalQty }}</strong>
                <span class="unit">件</span>
              </div>
              <div class="stat-divider" />
              <div class="stat-item">
                <span class="label">订单行总额：</span>
                <strong class="val-price">¥{{ money(totalAmount) }}</strong>
              </div>
            </div>
          </div>
        </section>

        <div class="bottom-grid">
          <section ref="提醒与共享" class="panel small-panel reminder-card">
            <div class="panel-header">
              <i class="el-icon-bell text-emerald" />
              <h3>提醒与共享</h3>
            </div>
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
            </div>
          </section>
          <section ref="外贸物流" class="panel small-panel logistics-card">
            <div class="panel-header">
              <i class="el-icon-ship text-emerald" />
              <h3>外贸与物流</h3>
            </div>
            <div class="logistics-grid">
              <div class="field-stack"><span>是否自提</span><el-switch v-model="form.shipping_snapshot.is_self_pickup" /></div>
              <div class="field-stack"><span>客户物流备注</span><el-input v-model="form.shipping_snapshot.customer_logistics_note" size="small" placeholder="客户指定承运方式、运输注意事项" /></div>
              <div class="field-stack trade-type-field"><span>贸易类型</span><el-radio-group v-model="form.trade_type" size="small"><el-radio-button label="domestic">内贸</el-radio-button><el-radio-button label="foreign">外贸</el-radio-button></el-radio-group></div>
              <div class="field-stack"><span class="required">快递选择</span><el-select v-model="form.carrier_id" size="small" placeholder="选择发货快递" clearable><el-option v-for="item in carrierOptions" :key="item.id" :label="item.name" :value="String(item.id)" /></el-select></div>
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
            <div class="panel-header">
              <i class="el-icon-collection text-emerald" />
              <h3>合同附件</h3>
            </div>
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
                  <small>技术协议文件</small>
                </button>
              </el-upload>
              <el-upload action="#" :auto-upload="false" :show-file-list="false" :on-change="file => addContractFile(file, '订单附件')">
                <button type="button" class="upload-card">
                  <i class="el-icon-upload2" />
                  <span>订单附件</span>
                  <small>报价单 / 沟通凭单</small>
                </button>
              </el-upload>
            </div>
            <div class="contract-meta">
              <span>已上传 {{ contractFiles.length }} 个合同附件</span>
              <el-button size="mini" plain icon="el-icon-folder-opened" @click="contractDialogVisible=true">查看附件清单</el-button>
            </div>
          </section>
        </div>

        <div class="final-grid">
          <section class="summary-bar-modern">
            <div class="summary-metric-card primary">
              <span class="metric-label">订单总金额</span>
              <div class="metric-value-wrap">
                <span class="currency">¥</span>
                <span class="amount">{{ money(totalAmount) }}</span>
              </div>
            </div>
            <div class="summary-metric-card">
              <span class="metric-label">订单总行数</span>
              <div class="metric-value-wrap">
                <span class="num">{{ form.lines.length }}</span>
                <span class="unit">行</span>
              </div>
            </div>
            <div class="summary-metric-card">
              <span class="metric-label">需生产制造</span>
              <div class="metric-value-wrap">
                <span class="num text-emerald">{{ productionLineCount }}</span>
                <span class="unit">行</span>
              </div>
            </div>
            <div class="summary-metric-card">
              <span class="metric-label">库存直发</span>
              <div class="metric-value-wrap">
                <span class="num text-blue">{{ stockLineCount }}</span>
                <span class="unit">行</span>
              </div>
            </div>
            <div class="summary-metric-card">
              <span class="metric-label">待补齐资料</span>
              <div class="metric-value-wrap">
                <span class="num" :class="missingDataCount ? 'text-amber' : 'text-muted'">{{ missingDataCount }}</span>
                <span class="unit">行</span>
              </div>
            </div>
          </section>
        </div>
      </main>


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
import cachedPageRoute from '@/utils/cachedPageRoute'
import ProductSkuPicker from '@/components/sales/ProductSkuPicker.vue'
import SalesOrderAttachmentPreviewDialog from '@/components/sales/SalesOrderAttachmentPreviewDialog.vue'
import OrderEditImpactDialog from '@/components/sales/OrderEditImpactDialog.vue'
import { legacyMediaUrl } from '@/utils/legacyMedia'
import CustomerPicker from '@/components/sales/CustomerPicker.vue'
import PurchaseItemPicker from '@/components/purchase/PurchaseItemPicker.vue'
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
  components: { ProductSkuPicker, CustomerPicker, PurchaseItemPicker, SalesOrderAttachmentPreviewDialog, OrderEditImpactDialog },
  data: () => ({
    selectedLine: null,
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
        carrier_id: data.carrier_id ? String(data.carrier_id) : '',
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
      if (this.lineCutMessage(row)) sections.push('cut')
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
      if (sku.special_custom_agreement_required && !files.some(file => file.file_type === '技术协议')) return '特殊定制缺少客户技术协议'
      if (sku.special_custom_description_required && !String((line.configuration_snapshot || {}).special_custom_description || '').trim()) return '请填写特殊定制配置说明'
      return ''
    },
    cutRequirements(line) {
      if (!line) return []
      if (!line.configuration_snapshot) this.$set(line, 'configuration_snapshot', {})
      if (!Array.isArray(line.configuration_snapshot.cut_requirements)) this.$set(line.configuration_snapshot, 'cut_requirements', [])
      return line.configuration_snapshot.cut_requirements
    },
    openCutItemPicker() {
      const selected = this.cutRequirements(this.selectedLine).map(row => ({ ...row, id: row.component_item_id, item_code: row.component_item_code, item_name: row.component_item_name }))
      this.$refs.cutItemPicker.open({
        multiple: true,
        selected,
        params: { status: 'enabled', is_purchase_item: 1, is_length_cut_material: 1 },
        title: '选择长度下料 Item',
        tip: '仅显示真实采购/库存的长度下料 Item。可跨分类、搜索和分页多选；长度与段数返回订单行后填写。'
      })
    },
    applyCutItems(items) {
      const existing = this.cutRequirements(this.selectedLine)
      const retained = existing.filter(row => items.some(item => Number(item.id) === Number(row.component_item_id)))
      items.forEach(item => {
        if (retained.some(row => Number(row.component_item_id) === Number(item.id))) return
        retained.push({ component_item_id: item.id, component_item_code: item.item_code, component_item_name: item.item_name, material_grade: item.material_grade || null, standard_stock_length_mm: Number(item.standard_stock_length_mm), cut_length_mm: null, piece_qty: 1, remark: '' })
      })
      this.$set(this.selectedLine.configuration_snapshot, 'cut_requirements', retained)
    },
    duplicateCutRequirement(index) {
      const rows = this.cutRequirements(this.selectedLine)
      const source = rows[index]
      rows.splice(index + 1, 0, { ...source, cut_length_mm: null, piece_qty: 1, remark: '' })
    },
    removeCutRequirement(index) { this.cutRequirements(this.selectedLine).splice(index, 1) },
    lineCutMessage(line) {
      const invalid = this.cutRequirements(line).find(row => !(Number(row.cut_length_mm) > 0) || !(Number(row.piece_qty) > 0) || !Number.isInteger(Number(row.piece_qty)))
      return invalid ? '下料要求必须填写大于 0 的每段长度和整数段数' : ''
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
      if (!this.impactPayload) { this.impactDialogVisible = false; return }
      if (this.impactPreview.requiresApproval && !String(this.impactReason || '').trim()) return this.$message.error('请填写本次修改的变更原因')
      this.impactSubmitting = true
      try {
        const { data } = await submitSalesOrderEditImpact(this.pageRoute.params.id, { ...this.impactPayload, change_reason: this.impactReason })
        this.$message.success(data.message || '订单修改已处理')
        this.impactDialogVisible = false
        this.$router.push(`/sales/orders/${this.pageRoute.params.id}/detail`)
      } finally { this.impactSubmitting = false }
    },
    async save(andConfirm) {
      if (!this.form.customer_id) return this.$message.error('请通过客户选择框选择客户')
      if (!this.form.sales_user_legacy_id) return this.$message.error('请选择销售人员')
      if (!this.form.platform) return this.$message.error('请选择成交平台')
      if (!this.form.payment_method_id) return this.$message.error('请选择付款方式')
      if (this.form.lines.some(line => !line.sku_id)) return this.$message.error('订单行必须通过全局搜索选择SKU')
      if (this.form.lines.some(line => !line.product_id)) return this.$message.error('所选SKU缺少所属Product，请维护SKU主数据后重试')
      if (andConfirm && !this.form.carrier_id) return this.$message.error('提交确认前必须先选择快递')
      if (andConfirm && this.form.lines.some(line => Number(line.unit_price || 0) <= 0)) return this.$message.error('提交确认前，订单行销售单价必须大于 0')
      const invalidAttribute = this.form.lines.find(line => this.lineAttributeMessage(line))
      if (andConfirm && invalidAttribute) return this.showLineError(invalidAttribute, this.lineAttributeMessage(invalidAttribute))
      const invalidCustomization = this.form.lines.find(line => this.lineCustomizationMessage(line))
      if (andConfirm && invalidCustomization) return this.showLineError(invalidCustomization, this.lineCustomizationMessage(invalidCustomization))
      const invalidCut = this.form.lines.find(line => this.lineCutMessage(line))
      if (invalidCut) return this.showLineError(invalidCut, this.lineCutMessage(invalidCut), 'cut')
      this.syncHeaderFlags()
      const carrier = this.carrierOptions.find(item => String(item.id) === String(this.form.carrier_id))
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
    lineTypeText(v) {
      return ({ physical: '制造/发货', service: '服务项目', no_delivery: '无需发货', auxiliary: '辅助录入', fee: '费用' })[v] || v
    },
    lineTypeTag(v) {
      return ({ physical: 'warning', service: 'info', no_delivery: 'success', auxiliary: '' })[v] || ''
    }
  }
}
</script>

<style scoped>
/* ==========================================================================
   销售订单创建/编辑 - 现代化企业级视觉规范
   ========================================================================== */
.sales-form-page {
  position: relative;
  z-index: 5;
  min-height: 100vh;
  background: #f8fafc;
  color: #0f172a;
  max-width: 100%;
  overflow-x: hidden;
}

/* 顶部吸顶工具栏 */
.form-toolbar {
  position: sticky;
  top: 0;
  z-index: 20;
  height: 56px;
  padding: 0 24px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: rgba(255, 255, 255, 0.95);
  backdrop-filter: blur(8px);
  border-bottom: 1px solid #e2e8f0;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
}
.page-title {
  display: flex;
  align-items: center;
  gap: 12px;
}
.title-meta-group {
  display: flex;
  align-items: center;
  gap: 10px;
}
.main-title {
  font-size: 16px;
  font-weight: 700;
  color: #0f172a;
}
.order-no-pill {
  font-size: 12px;
  font-weight: 600;
  padding: 2px 10px;
  background: #f1f5f9;
  color: #475569;
  border-radius: 4px;
  border: 1px solid #cbd5e1;
}
.back-btn {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  border: 1px solid #e2e8f0;
  background: #ffffff;
  color: #475569;
  display: grid;
  place-items: center;
  font-size: 16px;
  cursor: pointer;
  transition: all 0.2s ease;
}
.back-btn:hover {
  background: #f1f5f9;
  color: #008b4b;
  border-color: #cbd5e1;
}
.toolbar-actions {
  display: flex;
  gap: 10px;
  align-items: center;
}

/* 整体布局框架 */
.form-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) !important;
  gap: 14px;
  padding: 14px 18px 24px;
  max-width: 100%;
  overflow-x: hidden;
}
.form-main {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 14px;
}

/* 通用卡片面板 */
.panel {
  min-width: 0;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
  transition: box-shadow 0.2s ease;
}
.panel-header {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 12px 18px;
  border-bottom: 1px solid #f1f5f9;
  background: #ffffff;
}
.panel-header i {
  font-size: 15px;
}
.panel-header h3 {
  margin: 0;
  padding: 0;
  font-size: 14px;
  font-weight: 700;
  color: #1e293b;
  letter-spacing: -0.2px;
}

/* 顶部三卡片 */
.top-cards {
  display: grid;
  grid-template-columns: 1.15fr 1.05fr 0.95fr;
  gap: 14px;
  align-items: stretch;
}
.top-cards .panel {
  display: flex;
  flex-direction: column;
}
.info-grid {
  padding: 12px 18px 16px;
  display: grid;
  grid-template-columns: 88px minmax(0, 1fr) 78px;
  gap: 10px 12px;
  align-items: center;
}
.info-grid.two {
  grid-template-columns: 104px minmax(0, 1fr);
}
.info-grid label,
.flag-grid label {
  font-size: 12px;
  font-weight: 600;
  color: #475569;
}
.required::before {
  content: '*';
  color: #ef4444;
  margin-right: 4px;
  font-weight: 700;
}
.customer-select-field {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 88px;
  gap: 8px;
  align-items: center;
}
.customer-kind-radios {
  display: flex;
  min-width: 0;
}
.customer-kind-radios ::v-deep .el-radio-button__inner {
  padding: 6px 12px;
  font-size: 12px;
}
.flag-grid {
  padding: 12px 18px 14px;
  display: grid;
  grid-template-columns: 110px minmax(0, 1fr);
  gap: 10px 12px;
  align-items: center;
}
.inline-selects {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
}
.inline-selects .el-select:only-child {
  grid-column: 1 / -1;
}

/* ==========================================================================
   订单行核心编辑区 (Order Lines)
   ========================================================================== */
.order-lines {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
  overflow: hidden;
}
.order-lines .section-title {
  padding: 14px 20px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid #f1f5f9;
  background: #ffffff;
}
.title-meta {
  display: flex;
  flex-direction: column;
  gap: 3px;
}
.title-main {
  display: flex;
  align-items: center;
  gap: 10px;
}
.title-main h3 {
  margin: 0;
  padding: 0;
  font-size: 15px;
  font-weight: 700;
  color: #0f172a;
}
.lines-count-pill {
  font-size: 11px;
  font-weight: 700;
  padding: 2px 10px;
  border-radius: 12px;
  background: #eaf7ef;
  color: #008b4b;
  border: 1px solid #ccebd7;
}
.title-action-btns {
  display: flex;
  gap: 10px;
  align-items: center;
}
.btn-emerald-primary {
  background: linear-gradient(135deg, #008b4b 0%, #00763f 100%) !important;
  border-color: #00763f !important;
  color: #ffffff !important;
  font-weight: 600;
  box-shadow: 0 2px 4px rgba(0, 139, 75, 0.2);
}
.btn-emerald-primary:hover {
  background: linear-gradient(135deg, #009d55 0%, #008b4b 100%) !important;
}

/* 主表格样式优化 */
.sales-order-table {
  width: 100% !important;
  border-color: #e2e8f0 !important;
}
.sales-order-table ::v-deep th {
  background: #f8fafc !important;
  color: #475569 !important;
  font-size: 12px !important;
  font-weight: 700 !important;
  padding: 8px 0 !important;
  border-color: #e2e8f0 !important;
}
.sales-order-table ::v-deep td {
  padding: 8px 0 !important;
  border-color: #f1f5f9 !important;
  font-size: 12px !important;
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
  border-bottom: 2px solid #e2e8f0 !important;
}

.table-row-index {
  font-size: 12px;
  font-weight: 600;
  color: #64748b;
}

/* Product & SKU 选择卡片单元格 */
.cell-selector-card {
  display: flex;
  flex-direction: column;
  justify-content: center;
  padding: 4px 8px;
  min-height: 34px;
  background: #f8fafc;
  border: 1px dashed #cbd5e1;
  border-radius: 6px;
  cursor: pointer;
  transition: all 0.2s ease;
  user-select: none;
}
.cell-selector-card:hover {
  border-color: #008b4b;
  border-style: solid;
  background: #ffffff;
  box-shadow: 0 2px 4px rgba(0, 139, 75, 0.1);
}
.cell-selector-card.has-value {
  background: #ffffff;
  border: 1px solid #e2e8f0;
}
.cell-card-main {
  font-size: 12px;
  font-weight: 600;
  color: #0f172a;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.cell-selector-card:not(.has-value) .cell-card-main {
  color: #008b4b;
  font-weight: 500;
}
.cell-card-sub {
  font-size: 11px;
  color: #64748b;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* 匹配物料单元格 */
.match-item-cell {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 0 4px;
}
.match-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  flex-shrink: 0;
}
.match-dot.dot-green {
  background: #10b981;
  box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.2);
}
.match-dot.dot-gray {
  background: #cbd5e1;
}
.match-name {
  color: #475569;
  font-size: 12px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* 数量及单位单元格 */
.qty-unit-cell {
  display: flex;
  align-items: center;
  gap: 4px;
}
.table-inline-input ::v-deep .el-input__inner {
  height: 28px !important;
  line-height: 28px !important;
  padding: 0 6px !important;
  font-size: 12px !important;
  font-weight: 600 !important;
  border-radius: 4px !important;
  border-color: #d8e0ea !important;
}
.table-inline-input.text-right ::v-deep .el-input__inner {
  text-align: right !important;
}
.table-inline-input ::v-deep .el-input__inner:focus {
  border-color: #008b4b !important;
  box-shadow: 0 0 0 2px rgba(0, 139, 75, 0.12) !important;
}
.table-unit-pill {
  font-size: 11px;
  font-weight: 600;
  color: #475569;
  background: #f1f5f9;
  padding: 2px 6px;
  border-radius: 4px;
  white-space: nowrap;
}

/* 单价单元格 */
.price-input-cell {
  display: flex;
  align-items: center;
  gap: 2px;
}
.cell-currency {
  font-size: 12px;
  color: #94a3b8;
  font-weight: 600;
}

/* 金额单元格 */
.table-amount-cell {
  font-size: 13px;
  font-weight: 700;
  color: #008b4b;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
}

/* 特性角标 Flex */
.table-badges-flex {
  display: flex;
  flex-wrap: wrap;
  gap: 3px;
}
.badge-chip {
  font-size: 10px;
  font-weight: 600;
  padding: 1px 5px;
  border-radius: 4px;
  line-height: 1.4;
  white-space: nowrap;
}
.chip-amber {
  background: #fef3c7;
  color: #b45309;
  border: 1px solid #fde68a;
}
.chip-blue {
  background: #e0f2fe;
  color: #0369a1;
  border: 1px solid #bae6fd;
}
.chip-emerald {
  background: #dcfce7;
  color: #15803d;
  border: 1px solid #bbf7d0;
}
.chip-slate {
  background: #f1f5f9;
  color: #64748b;
  border: 1px solid #e2e8f0;
}
.badge-chip-dash {
  color: #cbd5e1;
}
.dash-text {
  color: #94a3b8;
  font-size: 11px;
}

.btn-file-count {
  height: 26px !important;
  padding: 0 8px !important;
  font-size: 11px !important;
  border-radius: 13px !important;
  border-color: #cbd5e1 !important;
  color: #475569 !important;
}
.btn-file-count:hover {
  border-color: #008b4b !important;
  color: #008b4b !important;
}

/* 操作列现代按钮 */
.row-actions-modern {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
}
.btn-action-edit {
  color: #008b4b !important;
  font-weight: 600 !important;
  padding: 0 !important;
}
.btn-action-edit:hover {
  color: #00763f !important;
}
.btn-action-del {
  color: #ef4444 !important;
  padding: 0 !important;
}
.btn-action-del:hover {
  color: #dc2626 !important;
}

/* 表格底部汇总条 */
.line-total-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 20px;
  background: #f8fafc;
  border-top: 1px solid #e2e8f0;
}
.total-meta {
  display: flex;
  align-items: center;
  gap: 8px;
}
.total-label {
  font-size: 13px;
  font-weight: 700;
  color: #334155;
}
.total-count-tag {
  font-size: 11px;
  padding: 1px 7px;
  border-radius: 10px;
  background: #e2e8f0;
  color: #475569;
  font-weight: 600;
}
.total-stats {
  display: flex;
  align-items: center;
  gap: 18px;
}
.stat-item {
  display: flex;
  align-items: baseline;
  gap: 4px;
}
.stat-item .label {
  font-size: 12px;
  color: #64748b;
}
.stat-item .val {
  font-size: 15px;
  font-weight: 700;
  color: #0f172a;
}
.stat-item .unit {
  font-size: 12px;
  color: #64748b;
}
.stat-item .val-price {
  font-size: 18px;
  font-weight: 800;
  color: #008b4b;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
}
.stat-divider {
  width: 1px;
  height: 18px;
  background: #cbd5e1;
}

/* ==========================================================================
   行内展开编辑工作台 (Order Line Studio)
   ========================================================================== */
.order-line-detail {
  box-sizing: border-box;
  margin: 14px 20px 20px;
  background: #ffffff;
  border: 1px solid #cbd5e1;
  border-radius: 10px;
  box-shadow: 0 4px 16px -2px rgba(15, 23, 42, 0.08), 0 2px 6px -2px rgba(15, 23, 42, 0.04);
  overflow: hidden;
  color: #0f172a;
  white-space: normal;
  scroll-margin-top: 70px;
}

/* Studio 头部横幅 */
.studio-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 20px;
  background: linear-gradient(90deg, #f8fafc 0%, #f1f5f9 100%);
  border-bottom: 1px solid #e2e8f0;
}
.studio-header-left {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}
.studio-row-badge {
  background: #008b4b;
  color: #ffffff;
  font-size: 11px;
  font-weight: 700;
  padding: 3px 10px;
  border-radius: 6px;
  letter-spacing: 0.5px;
  flex-shrink: 0;
}
.studio-title-block {
  min-width: 0;
  display: flex;
  flex-direction: column;
}
.studio-title {
  font-size: 14px;
  font-weight: 700;
  color: #0f172a;
  overflow-wrap: anywhere;
}
.studio-spec-text {
  font-size: 12px;
  color: #64748b;
  overflow-wrap: anywhere;
}
.studio-header-right {
  display: flex;
  align-items: center;
  gap: 14px;
  flex-shrink: 0;
}
.studio-stat-chip {
  display: flex;
  align-items: center;
  gap: 6px;
  background: #ffffff;
  padding: 4px 12px;
  border-radius: 6px;
  border: 1px solid #e2e8f0;
}
.studio-stat-chip .stat-label {
  font-size: 11px;
  color: #64748b;
}
.studio-stat-chip .stat-val {
  font-size: 14px;
  font-weight: 700;
  color: #008b4b;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
}
.studio-collapse-btn {
  color: #475569 !important;
  font-size: 12px !important;
  padding: 4px 8px !important;
}
.studio-collapse-btn:hover {
  color: #008b4b !important;
}

/* Studio 主卡片三栏网格 */
.studio-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 14px;
  padding: 16px 20px;
  background: #f8fafc;
}
.studio-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03);
}
.card-header-bar {
  height: 38px;
  padding: 0 14px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #ffffff;
  border-bottom: 1px solid #f1f5f9;
}
.bar-title {
  font-size: 12px;
  font-weight: 700;
  color: #1e293b;
  display: flex;
  align-items: center;
  gap: 6px;
}
.bar-hint {
  font-size: 11px;
  color: #94a3b8;
  font-weight: 400;
}
.text-emerald {
  color: #008b4b;
}
.studio-card-body {
  padding: 14px;
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

/* Card 1: 核心产品与规格档案 */
.product-showcase-box {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px;
  background: #f8fafc;
  border-radius: 6px;
  border: 1px solid #f1f5f9;
}
.product-img-wrap {
  width: 58px;
  height: 58px;
  border-radius: 6px;
  border: 1px solid #e2e8f0;
  background: #ffffff;
  display: grid;
  place-items: center;
  overflow: hidden;
  flex-shrink: 0;
}
.product-img-wrap img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.img-empty-box {
  color: #94a3b8;
  font-size: 24px;
}
.product-meta-stack {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.product-code-row {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}
.code-badge {
  font-size: 11px;
  font-weight: 600;
  color: #334155;
  background: #e2e8f0;
  padding: 1px 6px;
  border-radius: 4px;
}
.item-matching-banner {
  font-size: 11px;
  color: #64748b;
  display: flex;
  align-items: baseline;
  gap: 4px;
}
.item-matching-banner .item-name {
  color: #0f172a;
  font-weight: 600;
  overflow-wrap: anywhere;
}

.studio-form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}
.form-field-group {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}
.form-field-group.full-span {
  grid-column: 1 / -1;
}
.form-field-group label {
  font-size: 11px;
  font-weight: 600;
  color: #64748b;
}
.studio-select-pill {
  height: 32px;
  padding: 0 10px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 6px;
  border: 1px solid #d8e0ea;
  border-radius: 6px;
  background: #ffffff;
  cursor: pointer;
  transition: all 0.2s ease;
}
.studio-select-pill:hover {
  border-color: #008b4b;
  background: #f0fdf4;
}
.studio-select-pill .pill-text {
  font-size: 12px;
  font-weight: 600;
  color: #0f172a;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.studio-select-pill .text-muted {
  color: #94a3b8;
  font-weight: normal;
}
.spec-readout {
  padding: 6px 10px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  font-size: 12px;
  color: #334155;
  min-height: 28px;
  overflow-wrap: anywhere;
}

/* Card 2: 数量与价格条款 */
.commercial-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}
.input-with-unit-group,
.input-with-addon-group {
  display: flex;
  align-items: center;
  gap: 4px;
}
.compact-input-number {
  flex: 1;
  min-width: 0;
}
.compact-input-number ::v-deep .el-input__inner {
  height: 32px !important;
  line-height: 32px !important;
  font-size: 13px !important;
  font-weight: 700 !important;
}
.input-unit-addon,
.addon-prefix {
  font-size: 12px;
  font-weight: 700;
  color: #475569;
  background: #f1f5f9;
  padding: 0 8px;
  height: 32px;
  line-height: 32px;
  border-radius: 6px;
  border: 1px solid #e2e8f0;
}
.line-type-chip-wrap {
  height: 32px;
  display: flex;
  align-items: center;
}

/* Card 3: 客户定制与属性 */
.toggles-cluster {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
}
.toggle-card {
  padding: 8px 10px;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  background: #f8fafc;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  transition: all 0.2s ease;
}
.toggle-card.is-active {
  border-color: #a7f3d0;
  background: #f0fdf4;
}
.toggle-info {
  display: flex;
  flex-direction: column;
}
.toggle-title {
  font-size: 12px;
  font-weight: 600;
  color: #1e293b;
}
.toggle-desc {
  font-size: 10px;
  color: #94a3b8;
}
.special-desc-box {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.special-desc-box label {
  font-size: 11px;
  font-weight: 600;
  color: #ef4444;
}
.dropdowns-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
  align-items: end;
}
.dropdowns-grid .el-select {
  width: 100%;
}

/* Studio 折叠拓展区 */
.studio-subsections {
  border-top: 1px solid #e2e8f0;
  background: #ffffff;
  padding: 0 20px 10px;
}
.studio-collapse {
  border: 0 !important;
}
.studio-collapse ::v-deep .el-collapse-item__header {
  height: 44px !important;
  line-height: 44px !important;
  border-bottom: 1px solid #f1f5f9 !important;
  background: #ffffff !important;
  font-size: 13px !important;
  font-weight: 700 !important;
  color: #1e293b !important;
}
.studio-collapse ::v-deep .el-collapse-item__wrap {
  border-bottom: 1px solid #f1f5f9 !important;
  background: #ffffff !important;
}
.studio-collapse ::v-deep .el-collapse-item__content {
  padding-bottom: 14px !important;
}
.collapse-title-inner {
  display: flex;
  align-items: center;
  gap: 8px;
}
.collapse-title-inner i {
  color: #008b4b;
  font-size: 14px;
}
.count-tag {
  font-size: 11px;
  font-weight: normal;
  color: #64748b;
  background: #f1f5f9;
  padding: 1px 6px;
  border-radius: 10px;
}
.sub-panel-body {
  padding: 10px 0 0;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

/* 长度下料排版 */
.cut-actions-bar {
  display: flex;
  align-items: center;
  gap: 12px;
}
.bar-note {
  font-size: 12px;
  color: #94a3b8;
}
.cut-items-grid {
  display: grid;
  gap: 8px;
}
.cut-item-card {
  padding: 10px 14px;
  border: 1px solid #d1fae5;
  border-radius: 6px;
  background: #f0fdf4;
}
.cut-item-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
}
.cut-item-title {
  display: flex;
  align-items: center;
  gap: 8px;
}
.cut-item-title .code {
  font-size: 11px;
  font-weight: 700;
  color: #065f46;
  background: #a7f3d0;
  padding: 1px 6px;
  border-radius: 4px;
}
.cut-item-title .name {
  font-size: 12px;
  font-weight: 600;
  color: #065f46;
}
.cut-params-row {
  display: grid;
  grid-template-columns: 140px 140px minmax(0, 1fr);
  gap: 10px;
  align-items: end;
}
.cut-param-field {
  display: flex;
  flex-direction: column;
  gap: 3px;
}
.cut-param-field label {
  font-size: 11px;
  font-weight: 600;
  color: #047857;
}
.cut-param-field .input-unit {
  display: flex;
  align-items: center;
  gap: 4px;
}
.cut-param-field .input-unit span {
  font-size: 11px;
  color: #065f46;
  font-weight: 600;
}
.cut-card-footer {
  margin-top: 6px;
}
.cut-empty-state {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 12px 14px;
  background: #f8fafc;
  border: 1px dashed #cbd5e1;
  border-radius: 6px;
  color: #64748b;
  font-size: 12px;
}

/* 图纸资料排版 */
.upload-studio-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 10px;
}
.upload-controls {
  display: flex;
  align-items: center;
  gap: 8px;
}
.upload-controls .label {
  font-size: 12px;
  font-weight: 600;
  color: #475569;
}
.category-select {
  width: 140px;
}
.upload-tip {
  font-size: 12px;
  color: #94a3b8;
}
.studio-file-table-wrap {
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  overflow: hidden;
}
.studio-file-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
}
.studio-file-table th {
  background: #f8fafc;
  color: #475569;
  font-weight: 600;
  padding: 8px 12px;
  text-align: left;
  border-bottom: 1px solid #e2e8f0;
}
.studio-file-table td {
  padding: 8px 12px;
  border-bottom: 1px solid #f1f5f9;
  color: #1e293b;
}
.studio-file-table tr:last-child td {
  border-bottom: 0;
}
.file-name-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}
.file-icon {
  color: #008b4b;
  font-size: 14px;
}
.file-text {
  font-weight: 500;
  overflow-wrap: anywhere;
}
.main-file-pill {
  font-size: 10px;
  padding: 1px 6px;
  border-radius: 4px;
  background: #dcfce7;
  color: #15803d;
  font-weight: 600;
}
.version-tag {
  font-size: 11px;
  color: #64748b;
  background: #f1f5f9;
  padding: 2px 6px;
  border-radius: 4px;
}
.type-badge {
  font-size: 11px;
  color: #475569;
}
.action-cell {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}
.empty-table-cell {
  text-align: center;
  padding: 16px !important;
  color: #94a3b8;
}

/* 单位换算卡片 */
.conversion-cards-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 10px;
}
.stat-card {
  padding: 10px 12px;
  border-radius: 6px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.sc-label {
  font-size: 11px;
  color: #64748b;
}
.sc-value {
  font-size: 13px;
  color: #0f172a;
  overflow-wrap: anywhere;
}
.text-amber {
  color: #d97706;
}


/* ==========================================================================
   底部外贸、提醒、合同附件卡片
   ========================================================================== */
.bottom-grid {
  display: grid;
  grid-template-columns: 0.95fr 1.2fr 1fr;
  gap: 14px;
}
.small-panel {
  min-height: 180px;
}
.reminder-form {
  padding: 4px 18px 16px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.reminder-row {
  display: grid;
  grid-template-columns: minmax(140px, 1fr) minmax(180px, 1.2fr);
  gap: 12px;
  align-items: center;
}
.switch-field,
.days-field,
.share-user-line {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}
.switch-field span,
.days-field span,
.share-user-line > span,
.reminder-content > span {
  font-size: 12px;
  font-weight: 600;
  color: #475569;
  white-space: nowrap;
}
.switch-field em,
.days-field em {
  font-style: normal;
  color: #64748b;
  font-size: 12px;
}
.days-field ::v-deep .el-input-number--small {
  width: 96px;
}
.reminder-content {
  display: grid;
  grid-template-columns: 72px minmax(0, 1fr);
  gap: 8px;
  align-items: start;
}
.share-switch-line {
  margin-top: 4px;
}
.share-user-line {
  align-items: flex-start;
}
.select-share-btn {
  height: 30px;
  padding: 0 10px;
  border: 1px solid #d8e0ea;
  border-radius: 6px;
  background: #ffffff;
  color: #334155;
  cursor: pointer;
  transition: all 0.2s ease;
}
.select-share-btn:hover {
  border-color: #008b4b;
  color: #008b4b;
  background: #f0fdf4;
}
.select-share-btn i {
  color: #008b4b;
}
.share-chip-list {
  min-height: 30px;
  flex: 1;
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
  padding: 3px 8px;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  background: #ffffff;
}
.reminder-empty-hint {
  margin: 4px 0 0;
  padding: 8px 10px;
  border: 1px dashed #d8e0ea;
  border-radius: 6px;
  background: #f8fafc;
  color: #94a3b8;
  font-size: 12px;
}

/* 外贸物流网格 */
.logistics-grid {
  padding: 4px 18px 16px;
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
}
.field-stack {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.field-stack span {
  font-size: 12px;
  font-weight: 600;
  color: #475569;
}
.field-stack .el-select,
.field-stack .el-date-editor {
  width: 100% !important;
}

/* 合同附件上传卡片 */
.contract-upload-grid {
  padding: 4px 18px 10px;
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
}
.contract-upload-grid ::v-deep .el-upload {
  display: block;
  width: 100%;
}
.upload-card {
  width: 100%;
  height: 80px;
  border: 1px dashed #cbd5e1;
  border-radius: 8px;
  background: #f8fafc;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
  color: #475569;
  cursor: pointer;
  transition: all 0.2s ease;
}
.upload-card:hover {
  border-color: #008b4b;
  background: #f0fdf4;
  color: #008b4b;
}
.upload-card i {
  font-size: 18px;
  color: #008b4b;
}
.upload-card span {
  font-weight: 600;
  font-size: 12px;
}
.upload-card small {
  font-size: 11px;
  color: #94a3b8;
}
.contract-meta {
  margin: 0 18px 16px;
  padding: 8px 12px;
  border-radius: 6px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  color: #64748b;
  font-size: 12px;
}

/* ==========================================================================
   底部金额汇总指标栏 (Modern Financial Summary Dashboard)
   ========================================================================== */
.final-grid {
  width: 100%;
}
.summary-bar-modern {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
  display: grid;
  grid-template-columns: 1.4fr repeat(4, 1fr);
  overflow: hidden;
}
.summary-metric-card {
  padding: 14px 20px;
  display: flex;
  flex-direction: column;
  justify-content: center;
  border-right: 1px solid #f1f5f9;
  background: #ffffff;
  transition: all 0.2s ease;
}
.summary-metric-card:last-child {
  border-right: 0;
}
.summary-metric-card.primary {
  background: linear-gradient(135deg, #f0fdf4 0%, #ffffff 100%);
  border-right-color: #e2e8f0;
}
.summary-metric-card .metric-label {
  font-size: 12px;
  font-weight: 600;
  color: #64748b;
  margin-bottom: 6px;
}
.summary-metric-card.primary .metric-label {
  color: #166534;
}
.metric-value-wrap {
  display: flex;
  align-items: baseline;
  gap: 4px;
}
.metric-value-wrap .currency {
  font-size: 16px;
  font-weight: 700;
  color: #008b4b;
}
.metric-value-wrap .amount {
  font-size: 24px;
  font-weight: 800;
  color: #008b4b;
  letter-spacing: -0.5px;
  line-height: 1;
}
.metric-value-wrap .num {
  font-size: 20px;
  font-weight: 700;
  color: #1e293b;
  line-height: 1;
}
.metric-value-wrap .unit {
  font-size: 12px;
  color: #94a3b8;
  font-weight: 500;
}
.metric-value-wrap .text-emerald {
  color: #008b4b;
}
.metric-value-wrap .text-blue {
  color: #2563eb;
}
.metric-value-wrap .text-amber {
  color: #d97706;
}
.metric-value-wrap .text-muted {
  color: #94a3b8;
}

/* 共享人弹窗 */
.share-dialog-body {
  display: grid;
  gap: 12px;
}
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
.share-user-list .el-checkbox {
  margin: 0;
  padding: 8px;
  border-radius: 4px;
}
.share-user-list .el-checkbox:hover {
  background: #f8fafc;
}
.share-user-list span,
.share-user-list small {
  display: block;
}
.share-user-list small {
  margin-top: 2px;
  color: #94a3b8;
  font-size: 11px;
}

/* 预检焦点高亮 */
.precheck-focus {
  outline: 2px solid #f59e0b;
  outline-offset: 2px;
  transition: outline-color 0.25s ease;
}

/* ==========================================================================
   响应式断点适配规则 (Responsive Design)
   ========================================================================== */
@media (max-width: 1400px) {
  .top-cards {
    grid-template-columns: 1fr 1fr;
  }
  .top-cards .delivery-card {
    grid-column: 1 / -1;
  }
  .studio-grid {
    grid-template-columns: 1fr 1fr;
  }
  .studio-grid .attributes-card-section {
    grid-column: 1 / -1;
  }
  .bottom-grid {
    grid-template-columns: 1fr 1fr;
  }
  .bottom-grid .contract-card {
    grid-column: 1 / -1;
  }
  .summary-bar-modern {
    grid-template-columns: 1.3fr repeat(4, 1fr);
  }
}

@media (max-width: 1100px) {
  .top-cards {
    grid-template-columns: 1fr;
  }
  .top-cards .delivery-card {
    grid-column: auto;
  }
  .studio-grid {
    grid-template-columns: 1fr;
  }
  .studio-grid .attributes-card-section {
    grid-column: auto;
  }
  .conversion-cards-grid {
    grid-template-columns: 1fr 1fr;
  }
  .bottom-grid {
    grid-template-columns: 1fr;
  }
  .bottom-grid .contract-card {
    grid-column: auto;
  }
  .logistics-grid {
    grid-template-columns: 1fr 1fr;
  }
  .summary-bar-modern {
    grid-template-columns: 1fr 1fr;
  }
  .summary-bar-modern .summary-metric-card.primary {
    grid-column: 1 / -1;
  }
}

@media (max-width: 768px) {
  .form-toolbar {
    padding: 0 14px;
    height: auto;
    min-height: 52px;
    flex-wrap: wrap;
    gap: 8px;
    padding-top: 6px;
    padding-bottom: 6px;
  }
  .form-layout {
    padding: 10px 12px;
  }
  .order-line-detail {
    margin: 8px 10px 14px;
  }
  .studio-header {
    flex-direction: column;
    align-items: flex-start;
    gap: 10px;
  }
  .studio-header-right {
    width: 100%;
    justify-content: space-between;
  }
  .studio-grid {
    padding: 10px;
  }
  .commercial-grid,
  .studio-form-grid,
  .toggles-cluster,
  .dropdowns-grid {
    grid-template-columns: 1fr;
  }
  .cut-params-row {
    grid-template-columns: 1fr;
  }
  .summary-bar-modern {
    grid-template-columns: 1fr 1fr;
  }
  .summary-bar-modern .summary-metric-card.primary {
    grid-column: 1 / -1;
  }
  .logistics-grid,
  .contract-upload-grid {
    grid-template-columns: 1fr;
  }
  .line-total-bar {
    flex-direction: column;
    align-items: flex-start;
    gap: 10px;
  }
  .total-stats {
    width: 100%;
    justify-content: space-between;
  }
}

@media (max-width: 480px) {
  .info-grid {
    grid-template-columns: 1fr;
  }
  .flag-grid {
    grid-template-columns: 1fr;
  }
  .customer-select-field {
    grid-template-columns: 1fr;
  }
  .conversion-cards-grid {
    grid-template-columns: 1fr;
  }
  .summary-bar-modern {
    grid-template-columns: 1fr;
  }
}
</style>
