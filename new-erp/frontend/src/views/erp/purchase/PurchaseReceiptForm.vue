<template>
  <section class="receipt-form-page">
    <header class="page-head">
      <div class="head-left">
        <el-button size="small" icon="el-icon-arrow-left" circle @click="goBack" />
        <div class="head-text">
          <div class="title-row">
            <h1>{{ isEdit ? '编辑到货单' : '新增到货单' }}</h1>
            <el-tag size="mini" :type="confirmStatusTag" effect="light">{{ confirmStatusText }}</el-tag>
            <el-tag size="mini" :type="form.management_scope === 'office' ? 'info' : 'success'">{{ scopeLabel(form.management_scope) }}</el-tag>
            <span class="doc-no-pill">{{ form.receipt_no || '系统预占生成' }}</span>
            <el-tag v-if="isEdit" size="mini" type="info">ID: {{ $route.params.id }}</el-tag>
          </div>
        </div>
      </div>

      <div class="purchase-flow-steps" aria-label="采购业务流程">
        <template v-for="(step, idx) in flowSteps">
          <div
            :key="step.key"
            :class="['flow-step-item', { 'is-active': idx === 3, 'is-done': idx < 3 }]"
          >
            <span class="flow-step-dot">
              <i v-if="idx < 3" class="el-icon-check" />
              <span v-else>{{ idx + 1 }}</span>
            </span>
            <span class="flow-step-label">{{ step.label }}</span>
          </div>
          <i v-if="idx < flowSteps.length - 1" :key="step.key + '-sep'" class="el-icon-arrow-right flow-step-arrow" />
        </template>
      </div>

      <div class="heading-actions">
        <el-button size="small" @click="goBack">返回列表</el-button>
        <el-button size="small" :loading="saving" :disabled="!!scopeIssue || scopeChanging" @click="save(true)">保存草稿</el-button>
        <el-button size="small" type="success" icon="el-icon-check" :loading="saving" :disabled="!!scopeIssue || scopeChanging" @click="save(false)">保存</el-button>
      </div>
    </header>

    <el-alert v-if="scopeIssue" :title="scopeIssue" type="warning" :closable="false" show-icon class="posting-alert" />
    <el-alert
      v-if="isReplacement"
      class="posting-alert"
      type="warning"
      :closable="false"
      show-icon
      title="本单为换货免费补发到货单：供应商、来源物料、采购数量、单位及价格已锁定；只登记验收、库位和编号，不新增应付。"
    />
    <div v-else class="erp-page-tip">
      <i class="el-icon-info" />
      <span>{{ form.order_id ? '到货确认只生成待过账记录；到货金额保存时按采购订单剩余成交金额分摊。' : '到货确认只生成待过账记录，库存过账仅关联已登记编号。' }}</span>
    </div>

    <section class="form-card basic-info-card">
      <div class="card-head-row">
        <div class="card-head-title">
          <span class="bar-accent"></span>
          <h3>基础信息</h3>
        </div>
        <span class="card-head-hint">带 * 为必填项，保存前支持默认仓库与库位一键填充到非多库位明细行</span>
      </div>
      <div class="grid-4 basic-grid">
        <label class="field-block receipt-no-field">
          <span>到货单号</span>
          <el-input v-model="form.receipt_no" size="small" disabled class="erp-system-number">
            <template slot="append">系统预生成</template>
          </el-input>
        </label>
        <label class="field-block required">
          <span>管理类型</span>
          <el-select :value="form.management_scope" :disabled="scopeLocked" size="small" placeholder="请选择管理类型" @change="changeManagementScope">
            <el-option v-for="scope in scopeOptions" :key="scope.value" :label="scope.label" :value="scope.value" />
          </el-select>
        </label>
        <label class="field-block required">
          <span>供应商</span>
          <paged-record-select v-model="form.supplier_id" size="small" :disabled="isReplacement" placeholder="请选择供应商" entity="suppliers" :params="{ purchase_receipt_available: 1 }" :selected-rows.sync="suppliers" title="选择供应商" />
        </label>
        <label class="field-block required">
          <span>到货日期</span>
          <el-date-picker v-model="form.receipt_date" size="small" type="date" value-format="yyyy-MM-dd" placeholder="选择日期" />
        </label>

        <div class="field-block">
          <span class="label-action-row">
            <span>默认入库仓库</span>
            <button v-if="!isReplacement && defaultWarehouseId && form.items.length" type="button" class="field-sync-link" @click="syncDefaultLocatorToLines">同步到明细</button>
          </span>
          <paged-record-select v-model="defaultWarehouseId" size="small" clearable :disabled="isReplacement" placeholder="选填，默认目标仓库" entity="warehouses" :params="{ management_scope: form.management_scope, statuses: ['enabled', 'active'] }" :selected-rows.sync="warehouses" title="选择默认入库仓库" @change="onHeaderDefaultWarehouseChange" />
        </div>
        <div class="field-block">
          <span class="label-action-row">
            <span>默认入库库位</span>
            <button v-if="!isReplacement && defaultWarehouseId && defaultLocationId && form.items.length" type="button" class="field-sync-link" @click="syncDefaultLocatorToLines">同步到明细</button>
          </span>
          <paged-record-select v-model="defaultLocationId" size="small" clearable :disabled="isReplacement || !defaultWarehouseId" placeholder="选填，先选默认仓库" entity="locations" :params="{ management_scope: form.management_scope, warehouse_id: defaultWarehouseId, statuses: ['enabled', 'active'] }" :selected-rows.sync="locations" title="选择默认入库库位" @change="onHeaderDefaultLocationChange" />
        </div>
        <div class="field-block">
          <span>确认状态</span>
          <div class="readonly-status-box"><el-tag size="small" :type="confirmStatusTag" effect="light">{{ confirmStatusText }}</el-tag></div>
        </div>
        <div class="field-block">
          <span>库存过账状态</span>
          <div class="readonly-status-box"><el-tag size="small" :type="postingStatusTag" effect="light">{{ postingStatusText }}</el-tag></div>
        </div>

        <label class="field-block remark-span-4">
          <span>备注说明</span>
          <el-input v-model="form.remark" type="textarea" :rows="2" resize="none" maxlength="500" show-word-limit placeholder="请输入到货验收备注、运输单号或质量说明（选填）" />
        </label>
      </div>
    </section>

    <section class="form-card items-card lines-section">
      <div class="section-heading">
        <div class="title-with-bar">
          <span class="bar-accent"></span>
          <h2>到货明细</h2>
          <el-tag size="mini" type="success">共 {{ form.items.length }} 行物料</el-tag>
          <span class="section-sub-hint">点击任意行可展开验收数量、库位分配与实物/序列号录入</span>
        </div>
        <div class="line-actions">
          <el-tag v-if="isReplacement" size="small" type="warning">换货来源明细已锁定</el-tag>
          <template v-else>
            <el-button size="small" type="success" icon="el-icon-plus" @click="openBatchItemPicker">选择物料 (多选)</el-button>
            <el-button size="small" icon="el-icon-circle-plus-outline" @click="addLine">添加空白行</el-button>
            <el-button size="small" type="danger" plain icon="el-icon-delete" :disabled="!activeLine" @click="removeActiveLine">删除当前行{{ activeLine ? `（行号 ${activeIndex + 1}）` : '' }}</el-button>
          </template>
        </div>
      </div>

      <div class="line-table-shell">
        <el-table :data="form.items" size="mini" :row-key="row => form.items.indexOf(row)" :expand-row-keys="activeLine ? [activeIndex] : []" :row-class-name="lineRowClass" @row-click="selectLine" @expand-change="onExpandChange">
          <el-table-column type="expand" width="42">
            <template slot-scope="{row,$index}">
              <section ref="lineEditor" class="line-editor">
                <header>验收与入库信息 <span>（行号 {{ $index + 1 }}：{{ itemById(row.item_id).item_name || '请选择物料' }}）</span></header>
                <div class="editor-columns">
                  <section class="editor-group">
                    <h3>数量与换算</h3>
                    <label class="compact-field required"><span>到货采购数量</span><el-input v-model.number="row.qty" type="number" size="small" min="0.0001" :disabled="isReplacement" @input="syncReceiptActualQty(row)"><template slot="append">{{ purchaseUnitName(row) }}</template></el-input></label>
                    <label class="compact-field required"><span>采购单位</span><paged-record-select v-model="row.purchase_unit_id" size="small" :disabled="isReplacement" placeholder="请选择采购单位" @change="applyConversion(row)" :key="'purchase-unit-' + row.item_id" :params="{ item_id: row.item_id }" :request="params => purchaseConversions(row, params)" value-key="purchase_unit_id" :selected-rows.sync="row._conversionOptions" :local-rows="localConversionOptions(row)" :label="purchaseOptionLabel" :columns="[{ prop: 'purchase_unit.unit_name', label: '采购单位' }, { prop: 'factor', label: '换算比例' }, { prop: 'base_unit.unit_name', label: '基本单位' }]" title="选择采购单位" /></label>
                    <label class="compact-field required"><span>换算因子</span><el-input :value="conversionFactor(row)" size="small" disabled /></label>
                    <label class="compact-field"><span>标准基本数量</span><el-input :value="standardBaseQty(row)" size="small" disabled /></label>
                    <label class="compact-field"><span>实际基本数量</span><el-input v-model.number="row.actual_base_qty" type="number" size="small" min="0" :disabled="!actualConversionAllowed(row)" /></label>
                    <label class="compact-field"><span>差异数量</span><el-input :value="differenceQty(row)" size="small" disabled /></label>
                    <label class="compact-field"><span>差异原因</span><el-select v-model="row.difference_reason" size="small" :disabled="!hasDifference(row)" placeholder="无差异"><el-option label="包装重量偏差" value="包装重量偏差" /><el-option label="计量差异" value="计量差异" /><el-option label="验收修正" value="验收修正" /><el-option label="其他" value="其他" /></el-select></label>
                    <label v-if="!isReplacement || $can('inventory.amount.view')" class="compact-field required"><span>{{ isReplacement ? '库存成本单价' : taxMode === 'tax_included' ? '单价（含税）' : '单价（未税）' }}</span><el-input v-model.number="row.unit_price" type="number" size="small" min="0" :disabled="isReplacement || Boolean(row.order_item_id)"><template slot="append">CNY</template></el-input></label>
                  </section>

                  <section class="editor-group">
                    <h3>{{ isStockManaged(row) ? '质量与入库' : '验收与履约' }}</h3>
                    <label class="compact-field required"><span>合格数量</span><el-input v-model.number="row.qualified_qty" type="number" size="small" min="0" @change="syncQualityQty(row, 'qualified_qty')" /></label>
                    <label class="compact-field required"><span>不合格数量</span><el-input v-model.number="row.unqualified_qty" type="number" size="small" min="0" @change="syncQualityQty(row, 'unqualified_qty')" /></label>
                    <template v-if="isStockManaged(row)">
                      <label class="compact-field required"><span>批次号</span><el-input :value="row.batch_no || '保存时系统自动生成'" size="small" disabled class="erp-system-number" /></label>
                      <template v-if="isPipeLine(row)">
                        <label class="compact-field required"><span>每根长度</span><el-input v-model="row.stock_length_mm" size="small" inputmode="decimal" :placeholder="pipeStandardLength(row) ? '不填按标准 ' + pipeStandardLength(row) + ' mm' : '请填写实际每根长度'"><template slot="append">mm</template></el-input></label>
                        <p class="pipe-length-tip">这一行的管都按这个长度入库，共 <strong>{{ pipeTotalMeters(row) }}</strong>；长度不一样的请分成几行录。</p>
                      </template>
                      <label class="compact-field" :class="{ required: qualifiedBaseQty(row) > 0 }"><span>默认仓库</span><paged-record-select v-model="row.warehouse_id" size="small" :placeholder="qualifiedBaseQty(row) > 0 ? '请选择仓库' : '无需入库'" :disabled="qualifiedBaseQty(row) <= 0" @change="onDefaultWarehouseChange(row)" entity="warehouses" :params="{ management_scope: form.management_scope, statuses: ['enabled', 'active'] }" :selected-rows.sync="warehouses" title="选择仓库" /></label>
                      <label class="compact-field" :class="{ required: qualifiedBaseQty(row) > 0 }"><span>默认库位</span><paged-record-select v-model="row.location_id" size="small" :placeholder="qualifiedBaseQty(row) > 0 ? '请选择库位' : '无需入库'" :disabled="qualifiedBaseQty(row) <= 0 || !row.warehouse_id" @change="onDefaultLocationChange(row)" entity="locations" :params="{ management_scope: form.management_scope, warehouse_id: row.warehouse_id, statuses: ['enabled', 'active'] }" :selected-rows.sync="locations" title="选择库位" /></label>
                      <div class="allocation-entry"><span>入库库位分配</span><div><el-button size="mini" type="success" plain icon="el-icon-s-grid" :disabled="qualifiedBaseQty(row) <= 0" @click="openAllocationDialog(row)">多库位分配</el-button><small>{{ allocationProgressText(row) }}</small></div></div>
                    </template>
                    <el-alert v-else title="该物料按非库存方式采购：验收完成后直接形成履约与结算事实，不要求仓库、库位、批次或库存过账。" type="info" :closable="false" />
                    <label class="compact-field"><span>预计到货</span><el-date-picker v-model="row.expected_arrival_date" size="small" type="date" value-format="yyyy-MM-dd" /></label>
                    <label class="compact-field remark-compact"><span>备注</span><el-input v-model="row.remark" type="textarea" :rows="2" resize="none" maxlength="200" show-word-limit placeholder="请输入备注信息（选填）" /></label>
                  </section>

                  <section class="editor-group serial-group">
                    <template v-if="isPhysicalManaged(row)">
                      <div v-for="(group,groupIndex) in physicalGroups(row)" :key="`${row.item_id}-${groupIndex}`" class="physical-group">
                        <h3>{{ warehouseName(group.warehouse_id) }} / {{ locationName(group.location_id) }}</h3>
                        <receipt-physical-entries :entries="group.physical_entries || []" :quantity="Number(group.base_qty)" :mode="itemById(row.item_id).cutting_mode" :measure-unit="physicalMeasureUnit(row)" @change="setPhysicalEntries(row,groupIndex,$event)" />
                      </div>
                    </template>
                    <template v-else>
                    <div class="serial-title"><h3>设备编号 / 序列号</h3><el-tag v-if="isSerialManaged(row)" size="mini" type="danger" effect="plain">{{ serialTrackingMode(row)==='required' ? '必须逐件编号' : '按需逐件编号' }}</el-tag></div>
                    <template v-if="isStockManaged(row) && isSerialManaged(row)">
                      <div class="generate-row"><label><span>合格数量</span><el-input :value="serialRequiredCount(row)" size="small" disabled /></label><el-button size="small" type="success" @click="generateLineSerials(row)">一次生成 {{ serialRequiredCount(row) }} 个</el-button></div>
                      <p v-if="allocations(row).length>1" class="serial-allocation-tip">编号将按库位数量配额自动分配：{{ allocationProgressText(row) }}</p>
                      <label class="scan-label"><span>扫码枪扫描 / 手工输入</span><el-input v-model.trim="row._scanInput" size="small" placeholder="扫描设备编号，回车后自动加入" @keyup.enter.native="addScannedSerial(row)"><i slot="prefix" class="el-icon-full-screen" /><el-button slot="append" @click="addScannedSerial(row)">添加</el-button></el-input></label>
                      <p v-if="row._serialError" class="serial-error">{{ row._serialError }}</p>
                      <p v-else class="serial-helper">支持扫码枪回车录入；重复编号自动拦截</p>
                      <div class="serial-list-heading"><span>已录入 {{ serialNumberList(row).length }} / {{ serialRequiredCount(row) }}</span><el-button type="text" size="mini" @click="printSerialLabels(row)">全部打印</el-button></div>
                      <div class="serial-records">
                        <div v-for="(entry,index) in serialNumberList(row)" :key="entry.serial_no" class="serial-record"><span class="serial-index">{{ index+1 }}</span><strong :title="entry.serial_no">{{ entry.serial_no }}</strong><em>{{ entry.source==='system_generated' ? '系统生成' : '扫码' }}</em><el-button type="text" size="mini" icon="el-icon-printer" @click="printSerialLabels(row,entry.serial_no)">打印</el-button><button type="button" class="serial-delete" @click="removeSerial(row,index)">×</button></div>
                      </div>
                    </template>
                    <el-alert v-else :title="isStockManaged(row) ? '该物料仅按批次追溯，无需录入单件编号。' : '非库存物料不建立库存序列号档案。'" type="info" :closable="false" />
                    </template>
                  </section>
                </div>
              </section>
            </template>
          </el-table-column>
          <el-table-column label="行号" type="index" width="48" align="center" />
          <el-table-column label="物料" min-width="260">
            <template slot-scope="{row}">
              <div class="item-cell">
                <span v-if="row.item_id" class="code-badge">{{ itemById(row.item_id).item_code || '-' }}</span>
                <strong>{{ itemById(row.item_id).item_name || '请选择物料' }}</strong>
              </div>
            </template>
          </el-table-column>
          <el-table-column label="到货数量 / 采购单位" min-width="135"><template slot-scope="{row}">{{ number(row.qty) }} / {{ purchaseUnitName(row) }}</template></el-table-column>
          <el-table-column label="合格 / 不合格" width="112"><template slot-scope="{row}">{{ number(row.qualified_qty) }} / {{ number(row.unqualified_qty) }}</template></el-table-column>
          <el-table-column label="批次" min-width="112"><template slot-scope="{row}">{{ isStockManaged(row) ? (row.batch_no || '系统生成') : '非库存' }}</template></el-table-column>
          <el-table-column label="仓库 / 库位" min-width="166"><template slot-scope="{row}">{{ isStockManaged(row) ? allocationSummary(row) : '无需入库' }}</template></el-table-column>
          <el-table-column label="实物 / 编号" width="118">
            <template slot-scope="{row}"><span v-if="isPhysicalManaged(row)">{{ physicalEntryCount(row) }}{{ physicalPurchaseCount(row) === null ? '' : ' / ' + number(physicalPurchaseCount(row)) }} {{ itemById(row.item_id).cutting_mode === 'length' ? '根' : '张' }}</span><span v-else-if="isPipeLine(row)" class="pipe-length-cell">每根 {{ pipeLength(row) ? pipeLength(row) + ' mm' : '未填' }}</span><div v-else-if="isStockManaged(row)" class="serial-progress"><span>{{ serialNumberList(row).length }} / {{ serialRequiredCount(row) }}</span><i><b :style="{width:serialProgress(row)+'%'}" /></i></div><span v-else>无需编号</span></template>
          </el-table-column>
          <el-table-column v-if="!isReplacement || $can('inventory.amount.view')" :label="isReplacement ? '库存成本金额' : taxMode === 'tax_included' ? '金额（含税）' : '金额（未税）'" width="116" align="right"><template slot-scope="{row}">¥{{ money(lineAmount(row)) }}</template></el-table-column>
          <el-table-column label="操作" width="150">
            <template slot-scope="{row,$index}"><el-button class="expand-line-button" type="text" size="mini" @click.stop="selectLine(row)">{{ row === activeLine ? '收起' : '展开' }}</el-button><el-button type="text" size="mini" :disabled="isReplacement" @click.stop="openItemPicker(row)">{{ isReplacement ? '来源锁定' : (row.item_id ? '更换' : '选择') }}</el-button><el-button v-if="!isReplacement" class="danger-link" type="text" size="mini" @click.stop="removeLine($index)">删除</el-button></template>
          </el-table-column>
        </el-table>
      </div>
    </section>

    <div v-if="form.items.length" class="detail-layout">

      <aside class="form-card document-summary">
        <div class="summary-block">
          <div class="card-head-title"><span class="bar-accent"></span><h3>{{ isReplacement ? ($can('inventory.amount.view') ? '换货结算与库存成本' : '换货结算') : '单据金额汇总' }}</h3></div>
          <dl v-if="isReplacement"><dt>数量汇总</dt><dd class="green-text">{{ quantitySummary }}</dd><template v-if="$can('inventory.amount.view')"><dt>入库成本金额</dt><dd>¥{{ money(untaxedAmount) }}</dd></template><dt>本次新增应付</dt><dd class="no-payable">¥0.00</dd><dt>原采购合同金额</dt><dd>不变</dd></dl>
          <dl v-else><dt>数量汇总</dt><dd class="green-text">{{ quantitySummary }}</dd><dt>未税金额</dt><dd>¥{{ money(untaxedAmount) }}</dd><dt>税额</dt><dd>¥{{ money(taxAmount) }}</dd><dt>含税金额</dt><dd class="grand-total">¥{{ money(totalAmount) }}</dd></dl>
        </div>
        <div v-if="isEdit" class="summary-block">
          <div class="card-head-title"><span class="bar-accent"></span><h3>验收结算口径</h3></div>
          <dl><dt>合格暂估应付</dt><dd>¥{{ money(form.qualified_payable_amount) }}</dd><dt>质量冻结</dt><dd>¥{{ money(form.quality_hold_amount) }}</dd><dt>拒付 / 索赔</dt><dd>¥{{ money(form.rejected_claim_amount) }}</dd></dl>
        </div>
        <div v-if="activeLine" class="summary-block">
          <div class="card-head-title"><span class="bar-accent"></span><h3>校验状态</h3></div>
          <p :class="['validation-state',{invalid:!activeLineValid}]"><i :class="activeLineValid?'el-icon-circle-check':'el-icon-warning-outline'" /> {{ activeLineValid ? '当前行信息完整，可保存。' : '当前行仍有必填项未完成。' }}</p>
        </div>
      </aside>
    </div>

    <purchase-attachment-panel
      class="receipt-attachment-panel"
      title="到货附件"
      document-type="receipt"
      :document-id="$route.params.id || null"
      :draft-token="attachmentDraftToken"
      :initial-attachments="form.attachments || []"
    />

    <purchase-item-picker ref="itemPicker" @select="applyPickedItem" @select-multiple="applyPickedMultipleItems" />

    <el-dialog title="入库库位分配" :visible.sync="allocationDialog.visible" width="820px" :close-on-click-modal="false" append-to-body>
      <div v-if="allocationDialog.line" class="allocation-dialog-body">
        <el-alert :closable="false" type="info" show-icon :title="`同一批次可拆分到多个库位；已分配基本数量必须等于合格基本数量 ${number(qualifiedBaseQty(allocationDialog.line))}。`" />
        <div class="allocation-toolbar"><strong>{{ activeAllocationItemName }}</strong><el-button size="mini" type="success" icon="el-icon-plus" @click="addAllocationRow">添加库位</el-button></div>
        <el-table :data="allocationDialog.rows" size="mini" border>
          <el-table-column type="index" label="序号" width="52" align="center" />
          <el-table-column label="仓库" width="132"><template slot-scope="{row}"><paged-record-select v-model="row.warehouse_id" size="small" placeholder="选择仓库" @change="row.location_id=null" entity="warehouses" :params="{ management_scope: form.management_scope, statuses: ['enabled', 'active'] }" :selected-rows.sync="warehouses" title="选择仓库" /></template></el-table-column>
          <el-table-column label="库位" width="142"><template slot-scope="{row}"><paged-record-select v-model="row.location_id" size="small" placeholder="选择库位" :disabled="!row.warehouse_id" entity="locations" :params="{ management_scope: form.management_scope, warehouse_id: row.warehouse_id, statuses: ['enabled', 'active'] }" :selected-rows.sync="locations" title="选择库位" /></template></el-table-column>
          <el-table-column label="基本数量" width="105"><template slot-scope="{row}"><el-input-number v-model="row.base_qty" size="small" :min="0" :precision="allocationPrecision" :controls="false" :disabled="allocationUsesSerials" /></template></el-table-column>
          <el-table-column v-if="allocationUsesSerials" label="设备编号 / 序列号" min-width="235"><template slot-scope="{row}"><el-select v-model="row.serial_nos" size="small" multiple filterable collapse-tags placeholder="分配已录入编号" @change="row.base_qty=row.serial_nos.length"><el-option v-for="entry in allocationSerialOptions" :key="entry.serial_no" :value="entry.serial_no" :label="entry.serial_no" :disabled="serialAssignedElsewhere(entry.serial_no,row)" /></el-select></template></el-table-column>
          <el-table-column label="操作" width="55" align="center"><template slot-scope="{$index}"><el-button type="text" class="danger-link" @click="removeAllocationRow($index)">删除</el-button></template></el-table-column>
        </el-table>
        <div :class="['allocation-total',{invalid:!allocationDialogValid}]"><span>已分配 {{ number(allocationDialogTotal) }} / {{ number(qualifiedBaseQty(allocationDialog.line)) }}</span><span v-if="allocationUsesSerials">已分配编号 {{ allocationAssignedSerialCount }} / {{ allocationSerialOptions.length }}</span><span v-else-if="isSerialManaged(allocationDialog.line)">编号生成/扫码后按配额自动落位</span><strong>{{ allocationDialogValid ? '分配完整' : '分配未完成' }}</strong></div>
      </div>
      <span slot="footer"><el-button size="small" @click="allocationDialog.visible=false">取消</el-button><el-button size="small" type="success" @click="confirmAllocationDialog">确认分配</el-button></span>
    </el-dialog>

    <footer class="bottom-actions">
      <div class="bottom-actions-left">
        <span class="doc-no-pill">{{ form.receipt_no || '系统预占生成' }}</span>
        <span class="bottom-summary-meta">共 {{ form.items.length }} 行物料<template v-if="!isReplacement && totalAmount != null"> · 含税总额 <strong class="green-text">¥{{ money(totalAmount) }}</strong></template></span>
      </div>
      <div class="bottom-actions-right">
        <el-button size="small" @click="goBack">返回列表</el-button>
        <el-button size="small" :loading="saving" :disabled="!!scopeIssue || scopeChanging" @click="save(true)">保存草稿</el-button>
        <el-button size="small" type="success" icon="el-icon-check" :loading="saving" :disabled="!!scopeIssue || scopeChanging" @click="save(false)">保存</el-button>
      </div>
    </footer>
  </section>
</template>

<script>
import PagedRecordSelect from '@/components/common/PagedRecordSelect.vue'
import { listEntity, listItemPurchaseConversionOptions } from '@/api/erp/master'
import { generateReceiptSerials, getPurchase, savePurchaseReceipt } from '@/api/erp/purchase'
import { reserveForCreatePage, clearCreatePageReservation } from '@/utils/documentNumberReservation'
import PurchaseItemPicker from '@/components/purchase/PurchaseItemPicker.vue'
import PurchaseAttachmentPanel from '@/components/purchase/PurchaseAttachmentPanel.vue'
import { purchaseScopes, purchaseScopeLabel, purchaseScopeIssue, purchaseSourceLocked, purchaseScopeMatches, validPurchaseScope } from '@/utils/purchaseManagementScope.mjs'
import ReceiptPhysicalEntries from '@/components/purchase/ReceiptPhysicalEntries.vue'
import { roundInvoiceAmount } from '@/utils/financeInvoiceAmount'

export default {
  components: { PagedRecordSelect, PurchaseItemPicker, PurchaseAttachmentPanel, ReceiptPhysicalEntries },
  data: () => ({
    form: { management_scope: 'factory', receipt_no: '', supplier_id: null, receipt_date: '', confirm_status: 'draft', stock_post_status: 'pending', remark: '', items: [] },
    items: [], suppliers: [], warehouses: [], locations: [], activeIndex: 0, reservation: null, saving: false, pickerTarget: null, attachmentDraftToken: '',
    documentRevision: 0, warehouseRevision: 0, scopeChanging: false,
    defaultWarehouseId: null,
    defaultLocationId: null,
    allocationDialog: { visible: false, line: null, rows: [] }
  }),
  computed: {
    flowSteps() {
      return [
        { key: 'request', label: '采购需求' },
        { key: 'plan', label: '采购计划' },
        { key: 'order', label: '采购订单' },
        { key: 'receipt', label: '到货验收' }
      ]
    },
    confirmStatusTag() {
      const s = this.form.confirm_status || this.form.receipt_status || 'draft'
      return s === 'confirmed' ? 'success' : s === 'cancelled' ? 'danger' : 'info'
    },
    postingStatusTag() {
      const s = this.form.stock_post_status || 'pending'
      return s === 'posted' ? 'success' : s === 'reversed' ? 'danger' : s === 'not_required' ? 'info' : 'warning'
    },
    scopeOptions() { return purchaseScopes },
    scopeIssue() { return purchaseScopeIssue(this.form, this.items) },
    scopeLocked() { return this.scopeChanging || this.saving || purchaseSourceLocked(this.form) },
    scopedWarehouses() { return this.warehouses.filter(row => purchaseScopeMatches(row, this.form.management_scope)) },
    isEdit() { return Boolean(this.$route.params.id) },
    isReplacement() { return this.form.settlement_mode === 'replacement_no_charge' },
    activeLine() { return this.form.items[this.activeIndex] || null },
    activeAllocationItemName() { return this.allocationDialog.line ? `${this.itemById(this.allocationDialog.line.item_id).item_code || ''} ${this.itemById(this.allocationDialog.line.item_id).item_name || ''}`.trim() : '' },
    allocationUsesSerials() { return Boolean(this.allocationDialog.line && this.isSerialManaged(this.allocationDialog.line) && this.serialNumberList(this.allocationDialog.line).length) },
    allocationSerialOptions() { return this.allocationDialog.line ? this.serialNumberList(this.allocationDialog.line) : [] },
    allocationDialogTotal() { return this.allocationDialog.rows.reduce((sum, row) => sum + Number(row.base_qty || 0), 0) },
    allocationAssignedSerialCount() { return new Set(this.allocationDialog.rows.flatMap(row => row.serial_nos || [])).size },
    allocationPrecision() { return 6 },
    allocationDialogValid() {
      if (!this.allocationDialog.line || !this.allocationDialog.rows.length) return false
      if (this.allocationDialog.rows.some(row => !this.warehouseMatches(row.warehouse_id))) return false
      if (this.allocationDialog.rows.some(row => !row.warehouse_id || !row.location_id || Number(row.base_qty || 0) <= 0)) return false
      const locators = this.allocationDialog.rows.map(row => `${row.warehouse_id}-${row.location_id}`)
      if (new Set(locators).size !== locators.length || Math.abs(this.allocationDialogTotal - this.qualifiedBaseQty(this.allocationDialog.line)) > 0.000001) return false
      return !this.allocationUsesSerials || this.allocationAssignedSerialCount === this.allocationSerialOptions.length
    },
    validSuppliers() { return this.suppliers.filter(row => row.supplier_name && row.status === 'enabled' && (row.approval_status || 'approved') === 'approved' && !row.is_blacklisted && !row.purchase_restricted) },
    confirmStatusText() { return ({ draft: '草稿', confirmed: '已确认', cancelled: '已取消' })[this.form.confirm_status || this.form.receipt_status] || '草稿' },
    postingStatusText() { return ({ pending: '待过账', posted: '已过账', reversed: '已冲销', not_required: '无需过账' })[this.form.stock_post_status] || '待过账' },
    quantitySummary() {
      const groups = new Map()
      this.form.items.forEach(line => { if (!line.item_id || !Number(line.qty)) return; const unit = this.purchaseUnitName(line); groups.set(unit, Number(groups.get(unit) || 0) + Number(line.qty)) })
      return groups.size ? [...groups.entries()].map(([unit, qty]) => `${this.number(qty)} ${unit}`).join('；') : '0'
    },
    taxMode() { return this.form.order?.tax_mode || this.form.tax_mode_snapshot || 'tax_included' },
    untaxedAmount() { if (this.form.items.some(line => this.lineAmount(line) == null)) return null; return this.form.items.reduce((sum, line) => sum + this.receiptAmounts(line).excl, 0) },
    taxAmount() { if (this.untaxedAmount === null) return null; return this.form.items.reduce((sum, line) => sum + this.receiptAmounts(line).tax, 0) },
    totalAmount() { if (this.untaxedAmount === null) return null; return this.form.items.reduce((sum, line) => sum + this.receiptAmounts(line).incl, 0) },
    activeLineValid() {
      const line = this.activeLine
      if (!line || !line.item_id || !line.purchase_unit_id || !Number(line.qty) || !this.lineAllocationValid(line) || !this.physicalEntriesValid(line)) return false
      if (Math.abs(Number(line.qualified_qty || 0) + Number(line.unqualified_qty || 0) - Number(line.qty || 0)) > 0.000001) return false
      if (this.hasDifference(line) && !String(line.difference_reason || '').trim()) return false
      return !this.isSerialManaged(line) || this.serialTrackingMode(line) === 'optional' || this.serialNumberList(line).length === this.serialRequiredCount(line)
    }
  },
  watch: {
    '$route.fullPath'(next, previous) { if (next !== previous) this.initializeDocument() },
    activeIndex(index) {
      if (index >= 0) this.$nextTick(() => this.$refs.lineEditor?.scrollIntoView({ block: 'nearest' }))
    }
  },
  async mounted() { await this.initializeDocument() },
  methods: {
    purchaseConversions(line, params) { return listItemPurchaseConversionOptions(line.item_id, params) },
    localConversionOptions(line) { return (line._conversionOptions || []).filter(row => row.identity_conversion || row.snapshot_option) },
    scopeLabel(scope) { return purchaseScopeLabel(scope) },
    warehouseMatches(id) { return this.scopedWarehouses.some(row => Number(row.id) === Number(id)) },
    async loadScopedWarehouses() {
      const revision = ++this.warehouseRevision
      const scope = this.form.management_scope
      this.warehouses = []
      this.locations = []
      if (!validPurchaseScope(scope)) return
      const rows = (this.form.items || []).flatMap(line => [line, ...(line._allocations || line.allocations || [])])
      const warehouseIds = [...new Set(rows.map(row => row.warehouse_id).filter(Boolean))]
      const locationIds = [...new Set(rows.map(row => row.location_id).filter(Boolean))]
      try {
        const [warehouses, locations] = await Promise.all([
          warehouseIds.length ? listEntity('warehouses', { management_scope: scope, statuses: ['enabled', 'active'], ids: warehouseIds, per_page: warehouseIds.length }) : null,
          locationIds.length ? listEntity('locations', { management_scope: scope, statuses: ['enabled', 'active'], ids: locationIds, per_page: locationIds.length }) : null
        ])
        if (revision !== this.warehouseRevision || scope !== this.form.management_scope) return
        this.warehouses = warehouses?.data.data || []
        this.locations = locations?.data.data || []
      } catch (error) { if (revision === this.warehouseRevision) this.$message.error(error.userMessage || '仓库库位加载失败') }
    },
    async changeManagementScope(scope) {
      if (scope === this.form.management_scope || !validPurchaseScope(scope) || this.scopeLocked) return
      this.scopeChanging = true
      const document = this.form
      try {
        if (document.items.some(row => row.item_id || row.warehouse_id || (row._allocations || []).length)) await this.$confirm('切换管理类型将清空全部到货明细、仓库、库位及编号，是否继续？', '切换管理类型', { type: 'warning', confirmButtonText: '清空并切换' })
        if (this.form !== document) return
        this.documentRevision++
        this.pickerTarget = null
        if (this.$refs.itemPicker) this.$refs.itemPicker.visible = false
        this.allocationDialog = { visible: false, line: null, rows: [] }
        this.defaultWarehouseId = null
        this.defaultLocationId = null
        this.$set(document, 'management_scope', scope)
        this.$set(document, 'items', [this.blankLine()])
        this.activeIndex = 0
        await this.loadScopedWarehouses()
      } catch (error) { if (error !== 'cancel' && error !== 'close') this.$message.error(error.userMessage || '管理类型切换失败') }
      finally { this.scopeChanging = false }
    },
    blankLine() { return { item_id: null, qty: 1, purchase_unit_id: null, qualified_qty: 1, unqualified_qty: 0, actual_base_qty: 0, unit_price: 0, tax_rate: 13, difference_reason: '', batch_no: '', stock_length_mm: '', expected_arrival_date: this.form.receipt_date, warehouse_id: this.defaultWarehouseId || null, location_id: this.defaultLocationId || null, remark: '', serial_text: '', serial_number_source: 'supplier', _conversionOptions: [], _serialEntries: [], _allocations: [], _physicalEntries: [], _scanInput: '', _serialError: '' } },
    async initializeDocument() {
      const revision = ++this.documentRevision
      this.warehouseRevision++
      this.pickerTarget = null
      this.items = []
      this.warehouses = []
      this.defaultWarehouseId = null
      this.defaultLocationId = null
      this.allocationDialog = { visible: false, line: null, rows: [] }
      if (this.$refs.itemPicker) this.$refs.itemPicker.visible = false
      this.activeIndex = 0
      this.reservation = null
      if (this.isEdit) { await this.loadExisting(); if (revision === this.documentRevision) await this.loadScopedWarehouses(); return }
      this.attachmentDraftToken = this.newDraftToken()
      const today = new Date().toISOString().slice(0, 10)
      this.form = { management_scope: validPurchaseScope(this.$route.query?.management_scope) ? this.$route.query.management_scope : 'factory', receipt_no: '', supplier_id: null, receipt_date: today, confirm_status: 'draft', stock_post_status: 'pending', remark: '', items: [] }
      this.form.items.push(this.blankLine())
      try {
        const reservation = await reserveForCreatePage('purchase_receipt', this.$route.path)
        if (revision !== this.documentRevision) return
        this.reservation = reservation
        this.form.receipt_no = reservation.document_no
      } catch (error) { this.$message.error(error.userMessage || '单据编号预生成失败') }
      if (revision === this.documentRevision) await this.loadScopedWarehouses()
    },
    async loadExisting() {
      const revision = this.documentRevision
      try {
        const response = await getPurchase('receipts', this.$route.params.id)
        if (revision !== this.documentRevision) return
        const data = response.data
        if (data.supplier) this.suppliers = [data.supplier]
        this.attachmentDraftToken = ''
        ;(data.items || []).forEach(line => this.rememberItem(line.item))
        this.form = { ...data, items: (data.items || []).map(this.normalizeLine) }
        await Promise.all(this.form.items.map(line => this.loadLineConversions(line, false)))
      } catch (error) {
        this.$message.error(error.userMessage || '到货单加载失败，请返回列表后重试')
      }
    },
    normalizeLine(line) {
      const entries = Array.isArray(line.serial_entries) && line.serial_entries.length ? line.serial_entries : String(line.serial_text || '').split(/\r?\n|,|，/).map(value => value.trim()).filter(Boolean).map(serial_no => ({ serial_no, source: line.serial_number_source || 'supplier' }))
      const allocations = (line.allocations || []).map(row => ({ warehouse_id: row.warehouse_id, location_id: row.location_id, base_qty: Number(row.base_qty || 0), serial_nos: [...(row.serial_nos || [])], physical_entries: (row.physical_entries || []).map(entry => ({ dimensions: { ...entry.dimensions } })) }))
      const qty = Number(line.receipt_qty || line.qty || 0)
      const price = qty > 0 && line.receipt_cost != null ? Number((Number(line.receipt_cost) / qty).toFixed(8)) : line.unit_price == null ? undefined : Number(line.unit_price)
      return { ...line, qty, qualified_qty: Number(line.qualified_qty || 0), unqualified_qty: Number(line.unqualified_qty || 0), unit_price: price, _receiptSavedPrice: price, tax_rate: Number(line.tax_rate == null ? 13 : line.tax_rate), actual_base_qty: line.actual_base_qty == null ? null : Number(line.actual_base_qty), _conversionOptions: [], _serialEntries: entries, _allocations: allocations, _scanInput: '', _serialError: '' }
    },
    addLine() { this.form.items.push(this.blankLine()); this.activeIndex = this.form.items.length - 1 },
    removeActiveLine() { if (this.activeLine) this.removeLine(this.activeIndex) },
    removeLine(index) { if (this.form.items.length === 1) return this.$message.warning('到货单至少保留一行明细'); this.form.items.splice(index, 1); this.activeIndex = index < this.activeIndex ? this.activeIndex - 1 : Math.min(this.activeIndex, this.form.items.length - 1) },
    selectLine(row) { this.activeIndex = row === this.activeLine ? -1 : this.form.items.indexOf(row) },
    onExpandChange(row, expandedRows) { this.activeIndex = expandedRows.includes(row) ? this.form.items.indexOf(row) : -1 },
    lineRowClass({ rowIndex }) { return rowIndex === this.activeIndex ? 'selected-receipt-row' : '' },
    openItemPicker(line) {
      if (!validPurchaseScope(this.form.management_scope)) return this.$message.warning('请先明确单据管理类型')
      if (this.isReplacement) return this.$message.info('换货补发到货单的来源物料已锁定')
      this.activeIndex = this.form.items.indexOf(line)
      this.pickerTarget = line
      this.$refs.itemPicker.open({ currentId: line && line.item_id, multiple: false, params: { status: 'enabled', is_purchase_item: 1, management_scope: this.form.management_scope } })
    },
    openBatchItemPicker() {
      if (!validPurchaseScope(this.form.management_scope)) return this.$message.warning('请先明确单据管理类型')
      if (this.isReplacement) return this.$message.info('换货补发到货单的来源物料已锁定')
      this.pickerTarget = null
      const selected = (this.form.items || []).filter(row => row.item_id).map(row => this.itemById(row.item_id)).filter(row => row && row.id)
      this.$refs.itemPicker.open({
        multiple: true,
        selected,
        title: '选择到货物料 (支持多选)',
        params: { status: 'enabled', is_purchase_item: 1, management_scope: this.form.management_scope }
      })
    },
    async applyPickedMultipleItems(items) {
      if (!items || !items.length || this.isReplacement) return
      if (!validPurchaseScope(this.form.management_scope) || items.some(item => !purchaseScopeMatches(item, this.form.management_scope))) {
        return this.$message.warning('请选择与到货单管理类型一致的物料')
      }
      const existingItemIds = new Set(this.form.items.filter(row => row.item_id).map(row => Number(row.item_id)))
      const newItems = items.filter(item => !existingItemIds.has(Number(item.id)))
      if (!newItems.length) {
        return this.$message.info('所选物料均已在到货明细中，未添加重复行')
      }
      const touchedLines = []
      newItems.forEach(item => {
        this.rememberItem(item)
        const emptyRow = this.form.items.find(row => !row.item_id)
        if (emptyRow) {
          ;['management_scope_snapshot', 'is_stock_item_snapshot', 'purchase_conversion_snapshot', 'conversion_factor_snapshot', 'purchase_unit_name_snapshot', 'base_unit_name_snapshot'].forEach(k => this.$delete(emptyRow, k))
          this.$set(emptyRow, 'item_id', item.id)
          this.$set(emptyRow, 'purchase_unit_id', null)
          this.$set(emptyRow, '_conversionOptions', [])
          this.$set(emptyRow, '_physicalEntries', [])
          this.$set(emptyRow, '_serialEntries', [])
          this.$set(emptyRow, '_allocations', [])
          this.$set(emptyRow, '_scanInput', '')
          this.$set(emptyRow, '_serialError', '')
          this.$set(emptyRow, 'serial_text', '')
          this.$set(emptyRow, 'serial_number_source', 'supplier')
          if (!emptyRow.warehouse_id && this.defaultWarehouseId) this.$set(emptyRow, 'warehouse_id', this.defaultWarehouseId)
          if (!emptyRow.location_id && this.defaultLocationId) this.$set(emptyRow, 'location_id', this.defaultLocationId)
          touchedLines.push(emptyRow)
        } else {
          const line = { ...this.blankLine(), item_id: item.id }
          this.form.items.push(line)
          touchedLines.push(line)
        }
      })
      await Promise.all(touchedLines.map(line => this.onItemChange(line)))
      if (touchedLines.length) {
        const firstIdx = this.form.items.indexOf(touchedLines[0])
        if (firstIdx >= 0) this.activeIndex = firstIdx
      }
      this.$message.success(`已添加 ${newItems.length} 个到货物料明细`)
    },
    onHeaderDefaultWarehouseChange(warehouseId) {
      this.defaultLocationId = null
      if (!warehouseId || this.isReplacement) return
      let filled = 0
      this.form.items.forEach(line => {
        if (!this.isStockManaged(line) || this.qualifiedBaseQty(line) <= 0 || this.allocations(line).length > 1) return
        if (!line.warehouse_id) {
          this.$set(line, 'warehouse_id', warehouseId)
          this.$set(line, 'location_id', null)
          this.resetSingleAllocation(line)
          filled++
        }
      })
      if (filled > 0) this.$message.success(`已为 ${filled} 行空白仓库明细填入默认入库仓库`)
    },
    onHeaderDefaultLocationChange(locationId) {
      if (!locationId || !this.defaultWarehouseId || this.isReplacement) return
      let filled = 0
      this.form.items.forEach(line => {
        if (!this.isStockManaged(line) || this.qualifiedBaseQty(line) <= 0 || this.allocations(line).length > 1) return
        if ((!line.warehouse_id || Number(line.warehouse_id) === Number(this.defaultWarehouseId)) && !line.location_id) {
          this.$set(line, 'warehouse_id', this.defaultWarehouseId)
          this.$set(line, 'location_id', locationId)
          this.resetSingleAllocation(line)
          filled++
        }
      })
      if (filled > 0) this.$message.success(`已为 ${filled} 行空白库位明细填入默认库位`)
    },
    syncDefaultLocatorToLines() {
      if (this.isReplacement) return
      if (!this.defaultWarehouseId) return this.$message.warning('请先在基础信息中选择默认入库仓库')
      if (this.warehouses.some(r => Number(r.id) === Number(this.defaultWarehouseId)) && !this.warehouseMatches(this.defaultWarehouseId)) {
        return this.$message.warning('默认仓库与当前单据管理类型不一致，请重新选择')
      }
      let updated = 0
      let skippedMulti = 0
      this.form.items.forEach(line => {
        if (!this.isStockManaged(line) || this.qualifiedBaseQty(line) <= 0) return
        if (this.allocations(line).length > 1) {
          skippedMulti++
          return
        }
        this.$set(line, 'warehouse_id', this.defaultWarehouseId)
        this.$set(line, 'location_id', this.defaultLocationId || null)
        this.resetSingleAllocation(line)
        updated++
      })
      if (updated === 0 && skippedMulti > 0) {
        return this.$message.warning(`全部 ${skippedMulti} 行库存明细均已拆分多库位，为保留多库位分摊未作覆盖`)
      }
      if (updated === 0) {
        return this.$message.warning('当前没有需要入库的库存明细行')
      }
      const baseMsg = `已同步默认入库${this.defaultLocationId ? '仓库/库位' : '仓库'}到 ${updated} 行明细`
      this.$message.success(skippedMulti > 0 ? `${baseMsg}（已跳过 ${skippedMulti} 行多库位拆分明细）` : baseMsg)
    },
    async applyPickedItem(item) {
      const line = this.pickerTarget
      if (!line || !item || !this.form.items.includes(line)) return
      if (!purchaseScopeMatches(item, this.form.management_scope)) return this.$message.warning('请选择与到货单管理类型一致的物料')
      const changed = Number(line.item_id || 0) !== Number(item.id)
      this.rememberItem(item)
      this.$set(line, 'item_id', item.id)
      if (changed) {
        this.$delete(line, 'management_scope_snapshot')
        this.$delete(line, 'is_stock_item_snapshot')
        this.$delete(line, 'purchase_conversion_snapshot')
        this.$delete(line, 'conversion_factor_snapshot')
        this.$delete(line, 'purchase_unit_name_snapshot')
        this.$delete(line, 'base_unit_name_snapshot')
        this.$set(line, 'warehouse_id', this.defaultWarehouseId || null)
        this.$set(line, 'location_id', this.defaultLocationId || null)
        this.$set(line, 'purchase_unit_id', null)
        this.$set(line, '_conversionOptions', [])
        this.$set(line, '_physicalEntries', [])
        this.$set(line, '_serialEntries', [])
        this.$set(line, '_allocations', [])
        this.$set(line, '_scanInput', '')
        this.$set(line, '_serialError', '')
        this.$set(line, 'serial_text', '')
        this.$set(line, 'serial_number_source', 'supplier')
        await this.onItemChange(line)
      }
      this.pickerTarget = null
    },
    rememberItem(item) {
      if (!item || !item.id) return
      const index = this.items.findIndex(row => Number(row.id) === Number(item.id))
      if (index >= 0) this.$set(this.items, index, item)
      else this.items.push(item)
    },
    async onItemChange(line) { line._serialEntries = []; line.serial_text = ''; await this.loadLineConversions(line, true) },
    async loadLineConversions(line, chooseDefault = true) {
      if (!line.item_id) return this.$set(line, '_conversionOptions', [])
      const revision = this.documentRevision
      const itemId = line.item_id
      const { data } = await listItemPurchaseConversionOptions(itemId, { page: 1, per_page: 10, ids: !chooseDefault && line.purchase_unit_id ? [line.purchase_unit_id] : undefined })
      if (revision !== this.documentRevision || line.item_id !== itemId || !this.form.items.includes(line)) return
      const item = this.itemById(line.item_id)
      const itemUnit = item && (item.unit?.standard_unit || item.unit?.standardUnit || item.unit)
      const options = [...(data.data || [])]
      if (itemUnit && !options.some(row => Number(row.purchase_unit_id) === Number(itemUnit.id))) options.unshift({ purchase_unit_id: itemUnit.id, base_unit_id: itemUnit.id, factor: 1, is_default: options.length === 0, allow_actual_conversion: false, purchase_unit: itemUnit, base_unit: itemUnit, identity_conversion: true })
      this.$set(line, '_conversionOptions', options)
      if (chooseDefault || !line.purchase_unit_id) { const selected = options.find(row => row.is_default) || options[0]; if (selected) this.$set(line, 'purchase_unit_id', selected.purchase_unit_id) }
      this.applyConversion(line)
    },
    selectedConversion(line) { return (line._conversionOptions || []).find(row => Number(row.purchase_unit_id) === Number(line.purchase_unit_id)) },
    applyConversion(line) {
      const conversion = this.selectedConversion(line)
      if (!conversion) return
      this.$set(line, 'conversion_factor_preview', Number(conversion.factor || 1))
      this.$set(line, 'purchase_unit_name_preview', conversion.purchase_unit && conversion.purchase_unit.unit_name)
      this.$set(line, 'base_unit_name_preview', conversion.base_unit && conversion.base_unit.unit_name)
      this.$set(line, 'allow_actual_conversion_preview', Boolean(conversion.allow_actual_conversion))
      if (!this.actualConversionAllowed(line)) this.$set(line, 'actual_base_qty', this.standardBaseQtyNumber(line))
    },
    syncReceiptActualQty(line) { if (!this.actualConversionAllowed(line)) this.$set(line, 'actual_base_qty', this.standardBaseQtyNumber(line)) },
    purchaseOptionLabel(option) { const unit = option.purchase_unit || {}; return `${unit.unit_name || unit.symbol || '-'}（1 = ${this.number(option.factor || 1)} ${option.base_unit?.unit_name || '基本单位'}）` },
    purchaseUnitName(line) { const conversion = this.selectedConversion(line); return line.purchase_unit_name_snapshot || line.purchase_unit_name_preview || conversion?.purchase_unit?.unit_name || '-' },
    conversionFactor(line) { return this.number(line.conversion_factor_snapshot || line.conversion_factor_preview || 0) },
    standardBaseQtyNumber(line) { const factor = Number(line.conversion_factor_snapshot || line.conversion_factor_preview || 0); return Number(line.qty || 0) * factor },
    standardBaseQty(line) { return `${this.number(this.standardBaseQtyNumber(line))} ${line.base_unit_name_snapshot || line.base_unit_name_preview || ''}`.trim() },
    actualConversionAllowed(line) { return Boolean(line.allow_actual_conversion || line.allow_actual_conversion_preview || line.allow_actual_conversion_snapshot) },
    differenceQtyNumber(line) { return Number(line.actual_base_qty == null ? this.standardBaseQtyNumber(line) : line.actual_base_qty) - this.standardBaseQtyNumber(line) },
    differenceQty(line) { return `${this.number(this.differenceQtyNumber(line))} ${line.base_unit_name_snapshot || line.base_unit_name_preview || ''}`.trim() },
    hasDifference(line) { return Math.abs(this.differenceQtyNumber(line)) > 0.000001 },
    filteredLocations(warehouseId) { return warehouseId ? this.locations.filter(row => Number(row.warehouse_id) === Number(warehouseId)) : [] },
    itemById(id) { return this.items.find(row => Number(row.id) === Number(id)) || {} },
    isStockManaged(line) { const item = this.itemById(line && line.item_id); return line && line.is_stock_item_snapshot != null ? Boolean(line.is_stock_item_snapshot) : Boolean(item.is_stock_item) },
    warehouseName(id) { return this.warehouses.find(row => Number(row.id) === Number(id))?.warehouse_name || '-' },
    locationName(id) { return this.locations.find(row => Number(row.id) === Number(id))?.location_name || '-' },
    // 合格 + 不合格 必须等于到货数量：改其中一个时自动算出另一个，避免用户手工凑数。
    syncQualityQty(line, changed) {
      const received = Number(line.qty || 0)
      const value = Math.min(Math.max(Number(line[changed] || 0), 0), received)
      line[changed] = value
      const other = changed === 'qualified_qty' ? 'unqualified_qty' : 'qualified_qty'
      line[other] = Math.round((received - value) * 1000000) / 1000000
    },
    qualifiedBaseQty(line) { const received = Number(line.qty || 0); const actual = Number(line.actual_base_qty == null ? this.standardBaseQtyNumber(line) : line.actual_base_qty); return received > 0 ? actual * Number(line.qualified_qty || 0) / received : 0 },
    allocations(line) { return Array.isArray(line._allocations) ? line._allocations : [] },
    // 管材按根入库：每行记每根的实际长度，库存和开料按它算米数。
    isPipeLine(line) {
      const item = this.itemById(line && line.item_id)
      if (!this.isStockManaged(line) || item.material_management_mode === 'physical' || !(item.cutting_mode === 'length' || (!item.cutting_mode && item.is_length_cut_material))) return false
      const unit = item.unit || {}
      return String(unit.unit_code || '').toUpperCase() !== 'M' && String(unit.symbol || unit.unit_symbol || '').toLowerCase() !== 'm'
    },
    pipeStandardLength(line) { const value = Number(this.itemById(line && line.item_id).standard_stock_length_mm || 0); return value > 0 ? Number(value.toFixed(2)) : 0 },
    pipeLength(line) { const value = Number(line && line.stock_length_mm); return value > 0 ? Number(value.toFixed(2)) : this.pipeStandardLength(line) },
    pipeTotalMeters(line) { return `${Number((this.pipeLength(line) * this.qualifiedBaseQty(line) / 1000).toFixed(3))} 米` },
    isPhysicalManaged(line) { const item = this.itemById(line.item_id); return this.isStockManaged(line) && item.material_management_mode === 'physical' && ['sheet', 'length'].includes(item.cutting_mode) },
    physicalMeasureUnit(line) {
      const item = this.itemById(line.item_id)
      const selected = this.selectedConversion(line)?.base_unit || item.unit || {}
      const unit = selected.standard_unit || selected.standardUnit || selected
      const code = String(unit.unit_code || '').toUpperCase()
      const symbol = String(unit.symbol || unit.unit_symbol || '').toLowerCase()
      return ['M2', 'SQM'].includes(code) || ['m2', 'm²', '㎡'].includes(symbol) ? 'm²' : code === 'M' || symbol === 'm' ? 'm' : ''
    },
    physicalPurchaseCount(line) {
      const selected = this.selectedConversion(line)?.purchase_unit || {}
      const unit = selected.standard_unit || selected.standardUnit || selected
      const code = String(unit.unit_code || '').toUpperCase()
      const name = unit.unit_name || line.purchase_unit_name_snapshot || line.purchase_unit_name_preview
      return ['SHEET', 'ROOT'].includes(code) || ['张', '根'].includes(name) ? Number(line.qualified_qty || 0) : null
    },
    physicalInventoryQty(line, entries) {
      if (!this.physicalMeasureUnit(line)) return entries.length
      const length = this.itemById(line.item_id).cutting_mode === 'length'
      return entries.reduce((sum, entry) => sum + Number(entry.dimensions?.length_mm || 0) * (length ? 1 / 1000 : Number(entry.dimensions?.width_mm || 0) / 1000000), 0)
    },
    physicalEntryCount(line) { return this.physicalGroups(line).reduce((sum, group) => sum + (group.physical_entries || []).length, 0) },
    physicalGroups(line) { return this.allocations(line).length ? this.allocations(line) : [{ warehouse_id: line.warehouse_id, location_id: line.location_id, base_qty: this.qualifiedBaseQty(line), physical_entries: line._physicalEntries || [] }] },
    setPhysicalEntries(line, index, entries) { if (this.allocations(line).length) this.$set(line._allocations[index], 'physical_entries', entries); else this.$set(line, '_physicalEntries', entries) },
    physicalEntriesValid(line) {
      if (!this.isPhysicalManaged(line)) return true
      const valid = value => /^\d+(\.\d{1,2})?$/.test(String(value)) && Number(value) > 0
      // 采购按张／根核对件数，库存另按实测面积／长度核对，不能把平方米或米当成件数。
      const count = this.physicalPurchaseCount(line)
      if (count !== null && (!Number.isInteger(count) || this.physicalEntryCount(line) !== count)) return false
      const length = this.itemById(line.item_id).cutting_mode === 'length'
      return this.physicalGroups(line).every(group => Math.abs(this.physicalInventoryQty(line, group.physical_entries || []) - Number(group.base_qty)) < 0.000001 && (group.physical_entries || []).every(entry => {
        const dims = entry.dimensions || {}
        return (length ? ['length_mm'] : ['length_mm', 'width_mm', 'thickness_mm']).every(key => valid(dims[key])) && (length || !dims.nominal_thickness_mm || valid(dims.nominal_thickness_mm))
      }))
    },
    allocationSummary(line) {
      const rows = this.allocations(line)
      if (!rows.length) return `${this.warehouseName(line.warehouse_id)} / ${this.locationName(line.location_id)}`
      if (rows.length === 1) return `${this.warehouseName(rows[0].warehouse_id)} / ${this.locationName(rows[0].location_id)}`
      return `${this.warehouseName(rows[0].warehouse_id)} · ${rows.length} 个库位`
    },
    allocationProgressText(line) { const rows = this.allocations(line); if (this.qualifiedBaseQty(line) <= 0) return '全不合格，无需入库'; return rows.length ? `${rows.length} 个库位，${this.number(rows.reduce((sum,row)=>sum+Number(row.base_qty||0),0))} / ${this.number(this.qualifiedBaseQty(line))}` : '默认单库位' },
    lineAllocationValid(line) {
      if (!this.isStockManaged(line)) return true
      if (this.qualifiedBaseQty(line) <= 0) return true
      const rows = this.allocations(line)
      if (!rows.length) return Boolean(this.warehouseMatches(line.warehouse_id) && line.location_id)
      if (rows.some(row => !this.warehouseMatches(row.warehouse_id) || !row.location_id || Number(row.base_qty || 0) <= 0)) return false
      const locators = rows.map(row => `${row.warehouse_id}-${row.location_id}`)
      if (new Set(locators).size !== locators.length || Math.abs(rows.reduce((sum,row)=>sum+Number(row.base_qty||0),0)-this.qualifiedBaseQty(line))>0.000001) return false
      const serials = rows.flatMap(row => row.serial_nos || [])
      return !this.isSerialManaged(line) || !this.serialNumberList(line).length || (new Set(serials).size === this.serialNumberList(line).length && serials.length === this.serialNumberList(line).length)
    },
    resetSingleAllocation(line) {
      // A locator change must retain the entered dimensions of these same sheets.
      if (this.allocations(line).length <= 1) {
        if (this.allocations(line).length) this.$set(line, '_physicalEntries', line._allocations[0].physical_entries || [])
        this.$set(line, '_allocations', [])
      }
    },
    onDefaultWarehouseChange(line) { this.$set(line, 'location_id', null); this.resetSingleAllocation(line) },
    onDefaultLocationChange(line) { this.resetSingleAllocation(line) },
    openAllocationDialog(line) {
      if (!line.item_id) return this.$message.warning('请先选择物料')
      const existing = this.allocations(line)
      const rows = existing.length ? existing : [{ warehouse_id: line.warehouse_id || null, location_id: line.location_id || null, base_qty: this.qualifiedBaseQty(line), serial_nos: this.serialNumberList(line).map(entry => entry.serial_no), physical_entries: line._physicalEntries || [] }]
      this.allocationDialog = { visible: true, line, rows: rows.map(row => ({ warehouse_id: row.warehouse_id, location_id: row.location_id, base_qty: Number(row.base_qty || 0), serial_nos: [...(row.serial_nos || [])], physical_entries: (row.physical_entries || []).map(entry => ({ dimensions: { ...entry.dimensions } })) })) }
    },
    addAllocationRow() { this.allocationDialog.rows.push({ warehouse_id: null, location_id: null, base_qty: 0, serial_nos: [], physical_entries: [] }) },
    removeAllocationRow(index) { if (this.allocationDialog.rows.length === 1) return this.$message.warning('至少保留一个入库库位'); this.allocationDialog.rows.splice(index,1) },
    serialAssignedElsewhere(serialNo, currentRow) { return this.allocationDialog.rows.some(row => row !== currentRow && (row.serial_nos || []).includes(serialNo)) },
    confirmAllocationDialog() {
      if (!this.allocationDialogValid) return this.$message.error('库位分配数量、库位或设备编号尚未分配完整')
      const line = this.allocationDialog.line
      const rows = this.allocationDialog.rows.map(row => ({ warehouse_id: row.warehouse_id, location_id: row.location_id, base_qty: Number(row.base_qty), serial_nos: [...(row.serial_nos || [])], physical_entries: (row.physical_entries || []).map(entry => ({ dimensions: { ...entry.dimensions } })) }))
      this.$set(line, '_allocations', rows)
      this.$set(line, 'warehouse_id', rows[0].warehouse_id)
      this.$set(line, 'location_id', rows[0].location_id)
      if (this.serialNumberList(line).length) this.autoAssignSerialsToAllocations(line)
      this.allocationDialog.visible = false
      this.$message.success(`已分配到 ${rows.length} 个库位`)
    },
    serialTrackingMode(line) { if (!this.isStockManaged(line)) return 'none'; const item = this.itemById(line.item_id); return item.serial_tracking_mode || (item.is_serial_managed ? 'required' : 'none') },
    isSerialManaged(line) { return this.serialTrackingMode(line) !== 'none' },
    serialRequiredCount(line) { const received = Number(line.qty || 0); const actual = Number(line.actual_base_qty == null ? this.standardBaseQtyNumber(line) : line.actual_base_qty); const quantity = received > 0 ? actual * Number(line.qualified_qty || 0) / received : 0; return Number.isInteger(quantity) && quantity > 0 ? quantity : 0 },
    serialNumberList(line) { return Array.isArray(line._serialEntries) ? line._serialEntries : [] },
    serialProgress(line) { const required = this.serialRequiredCount(line); return required ? Math.min(100, this.serialNumberList(line).length / required * 100) : 0 },
    syncSerialText(line) { this.$set(line, 'serial_text', this.serialNumberList(line).map(entry => entry.serial_no).join('\n')); this.$set(line, 'serial_entries', this.serialNumberList(line).map(entry => ({ serial_no: entry.serial_no, source: entry.source }))); this.$set(line, 'serial_number_source', this.serialNumberList(line).every(entry => entry.source === 'system_generated') ? 'system_generated' : 'supplier') },
    autoAssignSerialsToAllocations(line) {
      const rows = this.allocations(line)
      if (!rows.length) return
      const serials = this.serialNumberList(line).map(entry => entry.serial_no)
      let offset = 0
      rows.forEach(row => {
        const quota = Math.max(0, Math.round(Number(row.base_qty || 0)))
        this.$set(row, 'serial_nos', serials.slice(offset, offset + quota))
        offset += quota
      })
    },
    async generateLineSerials(line) {
      const quantity = this.serialRequiredCount(line)
      if (!quantity) return this.$message.error('合格实际入库数量必须是大于 0 的整数')
      try {
        const response = await generateReceiptSerials({ item_id: line.item_id, quantity })
        this.$set(line, '_serialEntries', (response.data.data || []).map(serial_no => ({ serial_no, source: 'system_generated' })))
        this.$set(line, '_scanInput', '')
        line._serialError = ''
        this.syncSerialText(line)
        this.autoAssignSerialsToAllocations(line)
        this.$message.success(`已一次生成 ${quantity} 个设备编号`)
      } catch (error) { this.$message.error(error.userMessage || '设备编号生成失败') }
    },
    addScannedSerial(line) {
      const serialNo = String(line._scanInput || '').trim()
      if (!serialNo) return
      const duplicate = this.form.items.some(item => this.serialNumberList(item).some(entry => entry.serial_no === serialNo))
      if (duplicate) { line._serialError = '该编号已录入，请勿重复扫描'; return }
      line._serialEntries.push({ serial_no: serialNo, source: 'supplier' })
      const target = this.allocations(line).find(row => (row.serial_nos || []).length < Math.round(Number(row.base_qty || 0)))
      if (target) this.$set(target, 'serial_nos', [...(target.serial_nos || []), serialNo])
      line._scanInput = ''; line._serialError = ''
      this.syncSerialText(line)
    },
    removeSerial(line, index) { const removed = line._serialEntries[index]?.serial_no; line._serialEntries.splice(index, 1); this.allocations(line).forEach(row => this.$set(row, 'serial_nos', (row.serial_nos || []).filter(no => no !== removed))); this.syncSerialText(line) },
    printSerialLabels(line, serialNo = '') {
      const serials = serialNo ? [serialNo] : this.serialNumberList(line).map(entry => entry.serial_no)
      if (!serials.length) return this.$message.warning('请先录入或生成设备编号')
      const item = this.itemById(line.item_id)
      const escapeHtml = value => String(value == null ? '' : value).replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char]))
      const labels = serials.map(value => `<article><div class="title">设备编号 / 序列号</div><div class="serial">${escapeHtml(value)}</div><div class="meta">物料：${escapeHtml(item.item_code || '-')} / ${escapeHtml(item.item_name || '-')}</div><div class="meta">到货单：${escapeHtml(this.form.receipt_no || '-')}</div></article>`).join('')
      const popup = window.open('', '_blank', 'width=760,height=640')
      if (!popup) return this.$message.error('打印窗口被浏览器拦截，请允许弹出窗口')
      popup.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>设备编号标签</title><style>@page{size:70mm 40mm;margin:3mm}*{box-sizing:border-box}body{margin:0;font-family:Arial,"Microsoft YaHei"}article{width:64mm;height:34mm;padding:4mm;border:1px solid #222;page-break-after:always}.title{font-size:10pt}.serial{margin:3mm 0;font-size:16pt;font-weight:700;word-break:break-all}.meta{font-size:8.5pt;line-height:1.5}article:last-child{page-break-after:auto}
</style></head><body>${labels}</body></html>`)
      popup.document.close(); popup.focus(); window.setTimeout(() => popup.print(), 250)
    },
    receiptAmounts(line) {
      if (line.unit_price == null && line.receipt_cost == null) return { amount: null, excl: null, tax: null, incl: null }
      const qty = Number(line.qty || 0)
      const samePrice = Number(line.unit_price) === line._receiptSavedPrice
      const unchanged = samePrice && qty === Number(line.receipt_qty)
      if (this.isReplacement) {
        const amount = line.receipt_cost == null ? null : Number(line.receipt_cost)
        return { amount, excl: amount, tax: 0, incl: amount }
      }
      if (unchanged && line.amount_incl_tax != null && line.amount_excl_tax != null && line.tax_amount_snapshot != null) {
        return { amount: Number(line.receipt_cost), excl: Number(line.amount_excl_tax), tax: Number(line.tax_amount_snapshot), incl: Number(line.amount_incl_tax) }
      }
      const round = value => Number(roundInvoiceAmount(value))
      const ratio = Number(line.receipt_qty) > 0 ? qty / Number(line.receipt_qty) : 0
      const amount = round(samePrice && line.receipt_cost != null ? Number(line.receipt_cost) * ratio : qty * Number(line.unit_price || 0))
      const freight = line.order_item_id ? round(Number(line.freight_allocated_amount || 0) * ratio) : 0
      const rate = Number(line.tax_rate || 0)
      const tax = line.order_item_id && line.tax_amount_snapshot != null ? round(Number(line.tax_amount_snapshot) * ratio)
        : (this.taxMode === 'tax_excluded' ? round(amount * rate / 100) : round(amount - round(amount * 100 / (100 + rate))))
      const incl = round(amount + freight + (this.taxMode === 'tax_excluded' ? tax : 0))
      return { amount, excl: round(incl - tax), tax, incl }
    },
    lineAmount(line) { return this.receiptAmounts(line).amount },
    payload() { return { ...this.form, attachment_draft_token: this.attachmentDraftToken, reservation_token: this.reservation?.reservation_token, creation_session_id: this.reservation?.creation_session_id, items: this.form.items.map(line => { const stock = this.isStockManaged(line); return { ...line, unit_price: this.isReplacement && !this.$can('inventory.amount.view') ? undefined : line.unit_price, receipt_qty: line.qty, receipt_cost: this.isReplacement && !this.$can('inventory.amount.view') ? undefined : this.lineAmount(line), warehouse_id: stock ? line.warehouse_id : null, location_id: stock ? line.location_id : null, batch_no: stock ? line.batch_no : null, stock_length_mm: this.isPipeLine(line) && line.stock_length_mm !== '' && line.stock_length_mm !== null && line.stock_length_mm !== undefined ? line.stock_length_mm : null, serial_text: stock ? line.serial_text : null, serial_entries: stock ? this.serialNumberList(line).map(entry => ({ serial_no: entry.serial_no, source: entry.source })) : [], allocations: stock ? (this.allocations(line).length ? this.allocations(line) : (line.warehouse_id && line.location_id ? [{ warehouse_id: line.warehouse_id, location_id: line.location_id, base_qty: this.qualifiedBaseQty(line), serial_nos: this.serialNumberList(line).map(entry => entry.serial_no), physical_entries: line._physicalEntries || [] }] : [])) : [] } }) } },
    newDraftToken() { return window.crypto && window.crypto.randomUUID ? window.crypto.randomUUID() : `purchase-${Date.now()}-${Math.random().toString(16).slice(2)}` },
    validate(strict) {
      if (!this.form.supplier_id) return '供应商不能为空'
      if (!this.form.items.length || this.form.items.some(line => !line.item_id)) return '每一行都必须选择物料'
      if (this.form.items.some(line => !line.purchase_unit_id)) return '每一行都必须选择有效采购单位'
      if (this.form.items.some(line => Math.abs(Number(line.qualified_qty || 0) + Number(line.unqualified_qty || 0) - Number(line.qty || 0)) > 0.000001)) return '合格数量与不合格数量之和必须等于到货数量'
      if (this.form.items.some(line => this.hasDifference(line) && !String(line.difference_reason || '').trim())) return '存在基本数量差异时必须填写差异原因'
      if (strict && this.form.items.some(line => this.serialTrackingMode(line) === 'required' && this.serialNumberList(line).length !== this.serialRequiredCount(line))) return '必须逐件编号的物料，已录入编号数量必须与合格实际入库数量一致'
      if (strict && this.form.items.some(line => !this.physicalEntriesValid(line))) return '请补齐逐张板材长宽厚或逐根管材长度，件数须与合格采购数量一致，面积或长度合计须与各库位基本数量一致'
      if (strict && this.form.items.some(line => !this.lineAllocationValid(line))) return '每一行合格基本数量必须完整分配到有效仓库和库位；设备编号不得漏分或重复分配'
      return ''
    },
    async save(draft) {
      if (this.scopeChanging) return
      if (this.scopeIssue) return this.$message.error(this.scopeIssue)
      const message = this.validate(!draft)
      if (message) return this.$message.error(message)
      this.saving = true
      try {
        const response = await savePurchaseReceipt(this.payload())
        if (!this.isEdit && this.reservation) clearCreatePageReservation(this.reservation)
        this.$message.success(draft ? '到货单草稿已保存' : '到货单已保存')
        this.$router.push('/purchase/receipts')
        return response
      } catch (error) { this.$message.error(error.userMessage || '保存失败') } finally { this.saving = false }
    },
    goBack() { this.$router.push('/purchase/receipts') },
    number(value) { return Number(value || 0).toLocaleString('zh-CN', { maximumFractionDigits: 6 }) },
    money(value) { return value == null || value === '' ? '—' : Number(value).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
  }
}
</script>

<style scoped>
.receipt-form-page {
  box-sizing: border-box;
  max-width: 100%;
  min-height: calc(100vh - 52px);
  padding: 16px 20px 0;
  background: #f8fafc;
  color: #1e293b;
  display: flex;
  flex-direction: column;
  overflow-x: clip;
  position: relative;
}

.page-head {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 14px 20px;
  margin-bottom: 14px;
  box-shadow: 0 1px 3px rgba(15, 23, 42, 0.03);
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px 16px;
}

.head-left {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}

.head-text {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.title-row {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}

.title-row h1 {
  margin: 0;
  font-size: 19px;
  font-weight: 700;
  color: #0f172a;
}

.doc-no-pill {
  display: inline-flex;
  align-items: center;
  padding: 2px 9px;
  border-radius: 4px;
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  color: #334155;
  font-family: Consolas, Monaco, monospace;
  font-size: 12px;
  font-weight: 600;
}

.purchase-flow-steps {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 5px 10px;
  border-radius: 8px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  flex-wrap: wrap;
}

.flow-step-item {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 3px 9px;
  border-radius: 999px;
  font-size: 12px;
  color: #64748b;
  font-weight: 500;
  white-space: nowrap;
}

.flow-step-dot {
  width: 18px;
  height: 18px;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: #e2e8f0;
  color: #475569;
  font-size: 11px;
  font-weight: 700;
}

.flow-step-item.is-done {
  color: #15803d;
}

.flow-step-item.is-done .flow-step-dot {
  background: #dcfce7;
  color: #008b4b;
}

.flow-step-item.is-active {
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  color: #008b4b;
  font-weight: 600;
}

.flow-step-item.is-active .flow-step-dot {
  background: #008b4b;
  color: #ffffff;
}

.flow-step-arrow {
  font-size: 11px;
  color: #cbd5e1;
}

.heading-actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}

.posting-alert {
  border-radius: 8px;
  margin-bottom: 14px;
}

.form-card {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 16px 20px;
  box-shadow: 0 1px 3px rgba(15, 23, 42, 0.03);
  box-sizing: border-box;
  min-width: 0;
  margin-bottom: 14px;
}

.card-head-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 14px;
}

.card-head-title {
  display: flex;
  align-items: center;
  gap: 8px;
}

.bar-accent {
  width: 3px;
  height: 16px;
  background: #008b4b;
  border-radius: 2px;
  flex-shrink: 0;
}

.card-head-title h3,
.section-heading h2 {
  margin: 0;
  font-size: 14px;
  font-weight: 600;
  color: #1e293b;
}

.card-head-hint,
.section-sub-hint {
  font-size: 12px;
  color: #94a3b8;
}

.grid-4 {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px 14px;
}

.basic-grid > * {
  min-width: 0;
}

.remark-span-4 {
  grid-column: 1 / -1;
}

.field-block,
.compact-field,
.scan-label {
  display: grid;
  gap: 6px;
  color: #475569;
  font-size: 12px;
}

.field-block > span,
.compact-field > span {
  font-weight: 500;
}

.field-block.required > span:after,
.compact-field.required > span:after {
  content: ' *';
  color: #e14d50;
}

.field-block .el-select,
.field-block .el-date-editor,
.compact-field .el-select,
.compact-field .el-date-editor {
  width: 100%;
}

.label-action-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.field-sync-link {
  border: 0;
  background: #f0fdf4;
  color: #008b4b;
  border: 1px solid #bbf7d0;
  border-radius: 4px;
  padding: 1px 7px;
  font-size: 11px;
  line-height: 1.5;
  cursor: pointer;
  font-weight: 500;
  transition: all 0.15s ease;
}

.field-sync-link:hover {
  background: #008b4b;
  color: #fff;
  border-color: #008b4b;
}

.readonly-status-box {
  height: 32px;
  display: flex;
  align-items: center;
  padding: 0 10px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
}

.lines-section {
  padding: 0;
  overflow: hidden;
}

.section-heading {
  padding: 14px 20px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 10px;
  border-bottom: 1px solid #e2e8f0;
}

.title-with-bar {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}

.line-actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}

.line-table-shell {
  width: 100%;
  overflow: hidden;
}

.line-table-shell ::v-deep .el-table th.el-table__cell {
  height: 38px;
  background: #f8fafc !important;
  color: #334155;
  font-weight: 600;
}

.line-table-shell ::v-deep .el-table td.el-table__cell {
  min-height: 48px;
  padding: 6px 0;
}

.line-table-shell ::v-deep .el-table td.el-table__expanded-cell {
  padding: 0;
}

.line-table-shell ::v-deep .selected-receipt-row td {
  background: #f0fdf4 !important;
}

.line-table-shell ::v-deep .el-table__expand-icon:focus-visible {
  outline: 2px solid #008b4b;
}

.expand-line-button {
  color: #008b4b !important;
}

.item-cell {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.code-badge {
  font-family: Consolas, monospace;
  font-weight: 600;
  color: #008b4b;
  font-size: 12px;
}

.item-cell strong {
  white-space: normal;
  overflow-wrap: anywhere;
  font-size: 13px;
  color: #1e293b;
}

.serial-progress {
  display: grid;
  gap: 4px;
}

.serial-progress span {
  font-size: 12px;
  color: #334155;
}

.serial-progress i {
  width: 72px;
  height: 5px;
  border-radius: 3px;
  background: #e2e8f0;
  overflow: hidden;
}

.serial-progress b {
  display: block;
  height: 100%;
  background: #008b4b;
}

.danger-link {
  color: #ef4444 !important;
}

.danger-link:hover {
  color: #dc2626 !important;
  text-decoration: underline;
}

.pipe-length-tip {
  margin: -4px 0 8px;
  color: #64748b;
  font-size: 12px;
}

.pipe-length-tip strong,
.pipe-length-cell {
  color: #c45a00;
  font-weight: 700;
}

.line-editor {
  min-width: 0;
  overflow: hidden;
  background: #fff;
  scroll-margin: 64px 0;
}

.line-editor > header {
  min-height: 40px;
  padding: 8px 16px;
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 4px;
  background: #f8fafc;
  border-bottom: 1px solid #e2e8f0;
  font-size: 13px;
  font-weight: 600;
  color: #0f172a;
}

.line-editor > header span {
  margin-left: 6px;
  color: #64748b;
  font-weight: 400;
}

.editor-columns {
  display: grid;
  grid-template-columns: 1fr 1fr 1.18fr;
  min-height: 350px;
}

.editor-group {
  min-width: 0;
  padding: 14px 16px;
  border-right: 1px solid #edf0f2;
}

.editor-group:last-child {
  border-right: 0;
}

.editor-group h3 {
  margin: 0 0 12px;
  font-size: 13px;
  font-weight: 600;
  color: #1e293b;
}

.compact-field {
  grid-template-columns: 96px minmax(0, 1fr);
  align-items: center;
  margin-bottom: 9px;
}

.compact-field > span {
  white-space: nowrap;
}

.remark-compact {
  align-items: start;
}

.remark-compact > span {
  padding-top: 7px;
}

.serial-title {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.serial-title h3 {
  margin-bottom: 9px;
}

.generate-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 10px;
  align-items: end;
}

.generate-row label {
  display: grid;
  grid-template-columns: 68px 70px;
  align-items: center;
  gap: 6px;
  font-size: 12px;
}

.scan-label {
  margin-top: 10px;
}

.serial-helper,
.serial-error {
  min-height: 17px;
  margin: 4px 0 0;
  font-size: 11px;
}

.serial-helper {
  color: #64748b;
}

.serial-error {
  color: #ef4444;
  font-weight: 600;
}

.serial-list-heading {
  height: 28px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid #e2e8f0;
  color: #475569;
  font-size: 12px;
}

.serial-records {
  max-height: 126px;
  overflow-y: auto;
}

.serial-record {
  height: 31px;
  display: grid;
  grid-template-columns: 24px minmax(0, 1fr) 54px 48px 18px;
  align-items: center;
  border-bottom: 1px solid #edf0f2;
  font-size: 11px;
}

.serial-index {
  text-align: center;
  color: #64748b;
}

.serial-record strong {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-family: Consolas, monospace;
}

.serial-record em {
  color: #64748b;
  font-style: normal;
}

.serial-record .el-button {
  padding: 0;
}

.serial-delete {
  border: 0;
  background: transparent;
  color: #ef4444;
  font-size: 16px;
  cursor: pointer;
}

.detail-layout {
  margin-bottom: 14px;
}

.document-summary {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 24px;
  margin-bottom: 0;
}

.summary-block {
  min-width: 0;
}

.summary-block .card-head-title {
  margin-bottom: 12px;
}

.document-summary dl {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 10px 14px;
  margin: 0;
  font-size: 13px;
}

.document-summary dt {
  color: #64748b;
}

.document-summary dd {
  margin: 0;
  font-weight: 600;
  color: #1e293b;
  text-align: right;
}

.green-text,
.document-summary .no-payable {
  color: #008b4b !important;
}

.grand-total {
  color: #008b4b !important;
  font-size: 15px;
  font-weight: 700;
}

.validation-state {
  margin: 0;
  color: #008b4b;
  font-size: 13px;
  line-height: 1.7;
}

.validation-state.invalid {
  color: #d97706;
}

.validation-state i {
  margin-right: 5px;
}

.receipt-attachment-panel {
  margin-bottom: 16px;
}

.allocation-entry {
  display: grid;
  grid-template-columns: 96px minmax(0, 1fr);
  align-items: center;
  margin: -1px 0 9px;
  color: #475569;
  font-size: 12px;
}

.allocation-entry > div {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}

.allocation-entry small {
  overflow: hidden;
  color: #64748b;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.serial-allocation-tip {
  margin: 7px 0 0;
  padding: 6px 8px;
  border-radius: 4px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  color: #008b4b;
  font-size: 11px;
}

.allocation-dialog-body {
  display: grid;
  gap: 12px;
}

.allocation-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.allocation-dialog-body ::v-deep .el-select,
.allocation-dialog-body ::v-deep .el-input-number {
  width: 100%;
}

.allocation-total {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 18px;
  padding: 10px 12px;
  border: 1px solid #bbf7d0;
  border-radius: 6px;
  background: #f0fdf4;
  color: #008b4b;
}

.allocation-total.invalid {
  border-color: #fde68a;
  background: #fffbeb;
  color: #b45309;
}

.allocation-total strong {
  min-width: 70px;
  text-align: right;
}

.bottom-actions {
  box-sizing: border-box;
  position: sticky;
  bottom: 0;
  z-index: 25;
  margin: auto -20px 0;
  min-height: 58px;
  padding: 10px 24px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
  background: #fff;
  border-top: 1px solid #e2e8f0;
  box-shadow: 0 -4px 12px rgba(15, 23, 42, 0.05);
}

.bottom-actions-left {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  min-width: 0;
}

.bottom-summary-meta {
  font-size: 13px;
  color: #475569;
}

.bottom-actions-right {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-left: auto;
}

.receipt-form-page ::v-deep .el-button--success {
  background-color: #008b4b !important;
  border-color: #008b4b !important;
  color: #fff !important;
}

.receipt-form-page ::v-deep .el-button--success.is-plain {
  background-color: #f0fdf4 !important;
  border-color: #bbf7d0 !important;
  color: #008b4b !important;
}

.receipt-form-page ::v-deep .el-button--success.is-plain:hover,
.receipt-form-page ::v-deep .el-button--success.is-plain:focus {
  background-color: #008b4b !important;
  border-color: #008b4b !important;
  color: #fff !important;
}

.receipt-form-page ::v-deep .el-button--success:hover,
.receipt-form-page ::v-deep .el-button--success:focus {
  background-color: #00763f !important;
  border-color: #00763f !important;
}

.receipt-form-page ::v-deep .el-button--default:hover,
.receipt-form-page ::v-deep .el-button--default:focus {
  border-color: #008b4b;
  color: #008b4b;
  background-color: #f0fdf4;
}

.receipt-form-page ::v-deep .el-button--text:not(.danger-link) {
  color: #008b4b;
}

.receipt-form-page ::v-deep .el-button--text:not(.danger-link):hover,
.receipt-form-page ::v-deep .el-button--text:not(.danger-link):focus {
  color: #00763f;
}

.receipt-form-page ::v-deep .el-input__inner:focus,
.receipt-form-page ::v-deep .el-textarea__inner:focus,
.receipt-form-page ::v-deep .el-select .el-input.is-focus .el-input__inner,
.receipt-form-page ::v-deep .el-range-editor.is-active {
  border-color: #008b4b !important;
}

.receipt-form-page ::v-deep .el-checkbox__input.is-checked .el-checkbox__inner,
.receipt-form-page ::v-deep .el-checkbox__input.is-indeterminate .el-checkbox__inner,
.receipt-form-page ::v-deep .el-radio__input.is-checked .el-radio__inner {
  background-color: #008b4b !important;
  border-color: #008b4b !important;
}

@media (max-width: 1180px) {
  .grid-4 {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .remark-span-4 {
    grid-column: span 2;
  }
  .purchase-flow-steps {
    width: 100%;
    justify-content: flex-start;
  }
}

@media (max-width: 900px) {
  .editor-columns {
    grid-template-columns: minmax(0, 1fr);
  }
  .editor-group {
    border-right: 0;
    border-bottom: 1px solid #edf0f2;
  }
  .document-summary {
    grid-template-columns: minmax(0, 1fr);
    gap: 16px;
  }
}

@media (max-width: 780px) {
  .receipt-form-page {
    padding: 12px 12px 0;
  }
  .page-head {
    flex-direction: column;
    align-items: stretch;
  }
  .title-row h1 {
    flex-basis: 100%;
    font-size: 18px;
  }
  .heading-actions {
    flex-wrap: wrap;
    max-width: 100%;
  }
  .grid-4 {
    grid-template-columns: minmax(0, 1fr);
  }
  .remark-span-4 {
    grid-column: span 1;
  }
  .section-heading {
    padding: 12px 14px;
    flex-direction: column;
    align-items: flex-start;
  }
  .line-editor > header {
    padding: 10px 14px;
  }
  .heading-actions .el-button,
  .bottom-actions .el-button,
  .section-heading .el-button {
    margin-left: 0;
  }
  .bottom-actions {
    margin: auto -12px 0;
    padding: 10px 14px;
    flex-direction: column;
    align-items: stretch;
  }
  .bottom-actions-right {
    margin-left: 0;
    justify-content: flex-end;
    flex-wrap: wrap;
  }
}
</style>
