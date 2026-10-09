<template>
  <section class="purchase-form-page">
    <div class="page-head">
      <div class="head-left">
        <el-button size="small" icon="el-icon-arrow-left" circle @click="$router.back()" />
        <div class="head-text">
          <div class="title-row">
            <h1>{{ title }}</h1>
            <el-tag size="mini" :type="form.management_scope === 'office' ? 'info' : 'success'">{{ scopeLabel(form.management_scope) }}</el-tag>
            <el-tag size="mini" type="success">{{ type === 'request' ? '采购需求' : type === 'plan' ? '采购计划' : type === 'order' ? '采购订单' : '到货单' }}</el-tag>
            <el-tag v-if="$route.params.id" size="mini" type="info">ID: {{ $route.params.id }}</el-tag>
          </div>
        </div>
      </div>
      <div class="head-actions">
        <el-button size="small" @click="$router.back()">取消返回</el-button>
        <el-button size="small" :disabled="!!scopeIssue || scopeChanging || saving" @click="save(false)">保存草稿</el-button>
        <el-button size="small" type="success" icon="el-icon-check" :disabled="!!scopeIssue || scopeChanging || saving" @click="save(true)">{{ type==='request' ? '确认需求' : type==='receipt' ? '保存' : '提交审核' }}</el-button>
      </div>
    </div>

    <el-alert v-if="scopeIssue" :title="scopeIssue" type="warning" :closable="false" show-icon />

    <!-- 全局统一页面提示条 (对齐主数据中心规范) -->
    <div v-if="type==='request' && form.id && form.request_status!=='draft'" class="erp-page-tip">
      <i class="el-icon-info" />
      <span>此需求尚未转计划。保存修改后回到草稿，需要重新确认后才能转采购计划。</span>
    </div>
    <div v-if="type==='receipt'" class="erp-page-tip">
      <i class="el-icon-info" />
      <span>当前确认仅生成待过账库存记录，不直接更新正式库存余额。正式库存过账由库存模块完成。</span>
    </div>

    <div :class="['form-layout', {'request-layout': ['request', 'plan'].includes(type)}]">
      <section class="form-card basic-info-card">
        <div class="card-head-title">
          <span class="bar-accent"></span>
          <h3>基础信息</h3>
        </div>
        <el-form label-width="96px" size="small">
          <el-form-item label="管理类型" required>
            <el-select :value="form.management_scope" :disabled="scopeLocked" placeholder="请选择管理类型" style="width:100%" @change="changeManagementScope">
              <el-option v-for="scope in scopeOptions" :key="scope.value" :label="scope.label" :value="scope.value" />
            </el-select>
          </el-form-item>
          <div class="grid-4" v-if="type==='request'">
            <el-form-item label="需求单号">
              <el-input v-model="form.request_no" disabled placeholder="系统预占生成">
                <template slot="append">系统预占</template>
              </el-input>
            </el-form-item>
            <el-form-item label="需求日期" required>
              <el-date-picker v-model="form.request_date" value-format="yyyy-MM-dd" placeholder="选择需求日期" style="width: 100%" />
            </el-form-item>
            <el-form-item label="来源类型">
              <el-select v-model="form.source_type" placeholder="请选择来源类型" style="width: 100%">
                <el-option label="手工创建" value="manual" />
                <el-option v-if="form.management_scope === 'factory'" label="生产需求" value="production" />
                <el-option v-if="form.management_scope === 'factory'" label="销售订单" value="sales" />
                <el-option label="库存预警" value="inventory_alert" />
              </el-select>
            </el-form-item>
            <el-form-item label="单据状态">
              <el-tag size="small" :type="form.request_status === 'confirmed' ? 'success' : 'info'">
                {{ form.id ? ({ draft: '草稿', confirmed: '已确认', closed: '已关闭', cancelled: '已取消', partially_planned: '部分转计划', planned: '已转计划' }[form.request_status] || form.request_status) : '草稿' }}
              </el-tag>
            </el-form-item>
            <el-form-item label="来源单号">
              <el-input v-model="form.source_no" placeholder="选填，关联外部单号" />
            </el-form-item>
            <el-form-item label="备注说明" class="remark-span-3">
              <el-input v-model="form.remark" placeholder="选填，请输入需求补充说明或业务要求..." />
            </el-form-item>
          </div>
          <div class="grid-4" v-if="type==='plan'">
            <el-form-item label="计划单号">
              <el-input v-model="form.plan_no" disabled placeholder="系统预占生成">
                <template slot="append">系统预占</template>
              </el-input>
            </el-form-item>
            <el-form-item label="计划日期" required>
              <el-date-picker v-model="form.plan_date" value-format="yyyy-MM-dd" placeholder="选择计划日期" style="width: 100%" />
            </el-form-item>
            <el-form-item label="需求来源">
              <el-input :value="planSourceText" disabled placeholder="手工创建" />
            </el-form-item>
            <el-form-item label="单据状态">
              <el-tag size="small" :type="planStatusTag">
                {{ planStatusLabel }}
              </el-tag>
            </el-form-item>
            <el-form-item label="备注说明" class="remark-span-4">
              <el-input v-model="form.remark" placeholder="选填，请输入计划补充说明或业务要求..." />
            </el-form-item>
          </div>
          <div class="grid-3" v-if="type==='order'">
            <el-form-item label="采购订单号"><el-input v-model="form.purchase_order_no" disabled placeholder="系统预占生成"><template slot="append">系统预占</template></el-input></el-form-item>
            <el-form-item label="供应商"><el-select v-model="form.supplier_id" :disabled="type==='order' && Boolean(form.plan_id)" filterable style="width: 100%"><el-option v-for="s in validSuppliers" :key="s.id" :label="`${s.supplier_code} / ${s.supplier_name}`" :value="s.id" /></el-select></el-form-item>
            <el-form-item label="订单日期"><el-date-picker v-model="form.order_date" value-format="yyyy-MM-dd" style="width: 100%" /></el-form-item>
            <el-form-item label="预计到货"><el-date-picker v-model="form.expected_arrival_date" value-format="yyyy-MM-dd" style="width: 100%" /></el-form-item>
            <el-form-item label="币种"><el-input v-model="form.currency" /></el-form-item>
            <el-form-item label="税率口径"><el-select v-model="form.tax_mode" style="width: 100%"><el-option label="含税" value="tax_included" /><el-option label="未税" value="tax_excluded" /></el-select></el-form-item>
            <el-form-item label="结算方式"><el-input v-model="form.settlement_method" /></el-form-item>
            <el-form-item label="交付方式"><el-input v-model="form.delivery_method" /></el-form-item>
            <el-form-item label="运费"><el-input-number v-model="form.freight_amount" :min="0" style="width: 100%" /></el-form-item>
          </div>
          <div class="grid-3" v-if="type==='receipt'">
            <el-form-item label="到货单号"><el-input v-model="form.receipt_no" disabled placeholder="系统预占生成"><template slot="append">系统预占</template></el-input></el-form-item>
            <el-form-item label="供应商"><el-select v-model="form.supplier_id" filterable style="width: 100%"><el-option v-for="s in validSuppliers" :key="s.id" :label="`${s.supplier_code} / ${s.supplier_name}`" :value="s.id" /></el-select></el-form-item>
            <el-form-item label="到货日期"><el-date-picker v-model="form.receipt_date" value-format="yyyy-MM-dd" style="width: 100%" /></el-form-item>
            <el-form-item label="确认状态"><el-tag size="small" type="info">草稿</el-tag></el-form-item>
            <el-form-item label="库存过账状态"><el-tag size="small" type="warning">待库存过账</el-tag></el-form-item>
          </div>
          <el-form-item v-if="!['request', 'plan'].includes(type)" label="备注说明">
            <el-input v-model="form.remark" type="textarea" :rows="2" placeholder="请输入需求补充说明或业务要求..." />
          </el-form-item>
        </el-form>
      </section>

      <main class="detail-workspace">
        <section v-if="type==='request'" class="form-card items-card">
          <div class="section-title">
            <div class="title-with-bar">
              <span class="bar-accent"></span>
              <h3>需求物料明细</h3>
              <el-tag size="mini" type="success">共 {{ form.items.length }} 行物料</el-tag>
            </div>
            <div class="line-actions">
              <el-button size="small" type="success" icon="el-icon-plus" @click="openBatchItemPicker">选择物料 (多选)</el-button>
              <el-button size="small" icon="el-icon-circle-plus-outline" @click="addRequestLine">添加空白行</el-button>
              <el-button size="small" type="danger" plain icon="el-icon-delete" :disabled="!selectedRequestRows.length" @click="removeSelectedLines">批量删除</el-button>
              <el-button v-if="form.items.length > 0" size="small" type="text" class="danger-link" @click="clearAllLines">清空明细</el-button>
            </div>
          </div>
          <el-table
            :data="form.items"
            size="small"
            border
            stripe
            class="items-table"
            @selection-change="handleRequestSelectionChange"
          >
            <el-table-column type="selection" width="48" align="center" />
            <el-table-column type="index" label="#" width="45" align="center" />
            <el-table-column label="物料信息" min-width="220">
              <template slot-scope="{row}">
                <div v-if="row.item_id" class="item-cell-box">
                  <div class="item-cell-info">
                    <span class="code-badge">{{ itemCode(row.item_id) }}</span>
                    <strong class="item-name-bold" :title="itemNameOnly(row.item_id)">{{ itemNameOnly(row.item_id) }}</strong>
                  </div>
                  <el-button type="text" size="mini" class="theme-link-btn" icon="el-icon-refresh" @click.stop="openItemPicker(row)">更换</el-button>
                </div>
                <div v-else class="empty-item-cell">
                  <el-button size="mini" plain type="success" icon="el-icon-search" @click.stop="openItemPicker(row)">选择物料</el-button>
                </div>
              </template>
            </el-table-column>
            <el-table-column label="规格型号" min-width="150">
              <template slot-scope="{row}">
                <el-input
                  v-model="row.spec_model"
                  size="small"
                  placeholder="规格型号"
                  :disabled="!row.item_id"
                />
              </template>
            </el-table-column>
            <el-table-column label="采购数量" width="125">
              <template slot-scope="{row}">
                <el-input-number :key="`${row.item_id || 'empty'}:${row.purchase_unit_id || 'unit'}`" v-model="row.purchase_quantity" :min="purchaseStep(row)" :step="purchaseStep(row)" :disabled="!row.item_id" @change="refreshPlanning(row)" size="small" controls-position="right" style="width: 100%" />
              </template>
            </el-table-column>
            <el-table-column label="采购单位" width="175">
              <template slot-scope="{row}">
                <el-select v-model="row.purchase_unit_id" size="small" placeholder="选择采购单位" :disabled="!row.item_id" style="width: 100%" @change="refreshPlanning(row)">
                  <el-option v-for="c in row._conversionOptions || []" :key="c.purchase_unit_id" :label="purchaseOptionLabel(c)" :value="c.purchase_unit_id" />
                </el-select>
              </template>
            </el-table-column>
            <el-table-column label="折合库存" width="125">
              <template slot-scope="{row}">
                <div v-if="row.item_id" class="stock-conversion-cell">
                  <span v-if="row._planningPending" class="planning-calc-hint"><i class="el-icon-loading" /> 计算中</span>
                  <el-tooltip v-else-if="row._planningError" :content="row._planningError" placement="top">
                    <span class="planning-error-badge"><i class="el-icon-warning-outline" /> 异常</span>
                  </el-tooltip>
                  <div v-else class="stock-qty-display">
                    <strong class="stock-num highlight-green">{{ plannedBaseQty(row) }}</strong>
                    <span class="stock-unit-label">{{ plannedBaseUnit(row) }}</span>
                    <el-tooltip
                      v-if="isConvertedUnit(row)"
                      :content="conversionFormulaTip(row)"
                      placement="top"
                    >
                      <i class="el-icon-info stock-info-icon" />
                    </el-tooltip>
                  </div>
                </div>
                <span v-else class="cell-empty-dash">-</span>
              </template>
            </el-table-column>
            <el-table-column label="期望到货" width="155">
              <template slot-scope="{row}">
                <el-date-picker v-model="row.expected_date" value-format="yyyy-MM-dd" placeholder="选择交期" size="small" style="width: 100%" />
              </template>
            </el-table-column>
            <el-table-column label="目标仓库" width="160">
              <template slot-scope="{row}">
                <el-select v-model="row.warehouse_id" clearable placeholder="请选择仓库" size="small" style="width: 100%">
                  <el-option v-for="w in scopedWarehouses" :key="w.id" :label="w.warehouse_name" :value="w.id" />
                </el-select>
              </template>
            </el-table-column>
            <el-table-column label="优先级" width="105" align="center">
              <template slot-scope="{row}">
                <el-select v-model="row.priority" size="small" style="width: 100%">
                  <el-option label="高" value="high" />
                  <el-option label="中" value="normal" />
                  <el-option label="低" value="low" />
                </el-select>
              </template>
            </el-table-column>
            <el-table-column label="备注" min-width="140">
              <template slot-scope="{row}">
                <el-input v-model="row.remark" placeholder="选填行备注" size="small" />
              </template>
            </el-table-column>
            <el-table-column label="操作" width="70" align="center">
              <template slot-scope="{$index}">
                <el-button type="text" class="danger-link" icon="el-icon-delete" @click="form.items.splice($index,1)">删除</el-button>
              </template>
            </el-table-column>
          </el-table>

          <!-- 需求表格底部统计与快捷栏 -->
          <div class="table-summary-bar">
            <div class="batch-quick-tools" v-if="selectedRequestRows.length">
              <span class="selected-hint">已勾选 <strong>{{ selectedRequestRows.length }}</strong> 行：</span>
              <el-button size="mini" plain icon="el-icon-date" @click="batchSetDeliveryDate">批量设交期</el-button>
              <el-dropdown trigger="click" @command="batchSetWarehouse">
                <el-button size="mini" plain icon="el-icon-office-building">
                  批量设仓库 <i class="el-icon-arrow-down el-icon--right" />
                </el-button>
                <el-dropdown-menu slot="dropdown">
                  <el-dropdown-item v-for="w in scopedWarehouses" :key="w.id" :command="w.id">{{ w.warehouse_name }}</el-dropdown-item>
                </el-dropdown-menu>
              </el-dropdown>
            </div>
            <div v-else></div>
            <div class="summary-metrics">
              <div class="metric-item">
                <span class="metric-label">物料品种：</span>
                <strong class="metric-val">{{ form.items.filter(i => i.item_id).length }} 种</strong>
              </div>
              <div class="metric-item">
                <span class="metric-label">采购总数量：</span>
                <strong class="metric-val highlight-green">{{ quantitySummary }}</strong>
              </div>
              <div class="metric-item" v-if="hasConversionDifference">
                <span class="metric-label">折合库存总量：</span>
                <strong class="metric-val base-stock-val">{{ baseQuantitySummary }}</strong>
              </div>
            </div>
          </div>
        </section>

        <section v-if="type==='plan'" class="form-card items-card">
          <div class="section-title">
            <div class="title-with-bar">
              <span class="bar-accent"></span>
              <h3>计划物料明细及供应商拆分</h3>
              <el-tag size="mini" type="success">共 {{ form.items.length }} 行物料</el-tag>
            </div>
            <div class="line-actions">
              <el-button size="small" type="success" icon="el-icon-plus" @click="openBatchItemPicker">选择物料 (多选)</el-button>
              <el-button size="small" icon="el-icon-circle-plus-outline" @click="addPlanItem">添加空白物料</el-button>
              <el-button size="small" type="danger" plain icon="el-icon-delete" :disabled="!selectedPlanRows.length" @click="removeSelectedPlanLines">批量删除</el-button>

              <el-button v-if="form.items.length > 0" size="small" type="text" class="danger-link" @click="clearAllPlanLines">清空明细</el-button>
            </div>
          </div>

          <el-table
            ref="planTable"
            :data="form.items"
            size="small"
            border
            stripe
            row-key="_rowKey"
            class="items-table plan-items-table"
            @selection-change="handlePlanSelectionChange"
          >

            <el-table-column type="selection" width="48" align="center" />
            <el-table-column type="index" label="#" width="45" align="center" />
            <el-table-column label="物料信息" min-width="210">
              <template slot-scope="{row}">
                <div v-if="row.item_id" class="item-cell-box">
                  <div class="item-cell-info">
                    <span class="code-badge">{{ itemCode(row.item_id) }}</span>
                    <strong class="item-name-bold" :title="itemNameOnly(row.item_id)">{{ itemNameOnly(row.item_id) }}</strong>
                  </div>
                  <el-button type="text" size="mini" class="theme-link-btn" icon="el-icon-refresh" @click.stop="openItemPicker(row)">更换</el-button>
                </div>
                <div v-else class="empty-item-cell">
                  <el-button size="mini" plain type="success" icon="el-icon-search" @click.stop="openItemPicker(row)">选择物料</el-button>
                </div>
              </template>
            </el-table-column>
            <el-table-column label="规格型号" min-width="140">
              <template slot-scope="{row}">
                <el-input
                  v-model="row.spec_model"
                  size="small"
                  placeholder="规格型号"
                  :disabled="!row.item_id"
                />
              </template>
            </el-table-column>
            <el-table-column label="计划采购量" width="125">
              <template slot-scope="{row}">
                <el-input-number :key="`${row.item_id || 'empty'}:${row.purchase_unit_id || 'unit'}`" v-model="row.purchase_quantity" :min="purchaseStep(row)" :step="purchaseStep(row)" :disabled="!row.item_id" size="small" controls-position="right" style="width: 100%" @change="refreshPlanning(row)" />
              </template>
            </el-table-column>
            <el-table-column label="采购单位" width="175">
              <template slot-scope="{row}">
                <el-select v-model="row.purchase_unit_id" size="small" placeholder="选择采购单位" :disabled="!row.item_id" style="width: 100%" @change="changePlanUnit(row)">
                  <el-option v-for="c in row._conversionOptions || []" :key="c.purchase_unit_id" :label="purchaseOptionLabel(c)" :value="c.purchase_unit_id" />
                </el-select>
              </template>
            </el-table-column>
            <el-table-column label="折合库存" width="115">
              <template slot-scope="{row}">
                <div v-if="row.item_id" class="stock-conversion-cell">
                  <span v-if="row._planningPending" class="planning-calc-hint"><i class="el-icon-loading" /> 计算中</span>
                  <el-tooltip v-else-if="row._planningError" :content="row._planningError" placement="top">
                    <span class="planning-error-badge"><i class="el-icon-warning-outline" /> 换算异常</span>
                  </el-tooltip>
                  <template v-else>
                    <span class="stock-qty-text">{{ plannedBaseQty(row) }} {{ plannedBaseUnit(row) }}</span>
                    <el-tooltip v-if="isConvertedUnit(row)" :content="conversionFormulaTip(row)" placement="top">
                      <i class="el-icon-info unit-convert-icon" />
                    </el-tooltip>
                  </template>
                </div>
                <span v-else class="text-muted">--</span>
              </template>
            </el-table-column>
            <el-table-column label="期望交期" width="135">
              <template slot-scope="{row}">
                <el-date-picker v-model="row.expected_date" value-format="yyyy-MM-dd" placeholder="选择交期" size="small" style="width: 100%" />
              </template>
            </el-table-column>
            <el-table-column label="供应商分配及报价" min-width="280">
              <template slot-scope="{row, $index}">
                <div
                  v-if="hasAllocatedSupplier(row)"
                  class="plan-split-cell-card clickable"
                  @click.stop="openSupplierSplitDialog(row, $index)"
                >
                  <!-- 顶部配平状态与操作栏 -->
                  <div class="split-card-header">
                    <div class="header-status-group">
                      <el-tag size="mini" :type="allocationTag(row)" effect="light" class="alloc-tag">
                        <i :class="isPlanBalanced(row) ? 'el-icon-check' : 'el-icon-warning-outline'" />
                        {{ allocationLabel(row) }}
                      </el-tag>
                      <span class="split-count-badge">{{ allocatedSuppliersCount(row) }} 家供应商</span>
                      <el-tag v-if="hasUnassignedSplit(row)" size="mini" type="danger" effect="plain" class="missing-supplier-tag">未定供应商</el-tag>
                    </div>
                    <span class="split-edit-btn" title="点击修改供应商分配">
                      <i class="el-icon-edit" /> 调整
                    </span>
                  </div>

                  <!-- 供应商拆分列表 -->
                  <div class="split-card-list">
                    <div
                      v-for="(split, splitIndex) in row.splits"
                      :key="split.id || splitIndex"
                      class="split-card-row"
                    >
                      <div class="supplier-info" :title="split.supplier_id ? supplierName(split.supplier_id) : '待选供应商'">
                        <i class="el-icon-office-building supplier-ico" />
                        <span :class="['supplier-name-txt', !split.supplier_id ? 'text-warning-bold text-danger' : '']">
                          {{ split.supplier_id ? supplierName(split.supplier_id) : '【未选供应商】' }}
                        </span>
                      </div>
                      <div class="split-qty-price-pill">
                        <span class="pill-qty">{{ split.purchase_quantity }} {{ planningUnitName(split) }}</span>
                        <span class="pill-dot">·</span>
                        <span class="pill-price">¥{{ money(split.purchase_unit_price) }}</span>
                      </div>
                    </div>
                  </div>
                </div>

                <div v-else class="plan-split-empty-card clickable" @click.stop="openSupplierSplitDialog(row, $index)">
                  <div class="empty-main-info">
                    <el-tag size="mini" type="danger" effect="plain" class="empty-unallocated-tag">
                      <i class="el-icon-warning" /> 未分配供应商
                    </el-tag>
                    <span class="empty-subtext">尚未选定供货商与价格</span>
                  </div>
                  <span class="empty-action-pill"><i class="el-icon-circle-plus-outline" /> 分配供应商</span>
                </div>
              </template>
            </el-table-column>
            <el-table-column label="预计金额" width="115" align="right">
              <template slot-scope="{row}">
                <strong class="text-money">¥{{ money(lineTotalAmount(row)) }}</strong>
              </template>
            </el-table-column>
            <el-table-column label="操作" width="130" align="center">
              <template slot-scope="{row, $index}">
                <el-button
                  type="text"
                  :class="hasAllocatedSupplier(row) ? 'action-link-theme' : 'action-link-theme highlight-alloc-action'"
                  :icon="hasAllocatedSupplier(row) ? 'el-icon-setting' : 'el-icon-circle-plus-outline'"
                  @click.stop="openSupplierSplitDialog(row, $index)"
                >
                  {{ hasAllocatedSupplier(row) ? '分配供应商' : '分配供应商' }}
                </el-button>
                <el-button type="text" class="danger-link" icon="el-icon-delete" @click="form.items.splice($index, 1)">删除</el-button>
              </template>
            </el-table-column>
          </el-table>

          <!-- 计划表格底部统计与快捷栏 -->
          <div class="table-summary-bar">
            <div class="batch-quick-tools" v-if="selectedPlanRows.length">
              <span class="selected-hint">已勾选 <strong>{{ selectedPlanRows.length }}</strong> 行：</span>
              <el-button size="mini" plain icon="el-icon-date" @click="batchSetPlanDeliveryDate">批量设交期</el-button>
            </div>
            <div v-else></div>
            <div class="summary-metrics">
              <div class="metric-item">
                <span class="metric-label">物料品种：</span>
                <strong class="metric-val">{{ form.items.filter(i => i.item_id).length }} 种</strong>
              </div>
              <div class="metric-item">
                <span class="metric-label">采购总数量：</span>
                <strong class="metric-val highlight-green">{{ quantitySummary }}</strong>
              </div>
              <div class="metric-item" v-if="hasConversionDifference">
                <span class="metric-label">折合库存总量：</span>
                <strong class="metric-val base-stock-val">{{ baseQuantitySummary }}</strong>
              </div>
              <div class="metric-item">
                <span class="metric-label">预计总金额：</span>
                <strong class="metric-val highlight-green">¥{{ money(totalAmount) }}</strong>
              </div>
              <div class="metric-item">
                <span class="metric-label">供应商分配：</span>
                <strong class="metric-val" :class="unallocatedSupplierItemsCount === 0 ? 'text-success' : 'text-danger'">
                  <i :class="unallocatedSupplierItemsCount === 0 ? 'el-icon-circle-check' : 'el-icon-warning'" />
                  {{ unallocatedSupplierItemsCount === 0 ? `全部已分配 (${uniqueAllocatedSuppliersCount}家)` : `${unallocatedSupplierItemsCount} 项未分配供应商` }}
                </strong>
              </div>
              <div class="metric-item">
                <span class="metric-label">采购量配平：</span>
                <strong class="metric-val" :class="planAllocationComplete ? 'text-success' : 'text-warning'">
                  {{ planAllocationComplete ? '全部已配平' : `${unallocatedPlanItemsCount} 项待配平` }}
                </strong>
              </div>
            </div>
          </div>
        </section>

        <section v-if="['order', 'receipt'].includes(type)" class="form-card">
          <div class="section-title"><div class="title-with-bar"><span class="bar-accent"></span><h3>{{ type==='order' ? '采购明细' : '到货明细' }}</h3></div><el-button type="text" icon="el-icon-plus" :disabled="type==='order' && Boolean(form.plan_id)" @click="addLine">添加明细</el-button></div>
          <div v-if="type==='order'" class="recommend-card">
            <div class="section-title">
              <div><h3>当前行供应商推荐</h3><span>点击采购明细行后查询；按统一权重综合价格、质量、交付、退货和合作表现</span></div>
              <el-button size="mini" :loading="recommendLoading" @click="loadRecommendations">刷新推荐</el-button>
            </div>
            <el-table :data="recommendations" size="mini" border empty-text="请选择一行采购物料后获取推荐">
              <el-table-column prop="supplier_name" label="供应商" min-width="130" show-overflow-tooltip />
              <el-table-column label="能力" width="82"><template slot-scope="{row}">{{ capabilityText(row.capability_level) }}</template></el-table-column>
              <el-table-column label="可比价" width="90"><template slot-scope="{row}">{{ row.comparable_price == null ? '-' : money(row.comparable_price) }}</template></el-table-column>
              <el-table-column label="综合评分" width="82" align="right"><template slot-scope="{row}">{{ row.recommendation_score == null ? '-' : row.recommendation_score }}</template></el-table-column>
              <el-table-column label="推荐依据" min-width="150"><template slot-scope="{row}"><el-tooltip :content="row.recommendation_explanation || basisText(row.recommendation_basis)" placement="top"><el-tag size="mini" :type="row.recommended ? 'success' : 'info'">{{ basisText(row.recommendation_basis) }}</el-tag></el-tooltip></template></el-table-column>
              <el-table-column label="操作" width="76"><template slot-scope="{row}"><el-button type="text" size="mini" :disabled="!row.auto_selectable || Boolean(form.plan_id)" @click="chooseRecommendation(row)">选用</el-button></template></el-table-column>
            </el-table>
          </div>
          <el-table class="purchase-lines-table" :data="form.items" size="mini" border highlight-current-row @row-click="selectLine">
            <el-table-column label="物料" width="320"><template slot-scope="{row}"><el-input class="item-picker-input" :value="itemName(row.item_id)" readonly><el-button slot="append" icon="el-icon-search" :disabled="type==='order' && Boolean(form.plan_id)" @click.stop="openItemPicker(row)">选择</el-button></el-input></template></el-table-column>
            <el-table-column :label="type==='order' ? '采购包装数量' : '到货采购数量'" width="126"><template slot-scope="{row}"><el-input v-model.number="row.qty" type="number" min="0.0001" :step="purchaseStep(row)" :disabled="type==='order' && Boolean(form.plan_id)" @input="syncReceiptActualQty(row)" /></template></el-table-column>
            <el-table-column width="200">
              <template slot="header"><span>采购单位 <el-tooltip content="来自当前 Item 已生效的采购换算；库存基本单位按 1:1 补充" placement="top"><i class="el-icon-question unit-source-help" /></el-tooltip></span></template>
              <template slot-scope="{row}"><el-select v-if="type==='order'" v-model="row.purchase_unit_id" :disabled="Boolean(form.plan_id)" placeholder="请选择采购单位" no-data-text="当前Item未维护有效采购换算" @change="changePurchaseUnit(row)"><el-option v-for="c in row._conversionOptions || []" :key="c.id || `base-${c.purchase_unit_id}`" :label="purchaseOptionLabel(c)" :value="c.purchase_unit_id" /></el-select><span v-else>{{ row.purchase_unit_name_snapshot || conversionUnitName(row) }}</span></template>
            </el-table-column>
            <el-table-column label="换算因子" width="88" align="right"><template slot-scope="{row}">{{ conversionFactor(row) }}</template></el-table-column>
            <el-table-column :label="type==='order' ? '计划基本数量' : '标准基本数量'" width="120" align="right"><template slot-scope="{row}">{{ standardBaseQty(row) }} {{ baseUnitName(row) }}</template></el-table-column>
            <el-table-column v-if="type==='receipt'" label="实际基本数量" width="132"><template slot-scope="{row}"><el-input v-model.number="row.actual_base_qty" type="number" :disabled="!actualConversionAllowed(row)" min="0" step="0.000001" /></template></el-table-column>
            <el-table-column v-if="type==='receipt'" label="差异数量" width="110" align="right"><template slot-scope="{row}">{{ differenceQty(row) }} {{ baseUnitName(row) }}</template></el-table-column>
            <el-table-column v-if="type==='receipt'" label="差异原因" min-width="150"><template slot-scope="{row}"><el-input v-model="row.difference_reason" :disabled="!hasDifference(row)" :placeholder="hasDifference(row) ? '必填' : '无差异'" /></template></el-table-column>
            <el-table-column :label="type==='order' ? '包装单价' : '单价'" :width="type==='order' ? 146 : 132"><template slot-scope="{row}"><el-input-number class="line-number-input" v-model="row.unit_price" :min="0" controls-position="right" /></template></el-table-column>
            <el-table-column v-if="type==='order'" label="基本单位单价" width="120" align="right"><template slot-scope="{row}">{{ baseUnitPrice(row) }}</template></el-table-column>
            <el-table-column v-if="type==='order'" label="税率（%）" width="136"><template slot-scope="{row}"><el-input-number class="line-number-input" v-model="row.tax_rate" :min="0" :max="100" controls-position="right" /></template></el-table-column>
            <el-table-column v-if="type==='receipt'" label="合格/不合格" width="190"><template slot-scope="{row}"><el-input v-model.number="row.qualified_qty" type="number" min="0" step="0.0001" /><el-input v-model.number="row.unqualified_qty" type="number" min="0" step="0.0001" /></template></el-table-column>
            <el-table-column v-if="type==='receipt'" label="批次号" width="150"><template slot-scope="{row}"><el-input v-model="row.batch_no" /></template></el-table-column>
            <el-table-column v-if="type==='receipt'" label="设备编号 / 序列号" min-width="330"><template slot-scope="{row}">
              <div v-if="isSerialManaged(row)" class="serial-entry">
                <div class="serial-entry-tools"><span>{{ serialTrackingMode(row)==='required' ? '必须逐件编号' : '按需逐件编号' }}</span><el-button size="mini" type="success" plain @click.stop="generateLineSerials(row)">{{ serialGenerationButtonText(row) }}</el-button></div>
                <el-input v-model="row.serial_text" type="textarea" :rows="3" resize="vertical" placeholder="供应商SN可直接粘贴；每台一行" @input="markSupplierSerials(row)" />
                <div v-if="serialNumberList(row.serial_text).length" class="serial-number-panel">
                  <div class="serial-number-summary"><span>已录入 {{ serialNumberList(row.serial_text).length }} 个</span><el-button type="text" size="mini" icon="el-icon-printer" @click.stop="printSerialLabels(row)">全部打印</el-button></div>
                  <div class="serial-number-list">
                    <div v-for="serialNo in serialNumberList(row.serial_text)" :key="serialNo" class="serial-number-item"><span :title="serialNo">{{ serialNo }}</span><el-button type="text" size="mini" icon="el-icon-printer" @click.stop="printSerialLabels(row, serialNo)">打印</el-button></div>
                  </div>
                </div>
              </div>
              <span v-else>无需单件编号</span>
            </template></el-table-column>
            <el-table-column v-if="type==='receipt'" label="目标仓库" width="150"><template slot-scope="{row}"><el-select v-model="row.warehouse_id" clearable><el-option v-for="w in scopedWarehouses" :key="w.id" :label="w.warehouse_name" :value="w.id" /></el-select></template></el-table-column>
            <el-table-column v-if="type==='receipt'" label="目标库位" width="150"><template slot-scope="{row}"><el-select v-model="row.location_id" clearable><el-option v-for="l in filteredLocations(row.warehouse_id)" :key="l.id" :label="l.location_name" :value="l.id" /></el-select></template></el-table-column>
            <el-table-column label="预计到货" width="158"><template slot-scope="{row}"><el-date-picker v-model="row.expected_arrival_date" value-format="yyyy-MM-dd" /></template></el-table-column>
            <el-table-column label="备注" width="147"><template slot-scope="{row}"><el-input v-model="row.remark" /></template></el-table-column>
            <el-table-column label="操作" width="70"><template slot-scope="{$index}"><el-button type="text" class="danger-link" :disabled="type==='order' && Boolean(form.plan_id)" @click="form.items.splice($index,1)">删除</el-button></template></el-table-column>
          </el-table>
        </section>
      </main>

      <aside v-if="!['request', 'plan'].includes(type)">
        <section class="form-card summary-card">
          <div class="card-head-title">
            <span class="bar-accent"></span>
            <h3>金额 / 数量合计</h3>
          </div>
          <dl class="stat-dl">
            <dt>物料行数</dt><dd>{{ form.items.length }} 行</dd>
            <dt>数量汇总</dt><dd class="green-text">{{ quantitySummary }}</dd>
            <template v-if="type!=='request'">
              <dt>未税金额</dt><dd>¥{{ money(untaxedAmount) }}</dd>
              <dt>税额</dt><dd>¥{{ money(taxAmount) }}</dd>
              <dt>含税金额</dt><dd class="grand-total">¥{{ money(totalAmount) }}</dd>
            </template>
            <dt v-if="type==='plan'">预计订单数</dt><dd v-if="type==='plan'">{{ expectedOrderCount }} 张</dd>
          </dl>
        </section>
      </aside>
    </div>

    <purchase-attachment-panel
      v-if="type==='order'"
      title="采购附件"
      document-type="order"
      :document-id="$route.params.id || null"
      :draft-token="attachmentDraftToken"
      :initial-attachments="form.attachments || []"
    />

        <!-- 采购计划：供应商分配与报价模态弹窗 -->
    <el-dialog
      :visible.sync="supplierDialogVisible"
      title="分配供应商及报价"
      width="1120px"
      top="5vh"
      append-to-body
      custom-class="supplier-split-dialog"
      :close-on-click-modal="false"
    >
      <div v-if="activeSplitRow" class="split-dialog-content">
        <!-- 1. 当前物料信息条 -->
        <div class="split-mat-card">
          <div class="mat-card-header">
            <div class="mat-title-area">
              <span class="mat-order-badge">第 {{ activeSplitValidIndex + 1 }} 项</span>
              <span class="code-badge">{{ itemCode(activeSplitRow.item_id) }}</span>
              <strong class="mat-name-txt">{{ itemNameOnly(activeSplitRow.item_id) }}</strong>
              <span class="mat-spec-pill">规格型号：{{ activeSplitRow.spec_model || itemSpec(activeSplitRow.item_id) || '无规格' }}</span>
            </div>
            <div class="mat-qty-pills">
              <span class="plan-pill">计划采购量：<strong>{{ activeSplitRow.purchase_quantity }} {{ planningUnitName(activeSplitRow) }}</strong></span>
              <span class="stock-pill">折合库存：<strong>{{ plannedBaseQty(activeSplitRow) }} {{ plannedBaseUnit(activeSplitRow) }}</strong></span>
            </div>
          </div>

          <!-- 配平进度与差额 -->
          <div class="mat-balance-banner">
            <div class="balance-labels">
              <span>计划折合库存: <strong>{{ plannedBaseQty(activeSplitRow) }} {{ plannedBaseUnit(activeSplitRow) }}</strong></span>
              <span class="divider">/</span>
              <span>已分配: <strong class="text-green">{{ allocated(activeSplitRow) }} {{ plannedBaseUnit(activeSplitRow) }}</strong></span>
              <span class="divider">/</span>
              <span>{{ allocationState(activeSplitRow).delta < 0 ? '超出计划' : '未分配差额' }}: <strong :class="allocationState(activeSplitRow).delta !== 0 ? 'text-danger' : 'text-muted'">{{ Math.abs(allocationState(activeSplitRow).delta) }} {{ plannedBaseUnit(activeSplitRow) }}</strong></span>
              <el-tag size="mini" :type="allocationTag(activeSplitRow)" style="margin-left: 10px;">
                <i :class="isPlanBalanced(activeSplitRow) ? 'el-icon-check' : 'el-icon-warning-outline'"></i>
                {{ allocationLabel(activeSplitRow) }}
              </el-tag>
            </div>
            <div class="progress-bar-track">
              <div
                class="progress-bar-fill"
                :style="{
                  width: allocationState(activeSplitRow).percent + '%',
                  backgroundColor: isPlanBalanced(activeSplitRow) ? '#008b4b' : (allocationState(activeSplitRow).delta < 0 ? '#ef4444' : '#f59e0b')
                }"
              ></div>
            </div>
          </div>
        </div>

        <!-- 2. 供应商列表工具栏与智能推荐 -->
        <div class="split-table-toolbar">
          <div class="toolbar-title">
            <strong>供应商分配与供货确认</strong>
            <span class="hint-text">（可分配多家供应商共同供货，单价与交期分别配置）</span>
          </div>
          <div class="toolbar-btns">
            <el-button size="mini" type="success" icon="el-icon-plus" @click="addSplitToActiveRow">添加供应商</el-button>
            <el-button size="mini" plain type="success" icon="el-icon-magic-stick" @click="toggleRecommendInDialog">
              {{ showRecommendInDialog ? '收起推荐' : '智能推荐供应商' }}
            </el-button>
          </div>
        </div>

        <!-- 推荐面板 -->
        <div v-if="showRecommendInDialog" class="dialog-recommend-box">
          <div class="recommend-head">
            <span><i class="el-icon-magic-stick"></i> 系统智能推荐供应商候选（基于历史采购、质量评分与交付率）</span>
            <el-button size="mini" type="text" :loading="recommendLoading" @click="loadRecommendations">刷新推荐</el-button>
          </div>
          <el-table :data="recommendations" size="mini" border stripe empty-text="暂无历史推荐数据">
            <el-table-column prop="supplier_name" label="供应商名称" min-width="150" show-overflow-tooltip />
            <el-table-column label="能力级别" width="85">
              <template slot-scope="scope">{{ capabilityText(scope.row.capability_level) }}</template>
            </el-table-column>
            <el-table-column label="可比单价" width="95" align="right">
              <template slot-scope="scope">{{ scope.row.comparable_price == null ? '-' : '¥' + money(scope.row.comparable_price) }}</template>
            </el-table-column>
            <el-table-column label="综合评分" width="80" align="right">
              <template slot-scope="scope">{{ scope.row.recommendation_score == null ? '-' : scope.row.recommendation_score }}</template>
            </el-table-column>
            <el-table-column label="推荐依据" min-width="130">
              <template slot-scope="scope">
                <el-tag size="mini" :type="scope.row.recommended ? 'success' : 'info'">{{ basisText(scope.row.recommendation_basis) }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column label="操作" width="76" align="center">
              <template slot-scope="scope">
                <el-button type="text" size="mini" :disabled="!scope.row.auto_selectable" @click="chooseRecommendationInDialog(scope.row)">
                  选用
                </el-button>
              </template>
            </el-table-column>
          </el-table>
        </div>

        <!-- 供应商拆分表格 -->
        <el-table :data="activeSplitRow.splits || []" size="small" border stripe class="dialog-splits-table" empty-text="暂未分配供应商，请点击上方「添加供应商」">
          <el-table-column type="index" label="#" width="45" align="center" />
          <el-table-column label="供应商 *" min-width="210">
            <template slot-scope="scope">
              <el-select
                v-model="scope.row.supplier_id"
                filterable
                size="small"
                placeholder="请选择供应商"
                style="width: 100%"
                @change="handleDialogSplitSupplierChange(activeSplitRow, scope.row)"
              >
                <el-option
                  v-for="s in validSuppliers"
                  :key="s.id"
                  :label="s.supplier_name + (s.supplier_code ? ' (' + s.supplier_code + ')' : '')"
                  :value="s.id"
                />
              </el-select>
            </template>
          </el-table-column>
          <el-table-column label="采购单位" width="160">
            <template slot-scope="scope">
              <div class="table-cell-unit-wrapper">
                <el-select
                  v-model="scope.row.purchase_unit_id"
                  size="small"
                  style="width: 100%"
                  :loading="Boolean(scope.row._planningPending)"
                  @change="handleDialogSplitUnitChange(activeSplitRow, scope.row)"
                >
                  <el-option
                    v-for="c in scope.row._conversionOptions || activeSplitRow._conversionOptions || []"
                    :key="c.purchase_unit_id"
                    :label="purchaseOptionLabel(c)"
                    :value="c.purchase_unit_id"
                  />
                </el-select>
                <el-tooltip v-if="scope.row._planningError" :content="scope.row._planningError" placement="top">
                  <i class="el-icon-warning text-danger unit-cell-error-icon" />
                </el-tooltip>
              </div>
            </template>
          </el-table-column>
          <el-table-column label="分配采购量 *" width="125">
            <template slot-scope="scope">
              <el-input-number
                v-model="scope.row.purchase_quantity"
                :min="purchaseStep(scope.row)"
                :step="purchaseStep(scope.row)"
                size="small"
                controls-position="right"
                style="width: 100%"
                @change="refreshPlanning(activeSplitRow, scope.row)"
              />
            </template>
          </el-table-column>
          <el-table-column label="采购单价 (¥) *" width="125">
            <template slot-scope="scope">
              <el-input-number
                v-model="scope.row.purchase_unit_price"
                :min="0"
                :precision="2"
                size="small"
                controls-position="right"
                style="width: 100%"
                @change="handleDialogSplitPriceChange(scope.row)"
              />
            </template>
          </el-table-column>
          <el-table-column label="税率 (%)" width="85">
            <template slot-scope="scope">
              <el-input-number
                v-model="scope.row.tax_rate"
                :min="0"
                :max="100"
                size="small"
                controls-position="right"
                style="width: 100%"
              />
            </template>
          </el-table-column>
          <el-table-column label="预计金额" width="115" align="right">
            <template slot-scope="scope">
              <strong class="text-money">¥{{ money(splitAmount(scope.row)) }}</strong>
            </template>
          </el-table-column>
          <el-table-column label="期望交期" width="135">
            <template slot-scope="scope">
              <el-date-picker
                v-model="scope.row.expected_date"
                value-format="yyyy-MM-dd"
                size="small"
                placeholder="选择交期"
                style="width: 100%"
              />
            </template>
          </el-table-column>
          <el-table-column label="操作" width="60" align="center">
            <template slot-scope="scope">
              <el-button type="text" class="danger-link" icon="el-icon-delete" @click="activeSplitRow.splits.splice(scope.$index, 1)" />
            </template>
          </el-table-column>
        </el-table>
      </div>

      <!-- 弹窗底部操作与无缝物料切换 -->
      <div slot="footer" class="dialog-split-footer">
        <div class="footer-nav">
          <el-button
            size="small"
            plain
            icon="el-icon-arrow-left"
            :disabled="activeSplitValidIndex <= 0"
            @click="navigateSplitMaterial(-1)"
          >
            上一项物料
          </el-button>
          <span class="nav-indicator">{{ activeSplitValidIndex + 1 }} / {{ validPlanItems.length }}</span>
          <el-button
            size="small"
            plain
            :disabled="activeSplitValidIndex >= validPlanItems.length - 1"
            @click="navigateSplitMaterial(1)"
          >
            下一项物料 <i class="el-icon-arrow-right" />
          </el-button>
        </div>
        <div class="footer-actions">
          <el-button size="small" type="success" @click="closeSupplierSplitDialog">确定并返回</el-button>
        </div>
      </div>
    </el-dialog>


    <purchase-item-picker ref="itemPicker" @select="applyPickedItem" @select-multiple="applyPickedMultipleItems" />

    <footer class="bottom-actions">
      <el-button size="small" @click="$router.back()">取消返回</el-button>
      <el-button size="small" :disabled="!!scopeIssue || scopeChanging || saving" @click="save(false)">保存草稿</el-button>
      <el-button size="small" type="success" icon="el-icon-check" :disabled="!!scopeIssue || scopeChanging || saving" @click="save(true)">{{ type==='request' ? '确认需求' : type==='receipt' ? '保存' : '提交审核' }}</el-button>
    </footer>
  </section>
</template>

<script>
import { listEntity, listItemPurchaseConversionOptions } from '@/api/erp/master'
import { previewPurchaseConversion, generateReceiptSerials, getPurchase, getSupplierRecommendations, savePurchaseRequest, savePurchasePlan, savePurchaseOrder, savePurchaseReceipt, submitRequest, submitPlan, submitOrder } from '@/api/erp/purchase'
import { reserveForCreatePage, clearCreatePageReservation } from '@/utils/documentNumberReservation'
import PurchaseItemPicker from '@/components/purchase/PurchaseItemPicker.vue'
import PurchaseConversionFacts from '@/components/purchase/PurchaseConversionFacts.vue'
import PurchaseAttachmentPanel from '@/components/purchase/PurchaseAttachmentPanel.vue'
import { purchaseScopes, purchaseScopeLabel, purchaseScopeIssue, purchaseSourceLocked, purchaseScopeMatches, validPurchaseScope } from '@/utils/purchaseManagementScope.mjs'
import { planAllocation, planAllocationLabel, planAllocationTag, planTargetBaseQty, planAllocatedBaseQty } from '@/utils/purchasePlanAllocation'

export default {
  components: { PurchaseItemPicker, PurchaseAttachmentPanel, PurchaseConversionFacts },
  props: { type: { type: String, required: true } },
  data: () => ({
    form: { management_scope: 'factory', items: [] }, documentRevision: 0, warehouseRevision: 0, scopeChanging: false, saving: false, items: [], suppliers: [], warehouses: [], locations: [], activeIndex: 0,
    reservation: null, recommendations: [], recommendLoading: false, recommendationRevision: 0, pickerTarget: null, attachmentDraftToken: '',
    selectedRequestRows: [],
    selectedPlanRows: [],
      supplierDialogVisible: false,
      activeSplitRow: null,
      activeSplitIndex: 0,
      showRecommendInDialog: false,
    isPlanAllExpanded: false
  }),
  computed: {
    scopeOptions() { return purchaseScopes },
    scopeIssue() { return purchaseScopeIssue(this.form, this.items) },
    scopeLocked() { return this.scopeChanging || this.saving || purchaseSourceLocked(this.form) || (this.type === 'request' && ['production', 'sales'].includes(this.form.source_type)) },
    scopedWarehouses() { return this.warehouses.filter(row => purchaseScopeMatches(row, this.form.management_scope)) },
    validPlanItems() {
      return (this.form.items || []).filter(i => i.item_id)
    },
    activeSplitValidIndex() {
      if (!this.activeSplitRow) return 0
      return this.validPlanItems.indexOf(this.activeSplitRow)
    },
    title() { return `${this.$route.params.id ? '编辑' : '新增'}${this.type === 'request' ? '采购需求' : this.type === 'plan' ? '采购计划' : this.type === 'order' ? '采购订单' : '到货单'}` },
    validSuppliers() {
      return this.suppliers.filter(s => s.supplier_name && s.status === 'enabled' &&
        (s.approval_status || 'approved') === 'approved' && !s.is_blacklisted &&
        (s.cooperation_status || 'normal') === 'normal' && !s.purchase_restricted &&
        (s.quality_status || 'normal') !== 'frozen')
    },
    activeLine() { return this.form.items[this.activeIndex] },
    planSourceText() {
      if (this.form.source_type === 'purchase_request' || this.form.data_source === 'purchase_request') {
        return `采购需求转入 (${this.form.source_no || ''})`
      }
      if (this.form.remark && this.form.remark.includes('采购需求')) {
        return '采购需求转入'
      }
      return '手工创建'
    },
    planStatusLabel() {
      const map = { draft: '草稿', submitted: '已提交', approved: '已审核', rejected: '已驳回', closed: '已关闭', cancelled: '已取消' }
      return map[this.form.plan_status] || this.form.plan_status || '草稿'
    },
    planStatusTag() {
      const status = this.form.plan_status || 'draft'
      return ['approved', 'confirmed'].includes(status) ? 'success' : ['rejected', 'cancelled'].includes(status) ? 'danger' : ['submitted', 'pending'].includes(status) ? 'warning' : 'info'
    },
    unallocatedPlanItemsCount() {
      if (this.type !== 'plan') return 0
      return (this.form.items || []).filter(line => !this.isPlanBalanced(line)).length
    },
    unallocatedSupplierItemsCount() {
      if (this.type !== 'plan') return 0
      return (this.form.items || []).filter(line => line.item_id && !this.hasAllocatedSupplier(line)).length
    },
    uniqueAllocatedSuppliersCount() {
      if (this.type !== 'plan') return 0
      const set = new Set()
      ;(this.form.items || []).forEach(line => {
        ;(line.splits || []).forEach(s => {
          if (s.supplier_id) set.add(s.supplier_id)
        })
      })
      return set.size
    },
    planAllocationComplete() {
      return this.type === 'plan' && (this.form.items || []).length > 0 && this.unallocatedPlanItemsCount === 0 && this.unallocatedSupplierItemsCount === 0
    },
    quantitySummary() {
      const groups = new Map()
      const rows = this.form.items || []
      rows.forEach(line => {
        const quantity = ['request', 'plan'].includes(this.type) ? line.purchase_quantity : line.qty
        const unit = ['order', 'receipt'].includes(this.type) ? this.conversionUnitName(line) : this.planningUnitName(line)
        if (!line.item_id || !Number(quantity)) return
        groups.set(unit, Number(groups.get(unit) || 0) + Number(quantity))
      })
      return groups.size ? [...groups.entries()].map(([unit, quantity]) => `${Number(quantity).toLocaleString('zh-CN', { maximumFractionDigits: 6 })} ${unit}`).join('；') : '0'
    },
    baseQuantitySummary() {
      const groups = new Map()
      const rows = this.form.items || []
      rows.forEach(line => {
        const snap = line._planningPreview || line.purchase_conversion_snapshot
        const quantity = snap ? Number(snap.planned_base_qty || 0) : (['request', 'plan'].includes(this.type) ? Number(line.purchase_quantity || 0) : Number(line.qty || 0))
        const unit = snap?.base_unit_name_snapshot || this.itemUnitName(line.item_id)
        if (!line.item_id || !quantity) return
        groups.set(unit, Number(groups.get(unit) || 0) + quantity)
      })
      return groups.size ? [...groups.entries()].map(([unit, quantity]) => `${Number(quantity).toLocaleString('zh-CN', { maximumFractionDigits: 6 })} ${unit}`).join('；') : '0'
    },
    hasConversionDifference() {
      return this.quantitySummary !== this.baseQuantitySummary && this.baseQuantitySummary !== '0'
    },
    untaxedAmount() {
      return this.linesForAmount().reduce((sum, line) => {
        const amount = Number(line.qty || 0) * Number(line.price || 0)
        const rate = Number(line.tax || 0)
        return sum + ((this.form.tax_mode || 'tax_included') === 'tax_included' && rate > 0 ? amount * 100 / (100 + rate) : amount)
      }, 0)
    },
    taxAmount() {
      return this.linesForAmount().reduce((sum, line) => {
        const amount = Number(line.qty || 0) * Number(line.price || 0)
        const rate = Number(line.tax || 0)
        return sum + ((this.form.tax_mode || 'tax_included') === 'tax_included' && rate > 0
          ? amount - amount * 100 / (100 + rate)
          : amount * rate / 100)
      }, 0)
    },
    totalAmount() {
      // 计划生成的订单采用含税价；没有显式口径时，预览必须与生成结果一致。
      const lineAmount = (this.form.tax_mode || 'tax_included') === 'tax_included'
        ? this.linesForAmount().reduce((sum, line) => sum + Number(line.qty || 0) * Number(line.price || 0), 0)
        : this.untaxedAmount + this.taxAmount
      return lineAmount + Number(this.form.freight_amount || 0)
    },
    expectedOrderCount() { return new Set(this.form.items.flatMap(i => (i.splits || []).map(s => s.supplier_id).filter(Boolean))).size }
  },
  watch: {
    type(newType, oldType) {
      if (newType !== oldType) this.initializeDocument()
    },
    '$route.fullPath'(newPath, oldPath) {
      if (newPath !== oldPath) this.initializeDocument()
    }
  },
  async mounted() {
    const [suppliers, locations] = await Promise.allSettled([listEntity('suppliers', { status: 'enabled', per_page: 100 }), listEntity('locations', { per_page: 100 })])
    this.suppliers = suppliers.status === 'fulfilled' ? suppliers.value.data.data || [] : []
    this.locations = locations.status === 'fulfilled' ? (locations.value.data.data || []).filter(row => ['active', 'enabled'].includes(row.status)) : []
    await this.initializeDocument()
  },
  methods: {
    scopeLabel(scope) { return purchaseScopeLabel(scope) },
    async loadScopedWarehouses() {
      const revision = ++this.warehouseRevision
      const scope = this.form.management_scope
      this.warehouses = []
      if (!validPurchaseScope(scope)) return
      try {
        const { data } = await listEntity('warehouses', { management_scope: scope, page: 1, per_page: 100 })
        if (revision !== this.warehouseRevision || scope !== this.form.management_scope) return
        this.warehouses = (data.data || []).filter(row => ['enabled', 'active'].includes(row.status) && purchaseScopeMatches(row, scope))
      } catch (error) { if (revision === this.warehouseRevision) this.$message.error(error.userMessage || '仓库加载失败') }
    },
    async changeManagementScope(scope) {
      if (scope === this.form.management_scope || !validPurchaseScope(scope) || this.scopeLocked) return
      this.scopeChanging = true
      const document = this.form
      try {
        if (document.items.some(row => row.item_id || row.warehouse_id)) await this.$confirm('切换管理类型将清空全部采购明细、供应商分配及目标仓库，是否继续？', '切换管理类型', { type: 'warning', confirmButtonText: '清空并切换' })
        if (this.form !== document) return
        this.documentRevision++
        this.recommendationRevision++
        this.pickerTarget = null
        if (this.$refs.itemPicker) this.$refs.itemPicker.visible = false
        this.supplierDialogVisible = false
        this.activeSplitRow = null
        this.selectedPlanRows = []
        this.selectedRequestRows = []
        this.recommendations = []
        this.recommendLoading = false
        this.$set(document, 'management_scope', scope)
        this.$set(document, 'items', [])
        if (this.type === 'request') this.addRequestLine()
        else if (this.type === 'plan') this.addPlanItem()
        else this.addLine()
        this.activeIndex = 0
        await this.loadScopedWarehouses()
      } catch (error) { if (error !== 'cancel' && error !== 'close') this.$message.error(error.userMessage || '管理类型切换失败') }
      finally { this.scopeChanging = false }
    },
    canPickScope(items) {
      if (!validPurchaseScope(this.form.management_scope) || items.some(item => !purchaseScopeMatches(item, this.form.management_scope))) {
        this.$message.warning('请选择与单据管理类型一致的物料')
        return false
      }
      return true
    },
    planningSnapshot(line) { return line._planningPreview || line.purchase_conversion_snapshot },
    planningUnitName(line) { return this.canonicalUnit(this.selectedConversion(line)?.purchase_unit)?.unit_name || this.planningSnapshot(line)?.purchase_unit_name_snapshot || '-' },
    purchaseStep(line) {
      const snapshot = this.planningSnapshot(line)
      const unit = this.canonicalUnit(this.selectedConversion(line)?.purchase_unit)
      return Math.pow(10, -Number(unit?.decimal_places ?? snapshot?.purchase_decimal_places ?? 4))
    },
    withSnapshotOption(options, snapshot) {
      const current = options.find(option => Number(option.purchase_unit_id) === Number(snapshot.purchase_unit_id))
      return [{
        purchase_unit_id: snapshot.purchase_unit_id, base_unit_id: snapshot.base_unit_id,
        factor: snapshot.conversion_factor_snapshot, allow_actual_conversion: snapshot.allow_actual_conversion_snapshot,
        purchase_unit: { id: snapshot.purchase_unit_id, unit_name: snapshot.purchase_unit_name_snapshot, decimal_places: snapshot.purchase_decimal_places ?? this.canonicalUnit(current?.purchase_unit)?.decimal_places ?? 4 },
        base_unit: { id: snapshot.base_unit_id, unit_name: snapshot.base_unit_name_snapshot, decimal_places: snapshot.base_decimal_places ?? this.canonicalUnit(current?.base_unit)?.decimal_places ?? 4 },
      }, ...options.filter(option => Number(option.purchase_unit_id) !== Number(snapshot.purchase_unit_id))]
    },
    async initializeMissingPlanningLines() {
      await Promise.all(this.form.items.filter(line => line.item_id && !line.purchase_unit_id).map(line => this.loadLineConversions(line)))
    },
    async initializeSplit(line, split) {
      const snapshot = split.purchase_conversion_snapshot
      this.$set(split, 'purchase_unit_id', snapshot?.purchase_unit_id || split.purchase_unit_id || line.purchase_unit_id)
      this.$set(split, '_conversionOptions', snapshot ? this.withSnapshotOption(line._conversionOptions || [], snapshot) : line._conversionOptions || [])
      this.$set(split, 'purchase_quantity', snapshot ? Number(snapshot.purchase_qty) : split.purchase_quantity ?? 1)
      this.$set(split, 'purchase_unit_price', snapshot ? Number(snapshot.purchase_unit_price) : split.purchase_unit_price ?? Number(split.unit_price || 0) * Number(line._planningPreview?.conversion_factor_snapshot || 1))
      await this.refreshPlanning(line, split)
    },
    getLineConversionFactor(line, target = null) {
      const row = target || line
      if (row?._planningPreview?.conversion_factor_snapshot) {
        return Number(row._planningPreview.conversion_factor_snapshot)
      }
      const unitId = row?.purchase_unit_id || line?.purchase_unit_id
      const options = row?._conversionOptions || line?._conversionOptions || []
      const matched = options.find(c => Number(c.purchase_unit_id) === Number(unitId))
      if (matched && matched.factor) {
        return Number(matched.factor)
      }
      if (line?._planningPreview?.conversion_factor_snapshot) {
        return Number(line._planningPreview.conversion_factor_snapshot)
      }
      return 1
    },
    async changePlanUnit(line) { await this.refreshPlanning(line) },
    async refreshPlanning(line, split = null) {
      const documentRevision = this.documentRevision
      const target = split || line
      const revision = Number(target._planningRevision || 0) + 1
      this.$set(target, '_planningRevision', revision)
      this.$set(target, '_planningError', '')
      if (!line.item_id) return
      const quantity = Number(target.purchase_quantity)
      if (!(quantity > 0)) { this.$set(target, '_planningError', '采购数量必须大于0'); return }

      const factor = this.getLineConversionFactor(line, target)
      const optimisticPlannedBase = Number((quantity * factor).toFixed(8))

      const hasKnownFactor = Boolean(target._planningPreview?.conversion_factor_snapshot) &&
        Number(target.purchase_unit_id || line.purchase_unit_id) === Number(target._planningPreview?.purchase_unit_id)

      // 乐观即时更新快照中的折合库存与录入数量，保持进度条平稳过渡，绝不置空重统
      if (target._planningPreview) {
        this.$set(target._planningPreview, 'planned_base_qty', optimisticPlannedBase)
        this.$set(target._planningPreview, 'purchase_qty', quantity)
        if (split && split.purchase_unit_price != null) {
          this.$set(target._planningPreview, 'purchase_unit_price', Number(split.purchase_unit_price || 0))
          this.$set(target._planningPreview, 'amount', Number((quantity * Number(split.purchase_unit_price || 0)).toFixed(4)))
        }
      } else {
        this.$set(target, '_planningPreview', {
          item_id: line.item_id,
          purchase_unit_id: target.purchase_unit_id || line.purchase_unit_id,
          purchase_qty: quantity,
          planned_base_qty: optimisticPlannedBase,
          conversion_factor_snapshot: String(factor),
          base_unit_name_snapshot: this.plannedBaseUnit(line)
        })
      }

      // 仅当单位发生改变或尚未建立换算快照时才置 pending；已有换算因子的数量微调即时乐观计算，避免“计算中”跳跃
      if (!hasKnownFactor) {
        this.$set(target, '_planningPending', true)
      }
      try {
        const { data } = await previewPurchaseConversion({
          item_id: line.item_id,
          purchase_unit_id: target.purchase_unit_id || line.purchase_unit_id,
          purchase_quantity: quantity, is_split: Boolean(split),
          purchase_unit_price: split ? Number(split.purchase_unit_price || 0) : 0,
          document_type: this.type, document_id: this.form.id || null,
          line_id: line.id || null, split_id: split?.id || null,
          request_item_id: line.request_item_id || null
        })
        if (target._planningRevision !== revision || documentRevision !== this.documentRevision) return
        this.$set(target, '_planningPreview', data.data)
        this.$set(target, 'purchase_unit_id', data.data.purchase_unit_id)
        this.$set(target, '_conversionOptions', this.withSnapshotOption(target._conversionOptions || line._conversionOptions || [], data.data))
      } catch (error) {
        if (target._planningRevision === revision) this.$set(target, '_planningError', error.userMessage || '采购换算计算失败')
      } finally {
        if (target._planningRevision === revision) this.$set(target, '_planningPending', false)
      }
    },
    plannedBaseQty(row) {
      const snap = row._planningPreview || row.purchase_conversion_snapshot
      if (snap) {
        return Number(snap.planned_base_qty || 0).toLocaleString('zh-CN', { maximumFractionDigits: 6 })
      }
      return Number(row.purchase_quantity || 1).toLocaleString('zh-CN', { maximumFractionDigits: 6 })
    },
    plannedBaseUnit(row) {
      const snap = row._planningPreview || row.purchase_conversion_snapshot
      if (snap && snap.base_unit_name_snapshot) return snap.base_unit_name_snapshot
      return this.itemUnitName(row.item_id)
    },
    isConvertedUnit(row) {
      const snap = row._planningPreview || row.purchase_conversion_snapshot
      if (!snap) return false
      const factor = Number(snap.conversion_factor_snapshot ?? snap.conversion_factor ?? 1)
      const purchaseId = Number(snap.purchase_unit_id ?? 0)
      const baseId = Number(snap.base_unit_id ?? 0)
      return factor !== 1 || (purchaseId && baseId && purchaseId !== baseId)
    },
    conversionFormulaTip(row) {
      const snap = row._planningPreview || row.purchase_conversion_snapshot
      const pUnit = snap?.purchase_unit_name_snapshot || this.planningUnitName(row)
      const bUnit = snap?.base_unit_name_snapshot || this.plannedBaseUnit(row)
      const factor = Number(snap?.conversion_factor_snapshot || 1).toLocaleString('zh-CN', { maximumFractionDigits: 6 })
      return `换算规则：1 ${pUnit} = ${factor} ${bUnit}`
    },
    getItem(id) {
      if (!id) return null
      let item = this.items.find(row => Number(row.id) === Number(id))
      if (!item) {
        const line = this.form.items.find(row => Number(row.item_id) === Number(id))
        if (line && line.item) {
          item = line.item
          this.rememberItem(item)
        }
      }
      return item || null
    },
    currentSelectedItems() {
      return (this.form.items || [])
        .filter(row => row.item_id)
        .map(row => {
          const item = this.getItem(row.item_id)
          return item || {
            id: row.item_id,
            item_code: this.itemCode(row.item_id),
            item_name: this.itemNameOnly(row.item_id),
            unit: { symbol: this.itemUnitName(row.item_id) }
          }
        })
    },
    itemCode(id) {
      const item = this.getItem(id)
      return item ? item.item_code : '-'
    },
    itemNameOnly(id) {
      const item = this.getItem(id)
      return item ? item.item_name : '-'
    },
    itemSpec(id) {
      const item = this.getItem(id)
      return item ? (item.spec_model || item.spec || item.model || '') : ''
    },
    handleRequestSelectionChange(rows) {
      this.selectedRequestRows = rows
    },
    async removeSelectedLines() {
      if (!this.selectedRequestRows.length) return
      try {
        await this.$confirm(`确定删除选中的 ${this.selectedRequestRows.length} 行物料吗？`, '提示', { type: 'warning' })
        this.form.items = this.form.items.filter(item => !this.selectedRequestRows.includes(item))
        this.selectedRequestRows = []
        if (!this.form.items.length) {
          this.addRequestLine()
        }
        this.$message.success('已批量删除所选行')
      } catch (e) {
        // cancel
      }
    },
    async clearAllLines() {
      try {
        await this.$confirm('确定清空所有物料明细吗？', '提示', { type: 'warning' })
        this.form.items = []
        this.addRequestLine()
        this.selectedRequestRows = []
        this.$message.success('已清空物料明细')
      } catch (e) {
        // cancel
      }
    },
    async batchSetDeliveryDate() {
      if (!this.selectedRequestRows.length) return this.$message.warning('请先勾选需要设置交期的物料行')
      try {
        const { value } = await this.$prompt('请输入统一期望交期 (YYYY-MM-DD)', '批量设置期望交期', {
          inputType: 'date',
          inputValue: new Date().toISOString().slice(0, 10),
          confirmButtonText: '应用到所选行'
        })
        if (value) {
          this.selectedRequestRows.forEach(row => {
            this.$set(row, 'expected_date', value)
          })
          this.$message.success(`已为 ${this.selectedRequestRows.length} 行设置期望交期：${value}`)
        }
      } catch (e) {
        // cancel
      }
    },
    batchSetWarehouse(warehouseId) {
      if (!this.scopedWarehouses.some(row => Number(row.id) === Number(warehouseId))) return this.$message.warning('只能选择当前管理类型的仓库')
      if (!this.selectedRequestRows.length) return this.$message.warning('请先勾选物料行')
      this.selectedRequestRows.forEach(row => {
        this.$set(row, 'warehouse_id', warehouseId)
      })
      this.$message.success(`已为 ${this.selectedRequestRows.length} 行更新目标仓库`)
    },
    async initializeDocument() {
      const revision = ++this.documentRevision
      this.warehouseRevision++
      this.pickerTarget = null
      this.items = []
      this.warehouses = []
      if (this.$refs.itemPicker) this.$refs.itemPicker.visible = false
      this.supplierDialogVisible = false
      this.activeSplitRow = null
      this.selectedPlanRows = []
      this.selectedRequestRows = []
      this.recommendationRevision++
      this.recommendLoading = false
      this.activeIndex = 0
      this.recommendations = []
      this.reservation = null
      if (this.$route.params.id) await this.loadExisting()
      else {
        this.initBlank()
        await this.reserveNumber()
      }
      if (revision === this.documentRevision) await this.loadScopedWarehouses()
    },
    initBlank() {
      const today = new Date().toISOString().slice(0, 10)
      this.attachmentDraftToken = this.newDraftToken()
      if (this.type === 'request') this.form = { management_scope: validPurchaseScope(this.$route.query?.management_scope) ? this.$route.query.management_scope : 'factory', request_no: '', request_date: today, source_type: 'manual', items: [{ item_id: null, spec_model: '', purchase_quantity: 1, expected_date: '', priority: 'normal' }] }
      if (this.type === 'plan') this.form = { management_scope: validPurchaseScope(this.$route.query?.management_scope) ? this.$route.query.management_scope : 'factory', plan_no: '', plan_date: today, items: [{ _rowKey: Date.now(), item_id: null, spec_model: '', unit_id: null, purchase_quantity: 1, expected_date: '', splits: [] }] }
      if (this.type === 'order') this.form = { management_scope: validPurchaseScope(this.$route.query?.management_scope) ? this.$route.query.management_scope : 'factory', purchase_order_no: '', supplier_id: null, order_date: today, currency: 'CNY', tax_mode: 'tax_included', freight_amount: 0, items: [{ item_id: null, qty: 1, unit_price: 0, tax_rate: 13, expected_arrival_date: '' }] }
      if (this.type === 'receipt') this.form = { management_scope: validPurchaseScope(this.$route.query?.management_scope) ? this.$route.query.management_scope : 'factory', receipt_no: '', supplier_id: null, receipt_date: today, items: [{ item_id: null, qty: 1, qualified_qty: 1, unqualified_qty: 0, actual_base_qty: 0, unit_price: 0, batch_no: '', serial_text: '', serial_number_source: 'supplier', warehouse_id: null, location_id: null }] }
    },
    async reserveNumber() {
      const revision = this.documentRevision
      const type = this.type
      const path = this.$route.path
      const documentTypes = { request: 'purchase_request', plan: 'purchase_plan', order: 'purchase_order', receipt: 'purchase_receipt' }
      const fields = { request: 'request_no', plan: 'plan_no', order: 'purchase_order_no', receipt: 'receipt_no' }
      try {
        const reservation = await reserveForCreatePage(documentTypes[type], path)
        if (revision !== this.documentRevision || path !== this.$route.path) return
        this.reservation = reservation
        this.$set(this.form, fields[type], reservation.document_no)
      } catch (e) {
        this.$message.error(e.userMessage || '单据编号预生成失败，请重新打开新增页面')
      }
    },
    async loadExisting() {
      const revision = this.documentRevision
      const map = { request: 'requests', plan: 'plans', order: 'orders', receipt: 'receipts' }
      const res = await getPurchase(map[this.type], this.$route.params.id)
      if (revision !== this.documentRevision) return
      const data = res.data
      this.attachmentDraftToken = ''
      ;(data.items || []).forEach(line => this.rememberItem(line.item))
      if (this.type === 'request') this.form = { ...data, items: (data.items || []).map(i => ({ ...i, spec_model: i.spec_model || i.item?.spec_model || i.item?.spec || i.item?.model || '', purchase_quantity: Number(i.purchase_conversion_snapshot?.purchase_qty ?? i.request_qty), purchase_unit_id: i.purchase_conversion_snapshot?.purchase_unit_id || i.unit_id })) }
      if (this.type === 'plan') this.form = { ...data, items: (data.items || []).map(i => ({ _rowKey: i.id || (Date.now() + Math.random()), ...i, spec_model: i.spec_model || i.item?.spec_model || i.item?.spec || i.item?.model || '', purchase_quantity: Number(i.purchase_conversion_snapshot?.purchase_qty ?? i.required_qty ?? i.plan_qty), purchase_unit_id: i.purchase_conversion_snapshot?.purchase_unit_id || i.unit_id, splits: (i.splits || []).map(s => ({ ...s, purchase_quantity: Number(s.purchase_conversion_snapshot?.purchase_qty ?? s.purchase_qty), purchase_unit_id: s.purchase_conversion_snapshot?.purchase_unit_id || i.unit_id, purchase_unit_price: Number(s.purchase_conversion_snapshot?.purchase_unit_price ?? s.unit_price), unit_price: Number(s.unit_price), tax_rate: Number(s.tax_rate) })) })) }
      if (this.type === 'order') this.form = { ...data, items: (data.items || []).map(i => ({ ...i, qty: Number(i.purchase_qty || i.order_qty), unit_price: Number(i.purchase_unit_price || i.unit_price), tax_rate: Number(i.tax_rate), _conversionOptions: [] })) }
      if (this.type === 'receipt') this.form = { ...data, items: (data.items || []).map(i => ({ ...i, qty: Number(i.receipt_qty), unit_price: Number(i.unit_price), qualified_qty: Number(i.qualified_qty), unqualified_qty: Number(i.unqualified_qty), actual_base_qty: i.actual_base_qty == null ? null : Number(i.actual_base_qty), _conversionOptions: [] })) }
      await Promise.all(this.form.items.map(async line => {
        const snap = line.purchase_conversion_snapshot
        if (snap) this.$set(line, 'purchase_unit_id', snap.purchase_unit_id)
        await this.loadLineConversions(line, false)
        if (this.type === 'plan') await Promise.all((line.splits || []).map(split => this.initializeSplit(line, split)))
      }))
    },
    addPlanItem() {
      const defaultDate = this.form.items[0]?.expected_date || ''
      this.form.items.push({ _rowKey: Date.now() + Math.random(), item_id: null, spec_model: '', unit_id: null, purchase_quantity: 1, expected_date: defaultDate, splits: [] })
      this.activeIndex = this.form.items.length - 1
      this.recommendations = []
    },
    removePlanItem(index) {
      this.form.items.splice(index, 1)
      if (this.activeIndex >= this.form.items.length) this.activeIndex = Math.max(0, this.form.items.length - 1)
    },
    addRequestLine() { this.form.items.push({ item_id: null, spec_model: '', purchase_quantity: 1, expected_date: '', priority: 'normal' }) },
    remainingPurchaseQuantity(line) {
      const factor = Number(line._planningPreview?.conversion_factor_snapshot || 1)
      const step = this.purchaseStep(line)
      return Math.max(step, Math.ceil(this.remaining(line) / factor / step - 0.00000001) * step)
    },
    async addSplit() {
      if (!this.activeLine.item_id) return this.$message.warning('请先选择物料')
      const split = { supplier_id: null, purchase_unit_id: this.activeLine.purchase_unit_id, purchase_quantity: this.remainingPurchaseQuantity(this.activeLine), purchase_unit_price: 0, tax_rate: 13 }
      this.activeLine.splits.push(split)
      await this.initializeSplit(this.activeLine, split)
    },
    addLine() { if (this.type === 'order' && this.form.plan_id) return this.$message.warning('计划生成订单须保留计划明细'); this.form.items.push({ item_id: null, qty: 1, unit_price: 0, tax_rate: 13, qualified_qty: 1, unqualified_qty: 0, actual_base_qty: 0, serial_text: '', serial_number_source: 'supplier', warehouse_id: null, location_id: null, purchase_unit_id: null, _conversionOptions: [] }); this.activeIndex = this.form.items.length - 1; this.recommendations = [] },
    openItemPicker(line) {
      if (!validPurchaseScope(this.form.management_scope)) return this.$message.warning('请先明确单据管理类型')
      if (purchaseSourceLocked(this.form)) return this.$message.warning('来源单据物料已锁定，请保留原来源明细')
      this.pickerTarget = line
      const isMulti = ['request', 'plan'].includes(this.type)
      this.$refs.itemPicker.open({
        currentId: line && line.item_id,
        multiple: isMulti,
        selected: isMulti ? this.currentSelectedItems() : (line && line.item_id ? [this.getItem(line.item_id)].filter(Boolean) : []),
        title: isMulti ? '选择采购物料 (支持多选)' : '选择采购物料',
        params: { status: 'enabled', is_purchase_item: 1, management_scope: this.form.management_scope }
      })
    },
    async applyPickedItem(item) {
      const line = this.pickerTarget
      if (!line || !item || !this.form.items.includes(line) || !this.canPickScope([item])) return
      const changed = Number(line.item_id || 0) !== Number(item.id)
      this.rememberItem(item)
      this.$set(line, 'item_id', item.id)
      this.$set(line, 'spec_model', item.spec_model || item.spec || item.model || '')
      if (this.type === 'plan') this.$set(line, 'unit_id', item.unit_id || item.unit?.id || null)
      if (changed) {
        this.$delete(line, 'id')
        this.$delete(line, 'purchase_conversion_snapshot')
        this.$delete(line, 'conversion_factor_snapshot')
        this.$delete(line, '_planningPreview')
        if (this.type === 'plan') this.$set(line, 'splits', [])
        this.$set(line, 'purchase_unit_id', null)
        this.$set(line, '_conversionOptions', [])
        this.$set(line, 'serial_text', '')
        this.$set(line, 'serial_number_source', 'supplier')
        await this.loadLineConversions(line, true)
      }
      this.recommendations = []
      this.pickerTarget = null
    },
    openBatchItemPicker() {
      if (!validPurchaseScope(this.form.management_scope)) return this.$message.warning('请先明确单据管理类型')
      this.pickerTarget = null
      this.$refs.itemPicker.open({
        multiple: true,
        selected: this.currentSelectedItems(),
        title: '选择采购物料 (支持多选)',
        params: { status: 'enabled', is_purchase_item: 1, management_scope: this.form.management_scope }
      })
    },
    async applyPickedMultipleItems(items) {
      if (!items || !items.length || !this.canPickScope(items)) return

      // 若从某一行点击“更换”触发
      if (this.pickerTarget) {
        const targetLine = this.pickerTarget
        this.pickerTarget = null
        const otherLineItemIds = new Set(
          this.form.items.filter(row => row !== targetLine && row.item_id).map(row => Number(row.item_id))
        )
        const candidates = items.filter(item => !otherLineItemIds.has(Number(item.id)))
        if (!candidates.length) {
          return this.$message.warning('所选物料已在其他明细行中，不能重复添加')
        }
        const first = candidates.shift()
        this.rememberItem(first)
        // 重新确认原物料时保留采购员已编辑的规格、供应商和快照，只有换物料才清理。
        if (Number(targetLine.item_id) !== Number(first.id)) {
          this.$set(targetLine, 'item_id', first.id)
          this.$set(targetLine, 'spec_model', first.spec_model || first.spec || first.model || '')
          this.$delete(targetLine, 'id')
          this.$delete(targetLine, 'purchase_conversion_snapshot')
          this.$delete(targetLine, 'conversion_factor_snapshot')
          this.$set(targetLine, '_planningRevision', Number(targetLine._planningRevision || 0) + 1)
          this.$set(targetLine, '_planningPreview', null)
          this.$set(targetLine, '_conversionOptions', [])
          this.$set(targetLine, 'purchase_unit_id', null)
          if (this.type === 'plan') {
            this.$set(targetLine, 'unit_id', first.unit_id || first.unit?.id || null)
            this.$set(targetLine, 'splits', [])
          }
        }

        const currentItemIds = new Set(this.form.items.filter(row => row.item_id).map(row => Number(row.item_id)))
        let addedCount = 1
        const defaultDate = this.form.items[0]?.expected_date || ''
        const defaultWarehouse = this.form.items[0]?.warehouse_id || null
        candidates.forEach(item => {
          if (!currentItemIds.has(Number(item.id))) {
            this.rememberItem(item)
            this.form.items.push({
              _rowKey: Date.now() + Math.random(),
              item_id: item.id,
              spec_model: item.spec_model || item.spec || item.model || '',
              purchase_quantity: 1,
              expected_date: defaultDate,
              priority: 'normal',
              warehouse_id: defaultWarehouse,
              remark: '',
              ...(this.type === 'plan' ? { unit_id: item.unit_id || item.unit?.id || null, splits: [] } : {})
            })
            currentItemIds.add(Number(item.id))
            addedCount++
          }
        })
        await this.initializeMissingPlanningLines()
        return this.$message.success(`已更新物料明细（选用 ${addedCount} 项）`)
      }

      // 从主按钮“选择物料 (多选)”触发
      const existingItemIds = new Set(this.form.items.filter(row => row.item_id).map(row => Number(row.item_id)))
      const newItems = items.filter(item => !existingItemIds.has(Number(item.id)))

      if (!newItems.length) {
        return this.$message.info('所选物料均已在需求明细中，未添加重复行')
      }

      const defaultDate = this.form.items[0]?.expected_date || ''
      const defaultWarehouse = this.form.items[0]?.warehouse_id || null
      let filledEmpty = false

      newItems.forEach(item => {
        this.rememberItem(item)
        const emptyRow = this.form.items.find(row => !row.item_id)
        if (emptyRow && !filledEmpty) {
          this.$set(emptyRow, 'item_id', item.id)
          this.$set(emptyRow, 'spec_model', item.spec_model || item.spec || item.model || '')
          filledEmpty = true
        } else {
          this.form.items.push(this.type === 'plan' ? {
            _rowKey: Date.now() + Math.random(),
            item_id: item.id,
            spec_model: item.spec_model || item.spec || item.model || '',
            unit_id: item.unit_id || item.unit?.id || null,
            purchase_quantity: 1,
            expected_date: defaultDate,
            splits: []
          } : {
            item_id: item.id,
            spec_model: item.spec_model || item.spec || item.model || '',
            purchase_quantity: 1,
            expected_date: defaultDate,
            priority: 'normal',
            warehouse_id: defaultWarehouse,
            remark: ''
          })
        }
      })

      await this.initializeMissingPlanningLines()
      this.$message.success(`已添加 ${newItems.length} 个新物料明细`)
    },
    rememberItem(item) {
      if (!item || !item.id) return
      const index = this.items.findIndex(row => Number(row.id) === Number(item.id))
      if (index >= 0) this.$set(this.items, index, item)
      else this.items.push(item)
    },
    async loadLineConversions(line, chooseDefault = true) {
      if (!line.item_id) return this.$set(line, '_conversionOptions', [])
      const documentRevision = this.documentRevision
      const itemId = line.item_id
      const { data } = await listItemPurchaseConversionOptions(itemId, { page: 1, per_page: 100 })
      if (line.item_id !== itemId || documentRevision !== this.documentRevision || !this.form.items.includes(line)) return
      const item = this.items.find(row => Number(row.id) === Number(line.item_id))
      const itemUnit = item && (item.unit?.standard_unit || item.unit?.standardUnit || item.unit)
      let options = [...(data.data || [])]
      if (itemUnit && !options.some(row => Number(row.purchase_unit_id) === Number(itemUnit.id))) {
        options.unshift({ purchase_unit_id: itemUnit.id, base_unit_id: itemUnit.id, factor: 1, is_default: options.length === 0, allow_actual_conversion: false, purchase_unit: itemUnit, base_unit: itemUnit, identity_conversion: true })
      }
      const snapshot = line.purchase_conversion_snapshot || (line.conversion_factor_snapshot ? line : null)
      if (!chooseDefault && snapshot) options = this.withSnapshotOption(options, snapshot)
      this.$set(line, '_conversionOptions', options)
      if (chooseDefault || !line.purchase_unit_id) {
        const selected = options.find(row => row.is_default) || options[0]
        if (selected) this.$set(line, 'purchase_unit_id', selected.purchase_unit_id)
      }
      if (['request', 'plan'].includes(this.type)) await this.refreshPlanning(line)
      else this.applyConversion(line, false)
    },
    selectedConversion(line) { return (line._conversionOptions || []).find(row => Number(row.purchase_unit_id) === Number(line.purchase_unit_id)) },
    purchaseOptionLabel(conversion) {
      const purchaseUnit = conversion && (conversion.purchase_unit?.standard_unit || conversion.purchase_unit?.standardUnit || conversion.purchase_unit)
      const baseUnit = conversion && (conversion.base_unit?.standard_unit || conversion.base_unit?.standardUnit || conversion.base_unit)
      const purchaseName = purchaseUnit && (purchaseUnit.symbol || purchaseUnit.unit_name || purchaseUnit.unit_code) || '-'
      const baseName = baseUnit && (baseUnit.symbol || baseUnit.unit_name || baseUnit.unit_code) || '-'
      const factor = Number(conversion && conversion.factor || 1)
      const isBase = factor === 1 && (Number(conversion?.purchase_unit_id) === Number(conversion?.base_unit_id) || purchaseName === baseName)
      const factorText = isBase ? '基本库存单位' : `1 ${purchaseName} = ${factor.toLocaleString('zh-CN', { maximumFractionDigits: 6 })} ${baseName}`
      return `${purchaseName}（${factorText}）${conversion && conversion.is_default ? ' · 默认' : ''}`
    },
    canonicalUnit(unit) { return unit && (unit.standard_unit || unit.standardUnit || unit) },
    applyConversion(line, preserveBaseQuantity = false) {
      const c = this.selectedConversion(line)
      if (!c) return
      const previousFactor = Number(line._effectiveConversionFactor || line.conversion_factor_preview || line.conversion_factor_snapshot || 0)
      const previousBaseQuantity = Number(line.qty || 0) * previousFactor
      const previousBaseUnitPrice = previousFactor > 0 ? Number(line.unit_price || 0) / previousFactor : 0
      const nextFactor = Number(c.factor)
      const purchaseUnit = this.canonicalUnit(c.purchase_unit)
      const baseUnit = this.canonicalUnit(c.base_unit)
      if (preserveBaseQuantity && previousFactor > 0 && nextFactor > 0) {
        const scale = Math.pow(10, Number(purchaseUnit?.decimal_places ?? 4))
        this.$set(line, 'qty', Math.ceil(previousBaseQuantity / nextFactor * scale - 0.00000001) / scale)
        this.$set(line, 'unit_price', previousBaseUnitPrice * nextFactor)
      }
      this.$set(line, 'conversion_factor_preview', nextFactor)
      this.$set(line, 'purchase_unit_name_preview', purchaseUnit && (purchaseUnit.unit_name || purchaseUnit.symbol || purchaseUnit.unit_code))
      this.$set(line, 'base_unit_name_preview', baseUnit && (baseUnit.unit_name || baseUnit.symbol || baseUnit.unit_code))
      this.$set(line, 'allow_actual_conversion_preview', Boolean(c.allow_actual_conversion))
      this.$set(line, '_effectiveConversionFactor', nextFactor)
      if (this.type === 'receipt' && (line.actual_base_qty == null || Number(line.actual_base_qty) === 0)) this.$set(line, 'actual_base_qty', this.standardBaseQtyNumber(line))
    },
    changePurchaseUnit(line) { this.applyConversion(line, true) },
    syncReceiptActualQty(line) {
      if (this.type === 'receipt' && !this.actualConversionAllowed(line)) this.$set(line, 'actual_base_qty', this.standardBaseQtyNumber(line))
    },
    effectiveConversionFactor(line) { return Number(this.type === 'order' ? (line.conversion_factor_preview || line.conversion_factor_snapshot || 0) : (line.conversion_factor_snapshot || line.conversion_factor_preview || 0)) },
    conversionFactor(line) { return this.effectiveConversionFactor(line).toLocaleString('zh-CN', { maximumFractionDigits: 6 }) },
    conversionUnitName(line) { const c = this.selectedConversion(line); return line.purchase_unit_name_preview || (c && c.purchase_unit && c.purchase_unit.unit_name) || '-' },
    baseUnitName(line) { const c = this.selectedConversion(line); const selectedBase = this.canonicalUnit(c && c.base_unit); return (this.type === 'order' ? line.base_unit_name_preview : line.base_unit_name_snapshot) || line.base_unit_name_preview || (selectedBase && (selectedBase.unit_name || selectedBase.symbol || selectedBase.unit_code)) || '-' },
    standardBaseQtyNumber(line) {
      const quantity = Number(line.qty || 0)
      const factor = this.effectiveConversionFactor(line)
      return quantity > 0 && factor > 0 ? quantity * factor : Number(line.standard_base_qty || 0)
    },
    standardBaseQty(line) { return this.standardBaseQtyNumber(line).toLocaleString('zh-CN', { maximumFractionDigits: 6 }) },
    actualConversionAllowed(line) { return Boolean(line.allow_actual_conversion || line.allow_actual_conversion_preview || line.allow_actual_conversion_snapshot) },
    differenceQtyNumber(line) { return Number(line.actual_base_qty == null ? this.standardBaseQtyNumber(line) : line.actual_base_qty) - this.standardBaseQtyNumber(line) },
    differenceQty(line) { return this.differenceQtyNumber(line).toLocaleString('zh-CN', { maximumFractionDigits: 6 }) },
    hasDifference(line) { return Math.abs(this.differenceQtyNumber(line)) > 0.0000001 },
    baseUnitPrice(line) { const factor = this.effectiveConversionFactor(line); return factor > 0 ? this.money(Number(line.unit_price || 0) / factor) : '-' },
    selectLine(row) {
      const index = this.form.items.indexOf(row)
      if (index >= 0) this.activeIndex = index
      if (this.type === 'order') this.loadRecommendations()
    },
    filteredLocations(warehouseId) { return warehouseId ? this.locations.filter(l => l.warehouse_id === warehouseId) : this.locations },
    allocated(line) {
      return planAllocatedBaseQty(line)
    },
    lineTargetBaseQty(line) {
      return planTargetBaseQty(line)
    },
    remaining(line) {
      if (!line) return 0
      return Math.max(0, planAllocation(line).delta)
    },
    itemName(id) { const item = this.items.find(i => Number(i.id) === Number(id)); return item ? `${item.item_code} / ${item.item_name}${item.spec_model ? ` / ${item.spec_model}` : ''}` : '请选择物料' },
    serialTrackingMode(line) { const item = this.items.find(row => Number(row.id) === Number(line.item_id)); return item ? (item.serial_tracking_mode || (item.is_serial_managed ? 'required' : 'none')) : 'none' },
    isSerialManaged(line) { return this.serialTrackingMode(line) !== 'none' },
    markSupplierSerials(line) { line.serial_number_source = 'supplier' },
    serialQuantity(line) { const received = Number(line.qty || 0); const actual = Number(line.actual_base_qty == null ? this.standardBaseQtyNumber(line) : line.actual_base_qty); return received > 0 ? actual * Number(line.qualified_qty || 0) / received : 0 },
    serialNumberList(value) { return String(value || '').split(/\r?\n|,|，/).map(row => row.trim()).filter(Boolean) },
    serialGenerationButtonText(line) { const quantity = this.serialQuantity(line); return Number.isInteger(quantity) && quantity > 0 ? `一次生成 ${quantity} 个` : '一次生成全部编号' },
    async generateLineSerials(line) { const quantity = this.serialQuantity(line); if (!Number.isInteger(quantity) || quantity <= 0) return this.$message.error('合格实际入库数量必须是大于 0 的整数后才能生成序列号'); try { const response = await generateReceiptSerials({ item_id: line.item_id, quantity }); this.$set(line, 'serial_text', (response.data.data || []).join('\n')); this.$set(line, 'serial_number_source', 'system_generated'); this.$message.success(`已生成 ${quantity} 个序列号，请核对后保存`) } catch (e) { this.$message.error(e.userMessage || '序列号生成失败') } },
    printSerialLabels(line, serialNo = '') {
      const serials = serialNo ? [serialNo] : this.serialNumberList(line.serial_text)
      if (!serials.length) return this.$message.warning('请先录入或生成设备编号')
      const item = this.items.find(row => Number(row.id) === Number(line.item_id)) || {}
      const escapeHtml = value => String(value == null ? '' : value).replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char]))
      const labels = serials.map(value => `<article><div class="title">设备编号 / 序列号</div><div class="serial">${escapeHtml(value)}</div><div class="meta">物料：${escapeHtml(item.item_code || '-')} / ${escapeHtml(item.item_name || '-')}</div><div class="meta">到货单：${escapeHtml(this.form.receipt_no || '-')}</div></article>`).join('')
      const popup = window.open('', '_blank', 'width=760,height=640')
      if (!popup) return this.$message.error('打印窗口被浏览器拦截，请允许弹出窗口后重试')
      popup.document.open()
      popup.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>设备编号标签</title><style>@page{size:70mm 40mm;margin:3mm}*{box-sizing:border-box}body{margin:0;font-family:Arial,"Microsoft YaHei",sans-serif;color:#111}article{width:64mm;height:34mm;padding:4mm;border:1px solid #222;page-break-after:always;display:flex;flex-direction:column;justify-content:center}.title{font-size:10pt}.serial{margin:2.5mm 0;font-size:16pt;font-weight:700;word-break:break-all}.meta{font-size:8.5pt;line-height:1.5}article:last-child{page-break-after:auto}@media screen{body{padding:16px;background:#eee}article{margin:0 auto 16px;background:#fff}}


.text-warning-bold {
  color: #d97706 !important;
  font-weight: 700;
}
</style></head><body>${labels}</body></html>`)
      popup.document.close()
      popup.focus()
      window.setTimeout(() => popup.print(), 250)
    },
    itemUnitName(id) {
      const item = this.items.find(row => Number(row.id) === Number(id))
      const unit = item && (item.unit || item.inventory_unit || item.base_unit)
      const canonical = unit && (unit.standard_unit || unit.standardUnit || unit)
      return canonical ? (canonical.symbol || canonical.unit_name || canonical.unit_code) : '-'
    },
    linesForAmount() { return this.type === 'plan' ? this.form.items.flatMap(i => (i.splits || []).map(s => ({ qty: s.purchase_quantity, price: s.purchase_unit_price, tax: s.tax_rate }))) : this.form.items.map(i => ({ qty: i.qty, price: i.unit_price, tax: i.tax_rate || 0 })) },
    async loadRecommendations() {
      const revision = ++this.recommendationRevision
      this.recommendations = []
      this.recommendLoading = false
      const line = this.activeLine
      if (!line || !line.item_id) return this.$message.warning('请先选择当前采购物料')
      const item = this.items.find(row => Number(row.id) === Number(line.item_id))
      const quantity = this.type === 'plan' ? Number(line.purchase_quantity || 0) : Number(line.qty || 0)
      const unitId = Number(line.purchase_unit_id || line.unit_id || item?.unit_id || 0)
      if (!quantity || !unitId) return this.$message.warning('请先填写数量并确认采购单位')
      this.recommendLoading = true
      try {
        const response = await getSupplierRecommendations(line.item_id, {
          quantity,
          unit_id: unitId,
          required_date: this.type === 'plan' ? line.expected_date : line.expected_arrival_date,
          currency: this.form.currency || 'CNY',
          tax_mode: this.form.tax_mode || 'tax_included'
        })
        if (revision !== this.recommendationRevision || this.activeLine !== line) return
        this.recommendations = response.data.data.candidates || []
        // 供应商推荐是采购决策参考，不是采购订单的前置强约束。
        // 空候选只显示空态，避免用户调整数量或单价时被重复的警告打断。
      } catch (e) {
        if (revision !== this.recommendationRevision || this.activeLine !== line) return
        this.recommendations = []
        this.$message.error(e.userMessage || '供应商推荐加载失败')
      } finally {
        if (revision === this.recommendationRevision) this.recommendLoading = false
      }
    },
    supplierName(id) {
      if (!id) return '-'
      const s = this.suppliers.find(row => Number(row.id) === Number(id))
      return s ? s.supplier_name : '--'
    },
    handlePlanSelectionChange(rows) {
      this.selectedPlanRows = rows
    },
    async removeSelectedPlanLines() {
      if (!this.selectedPlanRows.length) return
      try {
        await this.$confirm(`确定删除选中的 ${this.selectedPlanRows.length} 行物料吗？`, '提示', { type: 'warning' })
        this.form.items = this.form.items.filter(item => !this.selectedPlanRows.includes(item))
        this.selectedPlanRows = []
        if (!this.form.items.length) {
          this.addPlanItem()
        }
        this.$message.success('已批量删除所选物料行')
      } catch (e) {}
    },
    async clearAllPlanLines() {
      try {
        await this.$confirm('确定清空所有计划物料明细吗？', '提示', { type: 'warning' })
        this.form.items = []
        this.addPlanItem()
        this.selectedPlanRows = []
        this.$message.success('已清空计划物料明细')
      } catch (e) {}
    },
    togglePlanExpandAll() {
      this.isPlanAllExpanded = !this.isPlanAllExpanded
      ;(this.form.items || []).forEach(row => {
        this.$refs.planTable?.toggleRowExpansion(row, this.isPlanAllExpanded)
      })
    },
    openSupplierSplitDialog(row, index) {
      if (!row.item_id) return this.$message.warning('请先选择物料')
      // 物料选择后单位与换算异步载入；此时不能用缺省精度生成供应商采购量。
      if (row._planningPending || !row.purchase_unit_id || !row._planningPreview) {
        return this.$message.warning(row._planningError || '采购单位和数量尚未计算完成，请稍后再分配')
      }
      this.activeSplitRow = row
      this.activeSplitIndex = typeof index === 'number' ? index : this.form.items.indexOf(row)
      if (!row.splits) this.$set(row, 'splits', [])
      if (row.splits.length === 0) {
        const rem = this.remainingPurchaseQuantity(row)
        const split = {
          supplier_id: null,
          purchase_unit_id: row.purchase_unit_id,
          purchase_quantity: rem > 0 ? rem : (row.purchase_quantity || 1),
          purchase_unit_price: 0,
          tax_rate: 13,
          expected_date: row.expected_date || ''
        }
        row.splits.push(split)
        this.initializeSplit(row, split)
      }
      this.activeIndex = this.form.items.indexOf(row)
      this.showRecommendInDialog = false
      this.supplierDialogVisible = true
      this.loadRecommendations()
    },
    addSplitToActiveRow() {
      if (!this.activeSplitRow) return
      this.addSplitToLine(this.activeSplitRow)
    },
    toggleRecommendInDialog() {
      this.showRecommendInDialog = !this.showRecommendInDialog
      if (this.showRecommendInDialog) {
        this.loadRecommendations()
      }
    },
    chooseRecommendationInDialog(candidate) {
      if (!candidate.auto_selectable || !this.activeSplitRow) return
      this.chooseRecommendationForLine(candidate, this.activeSplitRow)
    },
    navigateSplitMaterial(delta) {
      const items = this.validPlanItems
      const cur = items.indexOf(this.activeSplitRow)
      const next = cur + delta
      if (next >= 0 && next < items.length) {
        this.openSupplierSplitDialog(items[next], this.form.items.indexOf(items[next]))
      }
    },
    closeSupplierSplitDialog() {
      this.supplierDialogVisible = false
    },
    handleDialogSplitSupplierChange(row, split) {
      if (split && !split.purchase_unit_id) {
        this.$set(split, 'purchase_unit_id', row.purchase_unit_id)
      }
      this.refreshPlanning(row, split)
    },
    handleDialogSplitUnitChange(row, split) {
      this.refreshPlanning(row, split)
    },
    handleDialogSplitPriceChange(split) {
      if (split && split._planningPreview) {
        this.$set(split._planningPreview, 'purchase_unit_price', Number(split.purchase_unit_price || 0))
        this.$set(split._planningPreview, 'amount', this.splitAmount(split))
      }
    },
    hasAllocatedSupplier(row) {
      return (row?.splits || []).some(s => Boolean(s.supplier_id))
    },
    allocatedSuppliersCount(row) {
      const ids = new Set((row?.splits || []).filter(s => Boolean(s.supplier_id)).map(s => s.supplier_id))
      return ids.size
    },
    hasUnassignedSplit(row) {
      const splits = row?.splits || []
      return splits.length > 0 && splits.some(s => !s.supplier_id)
    },
    isPlanBalanced(row) {
      return planAllocation(row).balanced
    },
    allocationState: planAllocation,
    allocationLabel(row) { return planAllocationLabel(planAllocation(row)) },
    allocationTag(row) { return planAllocationTag(planAllocation(row)) },
    async addSplitToLine(line) {
      if (!line.item_id) return this.$message.warning('请先选择物料')
      line.splits = line.splits || []
      const rem = this.remainingPurchaseQuantity(line)
      const split = {
        supplier_id: null,
        purchase_unit_id: line.purchase_unit_id,
        purchase_quantity: rem > 0 ? rem : 1,
        purchase_unit_price: 0,
        tax_rate: 13,
        expected_date: line.expected_date || ''
      }
      line.splits.push(split)
      await this.initializeSplit(line, split)
      this.$refs.planTable?.toggleRowExpansion(line, true)
    },
    async toggleRecommendForLine(line) {
      this.$set(line, '_showRecommend', !line._showRecommend)
      if (line._showRecommend) {
        this.activeIndex = this.form.items.indexOf(line)
        await this.loadRecommendations()
      }
    },
    chooseRecommendationForLine(candidate, line) {
      if (!candidate.auto_selectable) return
      line.splits = line.splits || []
      const snapshot = {
        recommended_supplier_id_snapshot: candidate.recommended ? candidate.supplier_id : (this.recommendations.find(row => row.recommended)?.supplier_id || null),
        recommended_price_snapshot: candidate.comparable_price,
        recommendation_basis_snapshot: candidate.recommendation_basis,
        recommendation_time: new Date().toISOString().slice(0, 19).replace('T', ' ')
      }
      const existing = line.splits.find(row => Number(row.supplier_id) === Number(candidate.supplier_id)) || line.splits.find(row => !row.supplier_id)
      // 推荐单价按物料行的采购单位比较，不能直接覆盖不同单位拆分行的单价。
      if (existing && Number(existing.purchase_unit_id) !== Number(line.purchase_unit_id)) {
        return this.$message.warning('该分配行的采购单位与推荐报价单位不同，请先统一采购单位后再选用')
      }
      const split = existing || {
        supplier_id: candidate.supplier_id,
        purchase_quantity: this.remainingPurchaseQuantity(line),
        purchase_unit_id: line.purchase_unit_id,
        tax_rate: candidate.tax_rate || 0,
        expected_date: line.expected_date || ''
      }
      this.$set(split, 'supplier_id', candidate.supplier_id)
      Object.assign(split, snapshot)
      if (candidate.comparable_price != null) split.unit_price = candidate.comparable_price
      if (!existing) line.splits.push(split)
      this.$set(split, 'purchase_unit_price', candidate.comparable_price == null ? Number(split.purchase_unit_price || 0) : Number(candidate.comparable_price))
      this.refreshPlanning(line, split)
      this.$refs.planTable?.toggleRowExpansion(line, true)
      this.$message.success(`已选用 ${candidate.supplier_name}，推荐依据和价格已留存快照`)
    },
    splitAmount(split) {
      const qty = Number(split.purchase_quantity || split.purchase_qty || 0)
      const price = Number(split.purchase_unit_price ?? split.unit_price ?? 0)
      return Number((qty * price).toFixed(2))
    },
    lineTotalAmount(line) {
      return (line.splits || []).reduce((sum, s) => sum + this.splitAmount(s), 0)
    },
    async batchSetPlanDeliveryDate() {
      if (!this.selectedPlanRows.length) return this.$message.warning('请先勾选需要设置交期的物料行')
      try {
        const { value } = await this.$prompt('请输入统一期望交期 (YYYY-MM-DD)', '批量设置期望交期', {
          inputType: 'date',
          inputValue: new Date().toISOString().slice(0, 10),
          confirmButtonText: '应用到所选行'
        })
        if (value) {
          this.selectedPlanRows.forEach(row => {
            this.$set(row, 'expected_date', value)
            ;(row.splits || []).forEach(split => {
              if (!split.expected_date) this.$set(split, 'expected_date', value)
            })
          })
          this.$message.success(`已为 ${this.selectedPlanRows.length} 行设置期望交期：${value}`)
        }
      } catch (e) {}
    },
    chooseRecommendation(candidate) {
      if (!candidate.auto_selectable) return
      const snapshot = {
        recommended_supplier_id_snapshot: candidate.recommended ? candidate.supplier_id : (this.recommendations.find(row => row.recommended)?.supplier_id || null),
        recommended_price_snapshot: candidate.comparable_price,
        recommendation_basis_snapshot: candidate.recommendation_basis,
        recommendation_time: new Date().toISOString().slice(0, 19).replace('T', ' ')
      }
      if (this.type === 'plan') {
        const existing = (this.activeLine.splits || []).find(row => Number(row.supplier_id) === Number(candidate.supplier_id))
        const split = existing || { supplier_id: candidate.supplier_id, purchase_quantity: this.remainingPurchaseQuantity(this.activeLine), purchase_unit_id: this.activeLine.purchase_unit_id, tax_rate: candidate.tax_rate || 0 }
        Object.assign(split, snapshot)
        if (candidate.comparable_price != null) split.unit_price = candidate.comparable_price
        if (!existing) this.activeLine.splits.push(split)
        this.$set(split, 'purchase_unit_price', candidate.comparable_price == null ? Number(split.purchase_unit_price || 0) : Number(candidate.comparable_price))
        this.initializeSplit(this.activeLine, split)
      } else if (this.type === 'order') {
        this.form.supplier_id = candidate.supplier_id
        Object.assign(this.activeLine, snapshot)
        if (candidate.comparable_price != null) this.activeLine.unit_price = candidate.comparable_price
      }
      this.$message.success(`已选用 ${candidate.supplier_name}，推荐依据和价格已留存快照`)
    },
    capabilityText(v) { return ({ confirmed_item: '具体物料', quotation: '有效报价', purchase_history: '采购历史', item_default: '物料默认', category_candidate: '品类候选' })[v] || v || '-' },
    basisText(v) {
      return ({
        VALID_QUOTE_BEST_PRICE: '有效报价优价', RECENT_PURCHASE_BEST_PRICE: '近期采购优价',
        DEFAULT_SUPPLIER: '默认供应商', LAST_SUCCESSFUL_SUPPLIER: '最近成功供应商',
        CATEGORY_CANDIDATE: '品类候选', CONFIRMED_CAPABILITY: '已确认能力'
      })[v] || v || '-'
    },
    async ensureRecommendationOverrides() {
      const rows = this.type === 'plan' ? this.form.items.flatMap(item => item.splits || []) : (this.type === 'order' ? this.form.items : [])
      for (const row of rows) {
        const actual = this.type === 'plan' ? row.supplier_id : this.form.supplier_id
        if (!row.recommended_supplier_id_snapshot || Number(row.recommended_supplier_id_snapshot) === Number(actual) || row.supplier_override_reason) continue
        const result = await this.$prompt('当前选择与系统推荐供应商不同，请填写调整原因。该原因会随订单留痕。', '供应商调整留痕', {
          inputPattern: /\S+/, inputErrorMessage: '必须填写调整原因', confirmButtonText: '确认调整'
        })
        this.$set(row, 'supplier_override_reason', 'manual_business_judgement')
        this.$set(row, 'supplier_override_remark', result.value)
      }
    },
    payload() {
      let payload
      if (this.type === 'request') payload = { ...this.form, items: this.form.items.map(i => ({ ...i, purchase_quantity: i.purchase_quantity, expected_conversion_fingerprint: i._planningPreview?.conversion_fingerprint })) }
      else if (this.type === 'plan') payload = { ...this.form, items: this.form.items.map(i => ({ ...i, purchase_quantity: i.purchase_quantity, expected_conversion_fingerprint: i._planningPreview?.conversion_fingerprint, splits: (i.splits || []).map(split => ({ ...split, expected_conversion_fingerprint: split._planningPreview?.conversion_fingerprint })) })) }
      else if (this.type === 'order') payload = { ...this.form, items: this.form.items.map(i => ({ ...i, order_qty: i.qty, expected_conversion_factor: this.effectiveConversionFactor(i) })) }
      else payload = { ...this.form, items: this.form.items.map(i => ({ ...i, receipt_qty: i.qty })) }
      if (!this.$route.params.id && this.reservation) {
        payload.reservation_token = this.reservation.reservation_token
        payload.creation_session_id = this.reservation.creation_session_id
      }
      if (this.type === 'order') payload.attachment_draft_token = this.attachmentDraftToken
      return payload
    },
    newDraftToken() { return window.crypto && window.crypto.randomUUID ? window.crypto.randomUUID() : `purchase-${Date.now()}-${Math.random().toString(16).slice(2)}` },
    async save(submit) {
      if (this.scopeChanging || this.saving) return
      if (this.scopeIssue) return this.$message.error(this.scopeIssue)
      if (this.form.items.some(line => line.warehouse_id && !this.scopedWarehouses.some(row => Number(row.id) === Number(line.warehouse_id)))) return this.$message.error('目标仓库与单据管理类型不一致或不可用，请重新选择')
      if (!this.form.items.length) return this.$message.error('请至少添加一行采购物料')
      if (this.type === 'plan' && this.form.items.some(line => (line.splits || []).some(split => !split.supplier_id))) return this.$message.error('请为每条供应商分配选择供应商，或删除空白分配行')
      if (!['plan', 'request'].includes(this.type) && !this.form.supplier_id) return this.$message.error('供应商不能为空')
      if (this.type === 'plan' && this.form.items.some(i => this.allocated(i) > Number(i._planningPreview?.planned_base_qty || 0) + 0.00000001 || (submit && this.remaining(i) > 0.00000001))) return this.$message.error('供应商采购数量不正确，请核对计划采购数量与已分配数量')
      if (this.form.items.some(line => !line.item_id)) return this.$message.error('每一行都必须选择采购物料')
      if (this.type === 'order' && this.form.items.some(line => !line.purchase_unit_id)) return this.$message.error('每一行都必须选择有效采购单位')
      if (this.type === 'receipt' && this.form.items.some(line => this.hasDifference(line) && !String(line.difference_reason || '').trim())) return this.$message.error('实际基本数量与标准数量不同时，必须填写差异原因')
      if (['request', 'plan'].includes(this.type)) {
        const rows = this.form.items.flatMap(line => [line, ...(line.splits || [])])
        if (rows.some(row => row._planningPending)) return this.$message.warning('采购换算正在计算，请稍候保存')
        if (rows.some(row => row._planningError || !row._planningPreview)) return this.$message.error('请先完成采购单位及数量换算')
      }
      const revision = this.documentRevision
      const document = this.form
      this.saving = true
      try {
        try {
          await this.ensureRecommendationOverrides()
        } catch (e) {
          if (e === 'cancel') return
          throw e
        }
        if (revision !== this.documentRevision || document !== this.form || this.scopeIssue) return
        const api = this.type === 'request' ? savePurchaseRequest : this.type === 'plan' ? savePurchasePlan : this.type === 'order' ? savePurchaseOrder : savePurchaseReceipt
        let res
        try { res = await api(this.payload()) } catch (e) { this.$message.error(e.userMessage || '保存失败'); return }
        const savedId = res.data.data.id
        const created = !this.$route.params.id
        if (created) {
          clearCreatePageReservation(this.reservation)
          this.reservation = null
          if (submit) {
            const path = this.type === 'request' ? `/purchase/requests/${savedId}/edit` : this.type === 'plan' ? `/purchase/plans/${savedId}/edit` : this.type === 'order' ? `/purchase/orders/${savedId}/edit` : `/purchase/receipts/${savedId}/edit`
            await this.$router.replace(path)
          }
        }
        try {
          if (submit && this.type === 'request') await submitRequest(savedId)
          if (submit && this.type === 'plan') await submitPlan(savedId)
          if (submit && this.type === 'order') await submitOrder(savedId)
        } catch (e) {
          this.$message.error(e.userMessage || '提交失败，请检查当前状态和必填信息')
          return
        }
        this.$message.success(submit && this.type === 'request' ? '需求已确认，已锁定需求，可转采购计划' : submit && this.type !== 'receipt' ? '已保存并提交审核' : '保存成功')
        this.$router.push(this.type === 'request' ? '/purchase/requests' : this.type === 'plan' ? '/purchase/plans' : this.type === 'order' ? '/purchase/orders' : '/purchase/receipts')
      } finally { this.saving = false }
    },
    money(v) { return Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
  }
}
</script>

<style scoped>
.planning-conversion-row { display: flex; align-items: center; flex-wrap: wrap; gap: 12px; margin: 8px 0 16px; min-width: 0; }
.planning-conversion-row > * { min-width: 0; max-width: 100%; }
.planning-error { display: block; color: #b91c1c; font-size: 12px; line-height: 1.6; white-space: normal; }
.planning-status { display: block; color: #64748b; font-size: 12px; }
.base-stock-val { color: #0f172a; font-weight: 600; }
.stock-conversion-cell { height: 32px; display: flex; align-items: center; }
.stock-qty-display { display: flex; align-items: center; gap: 4px; font-size: 13px; line-height: 1; }
.stock-num { font-size: 14px; font-weight: 700; color: #008b4b; }
.stock-unit-label { color: #475569; font-size: 12px; }
.stock-info-icon { color: #008b4b; font-size: 13px; cursor: pointer; margin-left: 2px; }
.stock-info-icon:hover { color: #00763f; }
.planning-calc-hint { color: #008b4b; font-size: 12px; }
.planning-error-badge { color: #ef4444; font-size: 12px; cursor: help; }
.cell-empty-dash { color: #94a3b8; font-size: 14px; }

.purchase-form-page {
  box-sizing: border-box;
  max-width: 100%;
  min-height: calc(100vh - 52px);
  background: #f8fafc;
  padding: 16px 20px 80px;
  position: relative;
}

.page-head {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 16px 20px;
  margin-bottom: 14px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
}

.head-left {
  display: flex;
  align-items: center;
  gap: 12px;
}

.head-text {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.title-row {
  display: flex;
  align-items: center;
  gap: 10px;
}

.title-row h1 {
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
  gap: 8px;
}

.receipt-posting-tip {
  border-radius: 8px;
  margin-bottom: 14px;
}

.form-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 320px;
  grid-template-areas: "basic summary" "detail detail";
  gap: 14px;
  align-items: stretch;
  margin-bottom: 14px;
}

.form-layout.request-layout {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.basic-info-card {
  grid-area: basic;
  margin-bottom: 0 !important;
}

.detail-workspace {
  grid-area: detail;
  min-width: 0;
}

.form-layout > aside {
  grid-area: summary;
  display: grid;
  grid-template-rows: auto auto;
  gap: 14px;
  min-width: 0;
}

.form-card {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 16px 20px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  box-sizing: border-box;
  min-width: 0;
  margin-bottom: 14px;
}

.card-head-title {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 14px;
}

.bar-accent {
  width: 3px;
  height: 16px;
  background: #008b4b;
  border-radius: 2px;
}

.card-head-title h3,
.section-title h3 {
  margin: 0;
  font-size: 14px;
  font-weight: 600;
  color: #1e293b;
}

.grid-4 {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
}

.remark-span-3 {
  grid-column: span 3;
}

.grid-3 {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
}

.grid-3 .el-select,
.grid-3 .el-date-editor,
.grid-4 .el-select,
.grid-4 .el-date-editor {
  width: 100%;
}

.items-card .section-title {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 12px;
}

.title-with-bar {
  display: flex;
  align-items: center;
  gap: 8px;
}

.line-actions {
  display: flex;
  align-items: center;
  gap: 8px;
}

.items-table {
  width: 100%;
}

.items-table ::v-deep .el-table__row td {
  padding: 6px 0 !important;
  vertical-align: middle;
}

.items-table ::v-deep .el-input__inner {
  height: 32px !important;
  line-height: 32px !important;
}

.split-editor ::v-deep .el-table .el-input-number,
.split-editor ::v-deep .el-table .el-date-editor,
.split-editor ::v-deep .el-table .el-select {
  width: 100%;
  min-width: 0;
}

.split-editor ::v-deep .el-table .el-input-number.is-controls-right .el-input__inner {
  padding-left: 6px;
  padding-right: 32px;
}

.danger-link {
  color: #ef4444 !important;
  font-size: 12px;
}

.danger-link:hover {
  color: #dc2626 !important;
  text-decoration: underline;
}

.item-picker-input {
  width: 100%;
}

.item-picker-input ::v-deep .el-input__inner {
  cursor: pointer;
  background: #fff;
}

.item-picker-input ::v-deep .el-input-group__append {
  padding: 0 10px;
  background: #f0fdf4;
  border-color: #bbf7d0;
  color: #008b4b;
}

.item-picker-input ::v-deep .el-input-group__append .el-button {
  margin: -8px -10px;
  padding: 8px 10px;
  color: #008b4b;
  font-weight: 500;
}

.stat-dl {
  display: grid;
  grid-template-columns: 88px 1fr;
  gap: 8px;
  margin: 0;
  font-size: 13px;
}

.stat-dl dt {
  color: #64748b;
}

.stat-dl dd {
  margin: 0;
  color: #1e293b;
  font-weight: 600;
  text-align: right;
}

.green-text {
  color: #008b4b !important;
}

.grand-total {
  color: #008b4b !important;
  font-size: 15px;
  font-weight: 700;
}

.item-cell-box,
.empty-item-cell {
  height: 38px;
  min-height: 38px;
  max-height: 38px;
  display: flex;
  align-items: center;
  box-sizing: border-box;
}

.item-cell-box {
  justify-content: space-between;
  gap: 8px;
  padding: 0;
}

.item-cell-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.item-code-line {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.code-badge {
  font-family: monospace;
  font-weight: 600;
  color: #008b4b;
  font-size: 13px;
}

.item-name-bold {
  color: #1e293b;
  font-size: 13px;
}

.item-spec-sub {
  color: #64748b;
  font-size: 12px;
}

.theme-link-btn {
  color: #008b4b !important;
  font-size: 12px;
  flex-shrink: 0;
}

.theme-link-btn:hover {
  color: #00763f !important;
}

.empty-item-cell {
  padding: 2px 0;
}

.table-summary-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 14px;
  padding: 10px 16px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  flex-wrap: wrap;
  gap: 12px;
}

.batch-quick-tools {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.selected-hint {
  font-size: 13px;
  color: #475569;
}

.selected-hint strong {
  color: #008b4b;
}

.summary-metrics {
  display: flex;
  align-items: center;
  gap: 20px;
  margin-left: auto;
}

.metric-item {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 13px;
}

.metric-label {
  color: #64748b;
}

.metric-val {
  color: #1e293b;
}

.metric-val.highlight-green {
  color: #008b4b;
  font-size: 15px;
}

.purchase-form-page ::v-deep .el-button--success {
  background-color: #008b4b !important;
  border-color: #008b4b !important;
  color: #fff !important;
}

.purchase-form-page ::v-deep .el-button--success:hover,
.purchase-form-page ::v-deep .el-button--success:focus {
  background-color: #00763f !important;
  border-color: #00763f !important;
}

.purchase-form-page ::v-deep .el-checkbox__input.is-checked .el-checkbox__inner,
.purchase-form-page ::v-deep .el-checkbox__input.is-indeterminate .el-checkbox__inner {
  background-color: #008b4b !important;
  border-color: #008b4b !important;
}

.bottom-actions {
  box-sizing: border-box;
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  height: 58px;
  padding: 10px 24px;
  display: flex;
  justify-content: flex-end;
  align-items: center;
  gap: 10px;
  background: #fff;
  border-top: 1px solid #e2e8f0;
  box-shadow: 0 -4px 12px rgba(0, 0, 0, 0.05);
  z-index: 30;
}

/* 计划表格内嵌拆分抽屉样式 */
.plan-split-drawer {
  background: #f8fafc;
  padding: 14px 18px;
  border-radius: 6px;
  border: 1px solid #e2e8f0;
  margin: 6px 0;
}

.split-drawer-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 12px;
}

.drawer-title-box {
  display: flex;
  align-items: center;
  gap: 8px;
  color: #008b4b;
  font-size: 13px;
}

.drawer-title-accent {
  width: 3px;
  height: 12px;
  background: #008b4b;
  border-radius: 2px;
}

.spec-chip {
  font-size: 11px;
  color: #475569;
  background: #fff;
  padding: 1px 7px;
  border-radius: 3px;
  border: 1px solid #e2e8f0;
}

.drawer-balance-gauge {
  font-size: 12px;
  color: #475569;
  display: flex;
  align-items: center;
  gap: 6px;
}

.drawer-balance-gauge .divider {
  color: #cbd5e1;
}

.drawer-actions-box {
  display: flex;
  align-items: center;
  gap: 8px;
}

.splits-edit-table {
  background: #fff;
}

/* 采购计划表格中的供应商分配紧凑结构化卡片 */
.plan-split-cell-card {
  display: flex;
  flex-direction: column;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 5px 8px;
  cursor: pointer;
  transition: all 0.2s ease-in-out;
  box-sizing: border-box;
  width: 100%;
  min-width: 0;
}

.plan-split-cell-card:hover {
  background: #f0fdf4;
  border-color: #86efac;
  box-shadow: 0 1px 4px rgba(0, 139, 75, 0.08);
}

.plan-split-cell-card:hover .split-edit-btn {
  color: #008b4b;
  font-weight: 600;
}

.split-card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 4px;
  padding-bottom: 4px;
  border-bottom: 1px dashed #e2e8f0;
  gap: 6px;
}

.header-status-group {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-width: 0;
}

.header-status-group .alloc-tag {
  font-weight: 600;
  padding: 0 6px;
  height: 20px;
  line-height: 18px;
  border-radius: 3px;
  flex-shrink: 0;
}

.split-count-badge {
  font-size: 11px;
  background: #ede9fe;
  color: #6d28d9;
  padding: 1px 6px;
  border-radius: 3px;
  font-weight: 500;
  white-space: nowrap;
}

.split-edit-btn {
  font-size: 11px;
  color: #94a3b8;
  display: inline-flex;
  align-items: center;
  gap: 2px;
  transition: color 0.15s ease;
  white-space: nowrap;
  flex-shrink: 0;
}

.split-card-list {
  display: flex;
  flex-direction: column;
  gap: 3px;
  max-height: 96px;
  overflow-y: auto;
}

.split-card-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 2px 6px;
  border-radius: 4px;
  background: #ffffff;
  border: 1px solid #f1f5f9;
  font-size: 12px;
  line-height: 1.4;
  min-width: 0;
}

.split-card-row:hover {
  background: #fafafa;
}

.supplier-info {
  display: flex;
  align-items: center;
  gap: 4px;
  flex: 1;
  min-width: 0;
}

.supplier-ico {
  font-size: 12px;
  color: #94a3b8;
  flex-shrink: 0;
}

.supplier-name-txt {
  font-weight: 500;
  color: #1e293b;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 12px;
  flex: 1;
  min-width: 0;
}

.split-qty-price-pill {
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  gap: 3px;
  font-size: 11px;
  background: #f0fdf4;
  border: 1px solid #dcfce7;
  color: #15803d;
  padding: 1px 6px;
  border-radius: 3px;
  font-weight: 500;
  white-space: nowrap;
}

.pill-qty {
  font-weight: 600;
}

.pill-dot {
  color: #86efac;
}

.pill-price {
  font-weight: 600;
}

/* 待分配/未分配空态卡片 (高可视度、防折行挤压) */
.plan-split-empty-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 8px 12px;
  background: #fff7ed;
  border: 1px dashed #fb923c;
  border-left: 3px solid #ea580c;
  border-radius: 6px;
  cursor: pointer;
  transition: all 0.2s ease;
  box-sizing: border-box;
  width: 100%;
  min-height: 42px;
}

.plan-split-empty-card:hover {
  background: #ffedd5;
  border-color: #ea580c;
  box-shadow: 0 1px 4px rgba(234, 88, 12, 0.15);
}

.empty-main-info {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
  flex: 1;
}

.empty-unallocated-tag {
  height: 22px;
  line-height: 20px;
  padding: 0 8px;
  flex-shrink: 0;
  font-weight: 600;
  border-color: #fdba74;
}

.empty-subtext {
  font-size: 12px;
  color: #c2410c;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.empty-action-pill {
  font-size: 12px;
  color: #fff;
  background: #ea580c;
  font-weight: 500;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px 10px;
  border-radius: 4px;
  flex-shrink: 0;
  white-space: nowrap;
  transition: background 0.2s ease;
}

.plan-split-empty-card:hover .empty-action-pill {
  background: #c2410c;
}

.highlight-alloc-action {
  font-weight: 600;
  color: #ea580c !important;
}

.highlight-alloc-action:hover {
  color: #c2410c !important;
}

.missing-supplier-tag {
  font-size: 11px;
  padding: 0 4px;
  height: 18px;
  line-height: 16px;
}

/* 分配弹窗采购单位列防高度抖动容器 */
.table-cell-unit-wrapper {
  position: relative;
  display: flex;
  align-items: center;
  width: 100%;
}

.unit-cell-error-icon {
  position: absolute;
  right: 28px;
  font-size: 14px;
  cursor: pointer;
  z-index: 2;
}

.plan-items-table .el-table__expanded-cell {
  background: #f8fafc;
  padding: 10px 16px;
}

.remark-span-4 {
  grid-column: span 4;
}

/* 计划与订单拆分编辑 */
.plan-editor {
  display: grid;
  grid-template-columns: 300px minmax(0, 1fr);
  gap: 12px;
}

.material-list,
.split-editor {
  min-width: 0;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 14px;
}

.material-card {
  padding: 10px;
  border-left: 3px solid transparent;
  border-bottom: 1px solid #edf0f2;
  cursor: pointer;
}

.material-card.active {
  background: #f0fdf4;
  border-left-color: #008b4b;
}

.material-card b,
.material-card span {
  display: block;
}

.material-card span {
  color: #64748b;
  font-size: 11px;
}

.material-base {
  margin-bottom: 10px;
}

.recommend-card {
  margin: 10px 0;
  padding: 12px;
  border: 1px solid #bbf7d0;
  border-radius: 6px;
  background: #f0fdf4;
}

.recommend-card .section-title {
  margin-bottom: 8px;
}

.recommend-card .section-title h3 {
  margin: 0 0 2px;
}

.recommend-card .section-title span {
  font-size: 11px;
  color: #475569;
}

.serial-entry-tools {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 6px;
  font-size: 11px;
  color: #64748b;
}

.serial-entry-tools .el-button {
  flex: 0 0 auto;
  padding: 5px 8px;
}

.serial-number-panel {
  margin-top: 6px;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  background: #f8fafc;
}

.serial-number-summary {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 4px 8px;
  border-bottom: 1px solid #e2e8f0;
  color: #64748b;
  font-size: 11px;
}

.serial-number-list {
  max-height: 132px;
  overflow-y: auto;
}

.serial-number-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  min-height: 28px;
  padding: 2px 8px;
  border-bottom: 1px solid #edf1ee;
}

.serial-number-item:last-child {
  border-bottom: 0;
}

.serial-number-item span {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-family: Consolas, monospace;
  color: #1e293b;
}

.serial-number-item .el-button {
  flex: 0 0 auto;
  padding: 3px 0;
}

::v-deep .purchase-lines-table .el-select,
::v-deep .purchase-lines-table .el-input,
::v-deep .purchase-lines-table .el-input-number,
::v-deep .purchase-lines-table .el-date-editor {
  box-sizing: border-box;
  width: 100% !important;
  max-width: 100%;
}

::v-deep .purchase-lines-table .line-number-input .el-input__inner {
  padding-left: 8px;
  padding-right: 38px;
  text-align: left;
}

.unit-source-help {
  margin-left: 2px;
  color: #64748b;
  cursor: help;
}

@media (max-width: 1180px) {
  .form-layout {
    grid-template-columns: minmax(0, 1fr);
    grid-template-areas: "basic" "summary" "detail";
  }
  .form-layout > aside {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    grid-template-rows: auto;
    gap: 14px;
  }
  .grid-3,
  .grid-4 {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .remark-span-3 {
    grid-column: span 2;
  }
}

@media (max-width: 760px) {
  .purchase-form-page {
    padding: 12px 10px 76px;
  }
  .page-head {
    flex-direction: column;
    align-items: stretch;
  }
  .head-left {
    align-items: flex-start;
    min-width: 0;
  }
  .head-text {
    min-width: 0;
  }
  .title-row {
    flex-wrap: wrap;
  }
  .title-row h1 {
    flex-basis: 100%;
    font-size: 18px;
  }
  .head-actions {
    flex-wrap: wrap;
    justify-content: flex-start;
  }
  .head-actions ::v-deep .el-button,
  .bottom-actions ::v-deep .el-button {
    margin-left: 0;
    padding-left: 12px;
    padding-right: 12px;
  }
  .grid-3,
  .grid-4,
  .form-layout > aside {
    grid-template-columns: minmax(0, 1fr);
  }
  .remark-span-3 {
    grid-column: span 1;
  }
  .table-summary-bar {
    flex-direction: column;
    align-items: stretch;
  }
  .summary-metrics {
    margin-left: 0;
    justify-content: space-between;
  }
  .plan-editor {
    grid-template-columns: minmax(0, 1fr);
  }
  .bottom-actions {
    left: 0;
    padding: 10px 14px;
  }
  .section-title {
    align-items: flex-start;
    gap: 8px;
  }
}
</style>

<style>
/* 供应商分配模态弹窗全局样式 (针对 append-to-body 弹窗) */
.supplier-split-dialog {
  box-sizing: border-box;
  max-width: calc(100% - 32px);
  max-height: 90vh;
  display: flex;
  flex-direction: column;
  border-radius: 10px;
  overflow: hidden;
  box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
}
.supplier-split-dialog .el-dialog__header {
  padding: 18px 24px;
  background: #f8fafc;
  border-bottom: 1px solid #e2e8f0;
}
.supplier-split-dialog .el-dialog__title {
  font-size: 17px;
  font-weight: 700;
  color: #0f172a;
}
.supplier-split-dialog .el-dialog__body {
  min-height: 0;
  overflow-y: auto;
  padding: 20px 24px;
  background: #fff;
}
.split-mat-card {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 16px 20px;
  margin-bottom: 18px;
}
.mat-card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 14px;
}
.mat-title-area {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.mat-order-badge {
  background: #008b4b;
  color: #fff;
  font-size: 12px;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 4px;
}
.mat-title-area .code-badge {
  background: #f0fdf4;
  color: #166534;
  border: 1px solid #bbf7d0;
  font-family: monospace;
  font-size: 13px;
  padding: 2px 6px;
  border-radius: 4px;
}
.mat-name-txt {
  font-size: 16px;
  color: #1e293b;
}
.mat-spec-pill {
  background: #e2e8f0;
  color: #334155;
  font-size: 12px;
  font-weight: 600;
  padding: 3px 10px;
  border-radius: 4px;
}
.mat-qty-pills {
  display: flex;
  flex-wrap: wrap;
  gap: 14px;
  font-size: 13px;
  color: #64748b;
}
.mat-qty-pills strong {
  white-space: nowrap;
  color: #0f172a;
  font-size: 14px;
}
.mat-balance-banner {
  background: #fff;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  padding: 12px 16px;
}
.balance-labels {
  display: flex;
  align-items: center;
  gap: 12px;
  font-size: 13px;
  color: #64748b;
  margin-bottom: 10px;
  flex-wrap: wrap;
}
.balance-labels strong {
  font-size: 14px;
}
.text-green {
  color: #008b4b !important;
}
.text-danger {
  color: #ef4444 !important;
}
.progress-bar-track {
  width: 100%;
  height: 8px;
  background: #f1f5f9;
  border-radius: 4px;
  overflow: hidden;
}
.progress-bar-fill {
  height: 100%;
  transition: width 0.3s ease, background-color 0.3s ease;
}
.split-table-toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
}
.toolbar-title {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}
.toolbar-title strong {
  font-size: 14px;
  color: #1e293b;
}
.hint-text {
  font-size: 12px;
  color: #94a3b8;
}
.dialog-recommend-box {
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 6px;
  padding: 12px;
  margin-bottom: 14px;
}
.recommend-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 8px;
  font-size: 13px;
  color: #166534;
  font-weight: 600;
}
.dialog-splits-table th {
  background: #f8fafc !important;
  color: #475569;
  font-weight: 600;
}
.supplier-split-dialog .el-input-number.is-controls-right .el-input__inner {
  padding-left: 6px;
  padding-right: 34px;
}
.supplier-split-dialog .el-input-number.is-controls-right .el-input-number__increase,
.supplier-split-dialog .el-input-number.is-controls-right .el-input-number__decrease {
  width: 28px;
}
.dialog-split-footer {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  justify-content: space-between;
  align-items: center;
  padding: 12px 10px 6px;
}
.footer-nav {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
}
.nav-indicator {
  font-size: 13px;
  color: #64748b;
  font-weight: 600;
}
</style>
